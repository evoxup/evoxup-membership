<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Evoxup Membership identifiers shared by runtime services.
 *
 * The final 1.6 line emits Membership identifiers only for hooks, package IDs,
 * assets, scheduler groups, error codes and WooCommerce metadata.
 */
final class MembershipIdentifiers {
    public static function hook_prefix(): string {
        return 'evomembers_';
    }

    public static function core_package_id(): string {
        return 'evoxup-membership';
    }

    public static function center_slug(): string {
        return 'evoxup-membership';
    }

    public static function center_block_name(): string {
        return 'evoxup/membership-center';
    }

    public static function scheduler_group(): string {
        return 'evoxup-membership';
    }

    public static function error_code( string $suffix ): string {
        return self::hook_prefix() . ltrim( $suffix, '_' );
    }

    public static function admin_asset_handle(): string {
        return 'evoxup-membership-admin';
    }

    public static function frontend_asset_handle(): string {
        return 'evoxup-membership-frontend';
    }

    public static function editor_asset_handle(): string {
        return 'evoxup-membership-editor-blocks';
    }

    public static function meta_key( string $canonical_key ): string {
        return $canonical_key;
    }

    public static function migrate_order_meta( object $order, string $canonical_key, mixed $value ): void {
        if ( method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( $canonical_key, $value );
        }
    }

    public static function add_item_meta( object $item, string $canonical_key, mixed $value, bool $unique = true ): void {
        if ( method_exists( $item, 'add_meta_data' ) ) {
            $item->add_meta_data( $canonical_key, $value, $unique );
        }
    }
}
