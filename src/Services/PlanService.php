<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO owns versioned evomembers_* operational tables and deliberately reads transactional state fresh.

use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class PlanService {
    public function all( bool $active_only = false ): array {
        global $wpdb;

        if ( $active_only ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT p.*,COALESCE(pp.product_count,0) AS product_count,COALESCE(mm.active_members,0) AS active_members FROM %i p LEFT JOIN (SELECT plan_id,COUNT(*) AS product_count FROM %i GROUP BY plan_id) pp ON pp.plan_id=p.id LEFT JOIN (SELECT plan_id,COUNT(*) AS active_members FROM %i WHERE status=%s GROUP BY plan_id) mm ON mm.plan_id=p.id WHERE p.status=%s ORDER BY p.id DESC',
                    Database::table( 'plans' ),
                    Database::table( 'plan_products' ),
                    Database::table( 'memberships' ),
                    'active',
                    'active'
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT p.*,COALESCE(pp.product_count,0) AS product_count,COALESCE(mm.active_members,0) AS active_members FROM %i p LEFT JOIN (SELECT plan_id,COUNT(*) AS product_count FROM %i GROUP BY plan_id) pp ON pp.plan_id=p.id LEFT JOIN (SELECT plan_id,COUNT(*) AS active_members FROM %i WHERE status=%s GROUP BY plan_id) mm ON mm.plan_id=p.id ORDER BY p.id DESC',
                    Database::table( 'plans' ),
                    Database::table( 'plan_products' ),
                    Database::table( 'memberships' ),
                    'active'
                ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : array();
    }

    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) { return null; }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', Database::table( 'plans' ), $id ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) { return null; }
        $row['products'] = $this->products( $id );
        return $row;
    }

    public function save( array $data, int $id = 0 ): int|false {
        global $wpdb;
        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( '' === $code || '' === $name ) { return false; }

        $tier = sanitize_key( (string) ( $data['tier'] ?? 'custom' ) );
        if ( ! in_array( $tier, array( 'free', 'pro', 'super_star', 'custom' ), true ) ) { $tier = 'custom'; }
        $status = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
        if ( ! in_array( $status, array( 'active', 'inactive', 'frozen', 'archived' ), true ) ) { $status = 'active'; }

        $entitlements = $data['entitlements'] ?? array();
        if ( is_string( $entitlements ) ) { $entitlements = preg_split( '/[\r\n,]+/', $entitlements ); }
        $entitlements = is_array( $entitlements )
            ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $entitlements ) ) ) )
            : array();

        $product_ids = isset( $data['product_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', (array) $data['product_ids'] ) ) ) )
            : array();
        $plan_mode = $this->mode_from_count( count( $product_ids ) );
        if ( $id > 0 && ! isset( $data['product_ids'] ) ) {
            $plan_mode = $this->mode_from_count( count( $this->products( $id ) ) );
        }

        $upgrade_plan_id   = absint( $data['upgrade_plan_id'] ?? 0 ) ?: null;
        $downgrade_plan_id = absint( $data['downgrade_plan_id'] ?? 0 ) ?: null;
        if ( $upgrade_plan_id === $id ) { $upgrade_plan_id = null; }
        if ( $downgrade_plan_id === $id ) { $downgrade_plan_id = null; }

        $now = current_time( 'mysql', true );
        $row = array(
            'code'               => $code,
            'name'               => $name,
            'description'        => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
            'tier'               => $tier,
            'plan_mode'          => $plan_mode,
            'status'             => $status,
            'duration_days'      => absint( $data['duration_days'] ?? 0 ) ?: null,
            'grace_days'         => absint( $data['grace_days'] ?? 0 ),
            'auto_renew_default' => ! empty( $data['auto_renew_default'] ) ? 1 : 0,
            'upgrade_plan_id'    => $upgrade_plan_id,
            'downgrade_plan_id'  => $downgrade_plan_id,
            'entitlements_json'  => wp_json_encode( $entitlements ),
            'metadata_json'      => ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
            'updated_at'         => $now,
        );

        if ( $id > 0 ) {
            if ( false === $wpdb->update( Database::table( 'plans' ), $row, array( 'id' => $id ) ) ) { return false; }
        } else {
            $row['created_at'] = $now;
            if ( false === $wpdb->insert( Database::table( 'plans' ), $row ) ) { return false; }
            $id = (int) $wpdb->insert_id;
        }

        if ( isset( $data['product_ids'] ) ) { $this->sync_products( $id, $product_ids ); }
        EventBus::emit( 'plan.saved', array( 'plan_id'=>$id, 'tier'=>$tier, 'mode'=>$plan_mode, 'status'=>$status ), 'plan', $id );
        return $id;
    }

    public function sync_products( int $plan_id, array $product_ids ): void {
        global $wpdb;
        if ( $plan_id < 1 ) { return; }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
        $wpdb->delete( Database::table( 'plan_products' ), array( 'plan_id'=>$plan_id ), array( '%d' ) );
        $now = current_time( 'mysql', true );
        foreach ( $ids as $index=>$product_id ) {
            $wpdb->insert( Database::table( 'plan_products' ), array(
                'plan_id'=>$plan_id,
                'product_id'=>$product_id,
                'quantity'=>1,
                'is_primary'=>0 === $index ? 1 : 0,
                'created_at'=>$now,
                'updated_at'=>$now,
            ) );
        }
        $this->refresh_mode( $plan_id );
    }

    public function refresh_mode( int $plan_id ): void {
        global $wpdb;
        if ( $plan_id < 1 ) { return; }
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%d', Database::table( 'plan_products' ), $plan_id )
        );
        $wpdb->update(
            Database::table( 'plans' ),
            array( 'plan_mode'=>$this->mode_from_count( $count ), 'updated_at'=>current_time( 'mysql', true ) ),
            array( 'id'=>$plan_id )
        );
    }

    public function products( int $plan_id ): array {
        global $wpdb;
        if ( $plan_id < 1 ) { return array(); }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.id,p.code,p.name,p.product_type,p.source,p.external_source_id,p.image_id,p.image_url,p.price,p.sale_price,p.currency,p.purchase_url,p.status,p.requires_license,p.license_provider,p.license_seats,pp.quantity,pp.is_primary FROM %i pp INNER JOIN %i p ON p.id=pp.product_id WHERE pp.plan_id=%d ORDER BY pp.is_primary DESC,pp.id ASC',
                Database::table( 'plan_products' ),
                Database::table( 'products' ),
                $plan_id
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function plans_for_product( int $product_id ): array {
        global $wpdb;
        if ( $product_id < 1 ) { return array(); }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.id,p.code,p.name,p.description,p.tier,p.plan_mode,p.status,p.duration_days,p.grace_days,p.auto_renew_default,p.upgrade_plan_id,p.downgrade_plan_id,p.entitlements_json,p.metadata_json,p.created_at,p.updated_at,pp.is_primary,pp.quantity FROM %i pp INNER JOIN %i p ON p.id=pp.plan_id WHERE pp.product_id=%d ORDER BY pp.is_primary DESC,FIELD(p.status,%s,%s,%s,%s),p.id ASC',
                Database::table( 'plan_products' ),
                Database::table( 'plans' ),
                $product_id,
                'active','inactive','frozen','archived'
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function member_counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT plan_id,status,COUNT(*) AS total FROM %i GROUP BY plan_id,status', Database::table( 'memberships' ) ),
            ARRAY_A
        );
        $result = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            $plan_id = absint( $row['plan_id'] ?? 0 );
            $status = sanitize_key( (string) ( $row['status'] ?? '' ) );
            if ( $plan_id > 0 && '' !== $status ) { $result[$plan_id][$status] = (int) $row['total']; }
        }
        return $result;
    }

    public function delete( int $id, bool $force = false ): bool {
        global $wpdb;
        if ( $id < 1 ) { return false; }
        $memberships = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%d', Database::table( 'memberships' ), $id )
        );
        if ( $memberships > 0 && ! $force ) {
            return false !== $wpdb->update(
                Database::table( 'plans' ),
                array( 'status'=>'archived', 'updated_at'=>current_time( 'mysql', true ) ),
                array( 'id'=>$id )
            );
        }
        $wpdb->delete( Database::table( 'plan_products' ), array( 'plan_id'=>$id ), array( '%d' ) );
        $wpdb->update( Database::table( 'plans' ), array( 'upgrade_plan_id'=>null ), array( 'upgrade_plan_id'=>$id ) );
        $wpdb->update( Database::table( 'plans' ), array( 'downgrade_plan_id'=>null ), array( 'downgrade_plan_id'=>$id ) );
        return false !== $wpdb->delete( Database::table( 'plans' ), array( 'id'=>$id ), array( '%d' ) );
    }

    private function mode_from_count( int $count ): string {
        if ( $count < 1 ) { return 'empty'; }
        return 1 === $count ? 'single' : 'bundle';
    }
}
