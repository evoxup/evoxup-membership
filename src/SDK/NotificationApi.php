<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\NotificationApiInterface;
use EvoMembers\Services\NotificationService;

defined( 'ABSPATH' ) || exit;

final class NotificationApi implements NotificationApiInterface {
    public function __construct( private readonly NotificationService $service = new NotificationService() ) {}
    public function create( int $customer_id, string $title, string $message, string $type = 'info', string $action_url = '', ?string $expires_at = null ): array|\WP_Error { return $this->service->create( $customer_id, $title, $message, $type, $action_url, $expires_at ); }
    public function for_customer( int $customer_id, int $limit = 100 ): array { return $this->service->for_customer( $customer_id, $limit ); }
    public function unread_count( int $customer_id ): int { return $this->service->unread_count( $customer_id ); }
    public function mark_read( int $id, int $customer_id ): bool { return $this->service->mark_read( $id, $customer_id ); }
}
