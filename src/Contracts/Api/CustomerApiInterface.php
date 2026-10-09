<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface CustomerApiInterface {
    public function get( int $id ): ?array;
    public function find_by_wp_user( int $wp_user_id ): ?array;
    public function find_by_email( string $email ): ?array;
    public function all( int $limit = 200 ): array;
    public function find_or_create( array $data, bool $link_wp_user = false ): int;
    public function ensure_wp_user( int $customer_id, array $data = array() ): int|\WP_Error;
    public function set_status( int $id, string $status ): bool;
    public function delete( int $id, bool $force = false, bool $delete_wp_user = false ): bool;
}
