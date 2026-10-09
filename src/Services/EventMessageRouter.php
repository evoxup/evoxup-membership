<?php
namespace EvoMembers\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Converts canonical domain events into member-facing messages.
 *
 * Templates are global by default and may be overridden through the
 * evoxup_membership_event_message_template filter by products, plans or modules.
 */
final class EventMessageRouter {
    public function boot(): void {
        add_action( 'evomembers_event', array( $this, 'route' ), 20, 5 );
    }

    public function route( string $event, array $payload, int $event_id, int $customer_id, string $source ): void {
        unset( $event_id );
        if ( $customer_id < 1 ) { return; }

        // A multi-seat issue emits one event per independent key. One grouped
        // member message is enough and avoids flooding the inbox.
        if ( 'license.issued' === $event && absint( $payload['seat_total'] ?? 1 ) > 1 ) { return; }

        $template = $this->template( $event, $payload );
        if ( ! $template ) { return; }
        $template = \EvoMembers\Core\Hooks::apply_filters( 'event_message_template', $template, $event, $payload, $customer_id, $source );
        if ( ! is_array( $template ) || empty( $template['subject'] ) || empty( $template['message'] ) ) { return; }

        $channel = sanitize_key( (string) get_option( 'evomembers_event_message_channel', 'in_app' ) );
        if ( ! in_array( $channel, array( 'in_app', 'email', 'both' ), true ) ) { $channel = 'in_app'; }
        // WooCommerce remains owner of its customer emails. EVO still creates
        // the in-app message, but will not duplicate WooCommerce email delivery.
        if ( 'woocommerce' === $source && in_array( $channel, array( 'email', 'both' ), true ) ) { $channel = 'in_app'; }

        ( new MessageService() )->create(
            $customer_id,
            sanitize_text_field( (string) $template['subject'] ),
            sanitize_textarea_field( (string) $template['message'] ),
            $channel
        );
    }

    private function template( string $event, array $payload ): ?array {
        if ( 'membership.created' === $event ) {
            $membership = ( new MembershipService() )->get( absint( $payload['membership_id'] ?? 0 ) );
            $plan = $membership['plan_name'] ?? __( 'membership', 'evoxup-membership' );
            /* translators: %s: membership plan name. */
            $subject = sprintf( __( 'Your %s membership is active', 'evoxup-membership' ), $plan );
            /* translators: 1: membership plan name, 2: membership status, 3: expiration date or Lifetime. */
            $message = sprintf( __( 'Your membership plan %1$s has been created. Status: %2$s. Expiration: %3$s.', 'evoxup-membership' ), $plan, (string) ( $payload['status'] ?? 'active' ), (string) ( $payload['expires_at'] ?? __( 'Lifetime', 'evoxup-membership' ) ) );
            return array( 'subject' => $subject, 'message' => $message );
        }
        if ( 'membership.renewed' === $event ) {
            $membership = ( new MembershipService() )->get( absint( $payload['membership_id'] ?? 0 ) );
            $plan = $membership['plan_name'] ?? __( 'membership', 'evoxup-membership' );
            /* translators: %s: membership plan name. */
            $subject = sprintf( __( '%s membership renewed', 'evoxup-membership' ), $plan );
            /* translators: 1: membership plan name, 2: new expiration date or Lifetime. */
            $message = sprintf( __( 'Your %1$s membership has been renewed. Existing license keys were preserved. New expiration: %2$s.', 'evoxup-membership' ), $plan, (string) ( $payload['expires_at'] ?? __( 'Lifetime', 'evoxup-membership' ) ) );
            return array( 'subject' => $subject, 'message' => $message );
        }
        if ( 'membership.status_changed' === $event ) {
            /* translators: 1: previous membership status, 2: new membership status. */
            $message = sprintf( __( 'Your membership status changed from %1$s to %2$s.', 'evoxup-membership' ), (string) ( $payload['from'] ?? '' ), (string) ( $payload['to'] ?? '' ) );
            return array( 'subject' => __( 'Membership status changed', 'evoxup-membership' ), 'message' => $message );
        }
        if ( 'license.issued' === $event ) {
            $total = max( 1, absint( $payload['seat_total'] ?? 1 ) );
            /* translators: %d: number of independent license keys. */
            $message = sprintf( _n( '%d independent license key has been issued. Open My Membership to view and activate it.', '%d independent license keys have been issued. Open My Membership to view and activate them.', $total, 'evoxup-membership' ), $total );
            return array(
                'subject' => _n( 'Your EVO license key is ready', 'Your EVO license keys are ready', $total, 'evoxup-membership' ),
                'message' => $message,
            );
        }
        if ( 'license.external_registered' === $event ) {
            $total = max( 1, absint( $payload['seat_total'] ?? 1 ) );
            /* translators: %d: number of external license keys. */
            $message = sprintf( _n( '%d external license key has been recorded. Open My Membership to view it.', '%d external license keys have been recorded. Open My Membership to view them.', $total, 'evoxup-membership' ), $total );
            return array(
                'subject' => _n( 'Your external license is ready', 'Your external licenses are ready', $total, 'evoxup-membership' ),
                'message' => $message,
            );
        }
        if ( 'license.status_changed' === $event ) {
            /* translators: %s: new license status. */
            $message = sprintf( __( 'The status of one of your EVO licenses is now: %s.', 'evoxup-membership' ), (string) ( $payload['status'] ?? '' ) );
            return array( 'subject' => __( 'License status changed', 'evoxup-membership' ), 'message' => $message );
        }
        if ( 'order.created' === $event ) {
            return array(
                'subject' => __( 'Order received', 'evoxup-membership' ),
                'message' => __( 'Your order has been recorded by EVO. Memberships, licenses and downloads linked to the order will appear in My Membership.', 'evoxup-membership' ),
            );
        }
        return null;
    }}
