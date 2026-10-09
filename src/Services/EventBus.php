<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class EventBus {
    public static function emit( string $event, array $payload = array(), string $aggregate_type = '', int $aggregate_id = 0, int $customer_id = 0, string $source = 'evo' ): int {
        global $wpdb;

        $parts = array_values( array_filter( array_map( 'sanitize_key', explode( '.', strtolower( trim( $event ) ) ) ) ) );
        if ( ! $parts ) { return 0; }
        $canonical = implode( '.', $parts );
        $hook_name = implode( '_', $parts );
        $now       = current_time( 'mysql', true );
        $row       = array(
            'event_name'     => $canonical,
            'aggregate_type' => '' !== $aggregate_type ? sanitize_key( $aggregate_type ) : null,
            'aggregate_id'   => $aggregate_id > 0 ? $aggregate_id : null,
            'customer_id'    => $customer_id > 0 ? $customer_id : null,
            'source'         => sanitize_key( $source ) ?: 'evo',
            'payload_json'   => wp_json_encode( $payload ),
            'created_at'     => $now,
        );
        $ok = $wpdb->insert( Database::table( 'events' ), $row );
        $id = false === $ok ? 0 : (int) $wpdb->insert_id;

        /**
         * Legacy WordPress hooks remain available for compatibility, but an
         * unmanaged listener must never roll back the domain operation that
         * already produced this event. Official extensions are additionally
         * isolated per callback by ExtensionContext.
         */
        try {
            \EvoMembers\Core\Hooks::do_action( 'event', $canonical, $payload, $id, $customer_id, $source );
        } catch ( \Throwable $throwable ) {
            self::log_listener_failure( $canonical, 'evomembers_event', $throwable );
        }
        try {
            \EvoMembers\Core\Hooks::do_action( 'event_' . $hook_name, $payload, $id, $customer_id, $source );
        } catch ( \Throwable $throwable ) {
            self::log_listener_failure( $canonical, 'evomembers_event_' . $hook_name, $throwable );
        }

        return $id;
    }

    private static function log_listener_failure( string $event, string $hook, \Throwable $throwable ): void {
        try {
            Logger::write(
                'event_listener_failed',
                array(
                    'event'     => sanitize_text_field( $event ),
                    'hook'      => sanitize_text_field( $hook ),
                    'exception' => sanitize_text_field( get_class( $throwable ) ),
                    'message'   => sanitize_text_field( $throwable->getMessage() ),
                    'file'      => wp_normalize_path( $throwable->getFile() ),
                    'line'      => (int) $throwable->getLine(),
                ),
                'error'
            );
        } catch ( \Throwable ) {
            // Event persistence and the calling transaction remain authoritative.
        }
    }

    public static function for_customer( int $customer_id, int $limit = 100 ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d ORDER BY id DESC LIMIT %d', Database::table( 'events' ), $customer_id, $limit ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public static function recent( int $limit = 100 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Database::table( 'events' ), $limit ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }
}
