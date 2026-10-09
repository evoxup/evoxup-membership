<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\ProductApiInterface;
use EvoMembers\Services\ProductService;

defined( 'ABSPATH' ) || exit;

final class ProductApi implements ProductApiInterface {
    public function __construct( private readonly ProductService $service = new ProductService() ) {}
    public function get( int $id ): ?array { return $this->service->get( $id ); }
    public function get_by_code( string $code ): ?array { return $this->service->get_by_code( $code ); }
    public function get_by_source( string $source, string $external_source_id ): ?array { return $this->service->get_by_source( $source, $external_source_id ); }
    public function all( int $limit = 300, bool $include_archived = false, string $source = 'all', int $offset = 0 ): array { return $this->service->all( $limit, $include_archived, $source, $offset ); }
    public function create( array $data ): int { return $this->service->create( $data ); }
    public function update( int $id, array $data ): bool { return $this->service->update( $id, $data ); }
    public function set_status( int $id, string $status ): bool { return $this->service->set_status( $id, $status ); }
    public function delete( int $id, bool $force = false ): bool { return $this->service->delete( $id, $force ); }
    public function sync_plans( int $product_id, array $plan_ids ): void { $this->service->sync_plans( $product_id, $plan_ids ); }
}
