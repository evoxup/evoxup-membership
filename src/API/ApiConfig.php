<?php
namespace EvoMembers\API;

use EvoMembers\Core\Crypto;

defined( 'ABSPATH' ) || exit;

final class ApiConfig {
    private function settings(): array {
        $settings = get_option( 'evomembers_settings', array() );
        return is_array( $settings ) ? $settings : array();
    }

    public function mode(): string {
        $mode = sanitize_key( (string) ( $this->settings()['api_mode'] ?? 'internal' ) );
        return in_array( $mode, array( 'internal', 'external', 'hybrid' ), true ) ? $mode : 'internal';
    }

    public function uses_internal(): bool {
        return in_array( $this->mode(), array( 'internal', 'hybrid' ), true );
    }

    public function uses_external(): bool {
        return in_array( $this->mode(), array( 'external', 'hybrid' ), true );
    }

    public function internal_base_url(): string {
        return untrailingslashit( rest_url( 'evomembers/v1' ) );
    }

    public function external_base_url(): string {
        $url = untrailingslashit( esc_url_raw( (string) ( $this->settings()['external_url'] ?? '' ) ) );
        if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Backward-compatible effective base URL. Hybrid stays local-first;
     * callers that explicitly need the remote node must use external_base_url().
     */
    public function base_url(): string {
        return 'external' === $this->mode() && '' !== $this->external_base_url()
            ? $this->external_base_url()
            : $this->internal_base_url();
    }

    public function external_client_id(): string {
        return sanitize_text_field( (string) ( $this->settings()['external_client_id'] ?? '' ) );
    }

    public function external_secret(): string {
        $stored = (string) ( $this->settings()['external_secret_encrypted'] ?? '' );
        return '' !== $stored ? Crypto::decrypt( $stored ) : '';
    }

    public function external_secret_last4(): string {
        return sanitize_text_field( (string) ( $this->settings()['external_secret_last4'] ?? '' ) );
    }

    public function has_external_credentials(): bool {
        return '' !== $this->external_base_url() && '' !== $this->external_secret();
    }

    public function external_headers( string $request_id = '', string $origin = '' ): array {
        $headers = array();
        $secret  = $this->external_secret();
        if ( '' !== $secret ) {
            $headers['X-Evomembers-Key'] = $secret;
        }
        $client_id = $this->external_client_id();
        if ( '' !== $client_id ) {
            $headers['X-Evomembers-Client-ID'] = $client_id;
        }
        if ( '' !== $request_id ) {
            $headers['X-Evomembers-Request-ID'] = sanitize_text_field( $request_id );
        }
        if ( '' !== $origin ) {
            $headers['X-Evomembers-Origin'] = sanitize_text_field( $origin );
        }
        $headers['X-Evomembers-Node'] = self::node_id();
        return $headers;
    }

    public static function node_id(): string {
        return 'evomembers_' . substr( hash( 'sha256', strtolower( untrailingslashit( home_url( '/' ) ) ) ), 0, 20 );
    }

    public static function encrypt_external_secret( string $secret ): string {
        $secret = trim( $secret );
        return '' === $secret ? '' : Crypto::encrypt( $secret );
    }
}
