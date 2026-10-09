<?php
namespace EvoMembers\Contracts\Platform;

defined( 'ABSPATH' ) || exit;

/**
 * Privileged infrastructure contract for official Evoxup diagnostics/portability extensions.
 * Third-party business extensions should use the domain APIs instead.
 */
interface PlatformStorageApiInterface {
    public function table( string $logical_name ): string;
    public function schema(): array;
}
