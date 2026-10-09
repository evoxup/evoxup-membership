<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\PlanApiInterface;
use EvoMembers\Services\PlanService;

defined( 'ABSPATH' ) || exit;

final class PlanApi implements PlanApiInterface {
    public function __construct( private readonly PlanService $service = new PlanService() ) {}
    public function get( int $id ): ?array { return $this->service->get( $id ); }
    public function all( bool $active_only = false ): array { return $this->service->all( $active_only ); }
    public function save( array $data, int $id = 0 ): int|false { return $this->service->save( $data, $id ); }
    public function delete( int $id, bool $force = false ): bool { return $this->service->delete( $id, $force ); }
    public function sync_products( int $plan_id, array $product_ids ): void { $this->service->sync_products( $plan_id, $product_ids ); }
    public function products( int $plan_id ): array { return $this->service->products( $plan_id ); }
    public function plans_for_product( int $product_id ): array { return $this->service->plans_for_product( $product_id ); }
}
