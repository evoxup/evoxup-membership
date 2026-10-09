<?php
namespace EvoMembers\Core;

use EvoMembers\Services\EventBus;
use EvoMembers\Services\Logger;
use EvoMembers\Services\AuditTrail;
use EvoMembers\Contracts\Api\ProductApiInterface;
use EvoMembers\Contracts\Api\PlanApiInterface;
use EvoMembers\Contracts\Api\MembershipApiInterface;
use EvoMembers\Contracts\Api\LicenseApiInterface;
use EvoMembers\Contracts\Api\CustomerApiInterface;
use EvoMembers\Contracts\Api\NotificationApiInterface;
use EvoMembers\Contracts\Api\OrderApiInterface;
use EvoMembers\Contracts\Api\EntitlementApiInterface;
use EvoMembers\Contracts\Api\PurchaseLinkApiInterface;
use EvoMembers\Contracts\Api\EventApiInterface;
use EvoMembers\Contracts\Api\AuditApiInterface;
use EvoMembers\Contracts\Api\CapabilityApiInterface;
use EvoMembers\Contracts\Platform\PlatformStorageApiInterface;
use EvoMembers\Contracts\Platform\ExtensionRegistryApiInterface;
use EvoMembers\SDK\ProductApi;
use EvoMembers\SDK\PlanApi;
use EvoMembers\SDK\MembershipApi;
use EvoMembers\SDK\LicenseApi;
use EvoMembers\SDK\CustomerApi;
use EvoMembers\SDK\NotificationApi;
use EvoMembers\SDK\OrderApi;
use EvoMembers\SDK\EntitlementApi;
use EvoMembers\SDK\PurchaseLinkApi;
use EvoMembers\SDK\EventApi;
use EvoMembers\SDK\AuditApi;
use EvoMembers\SDK\CapabilityApi;
use EvoMembers\SDK\Platform\PlatformStorageApi;
use EvoMembers\SDK\Platform\ExtensionRegistryApi;

defined( 'ABSPATH' ) || exit;

/**
 * Small, stable surface exposed to EVO extensions.
 *
 * It deliberately exposes versioned domain contracts plus a small set of
 * documented platform contracts. Ordinary extensions should stay on the
 * domain APIs; privileged infrastructure contracts exist for diagnostics and
 * configuration portability without importing Core implementation classes.
 */
final class ExtensionContext {
    public function __construct(
        private string $id,
        private array $manifest = array()
    ) {
        $this->id = sanitize_key( $this->id );
    }

    public function id(): string {
        return $this->id;
    }

    public function version(): string {
        return sanitize_text_field( (string) ( $this->manifest['version'] ?? '' ) );
    }

    public function api_version(): string {
        return defined( 'EVOMEMBERS_EXTENSION_API_VERSION' ) ? EVOMEMBERS_EXTENSION_API_VERSION : '1.0.0';
    }

    public function minimum_api_version(): string {
        return defined( 'EVOMEMBERS_EXTENSION_API_MIN_VERSION' ) ? EVOMEMBERS_EXTENSION_API_MIN_VERSION : '1.0.0';
    }

    public function event_schema_version(): string {
        return defined( 'EVOMEMBERS_EVENT_SCHEMA_VERSION' ) ? EVOMEMBERS_EVENT_SCHEMA_VERSION : '1.0.0';
    }

    public function supports_api( string $version ): bool {
        return version_compare( $this->api_version(), $version, '>=' ) && version_compare( $this->minimum_api_version(), $version, '<=' );
    }

    public function manifest(): array {
        return $this->manifest;
    }

    /** API 2.0 stable domain service contracts. */
    public function products(): ProductApiInterface { return new ProductApi(); }
    public function plans(): PlanApiInterface { return new PlanApi(); }
    public function memberships(): MembershipApiInterface { return new MembershipApi(); }
    public function licenses(): LicenseApiInterface { return new LicenseApi(); }
    public function customers(): CustomerApiInterface { return new CustomerApi(); }
    public function notifications(): NotificationApiInterface { return new NotificationApi(); }
    public function orders(): OrderApiInterface { return new OrderApi(); }
    public function entitlements(): EntitlementApiInterface { return new EntitlementApi(); }
    public function purchase_links(): PurchaseLinkApiInterface { return new PurchaseLinkApi(); }
    public function events(): EventApiInterface { return new EventApi( $this->id ); }
    public function audit_api(): AuditApiInterface { return new AuditApi( 'extension_' . $this->id ); }
    public function capabilities(): CapabilityApiInterface { return new CapabilityApi(); }
    public function platform_storage(): PlatformStorageApiInterface { return new PlatformStorageApi(); }
    public function extension_registry(): ExtensionRegistryApiInterface { return new ExtensionRegistryApi(); }

    /** Register a versioned event schema for validated API 2.0 emission. */
    public function register_event( string $event, array $definition = array() ): bool {
        return $this->events()->register( $event, $definition );
    }

    /**
     * Register an extension action behind a fault-containment boundary.
     *
     * An extension callback is never allowed to abort a Core transaction,
     * webhook, checkout or another extension. Failures are logged with the
     * owning extension and hook, while WordPress continues the request.
     */
    public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $extension_id = $this->id;
        $wrapped = static function ( mixed ...$args ) use ( $callback, $hook, $extension_id ): void {
            try {
                $callback( ...$args );
            } catch ( \Throwable $throwable ) {
                self::log_callback_failure( $extension_id, $hook, $callback, $throwable, 'action' );
            }
        };
        add_action( $hook, $wrapped, $priority, $accepted_args );
    }

    /**
     * Register an extension filter behind the same isolation boundary.
     * On failure the incoming filter value is returned unchanged.
     */
    public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $extension_id = $this->id;
        $wrapped = static function ( mixed ...$args ) use ( $callback, $hook, $extension_id ): mixed {
            $fallback = $args[0] ?? null;
            try {
                return $callback( ...$args );
            } catch ( \Throwable $throwable ) {
                self::log_callback_failure( $extension_id, $hook, $callback, $throwable, 'filter' );
                return $fallback;
            }
        };
        add_filter( $hook, $wrapped, $priority, $accepted_args );
    }

    /** Subscribe to a canonical EVO event such as license.issued. */
    public function on_event( string $event, callable $callback, int $priority = 10 ): void {
        $parts = array_values( array_filter( array_map( 'sanitize_key', explode( '.', strtolower( trim( $event ) ) ) ) ) );
        if ( ! $parts ) {
            return;
        }
        $this->add_action( 'evomembers_event_' . implode( '_', $parts ), $callback, $priority, 4 );
    }

    public function emit( string $event, array $payload = array(), string $aggregate_type = '', int $aggregate_id = 0, int $customer_id = 0 ): int {
        return EventBus::emit( $event, $payload, $aggregate_type, $aggregate_id, $customer_id, 'extension_' . $this->id );
    }

    public function get_option( string $key, mixed $default = null ): mixed {
        $canonical = $this->option_key( $key );
        $value = get_option( $canonical, null );
        if ( null !== $value ) {
            return $value;
        }

        // One-time compatibility with pre-1.8.4 extension settings.
        $legacy_key = 'evo_ext_' . $this->id . '_' . ( '' !== sanitize_key( $key ) ? sanitize_key( $key ) : 'settings' );
        $legacy = get_option( $legacy_key, null );
        if ( null !== $legacy ) {
            update_option( $canonical, $legacy, false );
            delete_option( $legacy_key );
            return $legacy;
        }

        return $default;
    }

    public function update_option( string $key, mixed $value ): bool {
        return update_option( $this->option_key( $key ), $value, false );
    }

    public function delete_option( string $key ): bool {
        return delete_option( $this->option_key( $key ) );
    }

    public function log( string $event, array $context = array(), string $level = 'info' ): void {
        $context['extension_id'] = $this->id;
        Logger::write( 'extension.' . sanitize_key( $event ), $context, sanitize_key( $level ) ?: 'info' );
    }

    /** Register an extension permission in the shared Evoxup RBAC registry. */
    public function register_capability( string $capability, string $label, string $group = 'Extensions', array $default_roles = array() ): bool {
        return Capabilities::register( $capability, $label, $group, $default_roles );
    }

    public function can( string $capability ): bool {
        return current_user_can( sanitize_key( $capability ) );
    }

    /** Emit a privacy-safe central audit event. */
    public function audit(
        string $action,
        string $resource_type = '',
        string|int $resource_id = '',
        ?array $before = null,
        ?array $after = null,
        array $context = array(),
        string $result = 'success',
        string $severity = 'info'
    ): array {
        $context['extension_id'] = $this->id;
        return AuditTrail::record( $action, $resource_type, $resource_id, $before, $after, $context, $result, 'extension_' . $this->id, $severity );
    }

    private static function log_callback_failure( string $extension_id, string $hook, callable $callback, \Throwable $throwable, string $kind ): void {
        $callback_name = 'callable';
        if ( is_array( $callback ) && 2 === count( $callback ) ) {
            $owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
            $callback_name = $owner . '::' . (string) $callback[1];
        } elseif ( is_string( $callback ) ) {
            $callback_name = $callback;
        } elseif ( $callback instanceof \Closure ) {
            $callback_name = 'Closure';
        }

        try {
            Logger::write(
                'extension_callback_failed',
                array(
                    'extension_id' => sanitize_key( $extension_id ),
                    'hook'         => sanitize_text_field( $hook ),
                    'kind'         => sanitize_key( $kind ),
                    'callback'     => sanitize_text_field( $callback_name ),
                    'exception'    => sanitize_text_field( get_class( $throwable ) ),
                    'message'      => sanitize_text_field( $throwable->getMessage() ),
                    'file'         => wp_normalize_path( $throwable->getFile() ),
                    'line'         => (int) $throwable->getLine(),
                ),
                'error'
            );
        } catch ( \Throwable ) {
            // Diagnostics must never become a second failure path.
        }
    }

    private function option_key( string $key ): string {
        $key = sanitize_key( $key );
        return 'evomembers_ext_' . $this->id . '_' . ( '' !== $key ? $key : 'settings' );
    }
}
