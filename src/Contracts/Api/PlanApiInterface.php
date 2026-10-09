<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface PlanApiInterface {
    public function get( int $id ): ?array;
    public function all( bool $active_only = false ): array;
    public function save( array $data, int $id = 0 ): int|false;
    public function delete( int $id, bool $force = false ): bool;
    public function sync_products( int $plan_id, array $product_ids ): void;
    public function products( int $plan_id ): array;
    public function plans_for_product( int $product_id ): array;
}
