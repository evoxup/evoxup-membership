<?php
namespace EvoMembers\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy-safe central audit contract.
 *
 * Core emits normalized entries only. Persistent storage and the admin UI live
 * in the official Central Audit extension so Core remains small and extensions
 * stay independently replaceable.
 */
final class AuditTrail {
    private static string $request_id = '';
    /** @var array<int,array<string,mixed>> */
    private static array $pending = array();
    private static bool $buffer_pending = true;

    public static function record(
        string $action,
        string $resource_type = '',
        string|int $resource_id = '',
        ?array $before = null,
        ?array $after = null,
        array $context = array(),
        string $result = 'success',
        string $source = 'core',
        string $severity = 'info'
    ): array {
        $action = self::canonical( $action );
        if ( '' === $action ) {
            return array();
        }

        $user_id = get_current_user_id();
        $user = $user_id > 0 ? wp_get_current_user() : null;
        $roles = $user instanceof \WP_User ? array_values( array_map( 'sanitize_key', (array) $user->roles ) ) : array();

        $entry = array(
            'event_uuid'      => wp_generate_uuid4(),
            'request_id'      => self::request_id(),
            'occurred_at'     => current_time( 'mysql', true ),
            'actor_user_id'   => $user_id,
            'actor_type'      => $user_id > 0 ? 'user' : 'system',
            'actor_label'     => $user instanceof \WP_User ? sanitize_text_field( $user->user_login ) : 'system',
            'actor_roles'     => $roles,
            'source'          => sanitize_key( str_replace( array( ':', '.' ), '_', $source ) ) ?: 'core',
            'action'          => $action,
            'category'        => sanitize_key( (string) ( $context['category'] ?? self::category_for_action( $action ) ) ) ?: 'platform',
            'resource_type'   => sanitize_key( $resource_type ),
            'resource_id'     => is_int( $resource_id ) ? (string) $resource_id : sanitize_text_field( (string) $resource_id ),
            'result'          => in_array( sanitize_key( $result ), array( 'success', 'failure', 'denied', 'warning' ), true ) ? sanitize_key( $result ) : 'success',
            'severity'        => in_array( sanitize_key( $severity ), array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' ), true ) ? sanitize_key( $severity ) : 'info',
            'before'          => null === $before ? null : self::redact( $before ),
            'after'           => null === $after ? null : self::redact( $after ),
            'context'         => self::redact( $context ),
        );

        // Core boots before optional extensions. Keep a small request-local
        // buffer until a primary audit sink drains it so early boot failures and
        // authorization events are not lost merely because the Audit extension
        // loads later in the same request.
        if ( self::$buffer_pending ) {
            self::$pending[] = $entry;
            if ( count( self::$pending ) > 100 ) {
                array_shift( self::$pending );
            }
        }

        /**
         * Persist/forward one normalized Evoxup audit entry.
         *
         * The official Central Audit extension subscribes here. Third-party
         * observability integrations may also subscribe without touching Core.
         */
        do_action( 'evoxup_audit_record', $entry );

        return $entry;
    }

    /**
     * Drain audit entries emitted before the persistent sink was ready.
     * Calling this marks the sink live for the remainder of the request.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function drain_pending(): array {
        $pending = self::$pending;
        self::$pending = array();
        self::$buffer_pending = false;
        return $pending;
    }

    public static function denied( string $action, string $resource_type = '', string|int $resource_id = '', array $context = array(), string $source = 'core' ): array {
        return self::record( $action, $resource_type, $resource_id, null, null, $context, 'denied', $source, 'warning' );
    }

    public static function redact( mixed $value, string $key = '', int $depth = 0 ): mixed {
        if ( self::sensitive_key( $key ) ) {
            return '[REDACTED]';
        }
        if ( $depth >= 8 ) {
            return '[MAX_DEPTH]';
        }
        if ( is_array( $value ) ) {
            $clean = array();
            $count = 0;
            foreach ( $value as $item_key => $item_value ) {
                if ( $count >= 100 ) {
                    $clean['_truncated_items'] = max( 0, count( $value ) - 100 );
                    break;
                }
                $safe_key = is_string( $item_key ) ? $item_key : (string) $item_key;
                $clean[ $item_key ] = self::redact( $item_value, $safe_key, $depth + 1 );
                ++$count;
            }
            return $clean;
        }
        if ( is_object( $value ) ) {
            if ( $value instanceof \JsonSerializable ) {
                return self::redact( $value->jsonSerialize(), $key, $depth + 1 );
            }
            return '[OBJECT:' . sanitize_text_field( $value::class ) . ']';
        }
        if ( is_resource( $value ) ) {
            return '[RESOURCE]';
        }
        if ( is_string( $value ) ) {
            $value = wp_check_invalid_utf8( $value, true );
            if ( strlen( $value ) > 5000 ) {
                $value = substr( $value, 0, 5000 ) . '…';
            }
            return $value;
        }
        return $value;
    }

    private static function request_id(): string {
        if ( '' === self::$request_id ) {
            self::$request_id = wp_generate_uuid4();
        }
        return self::$request_id;
    }

    private static function canonical( string $action ): string {
        $parts = array_values( array_filter( array_map( 'sanitize_key', explode( '.', strtolower( trim( $action ) ) ) ) ) );
        return implode( '.', $parts );
    }

    private static function category_for_action( string $action ): string {
        $first = strtok( $action, '.' );
        return is_string( $first ) && '' !== $first ? sanitize_key( $first ) : 'platform';
    }

    private static function sensitive_key( string $key ): bool {
        $key = strtolower( trim( $key ) );
        if ( '' === $key ) {
            return false;
        }
        if ( preg_match( '/password|passwd|secret|token|authorization|cookie|nonce|private[_-]?key|client[_-]?secret|webhook[_-]?secret|access[_-]?token|refresh[_-]?token|signature|email|phone|destination|payment[_-]?destination|payout[_-]?destination|iban|bank[_-]?account|wallet[_-]?address/', $key ) ) {
            return true;
        }
        return in_array( $key, array( 'key', 'api_key', 'license_key', 'secret_key', 'rest_key' ), true );
    }
}
