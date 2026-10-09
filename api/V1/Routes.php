<?php
namespace EvoMembers\Api\V1;

use EvoMembers\Services\CustomerService;
use EvoMembers\Services\LicenseService;
use EvoMembers\Services\MembershipService;
use EvoMembers\Services\MessageService;
use EvoMembers\Services\NotificationService;
use EvoMembers\Services\ProductService;
use EvoMembers\Services\PlanService;
use EvoMembers\Services\RestKeyService;
use EvoMembers\Services\AuthorityRouter;
use EvoMembers\Services\WebhookService;
use EvoMembers\Core\RequestGuard;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Routes {
    public static function register(): void {
        register_rest_route( 'evomembers/v1', '/discovery', array( 'methods' => 'GET', 'callback' => array( self::class, 'discovery' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'evomembers/v1', '/licenses/verify', array( 'methods' => 'POST', 'callback' => array( self::class, 'verify_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/licenses/activate', array( 'methods' => 'POST', 'callback' => array( self::class, 'activate_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/licenses/deactivate', array( 'methods' => 'POST', 'callback' => array( self::class, 'deactivate_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        // Stable public external-client layer. It deliberately carries no
        // server master secret; the customer license + site policy are the
        // credential, protected by RequestGuard rate/abuse controls.
        register_rest_route( 'evomembers/v1', '/public/discovery', array( 'methods' => 'GET', 'callback' => array( self::class, 'public_discovery' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'evomembers/v1', '/public/license/verify', array( 'methods' => 'POST', 'callback' => array( self::class, 'verify_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/public/license/activate', array( 'methods' => 'POST', 'callback' => array( self::class, 'activate_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/public/license/deactivate', array( 'methods' => 'POST', 'callback' => array( self::class, 'deactivate_license' ), 'permission_callback' => array( self::class, 'public_license_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/webhooks/(?P<platform>[a-z0-9_-]+)', array( 'methods' => 'POST', 'callback' => array( self::class, 'webhook' ), 'permission_callback' => array( self::class, 'public_webhook_guard' ) ) );
        register_rest_route( 'evomembers/v1', '/webhooks/(?P<platform>[a-z0-9_-]+)', array( 'methods' => 'GET', 'callback' => array( self::class, 'webhook_info' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'evomembers/v1', '/me/membership', array( 'methods' => 'GET', 'callback' => array( self::class, 'my_membership' ), 'permission_callback' => array( self::class, 'logged_in' ) ) );
        register_rest_route( 'evomembers/v1', '/me/messages', array( 'methods' => 'GET', 'callback' => array( self::class, 'my_messages' ), 'permission_callback' => array( self::class, 'logged_in' ) ) );
        register_rest_route( 'evomembers/v1', '/me/notifications', array( 'methods' => 'GET', 'callback' => array( self::class, 'my_notifications' ), 'permission_callback' => array( self::class, 'logged_in' ) ) );
        register_rest_route( 'evomembers/v1', '/me/messages/(?P<id>\d+)/read', array( 'methods' => 'POST', 'callback' => array( self::class, 'read_message' ), 'permission_callback' => array( self::class, 'logged_in' ) ) );
        register_rest_route( 'evomembers/v1', '/me/notifications/(?P<id>\d+)/read', array( 'methods' => 'POST', 'callback' => array( self::class, 'read_notification' ), 'permission_callback' => array( self::class, 'logged_in' ) ) );
        register_rest_route( 'evomembers/v1', '/integration/products', array( 'methods' => 'GET', 'callback' => array( self::class, 'integration_products' ), 'permission_callback' => array( self::class, 'can_read_products' ) ) );
        register_rest_route( 'evomembers/v1', '/integration/licenses/verify', array( 'methods' => 'POST', 'callback' => array( self::class, 'integration_verify_license' ), 'permission_callback' => array( self::class, 'can_verify_license' ) ) );
        register_rest_route( 'evomembers/v1', '/integration/licenses/activate', array( 'methods' => 'POST', 'callback' => array( self::class, 'integration_activate_license' ), 'permission_callback' => array( self::class, 'can_manage_licenses' ) ) );
        register_rest_route( 'evomembers/v1', '/integration/licenses/deactivate', array( 'methods' => 'POST', 'callback' => array( self::class, 'integration_deactivate_license' ), 'permission_callback' => array( self::class, 'can_manage_licenses' ) ) );
        register_rest_route( 'evomembers/v1', '/integration/discovery', array( 'methods' => 'GET', 'callback' => array( self::class, 'integration_discovery' ), 'permission_callback' => array( self::class, 'can_inspect_credential' ) ) );
    }

    public static function logged_in(): bool { return is_user_logged_in(); }

    public static function public_license_guard( WP_REST_Request $request ): true|\WP_Error { return RequestGuard::license_request( $request ); }
    public static function public_webhook_guard( WP_REST_Request $request ): true|\WP_Error { return RequestGuard::webhook_request( $request ); }

    public static function can_read_products( WP_REST_Request $request ): bool|\WP_Error { return ( new RestKeyService() )->authenticate( $request, 'read_products' ); }
    public static function can_verify_license( WP_REST_Request $request ): bool|\WP_Error { return ( new RestKeyService() )->authenticate( $request, 'verify_license' ); }
    public static function can_manage_licenses( WP_REST_Request $request ): bool|\WP_Error { return ( new RestKeyService() )->authenticate( $request, 'manage_licenses' ); }
    public static function can_inspect_credential( WP_REST_Request $request ): bool|\WP_Error { $result = ( new RestKeyService() )->inspect( $request ); return is_wp_error( $result ) ? $result : true; }

    public static function integration_products( WP_REST_Request $request ): WP_REST_Response {
        // ProductService is already the canonical Product directory for EVO and
        // discovered WooCommerce products. Expose its existing identity fields
        // so trusted infrastructure can render a selector instead of asking an
        // operator to guess which numeric ID belongs to which system.
        $items = array_map( static function( array $p ): array {
            return array(
                'id'                 => absint( $p['id'] ?? 0 ),
                'code'               => sanitize_key( (string) ( $p['code'] ?? '' ) ),
                'name'               => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
                'source'             => sanitize_key( (string) ( $p['source'] ?? ProductService::SOURCE_EVO ) ),
                'external_source_id' => sanitize_text_field( (string) ( $p['external_source_id'] ?? '' ) ),
                'status'             => sanitize_key( (string) ( $p['status'] ?? 'active' ) ),
            );
        }, ( new ProductService() )->linkable() );
        return new WP_REST_Response( array( 'success'=>true, 'data'=>array( 'items'=>$items ), 'meta'=>self::integration_meta( $request ) ), 200 );
    }

    public static function integration_verify_license( WP_REST_Request $request ): WP_REST_Response {
        $license_key = sanitize_text_field( (string) $request->get_param( 'license_key' ) );
        $requested_product_id = absint( $request->get_param( 'product_id' ) );
        $required_tier = sanitize_key( (string) $request->get_param( 'required_tier' ) );
        $context = self::license_context( $request );

        // Validate the key/site first without forcing direct-product equality.
        // The same existing key may represent a direct Product or a Membership
        // whose existing Plan contains one or several Products.
        $base_context = $context;
        $base_context['product_id'] = 0;
        $base_context['product_code'] = '';
        $licenses = new LicenseService();
        $result = $licenses->verify( $license_key, $base_context );
        if ( is_wp_error( $result ) ) { return self::error( $result ); }

        $customer_id = absint( $result['customer_id'] ?? 0 );
        $customer = $customer_id > 0 ? ( new CustomerService() )->get( $customer_id ) : null;
        $direct_product_match = $requested_product_id > 0 && absint( $result['product_id'] ?? 0 ) === $requested_product_id;

        // A verified license identifies its existing Core customer. Membership
        // access is therefore resolved from that customer's already-stored
        // memberships. This supports Core's existing ability to grant multiple
        // Products through a Plan without inventing a second entitlement model.
        $membership_service = new MembershipService();
        $membership_candidates = array();
        $linked_membership_id = absint( $result['membership_id'] ?? 0 );
        if ( $linked_membership_id > 0 ) {
            $linked = $membership_service->get( $linked_membership_id );
            if ( is_array( $linked ) ) { $membership_candidates[] = $linked; }
        }
        if ( $customer_id > 0 ) {
            foreach ( $membership_service->for_customer( $customer_id ) as $candidate ) {
                if ( ! is_array( $candidate ) ) { continue; }
                $candidate_id = absint( $candidate['id'] ?? 0 );
                $seen = false;
                foreach ( $membership_candidates as $existing ) {
                    if ( absint( $existing['id'] ?? 0 ) === $candidate_id ) { $seen = true; break; }
                }
                if ( ! $seen ) { $membership_candidates[] = $candidate; }
            }
        }

        $membership = null;
        $membership_active = false;
        $membership_product_match = false;
        $membership_tier = '';
        $now = time();
        foreach ( $membership_candidates as $candidate ) {
            $starts_at = ! empty( $candidate['starts_at'] ) ? strtotime( (string) $candidate['starts_at'] . ' UTC' ) : false;
            $expires_at = ! empty( $candidate['expires_at'] ) ? strtotime( (string) $candidate['expires_at'] . ' UTC' ) : false;
            $active = 'active' === sanitize_key( (string) ( $candidate['status'] ?? '' ) )
                && ( false === $starts_at || $starts_at <= $now )
                && ( false === $expires_at || $expires_at >= $now );
            if ( ! $active ) { continue; }

            $matches_product = false;
            if ( $requested_product_id < 1 ) {
                $matches_product = true;
            } elseif ( absint( $candidate['plan_id'] ?? 0 ) > 0 ) {
                foreach ( ( new PlanService() )->products( absint( $candidate['plan_id'] ) ) as $plan_product ) {
                    if ( absint( $plan_product['id'] ?? 0 ) === $requested_product_id ) {
                        $matches_product = true;
                        break;
                    }
                }
            }
            if ( ! $matches_product ) { continue; }

            $candidate_tier = sanitize_key( (string) ( $candidate['tier'] ?? '' ) );
            $candidate_tier_allowed = '' === $required_tier || 'free' === $required_tier || self::tier_satisfies( $candidate_tier, $required_tier );
            if ( ! $candidate_tier_allowed ) {
                // Keep looking: the same customer can legitimately own another
                // active membership at a higher tier.
                continue;
            }

            $membership = $candidate;
            $membership_active = true;
            $membership_product_match = true;
            $membership_tier = $candidate_tier;
            break;
        }

        $membership_id = is_array( $membership ) ? absint( $membership['id'] ?? 0 ) : 0;
        if ( $requested_product_id < 1 ) {
            $product_entitled = true;
        } else {
            $product_entitled = $direct_product_match || $membership_product_match;
        }

        // An exact standalone Product key is sufficient for that Product.
        // Membership tier hierarchy applies when the grant comes from a Plan.
        if ( $direct_product_match ) {
            $tier_allowed = true;
        } elseif ( $membership_product_match ) {
            $tier_allowed = '' === $required_tier || 'free' === $required_tier || self::tier_satisfies( $membership_tier, $required_tier );
        } else {
            $tier_allowed = '' === $required_tier || 'free' === $required_tier;
        }
        $access_granted = ! empty( $result['valid'] ) && $product_entitled && $tier_allowed;
        $activation_id = 0;

        // Bind/refresh the supplied domain only after the complete entitlement
        // decision succeeds, so denied package requests never consume a slot.
        $site_url = esc_url_raw( (string) ( $context['site_url'] ?? '' ) );
        if ( $access_granted && ! empty( $context['bind_site'] ) && '' !== $site_url ) {
            $activation = $licenses->activate(
                $license_key,
                $site_url,
                sanitize_text_field( (string) ( $context['client_version'] ?? '' ) ),
                sanitize_text_field( (string) ( $context['server_ip'] ?? '' ) )
            );
            if ( is_wp_error( $activation ) ) { return self::error( $activation ); }
            $activation_id = absint( $activation['activation_id'] ?? 0 );
        }

        if ( $access_granted ) {
            $access_reason = $direct_product_match ? 'direct_product' : ( $membership_product_match ? 'membership_plan_product' : 'valid_key' );
        } elseif ( $membership_id > 0 && ! $membership_active && ! $direct_product_match ) {
            $access_reason = 'membership_not_active';
        } elseif ( ! $product_entitled ) {
            $access_reason = 'product_not_entitled';
        } else {
            $access_reason = 'tier_not_allowed';
        }

        if ( is_array( $membership ) ) {
            $result['membership_id'] = $membership_id;
            $result['membership_status'] = sanitize_key( (string) ( $membership['status'] ?? '' ) );
            $result['plan_id'] = absint( $membership['plan_id'] ?? 0 );
            $result['plan_code'] = sanitize_key( (string) ( $membership['plan_code'] ?? '' ) );
            $result['plan_name'] = sanitize_text_field( (string) ( $membership['plan_name'] ?? '' ) );
            $result['tier'] = $membership_tier;
        }
        $result['authority'] = 'internal';
        $result['activation_id'] = $activation_id;
        $result['requested_product_id'] = $requested_product_id;
        $result['required_tier'] = $required_tier;
        $result['entitled'] = $product_entitled;
        $result['tier_allowed'] = $tier_allowed;
        $result['site_allowed'] = true;
        $result['access_granted'] = $access_granted;
        $result['access_reason'] = $access_reason;
        $result['membership_active'] = $membership_active;
        $result['membership_starts_at'] = is_array( $membership ) ? sanitize_text_field( (string) ( $membership['starts_at'] ?? '' ) ) : '';
        $result['membership_expires_at'] = is_array( $membership ) ? sanitize_text_field( (string) ( $membership['expires_at'] ?? '' ) ) : '';
        $result['subject'] = array(
            'customer_id'   => $customer_id,
            'wp_user_id'    => is_array( $customer ) ? absint( $customer['wp_user_id'] ?? 0 ) : 0,
            'email'         => is_array( $customer ) ? sanitize_email( (string) ( $customer['email'] ?? '' ) ) : '',
            'membership_id' => $membership_id,
        );

        return new WP_REST_Response( array( 'success' => true, 'data' => $result, 'meta'=>self::integration_meta( $request ) ), 200 );
    }

    public static function integration_activate_license( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new LicenseService() )->activate(
            sanitize_text_field( (string) $request->get_param( 'license_key' ) ),
            esc_url_raw( (string) $request->get_param( 'site_url' ) ),
            sanitize_text_field( (string) $request->get_param( 'client_version' ) ),
            sanitize_text_field( (string) $request->get_param( 'server_ip' ) )
        );
        if ( ! is_wp_error( $result ) ) { $result['authority'] = 'internal'; }
        return is_wp_error( $result ) ? self::error( $result ) : new WP_REST_Response( array( 'success' => true, 'data' => $result, 'meta'=>self::integration_meta( $request ) ), 200 );
    }

    public static function integration_deactivate_license( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new LicenseService() )->deactivate(
            sanitize_text_field( (string) $request->get_param( 'license_key' ) ),
            esc_url_raw( (string) $request->get_param( 'site_url' ) ),
            sanitize_text_field( (string) $request->get_param( 'server_ip' ) )
        );
        if ( ! is_wp_error( $result ) ) { $result['authority'] = 'internal'; }
        return is_wp_error( $result ) ? self::error( $result ) : new WP_REST_Response( array( 'success' => true, 'data' => $result, 'meta'=>self::integration_meta( $request ) ), 200 );
    }

    public static function integration_discovery( WP_REST_Request $request ): WP_REST_Response {
        $credential = ( new RestKeyService() )->inspect( $request );
        if ( is_wp_error( $credential ) ) { return self::error( $credential ); }
        $server = self::server_capabilities();
        $required = self::capability_permissions();
        $effective = array();
        foreach ( $server as $capability => $supported ) {
            $permission = $required[ $capability ] ?? '';
            $effective[ $capability ] = (bool) $supported && ( '' === $permission || in_array( $permission, (array) $credential['permissions'], true ) );
        }
        return new WP_REST_Response( array( 'success'=>true, 'data'=>array(
            'platform'=>'EVO', 'plugin'=>'Evoxup Membership', 'version'=>EVOMEMBERS_VERSION, 'api_version'=>'v1',
            'api_base'=>untrailingslashit( rest_url( 'evomembers/v1' ) ), 'node_id'=>\EvoMembers\API\ApiConfig::node_id(),
            'server_capabilities'=>$server, 'credential_permissions'=>(array)$credential['permissions'], 'effective_capabilities'=>$effective,
            'credential'=>array( 'name'=>$credential['name'], 'public_id'=>$credential['public_id'], 'ip_limited'=>$credential['ip_limited'] ),
        ), 'meta'=>self::integration_meta( $request ) ), 200 );
    }

    public static function public_discovery(): WP_REST_Response {
        return new WP_REST_Response( array( 'success'=>true, 'data'=>array(
            'platform'=>'EVO', 'plugin'=>'Evoxup Membership', 'version'=>EVOMEMBERS_VERSION,
            'api_version'=>'v1', 'public_api_base'=>untrailingslashit( rest_url( 'evomembers/v1/public' ) ),
            'endpoints'=>array( 'license.verify'=>'/license/verify', 'license.activate'=>'/license/activate', 'license.deactivate'=>'/license/deactivate' ),
            'authentication'=>'customer_license_and_site_policy',
            'server_secret_required'=>false,
        ) ), 200 );
    }

    public static function discovery(): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'success' => true,
                'data' => array(
                    'platform' => 'EVO', 'plugin' => 'Evoxup Membership', 'version' => EVOMEMBERS_VERSION,
                    'api_version' => 'v1', 'api_base' => untrailingslashit( rest_url( 'evomembers/v1' ) ), 'public_api_base' => untrailingslashit( rest_url( 'evomembers/v1/public' ) ), 'node_id' => \EvoMembers\API\ApiConfig::node_id(),
                    'extension_api_version' => EVOMEMBERS_EXTENSION_API_VERSION,
                    'extension_api_min_version' => defined( 'EVOMEMBERS_EXTENSION_API_MIN_VERSION' ) ? EVOMEMBERS_EXTENSION_API_MIN_VERSION : '1.0.0',
                    'event_schema_version' => defined( 'EVOMEMBERS_EVENT_SCHEMA_VERSION' ) ? EVOMEMBERS_EVENT_SCHEMA_VERSION : '1.0.0',
                    'sdk_version' => defined( 'EVOMEMBERS_SDK_VERSION' ) ? EVOMEMBERS_SDK_VERSION : EVOMEMBERS_EXTENSION_API_VERSION,
                    'capabilities' => array_keys( array_filter( self::server_capabilities() ) ),
                    'server_capabilities' => self::server_capabilities(),
                    'authority_modes' => array( 'internal', 'external', 'hybrid' ),
                    'cooperation' => array( 'local_first_hybrid'=>true, 'remote_integration_endpoints_are_local_only'=>true, 'self_loop_protection'=>true ),
                ),
            ), 200
        );
    }

    public static function verify_license( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new AuthorityRouter() )->verify_license( sanitize_text_field( (string) $request->get_param( 'license_key' ) ), self::license_context( $request ) );
        return is_wp_error( $result ) ? self::error( $result ) : new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
    }

    public static function activate_license( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new AuthorityRouter() )->activate_license( sanitize_text_field( (string) $request->get_param( 'license_key' ) ), esc_url_raw( (string) $request->get_param( 'site_url' ) ), sanitize_text_field( (string) $request->get_param( 'client_version' ) ), sanitize_text_field( (string) $request->get_param( 'server_ip' ) ) );
        return is_wp_error( $result ) ? self::error( $result ) : new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
    }

    public static function deactivate_license( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new AuthorityRouter() )->deactivate_license( sanitize_text_field( (string) $request->get_param( 'license_key' ) ), esc_url_raw( (string) $request->get_param( 'site_url' ) ), sanitize_text_field( (string) $request->get_param( 'server_ip' ) ) );
        return is_wp_error( $result ) ? self::error( $result ) : new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
    }

    public static function webhook_info( WP_REST_Request $request ): WP_REST_Response {
        $platform = sanitize_key( (string) $request['platform'] );
        return new WP_REST_Response( array(
            'success' => true, 'platform' => $platform,
            'endpoint' => rest_url( 'evomembers/v1/webhooks/' . $platform ),
            'webhook_method' => 'POST', 'status' => 'ready',
            'message' => 'POST events are authenticated first. Only verified events are sanitized, stored, and processed.',
        ), 200 );
    }

    public static function webhook( WP_REST_Request $request ): WP_REST_Response {
        $result = ( new WebhookService() )->receive( sanitize_key( (string) $request['platform'] ), $request );
        $status = isset( $result['status'] ) ? (int) $result['status'] : 200;
        unset( $result['status'] );
        return new WP_REST_Response( $result, $status );
    }

    public static function my_membership(): WP_REST_Response {
        $customer_id = self::current_customer_id();
        if ( $customer_id < 1 ) { return new WP_REST_Response( array( 'success' => true, 'data' => array( 'current' => null, 'history' => array() ) ), 200 ); }
        $service = new MembershipService();
        return new WP_REST_Response( array( 'success' => true, 'data' => array( 'current' => $service->current_for_customer( $customer_id ), 'history' => $service->for_customer( $customer_id ) ) ), 200 );
    }

    public static function my_messages(): WP_REST_Response {
        $customer_id = self::current_customer_id();
        return new WP_REST_Response( array( 'success' => true, 'data' => $customer_id > 0 ? ( new MessageService() )->for_customer( $customer_id ) : array() ), 200 );
    }

    public static function my_notifications(): WP_REST_Response {
        $customer_id = self::current_customer_id();
        return new WP_REST_Response( array( 'success' => true, 'data' => $customer_id > 0 ? ( new NotificationService() )->for_customer( $customer_id ) : array() ), 200 );
    }

    public static function read_message( WP_REST_Request $request ): WP_REST_Response {
        $customer_id = self::current_customer_id();
        $ok = $customer_id > 0 && ( new MessageService() )->mark_read( absint( $request['id'] ), $customer_id );
        return new WP_REST_Response( array( 'success' => $ok ), $ok ? 200 : 404 );
    }

    public static function read_notification( WP_REST_Request $request ): WP_REST_Response {
        $customer_id = self::current_customer_id();
        $ok = $customer_id > 0 && ( new NotificationService() )->mark_read( absint( $request['id'] ), $customer_id );
        return new WP_REST_Response( array( 'success' => $ok ), $ok ? 200 : 404 );
    }

    private static function integration_meta( ?WP_REST_Request $request ): array {
        return array(
            'request_id' => $request ? sanitize_text_field( (string) $request->get_header( 'x-evomembers-request-id' ) ) : '',
            'origin'     => $request ? sanitize_text_field( (string) $request->get_header( 'x-evomembers-origin' ) ) : '',
            'node_id'    => \EvoMembers\API\ApiConfig::node_id(),
        );
    }

    private static function license_context( WP_REST_Request $request ): array {
        return array(
            'product_id'     => absint( $request->get_param( 'product_id' ) ),
            'product_code'   => sanitize_key( (string) $request->get_param( 'product_code' ) ),
            'site_url'       => esc_url_raw( (string) $request->get_param( 'site_url' ) ),
            'domain'         => sanitize_text_field( (string) $request->get_param( 'domain' ) ),
            'server_ip'      => sanitize_text_field( (string) $request->get_param( 'server_ip' ) ),
            'client_version' => sanitize_text_field( (string) $request->get_param( 'client_version' ) ),
            'required_tier'   => sanitize_key( (string) $request->get_param( 'required_tier' ) ),
            'bind_site'       => rest_sanitize_boolean( $request->get_param( 'bind_site' ) ),
        );
    }

    private static function tier_satisfies( string $actual, string $required ): bool {
        $actual = sanitize_key( $actual );
        $required = sanitize_key( $required );
        if ( '' === $required || 'free' === $required ) { return true; }
        $rank = array( 'free'=>0, 'pro'=>1, 'super_star'=>2, 'superstar'=>2 );
        if ( ! array_key_exists( $required, $rank ) ) { return false; }
        return array_key_exists( $actual, $rank ) && $rank[ $actual ] >= $rank[ $required ];
    }

    private static function server_capabilities(): array {
        return array(
            'licenses.verify'        => true,
            'licenses.activate'      => true,
            'licenses.deactivate'    => true,
            'products.read'          => true,
            'products.manage'        => false,
            'webhooks.read'          => false,
            'webhooks.submit'        => false,
            'customers.read'         => false,
            'authority.hybrid'       => true,
            'cooperative_execution'  => true,
            'request_correlation'    => true,
            'self_loop_protection'   => true,
            'signed_responses'       => false,
        );
    }

    private static function capability_permissions(): array {
        return array(
            'licenses.verify'     => 'verify_license',
            'licenses.activate'   => 'manage_licenses',
            'licenses.deactivate' => 'manage_licenses',
            'products.read'       => 'read_products',
            'products.manage'     => 'manage_products',
            'webhooks.read'       => 'read_webhooks',
            'webhooks.submit'     => 'write_webhooks',
            'customers.read'      => 'read_customers',
        );
    }

    private static function current_customer_id(): int {
        $user_id = get_current_user_id();
        if ( $user_id < 1 ) { return 0; }

        // Read-only REST endpoints must not create or mutate customer records.
        // Prefer the explicit WordPress-user mapping, then a verified email match.
        $customers = new CustomerService();
        $customer = $customers->find_by_wp_user( $user_id );
        if ( is_array( $customer ) ) {
            return absint( $customer['id'] ?? 0 );
        }

        $user = get_userdata( $user_id );
        if ( ! $user || ! is_email( (string) $user->user_email ) ) { return 0; }
        $customer = $customers->find_by_email( (string) $user->user_email );
        return is_array( $customer ) ? absint( $customer['id'] ?? 0 ) : 0;
    }

    private static function error( \WP_Error $error ): WP_REST_Response {
        $data = $error->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
        return new WP_REST_Response(
            array(
                'success' => false,
                'data'    => array( 'valid'=>false, 'reason'=>$error->get_error_message() ),
                'error'   => array( 'code'=>$error->get_error_code(), 'message'=>$error->get_error_message() ),
            ),
            $status
        );
    }
}
