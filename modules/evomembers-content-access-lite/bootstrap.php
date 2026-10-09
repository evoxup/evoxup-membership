<?php

defined( 'ABSPATH' ) || exit;

final class EvoMembersContentAccessLite implements \EvoMembers\Contracts\ExtensionInterface {
    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        add_shortcode( 'evomembers_content_access', array( $this, 'shortcode' ) );
    }
    public function shortcode( array $atts = array(), ?string $content = null ): string {
        $atts = shortcode_atts( array( 'capability' => '', 'message' => '' ), $atts, 'evomembers_content_access' );
        $capability = sanitize_key( (string) $atts['capability'] );
        $allowed = is_user_logged_in() && ( '' === $capability || current_user_can( $capability ) );
        if ( $allowed ) {
            return do_shortcode( wp_kses_post( (string) $content ) );
        }
        $message = sanitize_text_field( (string) $atts['message'] );
        if ( '' === $message ) {
            $message = __( 'This content is available to eligible signed-in members.', 'evoxup-membership' );
        }
        return '<div class="evomembers-lite-message">' . esc_html( $message ) . '</div>';
    }
}
