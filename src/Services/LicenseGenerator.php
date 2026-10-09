<?php
namespace EvoMembers\Services;

defined( 'ABSPATH' ) || exit;

final class LicenseGenerator {
    public function generate( array $overrides = array() ): string {
        $overrides = array_filter( $overrides, static function ( $value ): bool { return '' !== $value && null !== $value && 0 !== $value && '0' !== $value; } );
        $settings = get_option( 'evomembers_license_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $cfg = wp_parse_args( $overrides, $settings + array(
            'mode' => 'alnum', 'prefix' => 'EVO', 'segments' => 3, 'segment_length' => 10, 'separator' => '-',
        ) );
        $mode = in_array( $cfg['mode'], array( 'alnum', 'hex', 'numeric', 'uuid' ), true ) ? $cfg['mode'] : 'alnum';
        $prefix = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $cfg['prefix'] ) );
        $separator = in_array( (string) $cfg['separator'], array( '-', '_', '.' ), true ) ? (string) $cfg['separator'] : '-';
        if ( 'uuid' === $mode ) {
            $body = strtoupper( wp_generate_uuid4() );
            return '' !== $prefix ? $prefix . $separator . $body : $body;
        }
        $segments = min( 8, max( 1, absint( $cfg['segments'] ) ) );
        $length = min( 32, max( 4, absint( $cfg['segment_length'] ) ) );
        $parts = array();
        for ( $i = 0; $i < $segments; $i++ ) {
            $parts[] = $this->part( $mode, $length );
        }
        $key = implode( $separator, $parts );
        return '' !== $prefix ? $prefix . $separator . $key : $key;
    }

    private function part( string $mode, int $length ): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ23456789';
        if ( 'numeric' === $mode ) { $alphabet = '0123456789'; }
        if ( 'hex' === $mode ) { return strtoupper( substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length ) ); }
        $out=''; $max=strlen($alphabet)-1;
        for($i=0;$i<$length;$i++){ $out .= $alphabet[random_int(0,$max)]; }
        return $out;
    }
}
