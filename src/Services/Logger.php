<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class Logger {
    public static function write( string $event, array $context = array(), string $level = 'info', ?int $customer_id = null, ?int $license_id = null ): void {
        global $wpdb;
        $wpdb->insert(
            Database::table( 'logs' ),
            array(
                'level'         => sanitize_key( $level ),
                'event'         => sanitize_key( $event ),
                'customer_id'   => $customer_id,
                'license_id'    => $license_id,
                'actor_user_id' => get_current_user_id() ?: null,
                'request_id'    => isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ) ) : null,
                'context_json'  => wp_json_encode( $context ),
                'created_at'    => current_time( 'mysql', true ),
            )
        );
    }
}
