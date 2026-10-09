<?php

defined( 'ABSPATH' ) || exit;

final class EvoMembersAnalyticsLite implements \EvoMembers\Contracts\ExtensionInterface {
    private ?\EvoMembers\Core\ExtensionContext $context = null;

    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        $this->context = $context;
        add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
    }

    public function register_widget(): void {
        if ( ! current_user_can( 'evomembers_view_dashboard' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'evomembers_analytics_lite',
            __( 'Evoxup Analytics Lite', 'evoxup-membership' ),
            array( $this, 'render_widget' )
        );
    }

    public function render_widget(): void {
        if ( ! $this->context ) {
            return;
        }
        $items = array(
            __( 'Members', 'evoxup-membership' )     => count( $this->context->customers()->all( 300 ) ),
            __( 'Memberships', 'evoxup-membership' ) => count( $this->context->memberships()->all( 300 ) ),
            __( 'Products', 'evoxup-membership' )    => count( $this->context->products()->all( 300, true ) ),
        );
        echo '<div class="evomembers-lite-stats">';
        foreach ( $items as $label => $count ) {
            echo '<div><strong>' . esc_html( number_format_i18n( (int) $count ) ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
        }
        echo '</div><p><small>' . esc_html__( 'Local Evoxup records only. No analytics data leaves this site.', 'evoxup-membership' ) . '</small></p>';
    }
}
