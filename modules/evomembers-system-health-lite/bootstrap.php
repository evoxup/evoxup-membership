<?php

defined( 'ABSPATH' ) || exit;

final class EvoMembersSystemHealthLite implements \EvoMembers\Contracts\ExtensionInterface {
    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        add_action( 'admin_menu', array( $this, 'menu' ), 40 );
    }
    public function menu(): void {
        add_submenu_page(
            'evomembers-members',
            __( 'System Health Lite', 'evoxup-membership' ),
            __( 'System Health', 'evoxup-membership' ),
            'manage_options',
            'evomembers-system-health-lite',
            array( $this, 'render' )
        );
    }
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view diagnostics.', 'evoxup-membership' ) );
        }
        global $wpdb;
        $uploads = wp_upload_dir();
        $checks = array(
            __( 'WordPress', 'evoxup-membership' ) => get_bloginfo( 'version' ),
            __( 'PHP', 'evoxup-membership' ) => PHP_VERSION,
            __( 'HTTPS', 'evoxup-membership' ) => is_ssl() ? __( 'Enabled', 'evoxup-membership' ) : __( 'Not detected', 'evoxup-membership' ),
            __( 'Memory limit', 'evoxup-membership' ) => (string) ini_get( 'memory_limit' ),
            __( 'Database', 'evoxup-membership' ) => sanitize_text_field( (string) $wpdb->db_version() ),
            __( 'WP-Cron', 'evoxup-membership' ) => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Disabled by configuration', 'evoxup-membership' ) : __( 'Available', 'evoxup-membership' ),
            __( 'Uploads directory', 'evoxup-membership' ) => ! empty( $uploads['error'] ) ? __( 'Unavailable', 'evoxup-membership' ) : __( 'Available', 'evoxup-membership' ),
            __( 'WooCommerce', 'evoxup-membership' ) => class_exists( 'WooCommerce' ) ? __( 'Active', 'evoxup-membership' ) : __( 'Not active', 'evoxup-membership' ),
        );
        echo '<div class="wrap"><h1>' . esc_html__( 'Evoxup System Health Lite', 'evoxup-membership' ) . '</h1><p>' . esc_html__( 'Read-only diagnostics. Optional add-ons are ignored when they are not installed.', 'evoxup-membership' ) . '</p><table class="widefat striped"><tbody>';
        foreach ( $checks as $label => $value ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
