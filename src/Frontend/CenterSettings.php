<?php
namespace EvoMembers\Frontend;

defined( 'ABSPATH' ) || exit;

final class CenterSettings {
    public const OPTION = 'evomembers_center_settings';

    public static function defaults(): array {
        return array(
            'hero_title'          => __( 'Products, plans and your membership', 'evoxup-membership' ),
            'hero_text'           => __( 'Buy or upgrade products and memberships, then manage licenses, sites, downloads, orders and messages from the same center.', 'evoxup-membership' ),
            'products_title'      => __( 'Products', 'evoxup-membership' ),
            'plans_title'         => __( 'Membership plans', 'evoxup-membership' ),
            'account_title'       => __( 'My Membership', 'evoxup-membership' ),
            'show_products'       => 1,
            'show_plans'          => 1,
            'show_account'        => 1,
            'show_memberships'    => 1,
            'show_licenses'       => 1,
            'show_activations'    => 1,
            'show_orders'         => 1,
            'show_downloads'      => 1,
            'show_messages'       => 1,
            'catalog_visibility'  => 'public',
            'layout'              => 'stacked',
            'blue'                => '#1478ff',
            'crimson'             => '#b01257',
        );
    }

    public static function get(): array {
        $stored = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
    }

    public static function save( array $input ): array {
        $layout = sanitize_key( (string) ( $input['layout'] ?? 'stacked' ) );
        if ( ! in_array( $layout, array( 'stacked', 'compact', 'account_first' ), true ) ) {
            $layout = 'stacked';
        }
        $visibility = sanitize_key( (string) ( $input['catalog_visibility'] ?? 'public' ) );
        if ( ! in_array( $visibility, array( 'public', 'members' ), true ) ) {
            $visibility = 'public';
        }
        $blue = sanitize_hex_color( (string) ( $input['blue'] ?? '' ) ) ?: '#1478ff';
        $crimson = sanitize_hex_color( (string) ( $input['crimson'] ?? '' ) ) ?: '#b01257';
        $settings = array(
            'hero_title'         => sanitize_text_field( (string) ( $input['hero_title'] ?? '' ) ) ?: self::defaults()['hero_title'],
            'hero_text'          => sanitize_textarea_field( (string) ( $input['hero_text'] ?? '' ) ) ?: self::defaults()['hero_text'],
            'products_title'     => sanitize_text_field( (string) ( $input['products_title'] ?? '' ) ) ?: self::defaults()['products_title'],
            'plans_title'        => sanitize_text_field( (string) ( $input['plans_title'] ?? '' ) ) ?: self::defaults()['plans_title'],
            'account_title'      => sanitize_text_field( (string) ( $input['account_title'] ?? '' ) ) ?: self::defaults()['account_title'],
            'show_products'      => empty( $input['show_products'] ) ? 0 : 1,
            'show_plans'         => empty( $input['show_plans'] ) ? 0 : 1,
            'show_account'       => empty( $input['show_account'] ) ? 0 : 1,
            'show_memberships'   => empty( $input['show_memberships'] ) ? 0 : 1,
            'show_licenses'      => empty( $input['show_licenses'] ) ? 0 : 1,
            'show_activations'   => empty( $input['show_activations'] ) ? 0 : 1,
            'show_orders'        => empty( $input['show_orders'] ) ? 0 : 1,
            'show_downloads'     => empty( $input['show_downloads'] ) ? 0 : 1,
            'show_messages'      => empty( $input['show_messages'] ) ? 0 : 1,
            'catalog_visibility' => $visibility,
            'layout'             => $layout,
            'blue'               => $blue,
            'crimson'            => $crimson,
        );
        update_option( self::OPTION, $settings, false );
        return $settings;
    }
}
