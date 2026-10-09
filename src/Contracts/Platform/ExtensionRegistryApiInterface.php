<?php
namespace EvoMembers\Contracts\Platform;

defined( 'ABSPATH' ) || exit;

interface ExtensionRegistryApiInterface {
    public function discover(): array;
    /** @return array<int,string> */
    public function active_ids(): array;
    public function is_active( string $extension_id ): bool;
    public function recovery( string $extension_id ): array;
}
