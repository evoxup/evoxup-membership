<?php
namespace EvoMembers\Services;

use WP_Error;

use EvoMembers\Services\Fulfillment\EvoFulfillment;
use EvoMembers\Services\Fulfillment\WooCommerceFulfillment;
use EvoMembers\Services\Fulfillment\CombinedFulfillment;
use EvoMembers\Services\Fulfillment\ModuleFulfillment;

defined( 'ABSPATH' ) || exit;

final class FulfillmentRouter {
    public function run( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0, string $effective_mode = '' ): array|WP_Error {
        $mode = sanitize_key( $effective_mode ?: (string) ( $product['fulfillment_mode'] ?? 'evo' ) );
        if ( 'none' === $mode ) {
            $result = array(
                'mode' => 'none',
                'engine' => 'none',
                'evo_license_id' => 0,
                'evo_license_created' => false,
                'woocommerce_order_id' => 0,
                'woocommerce_created' => false,
                'email_sent' => false,
            );
            \EvoMembers\Core\Hooks::do_action( 'fulfillment_completed', 'none', $result, $product, $customer_id, $evo_order_id, $normalized, $integration );
            return $result;
        }

        $handlers = array(
            'evo'           => new EvoFulfillment(),
            'woocommerce'   => new WooCommerceFulfillment(),
            'both'          => new CombinedFulfillment(),
            'external_hook' => new ModuleFulfillment(),
        );
        if ( ! isset( $handlers[ $mode ] ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'fulfillment_mode_invalid' ), __( 'The product fulfillment mode is invalid.', 'evoxup-membership' ) );
        }
        $result = $handlers[ $mode ]->fulfill( $evo_order_id, $customer_id, $product, $normalized, $integration, $webhook_id );
        if ( ! is_wp_error( $result ) ) {
            $result['mode'] = $mode;
            $result['engine'] = $mode;
            \EvoMembers\Core\Hooks::do_action( 'fulfillment_completed', $mode, $result, $product, $customer_id, $evo_order_id, $normalized, $integration );
        }
        return $result;
    }
}
