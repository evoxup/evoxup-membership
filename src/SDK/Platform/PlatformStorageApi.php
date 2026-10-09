<?php
namespace EvoMembers\SDK\Platform;

use EvoMembers\Contracts\Platform\PlatformStorageApiInterface;
use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class PlatformStorageApi implements PlatformStorageApiInterface {
    public function table( string $logical_name ): string {
        $logical_name = sanitize_key( $logical_name );
        if ( '' === $logical_name || ! array_key_exists( $logical_name, Database::schema() ) ) {
            return '';
        }
        return Database::table( $logical_name );
    }

    public function schema(): array {
        return Database::schema();
    }
}
