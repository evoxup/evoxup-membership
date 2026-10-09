<?php
namespace EvoMembers\Services\Fulfillment;

use EvoMembers\Services\WooCommerceService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CombinedFulfillment {
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0 ): array|WP_Error {
        // Licensing and order mirroring are deliberately separate concerns.
        // EvoFulfillment chooses EVO/external/no-license from Product settings;
        // WooCommerceService only mirrors the commercial order when requested.
        $license = ( new EvoFulfillment() )->fulfill( $evo_order_id, $customer_id, $product, $normalized, $integration, $webhook_id );
        if ( is_wp_error( $license ) ) { return $license; }

        $first_license_id = absint( $license['evo_license_id'] ?? 0 );
        $wc = ( new WooCommerceService() )->fulfill( $evo_order_id, $customer_id, $product, $normalized, $integration, $first_license_id );
        if ( is_wp_error( $wc ) ) { return $wc; }

        return array_merge(
            $license,
            array(
                'woocommerce_order_id' => (int) ( $wc['woocommerce_order_id'] ?? 0 ),
                'woocommerce_created'  => ! empty( $wc['created'] ),
                'email_sent'           => false,
            )
        );
    }
}
