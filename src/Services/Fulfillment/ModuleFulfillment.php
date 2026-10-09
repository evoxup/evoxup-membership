<?php
namespace EvoMembers\Services\Fulfillment;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ModuleFulfillment {
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0 ): array|WP_Error {
        $result = \EvoMembers\Core\Hooks::apply_filters( 'module_fulfillment', null, $evo_order_id, $customer_id, $product, $normalized, $integration, $webhook_id );
        \EvoMembers\Core\Hooks::do_action( 'external_fulfillment', $evo_order_id, $customer_id, $product, $normalized, $integration, $webhook_id );
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! is_array( $result ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'module_fulfillment_unhandled' ), __( 'External module mode was selected, but no active EVO module registered a fulfillment handler for this sale.', 'evoxup-membership' ) );
        }
        return wp_parse_args( $result, array( 'evo_license_id' => 0, 'woocommerce_order_id' => 0, 'email_sent' => false ) );
    }
}
