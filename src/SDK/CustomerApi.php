<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\CustomerApiInterface;
use EvoMembers\Services\CustomerService;

defined( 'ABSPATH' ) || exit;

final class CustomerApi implements CustomerApiInterface {
    public function __construct( private readonly CustomerService $service = new CustomerService() ) {}
    public function get( int $id ): ?array { return $this->service->get( $id ); }
    public function find_by_wp_user( int $wp_user_id ): ?array { return $this->service->find_by_wp_user( $wp_user_id ); }
    public function find_by_email( string $email ): ?array { return $this->service->find_by_email( $email ); }
    public function all( int $limit = 200 ): array { return $this->service->all( $limit ); }
    public function find_or_create( array $data, bool $link_wp_user = false ): int { return $this->service->find_or_create( $data, $link_wp_user ); }
    public function ensure_wp_user( int $customer_id, array $data = array() ): int|\WP_Error { return $this->service->ensure_wp_user( $customer_id, $data ); }
    public function set_status( int $id, string $status ): bool { return $this->service->set_status( $id, $status ); }
    public function delete( int $id, bool $force = false, bool $delete_wp_user = false ): bool { return $this->service->delete( $id, $force, $delete_wp_user ); }
}
