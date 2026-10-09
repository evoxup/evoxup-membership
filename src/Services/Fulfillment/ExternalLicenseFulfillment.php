<?php
namespace EvoMembers\Services\Fulfillment;

use EvoMembers\Providers\LicenseProviderRegistry;
use EvoMembers\Services\IntegrationService;
use EvoMembers\Services\LicenseService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Delegates license issuance to an optional external provider adapter and then
 * records the returned keys inside EVO without pretending EVO generated them.
 */
final class ExternalLicenseFulfillment {
    public function fulfill( int $evo_order_id, int $customer_id, array $product, array $normalized, array $integration, int $webhook_id = 0 ): array|WP_Error {
        $routing = is_array( $normalized['_evo_routing']['license_settings'] ?? null ) ? $normalized['_evo_routing']['license_settings'] : array();
        $provider_slug = sanitize_key( (string) ( $routing['provider'] ?? $product['license_provider'] ?? 'external' ) );
        if ( in_array( $provider_slug, array( '', 'none', 'evo', 'external' ), true ) ) {
            $provider_slug = sanitize_key( (string) ( $routing['external_provider'] ?? $integration['slug'] ?? 'external' ) ) ?: 'external';
        }

        $seat_count = max( 1, absint( $routing['license_seats'] ?? $product['license_seats'] ?? 1 ) );
        $context = array(
            'provider'       => $provider_slug,
            'order_id'       => $evo_order_id,
            'customer_id'    => $customer_id,
            'product'        => $product,
            'normalized'     => $normalized,
            'integration'    => $integration,
            'webhook_id'     => $webhook_id,
            'license_seats'  => $seat_count,
            'membership_id'  => absint( $normalized['_evo_membership']['membership_id'] ?? $normalized['_evo_routing']['membership_id'] ?? 0 ),
        );

        $result = null;
        $adapter = LicenseProviderRegistry::get( $provider_slug );
        if ( $adapter && $adapter->supports( 'issue' ) ) {
            $result = $adapter->issue( $context );
        } else {
            $result = \EvoMembers\Core\Hooks::apply_filters( 'external_license_issue', null, $provider_slug, $context );
        }

        // A provider webhook may already contain a key even when no active API
        // adapter can issue one. Record it rather than generating a fake EVO key.
        if ( null === $result ) {
            $incoming = is_array( $normalized['license'] ?? null ) ? $normalized['license'] : array();
            $key = sanitize_text_field( (string) ( $incoming['key'] ?? '' ) );
            if ( '' !== $key ) {
                $result = array(
                    'license_key' => $key,
                    'status'      => sanitize_key( (string) ( $incoming['status'] ?? 'active' ) ) ?: 'active',
                    'reference'   => sanitize_text_field( (string) ( $incoming['reference'] ?? '' ) ),
                );
            }
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! is_array( $result ) ) {
            return new WP_Error(
                \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_unhandled' ),
                __( 'This product uses an external license provider, but no provider adapter returned a license.', 'evoxup-membership' )
            );
        }

        $items = array();
        if ( ! empty( $result['licenses'] ) && is_array( $result['licenses'] ) ) {
            $items = $result['licenses'];
        } elseif ( ! empty( $result['license_keys'] ) && is_array( $result['license_keys'] ) ) {
            foreach ( $result['license_keys'] as $key ) {
                $items[] = array( 'license_key' => $key, 'status' => $result['status'] ?? 'active' );
            }
        } elseif ( ! empty( $result['license_key'] ) ) {
            $items[] = $result;
        }

        if ( ! $items ) {
            return new WP_Error(
                \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_missing_key' ),
                __( 'The external provider responded, but no license key was returned.', 'evoxup-membership' )
            );
        }

        $licenses = new LicenseService();
        $recorded = array();
        $total = count( $items );
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                $item = array( 'license_key' => (string) $item );
            }
            $key = trim( (string) ( $item['license_key'] ?? $item['key'] ?? '' ) );
            if ( '' === $key ) {
                continue;
            }
            $registered = $licenses->register_external(
                array(
                    'customer_id'       => $customer_id,
                    'product_id'        => absint( $normalized['_evo_routing']['product_id'] ?? $product['id'] ?? 0 ),
                    'membership_id'     => absint( $context['membership_id'] ),
                    'provider'          => $provider_slug,
                    'license_key'       => $key,
                    'status'              => sanitize_key( (string) ( $item['status'] ?? $result['status'] ?? 'active' ) ) ?: 'active',
                    'external_license_id' => sanitize_text_field( (string) ( $item['id'] ?? $item['license_id'] ?? $result['license_id'] ?? '' ) ),
                    'external_reference'  => sanitize_text_field( (string) ( $item['reference'] ?? $result['reference'] ?? '' ) ),
                    'seat_number'         => $index + 1,
                    'seat_total'        => $total,
                    'order_id'          => $evo_order_id,
                    'source'            => sanitize_key( (string) ( $integration['slug'] ?? 'external' ) ) ?: 'external',
                    'duration_days'     => absint( $routing['duration_days'] ?? $product['license_duration_days'] ?? 0 ),
                    'verification_mode' => sanitize_key( (string) ( $routing['verification_mode'] ?? $product['verification_mode'] ?? 'portable' ) ),
                    'allowed_site_url'  => esc_url_raw( (string) ( $routing['allowed_site_url'] ?? $product['allowed_site_url'] ?? '' ) ),
                    'allowed_domain'    => sanitize_text_field( (string) ( $routing['allowed_domain'] ?? $product['allowed_domain'] ?? '' ) ),
                    'allowed_ip'        => sanitize_text_field( (string) ( $routing['allowed_ip'] ?? $product['allowed_ip'] ?? '' ) ),
                    'metadata'          => array(
                        'provider_reference' => sanitize_text_field( (string) ( $item['reference'] ?? $result['reference'] ?? '' ) ),
                        'webhook_event_id'   => $webhook_id,
                        'integration_id'     => absint( $integration['id'] ?? 0 ),
                    ),
                )
            );
            if ( is_wp_error( $registered ) ) {
                return $registered;
            }
            $recorded[] = $registered;
        }

        if ( ! $recorded ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_not_recorded' ), __( 'No external license could be recorded.', 'evoxup-membership' ) );
        }

        $new_records = array_values(
            array_filter(
                $recorded,
                static fn( array $row ): bool => empty( $row['already_notified'] )
            )
        );
        $email_sent = false;
        if ( $new_records && $customer_id > 0 ) {
            $email_sent = $licenses->send_customer_group_email(
                $new_records,
                array(
                    'customer_id'          => $customer_id,
                    'product_id'           => absint( $normalized['_evo_routing']['product_id'] ?? $product['id'] ?? 0 ),
                    'external_product_name'=> sanitize_text_field( (string) ( $product['name'] ?? $normalized['product']['name'] ?? 'EVO' ) ),
                    'source'               => sanitize_key( (string) ( $integration['slug'] ?? 'external' ) ) ?: 'external',
                )
            );
            if ( $email_sent ) {
                $licenses->mark_notified( array_map( static fn( array $row ): int => (int) $row['id'], $new_records ) );
            }
        }

        return array(
            'license_provider'   => $provider_slug,
            'evo_license_id'     => (int) ( $recorded[0]['id'] ?? 0 ),
            'evo_license_ids'    => array_map( static fn( array $row ): int => (int) $row['id'], $recorded ),
            'license_key'        => (string) ( $recorded[0]['license_key'] ?? '' ),
            'license_keys'       => array_map( static fn( array $row ): string => (string) $row['license_key'], $recorded ),
            'evo_license_created'=> true,
            'external_license'   => true,
            'woocommerce_order_id'=> 0,
            'email_sent'         => $email_sent,
        );
    }
}
