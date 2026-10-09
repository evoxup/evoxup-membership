<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface NotificationApiInterface {
    public function create( int $customer_id, string $title, string $message, string $type = 'info', string $action_url = '', ?string $expires_at = null ): array|\WP_Error;
    public function for_customer( int $customer_id, int $limit = 100 ): array;
    public function unread_count( int $customer_id ): int;
    public function mark_read( int $id, int $customer_id ): bool;
}
