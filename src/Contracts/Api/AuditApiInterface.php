<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface AuditApiInterface {
    public function record( string $action, string $resource_type = '', string|int $resource_id = '', ?array $before = null, ?array $after = null, array $context = array(), string $result = 'success', string $severity = 'info' ): array;
    public function denied( string $action, string $resource_type = '', string|int $resource_id = '', array $context = array() ): array;
    public function redact( mixed $value ): mixed;
    public function drain_pending(): array;
}
