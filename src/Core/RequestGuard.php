<?php
namespace EvoMembers\Core;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Shared abuse-resistance guard for public EVO endpoints.
 *
 * Privacy rule: no raw IP address is persisted. Rate-limit buckets use an
 * HMAC fingerprint derived from REMOTE_ADDR and the WordPress auth salt.
 */
final class RequestGuard {
    private const DEFAULT_LICENSE_LIMIT = 90;
    private const DEFAULT_WEBHOOK_LIMIT = 240;
    private const DEFAULT_WINDOW_SECONDS = 60;
    private const DEFAULT_WEBHOOK_MAX_BYTES = 1048576; // 1 MiB.

    public static function license_request( WP_REST_Request $request ): true|WP_Error {
        unset( $request );
        return self::rate_limit( 'license', self::license_limit(), self::DEFAULT_WINDOW_SECONDS );
    }

    public static function webhook_request( WP_REST_Request $request ): true|WP_Error {
        $size = self::request_size( $request );
        $max = self::webhook_max_bytes();
        if ( $size > $max ) {
            self::record_block( 'payload_too_large' );
            return new WP_Error(
                'evomembers_request_too_large',
                __( 'Webhook request body exceeds the allowed size.', 'evoxup-membership' ),
                array( 'status' => 413, 'max_bytes' => $max )
            );
        }
        return self::rate_limit( 'webhook', self::webhook_limit(), self::DEFAULT_WINDOW_SECONDS );
    }

    /** Direct endpoint preflight, before php://input is read into memory. */
    public static function direct_webhook_request(): true|WP_Error {
        $length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( (string) $_SERVER['CONTENT_LENGTH'] ) ) : 0;
        $max = self::webhook_max_bytes();
        if ( $length > $max ) {
            self::record_block( 'payload_too_large' );
            return new WP_Error(
                'evomembers_request_too_large',
                __( 'Webhook request body exceeds the allowed size.', 'evoxup-membership' ),
                array( 'status' => 413, 'max_bytes' => $max )
            );
        }
        return self::rate_limit( 'webhook', self::webhook_limit(), self::DEFAULT_WINDOW_SECONDS );
    }

    /** Defense in depth for callers that reach WebhookService directly. */
    public static function validate_webhook_body( WP_REST_Request $request ): true|WP_Error {
        $size = self::request_size( $request );
        $max = self::webhook_max_bytes();
        if ( $size <= $max ) {
            return true;
        }
        self::record_block( 'payload_too_large' );
        return new WP_Error( 'evomembers_request_too_large', __( 'Webhook request body exceeds the allowed size.', 'evoxup-membership' ), array( 'status' => 413, 'max_bytes' => $max ) );
    }

    public static function register_security_source( array $sources ): array {
        $sources[] = array(
            'id'          => 'core_security',
            'label'       => __( 'Evoxup Core Security', 'evoxup-membership' ),
            'description' => __( 'Public endpoint abuse resistance and privacy-safe request guards.', 'evoxup-membership' ),
            'priority'    => 5,
            'capability'  => 'evomembers_view_diagnostics',
            'callback'    => array( self::class, 'security_checks' ),
        );
        return $sources;
    }

    public static function security_checks(): array {
        $stats = self::stats();
        return array(
            array(
                'id'       => 'core_public_license_throttle',
                'category' => 'abuse_resistance',
                'label'    => __( 'Public license endpoint throttling', 'evoxup-membership' ),
                'status'   => self::license_limit() > 0 ? 'pass' : 'fail',
                'summary'  => sprintf(
                    /* translators: %d: maximum license API requests allowed per minute for one privacy-safe client fingerprint. */
                    __( 'License verify/activate/deactivate requests are limited to %d requests per minute per privacy-safe client fingerprint.', 'evoxup-membership' ),
                    self::license_limit()
                ),
                'evidence' => sprintf( 'blocked_recent=%d', absint( $stats['rate_limited'] ?? 0 ) ),
            ),
            array(
                'id'       => 'core_webhook_payload_guard',
                'category' => 'input_limits',
                'label'    => __( 'Webhook request-size guard', 'evoxup-membership' ),
                'status'   => self::webhook_max_bytes() >= 65536 ? 'pass' : 'fail',
                'summary'  => sprintf(
                    /* translators: %d: maximum accepted webhook request body size in bytes. */
                    __( 'Webhook bodies larger than %d bytes are rejected before persistence.', 'evoxup-membership' ),
                    self::webhook_max_bytes()
                ),
                'evidence' => sprintf( 'blocked_recent=%d', absint( $stats['payload_too_large'] ?? 0 ) ),
            ),
            array(
                'id'       => 'core_webhook_throttle',
                'category' => 'abuse_resistance',
                'label'    => __( 'Webhook intake throttling', 'evoxup-membership' ),
                'status'   => self::webhook_limit() > 0 ? 'pass' : 'fail',
                'summary'  => sprintf(
                    /* translators: %d: maximum webhook requests allowed per minute for one privacy-safe client fingerprint. */
                    __( 'Webhook intake is limited to %d requests per minute per privacy-safe client fingerprint.', 'evoxup-membership' ),
                    self::webhook_limit()
                ),
                'evidence' => 'raw_ip_storage=false',
            ),
            array(
                'id'       => 'core_security_fingerprint_privacy',
                'category' => 'privacy',
                'label'    => __( 'Security fingerprint privacy', 'evoxup-membership' ),
                'status'   => function_exists( 'hash_hmac' ) ? 'pass' : 'fail',
                'summary'  => __( 'Abuse-control buckets use HMAC fingerprints and never persist raw client IP addresses.', 'evoxup-membership' ),
                'evidence' => 'HMAC-SHA256',
            ),
        );
    }

    private static function rate_limit( string $scope, int $limit, int $window ): true|WP_Error {
        if ( $limit < 1 ) {
            self::record_block( 'rate_limit_misconfigured' );
            return new WP_Error( 'evomembers_rate_limit_unavailable', __( 'Request rate-limit policy is unavailable.', 'evoxup-membership' ), array( 'status' => 503 ) );
        }
        $window = max( 10, $window );
        $bucket = (int) floor( time() / $window );
        $fingerprint = self::client_fingerprint();
        $key = 'evomembers_rg_' . substr( hash( 'sha256', $scope . '|' . $bucket . '|' . $fingerprint ), 0, 40 );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            self::record_block( 'rate_limited' );
            $retry = max( 1, ( ( $bucket + 1 ) * $window ) - time() );
            return new WP_Error(
                'evomembers_rate_limited',
                __( 'Too many requests. Try again shortly.', 'evoxup-membership' ),
                array( 'status' => 429, 'retry_after' => $retry )
            );
        }
        set_transient( $key, $count + 1, $window + 5 );
        return true;
    }

    private static function client_fingerprint(): string {
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
            $remote = 'unknown';
        }
        return hash_hmac( 'sha256', $remote, wp_salt( 'auth' ) );
    }

    private static function request_size( WP_REST_Request $request ): int {
        $header = absint( $request->get_header( 'content-length' ) );
        $body = (string) $request->get_body();
        return max( $header, strlen( $body ) );
    }

    private static function license_limit(): int {
        return max( 1, (int) apply_filters( 'evomembers_security_license_rate_limit', self::DEFAULT_LICENSE_LIMIT ) );
    }

    private static function webhook_limit(): int {
        return max( 1, (int) apply_filters( 'evomembers_security_webhook_rate_limit', self::DEFAULT_WEBHOOK_LIMIT ) );
    }

    public static function webhook_max_bytes(): int {
        return max( 65536, (int) apply_filters( 'evomembers_security_webhook_max_bytes', self::DEFAULT_WEBHOOK_MAX_BYTES ) );
    }

    private static function stats(): array {
        $stats = get_option( 'evomembers_security_guard_stats', array() );
        return is_array( $stats ) ? $stats : array();
    }

    private static function record_block( string $reason ): void {
        $reason = sanitize_key( $reason );
        $stats = self::stats();
        $stats[ $reason ] = absint( $stats[ $reason ] ?? 0 ) + 1;
        $stats['last_blocked_at'] = time();
        if ( false === get_option( 'evomembers_security_guard_stats', false ) ) {
            add_option( 'evomembers_security_guard_stats', $stats, '', false );
        } else {
            update_option( 'evomembers_security_guard_stats', $stats, false );
        }
    }
}
