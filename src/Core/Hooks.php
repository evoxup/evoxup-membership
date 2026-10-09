<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

/** Canonical Evoxup Membership hooks. */
final class Hooks {
    private const PREFIX = 'evomembers_';

    public static function do_action( string $suffix, mixed ...$args ): void {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The wrapper always prepends the fixed canonical Evoxup Membership prefix.
        do_action( self::PREFIX . $suffix, ...$args );
    }

    public static function apply_filters( string $suffix, mixed $value, mixed ...$args ): mixed {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The wrapper always prepends the fixed canonical Evoxup Membership prefix.
        return apply_filters( self::PREFIX . $suffix, $value, ...$args );
    }
}
