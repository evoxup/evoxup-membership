<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Crypto;
use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class IntegrationService {
    public function create( array $data ): int {
        global $wpdb;
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        $slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
        if ( '' === $name || '' === $slug || $this->by_slug( $slug ) ) {
            return 0;
        }

        $now = current_time( 'mysql', true );
        $payload = array(
            'name'              => $name,
            'slug'              => $slug,
            'status'            => $this->status( $data['status'] ?? 'active' ),
            'api_url'           => esc_url_raw( (string) ( $data['api_url'] ?? '' ) ) ?: null,
            'api_key_encrypted' => Crypto::encrypt( (string) ( $data['api_key'] ?? '' ) ) ?: null,
            'link_wp_user'      => ! empty( $data['link_wp_user'] ) ? 1 : 0,
            'security_mode'     => 'hmac_sha256',
            'updated_at'        => $now,
            'created_at'        => $now,
        );

        $ok = $wpdb->insert( Database::table( 'integrations' ), $payload );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;
        $current = $this->get( $id );
        if ( ! $current ) {
            return false;
        }

        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        $slug = sanitize_key( (string) ( $data['slug'] ?? '' ) );
        if ( '' === $name || '' === $slug ) {
            return false;
        }

        $duplicate = $this->by_slug( $slug );
        if ( $duplicate && (int) $duplicate['id'] !== $id ) {
            return false;
        }

        $payload = array(
            'name'           => $name,
            'slug'           => $slug,
            'status'         => $this->status( $data['status'] ?? 'active' ),
            'api_url'        => esc_url_raw( (string) ( $data['api_url'] ?? '' ) ) ?: null,
            'link_wp_user'   => ! empty( $data['link_wp_user'] ) ? 1 : 0,
            'updated_at'     => current_time( 'mysql', true ),
        );

        if ( ! empty( $data['api_key'] ) ) {
            $payload['api_key_encrypted'] = Crypto::encrypt( (string) $data['api_key'] ) ?: null;
        }

        return false !== $wpdb->update( Database::table( 'integrations' ), $payload, array( 'id' => $id ) );
    }

    /**
     * Webhook authentication belongs to the Webhooks screen. Keeping this
     * write path separate prevents editing an API integration from silently
     * resetting webhook authentication settings.
     */
    public function update_webhook_security( int $id, array $data ): bool {
        global $wpdb;
        $current = $this->get( $id );
        if ( ! $current ) {
            return false;
        }

        $settings = json_decode( (string) ( $current['settings_json'] ?? '' ), true );
        $settings = is_array( $settings ) ? $settings : array();
        $auth = isset( $settings['webhook_auth'] ) && is_array( $settings['webhook_auth'] ) ? $settings['webhook_auth'] : array();

        $payload = array(
            'security_mode'    => $this->security_mode( $data['security_mode'] ?? 'hmac_sha256' ),
            'signature_header' => sanitize_text_field( (string) ( $data['signature_header'] ?? '' ) ) ?: null,
            'allowed_ips'      => sanitize_textarea_field( (string) ( $data['allowed_ips'] ?? '' ) ) ?: null,
            'updated_at'       => current_time( 'mysql', true ),
        );

        foreach ( array(
            'secret'       => 'secret_encrypted',
            'bearer_token' => 'bearer_encrypted',
            'username'     => 'username_encrypted',
            'password'     => 'password_encrypted',
        ) as $input => $column ) {
            if ( isset( $data[ $input ] ) && '' !== trim( (string) $data[ $input ] ) ) {
                $payload[ $column ] = Crypto::encrypt( (string) $data[ $input ] ) ?: null;
            }
        }

        if ( isset( $data['webhook_api_key'] ) && '' !== trim( (string) $data['webhook_api_key'] ) ) {
            $auth['api_key_encrypted'] = Crypto::encrypt( (string) $data['webhook_api_key'] );
        }
        $auth['api_key_header'] = sanitize_text_field( (string) ( $data['webhook_api_key_header'] ?? ( $auth['api_key_header'] ?? 'X-API-Key' ) ) ) ?: 'X-API-Key';
        $settings['webhook_auth'] = $auth;
        $payload['settings_json'] = wp_json_encode( $settings );

        return false !== $wpdb->update( Database::table( 'integrations' ), $payload, array( 'id' => $id ) );
    }

    public function delete( int $id ): bool {
        global $wpdb;
        if ( $id < 1 || ! $this->get( $id ) ) {
            return false;
        }
        $wpdb->update(
            Database::table( 'integrations' ),
            array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id )
        );
        $wpdb->update(
            Database::table( 'product_mappings' ),
            array( 'status' => 'inactive', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'integration_id' => $id )
        );
        return true;
    }

    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', Database::table( 'integrations' ), $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function by_slug( string $slug ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s AND status <> %s LIMIT 1', Database::table( 'integrations' ), sanitize_key( $slug ), 'deleted' ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    public function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE status <> %s ORDER BY id DESC', Database::table( 'integrations' ), 'deleted' ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status <> %s', Database::table( 'integrations' ), 'deleted' )
        );
    }

    public function secret( array $integration ): string {
        return Crypto::decrypt( (string) ( $integration['secret_encrypted'] ?? '' ) );
    }

    public function bearer_token( array $integration ): string {
        return Crypto::decrypt( (string) ( $integration['bearer_encrypted'] ?? '' ) );
    }

    public function basic_username( array $integration ): string {
        return Crypto::decrypt( (string) ( $integration['username_encrypted'] ?? '' ) );
    }

    public function basic_password( array $integration ): string {
        return Crypto::decrypt( (string) ( $integration['password_encrypted'] ?? '' ) );
    }

    public function webhook_api_key( array $integration ): string {
        $settings = json_decode( (string) ( $integration['settings_json'] ?? '' ), true );
        $settings = is_array( $settings ) ? $settings : array();
        $auth = isset( $settings['webhook_auth'] ) && is_array( $settings['webhook_auth'] ) ? $settings['webhook_auth'] : array();
        return Crypto::decrypt( (string) ( $auth['api_key_encrypted'] ?? '' ) );
    }

    public function webhook_api_key_header( array $integration ): string {
        $settings = json_decode( (string) ( $integration['settings_json'] ?? '' ), true );
        $settings = is_array( $settings ) ? $settings : array();
        $auth = isset( $settings['webhook_auth'] ) && is_array( $settings['webhook_auth'] ) ? $settings['webhook_auth'] : array();
        return sanitize_text_field( (string) ( $auth['api_key_header'] ?? 'X-API-Key' ) ) ?: 'X-API-Key';
    }

    private function status( mixed $value ): string {
        return 'inactive' === sanitize_key( (string) $value ) ? 'inactive' : 'active';
    }

    private function security_mode( mixed $value ): string {
        $allowed = array( 'header', 'hmac_sha256', 'bearer', 'basic', 'api_key', 'ip_allowlist', 'custom' );
        $value   = sanitize_key( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : 'hmac_sha256';
    }
}
