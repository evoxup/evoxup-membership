<?php
namespace EvoMembers\Frontend;

use EvoMembers\Services\CustomerService;
use EvoMembers\Services\LicenseService;
use EvoMembers\Services\MembershipService;
use EvoMembers\Services\MessageService;
use EvoMembers\Services\OrderService;
use EvoMembers\Services\PlanService;
use EvoMembers\Services\ProductService;
use EvoMembers\Services\PurchaseLinkService;

defined( 'ABSPATH' ) || exit;

final class MembershipHub {
    public function boot(): void {
        add_shortcode( 'evomembers_center', array( $this, 'center_shortcode' ) );
        add_shortcode( 'evoxup_my_membership', array( $this, 'membership_shortcode' ) );
        add_shortcode( 'evoxup_product', array( $this, 'product_shortcode' ) );
        add_shortcode( 'evoxup_products', array( $this, 'products_shortcode' ) );
        add_shortcode( 'evoxup_plans', array( $this, 'plans_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    public function register_assets(): void {
        wp_register_style( 'evomembers-membership-frontend', EVOMEMBERS_URL . 'assets/frontend.css', array(), EVOMEMBERS_VERSION );
        wp_register_script( 'evomembers-membership-frontend', EVOMEMBERS_URL . 'assets/frontend.js', array(), EVOMEMBERS_VERSION, true );
    }

    private function enqueue_assets(): array {
        $settings = CenterSettings::get();
        wp_enqueue_style( 'evomembers-membership-frontend' );
        wp_enqueue_script( 'evomembers-membership-frontend' );
        wp_add_inline_style(
            'evomembers-membership-frontend',
            '.evo-membership-root{--evo-blue:' . esc_attr( (string) $settings['blue'] ) . ';--evo-crimson:' . esc_attr( (string) $settings['crimson'] ) . ';}'
        );
        return $settings;
    }

    public function center_shortcode( array $atts = array() ): string {
        unset( $atts );
        $settings = $this->enqueue_assets();
        $catalog_visible = 'public' === (string) $settings['catalog_visibility'] || is_user_logged_in();

        ob_start();
        ?>
        <div class="evo-membership-root evo-layout-<?php echo esc_attr( sanitize_html_class( (string) $settings['layout'] ) ); ?>">
            <nav class="evo-center-nav" aria-label="<?php echo esc_attr__( 'Membership center navigation', 'evoxup-membership' ); ?>">
                <?php if ( $catalog_visible && ! empty( $settings['show_products'] ) ) : ?><a href="#evo-store-products"><?php echo esc_html( (string) $settings['products_title'] ); ?></a><?php endif; ?>
                <?php if ( $catalog_visible && ! empty( $settings['show_plans'] ) ) : ?><a href="#evo-store-plans"><?php echo esc_html( (string) $settings['plans_title'] ); ?></a><?php endif; ?>
                <?php if ( ! empty( $settings['show_account'] ) ) : ?><a href="#evo-member-account"><?php echo esc_html( (string) $settings['account_title'] ); ?></a><?php endif; ?>
            </nav>
            <?php if ( 'account_first' === (string) $settings['layout'] && ! empty( $settings['show_account'] ) ) : ?>
                <?php echo wp_kses_post( $this->membership_shortcode() ); ?>
            <?php endif; ?>
            <?php if ( $catalog_visible && ( ! empty( $settings['show_products'] ) || ! empty( $settings['show_plans'] ) ) ) : ?>
            <section class="evo-account evo-membership-center">
                <header class="evo-account-hero evo-center-hero">
                    <img src="<?php echo esc_url( EVOMEMBERS_URL . 'assets/branding/evoxup-membership-icon.png' ); ?>" alt="" class="evo-account-logo">
                    <div><span class="evo-account-kicker">EVOXUP MEMBERSHIP</span><h1><?php echo esc_html( (string) $settings['hero_title'] ); ?></h1><p><?php echo esc_html( (string) $settings['hero_text'] ); ?></p></div>
                </header>
                <?php if ( ! empty( $settings['show_products'] ) ) : ?>
                    <div class="evo-account-section" id="evo-store-products"><h2><?php echo esc_html( (string) $settings['products_title'] ); ?></h2><?php echo wp_kses_post( $this->products_shortcode() ); ?></div>
                <?php endif; ?>
                <?php if ( ! empty( $settings['show_plans'] ) ) : ?>
                    <div class="evo-account-section" id="evo-store-plans"><h2><?php echo esc_html( (string) $settings['plans_title'] ); ?></h2><?php echo wp_kses_post( $this->plans_shortcode() ); ?></div>
                <?php endif; ?>
            </section>
            <?php elseif ( ! $catalog_visible ) : ?>
                <div class="evo-account evo-account-login"><p><?php esc_html_e( 'Sign in to view products and membership plans.', 'evoxup-membership' ); ?></p></div>
            <?php endif; ?>
            <?php if ( 'account_first' !== (string) $settings['layout'] && ! empty( $settings['show_account'] ) ) : ?>
                <?php echo wp_kses_post( $this->membership_shortcode() ); ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function membership_shortcode( array $atts = array() ): string {
        unset( $atts );
        $settings = $this->enqueue_assets();
        if ( ! is_user_logged_in() ) {
            return '<section id="evo-member-account" class="evo-account evo-account-login"><h2>' . esc_html( (string) $settings['account_title'] ) . '</h2><p>' . esc_html__( 'Please sign in to view your membership.', 'evoxup-membership' ) . '</p>' . wp_login_form( array( 'echo'=>false, 'redirect'=>( new PurchaseLinkService() )->membership_center_url() ) ) . '</section>';
        }

        $customer_id = $this->current_customer_id();
        if ( $customer_id < 1 ) {
            return '<section id="evo-member-account" class="evo-account"><p>' . esc_html__( 'No EVO customer profile is linked to this account yet.', 'evoxup-membership' ) . '</p></section>';
        }

        $customer       = ( new CustomerService() )->get( $customer_id );
        $memberships    = ( new MembershipService() )->for_customer( $customer_id );
        $license_service= new LicenseService();
        $licenses       = $license_service->for_customer( $customer_id, true );
        $activations    = $license_service->activations_for_customer( $customer_id );
        $orders         = ( new OrderService() )->for_customer( $customer_id );
        $messages       = ( new MessageService() )->for_customer( $customer_id, 100 );
        $purchase_links = new PurchaseLinkService();
        $user           = wp_get_current_user();
        $profile_url    = get_edit_profile_url( get_current_user_id() );
        $password_url   = wp_lostpassword_url( $purchase_links->membership_center_url() );
        $logout_url     = wp_logout_url( $purchase_links->membership_center_url() );
        $wc_account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

        $tabs = array();
        if ( ! empty( $settings['show_memberships'] ) ) { $tabs['evo-memberships'] = __( 'Memberships', 'evoxup-membership' ); }
        if ( ! empty( $settings['show_licenses'] ) ) { $tabs['evo-licenses'] = __( 'Licenses', 'evoxup-membership' ); }
        if ( ! empty( $settings['show_activations'] ) ) { $tabs['evo-activations'] = __( 'Activations', 'evoxup-membership' ); }
        if ( ! empty( $settings['show_orders'] ) ) { $tabs['evo-orders'] = __( 'Orders', 'evoxup-membership' ); }
        if ( ! empty( $settings['show_downloads'] ) ) { $tabs['evo-downloads'] = __( 'Downloads', 'evoxup-membership' ); }
        if ( ! empty( $settings['show_messages'] ) ) { $tabs['evo-messages'] = __( 'Messages', 'evoxup-membership' ); }

        ob_start();
        ?>
        <section id="evo-member-account" class="evo-account">
            <header class="evo-account-hero">
                <img src="<?php echo esc_url( EVOMEMBERS_URL . 'assets/branding/evoxup-membership-icon.png' ); ?>" alt="" class="evo-account-logo">
                <div class="evo-account-hero-copy"><span class="evo-account-kicker">EVOXUP</span><h2><?php echo esc_html( (string) $settings['account_title'] ); ?></h2><p><?php esc_html_e( 'Memberships, licenses, downloads and upgrade options in one place.', 'evoxup-membership' ); ?></p></div>
                <div class="evo-member-identity"><strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong><small><?php echo esc_html( (string) ( $customer['email'] ?? $user->user_email ) ); ?></small></div>
            </header>
            <div class="evo-account-actions evo-account-toolbar">
                <?php if ( $profile_url ) : ?><a class="evo-button" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Edit profile', 'evoxup-membership' ); ?></a><?php endif; ?>
                <?php if ( $wc_account_url ) : ?><a class="evo-button" href="<?php echo esc_url( $wc_account_url ); ?>"><?php esc_html_e( 'WooCommerce account', 'evoxup-membership' ); ?></a><?php endif; ?>
                <a class="evo-button" href="<?php echo esc_url( $password_url ); ?>"><?php esc_html_e( 'Password', 'evoxup-membership' ); ?></a>
                <a class="evo-button evo-button-danger" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Sign out', 'evoxup-membership' ); ?></a>
            </div>
            <?php if ( $tabs ) : ?><div class="evo-account-tabs"><?php foreach ( $tabs as $anchor => $label ) : ?><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div><?php endif; ?>

            <?php if ( ! empty( $settings['show_memberships'] ) ) : ?>
            <div id="evo-memberships" class="evo-account-section">
                <h3><?php esc_html_e( 'Memberships', 'evoxup-membership' ); ?></h3>
                <?php if ( ! $memberships ) : ?><p><?php esc_html_e( 'No memberships found.', 'evoxup-membership' ); ?></p><?php endif; ?>
                <div class="evo-account-grid">
                <?php foreach ( $memberships as $membership ) :
                    $ents = json_decode( (string) ( $membership['entitlements_json'] ?? '[]' ), true );
                    $ents = is_array( $ents ) ? $ents : array();
                    $purchase_url = ! empty( $membership['plan_id'] ) ? $purchase_links->plan_url( (int) $membership['plan_id'] ) : '';
                    $upgrade_url = ! empty( $membership['upgrade_plan_id'] ) ? $purchase_links->plan_url( (int) $membership['upgrade_plan_id'] ) : ''; ?>
                    <article class="evo-account-card">
                        <div class="evo-account-card-head"><strong><?php echo esc_html( (string) $membership['plan_name'] ); ?></strong><span class="evo-status evo-status-<?php echo esc_attr( sanitize_html_class( (string) $membership['status'] ) ); ?>"><?php echo esc_html( (string) $membership['status'] ); ?></span></div>
                        <p><b><?php esc_html_e( 'Tier:', 'evoxup-membership' ); ?></b> <?php echo esc_html( strtoupper( str_replace( '_', ' ', (string) ( $membership['tier'] ?? 'custom' ) ) ) ); ?></p>
                        <p><b><?php esc_html_e( 'Expires:', 'evoxup-membership' ); ?></b> <?php echo esc_html( $membership['expires_at'] ?: __( 'Lifetime', 'evoxup-membership' ) ); ?></p>
                        <?php if ( $ents ) : ?><div class="evo-chip-row"><?php foreach ( $ents as $ent ) : ?><span><?php echo esc_html( (string) $ent ); ?></span><?php endforeach; ?></div><?php endif; ?>
                        <div class="evo-account-actions">
                            <?php if ( '' !== $upgrade_url ) : ?><a class="evo-button evo-button-primary" href="<?php echo esc_url( $upgrade_url ); ?>"><?php esc_html_e( 'Upgrade', 'evoxup-membership' ); ?></a><?php endif; ?>
                            <?php if ( '' !== $purchase_url ) : ?><a class="evo-button" href="<?php echo esc_url( $purchase_url ); ?>"><?php esc_html_e( 'Buy / Renew', 'evoxup-membership' ); ?></a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['show_licenses'] ) ) : ?>
            <div id="evo-licenses" class="evo-account-section">
                <h3><?php esc_html_e( 'Licenses', 'evoxup-membership' ); ?></h3>
                <?php if ( ! $licenses ) : ?><p><?php esc_html_e( 'No licenses found.', 'evoxup-membership' ); ?></p><?php endif; ?>
                <div class="evo-account-grid">
                    <?php foreach ( $licenses as $license ) : ?>
                    <article class="evo-account-card evo-license-card">
                        <div class="evo-account-card-head"><strong><?php echo esc_html( $license['product_name'] ?: __( 'EVO License', 'evoxup-membership' ) ); ?></strong><span class="evo-status evo-status-<?php echo esc_attr( sanitize_html_class( (string) $license['status'] ) ); ?>"><?php echo esc_html( (string) $license['status'] ); ?></span></div>
                        <div class="evo-license-key-row"><code class="evo-license-key"><?php echo esc_html( (string) ( $license['license_key'] ?? '' ) ); ?></code><?php if ( ! empty( $license['license_key'] ) ) : ?><button type="button" class="evo-button evo-button-small" data-evo-copy="<?php echo esc_attr( (string) $license['license_key'] ); ?>"><?php esc_html_e( 'Copy', 'evoxup-membership' ); ?></button><?php endif; ?></div>
                        <p><?php
                        /* translators: 1: key/seat number, 2: total keys/seats, 3: active activation count. */
                        printf( esc_html__( 'Key %1$d of %2$d · Activation %3$d/1', 'evoxup-membership' ), (int) ( $license['seat_number'] ?? 1 ), (int) ( $license['seat_total'] ?? 1 ), (int) ( $license['active_activations'] ?? 0 ) );
                        ?></p>
                        <p><b><?php esc_html_e( 'Expires:', 'evoxup-membership' ); ?></b> <?php echo esc_html( $license['expires_at'] ?: __( 'Lifetime', 'evoxup-membership' ) ); ?></p>
                        <p><b><?php esc_html_e( 'Verification:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $license['verification_status'] ?? 'unverified' ) ); ?> · <?php echo esc_html( (string) ( $license['verification_count'] ?? 0 ) ); ?> <?php esc_html_e( 'checks', 'evoxup-membership' ); ?></p>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['show_activations'] ) ) : ?>
            <div id="evo-activations" class="evo-account-section">
                <h3><?php esc_html_e( 'Activations', 'evoxup-membership' ); ?></h3>
                <?php if ( ! $activations ) : ?><p><?php esc_html_e( 'No site activations found.', 'evoxup-membership' ); ?></p><?php endif; ?>
                <div class="evo-account-grid">
                    <?php foreach ( $activations as $activation ) : ?>
                    <article class="evo-account-card">
                        <div class="evo-account-card-head"><strong><?php echo esc_html( $activation['product_name'] ?: __( 'EVO License', 'evoxup-membership' ) ); ?></strong><span class="evo-status evo-status-<?php echo esc_attr( sanitize_html_class( (string) $activation['status'] ) ); ?>"><?php echo esc_html( (string) $activation['status'] ); ?></span></div>
                        <p><b><?php esc_html_e( 'Site:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $activation['site_url'] ?? '—' ) ); ?></p>
                        <p><b><?php esc_html_e( 'Domain:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $activation['domain'] ?? '—' ) ); ?></p>
                        <p><b><?php esc_html_e( 'Last seen:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $activation['last_seen_at'] ?? '—' ) ); ?></p>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['show_orders'] ) ) : ?>
            <div id="evo-orders" class="evo-account-section">
                <h3><?php esc_html_e( 'Orders', 'evoxup-membership' ); ?></h3>
                <?php if ( ! $orders ) : ?><p><?php esc_html_e( 'No orders found.', 'evoxup-membership' ); ?></p><?php endif; ?>
                <div class="evo-account-grid">
                    <?php foreach ( $orders as $order ) : ?>
                    <article class="evo-account-card">
                        <div class="evo-account-card-head"><strong><?php echo esc_html( ! empty( $order['woocommerce_order_id'] ) ? 'WooCommerce #' . (string) $order['woocommerce_order_id'] : ( (string) ( $order['external_order_id'] ?? '' ) ?: 'EVO #' . (string) $order['id'] ) ); ?></strong><span class="evo-status evo-status-<?php echo esc_attr( sanitize_html_class( (string) $order['status'] ) ); ?>"><?php echo esc_html( (string) $order['status'] ); ?></span></div>
                        <p><b><?php esc_html_e( 'Total:', 'evoxup-membership' ); ?></b> <?php echo esc_html( trim( (string) ( $order['currency'] ?? '' ) . ' ' . (string) ( $order['total'] ?? '' ) ) ?: '—' ); ?></p>
                        <p><b><?php esc_html_e( 'Items:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $order['item_count'] ?? 0 ) ); ?></p>
                        <p><b><?php esc_html_e( 'Purchased:', 'evoxup-membership' ); ?></b> <?php echo esc_html( (string) ( $order['purchased_at'] ?? $order['created_at'] ?? '—' ) ); ?></p>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['show_downloads'] ) ) : ?>
            <div id="evo-downloads" class="evo-account-section">
                <h3><?php esc_html_e( 'Downloads', 'evoxup-membership' ); ?></h3>
                <div class="evo-download-list">
                <?php $shown = false; foreach ( $licenses as $license ) : if ( empty( $license['download_url'] ) ) { continue; } $shown = true; ?>
                    <a class="evo-download-row" href="<?php echo esc_url( (string) $license['download_url'] ); ?>"><span><?php echo esc_html( $license['product_name'] ?: $license['product_code'] ); ?></span><b><?php esc_html_e( 'Download', 'evoxup-membership' ); ?></b></a>
                <?php endforeach; if ( ! $shown ) : ?><p><?php esc_html_e( 'No downloads are available for your current licenses.', 'evoxup-membership' ); ?></p><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['show_messages'] ) ) : ?>
            <div id="evo-messages" class="evo-account-section">
                <h3><?php esc_html_e( 'Messages', 'evoxup-membership' ); ?></h3>
                <?php if ( ! $messages ) : ?><p><?php esc_html_e( 'No messages yet.', 'evoxup-membership' ); ?></p><?php endif; ?>
                <div class="evo-message-list">
                    <?php foreach ( $messages as $message ) : ?>
                    <article class="evo-account-card evo-message-card">
                        <div class="evo-account-card-head"><strong><?php echo esc_html( (string) $message['subject'] ); ?></strong><span class="evo-status evo-status-<?php echo esc_attr( sanitize_html_class( (string) $message['status'] ) ); ?>"><?php echo esc_html( (string) $message['status'] ); ?></span></div>
                        <p><?php echo nl2br( esc_html( (string) $message['message'] ) ); ?></p>
                        <small><?php echo esc_html( (string) $message['created_at'] ); ?></small>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function product_shortcode( array $atts = array() ): string {
        $this->enqueue_assets();
        $atts = shortcode_atts( array( 'id'=>0, 'code'=>'' ), $atts, 'evoxup_product' );
        $service = new ProductService();
        $product = absint( $atts['id'] ) > 0 ? $service->get( absint( $atts['id'] ) ) : $service->get_by_code( sanitize_key( (string) $atts['code'] ) );
        if ( ! $product || 'active' !== (string) $product['status'] ) { return ''; }
        return $this->render_product_card( $product, false );
    }

    public function products_shortcode( array $atts = array() ): string {
        unset( $atts );
        $this->enqueue_assets();
        $products = ( new ProductService() )->active();
        if ( ! $products ) { return '<p>' . esc_html__( 'No products are available right now.', 'evoxup-membership' ) . '</p>'; }
        ob_start(); ?><div class="evo-product-grid"><?php foreach ( $products as $product ) { echo wp_kses_post( $this->render_product_card( $product, true ) ); } ?></div><?php
        return (string) ob_get_clean();
    }

    private function render_product_card( array $product, bool $compact ): string {
        $price = null !== $product['sale_price'] ? $product['sale_price'] : $product['price'];
        $image = ! empty( $product['image_id'] ) ? wp_get_attachment_image_url( (int) $product['image_id'], $compact ? 'medium_large' : 'large' ) : '';
        if ( ! $image && ! empty( $product['image_url'] ) ) { $image = esc_url_raw( (string) $product['image_url'] ); }
        if ( ! $image ) { $image = EVOMEMBERS_URL . 'assets/branding/evoxup-membership-feature.png'; }
        $purchase_url = ( new PurchaseLinkService() )->product_url( $product );
        ob_start(); ?>
        <article class="evo-product-card<?php echo $compact ? ' evo-product-card-compact' : ''; ?>">
            <img src="<?php echo esc_url( (string) $image ); ?>" alt="<?php echo esc_attr( (string) $product['name'] ); ?>">
            <div class="evo-product-card-body"><span class="evo-account-kicker"><?php echo esc_html( strtoupper( (string) ( $product['product_type'] ?? 'PRODUCT' ) ) ); ?></span><h3><?php echo esc_html( (string) $product['name'] ); ?></h3>
            <?php if ( ! empty( $product['excerpt'] ) ) : ?><p><?php echo esc_html( (string) $product['excerpt'] ); ?></p><?php endif; ?>
            <?php if ( null !== $price ) : ?><div class="evo-price"><?php echo esc_html( trim( (string) ( $product['currency'] ?? '' ) . ' ' . number_format_i18n( (float) $price, 2 ) ) ); ?></div><?php endif; ?>
            <div class="evo-account-actions"><?php if ( '' !== $purchase_url ) : ?><a class="evo-button evo-button-primary" href="<?php echo esc_url( $purchase_url ); ?>"><?php esc_html_e( 'Buy', 'evoxup-membership' ); ?></a><?php else : ?><span class="evo-status evo-status-inactive"><?php esc_html_e( 'Purchase link not configured', 'evoxup-membership' ); ?></span><?php endif; ?></div>
            </div>
        </article><?php
        return (string) ob_get_clean();
    }

    public function plans_shortcode( array $atts = array() ): string {
        unset( $atts );
        $this->enqueue_assets();
        $plans = ( new PlanService() )->all( true );
        if ( ! $plans ) { return '<p>' . esc_html__( 'No membership plans are available right now.', 'evoxup-membership' ) . '</p>'; }
        $links = new PurchaseLinkService();
        ob_start(); ?><div class="evo-plan-grid"><?php foreach ( $plans as $plan_row ) :
            $plan = ( new PlanService() )->get( (int) $plan_row['id'] ) ?: $plan_row;
            $purchase_url = $links->plan_url( $plan );
            $entitlements = json_decode( (string) ( $plan['entitlements_json'] ?? '[]' ), true );
            $entitlements = is_array( $entitlements ) ? $entitlements : array(); ?>
            <?php
            $primary_product = ! empty( $plan['products'][0] ) ? $plan['products'][0] : null;
            $plan_price = is_array( $primary_product ) ? ( ( null !== ( $primary_product['sale_price'] ?? null ) && '' !== (string) $primary_product['sale_price'] ) ? $primary_product['sale_price'] : ( $primary_product['price'] ?? null ) ) : null;
            $plan_currency = is_array( $primary_product ) ? (string) ( $primary_product['currency'] ?? '' ) : '';
            ?>
            <article class="evo-plan-card"><span class="evo-account-kicker"><?php echo esc_html( strtoupper( str_replace( '_', ' ', (string) $plan['tier'] ) ) ); ?></span><h3><?php echo esc_html( (string) $plan['name'] ); ?></h3><p><?php echo esc_html( (string) $plan['description'] ); ?></p>
            <?php if ( null !== $plan_price && '' !== (string) $plan_price ) : ?><div class="evo-price"><?php echo esc_html( trim( $plan_currency . ' ' . number_format_i18n( (float) $plan_price, 2 ) ) ); ?></div><?php endif; ?>
            <?php if ( $entitlements ) : ?><div class="evo-chip-row"><?php foreach ( $entitlements as $entitlement ) : ?><span><?php echo esc_html( (string) $entitlement ); ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ( '' !== $purchase_url ) : ?><a class="evo-button evo-button-primary" href="<?php echo esc_url( $purchase_url ); ?>"><?php esc_html_e( 'Buy / Upgrade', 'evoxup-membership' ); ?></a><?php else : ?><span class="evo-status evo-status-inactive"><?php esc_html_e( 'Link a purchasable product to generate a buy link', 'evoxup-membership' ); ?></span><?php endif; ?>
            </article><?php endforeach; ?></div><?php
        return (string) ob_get_clean();
    }

    private function current_customer_id(): int {
        $user_id = get_current_user_id();
        if ( $user_id < 1 ) { return 0; }
        $memberships = new MembershipService();
        $customer_id = $memberships->customer_id_for_wp_user( $user_id );
        if ( $customer_id > 0 ) { return $customer_id; }
        $user = get_userdata( $user_id );
        if ( ! $user || ! is_email( (string) $user->user_email ) ) { return 0; }
        $customer_id = ( new CustomerService() )->find_or_create( array( 'email'=>$user->user_email, 'display_name'=>$user->display_name, 'first_name'=>$user->first_name, 'last_name'=>$user->last_name ), false );
        if ( $customer_id > 0 ) { ( new CustomerService() )->ensure_wp_user( $customer_id ); }
        return $customer_id;
    }
}
