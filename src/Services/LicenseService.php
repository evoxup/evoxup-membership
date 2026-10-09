<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Crypto;
use EvoMembers\Core\Database;
use EvoMembers\Core\Installer;
use EvoMembers\Providers\LicenseProviderRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class LicenseService {
    public function get( int $license_id, bool $include_key = false ): ?array {
        global $wpdb;
        if ( $license_id < 1 ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', Database::table( 'licenses' ), $license_id ), ARRAY_A );
        if ( ! is_array( $row ) ) { return null; }
        if ( $include_key ) { $row['license_key'] = $this->get_plain_key( $license_id ); }
        return $row;
    }

    public function issue( array $args ): array|WP_Error {
        global $wpdb;
        $customer_id = absint( $args['customer_id'] ?? 0 );
        $product_id  = absint( $args['product_id'] ?? 0 );
        $product = $product_id > 0 ? ( new ProductService() )->get( $product_id ) : null;

        if ( $product_id > 0 && ! $product ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'product_not_found' ), __( 'Product not found.', 'evoxup-membership' ) );
        }

        if ( ! $product ) {
            $external_name = sanitize_text_field( (string) ( $args['external_product_name'] ?? '' ) );
            if ( '' === $external_name ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'product_required' ), __( 'A valid EVO product or external product context is required.', 'evoxup-membership' ) );
            }
            $product = array(
                'id'                    => 0,
                'name'                  => $external_name,
                'code'                  => sanitize_key( (string) ( $args['external_product_code'] ?? 'external-product' ) ),
                'max_activations'       => max( 1, absint( $args['max_activations'] ?? 1 ) ),
                'license_duration_days' => absint( $args['duration_days'] ?? 0 ),
                'license_type_id'       => absint( $args['license_type_id'] ?? 0 ),
                'verification_mode'     => sanitize_key( (string) ( $args['verification_mode'] ?? 'portable' ) ),
                'allowed_site_url'      => esc_url_raw( (string) ( $args['allowed_site_url'] ?? '' ) ),
                'allowed_domain'        => sanitize_text_field( (string) ( $args['allowed_domain'] ?? '' ) ),
                'allowed_ip'            => sanitize_text_field( (string) ( $args['allowed_ip'] ?? '' ) ),
            );
        }

        $plain   = $this->normalize_key( ( new LicenseGenerator() )->generate( is_array( $args['generator'] ?? null ) ? $args['generator'] : array() ) );
        $hash    = $this->hash_key( $plain );
        $now     = current_time( 'mysql', true );
        $days    = absint( $args['duration_days'] ?? $product['license_duration_days'] ?? 0 );
        $expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $days ) ) : null;
        // v1.0: each site/seat receives its own key. A single key has one activation.
        $max        = 1;
        $seat_total = max( 1, absint( $args['seat_total'] ?? 1 ) );
        $seat_no    = min( $seat_total, max( 1, absint( $args['seat_number'] ?? 1 ) ) );
        $group_uuid = sanitize_text_field( (string) ( $args['license_group_uuid'] ?? '' ) );

        $row = array(
                'customer_id'           => $customer_id > 0 ? $customer_id : null,
                'product_id'            => $product_id > 0 ? $product_id : null,
                'membership_id'         => ! empty( $args['membership_id'] ) ? absint( $args['membership_id'] ) : null,
                'license_group_uuid'     => '' !== $group_uuid ? $group_uuid : null,
                'seat_number'            => $seat_no,
                'seat_total'             => $seat_total,
                'license_hash'          => $hash,
                'license_key_encrypted' => Crypto::encrypt( $plain ),
                'license_last4'         => substr( $plain, -4 ),
                'status'                => 'active',
                'license_type_id'       => ! empty( $args['license_type_id'] ) ? absint( $args['license_type_id'] ) : ( ! empty( $product['license_type_id'] ) ? absint( $product['license_type_id'] ) : null ),
                'verification_mode'     => sanitize_key( (string) ( $args['verification_mode'] ?? $product['verification_mode'] ?? 'portable' ) ),
                'allowed_site_url'      => esc_url_raw( (string) ( $args['allowed_site_url'] ?? $product['allowed_site_url'] ?? '' ) ) ?: null,
                'allowed_domain'        => sanitize_text_field( (string) ( $args['allowed_domain'] ?? $product['allowed_domain'] ?? '' ) ) ?: null,
                'allowed_ip'            => sanitize_text_field( (string) ( $args['allowed_ip'] ?? $product['allowed_ip'] ?? '' ) ) ?: null,
                'verification_status'   => 'unverified',
                'verification_count'    => 0,
                'last_verified_at'      => null,
                'status_changed_at'     => $now,
                'max_activations'       => $max,
                'issued_at'             => $now,
                'expires_at'            => $expires,
                'created_from_order_id' => ! empty( $args['order_id'] ) ? absint( $args['order_id'] ) : null,
                'source'                => sanitize_key( (string) ( $args['source'] ?? 'evo' ) ) ?: 'evo',
                'source_order_id'       => ! empty( $args['source_order_id'] ) ? absint( $args['source_order_id'] ) : null,
                'source_order_item_id'  => ! empty( $args['source_order_item_id'] ) ? absint( $args['source_order_item_id'] ) : null,
                'generator_profile'     => ! empty( $args['generator_profile'] ) ? sanitize_key( (string) $args['generator_profile'] ) : null,
                'metadata_json'         => wp_json_encode(
                    array_merge(
                        is_array( $args['metadata'] ?? null ) ? $args['metadata'] : array(),
                        array(
                            'external_product_name' => sanitize_text_field( (string) ( $args['external_product_name'] ?? '' ) ),
                            'external_product_code' => sanitize_key( (string) ( $args['external_product_code'] ?? '' ) ),
                        )
                    )
                ),
                'created_at'            => $now,
                'updated_at'            => $now,
        );

        $ok = $wpdb->insert( Database::table( 'licenses' ), $row );
        if ( false === $ok ) {
            $first_error = sanitize_text_field( (string) $wpdb->last_error );
            Installer::repair();
            $ok = $wpdb->insert( Database::table( 'licenses' ), $row );
            if ( false === $ok ) {
                Logger::write(
                    'license_insert_failed',
                    array(
                        'product_id'     => $product_id,
                        'customer_id'    => $customer_id,
                        'database_error' => sanitize_text_field( (string) $wpdb->last_error ),
                        'first_error'    => $first_error,
                    ),
                    'error',
                    $customer_id ?: null
                );
                return new WP_Error(
                    \EvoMembers\Core\MembershipIdentifiers::error_code( 'license_insert_failed' ),
                    __( 'License could not be stored. EVO repaired the database schema and retried, but the insert still failed.', 'evoxup-membership' )
                );
            }
        }

        $id = (int) $wpdb->insert_id;
        Logger::write( 'license_issued', array( 'product_id' => $product_id, 'last4' => substr( $plain, -4 ), 'seat_number' => $seat_no, 'seat_total' => $seat_total ), 'info', $customer_id ?: null, $id );
        EventBus::emit( 'license.issued', array( 'license_id'=>$id, 'product_id'=>$product_id, 'membership_id'=>absint( $args['membership_id'] ?? 0 ), 'seat_number'=>$seat_no, 'seat_total'=>$seat_total, 'expires_at'=>$expires ), 'license', $id, $customer_id, sanitize_key( (string) ( $args['source'] ?? 'evo' ) ) ?: 'evo' );
        // Grouped seat issuance is mailed once after every independent key is
        // created. Single-seat issuance keeps the original per-license action.
        if ( 1 === $seat_total && empty( $args['defer_notification'] ) ) {
            \EvoMembers\Core\Hooks::do_action( 'license_issued', $id, $customer_id, $product_id, $plain );
            do_action( 'evoxup_license_issued', $id, $customer_id, $product_id, $plain );
        }

        return array(
            'id'              => $id,
            'license_key'     => $plain,
            'license_last4'   => substr( $plain, -4 ),
            'status'          => 'active',
            'max_activations' => $max,
            'seat_number'     => $seat_no,
            'seat_total'      => $seat_total,
            'license_group_uuid' => '' !== $group_uuid ? $group_uuid : null,
            'expires_at'      => $expires,
        );
    }

    /**
     * Issue one independent license key per requested seat/site.
     *
     * @return array<int,array>|WP_Error
     */
    public function issue_many( array $args, int $count ): array|WP_Error {
        $count = min( 100, max( 1, absint( $count ) ) );
        $group = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'evo-', true );
        $issued = array();
        for ( $seat = 1; $seat <= $count; $seat++ ) {
            $one = $args;
            $one['license_group_uuid'] = $group;
            $one['seat_number'] = $seat;
            $one['seat_total'] = $count;
            $one['max_activations'] = 1;
            // issue_many() owns delivery as one grouped message. Without this
            // flag a one-seat group would trigger both the single-license action
            // and the group action, causing duplicate customer emails.
            $one['defer_notification'] = true;
            $result = $this->issue( $one );
            if ( is_wp_error( $result ) ) {
                foreach ( $issued as $created ) {
                    if ( ! empty( $created['id'] ) ) { $this->delete( (int) $created['id'] ); }
                }
                return $result;
            }
            $issued[] = $result;
        }
        \EvoMembers\Core\Hooks::do_action( 'license_group_issued', $issued, $args );
        do_action( 'evoxup_license_group_issued', $issued, $args );
        return $issued;
    }

    public function register_external( array $args ): array|WP_Error {
        global $wpdb;

        $raw_plain = trim( (string) ( $args['license_key'] ?? '' ) );
        $plain     = $this->normalize_key( $raw_plain );
        if ( '' === $plain ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_key_required' ), __( 'External license key is required.', 'evoxup-membership' ) );
        }

        $provider = sanitize_key( (string) ( $args['provider'] ?? 'external' ) ) ?: 'external';
        $status   = sanitize_key( (string) ( $args['status'] ?? 'active' ) );
        if ( ! in_array( $status, array( 'active','inactive','disabled','frozen','expired','revoked','pending' ), true ) ) {
            $status = 'active';
        }

        $hash = $this->hash_key( strtoupper( $plain ) );
        $existing = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE license_hash=%s LIMIT 1', Database::table( 'licenses' ), $hash ),
            ARRAY_A
        );

        $metadata = is_array( $args['metadata'] ?? null ) ? $args['metadata'] : array();
        $external_id = sanitize_text_field( (string) ( $args['external_license_id'] ?? $args['license_id'] ?? '' ) );
        $external_reference = sanitize_text_field( (string) ( $args['external_reference'] ?? $args['reference'] ?? $metadata['provider_reference'] ?? '' ) );
        $now = current_time( 'mysql', true );

        if ( is_array( $existing ) ) {
            $old_meta = json_decode( (string) ( $existing['metadata_json'] ?? '' ), true );
            $old_meta = is_array( $old_meta ) ? $old_meta : array();
            $merged_meta = array_replace_recursive( $old_meta, $metadata );

            $update = array(
                'customer_id'                   => absint( $args['customer_id'] ?? 0 ) ?: ( absint( $existing['customer_id'] ?? 0 ) ?: null ),
                'product_id'                    => absint( $args['product_id'] ?? 0 ) ?: ( absint( $existing['product_id'] ?? 0 ) ?: null ),
                'membership_id'                 => absint( $args['membership_id'] ?? 0 ) ?: ( absint( $existing['membership_id'] ?? 0 ) ?: null ),
                'external_license_provider'     => $provider,
                'external_license_id'           => '' !== $external_id ? $external_id : ( $existing['external_license_id'] ?? null ),
                'external_reference'            => '' !== $external_reference ? $external_reference : ( $existing['external_reference'] ?? null ),
                'external_license_key_encrypted'=> Crypto::encrypt( $plain ),
                'external_license_status'       => $status,
                'status'                        => in_array( $status, array( 'active','inactive','disabled','frozen' ), true ) ? $status : ( 'active' === $status ? 'active' : 'inactive' ),
                'created_from_order_id'          => absint( $args['order_id'] ?? 0 ) ?: ( absint( $existing['created_from_order_id'] ?? 0 ) ?: null ),
                'source'                         => sanitize_key( (string) ( $args['source'] ?? $existing['source'] ?? 'external' ) ) ?: 'external',
                'source_order_id'                => absint( $args['source_order_id'] ?? 0 ) ?: ( absint( $existing['source_order_id'] ?? 0 ) ?: null ),
                'source_order_item_id'           => absint( $args['source_order_item_id'] ?? 0 ) ?: ( absint( $existing['source_order_item_id'] ?? 0 ) ?: null ),
                'metadata_json'                  => wp_json_encode( $merged_meta ),
                'updated_at'                     => $now,
            );
            $wpdb->update( Database::table( 'licenses' ), $update, array( 'id' => (int) $existing['id'] ) );

            return array(
                'id'               => (int) $existing['id'],
                'license_key'      => $plain,
                'license_last4'    => substr( $plain, -4 ),
                'status'           => $status,
                'external'         => true,
                'provider'         => $provider,
                'external_id'      => '' !== $external_id ? $external_id : (string) ( $existing['external_license_id'] ?? '' ),
                'reference'        => '' !== $external_reference ? $external_reference : (string) ( $existing['external_reference'] ?? '' ),
                'seat_number'      => (int) ( $existing['seat_number'] ?? 1 ),
                'seat_total'       => (int) ( $existing['seat_total'] ?? 1 ),
                'expires_at'       => $existing['expires_at'] ?? null,
                'is_new'           => false,
                'already_notified' => ! empty( $existing['notified_at'] ),
            );
        }

        $days        = absint( $args['duration_days'] ?? 0 );
        $expires     = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $days ) ) : null;
        $seat_total  = max( 1, absint( $args['seat_total'] ?? 1 ) );
        $seat_number = min( $seat_total, max( 1, absint( $args['seat_number'] ?? 1 ) ) );

        $row = array(
            'customer_id'                   => absint( $args['customer_id'] ?? 0 ) ?: null,
            'product_id'                    => absint( $args['product_id'] ?? 0 ) ?: null,
            'membership_id'                 => absint( $args['membership_id'] ?? 0 ) ?: null,
            'license_group_uuid'             => sanitize_text_field( (string) ( $args['license_group_uuid'] ?? '' ) ) ?: null,
            'seat_number'                    => $seat_number,
            'seat_total'                     => $seat_total,
            'license_hash'                   => $hash,
            'license_key_encrypted'          => null,
            'license_last4'                  => substr( $plain, -4 ),
            'external_license_provider'      => $provider,
            'external_license_id'            => '' !== $external_id ? $external_id : null,
            'external_reference'             => '' !== $external_reference ? $external_reference : null,
            'external_license_key_encrypted' => Crypto::encrypt( $plain ),
            'external_license_status'        => $status,
            'notified_at'                    => null,
            'status'                         => in_array( $status, array( 'active','inactive','disabled','frozen' ), true ) ? $status : ( 'active' === $status ? 'active' : 'inactive' ),
            'license_type_id'                => absint( $args['license_type_id'] ?? 0 ) ?: null,
            'verification_mode'              => sanitize_key( (string) ( $args['verification_mode'] ?? 'portable' ) ) ?: 'portable',
            'allowed_site_url'               => esc_url_raw( (string) ( $args['allowed_site_url'] ?? '' ) ) ?: null,
            'allowed_domain'                 => sanitize_text_field( (string) ( $args['allowed_domain'] ?? '' ) ) ?: null,
            'allowed_ip'                     => sanitize_text_field( (string) ( $args['allowed_ip'] ?? '' ) ) ?: null,
            'verification_status'            => 'unverified',
            'verification_count'             => 0,
            'last_verified_at'               => null,
            'status_changed_at'              => $now,
            'max_activations'                => 1,
            'issued_at'                      => $now,
            'expires_at'                     => $expires,
            'created_from_order_id'           => absint( $args['order_id'] ?? 0 ) ?: null,
            'source'                          => sanitize_key( (string) ( $args['source'] ?? 'external' ) ) ?: 'external',
            'source_order_id'                 => absint( $args['source_order_id'] ?? 0 ) ?: null,
            'source_order_item_id'            => absint( $args['source_order_item_id'] ?? 0 ) ?: null,
            'metadata_json'                   => wp_json_encode( $metadata ),
            'created_at'                      => $now,
            'updated_at'                      => $now,
        );

        if ( false === $wpdb->insert( Database::table( 'licenses' ), $row ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_store_failed' ), __( 'External license could not be stored.', 'evoxup-membership' ) );
        }

        $id = (int) $wpdb->insert_id;
        EventBus::emit(
            'license.external_registered',
            array( 'license_id'=>$id, 'provider'=>$provider, 'external_license_id'=>$external_id, 'reference'=>$external_reference, 'seat_number'=>$seat_number, 'seat_total'=>$seat_total ),
            'license',
            $id,
            absint( $args['customer_id'] ?? 0 ),
            $provider
        );
        \EvoMembers\Core\Hooks::do_action( 'external_license_registered', $id, $provider, $plain, $args );

        return array(
            'id'               => $id,
            'license_key'      => $plain,
            'license_last4'    => substr( $plain, -4 ),
            'status'           => $status,
            'external'         => true,
            'provider'         => $provider,
            'external_id'      => $external_id,
            'reference'        => $external_reference,
            'seat_number'      => $seat_number,
            'seat_total'       => $seat_total,
            'expires_at'       => $expires,
            'is_new'           => true,
            'already_notified' => false,
        );
    }

    public function mark_notified( array $license_ids ): void {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $license_ids ) ) ) );
        if ( ! $ids ) {
            return;
        }

        $now = current_time( 'mysql', true );
        foreach ( $ids as $id ) {
            $wpdb->update(
                Database::table( 'licenses' ),
                array( 'notified_at' => $now, 'updated_at' => $now ),
                array( 'id' => $id )
            );
        }
    }

    public function ids_for_source_item( string $source, int $order_id, int $item_id ): array {
        global $wpdb;

        $source   = sanitize_key( $source );
        $order_id = absint( $order_id );
        $item_id  = absint( $item_id );

        if ( '' === $source || $order_id < 1 || $item_id < 1 ) {
            return array();
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE source = %s AND source_order_id = %d AND source_order_item_id = %d ORDER BY id ASC',
                Database::table( 'licenses' ),
                $source,
                $order_id,
                $item_id
            )
        );

        return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
    }

    public function verify( string $plain, array $context = array() ): array|WP_Error {
        global $wpdb;
        $raw_plain = trim( $plain );
        $plain = $this->normalize_key( $raw_plain );
        if ( '' === $plain ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'license_required' ), __( 'License key is required.', 'evoxup-membership' ), array( 'status' => 400 ) );
        }

        $row = $this->find_license_row_by_plain_key( $plain );
        if ( ! is_array( $row ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'license_invalid' ), __( 'License key is invalid.', 'evoxup-membership' ), array( 'status' => 404 ) );
        }

        $now = current_time( 'mysql', true );
        $external_provider = sanitize_key( (string) ( $row['external_license_provider'] ?? '' ) );
        $external_verification_source = '';
        if ( '' !== $external_provider ) {
            $adapter = LicenseProviderRegistry::get( $external_provider );
            $external_result = null;
            if ( $adapter && $adapter->supports( 'verify' ) ) {
                $external_result = $adapter->verify( '' !== $raw_plain ? $raw_plain : $plain, $context );
                $external_verification_source = 'provider_adapter';
            } else {
                $external_result = \EvoMembers\Core\Hooks::apply_filters( 'external_license_verify', null, $external_provider, '' !== $raw_plain ? $raw_plain : $plain, $context, $row );
                if ( null !== $external_result ) { $external_verification_source = 'provider_filter'; }
            }
            if ( is_wp_error( $external_result ) ) {
                $this->record_verification( (int) $row['id'], 'external_failed', $now );
                return $external_result;
            }
            if ( is_array( $external_result ) && array_key_exists( 'valid', $external_result ) && ! $external_result['valid'] ) {
                $this->record_verification( (int) $row['id'], 'external_invalid', $now );
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'external_license_invalid' ), sanitize_text_field( (string) ( $external_result['reason'] ?? __( 'External provider rejected this license.', 'evoxup-membership' ) ) ), array( 'status'=>403 ) );
            }
            if ( '' === $external_verification_source ) { $external_verification_source = 'local_record'; }
        }
        $verification = 'valid';
        $error = null;
        if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
            $verification = 'expired';
            $error = new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'license_expired' ), __( 'License has expired.', 'evoxup-membership' ), array( 'status' => 403 ) );
        } elseif ( 'active' !== $row['status'] ) {
            $verification = sanitize_key( (string) $row['status'] );
            $messages = array(
                'inactive' => __( 'License is stopped.', 'evoxup-membership' ),
                'disabled' => __( 'License is disabled.', 'evoxup-membership' ),
                'frozen'   => __( 'License is frozen.', 'evoxup-membership' ),
            );
            $error = new WP_Error(
                \EvoMembers\Core\MembershipIdentifiers::error_code( 'license_' ) . $verification,
                $messages[ $verification ] ?? __( 'License is not active.', 'evoxup-membership' ),
                array( 'status' => 403 )
            );
        }

        if ( $error ) {
            $this->record_verification( (int) $row['id'], $verification, $now );
            return $error;
        }

        $product = ! empty( $row['product_id'] ) ? ( new ProductService() )->get( (int) $row['product_id'] ) : null;
        $license_meta = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
        $license_meta = is_array( $license_meta ) ? $license_meta : array();
        $requested_product_id = absint( $context['product_id'] ?? 0 );
        $requested_product_code = sanitize_key( (string) ( $context['product_code'] ?? '' ) );
        if ( $requested_product_id > 0 && (int) ( $row['product_id'] ?? 0 ) > 0 && $requested_product_id !== (int) $row['product_id'] ) {
            return $this->verification_error( (int) $row['id'], 'product_mismatch', 'evomembers_product_mismatch', __( 'License does not belong to the requested product.', 'evoxup-membership' ), 403, $now );
        }
        if ( '' !== $requested_product_code && is_array( $product ) && sanitize_key( (string) ( $product['code'] ?? '' ) ) !== $requested_product_code ) {
            return $this->verification_error( (int) $row['id'], 'product_mismatch', 'evomembers_product_mismatch', __( 'License does not belong to the requested product.', 'evoxup-membership' ), 403, $now );
        }

        $site_url = esc_url_raw( (string) ( $context['site_url'] ?? '' ) );
        $domain   = strtolower( trim( (string) ( $context['domain'] ?? '' ) ) );
        $site_host = '' !== $site_url ? strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) ) : '';
        if ( '' !== $site_host ) { $domain = $site_host; }
        $server_ip = sanitize_text_field( (string) ( $context['server_ip'] ?? '' ) );
        $mode = sanitize_key( (string) ( $row['verification_mode'] ?? 'portable' ) );
        $allowed_site = esc_url_raw( (string) ( $row['allowed_site_url'] ?? '' ) );
        $allowed_domain = strtolower( trim( (string) ( $row['allowed_domain'] ?? '' ) ) );

        if ( 'site' === $mode && '' !== $allowed_site ) {
            if ( '' === $site_url ) { return $this->verification_error( (int) $row['id'], 'site_required', 'evomembers_site_required', __( 'Site URL is required for this license.', 'evoxup-membership' ), 400, $now ); }
            if ( strtolower( untrailingslashit( $site_url ) ) !== strtolower( untrailingslashit( $allowed_site ) ) ) { return $this->verification_error( (int) $row['id'], 'site_mismatch', 'evomembers_site_mismatch', __( 'License is not valid for this site URL.', 'evoxup-membership' ), 403, $now ); }
        }
        if ( in_array( $mode, array( 'domain','domain_ip' ), true ) && '' !== $allowed_domain ) {
            if ( '' === $domain ) { return $this->verification_error( (int) $row['id'], 'domain_required', 'evomembers_domain_required', __( 'Domain is required for this license.', 'evoxup-membership' ), 400, $now ); }
            $domain_ok = ( $domain === $allowed_domain ) || str_ends_with( $domain, '.' . ltrim( $allowed_domain, '.' ) );
            if ( ! $domain_ok ) { return $this->verification_error( (int) $row['id'], 'domain_mismatch', 'evomembers_domain_mismatch', __( 'License is not valid for this domain.', 'evoxup-membership' ), 403, $now ); }
        }
        if ( in_array( $mode, array( 'server_ip','domain_ip' ), true ) && ! empty( $row['allowed_ip'] ) ) {
            if ( '' === $server_ip ) { return $this->verification_error( (int) $row['id'], 'ip_required', 'evomembers_ip_required', __( 'Server IP is required for this license.', 'evoxup-membership' ), 400, $now ); }
            if ( ! $this->ip_matches( $server_ip, (string) $row['allowed_ip'] ) ) { return $this->verification_error( (int) $row['id'], 'ip_mismatch', 'evomembers_ip_mismatch', __( 'License is not valid for this server IP.', 'evoxup-membership' ), 403, $now ); }
        }

        $membership = null;
        $plan = null;
        if ( ! empty( $row['membership_id'] ) ) {
            $membership = ( new MembershipService() )->get( (int) $row['membership_id'] );
            if ( is_array( $membership ) && ! empty( $membership['plan_id'] ) ) { $plan = ( new PlanService() )->get( (int) $membership['plan_id'] ); }
        }
        $active_activations = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id=%d AND status=%s', Database::table( 'license_activations' ), (int) $row['id'], 'active' )
        );
        $this->record_verification( (int) $row['id'], 'valid', $now );
        EventBus::emit( 'license.verified', array( 'license_id'=>(int)$row['id'], 'product_id'=>(int)($row['product_id']??0), 'site_url'=>$site_url, 'domain'=>$domain, 'server_ip'=>$server_ip ), 'license', (int)$row['id'], (int)($row['customer_id']??0), 'api' );
        return array(
            'valid'               => true,
            'reason'              => null,
            'id'                  => (int) $row['id'],
            'customer_id'         => (int) ( $row['customer_id'] ?? 0 ),
            'status'              => $row['status'],
            'verification_status' => 'valid',
            'license_last4'       => $row['license_last4'],
            'product_id'          => (int) $row['product_id'],
            'product_code'        => $product['code'] ?? null,
            'product_name'        => $product['name'] ?? ( $license_meta['external_product_name'] ?? null ),
            'membership_id'       => ! empty( $row['membership_id'] ) ? (int) $row['membership_id'] : null,
            'membership_status'   => is_array( $membership ) ? ( $membership['status'] ?? null ) : null,
            'plan_id'             => is_array( $plan ) ? (int) ( $plan['id'] ?? 0 ) : null,
            'plan_code'           => is_array( $plan ) ? ( $plan['code'] ?? null ) : null,
            'plan_name'           => is_array( $plan ) ? ( $plan['name'] ?? null ) : null,
            'tier'                => is_array( $plan ) ? ( $plan['tier'] ?? 'custom' ) : null,
            'entitlements'        => is_array( $plan ) ? ( json_decode( (string) ( $plan['entitlements_json'] ?? '[]' ), true ) ?: array() ) : array(),
            'seat_number'         => (int) ( $row['seat_number'] ?? 1 ),
            'seat_total'          => (int) ( $row['seat_total'] ?? 1 ),
            'license_group_uuid'  => $row['license_group_uuid'] ?? null,
            'max_activations'     => (int) $row['max_activations'],
            'active_activations'  => $active_activations,
            'expires_at'          => $row['expires_at'],
            'verification_mode'   => $row['verification_mode'] ?? 'portable',
            'license_provider'    => '' !== $external_provider ? $external_provider : 'evo',
            'verification_source' => '' !== $external_provider ? $external_verification_source : 'evo',
            'allowed_site_url'    => $row['allowed_site_url'] ?? null,
            'allowed_domain'      => $row['allowed_domain'] ?? null,
            'allowed_ip'          => $row['allowed_ip'] ?? null,
            'site_url'            => $site_url ?: null,
            'domain'              => $domain ?: null,
            'server_ip'           => $server_ip ?: null,
            'client_version'      => sanitize_text_field( (string) ( $context['client_version'] ?? '' ) ),
        );
    }

    public function activate( string $plain, string $site_url, string $client_version = '', string $server_ip = '' ): array|WP_Error {
        global $wpdb;
        $site_url = esc_url_raw( $site_url );
        if ( '' === $site_url || ! wp_http_validate_url( $site_url ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'site_invalid' ), __( 'A valid site URL is required.', 'evoxup-membership' ), array( 'status' => 400 ) );
        }
        $host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
        $server_ip = sanitize_text_field( trim( $server_ip ) );
        $license = $this->verify( $plain, array( 'site_url'=>$site_url, 'domain'=>$host, 'server_ip'=>$server_ip, 'client_version'=>$client_version ) );
        if ( is_wp_error( $license ) ) { return $license; }

        $mode = sanitize_key( (string) ( $license['verification_mode'] ?? 'portable' ) );
        if ( in_array( $mode, array( 'server_ip', 'domain_ip' ), true ) && '' === $server_ip ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'ip_required' ), __( 'Server IP is required for this license policy.', 'evoxup-membership' ), array( 'status'=>400 ) );
        }

        // Empty constraints mean "bind on first activation". Once stored, normal
        // verify/activate requests must match the bound site/domain/IP policy.
        $bind = array();
        if ( 'site' === $mode && empty( $license['allowed_site_url'] ) ) {
            $bind['allowed_site_url'] = untrailingslashit( $site_url );
            $license['allowed_site_url'] = $bind['allowed_site_url'];
        }
        if ( in_array( $mode, array( 'domain', 'domain_ip' ), true ) && empty( $license['allowed_domain'] ) ) {
            $bind['allowed_domain'] = $host;
            $license['allowed_domain'] = $host;
        }
        if ( in_array( $mode, array( 'server_ip', 'domain_ip' ), true ) && empty( $license['allowed_ip'] ) ) {
            $bind['allowed_ip'] = $server_ip;
            $license['allowed_ip'] = $server_ip;
        }
        if ( $bind ) {
            $bind['updated_at'] = current_time( 'mysql', true );
            $wpdb->update( Database::table( 'licenses' ), $bind, array( 'id'=>(int) $license['id'] ) );
        }

        $site_hash = hash( 'sha256', strtolower( untrailingslashit( $site_url ) ) );
        $table     = Database::table( 'license_activations' );
        $existing  = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND site_hash = %s LIMIT 1', $table, $license['id'], $site_hash ),
            ARRAY_A
        );
        $now = current_time( 'mysql', true );

        if ( is_array( $existing ) ) {
            $wpdb->update(
                $table,
                array( 'status' => 'active', 'client_version' => sanitize_text_field( $client_version ), 'domain'=>$host ?: null, 'server_ip'=>sanitize_text_field( $server_ip ) ?: null, 'last_seen_at' => $now, 'deactivated_at' => null ),
                array( 'id' => (int) $existing['id'] )
            );
            return array( 'activation_id' => (int) $existing['id'], 'license' => $license, 'site_url' => $site_url );
        }

        $active_count = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d AND status = %s', $table, $license['id'], 'active' )
        );
        if ( $active_count >= $license['max_activations'] ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'activation_limit' ), __( 'Activation limit reached.', 'evoxup-membership' ), array( 'status' => 403 ) );
        }

        if ( false === $wpdb->insert(
            $table,
            array(
                'license_id'     => $license['id'],
                'site_url'       => $site_url,
                'site_hash'      => $site_hash,
                'status'         => 'active',
                'client_version' => sanitize_text_field( $client_version ),
                'domain'         => $host ?: null,
                'server_ip'      => sanitize_text_field( $server_ip ) ?: null,
                'activated_at'   => $now,
                'last_seen_at'   => $now,
            )
        ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'activation_insert_failed' ), __( 'Activation could not be stored.', 'evoxup-membership' ), array( 'status' => 500 ) );
        }
        $activation_id = (int) $wpdb->insert_id;
        Logger::write( 'license_activated', array( 'site_url' => $site_url ), 'info', null, $license['id'] );
        EventBus::emit( 'license.activated', array( 'license_id'=>(int)$license['id'], 'activation_id'=>$activation_id, 'site_url'=>$site_url, 'domain'=>$host, 'server_ip'=>$server_ip ), 'license', (int)$license['id'], 0, 'api' );
        \EvoMembers\Core\Hooks::do_action( 'license_activated', $license['id'], $activation_id, $site_url );
        do_action( 'evoxup_license_activated', $license['id'], $activation_id, $site_url );
        return array( 'activation_id' => $activation_id, 'license' => $license, 'site_url' => $site_url );
    }

    public function deactivate( string $plain, string $site_url, string $server_ip = '' ): array|WP_Error {
        global $wpdb;
        $site_url = esc_url_raw( $site_url );
        if ( '' === $site_url || ! wp_http_validate_url( $site_url ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'site_invalid' ), __( 'A valid site URL is required.', 'evoxup-membership' ), array( 'status' => 400 ) );
        }
        $host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
        $server_ip = sanitize_text_field( trim( $server_ip ) );
        $license = $this->verify( $plain, array( 'site_url'=>$site_url, 'domain'=>$host, 'server_ip'=>$server_ip ) );
        if ( is_wp_error( $license ) ) { return $license; }

        $site_hash = hash( 'sha256', strtolower( untrailingslashit( $site_url ) ) );
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND site_hash = %s LIMIT 1', Database::table( 'license_activations' ), $license['id'], $site_hash ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'activation_not_found' ), __( 'Activation was not found.', 'evoxup-membership' ), array( 'status' => 404 ) );
        }
        $now = current_time( 'mysql', true );
        $wpdb->update(
            Database::table( 'license_activations' ),
            array( 'status' => 'inactive', 'deactivated_at' => $now, 'last_seen_at' => $now ),
            array( 'id' => (int) $row['id'] )
        );
        EventBus::emit( 'license.deactivated', array( 'license_id'=>(int)$license['id'], 'activation_id'=>(int)$row['id'], 'site_url'=>$site_url ), 'license', (int)$license['id'], 0, 'api' );
        \EvoMembers\Core\Hooks::do_action( 'license_deactivated', $license['id'], (int) $row['id'], $site_url );
        do_action( 'evoxup_license_deactivated', $license['id'], (int) $row['id'], $site_url );
        return array( 'activation_id' => (int) $row['id'], 'license_id' => (int) $license['id'], 'site_url' => $site_url, 'status' => 'inactive' );
    }

    public function for_created_order( int $order_id, bool $include_keys = false ): array {
        global $wpdb;
        if ( $order_id < 1 ) { return array(); }
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE created_from_order_id=%d ORDER BY id ASC', Database::table( 'licenses' ), $order_id ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) { return array(); }
        if ( $include_keys ) {
            foreach ( $rows as &$row ) { $row['license_key'] = $this->get_plain_key( (int) $row['id'] ); }
            unset( $row );
        }
        return $rows;
    }

    public function find_by_order( int $order_id ): ?array {
        global $wpdb;
        if ( $order_id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE created_from_order_id = %d ORDER BY id ASC LIMIT 1', Database::table( 'licenses' ), $order_id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function get_plain_key( int $license_id ): string {
        global $wpdb;
        if ( $license_id < 1 ) {
            return '';
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT license_key_encrypted, external_license_key_encrypted FROM %i WHERE id = %d LIMIT 1', Database::table( 'licenses' ), $license_id ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) { return ''; }
        $stored = (string) ( $row['license_key_encrypted'] ?? '' );
        if ( '' === $stored ) { $stored = (string) ( $row['external_license_key_encrypted'] ?? '' ); }
        return Crypto::decrypt( $stored );
    }

    public function for_customer( int $customer_id, bool $include_keys = false, int $limit = 200 ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT l.*, p.name AS product_name, p.code AS product_code, p.download_url, p.purchase_url, p.upgrade_url, (SELECT COUNT(*) FROM %i a WHERE a.license_id=l.id AND a.status=%s) AS active_activations FROM %i l LEFT JOIN %i p ON p.id=l.product_id WHERE l.customer_id=%d ORDER BY l.id DESC LIMIT %d',
                Database::table( 'license_activations' ), 'active', Database::table( 'licenses' ), Database::table( 'products' ), $customer_id, $limit
            ), ARRAY_A
        );
        if ( ! is_array( $rows ) ) { return array(); }
        if ( $include_keys ) {
            foreach ( $rows as &$row ) { $row['license_key'] = $this->get_plain_key( (int) $row['id'] ); }
            unset( $row );
        }
        return $rows;
    }

    public function all( int $limit = 100 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, $limit ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT l.*, p.name AS product_name, c.email AS customer_email, (SELECT COUNT(*) FROM %i a WHERE a.license_id=l.id AND a.status=%s) AS active_activations FROM %i l LEFT JOIN %i p ON p.id = l.product_id LEFT JOIN %i c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT %d',
                Database::table( 'license_activations' ),
                'active',
                Database::table( 'licenses' ),
                Database::table( 'products' ),
                Database::table( 'customers' ),
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function count_for_customer( int $customer_id, bool $active_only = false ): int {
        global $wpdb;
        if ( $customer_id < 1 ) { return 0; }
        if ( $active_only ) {
            return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d AND status=%s AND (expires_at IS NULL OR expires_at>=%s)', Database::table( 'licenses' ), $customer_id, 'active', current_time( 'mysql', true ) ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'licenses' ), $customer_id ) );
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::table( 'licenses' ) ) );
    }

    public function attach_external_license( int $license_id, string $provider, string $key, string $status = 'active' ): bool {
        global $wpdb;
        if ( $license_id < 1 || '' === trim( $key ) ) {
            return false;
        }
        return false !== $wpdb->update(
            Database::table( 'licenses' ),
            array(
                'external_license_provider'      => sanitize_key( $provider ),
                'external_license_key_encrypted' => Crypto::encrypt( trim( $key ) ),
                'external_license_status'        => sanitize_key( $status ),
                'updated_at'                     => current_time( 'mysql', true ),
            ),
            array( 'id' => $license_id )
        );
    }

    public function set_status( int $license_id, string $status ): bool {
        global $wpdb;
        $license_id = absint( $license_id );
        $status = sanitize_key( $status );
        if ( $license_id < 1 || ! in_array( $status, array( 'active', 'inactive', 'disabled', 'frozen' ), true ) ) {
            return false;
        }
        $ok = $wpdb->update(
            Database::table( 'licenses' ),
            array( 'status' => $status, 'status_changed_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $license_id )
        );
        if ( false !== $ok ) {
            Logger::write( 'license_status_changed', array( 'status' => $status ), 'info', null, $license_id );
            EventBus::emit( 'license.status_changed', array( 'license_id'=>$license_id, 'status'=>$status ), 'license', $license_id );
            \EvoMembers\Core\Hooks::do_action( 'license_status_changed', $license_id, $status );
            do_action( 'evoxup_license_status_changed', $license_id, $status );
        }
        return false !== $ok;
    }

    public function activations_for_customer( int $customer_id, int $limit = 100 ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT a.*, l.license_last4, l.seat_number, l.seat_total, p.name AS product_name, p.code AS product_code FROM %i a INNER JOIN %i l ON l.id=a.license_id LEFT JOIN %i p ON p.id=l.product_id WHERE l.customer_id=%d ORDER BY a.id DESC LIMIT %d',
                Database::table( 'license_activations' ), Database::table( 'licenses' ), Database::table( 'products' ), $customer_id, $limit
            ), ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function activations( int $limit = 200 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT a.*, l.license_last4, l.status AS license_status, p.name AS product_name, c.email AS customer_email FROM %i a INNER JOIN %i l ON l.id=a.license_id LEFT JOIN %i p ON p.id=l.product_id LEFT JOIN %i c ON c.id=l.customer_id ORDER BY a.id DESC LIMIT %d',
                Database::table( 'license_activations' ), Database::table( 'licenses' ), Database::table( 'products' ), Database::table( 'customers' ), $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function deactivate_activation_id( int $activation_id ): bool {
        global $wpdb;
        $activation_id = absint( $activation_id );
        if ( $activation_id < 1 ) { return false; }
        $now = current_time( 'mysql', true );
        return false !== $wpdb->update(
            Database::table( 'license_activations' ),
            array( 'status' => 'inactive', 'deactivated_at' => $now, 'last_seen_at' => $now ),
            array( 'id' => $activation_id )
        );
    }

    public function for_source_order( string $source, int $order_id ): array {
        global $wpdb;
        $source = sanitize_key( $source );
        $order_id = absint( $order_id );
        if ( '' === $source || $order_id < 1 ) { return array(); }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT l.*, p.name AS product_name FROM %i l LEFT JOIN %i p ON p.id=l.product_id WHERE l.source=%s AND l.source_order_id=%d ORDER BY l.source_order_item_id ASC, l.id ASC',
                Database::table( 'licenses' ), Database::table( 'products' ), $source, $order_id
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function delete( int $license_id ): bool {
        global $wpdb;
        if ( $license_id < 1 ) { return false; }
        $wpdb->delete( Database::table( 'license_activations' ), array( 'license_id' => $license_id ), array( '%d' ) );
        $ok = $wpdb->delete( Database::table( 'licenses' ), array( 'id' => $license_id ), array( '%d' ) );
        if ( false !== $ok ) { Logger::write( 'license_deleted', array( 'license_id' => $license_id ), 'warning' ); }
        return false !== $ok;
    }

    /** Apply one status to a selected set without trusting duplicate/raw IDs. */
    public function set_status_many( array $license_ids, string $status ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $license_ids ) ) ) );
        $result = array( 'requested' => count( $ids ), 'updated' => 0, 'failed' => 0 );
        foreach ( $ids as $license_id ) {
            if ( $this->set_status( $license_id, $status ) ) { ++$result['updated']; }
            else { ++$result['failed']; }
        }
        return $result;
    }

    /** Delete selected licenses and their activation rows. */
    public function delete_many( array $license_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $license_ids ) ) ) );
        $result = array( 'requested' => count( $ids ), 'deleted' => 0, 'failed' => 0 );
        foreach ( $ids as $license_id ) {
            if ( $this->delete( $license_id ) ) { ++$result['deleted']; }
            else { ++$result['failed']; }
        }
        return $result;
    }

    /** Delete every EVO license and activation. This never touches WP/Woo tables. */
    public function delete_all(): int|false {
        global $wpdb;
        $count = $this->count();
        $activations = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'license_activations' ) ) );
        if ( false === $activations ) { return false; }
        $licenses = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'licenses' ) ) );
        if ( false === $licenses ) { return false; }
        Logger::write( 'licenses_deleted_all', array( 'deleted' => $count ), 'warning' );
        return $count;
    }

    public function send_customer_group_email( array $issued, array $args = array() ): bool {
        if ( ! $issued ) { return false; }
        $source = sanitize_key( (string) ( $args['source'] ?? 'evo' ) );
        if ( 'woocommerce' === $source && ! \EvoMembers\Core\Hooks::apply_filters( 'send_standalone_woocommerce_license_email', true, $issued, $args ) ) {
            return false;
        }
        $customer_id = absint( $args['customer_id'] ?? 0 );
        if ( $customer_id < 1 ) { return false; }
        $customer = ( new CustomerService() )->get( $customer_id );
        if ( ! $customer || ! is_email( (string) ( $customer['email'] ?? '' ) ) ) { return false; }

        $product = ! empty( $args['product_id'] ) ? ( new ProductService() )->get( absint( $args['product_id'] ) ) : null;
        $product_name = is_array( $product ) ? (string) ( $product['name'] ?? '' ) : '';
        if ( '' === $product_name ) { $product_name = sanitize_text_field( (string) ( $args['external_product_name'] ?? 'EVO' ) ); }
        $center_url = ( new PurchaseLinkService() )->membership_center_url();
        $user = ! empty( $customer['wp_user_id'] ) ? get_userdata( (int) $customer['wp_user_id'] ) : false;

        /* translators: %s: product name. */
        $subject = sprintf( __( 'Your %s license keys', 'evoxup-membership' ), $product_name ?: 'EVO' );
        $message = __( 'Your independent license keys are ready:', 'evoxup-membership' ) . "\n\n";
        foreach ( $issued as $index => $license ) {
            $key = sanitize_text_field( (string) ( $license['license_key'] ?? '' ) );
            if ( '' === $key ) { continue; }
            /* translators: 1: key number, 2: total keys, 3: license key. */
            $message .= sprintf( __( 'Key %1$d of %2$d: %3$s', 'evoxup-membership' ), $index + 1, count( $issued ), $key ) . "\n";
        }
        $has_external = (bool) array_filter( $issued, static fn( array $license ): bool => ! empty( $license['external'] ) );
        $message .= "\n" . ( $has_external
            ? __( 'Activation limits and validity follow the external license provider policy.', 'evoxup-membership' )
            : __( 'Each key activates one site independently.', 'evoxup-membership' ) ) . "\n";
        if ( $user ) {
            /* translators: %s: WordPress username. */
            $message .= sprintf( __( 'Account username: %s', 'evoxup-membership' ), (string) $user->user_login ) . "\n";
            $message .= __( 'Login:', 'evoxup-membership' ) . ' ' . wp_login_url( $center_url ) . "\n";
            $message .= __( 'Set/reset password:', 'evoxup-membership' ) . ' ' . wp_lostpassword_url( $center_url ) . "\n";
        }
        $message .= __( 'My Membership / Products:', 'evoxup-membership' ) . ' ' . $center_url . "\n";
        $message .= __( 'You can view licenses, activations, downloads, orders and membership status from that page.', 'evoxup-membership' );

        $created = ( new MessageService() )->create( $customer_id, $subject, $message, 'both' );
        return ! is_wp_error( $created ) && 'sent' === (string) ( $created['status'] ?? '' );
    }

    /**
     * Deliver one grouped access email for existing license IDs.
     *
     * This is intentionally idempotent: rows already marked notified are skipped.
     * It is used by webhook replay/recovery so a successful purchase can recover
     * from a temporary mail transport failure without issuing a second key.
     */
    public function send_customer_group_email_by_ids( array $license_ids, array $args = array() ): bool {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $license_ids ) ) ) );
        if ( ! $ids ) { return false; }

        $issued = array();
        foreach ( $ids as $id ) {
            $row = $this->get( $id, true );
            if ( ! is_array( $row ) || ! empty( $row['notified_at'] ) ) { continue; }
            $key = trim( (string) ( $row['license_key'] ?? '' ) );
            if ( '' === $key ) { continue; }
            $issued[] = array(
                'id'                 => $id,
                'license_key'        => $key,
                'license_last4'      => (string) ( $row['license_last4'] ?? substr( $key, -4 ) ),
                'seat_number'        => absint( $row['seat_number'] ?? 1 ) ?: 1,
                'seat_total'         => absint( $row['seat_total'] ?? 1 ) ?: 1,
                'expires_at'         => $row['expires_at'] ?? null,
                'external'           => ! empty( $row['external_license_provider'] ),
                'provider'           => sanitize_key( (string) ( $row['external_license_provider'] ?? '' ) ),
            );
            if ( empty( $args['customer_id'] ) ) { $args['customer_id'] = absint( $row['customer_id'] ?? 0 ); }
            if ( empty( $args['product_id'] ) ) { $args['product_id'] = absint( $row['product_id'] ?? 0 ); }
            if ( empty( $args['source'] ) ) { $args['source'] = sanitize_key( (string) ( $row['source'] ?? 'evo' ) ) ?: 'evo'; }
        }
        if ( ! $issued ) { return true; }

        $sent = $this->send_customer_group_email( $issued, $args );
        if ( $sent ) { $this->mark_notified( array_column( $issued, 'id' ) ); }
        return $sent;
    }

    public function send_customer_email( int $license_id ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT l.*, c.email, c.first_name, c.wp_user_id, p.name AS product_name FROM %i l LEFT JOIN %i c ON c.id=l.customer_id LEFT JOIN %i p ON p.id=l.product_id WHERE l.id=%d LIMIT 1', Database::table( 'licenses' ), Database::table( 'customers' ), Database::table( 'products' ), $license_id ), ARRAY_A );
        if ( ! is_array( $row ) || ! is_email( $row['email'] ?? '' ) ) { return false; }
        if ( empty( $row['product_name'] ) ) {
            $meta = json_decode( (string) ( $row['metadata_json'] ?? '' ), true );
            if ( is_array( $meta ) && ! empty( $meta['external_product_name'] ) ) {
                $row['product_name'] = sanitize_text_field( (string) $meta['external_product_name'] );
            }
        }
        if ( 'woocommerce' === sanitize_key( (string) ( $row['source'] ?? '' ) ) && ! \EvoMembers\Core\Hooks::apply_filters( 'send_standalone_woocommerce_license_email', true, $row ) ) { return false; }
        $key = $this->get_plain_key( $license_id );
        if ( '' === $key ) { return false; }
        /* translators: %s: product name. */
        $subject = sprintf( __( 'Your %s license', 'evoxup-membership' ), $row['product_name'] ?: __( 'EVO', 'evoxup-membership' ) );
        $name = trim( (string) ( $row['first_name'] ?? '' ) );
        if ( $name ) {
            /* translators: %s: customer first name. */
            $message = sprintf( __( 'Hello %s,', 'evoxup-membership' ), $name );
        } else {
            $message = __( 'Hello,', 'evoxup-membership' );
        }
        $message .= "\n\n";
        /* translators: %s: product name. */
        $message .= sprintf( __( 'Your license for %s is:', 'evoxup-membership' ), $row['product_name'] ?: 'EVO' ) . "\n\n" . $key . "\n\n";
        if ( (int) ( $row['seat_total'] ?? 1 ) > 1 ) {
            /* translators: 1: seat number, 2: total independent license keys. */
            $message .= sprintf( __( 'License key/site: %1$d of %2$d', 'evoxup-membership' ), (int) ( $row['seat_number'] ?? 1 ), (int) ( $row['seat_total'] ?? 1 ) ) . "\n";
        } else {
            $message .= __( 'This key activates one site.', 'evoxup-membership' ) . "\n";
        }
        if ( ! empty( $row['expires_at'] ) ) {
            /* translators: %s: expiration date. */
            $message .= sprintf( __( 'Expires: %s', 'evoxup-membership' ), $row['expires_at'] );
        } else {
            $message .= __( 'Expires: Lifetime', 'evoxup-membership' );
        }
        $center_url = ( new PurchaseLinkService() )->membership_center_url();
        $message .= "\n\n" . __( 'My Membership / Products:', 'evoxup-membership' ) . ' ' . $center_url . "\n";
        if ( ! empty( $row['wp_user_id'] ) ) {
            $user = get_userdata( (int) $row['wp_user_id'] );
            if ( $user ) {
                /* translators: %s: WordPress username. */
                $message .= sprintf( __( 'Account username: %s', 'evoxup-membership' ), (string) $user->user_login ) . "\n";
                $message .= __( 'Login:', 'evoxup-membership' ) . ' ' . wp_login_url( $center_url ) . "\n";
                $message .= __( 'Set/reset password:', 'evoxup-membership' ) . ' ' . wp_lostpassword_url( $center_url );
            }
        }
        $subject = (string) \EvoMembers\Core\Hooks::apply_filters( 'license_email_subject', $subject, $row, $key );
        $message = (string) \EvoMembers\Core\Hooks::apply_filters( 'license_email_message', $message, $row, $key );
        if ( empty( $row['customer_id'] ) ) {
            return false;
        }
        $created = ( new MessageService() )->create( (int) $row['customer_id'], $subject, $message, 'email' );
        if ( is_wp_error( $created ) ) {
            return false;
        }
        return 'failed' !== (string) ( $created['status'] ?? '' );
    }


    private function find_license_row_by_plain_key( string $plain ): ?array {
        global $wpdb;
        $normalized = $this->normalize_key( $plain );
        $hash       = $this->hash_key( $normalized );
        $row        = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE license_hash=%s LIMIT 1', Database::table( 'licenses' ), $hash ),
            ARRAY_A
        );
        if ( is_array( $row ) ) {
            return $row;
        }

        // Recovery path for test/staging migrations where WordPress salts changed,
        // or for keys stored by an older build with a different hash. Narrow by
        // last four characters, decrypt the candidates, compare, then self-heal.
        $last4 = substr( $normalized, -4 );
        if ( '' === $last4 ) { return null; }
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE license_last4=%s ORDER BY id DESC LIMIT %d',
                Database::table( 'licenses' ),
                $last4,
                50
            ),
            ARRAY_A
        );
        foreach ( (array) $candidates as $candidate ) {
            $encrypted = (string) ( $candidate['license_key_encrypted'] ?? '' );
            if ( '' === $encrypted ) { $encrypted = (string) ( $candidate['external_license_key_encrypted'] ?? '' ); }
            if ( '' === $encrypted ) { continue; }
            $decrypted = $this->normalize_key( Crypto::decrypt( $encrypted ) );
            if ( '' === $decrypted || ! hash_equals( $normalized, $decrypted ) ) { continue; }
            $wpdb->update(
                Database::table( 'licenses' ),
                array( 'license_hash'=>$hash, 'updated_at'=>current_time( 'mysql', true ) ),
                array( 'id'=>absint( $candidate['id'] ?? 0 ) )
            );
            $candidate['license_hash'] = $hash;
            return $candidate;
        }
        return null;
    }

    private function record_verification( int $license_id, string $status, ?string $now = null ): void {
        global $wpdb;
        $now = $now ?: current_time( 'mysql', true );
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET verification_status=%s, verification_count=verification_count+1, last_verified_at=%s, updated_at=%s WHERE id=%d',
                Database::table( 'licenses' ), sanitize_key( $status ), $now, $now, $license_id
            )
        );
    }

    private function verification_error( int $license_id, string $verification_status, string $code, string $message, int $http_status, string $now ): WP_Error {
        $this->record_verification( $license_id, $verification_status, $now );
        return new WP_Error( $code, $message, array( 'status'=>$http_status ) );
    }

    private function ip_matches( string $ip, string $rules ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }
        $items = preg_split( '/[\s,;]+/', $rules ) ?: array();
        foreach ( $items as $rule ) {
            $rule = trim( $rule );
            if ( '' === $rule ) { continue; }
            if ( $ip === $rule ) { return true; }
            if ( str_contains( $rule, '/' ) ) {
                [ $subnet, $bits ] = array_pad( explode( '/', $rule, 2 ), 2, '' );
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                    $bits = max( 0, min( 32, (int) $bits ) );
                    $mask = $bits === 0 ? 0 : ( -1 << ( 32 - $bits ) );
                    if ( ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask ) ) { return true; }
                }
            }
        }
        return false;
    }

    private function normalize_key( string $plain ): string {
        $plain = trim( $plain );
        if ( '' === $plain ) { return ''; }
        // Keys are commonly copied from wrapped table cells or email clients.
        // Normalize Unicode dashes/zero-width characters and remove whitespace
        // without changing the visible separator structure of EVO keys.
        $plain = str_replace( array( "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}" ), '-', $plain );
        $plain = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\s]+/u', '', $plain );
        return strtoupper( is_string( $plain ) ? $plain : '' );
    }

    private function hash_key( string $plain ): string {
        return hash_hmac( 'sha256', $this->normalize_key( $plain ), wp_salt( 'secure_auth' ) . '|evo-license' );
    }
}
