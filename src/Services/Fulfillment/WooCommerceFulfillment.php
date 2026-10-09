<?php
namespace EvoMembers\Services\Fulfillment;

use EvoMembers\Services\WooCommerceService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WooCommerceFulfillment {
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0 ): array|WP_Error {
        $wc = ( new WooCommerceService() )->fulfill( $evo_order_id, $customer_id, $product, $normalized, $integration, 0 );
        if ( is_wp_error( $wc ) ) { return $wc; }
        return array( 'evo_license_id' => 0, 'evo_license_created' => false, 'woocommerce_order_id' => (int) ( $wc['woocommerce_order_id'] ?? 0 ), 'woocommerce_created' => ! empty( $wc['created'] ), 'email_sent' => false );
    }
}
