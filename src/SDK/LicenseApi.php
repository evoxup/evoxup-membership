<?php
namespace EvoMembers\SDK;

use EvoMembers\Contracts\Api\LicenseApiInterface;
use EvoMembers\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

final class LicenseApi implements LicenseApiInterface {
    public function __construct( private readonly LicenseService $service = new LicenseService() ) {}
    public function get( int $license_id, bool $include_key = false ): ?array { return $this->service->get( $license_id, $include_key ); }
    public function issue( array $args ): array|\WP_Error { return $this->service->issue( $args ); }
    public function issue_many( array $args, int $count ): array|\WP_Error { return $this->service->issue_many( $args, $count ); }
    public function register_external( array $args ): array|\WP_Error { return $this->service->register_external( $args ); }
    public function verify( string $license_key, array $context = array() ): array|\WP_Error { return $this->service->verify( $license_key, $context ); }
    public function activate( string $license_key, string $site_url, string $client_version = '', string $server_ip = '' ): array|\WP_Error { return $this->service->activate( $license_key, $site_url, $client_version, $server_ip ); }
    public function deactivate( string $license_key, string $site_url, string $server_ip = '' ): array|\WP_Error { return $this->service->deactivate( $license_key, $site_url, $server_ip ); }
    public function for_customer( int $customer_id, bool $include_keys = false, int $limit = 200 ): array { return $this->service->for_customer( $customer_id, $include_keys, $limit ); }
    public function count_for_customer( int $customer_id, bool $active_only = false ): int { return $this->service->count_for_customer( $customer_id, $active_only ); }
    public function set_status( int $license_id, string $status ): bool { return $this->service->set_status( $license_id, $status ); }
    public function deactivate_activation_id( int $activation_id ): bool { return $this->service->deactivate_activation_id( $activation_id ); }
}
