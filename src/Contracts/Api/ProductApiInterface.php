<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface ProductApiInterface {
    public function get( int $id ): ?array;
    public function get_by_code( string $code ): ?array;
    public function get_by_source( string $source, string $external_source_id ): ?array;
    public function all( int $limit = 300, bool $include_archived = false, string $source = 'all', int $offset = 0 ): array;
    public function create( array $data ): int;
    public function update( int $id, array $data ): bool;
    public function set_status( int $id, string $status ): bool;
    public function delete( int $id, bool $force = false ): bool;
    public function sync_plans( int $product_id, array $plan_ids ): void;
}
