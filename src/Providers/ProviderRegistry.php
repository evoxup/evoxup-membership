<?php
namespace EvoMembers\Providers;

use EvoMembers\Contracts\WebhookProviderInterface;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
    private static array $providers = array();

    public static function register( WebhookProviderInterface $provider ): void {
        self::$providers[ sanitize_key( $provider->slug() ) ] = $provider;
        do_action( 'evoxup_provider_registered', $provider->slug(), $provider );
    }

    public static function get( string $slug ): ?WebhookProviderInterface {
        $slug = sanitize_key( $slug );
        $providers = apply_filters( 'evoxup_webhook_providers', self::$providers );
        return is_array( $providers ) && isset( $providers[ $slug ] ) && $providers[ $slug ] instanceof WebhookProviderInterface ? $providers[ $slug ] : null;
    }

    public static function all(): array {
        $providers = apply_filters( 'evoxup_webhook_providers', self::$providers );
        return is_array( $providers ) ? $providers : array();
    }
}
