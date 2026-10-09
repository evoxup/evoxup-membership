<?php
namespace EvoMembers\Integrations\WooCommerce;

use EvoMembers\Services\CustomerService;
use EvoMembers\Services\GeneratorProfileService;
use EvoMembers\Services\LicenseService;
use EvoMembers\Services\LicenseTypeService;
use EvoMembers\Services\Logger;
use EvoMembers\Services\MembershipService;
use EvoMembers\Services\OrderService;
use EvoMembers\Services\PlanService;
use EvoMembers\Services\ProductService;
use EvoMembers\Providers\LicenseProviderRegistry;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Native WooCommerce licensing bridge.
 *
 * WooCommerce products remain WooCommerce products. EVO only registers itself
 * as one optional license provider in the normal WooCommerce product editor.
 * Third-party license plugins can add providers through the provider filter.
 */
final class ProductLicensing {
    private const META_PROVIDER     = '_evomembers_license_provider';
    private const META_TYPE         = '_evomembers_license_type_id';
    private const META_VERIFY       = '_evomembers_verification_mode';
    private const META_ACTIVATIONS  = '_evomembers_max_activations';
    private const META_DURATION     = '_evomembers_license_duration_days';
    private const META_GENERATOR    = '_evomembers_generator_profile';
    private const META_EVO_PRODUCT  = '_evomembers_evo_product_id';
    private const META_PLAN         = '_evomembers_membership_plan_id';

    public function boot(): void {
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tab' ), 40 );
        add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_fields' ) );

        // Run before the standard WooCommerce customer email callbacks so any
        // generated EVO key is already present as public line-item meta.
        add_action( 'woocommerce_payment_complete', array( $this, 'issue_for_order' ), 5 );
        add_action( 'woocommerce_order_status_processing', array( $this, 'issue_for_order' ), 5 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'issue_for_order' ), 5 );
        add_action( 'woocommerce_new_order', array( $this, 'sync_order' ), 20 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order' ), 20 );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_script' ) );
    }

    public function product_data_tab( array $tabs ): array {
        $tabs['evoxup_membership'] = array(
            'label'    => __( 'EVO Licensing', 'evoxup-membership' ),
            'target'   => 'evomembers_product_data',
            'class'    => array( 'show_if_simple', 'show_if_variable', 'show_if_external', 'show_if_downloadable' ),
            'priority' => 65,
        );
        return $tabs;
    }

    public function render_panel(): void {
        echo '<div id="evomembers_product_data" class="panel woocommerce_options_panel hidden">';
        echo '<div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Evoxup Membership', 'evoxup-membership' ) . '</strong><br><span class="description">' . esc_html__( 'Use EVO as the license source, link this WooCommerce product to an EVO Product and/or grant a Membership Plan after payment.', 'evoxup-membership' ) . '</span></p></div>';
        $this->render_fields();
        echo '</div>';
    }

    public function render_fields(): void {
        if ( ! function_exists( 'woocommerce_wp_select' ) || ! function_exists( 'woocommerce_wp_text_input' ) ) {
            return;
        }

        global $post;
        $product_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
        $providers  = self::providers( $product_id );
        $settings   = self::settings_for_product( $product_id );

        echo '<div class="options_group">';

        $evo_products = array( 0 => __( 'No EVO Product link', 'evoxup-membership' ) );
        foreach ( ( new ProductService() )->all( 500, true ) as $evo_product ) {
            $evo_products[ (int) $evo_product['id'] ] = (string) $evo_product['name'] . ' (' . (string) $evo_product['code'] . ')';
        }
        woocommerce_wp_select(
            array(
                'id'          => self::META_EVO_PRODUCT,
                'label'       => __( 'Linked EVO Product', 'evoxup-membership' ),
                'description' => __( 'Maps this WooCommerce product to the central EVO Product without copying or replacing the WooCommerce product.', 'evoxup-membership' ),
                'desc_tip'    => true,
                'options'     => $evo_products,
                'value'       => $settings['evo_product_id'],
            )
        );

        $plans = array( 0 => __( 'No membership plan', 'evoxup-membership' ) );
        foreach ( ( new PlanService() )->all() as $plan ) {
            if ( 'archived' === (string) ( $plan['status'] ?? '' ) ) { continue; }
            $plans[ (int) $plan['id'] ] = (string) $plan['name'] . ' [' . strtoupper( (string) ( $plan['tier'] ?? 'custom' ) ) . ']';
        }
        woocommerce_wp_select(
            array(
                'id'          => self::META_PLAN,
                'label'       => __( 'Membership Plan', 'evoxup-membership' ),
                'description' => __( 'When the order is paid/processing, EVO grants this plan to the customer. Licensing remains independently configurable below.', 'evoxup-membership' ),
                'desc_tip'    => true,
                'options'     => $plans,
                'value'       => $settings['plan_id'],
            )
        );

        woocommerce_wp_select(
            array(
                'id'          => self::META_PROVIDER,
                'label'       => __( 'License provider', 'evoxup-membership' ),
                'description' => __( 'Product Manager is the canonical license policy. Saving here writes the same policy to the discovered W-product; WooCommerce meta remains a compatibility fallback.', 'evoxup-membership' ),
                'desc_tip'    => true,
                'options'     => $providers,
                'value'       => $settings['provider'],
            )
        );

        $types = array( 0 => __( 'Use EVO defaults', 'evoxup-membership' ) );
        foreach ( ( new LicenseTypeService() )->all() as $type ) {
            if ( 'active' !== (string) ( $type['status'] ?? 'active' ) ) {
                continue;
            }
            $types[ (int) $type['id'] ] = (string) $type['name'];
        }
        woocommerce_wp_select(
            array(
                'id'            => self::META_TYPE,
                'label'         => __( 'EVO license type', 'evoxup-membership' ),
                'options'       => $types,
                'value'         => $settings['license_type_id'],
                'wrapper_class' => 'evoxup-evo-license-field',
            )
        );

        woocommerce_wp_select(
            array(
                'id'            => self::META_VERIFY,
                'label'         => __( 'Verification policy', 'evoxup-membership' ),
                'options'       => array(
                    'portable'  => __( 'Portable / key only', 'evoxup-membership' ),
                    'site'      => __( 'Exact site URL', 'evoxup-membership' ),
                    'domain'    => __( 'Domain', 'evoxup-membership' ),
                    'server_ip' => __( 'Server IP / CIDR', 'evoxup-membership' ),
                    'domain_ip' => __( 'Domain + server IP', 'evoxup-membership' ),
                ),
                'value'         => $settings['verification_mode'],
                'wrapper_class' => 'evoxup-evo-license-field',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id'                => self::META_ACTIVATIONS,
                'label'             => __( 'License keys / sites', 'evoxup-membership' ),
                'description'       => __( 'EVO issues one independent key per site/seat. Each key can activate one site.', 'evoxup-membership' ),
                'desc_tip'          => true,
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
                'value'             => (string) $settings['max_activations'],
                'wrapper_class'     => 'evoxup-evo-license-field',
            )
        );
        woocommerce_wp_text_input(
            array(
                'id'                => self::META_DURATION,
                'label'             => __( 'License duration (days)', 'evoxup-membership' ),
                'description'       => __( '0 = lifetime.', 'evoxup-membership' ),
                'desc_tip'          => true,
                'type'              => 'number',
                'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
                'value'             => (string) $settings['duration_days'],
                'wrapper_class'     => 'evoxup-evo-license-field',
            )
        );

        $generators = array( '' => __( 'Default EVO generator', 'evoxup-membership' ) );
        foreach ( ( new GeneratorProfileService() )->active_options() as $id => $name ) {
            $generators[ $id ] = $name;
        }
        woocommerce_wp_select(
            array(
                'id'            => self::META_GENERATOR,
                'label'         => __( 'Key generator', 'evoxup-membership' ),
                'options'       => $generators,
                'value'         => $settings['generator_profile'],
                'wrapper_class' => 'evoxup-evo-license-field',
            )
        );
        echo '</div>';
    }

    public function save_fields( $product ): void {
        if ( ! current_user_can( 'edit_products' ) ) { return; }
        $nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['woocommerce_meta_nonce'] ) ) : '';
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) { return; }
        if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
            return;
        }

        $product_id = method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0;
        $providers  = self::providers( $product_id );
        $provider   = isset( $_POST[ self::META_PROVIDER ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_PROVIDER ] ) ) : 'none'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce product save verifies its own nonce.
        if ( ! isset( $providers[ $provider ] ) ) {
            $provider = 'none';
        }

        $verify = isset( $_POST[ self::META_VERIFY ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_VERIFY ] ) ) : 'portable'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! in_array( $verify, array( 'portable', 'site', 'domain', 'server_ip', 'domain_ip' ), true ) ) {
            $verify = 'portable';
        }

        $license_type_id = absint( wp_unslash( $_POST[ self::META_TYPE ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $license_seats   = max( 1, absint( wp_unslash( $_POST[ self::META_ACTIVATIONS ] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $duration_days   = absint( wp_unslash( $_POST[ self::META_DURATION ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $generator       = sanitize_key( wp_unslash( (string) ( $_POST[ self::META_GENERATOR ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        // Keep the WooCommerce meta for backwards compatibility and for stores
        // where EVO discovery has not run yet. Product Manager remains the
        // canonical licensing source whenever the Woo product has an EVO source
        // reference (W...).
        $product->update_meta_data( self::META_PROVIDER, $provider );
        $product->update_meta_data( self::META_EVO_PRODUCT, absint( wp_unslash( $_POST[ self::META_EVO_PRODUCT ] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product->update_meta_data( self::META_PLAN, absint( wp_unslash( $_POST[ self::META_PLAN ] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product->update_meta_data( self::META_TYPE, $license_type_id );
        $product->update_meta_data( self::META_VERIFY, $verify );
        $product->update_meta_data( self::META_ACTIVATIONS, $license_seats );
        $product->update_meta_data( self::META_DURATION, $duration_days );
        $product->update_meta_data( self::META_GENERATOR, $generator );

        $central_product_id = self::ensure_central_woocommerce_product( $product_id, $product );
        if ( $central_product_id > 0 ) {
            ( new ProductService() )->update(
                $central_product_id,
                array(
                    'requires_license'      => 'none' !== $provider ? 1 : 0,
                    'license_provider'      => $provider,
                    'license_type_id'       => $license_type_id,
                    'verification_mode'     => $verify,
                    'license_seats'         => $license_seats,
                    'license_duration_days' => $duration_days,
                )
            );
        }

        \EvoMembers\Core\Hooks::do_action( 'woocommerce_license_provider_saved', $product_id, $provider, $product );
    }

    public function sync_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) || $order_id < 1 ) { return; }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }
        $result = ( new OrderService() )->upsert_woocommerce_order( $order );
        if ( is_wp_error( $result ) ) {
            Logger::write( 'woocommerce_evo_order_sync_failed', array( 'order_id'=>$order_id, 'error'=>$result->get_error_message() ), 'warning' );
        }
    }

    public function issue_for_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) { return; }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }

        $ledger_result = ( new OrderService() )->upsert_woocommerce_order( $order );
        $evo_order_id  = is_wp_error( $ledger_result ) ? 0 : (int) $ledger_result;

        // Idempotency is checked per order item below. Do not return early at
        // order level: a previous partial run may have licensed only some items.

        $licenses    = new LicenseService();
        $customers   = new CustomerService();
        $memberships = new MembershipService();
        $products    = new ProductService();

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) { continue; }

            $wc_product_id = absint( method_exists( $item, 'get_variation_id' ) ? $item->get_variation_id() : 0 );
            if ( $wc_product_id < 1 ) { $wc_product_id = absint( $item->get_product_id() ); }
            $settings = self::settings_for_product( $wc_product_id );
            $plan_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $settings['plan_ids'] ?? array() ) ) ) ) );
            if ( ! $plan_ids && (int) ( $settings['plan_id'] ?? 0 ) > 0 ) {
                $plan_ids[] = (int) $settings['plan_id'];
            }
            $needs_customer = 'none' !== (string) $settings['provider'] || ! empty( $plan_ids );
            if ( ! $needs_customer ) { continue; }

            $email = sanitize_email( (string) $order->get_billing_email() );
            if ( ! is_email( $email ) ) {
                Logger::write( 'woocommerce_evo_fulfillment_skipped', array( 'order_id'=>$order_id, 'item_id'=>$item_id, 'reason'=>'customer_email_missing' ), 'warning' );
                continue;
            }
            $customer_id = $customers->find_or_create(
                array(
                    'email'=>$email,
                    'first_name'=>(string) $order->get_billing_first_name(),
                    'last_name'=>(string) $order->get_billing_last_name(),
                    'display_name'=>trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
                    'company'=>(string) $order->get_billing_company(),
                    'address_1'=>(string) $order->get_billing_address_1(),
                    'address_2'=>(string) $order->get_billing_address_2(),
                    'city'=>(string) $order->get_billing_city(),
                    'state_region'=>(string) $order->get_billing_state(),
                    'postal_code'=>(string) $order->get_billing_postcode(),
                    'country_code'=>(string) $order->get_billing_country(),
                    'phone'=>(string) $order->get_billing_phone(),
                    'purchased_at'=>current_time( 'mysql', true ),
                ),
                true
            );
            if ( $customer_id < 1 ) { continue; }

            $membership_id  = 0;
            $membership_ids = array();
            foreach ( $plan_ids as $plan_id ) {
                $membership = $memberships->grant(
                    $customer_id,
                    $plan_id,
                    array(
                        'source'=>'woocommerce',
                        'source_order_id'=>$evo_order_id ?: null,
                        'external_reference'=>'woocommerce:' . $order_id . ':' . absint( $item_id ) . ':plan:' . $plan_id,
                        'metadata'=>array( 'woocommerce_order_id'=>$order_id, 'woocommerce_item_id'=>absint( $item_id ), 'woocommerce_product_id'=>$wc_product_id ),
                    )
                );
                if ( is_wp_error( $membership ) ) { continue; }
                $granted_id = (int) ( $membership['id'] ?? 0 );
                if ( $granted_id > 0 ) {
                    $membership_ids[] = $granted_id;
                    if ( $membership_id < 1 ) { $membership_id = $granted_id; }
                }
            }

            if ( 'none' === $settings['provider'] || 'external' === $settings['provider'] ) { continue; }
            if ( $licenses->ids_for_source_item( 'woocommerce', $order_id, absint( $item_id ) ) ) { continue; }

            if ( 'evo' !== $settings['provider'] ) {
                $provider = LicenseProviderRegistry::get( (string) $settings['provider'] );
                $context = array(
                    'provider'=>(string) $settings['provider'], 'woocommerce_order'=>$order, 'woocommerce_order_id'=>$order_id,
                    'woocommerce_item'=>$item, 'woocommerce_item_id'=>absint( $item_id ), 'woocommerce_product_id'=>$wc_product_id,
                    'customer_id'=>$customer_id, 'membership_id'=>$membership_id, 'settings'=>$settings,
                );
                $external = $provider && $provider->supports( 'issue' ) ? $provider->issue( $context ) : \EvoMembers\Core\Hooks::apply_filters( 'external_license_issue', null, (string) $settings['provider'], $context );
                if ( is_wp_error( $external ) ) { Logger::write( 'woocommerce_external_license_failed', array( 'order_id'=>$order_id, 'item_id'=>$item_id, 'provider'=>$settings['provider'], 'error'=>$external->get_error_message() ), 'error', $customer_id ); continue; }
                if ( ! is_array( $external ) ) { continue; }
                $external_items = ! empty( $external['licenses'] ) && is_array( $external['licenses'] ) ? $external['licenses'] : ( ! empty( $external['license_keys'] ) && is_array( $external['license_keys'] ) ? array_map( static fn( $key ): array => array( 'license_key'=>$key ), $external['license_keys'] ) : ( ! empty( $external['license_key'] ) ? array( $external ) : array() ) );
                $external_ids = array();
                foreach ( $external_items as $index=>$external_item ) {
                    if ( ! is_array( $external_item ) ) { $external_item = array( 'license_key'=>(string)$external_item ); }
                    $key = trim( (string) ( $external_item['license_key'] ?? $external_item['key'] ?? '' ) ); if ( '' === $key ) { continue; }
                    $registered = $licenses->register_external( array( 'customer_id'=>$customer_id, 'product_id'=>(int)$settings['evo_product_id'], 'membership_id'=>$membership_id, 'provider'=>(string)$settings['provider'], 'license_key'=>$key, 'status'=>(string)($external_item['status']??'active'), 'seat_number'=>$index+1, 'seat_total'=>count($external_items), 'source'=>'woocommerce', 'source_order_id'=>$order_id, 'source_order_item_id'=>absint($item_id), 'metadata'=>array('woocommerce_product_id'=>$wc_product_id) ) );
                    if ( is_wp_error( $registered ) ) { continue; }
                    $external_ids[]=(int)$registered['id'];
                    $item->add_meta_data( __( 'License Key', 'evoxup-membership' ) . ' #' . ( $index + 1 ), $key, false );
                }
                if ( $external_ids ) { \EvoMembers\Core\MembershipIdentifiers::add_item_meta( $item, '_evomembers_membership_license_ids', wp_json_encode( $external_ids ), true ); \EvoMembers\Core\MembershipIdentifiers::add_item_meta( $item, '_evomembers_membership_license_id', $external_ids[0], true ); $item->save_meta_data(); }
                continue;
            }

            $type = ! empty( $settings['license_type_id'] ) ? ( new LicenseTypeService() )->get( (int) $settings['license_type_id'] ) : null;
            if ( is_array( $type ) ) {
                if ( empty( $settings['verification_mode'] ) ) { $settings['verification_mode'] = (string) ( $type['verification_mode'] ?? 'portable' ); }
                if ( 0 === (int) $settings['duration_days'] && ! empty( $type['duration_days'] ) ) { $settings['duration_days'] = absint( $type['duration_days'] ); }
            }

            $wc_product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
            $name = $wc_product && method_exists( $wc_product, 'get_name' ) ? (string) $wc_product->get_name() : (string) $item->get_name();
            $evo_product = (int) $settings['evo_product_id'] > 0 ? $products->get( (int) $settings['evo_product_id'] ) : null;
            $generator = '' !== $settings['generator_profile'] ? ( new GeneratorProfileService() )->get( $settings['generator_profile'] ) : null;
            $seat_count = max( 1, absint( $settings['max_activations'] ) ) * max( 1, absint( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1 ) );

            $issued = $licenses->issue_many(
                array(
                    'customer_id'=>$customer_id,
                    'product_id'=>(int) $settings['evo_product_id'],
                    'membership_id'=>$membership_id,
                    'order_id'=>$evo_order_id,
                    'external_product_name'=>$evo_product ? (string) $evo_product['name'] : $name,
                    'external_product_code'=>$evo_product ? (string) $evo_product['code'] : 'wc-' . $wc_product_id,
                    'source'=>'woocommerce',
                    'source_order_id'=>$order_id,
                    'source_order_item_id'=>absint( $item_id ),
                    'license_type_id'=>absint( $settings['license_type_id'] ),
                    'verification_mode'=>$settings['verification_mode'],
                    'allowed_site_url'=>esc_url_raw( (string) ( $settings['allowed_site_url'] ?? '' ) ),
                    'allowed_domain'=>sanitize_text_field( (string) ( $settings['allowed_domain'] ?? '' ) ),
                    'allowed_ip'=>sanitize_text_field( (string) ( $settings['allowed_ip'] ?? '' ) ),
                    'max_activations'=>1,
                    'duration_days'=>absint( $settings['duration_days'] ),
                    'generator_profile'=>$settings['generator_profile'],
                    'generator'=>is_array( $generator ) ? $generator : array(),
                    'metadata'=>array( 'woocommerce_product_id'=>$wc_product_id, 'evo_order_id'=>$evo_order_id, 'plan_ids'=>$plan_ids, 'membership_ids'=>$membership_ids ),
                ),
                $seat_count
            );
            if ( is_wp_error( $issued ) ) {
                Logger::write( 'woocommerce_evo_license_failed', array( 'order_id'=>$order_id, 'item_id'=>$item_id, 'error'=>$issued->get_error_message() ), 'error', $customer_id );
                continue;
            }

            $license_ids = array();
            foreach ( $issued as $seat ) {
                $license_ids[] = (int) $seat['id'];
                $item->add_meta_data( __( 'EVO License Key', 'evoxup-membership' ) . ' #' . (int) $seat['seat_number'], (string) $seat['license_key'], false );
                \EvoMembers\Core\Hooks::do_action( 'woocommerce_evo_license_issued', (int) $seat['id'], $order_id, absint( $item_id ), $wc_product_id, $customer_id );
            }
            \EvoMembers\Core\MembershipIdentifiers::add_item_meta( $item, '_evomembers_membership_license_ids', wp_json_encode( $license_ids ), true );
            if ( $license_ids ) { \EvoMembers\Core\MembershipIdentifiers::add_item_meta( $item, '_evomembers_membership_license_id', (int) $license_ids[0], true ); }
            $item->save_meta_data();
        }
    }

    public static function provider_for_product( int $product_id ): string {
        $product_id = self::licensing_product_id( $product_id );
        if ( $product_id < 1 ) {
            return 'none';
        }

        $central = self::central_product_for_woocommerce( $product_id );
        if ( is_array( $central ) ) {
            if ( empty( $central['requires_license'] ) ) {
                return 'none';
            }
            $provider = sanitize_key( (string) ( $central['license_provider'] ?? 'evo' ) );
            return '' !== $provider && 'none' !== $provider ? $provider : 'evo';
        }

        $provider  = sanitize_key( (string) get_post_meta( $product_id, self::META_PROVIDER, true ) );
        $providers = self::providers( $product_id );
        return isset( $providers[ $provider ] ) ? $provider : 'none';
    }

    /**
     * Resolve licensing from the central Product Manager first. WooCommerce
     * post meta is retained only as a backwards-compatible fallback.
     */
    public static function settings_for_product( int $product_id ): array {
        $product_id = self::licensing_product_id( $product_id );
        $central    = self::central_product_for_woocommerce( $product_id );

        if ( is_array( $central ) ) {
            $provider = ! empty( $central['requires_license'] )
                ? sanitize_key( (string) ( $central['license_provider'] ?? 'evo' ) )
                : 'none';
            if ( '' === $provider || ( ! empty( $central['requires_license'] ) && 'none' === $provider ) ) {
                $provider = 'evo';
            }

            $verify = sanitize_key( (string) ( $central['verification_mode'] ?? 'portable' ) );
            if ( ! in_array( $verify, array( 'portable', 'site', 'domain', 'server_ip', 'domain_ip' ), true ) ) {
                $verify = 'portable';
            }

            $plan_ids = array();
            foreach ( ( new PlanService() )->plans_for_product( (int) $central['id'] ) as $plan ) {
                if ( 'active' !== (string) ( $plan['status'] ?? '' ) ) { continue; }
                $plan_id = absint( $plan['id'] ?? 0 );
                if ( $plan_id > 0 ) { $plan_ids[] = $plan_id; }
            }
            $plan_ids = array_values( array_unique( $plan_ids ) );
            $legacy_plan_id = absint( get_post_meta( $product_id, self::META_PLAN, true ) );
            if ( ! $plan_ids && $legacy_plan_id > 0 ) { $plan_ids[] = $legacy_plan_id; }

            $seats = max( 1, absint( $central['license_seats'] ?? 1 ) );
            return array(
                'provider'          => $provider,
                'evo_product_id'    => absint( $central['id'] ),
                'plan_id'           => absint( $plan_ids[0] ?? 0 ),
                'plan_ids'          => $plan_ids,
                'requires_license'  => 'none' !== $provider,
                'license_type_id'   => absint( $central['license_type_id'] ?? 0 ),
                'verification_mode' => $verify,
                'license_seats'     => $seats,
                'max_activations'   => $seats,
                'duration_days'     => absint( $central['license_duration_days'] ?? 0 ),
                'generator_profile' => sanitize_key( (string) get_post_meta( $product_id, self::META_GENERATOR, true ) ),
                'allowed_site_url'  => esc_url_raw( (string) ( $central['allowed_site_url'] ?? '' ) ),
                'allowed_domain'    => sanitize_text_field( (string) ( $central['allowed_domain'] ?? '' ) ),
                'allowed_ip'        => sanitize_text_field( (string) ( $central['allowed_ip'] ?? '' ) ),
                'settings_source'   => 'product_manager',
            );
        }

        $provider = self::provider_for_product( $product_id );
        $verify   = sanitize_key( (string) get_post_meta( $product_id, self::META_VERIFY, true ) );
        if ( ! in_array( $verify, array( 'portable', 'site', 'domain', 'server_ip', 'domain_ip' ), true ) ) {
            $verify = 'portable';
        }
        $plan_id = absint( get_post_meta( $product_id, self::META_PLAN, true ) );
        $seats   = max( 1, absint( get_post_meta( $product_id, self::META_ACTIVATIONS, true ) ?: 1 ) );
        return array(
            'provider'          => $provider,
            'evo_product_id'    => absint( get_post_meta( $product_id, self::META_EVO_PRODUCT, true ) ),
            'plan_id'           => $plan_id,
            'plan_ids'          => $plan_id > 0 ? array( $plan_id ) : array(),
            'requires_license'  => 'none' !== $provider,
            'license_type_id'   => absint( get_post_meta( $product_id, self::META_TYPE, true ) ),
            'verification_mode' => $verify,
            'license_seats'     => $seats,
            'max_activations'   => $seats,
            'duration_days'     => absint( get_post_meta( $product_id, self::META_DURATION, true ) ),
            'generator_profile' => sanitize_key( (string) get_post_meta( $product_id, self::META_GENERATOR, true ) ),
            'allowed_site_url'  => '',
            'allowed_domain'    => '',
            'allowed_ip'        => '',
            'settings_source'   => 'woocommerce_meta',
        );
    }

    private static function central_product_for_woocommerce( int $product_id ): ?array {
        $product_id = self::licensing_product_id( $product_id );
        if ( $product_id < 1 ) { return null; }
        return ( new ProductService() )->get_by_source( ProductService::SOURCE_WOOCOMMERCE, (string) $product_id );
    }

    private static function ensure_central_woocommerce_product( int $product_id, object $product ): int {
        $product_id = self::licensing_product_id( $product_id );
        if ( $product_id < 1 ) { return 0; }

        $service = new ProductService();
        $central = $service->get_by_source( ProductService::SOURCE_WOOCOMMERCE, (string) $product_id );
        if ( is_array( $central ) ) { return absint( $central['id'] ?? 0 ); }

        $name = method_exists( $product, 'get_name' ) ? sanitize_text_field( (string) $product->get_name() ) : '';
        if ( '' === $name && function_exists( 'wc_get_product' ) ) {
            $native = wc_get_product( $product_id );
            if ( $native && method_exists( $native, 'get_name' ) ) { $name = sanitize_text_field( (string) $native->get_name() ); }
        }
        if ( '' === $name ) { $name = 'WooCommerce #' . $product_id; }

        return $service->create(
            array(
                'source'             => ProductService::SOURCE_WOOCOMMERCE,
                'external_source_id' => (string) $product_id,
                'source_name'        => $name,
                'name'               => $name,
                'status'             => 'active',
            )
        );
    }

    private static function providers( int $product_id = 0 ): array {
        $providers = array(
            'none'     => __( 'No license', 'evoxup-membership' ),
            'evo'      => __( 'EVO — Evoxup Membership', 'evoxup-membership' ),
            'external' => __( 'Other license plugin / WooCommerce hooks', 'evoxup-membership' ),
        );
        foreach ( LicenseProviderRegistry::labels() as $slug => $label ) {
            $providers[ $slug ] = $label;
        }
        $providers = \EvoMembers\Core\Hooks::apply_filters( 'woocommerce_license_providers', $providers, $product_id );
        return is_array( $providers ) ? $providers : array( 'none' => __( 'No license', 'evoxup-membership' ), 'evo' => __( 'EVO License', 'evoxup-membership' ) );
    }

    private static function licensing_product_id( int $product_id ): int {
        $product_id = absint( $product_id );
        if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
            return $product_id;
        }
        $product = wc_get_product( $product_id );
        if ( $product && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
            $own = (string) get_post_meta( $product_id, self::META_PROVIDER, true );
            if ( '' === $own && method_exists( $product, 'get_parent_id' ) ) {
                return absint( $product->get_parent_id() );
            }
        }
        return $product_id;
    }

    public function enqueue_admin_script(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== (string) $screen->id ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
        $selector = '#' . self::META_PROVIDER;
        $script = '(function($){function evoxupToggleWooLicense(){var provider=$("' . esc_js( $selector ) . '").val();$(".evoxup-evo-license-field").toggle(provider==="evo");}$(document).on("change","' . esc_js( $selector ) . '",evoxupToggleWooLicense);$(evoxupToggleWooLicense);})(jQuery);';
        wp_add_inline_script( 'jquery', $script, 'after' );
    }

}
