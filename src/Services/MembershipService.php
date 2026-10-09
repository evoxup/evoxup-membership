<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MembershipService {
    /** Backward-compatible plan API. */
    public function plans( bool $active_only = false ): array { return ( new PlanService() )->all( $active_only ); }
    public function get_plan( int $id ): ?array { return ( new PlanService() )->get( $id ); }
    public function save_plan( array $data, int $id = 0 ): int|false { return ( new PlanService() )->save( $data, $id ); }

    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) { return null; }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT m.*, p.code AS plan_code, p.name AS plan_name, p.tier, p.plan_mode, p.entitlements_json, p.upgrade_plan_id, p.downgrade_plan_id FROM %i m INNER JOIN %i p ON p.id=m.plan_id WHERE m.id=%d LIMIT 1',
                Database::table( 'memberships' ), Database::table( 'plans' ), $id
            ), ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function grant( int $customer_id, int $plan_id, array $args = array() ): array|WP_Error {
        global $wpdb;
        if ( $customer_id < 1 || $plan_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'membership_missing_data' ), __( 'Customer and plan are required.', 'evoxup-membership' ) );
        }
        $plan = ( new PlanService() )->get( $plan_id );
        if ( ! $plan || 'active' !== (string) $plan['status'] ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'plan_unavailable' ), __( 'The selected membership plan is unavailable.', 'evoxup-membership' ) );
        }

        $source = sanitize_key( (string) ( $args['source'] ?? 'evo' ) ) ?: 'evo';
        $reference = sanitize_text_field( (string) ( $args['external_reference'] ?? '' ) );
        if ( '' !== $reference ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id=%d AND plan_id=%d AND source=%s AND external_reference=%s LIMIT 1', Database::table( 'memberships' ), $customer_id, $plan_id, $source, $reference ),
                ARRAY_A
            );
            if ( is_array( $existing ) ) { return $existing; }
        }

        $start_ts = ! empty( $args['starts_at'] ) ? strtotime( (string) $args['starts_at'] ) : time();
        if ( false === $start_ts ) { $start_ts = time(); }
        $duration = absint( $args['duration_days'] ?? $plan['duration_days'] ?? 0 );
        $expires = $duration > 0 ? gmdate( 'Y-m-d H:i:s', $start_ts + DAY_IN_SECONDS * $duration ) : null;
        $status = sanitize_key( (string) ( $args['status'] ?? 'active' ) );
        if ( ! in_array( $status, self::statuses(), true ) ) { $status = 'active'; }
        $now = current_time( 'mysql', true );
        $row = array(
            'customer_id'        => $customer_id,
            'plan_id'            => $plan_id,
            'status'             => $status,
            'source'             => $source,
            'source_order_id'    => ! empty( $args['source_order_id'] ) ? absint( $args['source_order_id'] ) : null,
            'external_reference' => '' !== $reference ? $reference : null,
            'starts_at'          => gmdate( 'Y-m-d H:i:s', $start_ts ),
            'expires_at'         => $expires,
            'auto_renew'         => array_key_exists( 'auto_renew', $args ) ? ( ! empty( $args['auto_renew'] ) ? 1 : 0 ) : ( ! empty( $plan['auto_renew_default'] ) ? 1 : 0 ),
            'notes'              => sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ),
            'metadata_json'      => ! empty( $args['metadata'] ) ? wp_json_encode( $args['metadata'] ) : null,
            'created_at'         => $now,
            'updated_at'         => $now,
        );
        if ( false === $wpdb->insert( Database::table( 'memberships' ), $row ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'membership_insert_failed' ), __( 'Membership could not be stored.', 'evoxup-membership' ) );
        }
        $row['id'] = (int) $wpdb->insert_id;
        EventBus::emit( 'membership.created', array( 'membership_id'=>$row['id'], 'plan_id'=>$plan_id, 'tier'=>$plan['tier'] ?? 'custom', 'status'=>$status, 'expires_at'=>$expires ), 'membership', $row['id'], $customer_id, $source );
        \EvoMembers\Core\Hooks::do_action( 'membership_granted', $row['id'], $customer_id, $plan_id, $row );
        do_action( 'evomembers_granted', $row['id'], $customer_id, $plan_id, $row );
        return $row;
    }

    /** Renew the same membership and extend its existing keys instead of replacing them. */
    public function renew( int $membership_id, ?int $duration_days = null ): array|WP_Error {
        global $wpdb;
        $membership = $this->get( $membership_id );
        if ( ! $membership ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'membership_not_found' ), __( 'Membership not found.', 'evoxup-membership' ) ); }
        $plan = ( new PlanService() )->get( (int) $membership['plan_id'] );
        $days = null !== $duration_days ? absint( $duration_days ) : absint( $plan['duration_days'] ?? 0 );
        $base = time();
        if ( ! empty( $membership['expires_at'] ) ) {
            $old = strtotime( (string) $membership['expires_at'] . ' UTC' );
            if ( false !== $old && $old > $base ) { $base = $old; }
        }
        $expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', $base + DAY_IN_SECONDS * $days ) : null;
        $now = current_time( 'mysql', true );
        $ok = $wpdb->update( Database::table( 'memberships' ), array( 'status'=>'active', 'expires_at'=>$expires, 'cancelled_at'=>null, 'renewal_count'=>absint( $membership['renewal_count'] ?? 0 ) + 1, 'last_renewed_at'=>$now, 'updated_at'=>$now ), array( 'id'=>$membership_id ) );
        if ( false === $ok ) { return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'membership_renew_failed' ), __( 'Membership renewal failed.', 'evoxup-membership' ) ); }
        // Preserve existing keys and extend their expiration.
        $wpdb->update( Database::table( 'licenses' ), array( 'status'=>'active', 'expires_at'=>$expires, 'updated_at'=>$now ), array( 'membership_id'=>$membership_id ) );
        EventBus::emit( 'membership.renewed', array( 'membership_id'=>$membership_id, 'expires_at'=>$expires, 'preserved_keys'=>true ), 'membership', $membership_id, (int)$membership['customer_id'], (string)($membership['source']??'evo') );
        return $this->get( $membership_id ) ?: array();
    }

    public function all( int $limit = 300 ): array {
        global $wpdb;
        $this->expire_due();
        $limit = min( 1000, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.*, p.code AS plan_code, p.name AS plan_name, p.tier, c.email, c.display_name, c.first_name, c.last_name, c.wp_user_id FROM %i m INNER JOIN %i p ON p.id=m.plan_id INNER JOIN %i c ON c.id=m.customer_id ORDER BY m.id DESC LIMIT %d',
                Database::table( 'memberships' ), Database::table( 'plans' ), Database::table( 'customers' ), $limit
            ), ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function for_customer( int $customer_id ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $this->expire_due();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.*, p.code AS plan_code, p.name AS plan_name, p.tier, p.plan_mode, p.entitlements_json, p.upgrade_plan_id, p.downgrade_plan_id FROM %i m INNER JOIN %i p ON p.id=m.plan_id WHERE m.customer_id=%d ORDER BY m.id DESC',
                Database::table( 'memberships' ), Database::table( 'plans' ), $customer_id
            ), ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function current_for_customer( int $customer_id ): ?array {
        foreach ( $this->for_customer( $customer_id ) as $row ) {
            if ( 'active' === (string) $row['status'] ) { return $row; }
        }
        return null;
    }

    public function set_status( int $membership_id, string $status ): bool {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( $membership_id < 1 || ! in_array( $status, self::statuses(), true ) ) { return false; }
        $current = $this->get( $membership_id );
        if ( ! $current ) { return false; }
        $now = current_time( 'mysql', true );
        $row = array( 'status'=>$status, 'updated_at'=>$now );
        if ( 'cancelled' === $status ) { $row['cancelled_at'] = $now; }
        if ( 'active' === $status ) { $row['cancelled_at'] = null; }
        $ok = false !== $wpdb->update( Database::table( 'memberships' ), $row, array( 'id'=>$membership_id ) );
        if ( $ok ) {
            EventBus::emit( 'membership.status_changed', array( 'membership_id'=>$membership_id, 'from'=>$current['status'], 'to'=>$status ), 'membership', $membership_id, (int)$current['customer_id'], (string)($current['source']??'evo') );
            do_action( 'evomembers_status_changed', $membership_id, $status );
        }
        return $ok;
    }

    public function delete( int $membership_id, bool $force = false ): bool {
        global $wpdb;
        $membership = $this->get( $membership_id );
        if ( ! $membership ) { return false; }
        $license_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE membership_id=%d', Database::table( 'licenses' ), $membership_id ) );
        if ( $license_count > 0 && ! $force ) { return $this->set_status( $membership_id, 'cancelled' ); }
        if ( $force ) {
            $license_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE membership_id=%d', Database::table( 'licenses' ), $membership_id ) );
            foreach ( (array)$license_ids as $license_id ) { ( new LicenseService() )->delete( (int)$license_id ); }
        }
        $ok = $wpdb->delete( Database::table( 'memberships' ), array( 'id'=>$membership_id ), array( '%d' ) );
        if ( false !== $ok ) { EventBus::emit( 'membership.deleted', array( 'membership_id'=>$membership_id, 'forced'=>$force ), 'membership', $membership_id, (int)$membership['customer_id'] ); }
        return false !== $ok;
    }

    public function statistics(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT status,COUNT(*) AS total FROM %i GROUP BY status', Database::table( 'memberships' ) ),
            ARRAY_A
        );
        $by_status = array();
        $total = 0;
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $status = sanitize_key( (string) ( $row['status'] ?? '' ) );
            $count  = (int) ( $row['total'] ?? 0 );
            if ( '' !== $status ) { $by_status[ $status ] = $count; }
            $total += $count;
        }

        return array(
            'total'     => $total,
            'active'    => (int) ( $by_status['active'] ?? 0 ),
            'pending'   => (int) ( $by_status['pending'] ?? 0 ),
            'past_due'  => (int) ( $by_status['past_due'] ?? 0 ),
            'suspended' => (int) ( $by_status['suspended'] ?? 0 ),
            'expired'   => (int) ( $by_status['expired'] ?? 0 ),
            'cancelled' => (int) ( $by_status['cancelled'] ?? 0 ),
            'refunded'  => (int) ( $by_status['refunded'] ?? 0 ),
            'by_status' => $by_status,
        );
    }

    public function count_for_customer( int $customer_id, bool $active_only = false ): int {
        global $wpdb;
        if ( $customer_id < 1 ) { return 0; }
        if ( $active_only ) {
            return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d AND status=%s AND (expires_at IS NULL OR expires_at>=%s)', Database::table( 'memberships' ), $customer_id, 'active', current_time( 'mysql', true ) ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'memberships' ), $customer_id ) );
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::table( 'memberships' ) ) );
    }

    public function customer_id_for_wp_user( int $wp_user_id ): int {
        global $wpdb;
        if ( $wp_user_id < 1 ) { return 0; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE wp_user_id=%d LIMIT 1', Database::table( 'customers' ), $wp_user_id ) );
    }

    public static function statuses(): array {
        return array( 'pending','active','past_due','suspended','expired','cancelled','refunded' );
    }

    private function expire_due(): void {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status=%s, updated_at=%s WHERE status=%s AND expires_at IS NOT NULL AND expires_at<%s', Database::table( 'memberships' ), 'expired', $now, 'active', $now ) );
    }
}
