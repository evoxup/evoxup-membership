<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\MembershipApiInterface;
use EvoMembers\Services\MembershipService;

defined( 'ABSPATH' ) || exit;

final class MembershipApi implements MembershipApiInterface {
    public function __construct( private readonly MembershipService $service = new MembershipService() ) {}
    public function get( int $id ): ?array { return $this->service->get( $id ); }
    public function all( int $limit = 300 ): array { return $this->service->all( $limit ); }
    public function for_customer( int $customer_id ): array { return $this->service->for_customer( $customer_id ); }
    public function current_for_customer( int $customer_id ): ?array { return $this->service->current_for_customer( $customer_id ); }
    public function count_for_customer( int $customer_id, bool $active_only = false ): int { return $this->service->count_for_customer( $customer_id, $active_only ); }
    public function grant( int $customer_id, int $plan_id, array $args = array() ): array|\WP_Error { return $this->service->grant( $customer_id, $plan_id, $args ); }
    public function renew( int $membership_id, ?int $duration_days = null ): array|\WP_Error { return $this->service->renew( $membership_id, $duration_days ); }
    public function set_status( int $membership_id, string $status ): bool { return $this->service->set_status( $membership_id, $status ); }
    public function delete( int $membership_id, bool $force = false ): bool { return $this->service->delete( $membership_id, $force ); }
}
