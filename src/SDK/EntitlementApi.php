<?php
namespace EvoMembers\SDK;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Entitlement resolution intentionally reads current EVO-owned operational tables. There is no equivalent WordPress CRUD API, and persistent caching here could grant stale access after a license, order, or membership status change.

use EvoMembers\Contracts\Api\EntitlementApiInterface;
use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class EntitlementApi implements EntitlementApiInterface {
    public function product_ids_for_customer( int $customer_id ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT product_id FROM (
                    SELECT product_id FROM %i WHERE customer_id=%d AND product_id IS NOT NULL AND status=%s AND (expires_at IS NULL OR expires_at>=%s)
                    UNION
                    SELECT oi.product_id FROM %i oi INNER JOIN %i o ON o.id=oi.order_id WHERE o.customer_id=%d AND oi.product_id IS NOT NULL
                    UNION
                    SELECT pp.product_id FROM %i pp INNER JOIN %i m ON m.plan_id=pp.plan_id WHERE m.customer_id=%d AND m.status=%s AND (m.expires_at IS NULL OR m.expires_at>=%s)
                ) owned_products ORDER BY product_id ASC",
                Database::table( 'licenses' ),
                $customer_id,
                'active',
                current_time( 'mysql', true ),
                Database::table( 'order_items' ),
                Database::table( 'orders' ),
                $customer_id,
                Database::table( 'plan_products' ),
                Database::table( 'memberships' ),
                $customer_id,
                'active',
                current_time( 'mysql', true )
            )
        );
        return array_values( array_unique( array_filter( array_map( 'absint', is_array( $rows ) ? $rows : array() ) ) ) );
    }

    public function product_count_for_customer( int $customer_id ): int {
        return count( $this->product_ids_for_customer( $customer_id ) );
    }
}
