<?php
namespace EvoMembers\Editor;

use EvoMembers\Frontend\MembershipHub;

 defined( 'ABSPATH' ) || exit;

final class Blocks {
    public function boot(): void {
        add_action( 'init', array( $this, 'register' ), 30 );
        add_filter( 'block_categories_all', array( $this, 'category' ), 10, 2 );
    }

    public function category( array $categories ): array {
        foreach ( $categories as $category ) {
            if ( 'evoxup' === ( $category['slug'] ?? '' ) ) {
                return $categories;
            }
        }
        array_unshift( $categories, array( 'slug' => 'evoxup', 'title' => __( 'Evoxup Membership', 'evoxup-membership' ) ) );
        return $categories;
    }

    public function register(): void {
        wp_register_script(
            'evoxup-membership-editor-blocks',
            EVOMEMBERS_URL . 'assets/editor-blocks.js',
            array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
            EVOMEMBERS_VERSION,
            true
        );
        $hub = new MembershipHub();
        $blocks = array(
            'membership-center' => array( 'title' => __( 'EVO Membership Center', 'evoxup-membership' ), 'render' => array( $hub, 'center_shortcode' ) ),
            'products'          => array( 'title' => __( 'EVO Products', 'evoxup-membership' ), 'render' => array( $hub, 'products_shortcode' ) ),
            'plans'             => array( 'title' => __( 'EVO Membership Plans', 'evoxup-membership' ), 'render' => array( $hub, 'plans_shortcode' ) ),
            'my-membership'     => array( 'title' => __( 'EVO My Membership', 'evoxup-membership' ), 'render' => array( $hub, 'membership_shortcode' ) ),
        );
        foreach ( $blocks as $slug => $config ) {
            register_block_type(
                'evoxup/' . $slug,
                array(
                    'api_version'     => 2,
                    'editor_script'   => 'evoxup-membership-editor-blocks',
                    'render_callback' => static fn( array $attributes = array(), string $content = '' ) => (string) call_user_func( $config['render'], $attributes ),
                    'attributes'      => array(),
                    'supports'        => array( 'html' => false ),
                )
            );
        }

    }
}
