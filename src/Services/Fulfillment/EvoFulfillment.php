<?php
namespace EvoMembers\Services\Fulfillment;

use EvoMembers\Services\LicenseService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class EvoFulfillment {
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0 ): array|WP_Error {
        $route = is_array( $normalized['_evo_routing']['license_settings'] ?? null ) ? $normalized['_evo_routing']['license_settings'] : array();
        $requires_license = ! empty( $route['requires_license'] ) || ! empty( $product['requires_license'] );
        $provider = sanitize_key( (string) ( $route['provider'] ?? $product['license_provider'] ?? ( $requires_license ? 'evo' : 'none' ) ) );
        if ( ! $requires_license || 'none' === $provider ) {
            return array( 'license_provider'=>'none', 'evo_license_id'=>0, 'evo_license_ids'=>array(), 'evo_license_created'=>false, 'license_key'=>'', 'license_keys'=>array(), 'woocommerce_order_id'=>0, 'email_sent'=>false );
        }
        if ( 'evo' !== $provider ) {
            return ( new ExternalLicenseFulfillment() )->fulfill( $evo_order_id, $customer_id, $product, $normalized, $integration, $webhook_id );
        }
        $licenses = new LicenseService();
        $existing = $evo_order_id > 0 ? $licenses->for_created_order( $evo_order_id, true ) : array();
        if ( $existing ) {
            $ids = array_map( static fn( array $row ): int => (int) $row['id'], $existing );
            $keys = array_map( static fn( array $row ): string => (string) ( $row['license_key'] ?? '' ), $existing );
            return array( 'evo_license_id' => $ids[0] ?? 0, 'evo_license_ids'=>$ids, 'license_key'=>$keys[0] ?? '', 'license_keys'=>$keys, 'evo_license_created' => false, 'woocommerce_order_id' => 0, 'email_sent' => false );
        }
        $seat_count = max( 1, absint( $route['license_seats'] ?? $route['max_activations'] ?? $product['license_seats'] ?? $product['max_activations'] ?? 1 ) );
        $issued = $licenses->issue_many( array(
            'customer_id'          => $customer_id,
            'product_id'           => absint( $normalized['_evo_routing']['product_id'] ?? $product['evo_product_id'] ?? $product['id'] ?? 0 ),
            'membership_id'        => absint( $normalized['_evo_membership']['membership_id'] ?? $normalized['_evo_routing']['membership_id'] ?? 0 ),
            'external_product_name'=> (string) ( $product['name'] ?? '' ),
            'external_product_code'=> (string) ( $normalized['product']['external_product_id'] ?? '' ),
            'order_id'             => $evo_order_id,
            'source'               => sanitize_key( (string) ( $integration['slug'] ?? 'evo' ) ) ?: 'evo',
            'max_activations'      => 1,
            'duration_days'        => absint( $route['duration_days'] ?? $product['license_duration_days'] ?? 0 ),
            'license_type_id'      => absint( $route['license_type_id'] ?? $product['license_type_id'] ?? 0 ),
            'verification_mode'    => sanitize_key( (string) ( $route['verification_mode'] ?? $product['verification_mode'] ?? 'portable' ) ),
            'allowed_site_url'     => esc_url_raw( (string) ( $route['allowed_site_url'] ?? $product['allowed_site_url'] ?? '' ) ),
            'allowed_domain'       => sanitize_text_field( (string) ( $route['allowed_domain'] ?? $product['allowed_domain'] ?? '' ) ),
            'allowed_ip'           => sanitize_text_field( (string) ( $route['allowed_ip'] ?? $product['allowed_ip'] ?? '' ) ),
            'metadata'             => array( 'webhook_event_id' => $webhook_id, 'integration_id' => (int) ( $integration['id'] ?? 0 ), 'provider' => sanitize_key( (string) ( $integration['slug'] ?? '' ) ) ),
        ), $seat_count );
        if ( is_wp_error( $issued ) ) { return $issued; }
        $ids = array_map( static fn( array $row ): int => (int) $row['id'], $issued );
        $keys = array_map( static fn( array $row ): string => (string) $row['license_key'], $issued );
        return array( 'evo_license_id' => $ids[0] ?? 0, 'evo_license_ids'=>$ids, 'evo_license_created' => true, 'license_key' => $keys[0] ?? '', 'license_keys'=>$keys, 'woocommerce_order_id' => 0, 'email_sent' => false, 'email_triggered_by_event' => true );
    }
}
