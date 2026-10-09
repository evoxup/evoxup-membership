<?php
/**
 * Plugin Name: Evoxup Membership — Membership, Licensing, & Universal Integrations
 * Plugin URI: https://evoxup.com/
 * Description: Free membership and licensing management for WordPress, with optional local WooCommerce integration, secure webhooks and versioned APIs.
 * Version: 1.8.5
 * Requires at least: 6.5
 * Requires PHP: 8.3
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Author: Evoxup
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: evoxup-membership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declare compatibility with WooCommerce features that Evoxup uses only
 * through the supported WooCommerce CRUD/order APIs. Evoxup never reads or
 * writes WooCommerce order posts/postmeta directly, so HPOS is supported.
 * Cart and Checkout Blocks are also supported because fulfillment is handled
 * after WooCommerce creates/updates the order through its normal lifecycle.
 */
add_action(
    'before_woocommerce_init',
    static function (): void {
        if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
);

define( 'EVOMEMBERS_VERSION', '1.8.5' );
define( 'EVOMEMBERS_FILE', __FILE__ );
define( 'EVOMEMBERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'EVOMEMBERS_URL', plugin_dir_url( __FILE__ ) );
define( 'EVOMEMBERS_DONATE_URL', 'https://evoxup.com/donate/' );

define( 'EVOMEMBERS_DB_VERSION', '3.1.1' );
define( 'EVOMEMBERS_EXTENSION_API_VERSION', '2.1.0' );
define( 'EVOMEMBERS_EXTENSION_API_MIN_VERSION', '1.0.0' );
define( 'EVOMEMBERS_EVENT_SCHEMA_VERSION', '1.0.0' );
define( 'EVOMEMBERS_SDK_VERSION', '2.2.0' );

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'EvoMembers\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        if ( 0 === strpos( $relative, 'Api\\' ) ) {
            $relative = substr( $relative, 4 );
            $file     = EVOMEMBERS_PATH . 'api/' . str_replace( '\\', '/', $relative ) . '.php';
        } else {
            $file = EVOMEMBERS_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
        }

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);

add_filter(
    'plugin_action_links_' . plugin_basename( __FILE__ ),
    static function ( array $links ): array {
        $links[] = '<a href="' . esc_url( EVOMEMBERS_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support / Donate', 'evoxup-membership' ) . '</a>';
        return $links;
    }
);

register_activation_hook( __FILE__, array( 'EvoMembers\\Core\\Installer', 'activate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        EvoMembers\Core\Plugin::instance()->boot();
    }
);
