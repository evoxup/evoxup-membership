<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\CapabilityApiInterface;
use EvoMembers\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

final class CapabilityApi implements CapabilityApiInterface {
    public function register( string $capability, string $label, string $group = 'Extensions', array $default_roles = array() ): bool { return Capabilities::register( $capability, $label, $group, $default_roles ); }
    public function can( string $capability ): bool { return current_user_can( sanitize_key( $capability ) ); }
    public function definitions(): array { return Capabilities::definitions(); }
}
