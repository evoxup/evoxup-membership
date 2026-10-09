<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface CapabilityApiInterface {
    public function register( string $capability, string $label, string $group = 'Extensions', array $default_roles = array() ): bool;
    public function can( string $capability ): bool;
    public function definitions(): array;
}
