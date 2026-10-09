<?php
namespace EvoMembers\SDK\Platform;

use EvoMembers\Contracts\Platform\ExtensionRegistryApiInterface;
use EvoMembers\Core\ModuleLoader;

defined( 'ABSPATH' ) || exit;

final class ExtensionRegistryApi implements ExtensionRegistryApiInterface {
    public function discover(): array { return ( new ModuleLoader() )->discover(); }
    public function active_ids(): array {
        $active = get_option( 'evomembers_active_modules', array() );
        return is_array( $active ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $active ) ) ) ) : array();
    }
    public function is_active( string $extension_id ): bool { return in_array( sanitize_key( $extension_id ), $this->active_ids(), true ); }
    public function recovery( string $extension_id ): array {
        $row = get_option( 'evomembers_module_recovery_' . sanitize_key( $extension_id ), array() );
        return is_array( $row ) ? $row : array();
    }
}
