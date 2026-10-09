<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use EvoMembers\Integrations\WooCommerce\ProductLicensing;

defined( 'ABSPATH' ) || exit;

final class MappingService {
    private string $last_error = '';

    public function last_error(): string {
        return $this->last_error;
    }

    public function save( array $data ): bool {
        global $wpdb;

        $this->last_error = '';
        $integration_id   = absint( $data['integration_id'] ?? 0 );
        $external_product = sanitize_text_field( (string) ( $data['external_product_id'] ?? '' ) );
        $variant          = sanitize_text_field( (string) ( $data['external_variant_id'] ?? '' ) );
        $mapping_id       = absint( $data['mapping_id'] ?? 0 );
        $target_type      = sanitize_key( (string) ( $data['target_type'] ?? 'evo' ) );
        $target_type      = in_array( $target_type, array( 'evo', 'woocommerce' ), true ) ? $target_type : 'evo';
        $target_id        = absint( $data['target_id'] ?? $data['product_id'] ?? 0 );
        $plan_id          = absint( $data['plan_id'] ?? 0 );

        if ( $integration_id < 1 ) {
            return $this->fail( 'Select an integration/platform before saving the route.' );
        }
        if ( '' === $external_product ) {
            return $this->fail( 'External product ID is required.' );
        }
        if ( $target_id < 1 ) {
            return $this->fail( 'Select the EVO or WooCommerce target product.' );
        }
        if ( ! ( new IntegrationService() )->get( $integration_id ) ) {
            return $this->fail( 'The selected integration no longer exists.' );
        }

        // Keep product_id populated for EVO routes. WooCommerce routes use 0 as
        // a compatibility fallback for 0.9.0 databases where product_id may
        // still be NOT NULL; target_type/target_id remain the source of truth.
        $product_id = 0;
        if ( 'evo' === $target_type ) {
            if ( ! ( new ProductService() )->get( $target_id ) ) {
                return $this->fail( 'The selected EVO product could not be found.' );
            }
            $product_id = $target_id;
        } elseif ( ! function_exists( 'wc_get_product' ) ) {
            return $this->fail( 'WooCommerce is not active, so a WooCommerce route cannot be saved.' );
        } elseif ( ! wc_get_product( $target_id ) ) {
            return $this->fail( 'The selected WooCommerce product could not be found.' );
        }

        // Product routing only decides where an external product goes.
        // Licensing belongs to Product Manager. WooCommerce targets resolve
        // their W-product policy first, with Woo post meta only as a legacy
        // compatibility fallback.
        $meta = array(
            'target_type' => $target_type,
            'target_id'   => $target_id,
        );
        $now = current_time( 'mysql', true );
        $payload = array(
            'integration_id'      => $integration_id,
            'external_product_id' => $external_product,
            // Store the empty variant as an empty string rather than NULL so
            // the composite unique key behaves consistently across MySQL hosts.
            'external_variant_id' => $variant,
            'product_id'          => $product_id,
            'target_type'         => $target_type,
            'target_id'           => $target_id,
            'plan_id'             => $plan_id > 0 ? $plan_id : null,
            'fulfillment_mode'    => 'inherit',
            'status'              => 'active',
            'metadata_json'       => wp_json_encode( $meta ),
            'updated_at'          => $now,
        );

        // Saving the same platform/product/variant again updates the existing
        // route instead of failing with a duplicate-key database error.
        if ( $mapping_id < 1 ) {
            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE integration_id=%d AND external_product_id=%s AND COALESCE(external_variant_id,%s)=%s ORDER BY id ASC LIMIT 1',
                    Database::table( 'product_mappings' ),
                    $integration_id,
                    $external_product,
                    '',
                    $variant
                )
            );
            if ( $existing_id > 0 ) {
                $mapping_id = $existing_id;
            }
        }

        if ( $mapping_id > 0 ) {
            $result = $wpdb->update( Database::table( 'product_mappings' ), $payload, array( 'id' => $mapping_id ) );
            if ( false === $result ) {
                return $this->fail_db( 'The product route could not be updated.' );
            }
            return true;
        }

        $payload['created_at'] = $now;
        $result = $wpdb->insert( Database::table( 'product_mappings' ), $payload );
        if ( false === $result ) {
            return $this->fail_db( 'The product route could not be inserted.' );
        }
        return true;
    }

    public function delete( int $id ): bool {
        global $wpdb;
        $this->last_error = '';
        if ( $id < 1 ) {
            return $this->fail( 'Product route ID is missing.' );
        }
        $result = $wpdb->delete( Database::table( 'product_mappings' ), array( 'id' => $id ), array( '%d' ) );
        if ( false === $result ) {
            return $this->fail_db( 'Product route delete failed.' );
        }
        return true;
    }

    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', Database::table( 'product_mappings' ), $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function find( int $integration_id, string $product, string $variant = '' ): ?array {
        global $wpdb;
        $product = sanitize_text_field( $product );
        $variant = sanitize_text_field( $variant );
        if ( $integration_id < 1 || '' === $product ) { return null; }
        if ( '' !== $variant ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE integration_id=%d AND external_product_id=%s AND status=%s AND (external_variant_id=%s OR external_variant_id IS NULL OR external_variant_id=%s) ORDER BY CASE WHEN external_variant_id=%s THEN 0 ELSE 1 END,id ASC LIMIT 1',
                    Database::table( 'product_mappings' ), $integration_id, $product, 'active', $variant, '', $variant
                ), ARRAY_A
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE integration_id=%d AND external_product_id=%s AND status=%s ORDER BY id ASC LIMIT 1',
                    Database::table( 'product_mappings' ), $integration_id, $product, 'active'
                ), ARRAY_A
            );
        }
        return is_array( $row ) ? $row : null;
    }

    public function find_any( int $integration_id, array $products, string $variant = '' ): ?array {
        $seen = array();
        foreach ( $products as $product ) {
            if ( ! is_scalar( $product ) ) {
                continue;
            }
            $raw = trim( (string) $product );
            if ( '' === $raw ) {
                continue;
            }
            $candidates = array( $raw, rawurldecode( $raw ), urldecode( $raw ), rawurlencode( $raw ), urlencode( $raw ) );
            foreach ( $candidates as $candidate ) {
                $candidate = sanitize_text_field( trim( html_entity_decode( (string) $candidate, ENT_QUOTES, 'UTF-8' ) ) );
                if ( '' === $candidate || isset( $seen[ $candidate ] ) ) {
                    continue;
                }
                $seen[ $candidate ] = true;
                $row = $this->find( $integration_id, $candidate, $variant );
                if ( $row ) {
                    $row['_matched_external_product_id'] = $candidate;
                    return $row;
                }
            }
        }
        return null;
    }

    public function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.*,i.name AS platform_name,p.name AS product_name,pl.name AS plan_name FROM %i m LEFT JOIN %i i ON i.id=m.integration_id LEFT JOIN %i p ON p.id=m.product_id LEFT JOIN %i pl ON pl.id=m.plan_id ORDER BY m.id DESC',
                Database::table( 'product_mappings' ), Database::table( 'integrations' ), Database::table( 'products' ), Database::table( 'plans' )
            ), ARRAY_A
        );
        if ( ! is_array( $rows ) ) { return array(); }
        foreach ( $rows as &$row ) {
            $meta = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $row['target_type'] = sanitize_key( (string) ( $row['target_type'] ?? $meta['target_type'] ?? 'evo' ) );
            $row['target_id'] = absint( $row['target_id'] ?? $meta['target_id'] ?? $row['product_id'] ?? 0 );
            if ( 'woocommerce' === $row['target_type'] && function_exists( 'wc_get_product' ) ) {
                $wc = wc_get_product( $row['target_id'] );
                $row['target_name'] = $wc ? $wc->get_name() : 'WooCommerce #' . $row['target_id'];
            } else {
                $row['target_name'] = $row['product_name'] ?: 'EVO #' . $row['target_id'];
            }
            $row['route_settings'] = $meta;
        }
        unset( $row );
        return $rows;
    }

    public function effective_fulfillment_mode( array $mapping, array $product = array() ): string {
        $meta = json_decode( (string) ( $mapping['metadata_json'] ?? '' ), true );
        $meta = is_array( $meta ) ? $meta : array();
        $type = sanitize_key( (string) ( $mapping['target_type'] ?? $meta['target_type'] ?? 'evo' ) );
        $target_id = absint( $mapping['target_id'] ?? $meta['target_id'] ?? $mapping['product_id'] ?? 0 );

        if ( 'woocommerce' === $type ) {
            // The resolved product passed by WebhookService represents the
            // canonical EVO Product policy behind the WooCommerce mapping.
            // Prefer it over historical Woo post meta so first-class mappings
            // can request EVO licensing without depending on the legacy bridge.
            $provider = sanitize_key( (string) ( $product['license_provider'] ?? '' ) );
            if ( ! empty( $product['requires_license'] ) && '' === $provider ) { $provider = 'evo'; }
            if ( 'evo' === $provider ) { return 'both'; }
            if ( ! empty( $product['requires_license'] ) && ! in_array( $provider, array( '', 'none' ), true ) ) { return 'woocommerce'; }
            return 'evo' === ProductLicensing::provider_for_product( $target_id ) ? 'both' : 'woocommerce';
        }

        return ! empty( $product['requires_license'] ) ? 'evo' : 'none';
    }

    private function verification_mode( mixed $value ): string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'portable', 'site', 'domain', 'server_ip', 'domain_ip' ), true ) ? $value : 'portable';
    }

    private function fail( string $message ): bool {
        $this->last_error = sanitize_text_field( $message );
        return false;
    }

    private function fail_db( string $message ): bool {
        global $wpdb;
        $db = sanitize_text_field( (string) $wpdb->last_error );
        return $this->fail( '' !== $db ? $message . ' Database: ' . $db : $message );
    }
}
