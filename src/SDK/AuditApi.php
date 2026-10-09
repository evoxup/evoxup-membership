<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\AuditApiInterface;
use EvoMembers\Services\AuditTrail;

defined( 'ABSPATH' ) || exit;

final class AuditApi implements AuditApiInterface {
    public function __construct( private readonly string $source = 'sdk' ) {}
    public function record( string $action, string $resource_type = '', string|int $resource_id = '', ?array $before = null, ?array $after = null, array $context = array(), string $result = 'success', string $severity = 'info' ): array { return AuditTrail::record( $action, $resource_type, $resource_id, $before, $after, $context, $result, $this->source, $severity ); }
    public function denied( string $action, string $resource_type = '', string|int $resource_id = '', array $context = array() ): array { return AuditTrail::denied( $action, $resource_type, $resource_id, $context, $this->source ); }
    public function redact( mixed $value ): mixed { return AuditTrail::redact( $value ); }
    public function drain_pending(): array { return AuditTrail::drain_pending(); }
}
