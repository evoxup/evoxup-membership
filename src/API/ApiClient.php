<?php
namespace EvoMembers\API;

defined( 'ABSPATH' ) || exit;

final class ApiClient {
    private ApiConfig $config;

    public function __construct( ?ApiConfig $config = null ) {
        $this->config = $config ?: new ApiConfig();
    }

    public function get( string $route, array $query = array(), array $headers = array() ): array {
        return $this->request( 'GET', $this->config->base_url(), $route, $query, array(), $headers );
    }

    public function post( string $route, array $body = array(), array $headers = array() ): array {
        return $this->request( 'POST', $this->config->base_url(), $route, array(), $body, $headers );
    }

    public function external_get( string $route, array $query = array(), array $headers = array() ): array {
        return $this->request( 'GET', $this->config->external_base_url(), $route, $query, array(), array_merge( $this->config->external_headers(), $headers ) );
    }

    public function external_post( string $route, array $body = array(), array $headers = array() ): array {
        return $this->request( 'POST', $this->config->external_base_url(), $route, array(), $body, array_merge( $this->config->external_headers(), $headers ) );
    }

    private function request( string $method, string $base, string $route, array $query, array $body, array $headers ): array {
        if ( '' === $base || ! wp_http_validate_url( $base ) ) {
            return array( 'success' => false, 'status' => 0, 'error' => array( 'code' => 'invalid_api_base', 'message' => 'EVO API base URL is not configured or invalid.' ) );
        }

        $url = untrailingslashit( $base ) . '/' . ltrim( $route, '/' );
        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        $args = array(
            'timeout'     => 'GET' === strtoupper( $method ) ? 20 : 30,
            'redirection' => 0,
            'headers'     => array_merge( array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' ), $headers ),
            'user-agent'  => 'Evoxup-Membership/' . EVOMEMBERS_VERSION,
        );
        if ( 'POST' === strtoupper( $method ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $started  = microtime( true );
        $response = 'GET' === strtoupper( $method ) ? wp_safe_remote_get( $url, $args ) : wp_safe_remote_post( $url, $args );
        $result   = $this->normalize( $response );
        $result['_meta'] = array(
            'url'        => esc_url_raw( $url ),
            'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
        );
        return $result;
    }

    private function normalize( mixed $response ): array {
        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'status' => 0, 'error' => array( 'code' => $response->get_error_code(), 'message' => $response->get_error_message() ) );
        }
        $status  = (int) wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return array( 'success' => false, 'status' => $status, 'error' => array( 'code' => 'invalid_json', 'message' => 'EVO API returned invalid JSON.' ) );
        }
        $decoded['status'] = $status;
        return $decoded;
    }
}
