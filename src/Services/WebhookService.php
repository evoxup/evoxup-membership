<?php
namespace EvoMembers\Services;

use EvoMembers\Core\RequestGuard;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use EvoMembers\Core\Installer;
use EvoMembers\Integrations\WooCommerce\ProductLicensing;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class WebhookService {
    private ?string $replay_source_ip = null;

    public function receive( string $slug, WP_REST_Request $request ): array {
        $body_guard = RequestGuard::validate_webhook_body( $request );
        if ( is_wp_error( $body_guard ) ) {
            $data = $body_guard->get_error_data();
            return array( 'success' => false, 'status' => is_array( $data ) ? absint( $data['status'] ?? 413 ) : 413, 'code' => $body_guard->get_error_code(), 'message' => $body_guard->get_error_message() );
        }
        global $wpdb;
        $slug     = sanitize_key( $slug );
        $raw_body = (string) $request->get_body();
        $payload  = $this->parse_payload( $request, $raw_body );
        $headers  = $request->get_headers();
        $now      = current_time( 'mysql', true );

        $integration = ( new IntegrationService() )->by_slug( $slug );
        if ( ! $integration || 'active' !== (string) $integration['status'] ) {
            return array( 'success' => false, 'status' => 404, 'code' => 'integration_not_found' );
        }
        $verification = $this->verify( $integration, $request, $raw_body );
        if ( 'verified' !== $verification ) {
            $status = 'unsupported' === $verification ? 501 : 401;
            $code   = 'unsupported' === $verification ? 'verification_unsupported' : 'verification_failed';
            return array( 'success' => false, 'status' => $status, 'code' => $code );
        }

        update_option(
            'evomembers_webhook_last_received',
            array(
                'provider'     => $slug,
                'received_at'  => $now,
                'method'       => sanitize_key( $request->get_method() ),
                'content_type' => sanitize_text_field( (string) $request->get_header( 'content-type' ) ),
            ),
            false
        );

        $event_id     = $this->first( $payload, array( 'event_id', 'id', 'sale_id', 'order_id', 'transaction_id', 'txn_id' ) );
        $event_type   = $this->first( $payload, array( 'event_type', 'event', 'type', 'resource_type' ) );
        $event_row    = array(
            'integration_id'      => $integration ? (int) $integration['id'] : null,
            'provider_slug'       => $slug,
            'event_type'          => $this->text( $event_type, 100 ),
            'external_event_id'   => $this->text( $event_id, 190 ),
            'provider_created_at' => $this->date( $this->first( $payload, array( 'created_at', 'created', 'sale_timestamp', 'purchase_date' ) ) ),
            'provider_updated_at' => $this->date( $this->first( $payload, array( 'updated_at', 'updated' ) ) ),
            'received_at'         => $now,
            'request_method'      => sanitize_key( $request->get_method() ),
            'content_type'        => sanitize_text_field( (string) $request->get_header( 'content-type' ) ),
            'source_ip'           => $this->source_ip(),
            'user_agent'          => isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : null,
            'verification_status' => 'verified',
            'processing_status'   => 'received',
            'headers_json'        => wp_json_encode( $this->sanitize_headers_for_storage( $headers ) ),
            'payload_json'        => wp_json_encode( $this->sanitize_payload_for_storage( $payload ) ),
            'created_at'          => $now,
            'updated_at'          => $now,
        );

        $webhook_id = $this->store_event( $event_row );
        if ( $webhook_id < 1 ) {
            $fallback_id = $this->store_fallback( $event_row, (string) get_option( 'evomembers_webhook_last_storage_error', 'database_insert_failed' ) );
            return array(
                'success'           => true,
                'status'            => 202,
                'event_id'          => $fallback_id,
                'processing_status' => 'captured_fallback',
                'error_code'        => 'database_storage_unavailable',
                'message'           => 'Webhook was captured in EVO fallback storage. Repair the EVO database from Webhook Inbox.',
            );
        }

        try {
            $normalized = $this->normalize( $payload, $slug );
            $wpdb->update(
                Database::table( 'webhook_events' ),
                array( 'verification_status' => $verification, 'processing_status' => 'processing', 'attempts' => 1, 'last_attempt_at' => $now, 'normalized_json' => wp_json_encode( $normalized ), 'updated_at' => $now ),
                array( 'id' => $webhook_id )
            );

            $mapping_service = new MappingService();
            $product_ids = $this->product_identifiers( $payload, $normalized );
            $mapping = $mapping_service->find_any( (int) $integration['id'], $product_ids, (string) $normalized['product']['external_variant_id'] );
            $product = null;
            $route_meta = array();
            $license_settings = array();
            $target_type = 'evo';
            $target_id = 0;

            if ( $mapping ) {
                $route_meta = json_decode( (string) ( $mapping['metadata_json'] ?? '' ), true );
                $route_meta = is_array( $route_meta ) ? $route_meta : array();
                $target_type = sanitize_key( (string) ( $mapping['target_type'] ?? $route_meta['target_type'] ?? 'evo' ) );
                $target_id = absint( $mapping['target_id'] ?? $route_meta['target_id'] ?? $mapping['product_id'] ?? 0 );

                if ( 'woocommerce' === $target_type ) {
                    $wc = function_exists( 'wc_get_product' ) ? wc_get_product( $target_id ) : null;
                    if ( $wc ) {
                        // The first-class WooCommerce extension owns current
                        // mappings. Ask its public mapping contract first; only
                        // fall back to the historical Core/Woo meta bridge when
                        // no first-class mapping is available. This keeps
                        // external-provider webhooks (Gumroad/JVZoo/etc.) and
                        // native WooCommerce purchases on the same EVO Product
                        // and license policy.
                        try {
                            $wc_mapping = apply_filters( 'evoxup_woocommerce_mapping', null, $target_id, 0 );
                            if ( is_array( $wc_mapping ) && ! empty( $wc_mapping['resolved'] ) && ! empty( $wc_mapping['evo_product_id'] ) ) {
                                $canonical = ( new ProductService() )->get( absint( $wc_mapping['evo_product_id'] ) );
                                if ( is_array( $canonical ) ) {
                                    $provider = ! empty( $canonical['requires_license'] )
                                        ? sanitize_key( (string) ( $canonical['license_provider'] ?? 'evo' ) )
                                        : 'none';
                                    if ( ! empty( $canonical['requires_license'] ) && in_array( $provider, array( '', 'none' ), true ) ) { $provider = 'evo'; }
                                    $seats = max( 1, absint( $canonical['license_seats'] ?? $canonical['max_activations'] ?? 1 ) );
                                    $wc_plan_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $wc_mapping['plan_ids'] ?? array() ) ) ) ) );
                                    if ( ! $wc_plan_ids ) {
                                        foreach ( ( new PlanService() )->plans_for_product( (int) $canonical['id'] ) as $linked_plan ) {
                                            if ( 'active' !== (string) ( $linked_plan['status'] ?? '' ) ) { continue; }
                                            $linked_plan_id = absint( $linked_plan['id'] ?? 0 );
                                            if ( $linked_plan_id > 0 ) { $wc_plan_ids[] = $linked_plan_id; }
                                        }
                                        $wc_plan_ids = array_values( array_unique( $wc_plan_ids ) );
                                    }
                                    $license_settings = array(
                                        'provider'          => $provider,
                                        'evo_product_id'    => absint( $canonical['id'] ),
                                        'plan_id'           => absint( $wc_plan_ids[0] ?? 0 ),
                                        'plan_ids'          => $wc_plan_ids,
                                        'requires_license'  => 'none' !== $provider,
                                        'license_type_id'   => absint( $canonical['license_type_id'] ?? 0 ),
                                        'verification_mode' => sanitize_key( (string) ( $canonical['verification_mode'] ?? 'portable' ) ) ?: 'portable',
                                        'license_seats'     => $seats,
                                        'max_activations'   => $seats,
                                        'duration_days'     => absint( $canonical['license_duration_days'] ?? 0 ),
                                        'allowed_site_url'  => esc_url_raw( (string) ( $canonical['allowed_site_url'] ?? '' ) ),
                                        'allowed_domain'    => sanitize_text_field( (string) ( $canonical['allowed_domain'] ?? '' ) ),
                                        'allowed_ip'        => sanitize_text_field( (string) ( $canonical['allowed_ip'] ?? '' ) ),
                                        'settings_source'   => 'evoxup_woocommerce_extension',
                                    );
                                }
                            }
                        } catch ( \Throwable $routing_error ) {
                            // Deep Woo mapping is optional to webhook intake. If an
                            // extension-level resolver fails, keep the purchase alive
                            // and fall back to the historical Woo licensing bridge.
                            Logger::write(
                                'woocommerce_mapping_contract_failed',
                                array( 'webhook_event_id' => $webhook_id, 'target_id' => $target_id, 'message' => sanitize_text_field( $routing_error->getMessage() ) ),
                                'warning'
                            );
                            $wc_mapping = null;
                        }
                        if ( ! $license_settings ) {
                            $license_settings = ProductLicensing::settings_for_product( $target_id );
                        }

                        $evo_product_id = absint( $license_settings['evo_product_id'] ?? 0 );
                        if ( $evo_product_id > 0 ) {
                            $product = array(
                                'id'                    => $evo_product_id,
                                'evo_product_id'        => $evo_product_id,
                                'name'                  => sanitize_text_field( $wc->get_name() ),
                                'code'                  => 'wc-' . $target_id,
                                'source'                => 'woocommerce',
                                'external_source_id'    => (string) $target_id,
                                'fulfillment_mode'      => 'evo' === ( $license_settings['provider'] ?? 'none' ) ? 'both' : 'woocommerce',
                                'requires_license'      => ! empty( $license_settings['requires_license'] ),
                                'license_provider'      => sanitize_key( (string) ( $license_settings['provider'] ?? 'none' ) ),
                                'license_seats'         => max( 1, absint( $license_settings['license_seats'] ?? $license_settings['max_activations'] ?? 1 ) ),
                                'max_activations'       => max( 1, absint( $license_settings['max_activations'] ?? 1 ) ),
                                'license_duration_days' => absint( $license_settings['duration_days'] ?? 0 ),
                                'license_type_id'       => absint( $license_settings['license_type_id'] ?? 0 ),
                                'verification_mode'     => sanitize_key( (string) ( $license_settings['verification_mode'] ?? 'portable' ) ),
                                'allowed_site_url'      => esc_url_raw( (string) ( $license_settings['allowed_site_url'] ?? '' ) ),
                                'allowed_domain'        => sanitize_text_field( (string) ( $license_settings['allowed_domain'] ?? '' ) ),
                                'allowed_ip'            => sanitize_text_field( (string) ( $license_settings['allowed_ip'] ?? '' ) ),
                            );
                        }
                    }
                } else {
                    $product = $target_id > 0 ? ( new ProductService() )->get( $target_id ) : null;
                    if ( $product ) {
                        $license_settings = array(
                            'provider'          => ! empty( $product['requires_license'] ) ? sanitize_key( (string) ( $product['license_provider'] ?? 'evo' ) ) : 'none',
                            'requires_license'  => ! empty( $product['requires_license'] ),
                            'license_type_id'   => absint( $product['license_type_id'] ?? 0 ),
                            'verification_mode' => sanitize_key( (string) ( $product['verification_mode'] ?? 'portable' ) ),
                            'license_seats'     => max( 1, absint( $product['license_seats'] ?? 1 ) ),
                            'max_activations'   => 1,
                            'duration_days'     => absint( $product['license_duration_days'] ?? 0 ),
                            'allowed_site_url'  => esc_url_raw( (string) ( $product['allowed_site_url'] ?? '' ) ),
                            'allowed_domain'    => sanitize_text_field( (string) ( $product['allowed_domain'] ?? '' ) ),
                            'allowed_ip'        => sanitize_text_field( (string) ( $product['allowed_ip'] ?? '' ) ),
                        );
                    }
                }
            }

            $effective_mode = ( $mapping && $product ) ? $mapping_service->effective_fulfillment_mode( $mapping, $product ) : '';

            // Resolve all plans granted by this sale. An explicit mapping/WooCommerce
            // plan is an override. Otherwise an EVO Product grants every active plan
            // linked through the many-to-many plan_products table, with the primary
            // relationship first. This keeps external webhook sales consistent with
            // the Product <-> Plan model used by the admin UI.
            $mapping_plan_id = $mapping ? absint( $mapping['plan_id'] ?? 0 ) : 0;
            $route_plan_ids = array();
            if ( $mapping_plan_id > 0 ) {
                // An explicit external-provider mapping is a deliberate single
                // plan override.
                $route_plan_ids[] = $mapping_plan_id;
            } else {
                // WooCommerce mappings can intentionally grant multiple plans.
                $route_plan_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $license_settings['plan_ids'] ?? array() ) ) ) ) );
                $legacy_plan_id = absint( $license_settings['plan_id'] ?? 0 );
                if ( ! $route_plan_ids && $legacy_plan_id > 0 ) { $route_plan_ids[] = $legacy_plan_id; }
            }
            if ( ! $route_plan_ids && $product ) {
                $evo_product_id = absint( $license_settings['evo_product_id'] ?? $product['id'] ?? 0 );
                if ( $evo_product_id > 0 ) {
                    foreach ( ( new PlanService() )->plans_for_product( $evo_product_id ) as $linked_plan ) {
                        if ( 'active' !== (string) ( $linked_plan['status'] ?? '' ) ) { continue; }
                        $linked_plan_id = absint( $linked_plan['id'] ?? 0 );
                        if ( $linked_plan_id > 0 ) { $route_plan_ids[] = $linked_plan_id; }
                    }
                }
            }
            $route_plan_ids = array_values( array_unique( array_filter( array_map( 'absint', $route_plan_ids ) ) ) );
            $primary_plan_id = absint( $route_plan_ids[0] ?? 0 );

            $normalized['_evo_routing'] = array(
                'mapping_id'                 => $mapping ? (int) $mapping['id'] : 0,
                'mapping_mode'               => $mapping ? (string) ( $mapping['fulfillment_mode'] ?? 'inherit' ) : '',
                'matched_external_product_id'=> $mapping ? (string) ( $mapping['_matched_external_product_id'] ?? $normalized['product']['external_product_id'] ) : '',
                'candidate_product_ids'       => $product_ids,
                'target_type'                => $target_type,
                'target_id'                  => $target_id,
                'plan_id'                    => $primary_plan_id,
                'plan_ids'                   => $route_plan_ids,
                'product_id'                 => $product ? absint( $license_settings['evo_product_id'] ?? $product['id'] ?? 0 ) : 0,
                'product_mode'               => $product ? (string) ( $product['fulfillment_mode'] ?? '' ) : '',
                'license_settings'           => $license_settings,
                'effective_mode'             => $effective_mode,
                'fulfillment_reused'         => false,
            );
            // Verified remote events may create/link the EVO customer record, but they never create WordPress users.
            $customer_id = ( new CustomerService() )->find_or_create( $normalized['customer'], false );
            $order_id = $this->create_order( (int) $integration['id'], $customer_id, $normalized, $payload );
            ( new OrderService() )->sync_external_item( $order_id, $normalized );
            $fulfillment = null;
            $error_code = null;
            $error_msg = null;

            $route_plan_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $normalized['_evo_routing']['plan_ids'] ?? array() ) ) ) ) );
            if ( ! $route_plan_ids ) {
                $single_route_plan_id = absint( $normalized['_evo_routing']['plan_id'] ?? 0 );
                if ( $single_route_plan_id > 0 ) { $route_plan_ids[] = $single_route_plan_id; }
            }
            if ( $mapping && $customer_id > 0 && $route_plan_ids ) {
                $granted_memberships = array();
                foreach ( $route_plan_ids as $route_plan_id ) {
                    $membership = ( new MembershipService() )->grant(
                        $customer_id,
                        $route_plan_id,
                        array(
                            'source' => $slug,
                            'source_order_id' => $order_id,
                            'external_reference' => (string) ( $normalized['order']['external_order_id'] ?? '' ) . ':plan:' . $route_plan_id,
                            'metadata' => array( 'webhook_event_id' => $webhook_id, 'mapping_id' => (int) $mapping['id'], 'product_id' => absint( $normalized['_evo_routing']['product_id'] ?? 0 ) ),
                        )
                    );
                    if ( is_wp_error( $membership ) ) { continue; }
                    $granted_memberships[] = array(
                        'membership_id' => (int) ( $membership['id'] ?? 0 ),
                        'plan_id'       => $route_plan_id,
                    );
                }
                if ( $granted_memberships ) {
                    $normalized['_evo_memberships'] = $granted_memberships;
                    $normalized['_evo_membership'] = $granted_memberships[0];
                    $normalized['_evo_routing']['membership_id'] = (int) $granted_memberships[0]['membership_id'];
                }
            }

            // License capture is independent from the fulfillment route. If a
            // provider sends a real key, EVO records it even when mapping,
            // membership creation or another optional stage is incomplete.
            $incoming_license_capture = $this->capture_incoming_license(
                $normalized,
                $integration,
                $customer_id,
                $order_id,
                $webhook_id,
                $mapping
            );
            if ( ! empty( $incoming_license_capture['attempted'] ) ) {
                $normalized['_evo_license_capture'] = $incoming_license_capture;
                if ( empty( $incoming_license_capture['stored'] ) && null === $error_code ) {
                    $error_code = 'external_license_capture_failed';
                    $error_msg  = sanitize_text_field( (string) ( $incoming_license_capture['message'] ?? 'Incoming license key could not be stored.' ) );
                }
            }


            if ( ! $mapping ) {
                $error_code = 'product_mapping_missing';
                $error_msg  = 'Webhook saved and normalized, but no matching EVO product mapping exists.';
            } elseif ( $customer_id < 1 ) {
                $error_code = 'customer_email_missing';
                $error_msg  = 'The product mapping exists, but the webhook does not contain a usable customer email.';
            } elseif ( ! $product ) {
                $error_code = 'mapped_product_missing';
                $error_msg  = 'The product route points to a target product that no longer exists or is unavailable.';
            } else {
                $stored_fulfillment = $this->stored_fulfillment( $order_id );
                if ( is_array( $stored_fulfillment ) ) {
                    $fulfillment = $stored_fulfillment;
                    $normalized['_evo_routing']['fulfillment_reused'] = true;
                    $normalized['_evo_routing']['effective_mode'] = (string) ( $stored_fulfillment['mode'] ?? $effective_mode );
                } else {
                    $fulfillment = ( new FulfillmentRouter() )->run( $order_id, $customer_id, $product, $normalized, $integration, $webhook_id, $effective_mode );
                    if ( ! is_wp_error( $fulfillment ) ) { $this->store_fulfillment( $order_id, $effective_mode, $fulfillment ); }
                }
                if ( is_wp_error( $fulfillment ) ) {
                    $error_code = $fulfillment->get_error_code();
                    $error_msg = $fulfillment->get_error_message();
                    $this->store_fulfillment_error( $order_id, $effective_mode, $error_code, $error_msg );
                } else {
                    $normalized['_evo_fulfillment'] = $fulfillment;
                    $normalized['_evo_delivery'] = $this->recover_customer_delivery( $customer_id, $product, $normalized, $fulfillment, $integration );
                }
            }

            $status = null === $error_code ? 'processed' : 'partially_processed';
            $wpdb->update(
                Database::table( 'webhook_events' ),
                array(
                    'processing_status'    => $status,
                    'processed_at'         => $now,
                    'updated_at'           => $now,
                    'error_code'           => $error_code,
                    'error_message'        => $error_msg,
                    'normalized_json'      => wp_json_encode( $normalized ),
                    'processing_trace_json'=> wp_json_encode(
                        array(
                            'mapping'      => $mapping ? 'resolved' : 'missing',
                            'customer'     => $customer_id > 0 ? 'resolved' : 'missing',
                            'order'        => $order_id > 0 ? 'stored' : 'missing',
                            'memberships'  => ! empty( $normalized['_evo_memberships'] ) ? 'granted' : 'none',
                            'license'      => $normalized['_evo_license_capture']['stored'] ?? null,
                            'fulfillment'  => is_array( $fulfillment ) ? 'processed' : ( is_wp_error( $fulfillment ) ? 'failed' : 'not_run' ),
                        )
                    ),
                ),
                array( 'id' => $webhook_id )
            );
            \EvoMembers\Core\Hooks::do_action( 'webhook_processed', $webhook_id, (int) $integration['id'], $normalized, $status );

            return array(
                'success' => true,
                'status' => 200,
                'event_id' => $webhook_id,
                'processing_status' => $status,
                'customer_id' => $customer_id ?: null,
                'order_id' => $order_id ?: null,
                'fulfillment_mode' => is_array( $fulfillment ) ? (string) ( $fulfillment['mode'] ?? $effective_mode ) : $effective_mode,
                'license_issued' => is_array( $fulfillment ) && ! empty( $fulfillment['evo_license_id'] ),
                'license_id' => is_array( $fulfillment ) ? (int) ( $fulfillment['evo_license_id'] ?? 0 ) : null,
                'woocommerce_order_id' => is_array( $fulfillment ) ? (int) ( $fulfillment['woocommerce_order_id'] ?? 0 ) : null,
                'error_code' => $error_code,
            );
        } catch ( \Throwable $e ) {
            $failed_verification = in_array( $verification, array( 'verified', 'failed', 'unsupported' ), true ) ? $verification : 'unknown';
            $this->fail( $webhook_id, 'runtime_exception', $e->getMessage(), $failed_verification );
            Logger::write( 'webhook_runtime_exception', array( 'event_id' => $webhook_id, 'provider' => $slug, 'verification' => $failed_verification, 'message' => $e->getMessage(), 'file' => wp_normalize_path( $e->getFile() ), 'line' => (int) $e->getLine() ), 'error' );
            return array( 'success' => false, 'status' => 500, 'event_id' => $webhook_id, 'code' => 'runtime_exception', 'message' => sanitize_text_field( $e->getMessage() ) );
        }
    }


    /**
     * Retry customer delivery without ever failing financial fulfillment.
     *
     * License issuance and access are the transaction. Email is a recoverable
     * delivery side effect. Replay therefore resends only unnotified existing
     * keys and never generates a replacement license.
     */
    private function recover_customer_delivery( int $customer_id, array $product, array $normalized, array $fulfillment, array $integration ): array {
        $result = array( 'attempted' => false, 'sent' => false, 'license_ids' => array(), 'error' => null );
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $fulfillment['evo_license_ids'] ?? array() ) ) ) ) );
        $single = absint( $fulfillment['evo_license_id'] ?? 0 );
        if ( $single > 0 && ! in_array( $single, $ids, true ) ) { $ids[] = $single; }
        if ( ! $ids || $customer_id < 1 ) { return $result; }

        $result['attempted'] = true;
        $result['license_ids'] = $ids;
        try {
            $result['sent'] = ( new LicenseService() )->send_customer_group_email_by_ids(
                $ids,
                array(
                    'customer_id'           => $customer_id,
                    'product_id'            => absint( $normalized['_evo_routing']['product_id'] ?? $product['id'] ?? 0 ),
                    'source'                => sanitize_key( (string) ( $integration['slug'] ?? 'webhook' ) ) ?: 'webhook',
                    'external_product_name' => sanitize_text_field( (string) ( $product['name'] ?? $normalized['product']['name'] ?? 'EVO' ) ),
                )
            );
        } catch ( \Throwable $delivery_error ) {
            $result['error'] = sanitize_text_field( $delivery_error->getMessage() );
            Logger::write( 'webhook_customer_delivery_failed', array( 'customer_id' => $customer_id, 'license_ids' => $ids, 'message' => $result['error'] ), 'warning', $customer_id );
        }
        return $result;
    }

    private function capture_incoming_license(
        array $normalized,
        array $integration,
        int $customer_id,
        int $order_id,
        int $webhook_id,
        ?array $mapping
    ): array {
        $incoming = is_array( $normalized['license'] ?? null ) ? $normalized['license'] : array();
        $key      = trim( (string) ( $incoming['key'] ?? '' ) );
        if ( '' === $key ) {
            return array( 'attempted' => false, 'stored' => false );
        }

        try {
            $routing  = is_array( $normalized['_evo_routing'] ?? null ) ? $normalized['_evo_routing'] : array();
            $settings = is_array( $routing['license_settings'] ?? null ) ? $routing['license_settings'] : array();
            $provider = sanitize_key( (string) ( $settings['provider'] ?? '' ) );
            if ( in_array( $provider, array( '', 'none', 'evo', 'external' ), true ) ) {
                $provider = sanitize_key( (string) ( $integration['slug'] ?? 'external' ) ) ?: 'external';
            }

            $membership_id = absint( $normalized['_evo_membership']['membership_id'] ?? $routing['membership_id'] ?? 0 );
            $product_id    = absint( $routing['product_id'] ?? 0 );
            $external_id   = sanitize_text_field( (string) ( $incoming['reference'] ?? '' ) );

            $licenses = new LicenseService();
            $registered = $licenses->register_external(
                array(
                    'customer_id'        => $customer_id,
                    'product_id'         => $product_id,
                    'membership_id'      => $membership_id,
                    'provider'           => $provider,
                    'license_key'        => $key,
                    'status'             => sanitize_key( (string) ( $incoming['status'] ?? 'active' ) ) ?: 'active',
                    'external_license_id'=> $external_id,
                    'external_reference' => sanitize_text_field( (string) ( $normalized['order']['external_transaction_id'] ?? $normalized['order']['external_order_id'] ?? $external_id ) ),
                    'order_id'           => $order_id,
                    'source'             => sanitize_key( (string) ( $integration['slug'] ?? 'external' ) ) ?: 'external',
                    'duration_days'      => absint( $settings['duration_days'] ?? 0 ),
                    'verification_mode'  => sanitize_key( (string) ( $settings['verification_mode'] ?? 'portable' ) ) ?: 'portable',
                    'allowed_site_url'   => esc_url_raw( (string) ( $settings['allowed_site_url'] ?? '' ) ),
                    'allowed_domain'     => sanitize_text_field( (string) ( $settings['allowed_domain'] ?? '' ) ),
                    'allowed_ip'         => sanitize_text_field( (string) ( $settings['allowed_ip'] ?? '' ) ),
                    'metadata'           => array(
                        'webhook_event_id'       => $webhook_id,
                        'integration_id'         => absint( $integration['id'] ?? 0 ),
                        'mapping_id'             => absint( $mapping['id'] ?? 0 ),
                        'external_product_id'    => sanitize_text_field( (string) ( $normalized['product']['external_product_id'] ?? '' ) ),
                        'external_product_name'  => sanitize_text_field( (string) ( $normalized['product']['name'] ?? '' ) ),
                        'capture_stage'          => 'webhook_normalized',
                        'mapping_resolved'       => ! empty( $mapping ),
                    ),
                )
            );

            if ( is_wp_error( $registered ) ) {
                Logger::write(
                    'external_license_capture_failed',
                    array( 'webhook_event_id'=>$webhook_id, 'provider'=>$provider, 'message'=>$registered->get_error_message() ),
                    'error',
                    $customer_id ?: null
                );
                return array(
                    'attempted' => true,
                    'stored'    => false,
                    'message'   => $registered->get_error_message(),
                );
            }

            $notified = false;
            if ( empty( $registered['already_notified'] ) && $customer_id > 0 ) {
                $notified = $licenses->send_customer_group_email(
                    array( $registered ),
                    array(
                        'customer_id'           => $customer_id,
                        'product_id'            => $product_id,
                        'external_product_name' => sanitize_text_field( (string) ( $normalized['product']['name'] ?? 'EVO' ) ),
                        'source'                => sanitize_key( (string) ( $integration['slug'] ?? 'external' ) ) ?: 'external',
                    )
                );
                if ( $notified ) {
                    $licenses->mark_notified( array( (int) $registered['id'] ) );
                }
            }

            return array(
                'attempted'  => true,
                'stored'     => true,
                'license_id' => (int) ( $registered['id'] ?? 0 ),
                'provider'   => $provider,
                'is_new'     => ! empty( $registered['is_new'] ),
                'notified'   => $notified || ! empty( $registered['already_notified'] ),
            );
        } catch ( \Throwable $e ) {
            Logger::write(
                'external_license_capture_exception',
                array( 'webhook_event_id'=>$webhook_id, 'message'=>$e->getMessage() ),
                'error',
                $customer_id ?: null
            );
            return array( 'attempted'=>true, 'stored'=>false, 'message'=>$e->getMessage() );
        }
    }

    public function storage_health(): array {
        global $wpdb;
        $table = Database::table( 'webhook_events' );
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $fallback = get_option( 'evomembers_webhook_fallback_events', array() );
        return array(
            'table' => $table,
            'exists' => (string) $found === $table,
            'count' => (string) $found === $table ? $this->database_count() : 0,
            'fallback_count' => is_array( $fallback ) ? count( $fallback ) : 0,
            'last_received' => get_option( 'evomembers_webhook_last_received', array() ),
            'last_storage_error' => (string) get_option( 'evomembers_webhook_last_storage_error', '' ),
        );
    }

    public function write_test_event(): int|string {
        $now = current_time( 'mysql', true );
        $row = array(
            'integration_id' => null,
            'provider_slug' => 'evo-test',
            'event_type' => 'storage_test',
            'external_event_id' => 'test-' . gmdate( 'YmdHis' ),
            'received_at' => $now,
            'request_method' => 'POST',
            'content_type' => 'application/json',
            'verification_status' => 'not_required',
            'processing_status' => 'processed',
            'attempts' => 1,
            'processed_at' => $now,
            'headers_json' => '{}',
            'payload_json' => '{"evo":"storage-test"}',
            'normalized_json' => '{"test":true}',
            'created_at' => $now,
            'updated_at' => $now,
        );
        $id = $this->store_event( $row );
        return $id > 0 ? $id : $this->store_fallback( $row, (string) get_option( 'evomembers_webhook_last_storage_error', 'database_insert_failed' ) );
    }

    public function cleanup_older_than( int $days ): int|false {
        global $wpdb;
        $days = min( 3650, max( 1, absint( $days ) ) );
        $before = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', Database::table( 'webhook_events' ), $before ) );
        $fallback = get_option( 'evomembers_webhook_fallback_events', array() );
        if ( is_array( $fallback ) ) {
            $fallback = array_values( array_filter( $fallback, static fn( $row ) => strtotime( (string) ( $row['received_at'] ?? '' ) ) >= strtotime( $before . ' UTC' ) ) );
            update_option( 'evomembers_webhook_fallback_events', $fallback, false );
        }
        return $deleted;
    }

    public function delete_all(): int|false {
        global $wpdb;
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Database::table( 'webhook_events' ) ) );
        delete_option( 'evomembers_webhook_fallback_events' );
        delete_option( 'evomembers_webhook_last_storage_error' );
        return $deleted;
    }

    public function all( int $limit = 100 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, $limit ) );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Database::table( 'webhook_events' ), $limit ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : array();
        $fallback = get_option( 'evomembers_webhook_fallback_events', array() );
        if ( is_array( $fallback ) ) {
            foreach ( $fallback as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $row['id'] = (string) ( $row['id'] ?? 'fallback' );
                $row['processing_status'] = (string) ( $row['processing_status'] ?? 'captured_fallback' );
                $row['verification_status'] = (string) ( $row['verification_status'] ?? 'pending' );
                $rows[] = $row;
            }
        }
        usort( $rows, static fn( $a, $b ) => strcmp( (string) ( $b['received_at'] ?? '' ), (string) ( $a['received_at'] ?? '' ) ) );
        return array_slice( $rows, 0, $limit );
    }

    public function retry_all_fallback( int $limit = 50 ): array {
        $events = get_option( 'evomembers_webhook_fallback_events', array() );
        $events = is_array( $events ) ? $events : array();
        $limit = min( 200, max( 1, absint( $limit ) ) );
        $ids = array();
        foreach ( $events as $row ) {
            if ( is_array( $row ) && str_starts_with( (string) ( $row['id'] ?? '' ), 'F-' ) ) {
                $ids[] = (string) $row['id'];
            }
            if ( count( $ids ) >= $limit ) {
                break;
            }
        }

        $result = array( 'attempted' => 0, 'migrated' => 0, 'remaining' => count( $events ), 'failed' => 0 );
        foreach ( $ids as $id ) {
            $result['attempted']++;
            $retry = $this->retry( $id );
            $new_id = (string) ( $retry['replayed_event_id'] ?? '' );
            if ( '' !== $new_id && ! str_starts_with( $new_id, 'F-' ) ) {
                $result['migrated']++;
            } else {
                $result['failed']++;
            }
        }
        $remaining = get_option( 'evomembers_webhook_fallback_events', array() );
        $result['remaining'] = is_array( $remaining ) ? count( $remaining ) : 0;
        return $result;
    }

    public function retry( string $event_id ): array {
        global $wpdb;
        $event_id = trim( sanitize_text_field( $event_id ) );
        if ( '' === $event_id ) {
            return array( 'success' => false, 'status' => 400, 'code' => 'event_id_required' );
        }

        $is_fallback = str_starts_with( $event_id, 'F-' );
        $row = $is_fallback ? $this->fallback_event( $event_id ) : $this->database_event( absint( $event_id ) );
        if ( ! $row ) {
            return array( 'success' => false, 'status' => 404, 'code' => 'webhook_event_not_found' );
        }

        $provider = sanitize_key( (string) ( $row['provider_slug'] ?? '' ) );
        if ( '' === $provider || 'evo-test' === $provider ) {
            return array( 'success' => false, 'status' => 400, 'code' => 'webhook_event_not_replayable' );
        }

        $request = $this->request_from_event( $row, $provider );
        $stored_ip = sanitize_text_field( (string) ( $row['source_ip'] ?? '' ) );
        $this->replay_source_ip = filter_var( $stored_ip, FILTER_VALIDATE_IP ) ? $stored_ip : null;

        if ( ! $is_fallback ) {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET attempts = attempts + 1, last_attempt_at = %s, updated_at = %s WHERE id = %d',
                    Database::table( 'webhook_events' ),
                    current_time( 'mysql', true ),
                    current_time( 'mysql', true ),
                    absint( $event_id )
                )
            );
        }

        try {
            $result = $this->receive( $provider, $request );
        } finally {
            $this->replay_source_ip = null;
        }

        $new_id = isset( $result['event_id'] ) ? (string) $result['event_id'] : '';
        if ( $is_fallback ) {
            if ( '' !== $new_id && ! str_starts_with( $new_id, 'F-' ) ) {
                $this->remove_fallback( $event_id );
            } elseif ( '' !== $new_id && $new_id !== $event_id && str_starts_with( $new_id, 'F-' ) ) {
                // The database is still unavailable. Keep the original audit item
                // and discard the duplicate fallback created by this retry.
                $this->remove_fallback( $new_id );
            }
        }

        $result['original_event_id'] = $event_id;
        $result['replayed_event_id'] = $new_id;
        return $result;
    }

    private function database_event( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', Database::table( 'webhook_events' ), $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    private function fallback_event( string $id ): ?array {
        $events = get_option( 'evomembers_webhook_fallback_events', array() );
        if ( ! is_array( $events ) ) {
            return null;
        }
        foreach ( $events as $row ) {
            if ( is_array( $row ) && $id === (string) ( $row['id'] ?? '' ) ) {
                return $row;
            }
        }
        return null;
    }

    private function remove_fallback( string $id ): void {
        $events = get_option( 'evomembers_webhook_fallback_events', array() );
        if ( ! is_array( $events ) ) {
            return;
        }
        $events = array_values(
            array_filter(
                $events,
                static fn( $row ) => ! is_array( $row ) || $id !== (string) ( $row['id'] ?? '' )
            )
        );
        if ( empty( $events ) ) {
            delete_option( 'evomembers_webhook_fallback_events' );
        } else {
            update_option( 'evomembers_webhook_fallback_events', $events, false );
        }
    }

    private function request_from_event( array $row, string $provider ): WP_REST_Request {
        $method = strtoupper( sanitize_key( (string) ( $row['request_method'] ?? 'POST' ) ) );
        if ( 'POST' !== $method ) {
            $method = 'POST';
        }
        $request = new WP_REST_Request( $method, '/evo-replay-webhook/' . $provider );
        $body = (string) ( $row['payload_json'] ?? '' );
        $request->set_body( $body );

        $headers = json_decode( (string) ( $row['headers_json'] ?? '' ), true );
        if ( is_array( $headers ) ) {
            foreach ( $headers as $name => $value ) {
                if ( is_array( $value ) ) {
                    $value = reset( $value );
                }
                if ( is_scalar( $value ) && '' !== (string) $name ) {
                    $request->set_header( (string) $name, (string) $value );
                }
            }
        }

        $content_type = (string) ( $row['content_type'] ?? '' );
        if ( '' !== $content_type && '' === (string) $request->get_header( 'content-type' ) ) {
            $request->set_header( 'content-type', $content_type );
        }

        if ( str_contains( strtolower( $content_type ), 'application/x-www-form-urlencoded' ) ) {
            $parsed = array();
            parse_str( $body, $parsed );
            if ( is_array( $parsed ) ) {
                $request->set_body_params( wp_unslash( $parsed ) );
            }
        }

        return $request;
    }


    public function count_all(): int {
        return $this->count();
    }

    public function count_status( string $status ): int {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( '' === $status ) {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE processing_status = %s',
                Database::table( 'webhook_events' ),
                $status
            )
        );
    }

    public function count(): int { return $this->database_count() + count( (array) get_option( 'evomembers_webhook_fallback_events', array() ) ); }

    private function database_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::table( 'webhook_events' ) ) );
    }

    private function store_event( array $row ): int {
        global $wpdb;
        $ok = $wpdb->insert( Database::table( 'webhook_events' ), $row );
        if ( false !== $ok ) {
            delete_option( 'evomembers_webhook_last_storage_error' );
            return (int) $wpdb->insert_id;
        }
        $first = sanitize_text_field( (string) $wpdb->last_error );
        update_option( 'evomembers_webhook_last_storage_error', $first, false );
        Installer::repair();
        $ok = $wpdb->insert( Database::table( 'webhook_events' ), $row );
        if ( false !== $ok ) {
            delete_option( 'evomembers_webhook_last_storage_error' );
            return (int) $wpdb->insert_id;
        }
        update_option( 'evomembers_webhook_last_storage_error', sanitize_text_field( (string) $wpdb->last_error ), false );
        return 0;
    }

    private function store_fallback( array $row, string $reason ): string {
        $events = get_option( 'evomembers_webhook_fallback_events', array() );
        $events = is_array( $events ) ? $events : array();
        $id = 'F-' . gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 );
        $row['id'] = $id;
        $row['processing_status'] = 'captured_fallback';
        $row['verification_status'] = (string) ( $row['verification_status'] ?? 'pending' );
        $row['error_code'] = 'database_storage_unavailable';
        $row['error_message'] = sanitize_text_field( $reason );
        $events[] = $row;
        if ( count( $events ) > 200 ) { $events = array_slice( $events, -200 ); }
        update_option( 'evomembers_webhook_fallback_events', $events, false );
        return $id;
    }

    private function verify( array $integration, WP_REST_Request $request, string $body ): string {
        $mode = sanitize_key( (string) ( $integration['security_mode'] ?? 'hmac_sha256' ) );
        if ( 'none' === $mode || '' === $mode ) {
            return 'failed';
        }

        $integrations = new IntegrationService();
        $header_name  = ! empty( $integration['signature_header'] ) ? (string) $integration['signature_header'] : 'X-Evomembers-Signature';

        if ( 'header' === $mode ) {
            $secret = $integrations->secret( $integration );
            $provided = trim( (string) $request->get_header( $header_name ) );
            return '' !== $secret && '' !== $provided && hash_equals( $secret, $provided ) ? 'verified' : 'failed';
        }

        if ( 'hmac_sha256' === $mode ) {
            $secret = $integrations->secret( $integration );
            $provided = trim( (string) $request->get_header( $header_name ) );
            $provided = preg_replace( '/^sha256=/i', '', $provided );
            $expected = '' !== $secret ? hash_hmac( 'sha256', $body, $secret ) : '';
            return '' !== $expected && is_string( $provided ) && hash_equals( $expected, $provided ) ? 'verified' : 'failed';
        }

        if ( 'bearer' === $mode ) {
            $expected = $integrations->bearer_token( $integration );
            $authorization = $this->authorization_header( $request );
            if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
                return 'failed';
            }
            $provided = trim( (string) $matches[1] );
            return '' !== $expected && '' !== $provided && hash_equals( $expected, $provided ) ? 'verified' : 'failed';
        }

        if ( 'basic' === $mode ) {
            $expected_user = $integrations->basic_username( $integration );
            $expected_pass = $integrations->basic_password( $integration );
            $authorization = $this->authorization_header( $request );
            if ( ! preg_match( '/^Basic\s+(.+)$/i', $authorization, $matches ) ) {
                return 'failed';
            }
            $decoded = base64_decode( trim( (string) $matches[1] ), true );
            if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
                return 'failed';
            }
            [ $provided_user, $provided_pass ] = explode( ':', $decoded, 2 );
            return '' !== $expected_user && hash_equals( $expected_user, $provided_user ) && hash_equals( $expected_pass, $provided_pass ) ? 'verified' : 'failed';
        }

        if ( 'api_key' === $mode ) {
            $expected = $integrations->webhook_api_key( $integration );
            $api_header = $integrations->webhook_api_key_header( $integration );
            $provided = trim( (string) $request->get_header( $api_header ) );
            return '' !== $expected && '' !== $provided && hash_equals( $expected, $provided ) ? 'verified' : 'failed';
        }

        if ( 'ip_allowlist' === $mode ) {
            $ip = $this->source_ip();
            return $ip && $this->ip_allowed( $ip, (string) ( $integration['allowed_ips'] ?? '' ) ) ? 'verified' : 'failed';
        }

        if ( 'custom' === $mode ) {
            $result = \EvoMembers\Core\Hooks::apply_filters( 'verify_webhook_custom', null, $integration, $request, $body );
            if ( true === $result || 'verified' === $result ) {
                return 'verified';
            }
            if ( false === $result || 'failed' === $result ) {
                return 'failed';
            }

            // Custom verification is fail-closed. A provider-specific adapter
            // must explicitly return verified; unsigned webhook delivery is never
            // accepted by the WordPress.org build.
            return 'unsupported';
        }

        return 'unsupported';
    }

    private function normalize( array $p, string $provider = '' ): array {
        $email  = $this->first( $p, array( 'email', 'buyer_email', 'customer_email', 'user_email', 'payer_email' ) );
        $first  = $this->first( $p, array( 'first_name', 'firstname', 'buyer_first_name', 'customer_first_name' ) );
        $middle = $this->first( $p, array( 'middle_name', 'middlename' ) );
        $last   = $this->first( $p, array( 'last_name', 'lastname', 'surname', 'buyer_last_name', 'customer_last_name' ) );
        $full   = $this->first( $p, array( 'name', 'full_name', 'buyer_name', 'customer_name' ) );

        $provider = sanitize_key( $provider );
        $external_order_id = $this->first( $p, array( 'order_id', 'sale_id', 'purchase_id', 'id' ) );
        $external_transaction_id = $this->first( $p, array( 'transaction_id', 'txn_id', 'payment_id' ) );
        $total = $this->number( $this->first( $p, array( 'total', 'price', 'amount', 'sale_price' ) ) );
        $payment_status = $this->first( $p, array( 'payment_status', 'status' ) );
        if ( 'gumroad' === $provider ) {
            $external_order_id = $this->first( $p, array( 'order_number', 'sale_id', 'purchase_id', 'id' ) );
            $external_transaction_id = $this->first( $p, array( 'sale_id', 'transaction_id', 'txn_id', 'payment_id' ) );
            $raw_price = $this->first( $p, array( 'price', 'total', 'amount', 'sale_price' ) );
            $total = null !== $raw_price && '' !== (string) $raw_price ? number_format( (float) $raw_price / 100, 8, '.', '' ) : null;
            $payment_status = $this->truthy( $p['refunded'] ?? false ) ? 'refunded' : ( $this->truthy( $p['disputed'] ?? false ) ? 'disputed' : 'paid' );
        }

        return array(
            'customer' => array(
                'email'              => $email,
                'secondary_email'    => $this->first( $p, array( 'secondary_email', 'alternate_email' ) ),
                'first_name'         => $first,
                'middle_name'        => $middle,
                'last_name'          => $last,
                'display_name'       => $full ?: trim( (string) $first . ' ' . (string) $last ),
                'phone'              => $this->first( $p, array( 'phone', 'phone_number', 'mobile', 'telephone' ) ),
                'phone_country_code' => $this->first( $p, array( 'phone_country_code', 'calling_code' ) ),
                'company'            => $this->first( $p, array( 'company', 'business_name' ) ),
                'address_1'          => $this->first( $p, array( 'address', 'address_1', 'street', 'street_address', 'billing_address_1' ) ),
                'address_2'          => $this->first( $p, array( 'address_2', 'street_2', 'billing_address_2' ) ),
                'city'               => $this->first( $p, array( 'city', 'billing_city' ) ),
                'state_region'       => $this->first( $p, array( 'state', 'region', 'province', 'billing_state' ) ),
                'postal_code'        => $this->first( $p, array( 'zip', 'zipcode', 'postal_code', 'postcode', 'billing_postcode' ) ),
                'country_code'       => $this->first( $p, array( 'country_code', 'country', 'billing_country' ) ),
                'tax_id'             => $this->first( $p, array( 'tax_id', 'tax_number' ) ),
                'vat_number'         => $this->first( $p, array( 'vat_number', 'vat_id' ) ),
                'purchased_at'       => $this->first( $p, array( 'purchase_date', 'purchased_at', 'sale_timestamp', 'created_at' ) ),
            ),
            'product' => array(
                'external_product_id' => (string) $this->first( $p, array( 'product_id', 'product', 'item_id', 'offer_id' ) ),
                'external_variant_id' => (string) $this->first( $p, array( 'variant_id', 'variant', 'price_id' ) ),
                'name'                => $this->first( $p, array( 'product_name', 'item_name', 'offer_name' ) ),
                'quantity'            => max( 1, absint( $this->first( $p, array( 'quantity', 'qty', 'item_quantity' ) ) ?: 1 ) ),
            ),
            'license' => array(
                'key'       => $this->first( $p, array( 'license_key', 'license', 'serial', 'serial_key', 'activation_key', 'key' ) ),
                'status'    => $this->first( $p, array( 'license_status', 'activation_status' ) ) ?: 'active',
                'reference' => $this->first( $p, array( 'license_id', 'license_reference', 'serial_id' ) ),
            ),
            'order' => array(
                'external_order_id'       => $external_order_id,
                'external_transaction_id' => $external_transaction_id,
                'currency'                => strtoupper( (string) $this->first( $p, array( 'currency', 'currency_code' ) ) ),
                'subtotal'                => $this->number( $this->first( $p, array( 'subtotal' ) ) ),
                'discount'                => $this->number( $this->first( $p, array( 'discount', 'discount_amount' ) ) ),
                'tax'                     => $this->number( $this->first( $p, array( 'tax', 'tax_amount' ) ) ),
                'shipping'                => $this->number( $this->first( $p, array( 'shipping', 'shipping_amount' ) ) ),
                'total'                   => $total,
                'coupon_code'             => $this->first( $p, array( 'coupon', 'coupon_code' ) ),
                'payment_method'          => $this->first( $p, array( 'payment_method', 'payment_type' ) ),
                'payment_status'          => $payment_status,
                'purchased_at'            => $this->first( $p, array( 'purchase_date', 'purchased_at', 'sale_timestamp', 'created_at' ) ),
            ),
        );
    }

    private function product_identifiers( array $payload, array $normalized ): array {
        $values = array( $normalized['product']['external_product_id'] ?? '' );
        foreach ( array( 'product_id', 'short_product_id', 'product', 'item_id', 'offer_id', 'permalink', 'product_permalink', 'sku' ) as $key ) {
            if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
                $values[] = (string) $payload[ $key ];
            }
        }
        if ( ! empty( $payload['product_permalink'] ) && is_scalar( $payload['product_permalink'] ) ) {
            $path = (string) wp_parse_url( (string) $payload['product_permalink'], PHP_URL_PATH );
            if ( '' !== $path ) {
                $values[] = basename( untrailingslashit( $path ) );
            }
        }
        $out = array();
        foreach ( $values as $value ) {
            $value = trim( (string) $value );
            if ( '' === $value ) {
                continue;
            }
            foreach ( array( $value, rawurldecode( $value ), urldecode( $value ) ) as $candidate ) {
                $candidate = sanitize_text_field( trim( (string) $candidate ) );
                if ( '' !== $candidate && ! in_array( $candidate, $out, true ) ) {
                    $out[] = $candidate;
                }
            }
        }
        return $out;
    }

    private function truthy( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
    }

    private function create_order( int $integration_id, int $customer_id, array $normalized, array $payload ): int {
        global $wpdb;
        $order          = $normalized['order'];
        $now            = current_time( 'mysql', true );
        $external_order = $this->text( $order['external_order_id'], 190 );
        $external_txn   = $this->text( $order['external_transaction_id'], 190 );

        if ( $external_order ) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT id FROM %i WHERE integration_id = %d AND external_order_id = %s LIMIT 1', Database::table( 'orders' ), $integration_id, $external_order )
            );
            if ( $existing > 0 ) {
                return $existing;
            }
        }

        $wpdb->insert(
            Database::table( 'orders' ),
            array(
                'customer_id'             => $customer_id ?: null,
                'integration_id'          => $integration_id,
                'external_order_id'       => $external_order,
                'external_transaction_id' => $external_txn,
                'status'                  => 'received',
                'currency'                => $this->text( $order['currency'], 12 ),
                'subtotal'                => $order['subtotal'],
                'discount'                => $order['discount'],
                'tax'                     => $order['tax'],
                'shipping'                => $order['shipping'],
                'total'                   => $order['total'],
                'coupon_code'             => $this->text( $order['coupon_code'], 100 ),
                'payment_method'          => $this->text( $order['payment_method'], 100 ),
                'payment_status'          => $this->text( $order['payment_status'], 50 ),
                'purchased_at'            => $this->date( $order['purchased_at'] ),
                'provider_data_json'      => wp_json_encode( $payload ),
                'created_at'              => $now,
                'updated_at'              => $now,
            )
        );
        $created_id = (int) $wpdb->insert_id;
        if ( $created_id > 0 ) { EventBus::emit( 'order.created', array( 'order_id'=>$created_id, 'external_order_id'=>$external_order ), 'order', $created_id, $customer_id, 'webhook' ); }
        return $created_id;
    }

    private function stored_fulfillment( int $order_id ): ?array {
        global $wpdb;
        if ( $order_id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT fulfillment_status, fulfillment_result_json FROM %i WHERE id = %d LIMIT 1', Database::table( 'orders' ), $order_id ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || 'processed' !== (string) ( $row['fulfillment_status'] ?? '' ) || empty( $row['fulfillment_result_json'] ) ) {
            return null;
        }
        $result = json_decode( (string) $row['fulfillment_result_json'], true );
        return is_array( $result ) ? $result : null;
    }

    private function store_fulfillment( int $order_id, string $mode, array $result ): void {
        global $wpdb;
        if ( $order_id < 1 ) { return; }
        $wpdb->update(
            Database::table( 'orders' ),
            array(
                'fulfillment_mode'        => sanitize_key( $mode ),
                'fulfillment_status'      => 'processed',
                'fulfillment_result_json' => wp_json_encode( $result ),
                'fulfilled_at'            => current_time( 'mysql', true ),
                'updated_at'              => current_time( 'mysql', true ),
            ),
            array( 'id' => $order_id )
        );
    }

    private function store_fulfillment_error( int $order_id, string $mode, string $code, string $message ): void {
        global $wpdb;
        if ( $order_id < 1 ) { return; }
        $wpdb->update(
            Database::table( 'orders' ),
            array(
                'fulfillment_mode'        => sanitize_key( $mode ),
                'fulfillment_status'      => 'failed',
                'fulfillment_result_json' => wp_json_encode( array( 'error_code' => sanitize_key( $code ), 'error_message' => sanitize_text_field( $message ) ) ),
                'updated_at'              => current_time( 'mysql', true ),
            ),
            array( 'id' => $order_id )
        );
    }

    private function fail( int $id, string $code, string $message, string $verification ): void {
        global $wpdb;
        $wpdb->update(
            Database::table( 'webhook_events' ),
            array(
                'verification_status' => $verification,
                'processing_status'   => 'rejected',
                'failed_at'           => current_time( 'mysql', true ),
                'error_code'          => sanitize_key( $code ),
                'error_message'       => sanitize_text_field( $message ),
                'updated_at'          => current_time( 'mysql', true ),
            ),
            array( 'id' => $id )
        );
    }

    /** @return array<string|int,mixed> */
    private function sanitize_payload_for_storage( array $payload ): array {
        $clean = array();
        foreach ( $payload as $key => $value ) {
            $safe_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
            if ( ! is_int( $safe_key ) && $this->is_sensitive_storage_key( (string) $safe_key ) ) {
                $clean[ $safe_key ] = '[redacted]';
                continue;
            }
            if ( is_array( $value ) ) {
                $clean[ $safe_key ] = $this->sanitize_payload_for_storage( $value );
            } elseif ( is_scalar( $value ) || null === $value ) {
                $clean[ $safe_key ] = sanitize_textarea_field( (string) $value );
            }
        }
        return $clean;
    }

    /** @return array<string,mixed> */
    private function sanitize_headers_for_storage( array $headers ): array {
        $clean = array();
        foreach ( $headers as $key => $value ) {
            $safe_key = sanitize_key( (string) $key );
            if ( '' === $safe_key ) {
                continue;
            }
            if ( $this->is_sensitive_storage_key( $safe_key ) || in_array( $safe_key, array( 'cookie', 'set_cookie' ), true ) ) {
                $clean[ $safe_key ] = '[redacted]';
                continue;
            }
            $values = is_array( $value ) ? $value : array( $value );
            $clean[ $safe_key ] = array_map(
                static fn( mixed $item ): string => mb_substr( sanitize_text_field( (string) $item ), 0, 1000 ),
                $values
            );
        }
        return $clean;
    }

    private function is_sensitive_storage_key( string $key ): bool {
        $key = sanitize_key( $key );
        if ( '' === $key ) {
            return false;
        }
        foreach ( array( 'password', 'passwd', 'pass', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'signature', 'credential', 'private_key', 'access_key' ) as $needle ) {
            if ( false !== strpos( $key, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private function parse_payload( WP_REST_Request $request, string $raw ): array {
        $json = json_decode( $raw, true );
        if ( is_array( $json ) ) {
            return $json;
        }

        $body = $request->get_body_params();
        if ( is_array( $body ) && ! empty( $body ) ) {
            return $body;
        }

        if ( '' !== trim( $raw ) ) {
            $parsed = array();
            parse_str( $raw, $parsed );
            if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                return wp_unslash( $parsed );
            }
        }

        return array();
    }

    private function first( array $data, array $keys ): mixed {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $data ) && null !== $data[ $key ] && '' !== (string) $data[ $key ] ) {
                return $data[ $key ];
            }
        }
        return null;
    }

    private function text( mixed $value, int $max ): ?string {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return null;
        }
        return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
    }

    private function number( mixed $value ): ?string {
        if ( null === $value || '' === (string) $value || ! is_numeric( $value ) ) {
            return null;
        }
        return number_format( (float) $value, 8, '.', '' );
    }

    private function date( mixed $value ): ?string {
        if ( empty( $value ) ) {
            return null;
        }
        $time = strtotime( (string) $value );
        return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
    }

    private function authorization_header( WP_REST_Request $request ): string {
        $value = trim( (string) $request->get_header( 'authorization' ) );
        if ( '' !== $value ) {
            return $value;
        }
        foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
            $server_value = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) : '';
            if ( '' !== $server_value ) {
                return $server_value;
            }
        }
        $user = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ) : '';
        if ( '' !== $user ) {
            $pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['PHP_AUTH_PW'] ) ) : '';
            return 'Basic ' . base64_encode( $user . ':' . $pass );
        }
        return '';
    }

    private function ip_allowed( string $ip, string $rules ): bool {
        $rules = preg_split( '/[\s,;]+/', trim( $rules ) );
        if ( ! is_array( $rules ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $rule = trim( (string) $rule );
            if ( '' === $rule ) {
                continue;
            }
            if ( $rule === $ip ) {
                return true;
            }
            if ( false !== strpos( $rule, '/' ) && $this->ip_in_cidr( $ip, $rule ) ) {
                return true;
            }
        }
        return false;
    }

    private function ip_in_cidr( string $ip, string $cidr ): bool {
        [ $network, $prefix ] = array_pad( explode( '/', $cidr, 2 ), 2, null );
        if ( null === $prefix || ! ctype_digit( (string) $prefix ) ) {
            return false;
        }
        $ip_bin = @inet_pton( $ip );
        $network_bin = @inet_pton( trim( (string) $network ) );
        if ( false === $ip_bin || false === $network_bin || strlen( $ip_bin ) !== strlen( $network_bin ) ) {
            return false;
        }
        $bits = (int) $prefix;
        $max_bits = 8 * strlen( $ip_bin );
        if ( $bits < 0 || $bits > $max_bits ) {
            return false;
        }
        $bytes = intdiv( $bits, 8 );
        $remaining = $bits % 8;
        if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $network_bin, 0, $bytes ) ) {
            return false;
        }
        if ( 0 === $remaining ) {
            return true;
        }
        $mask = ( 0xFF << ( 8 - $remaining ) ) & 0xFF;
        return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $network_bin[ $bytes ] ) & $mask );
    }

    private function source_ip(): ?string {
        if ( null !== $this->replay_source_ip ) {
            return $this->replay_source_ip;
        }
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( '' === $ip ) {
            return null;
        }
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
    }
}
