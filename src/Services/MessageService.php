<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MessageService {
    public function create( int $customer_id, string $subject, string $body, string $channel = 'in_app' ): array|WP_Error {
        global $wpdb;
        $customer = ( new CustomerService() )->get( $customer_id );
        if ( ! $customer ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_not_found' ), __( 'Customer not found.', 'evoxup-membership' ) ); }
        $subject = sanitize_text_field( $subject );
        $body = sanitize_textarea_field( $body );
        $channel = in_array( sanitize_key( $channel ), array( 'in_app', 'email', 'both' ), true ) ? sanitize_key( $channel ) : 'in_app';
        if ( '' === $subject || '' === $body ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'message_required' ), __( 'Subject and message are required.', 'evoxup-membership' ) ); }
        $now = current_time( 'mysql', true );
        $row = array(
            'customer_id' => $customer_id,
            'subject'     => $subject,
            'message'     => $body,
            'channel'     => $channel,
            'status'      => in_array( $channel, array( 'email', 'both' ), true ) ? 'queued' : 'unread',
            'sent_at'     => null,
            'read_at'     => null,
            'created_at'  => $now,
            'updated_at'  => $now,
        );
        if ( false === $wpdb->insert( Database::table( 'messages' ), $row ) ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'message_store_failed' ), __( 'Message could not be stored.', 'evoxup-membership' ) ); }
        $row['id'] = (int) $wpdb->insert_id;

        // Storage belongs to Membership; delivery belongs to integrations listening to this event.
        do_action( 'evoxup_message_created', (int) $row['id'], $row );
        \EvoMembers\Core\Hooks::do_action( 'message_created', (int) $row['id'], $row );

        $stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', Database::table( 'messages' ), (int) $row['id'] ), ARRAY_A );
        return is_array( $stored ) ? $stored : $row;
    }

    public function for_customer( int $customer_id, int $limit = 100 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d ORDER BY id DESC LIMIT %d', Database::table( 'messages' ), $customer_id, min( 500, max( 1, $limit ) ) ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function all( int $limit = 200 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.*, c.email, c.display_name FROM %i m LEFT JOIN %i c ON c.id=m.customer_id ORDER BY m.id DESC LIMIT %d', Database::table( 'messages' ), Database::table( 'customers' ), min( 500, max( 1, $limit ) ) ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public function mark_read( int $id, int $customer_id ): bool {
        global $wpdb;
        $now = current_time( 'mysql', true );
        return false !== $wpdb->update( Database::table( 'messages' ), array( 'status' => 'read', 'read_at' => $now, 'updated_at' => $now ), array( 'id' => $id, 'customer_id' => $customer_id ) );
    }
}
