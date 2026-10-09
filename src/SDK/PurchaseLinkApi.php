<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\PurchaseLinkApiInterface;
use EvoMembers\Services\PurchaseLinkService;

defined( 'ABSPATH' ) || exit;

final class PurchaseLinkApi implements PurchaseLinkApiInterface {
    public function __construct( private readonly PurchaseLinkService $service = new PurchaseLinkService() ) {}
    public function product_url( array|int $product ): string { return $this->service->product_url( $product ); }
    public function plan_url( array|int $plan ): string { return $this->service->plan_url( $plan ); }
    public function membership_center_url(): string { return $this->service->membership_center_url(); }
}
