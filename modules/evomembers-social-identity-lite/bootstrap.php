<?php

defined( 'ABSPATH' ) || exit;

final class EvoMembersSocialIdentityLite implements \EvoMembers\Contracts\ExtensionInterface {
    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        add_shortcode( 'evomembers_social_identity', array( $this, 'shortcode' ) );
    }
    public function shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }
        $user = wp_get_current_user();
        $url = esc_url( (string) $user->user_url );
        $html = '<div class="evomembers-lite-identity"><strong>' . esc_html( $user->display_name ?: $user->user_login ) . '</strong>';
        if ( '' !== $url ) {
            $html .= '<br><a href="' . $url . '" rel="me noopener noreferrer">' . esc_html__( 'Profile website', 'evoxup-membership' ) . '</a>';
        }
        return $html . '</div>';
    }
}
