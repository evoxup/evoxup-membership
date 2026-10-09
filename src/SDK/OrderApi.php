<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\OrderApiInterface;
use EvoMembers\Services\OrderService;

defined( 'ABSPATH' ) || exit;

final class OrderApi implements OrderApiInterface {
    public function __construct( private readonly OrderService $service = new OrderService() ) {}
    public function get( int $id ): ?array { return $this->service->get( $id ); }
    public function find_by_provider_reference( string $provider, string $provider_order_id ): ?array { return $this->service->find_by_provider_reference( $provider, $provider_order_id ); }
    public function upsert_provider_order( array $order, array $items = array() ): int|\WP_Error { return $this->service->upsert_provider_order( $order, $items ); }
    public function all( int $limit = 300 ): array { return $this->service->all( $limit ); }
    public function for_customer( int $customer_id, int $limit = 100 ): array { return $this->service->for_customer( $customer_id, $limit ); }
    public function count_for_customer( int $customer_id ): int { return $this->service->count_for_customer( $customer_id ); }
    public function items( int $order_id ): array { return $this->service->items( $order_id ); }
    public function set_status( int $id, string $status ): bool { return $this->service->set_status( $id, $status ); }
    public function delete( int $id, bool $force = false ): bool { return $this->service->delete( $id, $force ); }
}
