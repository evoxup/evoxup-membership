<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface EntitlementApiInterface {
    /** @return array<int,int> */
    public function product_ids_for_customer( int $customer_id ): array;
    public function product_count_for_customer( int $customer_id ): int;
}
