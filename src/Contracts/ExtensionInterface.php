<?php
namespace EvoMembers\Contracts;

use EvoMembers\Core\ExtensionContext;

defined( 'ABSPATH' ) || exit;

/**
 * Stable contract for first-class EVO extensions.
 *
 * Extensions receive a scoped context instead of reaching into Core internals.
 * Legacy module.json packages remain supported by ModuleLoader for backwards
 * compatibility, but new extensions should implement this interface.
 */
interface ExtensionInterface {
    public function boot( ExtensionContext $context ): void;
}
