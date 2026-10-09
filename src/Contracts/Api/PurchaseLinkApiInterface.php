<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface PurchaseLinkApiInterface {
    public function product_url( array|int $product ): string;
    public function plan_url( array|int $plan ): string;
    public function membership_center_url(): string;
}
