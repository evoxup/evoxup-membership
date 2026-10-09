<?php
namespace EvoMembers\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves public purchase links without making WooCommerce or any external
 * provider the owner of the EVO product/plan model.
 */
final class PurchaseLinkService {
    public function product_url( array|int $product ): string {
        if ( is_int( $product ) ) {
            $product = ( new ProductService() )->get( $product ) ?: array();
        }
        if ( ! is_array( $product ) || empty( $product['id'] ) ) {
            return '';
        }

        $direct = esc_url_raw( (string) ( $product['purchase_url'] ?? '' ) );
        if ( '' !== $direct ) {
            return $direct;
        }

        if ( 'woocommerce' === (string) ( $product['source'] ?? '' ) ) {
            $native_id = absint( $product['external_source_id'] ?? 0 );
            if ( $native_id > 0 ) {
                $url = get_permalink( $native_id );
                if ( is_string( $url ) && '' !== $url ) {
                    return esc_url_raw( $url );
                }
            }
        }

        $wc_id = $this->woocommerce_product_for_evo_product( absint( $product['id'] ) );
        if ( $wc_id > 0 ) {
            $url = get_permalink( $wc_id );
            return is_string( $url ) ? esc_url_raw( $url ) : '';
        }

        return '';
    }

    public function plan_url( array|int $plan ): string {
        if ( is_int( $plan ) ) {
            $plan = ( new PlanService() )->get( $plan ) ?: array();
        }
        if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
            return '';
        }

        $wc_id = $this->woocommerce_product_for_plan( absint( $plan['id'] ) );
        if ( $wc_id > 0 ) {
            $url = get_permalink( $wc_id );
            return is_string( $url ) ? esc_url_raw( $url ) : '';
        }

        $products = is_array( $plan['products'] ?? null ) ? $plan['products'] : ( new PlanService() )->products( absint( $plan['id'] ) );
        foreach ( $products as $linked ) {
            $product = ( new ProductService() )->get( absint( $linked['product_id'] ?? $linked['id'] ?? 0 ) );
            if ( ! $product || 'active' !== (string) ( $product['status'] ?? '' ) ) {
                continue;
            }
            $url = $this->product_url( $product );
            if ( '' !== $url ) {
                return $url;
            }
        }

        return '';
    }

    public function membership_center_url(): string {
        $page_id = absint( get_option( 'evomembers_center_page_id', 0 ) );
        if ( $page_id < 1 ) {
            $page_id = absint( get_option( 'evomembers_my_membership_page_id', 0 ) );
        }
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( is_string( $url ) && '' !== $url ) {
                return esc_url_raw( $url );
            }
        }
        return home_url( '/evoxup-membership/' );
    }

    private function woocommerce_product_for_evo_product( int $evo_product_id ): int {
        if ( $evo_product_id < 1 || ! post_type_exists( 'product' ) ) {
            return 0;
        }
        $ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_key'       => '_evoxup_evo_product_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- targeted WooCommerce mapping lookup.
                'meta_value'     => (string) $evo_product_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- targeted WooCommerce mapping lookup.
            )
        );
        return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
    }

    private function woocommerce_product_for_plan( int $plan_id ): int {
        if ( $plan_id < 1 || ! post_type_exists( 'product' ) ) {
            return 0;
        }
        $ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_key'       => '_evoxup_membership_plan_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- targeted WooCommerce mapping lookup.
                'meta_value'     => (string) $plan_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- targeted WooCommerce mapping lookup.
            )
        );
        return ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
    }
}
