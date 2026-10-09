<?php
namespace EvoMembers\Services;

use EvoMembers\API\ApiClient;
use EvoMembers\API\ApiConfig;
use EvoMembers\Core\MembershipIdentifiers;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Local/external authority coordinator.
 *
 * External server-to-server calls always target the remote node's integration
 * endpoints, which execute against that node's local canonical data. This
 * deliberately avoids A -> B -> A routing loops while still allowing the
 * caller to run local-only, external-only, or local-first hybrid resolution.
 */
final class AuthorityRouter {
    private ApiConfig $config;
    private ApiClient $client;

    public function __construct( ?ApiConfig $config = null, ?ApiClient $client = null ) {
        $this->config = $config ?: new ApiConfig();
        $this->client = $client ?: new ApiClient( $this->config );
    }

    public function verify_license( string $license_key, array $context = array() ): array|WP_Error {
        $mode = $this->config->mode();
        if ( 'external' === $mode ) {
            return $this->verify_external( $license_key, $context );
        }

        $local = ( new LicenseService() )->verify( $license_key, $context );
        if ( ! is_wp_error( $local ) ) {
            $local['authority'] = 'internal';
            return $local;
        }

        if ( 'hybrid' !== $mode || ! $this->is_not_found( $local ) ) {
            return $local;
        }

        return $this->verify_external( $license_key, $context );
    }

    public function activate_license( string $license_key, string $site_url, string $client_version = '', string $server_ip = '' ): array|WP_Error {
        $mode = $this->config->mode();
        if ( 'external' === $mode ) {
            return $this->activate_external( $license_key, $site_url, $client_version, $server_ip );
        }

        $local = ( new LicenseService() )->activate( $license_key, $site_url, $client_version, $server_ip );
        if ( ! is_wp_error( $local ) ) {
            $local['authority'] = 'internal';
            return $local;
        }
        if ( 'hybrid' !== $mode || ! $this->is_not_found( $local ) ) {
            return $local;
        }
        return $this->activate_external( $license_key, $site_url, $client_version, $server_ip );
    }

    public function deactivate_license( string $license_key, string $site_url, string $server_ip = '' ): array|WP_Error {
        $mode = $this->config->mode();
        if ( 'external' === $mode ) {
            return $this->deactivate_external( $license_key, $site_url, $server_ip );
        }

        $local = ( new LicenseService() )->deactivate( $license_key, $site_url, $server_ip );
        if ( ! is_wp_error( $local ) ) {
            $local['authority'] = 'internal';
            return $local;
        }
        if ( 'hybrid' !== $mode || ! $this->is_not_found( $local ) ) {
            return $local;
        }
        return $this->deactivate_external( $license_key, $site_url, $server_ip );
    }

    public function products(): array|WP_Error {
        $local_items = array_map(
            static fn( array $p ): array => array(
                'id'               => (int) $p['id'],
                'code'             => (string) $p['code'],
                'name'             => (string) $p['name'],
                'product_type'     => (string) $p['product_type'],
                'status'           => (string) $p['status'],
                'requires_license' => (bool) $p['requires_license'],
                'authority'        => 'internal',
            ),
            ( new ProductService() )->all()
        );

        if ( 'internal' === $this->config->mode() ) {
            return $local_items;
        }

        $remote = $this->external_request( 'GET', 'integration/products' );
        if ( is_wp_error( $remote ) ) {
            return 'external' === $this->config->mode() ? $remote : $local_items;
        }
        $remote_items = is_array( $remote['items'] ?? null ) ? $remote['items'] : array();
        $remote_items = array_map(
            static function ( array $p ): array {
                $p['authority'] = 'external';
                return $p;
            },
            $remote_items
        );
        if ( 'external' === $this->config->mode() ) {
            return $remote_items;
        }

        $merged = array();
        foreach ( array_merge( $local_items, $remote_items ) as $item ) {
            $key = sanitize_key( (string) ( $item['code'] ?? '' ) );
            if ( '' === $key ) {
                $key = (string) ( $item['authority'] ?? 'unknown' ) . ':' . absint( $item['id'] ?? 0 );
            }
            if ( ! isset( $merged[ $key ] ) ) {
                $merged[ $key ] = $item; // local wins because it is appended first.
            }
        }
        return array_values( $merged );
    }

    public function discover_external(): array|WP_Error {
        if ( ! $this->config->uses_external() ) {
            return new WP_Error( 'evomembers_external_disabled', 'External API is not enabled.', array( 'status' => 400 ) );
        }
        if ( '' === $this->config->external_base_url() ) {
            return new WP_Error( 'evomembers_external_url_missing', 'External API base URL is required.', array( 'status' => 400 ) );
        }

        $route = $this->config->has_external_credentials() ? 'integration/discovery' : 'discovery';
        $result = $this->external_request( 'GET', $route, array(), false );
        if ( is_wp_error( $result ) && 'integration/discovery' === $route ) {
            $data = $result->get_error_data();
            $status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
            if ( 404 === $status ) {
                // Older EVO nodes may expose only public discovery. Keep the
                // connection visible as LIMITED instead of failing discovery.
                $fallback = $this->external_request( 'GET', 'discovery', array(), false );
                if ( ! is_wp_error( $fallback ) ) {
                    $fallback['compatibility'] = 'limited';
                    $fallback['credential_probe'] = 'unsupported';
                    return $fallback;
                }
            }
        }
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $result;
    }

    public function readiness(): array {
        $out = array(
            'mode'       => $this->config->mode(),
            'internal'   => array( 'enabled' => $this->config->uses_internal(), 'base_url' => $this->config->internal_base_url(), 'ready' => true ),
            'external'   => array( 'enabled' => $this->config->uses_external(), 'base_url' => $this->config->external_base_url(), 'ready' => false ),
            'overall'    => 'ready',
        );

        if ( ! $this->config->uses_external() ) {
            return $out;
        }

        $started = microtime( true );
        $discovery = $this->discover_external();
        $out['external']['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $discovery ) ) {
            $out['external']['error'] = $discovery->get_error_message();
            $out['overall'] = 'external' === $this->config->mode() ? 'blocked' : 'partial';
            return $out;
        }

        $out['external']['ready'] = true;
        $out['external']['discovery'] = $discovery;
        $out['external']['authenticated'] = ! empty( $discovery['credential'] ) || ! empty( $discovery['credential_permissions'] );
        if ( ! $out['external']['authenticated'] && $this->config->has_external_credentials() ) {
            // A legacy node may support the actual integration endpoints while
            // lacking authenticated capability discovery. Treat that as
            // PARTIAL/UNKNOWN, not a false hard failure.
            $out['overall'] = 'partial';
        }
        return $out;
    }

    private function verify_external( string $license_key, array $context ): array|WP_Error {
        $body = array_merge(
            array(
                'license_key' => sanitize_text_field( $license_key ),
            ),
            array_intersect_key( $context, array_flip( array( 'product_id', 'product_code', 'site_url', 'domain', 'server_ip', 'client_version' ) ) )
        );
        $result = $this->external_request( 'POST', 'integration/licenses/verify', $body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $result['authority'] = 'external';
        return $result;
    }

    private function activate_external( string $license_key, string $site_url, string $client_version, string $server_ip ): array|WP_Error {
        $result = $this->external_request( 'POST', 'integration/licenses/activate', array(
            'license_key'    => sanitize_text_field( $license_key ),
            'site_url'       => esc_url_raw( $site_url ),
            'client_version' => sanitize_text_field( $client_version ),
            'server_ip'      => sanitize_text_field( $server_ip ),
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $result['authority'] = 'external';
        return $result;
    }

    private function deactivate_external( string $license_key, string $site_url, string $server_ip ): array|WP_Error {
        $result = $this->external_request( 'POST', 'integration/licenses/deactivate', array(
            'license_key' => sanitize_text_field( $license_key ),
            'site_url'    => esc_url_raw( $site_url ),
            'server_ip'   => sanitize_text_field( $server_ip ),
        ) );
        if ( is_wp_error( $result ) ) { return $result; }
        $result['authority'] = 'external';
        return $result;
    }

    private function external_request( string $method, string $route, array $body = array(), bool $require_credentials = true ): array|WP_Error {
        $base = $this->config->external_base_url();
        if ( '' === $base || ! wp_http_validate_url( $base ) ) {
            return new WP_Error( 'evomembers_external_url_invalid', 'External API base URL is missing or invalid.', array( 'status' => 400 ) );
        }
        if ( $this->points_to_self( $base ) ) {
            return new WP_Error( 'evomembers_external_self_loop', 'External API points to this same EVO node. Choose Internal API or a different remote node.', array( 'status' => 409 ) );
        }
        if ( $require_credentials && ! $this->config->has_external_credentials() ) {
            return new WP_Error( 'evomembers_external_credentials_missing', 'External API service credential is required for this operation.', array( 'status' => 401 ) );
        }

        $request_id = wp_generate_uuid4();
        $headers = $this->config->external_headers( $request_id, ApiConfig::node_id() );
        $response = 'GET' === strtoupper( $method )
            ? $this->client->external_get( $route, array(), $headers )
            : $this->client->external_post( $route, $body, $headers );

        if ( empty( $response['success'] ) ) {
            $status  = absint( $response['status'] ?? 502 );
            $error   = is_array( $response['error'] ?? null ) ? $response['error'] : array();
            $message = sanitize_text_field( (string) ( $error['message'] ?? 'External EVO API request failed.' ) );
            $code    = sanitize_key( (string) ( $error['code'] ?? 'external_api_failed' ) );
            return new WP_Error( $code ?: 'external_api_failed', $message, array( 'status' => $status ?: 502 ) );
        }

        $response_meta = is_array( $response['meta'] ?? null ) ? $response['meta'] : array();
        $returned_request_id = sanitize_text_field( (string) ( $response_meta['request_id'] ?? '' ) );
        if ( '' !== $returned_request_id && ! hash_equals( $request_id, $returned_request_id ) ) {
            return new WP_Error( 'evomembers_external_request_mismatch', 'External EVO response request correlation did not match.', array( 'status'=>502 ) );
        }

        $data = $response['data'] ?? array();
        return is_array( $data ) ? $data : array();
    }

    private function points_to_self( string $base ): bool {
        $external_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
        $internal_host = strtolower( (string) wp_parse_url( $this->config->internal_base_url(), PHP_URL_HOST ) );
        $external_path = untrailingslashit( (string) wp_parse_url( $base, PHP_URL_PATH ) );
        $internal_path = untrailingslashit( (string) wp_parse_url( $this->config->internal_base_url(), PHP_URL_PATH ) );
        return '' !== $external_host && $external_host === $internal_host && $external_path === $internal_path;
    }

    private function is_not_found( WP_Error $error ): bool {
        $data = $error->get_error_data();
        $status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
        $code = sanitize_key( $error->get_error_code() );
        return 404 === $status && ( str_contains( $code, 'license_invalid' ) || str_contains( $code, 'activation_not_found' ) );
    }
}
