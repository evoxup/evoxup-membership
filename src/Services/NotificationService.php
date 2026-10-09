<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class NotificationService {
    public function create( int $customer_id, string $title, string $message, string $type = 'info', string $action_url = '', ?string $expires_at = null ): array|WP_Error {
        global $wpdb;
        if ( ! ( new CustomerService() )->get( $customer_id ) ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_not_found' ), __( 'Customer not found.', 'evoxup-membership' ) ); }
        $title = sanitize_text_field( $title );
        $message = sanitize_textarea_field( $message );
        if ( '' === $title || '' === $message ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'notification_required' ), __( 'Notification title and message are required.', 'evoxup-membership' ) ); }
        $type = in_array( sanitize_key( $type ), array( 'info', 'success', 'warning', 'error', 'offer', 'system' ), true ) ? sanitize_key( $type ) : 'info';
        $expires = null;
        if ( $expires_at ) {
            $ts = strtotime( $expires_at );
            if ( false !== $ts ) { $expires = gmdate( 'Y-m-d H:i:s', $ts ); }
        }
        $now = current_time( 'mysql', true );
        $row = array( 'customer_id' => $customer_id, 'type' => $type, 'title' => $title, 'message' => $message, 'action_url' => esc_url_raw( $action_url ), 'status' => 'unread', 'expires_at' => $expires, 'read_at' => null, 'created_at' => $now, 'updated_at' => $now );
        if ( false === $wpdb->insert( Database::table( 'notifications' ), $row ) ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'notification_store_failed' ), __( 'Notification could not be stored.', 'evoxup-membership' ) ); }
        $row['id'] = (int) $wpdb->insert_id;
        return $row;
    }

    public function for_customer( int $customer_id, int $limit = 100 ): array {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d AND (expires_at IS NULL OR expires_at >= %s) ORDER BY id DESC LIMIT %d', Database::table( 'notifications' ), $customer_id, $now, min( 500, max( 1, $limit ) ) ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function unread_count( int $customer_id ): int {
        global $wpdb;
        if ( $customer_id < 1 ) { return 0; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d AND status=%s AND (expires_at IS NULL OR expires_at>=%s)', Database::table( 'notifications' ), $customer_id, 'unread', current_time( 'mysql', true ) ) );
    }

    public function all( int $limit = 200 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT n.*, c.email, c.display_name FROM %i n LEFT JOIN %i c ON c.id=n.customer_id ORDER BY n.id DESC LIMIT %d', Database::table( 'notifications' ), Database::table( 'customers' ), min( 500, max( 1, $limit ) ) ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function mark_read( int $id, int $customer_id ): bool {
        global $wpdb;
        $now = current_time( 'mysql', true );
        return false !== $wpdb->update( Database::table( 'notifications' ), array( 'status' => 'read', 'read_at' => $now, 'updated_at' => $now ), array( 'id' => $id, 'customer_id' => $customer_id ) );
    }
}
