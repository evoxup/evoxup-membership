<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface MembershipApiInterface {
    public function get( int $id ): ?array;
    public function all( int $limit = 300 ): array;
    public function for_customer( int $customer_id ): array;
    public function current_for_customer( int $customer_id ): ?array;
    public function count_for_customer( int $customer_id, bool $active_only = false ): int;
    public function grant( int $customer_id, int $plan_id, array $args = array() ): array|\WP_Error;
    public function renew( int $membership_id, ?int $duration_days = null ): array|\WP_Error;
    public function set_status( int $membership_id, string $status ): bool;
    public function delete( int $membership_id, bool $force = false ): bool;
}
