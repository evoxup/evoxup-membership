<?php
namespace EvoMembers\Services;
defined( 'ABSPATH' ) || exit;
final class GeneratorProfileService {
    private const OPTION = 'evomembers_license_generators';
    public function all(): array {
        $profiles = get_option( self::OPTION, array() );
        $profiles = is_array( $profiles ) ? $profiles : array();
        if ( empty( $profiles ) ) {
            $profiles = array( 'default' => $this->default_profile() );
            update_option( self::OPTION, $profiles, false );
        }
        return $profiles;
    }
    public function get( string $id ): ?array {
        $id = sanitize_key( $id );
        $all = $this->all();
        return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
    }
    public function active_options(): array {
        $out = array();
        foreach ( $this->all() as $id => $profile ) {
            if ( 'active' === ( $profile['status'] ?? 'active' ) ) { $out[ $id ] = (string) ( $profile['name'] ?? $id ); }
        }
        return $out;
    }
    public function save( string $id, array $data ): string {
        $id = sanitize_key( $id ?: (string) ( $data['name'] ?? 'generator' ) );
        if ( '' === $id ) { $id = 'generator'; }
        $profiles = $this->all();
        $mode = sanitize_key( (string) ( $data['mode'] ?? 'alnum' ) );
        $profiles[ $id ] = array(
            'id' => $id,
            'name' => sanitize_text_field( (string) ( $data['name'] ?? ucfirst( $id ) ) ),
            'mode' => in_array( $mode, array( 'alnum', 'hex', 'numeric', 'uuid' ), true ) ? $mode : 'alnum',
            'prefix' => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $data['prefix'] ?? 'EVO' ) ) ),
            'segments' => min( 8, max( 1, absint( $data['segments'] ?? 3 ) ) ),
            'segment_length' => min( 32, max( 4, absint( $data['segment_length'] ?? 10 ) ) ),
            'separator' => in_array( (string) ( $data['separator'] ?? '-' ), array( '-', '_', '.' ), true ) ? (string) $data['separator'] : '-',
            'status' => 'inactive' === sanitize_key( (string) ( $data['status'] ?? 'active' ) ) ? 'inactive' : 'active',
        );
        update_option( self::OPTION, $profiles, false );
        return $id;
    }
    public function delete( string $id ): bool {
        $id = sanitize_key( $id );
        if ( 'default' === $id ) { return false; }
        $profiles = $this->all();
        if ( ! isset( $profiles[ $id ] ) ) { return false; }
        unset( $profiles[ $id ] );
        update_option( self::OPTION, $profiles, false );
        return true;
    }
    private function default_profile(): array {
        $legacy = get_option( 'evomembers_license_settings', array() );
        $legacy = is_array( $legacy ) ? $legacy : array();
        $mode = sanitize_key( (string) ( $legacy['mode'] ?? 'alnum' ) );
        return array(
            'id' => 'default', 'name' => 'Default EVO Generator',
            'mode' => in_array( $mode, array( 'alnum', 'hex', 'numeric', 'uuid' ), true ) ? $mode : 'alnum',
            'prefix' => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $legacy['prefix'] ?? 'EVO' ) ) ),
            'segments' => min( 8, max( 1, absint( $legacy['segments'] ?? 3 ) ) ),
            'segment_length' => min( 32, max( 4, absint( $legacy['segment_length'] ?? 10 ) ) ),
            'separator' => in_array( (string) ( $legacy['separator'] ?? '-' ), array( '-', '_', '.' ), true ) ? (string) $legacy['separator'] : '-',
            'status' => 'active',
        );
    }
}
