<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WooCommerceService {
    public function available(): bool {
        return function_exists( 'wc_create_order' ) && function_exists( 'wc_get_product' );
    }

    /**
     * Mirror an externally-paid sale into WooCommerce so normal WooCommerce
     * customer/order hooks and installed licensing extensions can run.
     */
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $evo_license_id = 0 ): array|WP_Error {
        global $wpdb;

        if ( ! $this->available() ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'woocommerce_unavailable' ), __( 'WooCommerce is not available.', 'evoxup-membership' ) );
        }
        if ( $evo_order_id < 1 || $customer_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'fulfillment_missing_data' ), __( 'Order and customer are required for WooCommerce fulfillment.', 'evoxup-membership' ) );
        }

        $existing_wc_id = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT woocommerce_order_id FROM %i WHERE id = %d LIMIT 1', Database::table( 'orders' ), $evo_order_id )
        );
        if ( $existing_wc_id > 0 && function_exists( 'wc_get_order' ) && wc_get_order( $existing_wc_id ) ) {
            return array( 'woocommerce_order_id' => $existing_wc_id, 'created' => false );
        }

        $customer_service = new CustomerService();
        $customer         = $customer_service->get( $customer_id );
        if ( ! $customer ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_not_found' ), __( 'Customer not found.', 'evoxup-membership' ) );
        }
        $wp_user_id = $customer_service->ensure_wp_user( $customer_id, $normalized['customer'] ?? array() );
        // A verified external sale must not remotely create a WordPress user.
        // If no local account exists, mirror the sale as a WooCommerce guest order.
        if ( is_wp_error( $wp_user_id ) ) {
            $wp_user_id = 0;
        }

        $wc_product_id = 0;
        if ( 'woocommerce' === ( $product['source'] ?? '' ) ) {
            $wc_product_id = absint( $product['external_source_id'] ?? 0 );
        }
        $wc_product_id = absint(
            \EvoMembers\Core\Hooks::apply_filters( 'woocommerce_product_id',
                $wc_product_id,
                $product,
                $normalized,
                $integration
            )
        );
        if ( $wc_product_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'woocommerce_product_missing' ), __( 'The EVO product is not linked to a WooCommerce product.', 'evoxup-membership' ) );
        }

        $wc_product = wc_get_product( $wc_product_id );
        if ( ! $wc_product ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'woocommerce_product_not_found' ), __( 'Linked WooCommerce product was not found.', 'evoxup-membership' ) );
        }

        try {
            $order = wc_create_order( array( 'customer_id' => (int) $wp_user_id ) );
            if ( is_wp_error( $order ) ) {
                return $order;
            }

            $wc_item_id = (int) $order->add_product( $wc_product, 1 );
            if ( $evo_license_id > 0 && $wc_item_id > 0 ) {
                $item = $order->get_item( $wc_item_id );
                if ( $item ) {
                    $plain_key = ( new LicenseService() )->get_plain_key( $evo_license_id );
                    if ( '' !== $plain_key ) {
                        $item->add_meta_data( __( 'EVO License Key', 'evoxup-membership' ), $plain_key, false );
                        $item->save_meta_data();
                    }
                }
            }
            $address = $this->billing_address( $customer );
            if ( $address ) {
                $order->set_address( $address, 'billing' );
            }

            $order_data   = $normalized['order'] ?? array();
            $external_id  = sanitize_text_field( (string) ( $order_data['external_order_id'] ?? '' ) );
            $transaction  = sanitize_text_field( (string) ( $order_data['external_transaction_id'] ?? $external_id ) );
            $provider     = sanitize_key( (string) ( $integration['slug'] ?? 'external' ) );

            $order->set_created_via( 'evoxup-membership' );
            $order->set_payment_method_title( sprintf( 'External: %s', $provider ) );
            \EvoMembers\Core\MembershipIdentifiers::migrate_order_meta( $order, '_evoxup_membership_evo_order_id', $evo_order_id );
            \EvoMembers\Core\MembershipIdentifiers::migrate_order_meta( $order, '_evoxup_membership_provider', $provider );
            if ( '' !== $external_id ) {
                \EvoMembers\Core\MembershipIdentifiers::migrate_order_meta( $order, '_evoxup_membership_external_order_id', $external_id );
            }
            if ( $evo_license_id > 0 ) {
                \EvoMembers\Core\MembershipIdentifiers::migrate_order_meta( $order, '_evoxup_membership_license_id', $evo_license_id );
            }

            $order->calculate_totals( false );
            $order->save();

            \EvoMembers\Core\Hooks::do_action( 'before_woocommerce_order_complete', $order->get_id(), $evo_order_id, $customer_id, $product, $normalized );

            if ( '' !== $transaction ) {
                $order->payment_complete( $transaction );
            } else {
                $order->payment_complete();
            }
            \EvoMembers\Core\Hooks::do_action( 'woocommerce_external_payment_complete', $order->get_id(), $wc_product_id, $customer_id, $evo_license_id, $normalized );
            if ( ! $order->has_status( 'completed' ) ) {
                $order->update_status( 'completed', __( 'Completed automatically by Evoxup Membership after verified external sale.', 'evoxup-membership' ), true );
            }

            $wc_order_id = (int) $order->get_id();
            $wpdb->update(
                Database::table( 'orders' ),
                array(
                    'woocommerce_order_id' => $wc_order_id,
                    'status'               => 'completed',
                    'payment_status'       => 'paid',
                    'paid_at'              => current_time( 'mysql', true ),
                    'updated_at'           => current_time( 'mysql', true ),
                ),
                array( 'id' => $evo_order_id )
            );

            \EvoMembers\Core\Hooks::do_action( 'after_woocommerce_order_complete', $wc_order_id, $evo_order_id, $customer_id, $product, $normalized );

            $external = \EvoMembers\Core\Hooks::apply_filters( 'external_license',
                null,
                $wc_order_id,
                $wc_product_id,
                $customer_id,
                $evo_license_id,
                $normalized
            );
            if ( $evo_license_id > 0 && is_array( $external ) && ! empty( $external['key'] ) ) {
                ( new LicenseService() )->attach_external_license(
                    $evo_license_id,
                    (string) ( $external['provider'] ?? 'woocommerce-extension' ),
                    (string) $external['key'],
                    (string) ( $external['status'] ?? 'active' )
                );
            }

            Logger::write(
                'woocommerce_order_created',
                array( 'evo_order_id' => $evo_order_id, 'woocommerce_order_id' => $wc_order_id, 'woocommerce_product_id' => $wc_product_id ),
                'info',
                $customer_id,
                $evo_license_id ?: null
            );

            return array( 'woocommerce_order_id' => $wc_order_id, 'created' => true );
        } catch ( \Throwable $e ) {
            Logger::write( 'woocommerce_fulfillment_failed', array( 'message' => $e->getMessage(), 'evo_order_id' => $evo_order_id ), 'error', $customer_id, $evo_license_id ?: null );
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'woocommerce_fulfillment_failed' ), $e->getMessage() );
        }
    }

    private function billing_address( array $customer ): array {
        $map = array(
            'first_name' => 'first_name',
            'last_name'  => 'last_name',
            'company'    => 'company',
            'address_1'  => 'address_1',
            'address_2'  => 'address_2',
            'city'       => 'city',
            'state'      => 'state_region',
            'postcode'   => 'postal_code',
            'country'    => 'country_code',
            'email'      => 'email',
            'phone'      => 'phone',
        );
        $address = array();
        foreach ( $map as $wc_key => $customer_key ) {
            if ( ! empty( $customer[ $customer_key ] ) ) {
                $address[ $wc_key ] = sanitize_text_field( (string) $customer[ $customer_key ] );
            }
        }
        return $address;
    }
}
