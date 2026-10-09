<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

final class Crypto {
    private static function key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|evomembers', true );
    }

    private static function legacy_key(): string {
        return hash( 'sha256', wp_salt( 'auth' ) . '|evo-members', true );
    }

    public static function encrypt( string $plain ): string {
        if ( '' === $plain ) {
            return '';
        }

        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
            return 'sodium:' . base64_encode( $nonce . $cipher );
        }

        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv     = random_bytes( 12 );
            $tag    = '';
            $cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
            if ( false !== $cipher ) {
                return 'openssl:' . base64_encode( $iv . $tag . $cipher );
            }
        }

        return '';
    }

    public static function decrypt( string $stored ): string {
        if ( '' === $stored ) {
            return '';
        }

        foreach ( array( self::key(), self::legacy_key() ) as $key ) {
            if ( 0 === strpos( $stored, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
                $raw = base64_decode( substr( $stored, 7 ), true );
                if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                    return '';
                }
                $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
                if ( false !== $plain ) {
                    return $plain;
                }
                continue;
            }

            if ( 0 === strpos( $stored, 'openssl:' ) && function_exists( 'openssl_decrypt' ) ) {
                $raw = base64_decode( substr( $stored, 8 ), true );
                if ( false === $raw || strlen( $raw ) <= 28 ) {
                    return '';
                }
                $iv     = substr( $raw, 0, 12 );
                $tag    = substr( $raw, 12, 16 );
                $cipher = substr( $raw, 28 );
                $plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
                if ( false !== $plain ) {
                    return $plain;
                }
            }
        }

        return '';
    }
}
