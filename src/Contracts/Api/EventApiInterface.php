<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface EventApiInterface {
    public function register( string $event, array $definition = array() ): bool;
    public function definition( string $event ): ?array;
    public function catalog(): array;
    public function validate( string $event, array $payload ): true|\WP_Error;
    public function subscribe( string $event, callable $callback, int $priority = 10 ): bool;
    public function emit( string $event, array $payload = array(), string $aggregate_type = '', int $aggregate_id = 0, int $customer_id = 0 ): int|\WP_Error;
    public function recent( int $limit = 100 ): array;
    public function for_customer( int $customer_id, int $limit = 100 ): array;
}
