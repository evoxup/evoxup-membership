<?php
namespace EvoMembers\Core;

use EvoMembers\Admin\Admin;
use EvoMembers\Api\V1\Routes;
use EvoMembers\Integrations\WordPress\Mail;
use EvoMembers\Integrations\WooCommerce\ProductLicensing;
use EvoMembers\Frontend\MembershipHub;
use EvoMembers\Editor\Blocks;
use EvoMembers\Services\WebhookService;
use EvoMembers\Services\EventMessageRouter;
use EvoMembers\Services\Logger;
use EvoMembers\Services\AuditTrail;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        // WordPress 6.7+ warns when a plugin triggers just-in-time translation
        // loading before init. Installer/capability upgrades can build translated
        // role labels, so defer them until init instead of running at plugins_loaded.
        add_action( 'init', fn(): mixed => $this->safe_boot( 'installer', static fn(): mixed => Installer::maybe_upgrade() ), 0 );
        // Capability changes are versioned independently from database schema so
        // a UI/permissions-only update does not rerun every dbDelta migration.
        add_action( 'init', fn(): mixed => $this->safe_boot( 'capabilities', static fn(): mixed => Capabilities::maybe_install() ), 1 );

        add_action( 'rest_api_init', fn(): mixed => $this->safe_boot( 'rest_routes', static fn(): mixed => Routes::register() ) );
        add_filter( 'evomembers_security_sources', array( RequestGuard::class, 'register_security_source' ) );

        // WordPress owns mail delivery. EVO emits message/license events and this
        // integration delegates actual delivery to wp_mail(), allowing SMTP and
        // mail-design plugins to remain in control of transport/presentation.
        $this->safe_boot( 'mail', static fn(): mixed => ( new Mail() )->boot() );
        $this->safe_boot( 'event_message_router', static fn(): mixed => ( new EventMessageRouter() )->boot() );

        $this->safe_boot( 'membership_hub', static fn(): mixed => ( new MembershipHub() )->boot() );
        $this->safe_boot( 'editor_blocks', static fn(): mixed => ( new Blocks() )->boot() );

        // Optional extensions may register translated capability labels in
        // their boot methods. Load active extensions on init so they can never
        // trigger the WordPress just-in-time translation warning at plugins_loaded.
        add_action( 'init', fn(): mixed => $this->safe_boot( 'extensions', static fn(): mixed => ( new ModuleLoader() )->boot_active() ), 2 );

        // WooCommerce remains independent. Active extensions now boot on init
        // priority 2, so defer this decision until priority 3. This preserves the
        // first-class WooCommerce integration handshake and avoids accidentally
        // enabling the fallback bridge before that extension can announce itself.
        add_action(
            'init',
            function (): void {
                if ( ! $this->first_class_woocommerce_extension_active() ) {
                    if ( (bool) get_option( 'evomembers_woocommerce_enabled', false ) ) {
                    $this->safe_boot( 'woocommerce_product_licensing', static fn(): mixed => ( new ProductLicensing() )->boot() );
                }
                } else {
                    \EvoMembers\Core\Hooks::do_action( 'legacy_woocommerce_bridge_skipped', 'evoxup-woocommerce' );
                }
            },
            3
        );

        if ( is_admin() ) {
            RequiredComponents::register_admin_hooks();
            $this->safe_boot( 'admin', static fn(): mixed => ( new Admin() )->boot() );
        }

        $this->safe_boot( 'loaded_hook', fn(): mixed => \EvoMembers\Core\Hooks::do_action( 'loaded', $this ) );
    }


    /**
     * Boot one runtime component without allowing a component-level failure to
     * white-screen WordPress. Errors are logged and surfaced through a hook so a
     * diagnostics extension can report them later.
     */
    private function safe_boot( string $component, callable $callback ): void {
        try {
            $callback();
        } catch ( \Throwable $throwable ) {
            $context = array(
                'component' => sanitize_key( $component ),
                'message'   => sanitize_text_field( $throwable->getMessage() ),
                'file'      => wp_normalize_path( $throwable->getFile() ),
                'line'      => (int) $throwable->getLine(),
            );

            try {
                Logger::write( 'core_component_boot_failed', $context, 'error' );
            } catch ( \Throwable $logging_error ) {
                // Database/logging may be the failing component. Keep the core
                // alive and use the PHP log as the final fallback.
                error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate last-resort runtime recovery log.
                    sprintf(
                        'Evoxup Membership component "%s" failed: %s (%s:%d)',
                        sanitize_key( $component ),
                        $throwable->getMessage(),
                        wp_normalize_path( $throwable->getFile() ),
                        (int) $throwable->getLine()
                    )
                );
            }

            AuditTrail::record(
                'system.component_boot_failed',
                'component',
                sanitize_key( $component ),
                null,
                null,
                array_merge( $context, array( 'category' => 'system' ) ),
                'failure',
                'core',
                'error'
            );
            \EvoMembers\Core\Hooks::do_action( 'component_boot_failed', sanitize_key( $component ), $throwable, $context );
        }
    }

    private function first_class_woocommerce_extension_active(): bool {
        return did_action( 'evoxup_woocommerce_runtime_ready' ) > 0;
    }

}
