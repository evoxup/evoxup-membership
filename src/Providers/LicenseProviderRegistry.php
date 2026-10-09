<?php
namespace EvoMembers\Providers;

use EvoMembers\Contracts\LicenseProviderInterface;

defined( 'ABSPATH' ) || exit;

final class LicenseProviderRegistry {
    private static array $providers = array();

    public static function register( LicenseProviderInterface $provider ): void {
        $slug = sanitize_key( $provider->slug() );
        if ( '' === $slug || in_array( $slug, array( 'none', 'evo' ), true ) ) {
            return;
        }
        self::$providers[ $slug ] = $provider;
        \EvoMembers\Core\Hooks::do_action( 'license_provider_registered', $slug, $provider );
    }

    public static function get( string $slug ): ?LicenseProviderInterface {
        $slug = sanitize_key( $slug );
        $providers = \EvoMembers\Core\Hooks::apply_filters( 'license_provider_objects', self::$providers );
        return is_array( $providers ) && isset( $providers[ $slug ] ) && $providers[ $slug ] instanceof LicenseProviderInterface
            ? $providers[ $slug ]
            : null;
    }

    /** @return array<string,LicenseProviderInterface> */
    public static function all(): array {
        $providers = \EvoMembers\Core\Hooks::apply_filters( 'license_provider_objects', self::$providers );
        if ( ! is_array( $providers ) ) {
            return array();
        }
        return array_filter( $providers, static fn( $provider ): bool => $provider instanceof LicenseProviderInterface );
    }

    /** @return array<string,string> */
    public static function labels(): array {
        $labels = array();
        foreach ( self::all() as $slug => $provider ) {
            $labels[ sanitize_key( (string) $slug ) ] = sanitize_text_field( $provider->label() );
        }
        return $labels;
    }
}
