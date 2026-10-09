<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Crypto;
use EvoMembers\Core\Database;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class RestKeyService {
    private const PERMISSIONS = array(
        'verify_license',
        'manage_licenses',
        'manage_products',
        'read_products',
        'read_webhooks',
        'write_webhooks',
        'read_customers',
    );

    public function create( array $data ): array|\WP_Error {
        global $wpdb;
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( ! $name ) {
            return new \WP_Error( 'evomembers_rest_key_name', 'Key name is required.' );
        }

        $public_id = 'evomembers_pub_' . strtolower( wp_generate_password( 18, false, false ) );
        $secret    = 'evomembers_sec_' . wp_generate_password( 48, false, false );
        $prefix    = substr( $secret, 0, 20 );
        $perms     = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $data['permissions'] ?? array() ) ), self::PERMISSIONS ) );
        if ( ! $perms ) {
            $perms = array( 'verify_license' );
        }

        $encrypted = Crypto::encrypt( $secret );
        if ( '' === $encrypted ) {
            return new \WP_Error( 'evomembers_rest_key_crypto', 'REST secret could not be encrypted safely on this server.' );
        }

        $ok = $wpdb->insert(
            Database::table( 'rest_keys' ),
            array(
                'name'               => $name,
                'public_id'          => $public_id,
                'key_prefix'         => $prefix,
                'key_hash'           => hash( 'sha256', $secret ),
                'secret_encrypted'   => $encrypted,
                'permissions_json'   => wp_json_encode( $perms ),
                'allowed_ips'        => sanitize_textarea_field( (string) ( $data['allowed_ips'] ?? '' ) ) ?: null,
                'status'             => 'active',
                'created_at'         => current_time( 'mysql', true ),
                'updated_at'         => current_time( 'mysql', true ),
            )
        );
        if ( false === $ok ) {
            return new \WP_Error( 'evomembers_rest_key_create', 'REST key could not be created.' );
        }

        return array(
            'id'        => (int) $wpdb->insert_id,
            'public_id' => $public_id,
            'secret'    => $secret,
            // Backward-compatible alias for older UI/extension code.
            'key'       => $secret,
            'prefix'    => $prefix,
        );
    }

    public function all(): array {
        global $wpdb;
        $r = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,name,public_id,key_prefix,permissions_json,allowed_ips,status,last_used_at,created_at FROM %i ORDER BY id DESC',
                Database::table( 'rest_keys' )
            ),
            ARRAY_A
        );
        return is_array( $r ) ? $r : array();
    }

    public function revoke( int $id ): bool {
        global $wpdb;
        return $id > 0 && false !== $wpdb->update(
            Database::table( 'rest_keys' ),
            array( 'status' => 'revoked', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id )
        );
    }

    /**
     * Permanently remove one REST credential.
     *
     * REST credentials are standalone authentication records; deleting one
     * does not remove licenses, memberships, products, customers, or logs.
     */
    public function delete( int $id ): bool {
        global $wpdb;
        if ( $id < 1 ) {
            return false;
        }
        return 1 === (int) $wpdb->delete(
            Database::table( 'rest_keys' ),
            array( 'id' => $id ),
            array( '%d' )
        );
    }

    public function reveal( int $id ): array|\WP_Error {
        global $wpdb;
        if ( $id < 1 ) {
            return new \WP_Error( 'evomembers_rest_key_id', 'REST credential ID is invalid.' );
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT id,name,public_id,key_prefix,secret_encrypted,status FROM %i WHERE id=%d LIMIT 1', Database::table( 'rest_keys' ), $id ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || 'active' !== sanitize_key( (string) ( $row['status'] ?? '' ) ) ) {
            return new \WP_Error( 'evomembers_rest_key_not_found', 'Active REST credential was not found.' );
        }
        $secret = Crypto::decrypt( (string) ( $row['secret_encrypted'] ?? '' ) );
        if ( '' === $secret ) {
            return new \WP_Error( 'evomembers_rest_key_unrecoverable', 'This historical REST secret cannot be decrypted. Rotate the credential instead.' );
        }
        return array(
            'id'        => (int) $row['id'],
            'name'      => sanitize_text_field( (string) $row['name'] ),
            'public_id' => sanitize_text_field( (string) ( $row['public_id'] ?? '' ) ),
            'secret'    => $secret,
        );
    }

    public function authenticate( WP_REST_Request $request, string $permission ): true|\WP_Error {
        $row = $this->credential( $request );
        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $permissions = $this->permissions_from_row( $row );
        if ( ! in_array( sanitize_key( $permission ), $permissions, true ) ) {
            return new \WP_Error( 'evomembers_rest_key_forbidden', 'This EVO REST key does not have the required permission.', array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * Authenticate a credential and expose only safe identity/permission metadata.
     */
    public function inspect( WP_REST_Request $request ): array|\WP_Error {
        $row = $this->credential( $request );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        return array(
            'id'          => (int) $row['id'],
            'name'        => sanitize_text_field( (string) $row['name'] ),
            'public_id'   => sanitize_text_field( (string) ( $row['public_id'] ?? '' ) ),
            'permissions' => $this->permissions_from_row( $row ),
            'status'      => sanitize_key( (string) $row['status'] ),
            'ip_limited'  => '' !== trim( (string) ( $row['allowed_ips'] ?? '' ) ),
            'last_used_at'=> sanitize_text_field( (string) ( $row['last_used_at'] ?? '' ) ),
        );
    }

    private function credential( WP_REST_Request $request ): array|\WP_Error {
        global $wpdb;
        $key = trim( (string) $request->get_header( 'x-evomembers-key' ) );
        if ( '' === $key ) {
            $auth = trim( (string) $request->get_header( 'authorization' ) );
            if ( 0 === stripos( $auth, 'Bearer ' ) ) {
                $key = trim( substr( $auth, 7 ) );
            }
        }
        if ( '' === $key ) {
            return new \WP_Error( 'evomembers_rest_key_missing', 'EVO REST secret is required.', array( 'status' => 401 ) );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE key_hash=%s AND status=%s LIMIT 1',
                Database::table( 'rest_keys' ),
                hash( 'sha256', $key ),
                'active'
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) {
            return new \WP_Error( 'evomembers_rest_key_invalid', 'EVO REST secret is invalid or revoked.', array( 'status' => 401 ) );
        }

        $sent_public_id = sanitize_text_field( (string) $request->get_header( 'x-evomembers-client-id' ) );
        $stored_public_id = sanitize_text_field( (string) ( $row['public_id'] ?? '' ) );
        if ( '' !== $sent_public_id && '' !== $stored_public_id && ! hash_equals( $stored_public_id, $sent_public_id ) ) {
            return new \WP_Error( 'evomembers_rest_client_id_mismatch', 'EVO REST public/client ID does not match this secret.', array( 'status' => 401 ) );
        }

        $remote = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
        $rules  = preg_split( '/[\s,]+/', (string) ( $row['allowed_ips'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
        if ( $rules && ! $this->ip_allowed( $remote, $rules ) ) {
            return new \WP_Error( 'evomembers_rest_ip_forbidden', 'This server IP is not allowed for this EVO REST key.', array( 'status' => 403 ) );
        }

        $wpdb->update(
            Database::table( 'rest_keys' ),
            array( 'last_used_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => (int) $row['id'] )
        );
        return $row;
    }

    private function permissions_from_row( array $row ): array {
        $permissions = json_decode( (string) ( $row['permissions_json'] ?? '' ), true );
        return is_array( $permissions ) ? array_values( array_intersect( array_map( 'sanitize_key', $permissions ), self::PERMISSIONS ) ) : array();
    }

    private function ip_allowed( string $ip, array $rules ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $rule = trim( (string) $rule );
            if ( $rule === $ip ) {
                return true;
            }
            if ( false === strpos( $rule, '/' ) ) {
                continue;
            }
            [ $network, $bits ] = array_pad( explode( '/', $rule, 2 ), 2, '' );
            $ip_bin  = @inet_pton( $ip );
            $net_bin = @inet_pton( $network );
            if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) || ! ctype_digit( (string) $bits ) ) {
                continue;
            }
            $bits      = (int) $bits;
            $max_bits  = strlen( $ip_bin ) * 8;
            if ( $bits < 0 || $bits > $max_bits ) {
                continue;
            }
            $bytes     = intdiv( $bits, 8 );
            $remainder = $bits % 8;
            if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
                continue;
            }
            if ( $remainder ) {
                $mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;
                if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
