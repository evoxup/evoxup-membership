<?php
namespace EvoMembers\Contracts\Api;

defined( 'ABSPATH' ) || exit;

interface LicenseApiInterface {
    public function get( int $license_id, bool $include_key = false ): ?array;
    public function issue( array $args ): array|\WP_Error;
    public function issue_many( array $args, int $count ): array|\WP_Error;
    public function register_external( array $args ): array|\WP_Error;
    public function verify( string $license_key, array $context = array() ): array|\WP_Error;
    public function activate( string $license_key, string $site_url, string $client_version = '', string $server_ip = '' ): array|\WP_Error;
    public function deactivate( string $license_key, string $site_url, string $server_ip = '' ): array|\WP_Error;
    public function for_customer( int $customer_id, bool $include_keys = false, int $limit = 200 ): array;
    public function count_for_customer( int $customer_id, bool $active_only = false ): int;
    public function set_status( int $license_id, string $status ): bool;
    public function deactivate_activation_id( int $activation_id ): bool;
}
