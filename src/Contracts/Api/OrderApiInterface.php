<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface OrderApiInterface {
    public function get( int $id ): ?array;
    public function find_by_provider_reference( string $provider, string $provider_order_id ): ?array;
    public function upsert_provider_order( array $order, array $items = array() ): int|\WP_Error;
    public function all( int $limit = 300 ): array;
    public function for_customer( int $customer_id, int $limit = 100 ): array;
    public function count_for_customer( int $customer_id ): int;
    public function items( int $order_id ): array;
    public function set_status( int $id, string $status ): bool;
    public function delete( int $id, bool $force = false ): bool;
}
