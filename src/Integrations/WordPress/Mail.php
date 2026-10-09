<?php
namespace EvoMembers\Integrations\WordPress;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use EvoMembers\Services\CustomerService;
use EvoMembers\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

final class Mail {
    public function boot(): void {
        add_action( 'evoxup_message_created', array( $this, 'deliver_message' ), 10, 2 );
        // License issuance is event-driven. WordPress mail remains the delivery
        // layer; WooCommerce-sourced licenses are intentionally left to the
        // WooCommerce customer-email flow.
        add_action( 'evoxup_license_issued', array( $this, 'license_issued' ), 20, 4 );
        add_action( 'evoxup_license_group_issued', array( $this, 'license_group_issued' ), 20, 2 );
    }


    public function license_group_issued( array $licenses, array $args ): void {
        try {
            $service = new LicenseService();
            if ( $service->send_customer_group_email( $licenses, $args ) ) {
                $service->mark_notified( array_values( array_filter( array_map( static fn( $row ): int => absint( is_array( $row ) ? ( $row['id'] ?? 0 ) : 0 ), $licenses ) ) ) );
            }
        } catch ( \Throwable $throwable ) {
            // Mail delivery must never roll back license issuance/webhook processing.
            // The unnotified rows remain recoverable on Replay.
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort mail transport diagnostics.
                sprintf( 'Evoxup license-group email failed: %s', sanitize_text_field( $throwable->getMessage() ) )
            );
        }
    }

    public function license_issued( int $license_id, int $customer_id, int $product_id, string $plain_key ): void {
        unset( $customer_id, $product_id, $plain_key );
        try {
            $service = new LicenseService();
            if ( $service->send_customer_email( $license_id ) ) {
                $service->mark_notified( array( $license_id ) );
            }
        } catch ( \Throwable $throwable ) {
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- last-resort mail transport diagnostics.
                sprintf( 'Evoxup license email failed: %s', sanitize_text_field( $throwable->getMessage() ) )
            );
        }
    }

    public function deliver_message( int $message_id, array $message ): void {
        $channel = sanitize_key( (string) ( $message['channel'] ?? 'in_app' ) );
        if ( ! in_array( $channel, array( 'email', 'both' ), true ) ) {
            return;
        }

        try {
            $customer = ( new CustomerService() )->get( absint( $message['customer_id'] ?? 0 ) );
            if ( ! $customer || ! is_email( (string) ( $customer['email'] ?? '' ) ) ) {
                $this->update_status( $message_id, 'failed', null );
                return;
            }

            $to          = (string) $customer['email'];
            $subject     = apply_filters( 'evoxup_mail_subject', (string) ( $message['subject'] ?? '' ), $message, $customer );
            $body        = apply_filters( 'evoxup_mail_body', (string) ( $message['message'] ?? '' ), $message, $customer );
            $headers     = apply_filters( 'evoxup_mail_headers', array(), $message, $customer );
            $attachments = apply_filters( 'evoxup_mail_attachments', array(), $message, $customer );

            /**
             * Fires immediately before WordPress sends an EVO email.
             * Mail/SMTP/template plugins can use WordPress hooks or the filters above.
             */
            do_action( 'evoxup_before_mail', $to, $subject, $body, $headers, $attachments, $message, $customer );
            $sent = wp_mail( $to, $subject, $body, $headers, $attachments );
            $this->update_status( $message_id, $sent ? 'sent' : 'failed', $sent ? current_time( 'mysql', true ) : null );
            do_action( 'evoxup_after_mail', $message_id, $sent, $message, $customer );
        } catch ( \Throwable $throwable ) {
            // A broken SMTP/template hook must not abort checkout/webhook fulfillment.
            $this->update_status( $message_id, 'failed', null );
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate transport fallback.
                sprintf( 'Evoxup mail delivery failed: %s', sanitize_text_field( $throwable->getMessage() ) )
            );
        }
    }

    private function update_status( int $message_id, string $status, ?string $sent_at ): void {
        global $wpdb;
        $now    = current_time( 'mysql', true );
        $status = sanitize_key( $status );

        if ( 'sent' === $status && $sent_at ) {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET status=%s,sent_at=%s,delivered_at=%s,attempts=attempts+1,last_error=NULL,updated_at=%s WHERE id=%d',
                    Database::table( 'messages' ),
                    'sent',
                    $sent_at,
                    $sent_at,
                    $now,
                    $message_id
                )
            );
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET status=%s,attempts=attempts+1,last_error=%s,updated_at=%s WHERE id=%d',
                Database::table( 'messages' ),
                'failed',
                __( 'WordPress mail transport did not confirm delivery.', 'evoxup-membership' ),
                $now,
                $message_id
            )
        );
    }
}
