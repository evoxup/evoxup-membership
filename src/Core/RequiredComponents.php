<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility facade retained for upgrades from older builds.
 *
 * The WordPress.org build has no mandatory commercial infrastructure modules.
 * Free local extensions are ordinary bundled modules and may be enabled/disabled
 * by an administrator from Extensions.
 */
final class RequiredComponents {
    public static function definitions(): array { return array(); }
    public static function is_required( string $id ): bool { return false; }
    public static function register_admin_hooks(): void {}
    public static function install_on_activation(): void {}
    public static function ensure( bool $force = false ): void {}
    public static function status(): array { return array(); }
    public static function repair(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage Evoxup extensions.', 'evoxup-membership' ) );
        }
        check_admin_referer( 'evomembers_manage_extensions' );
        wp_safe_redirect( admin_url( 'admin.php?page=evomembers-modules' ) );
        exit;
    }
    public static function admin_notice(): void {}
}
