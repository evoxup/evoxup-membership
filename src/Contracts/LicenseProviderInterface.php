<?php
namespace EvoMembers\Contracts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Optional adapter contract for external license systems.
 *
 * Adapters may be supplied by EVO modules or third-party plugins. The core
 * never hard-codes a commercial provider: it only asks the registered adapter
 * to issue/verify a key when that provider has been selected.
 */
interface LicenseProviderInterface {
    public function slug(): string;
    public function label(): string;
    public function supports( string $operation ): bool;

    /** @return array|WP_Error */
    public function issue( array $context ): array|WP_Error;

    /** @return array|WP_Error */
    public function verify( string $license_key, array $context = array() ): array|WP_Error;
}
