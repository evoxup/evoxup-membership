<?php

defined( 'ABSPATH' ) || exit;

final class EvoMembersAccountLite implements \EvoMembers\Contracts\ExtensionInterface {
    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        add_shortcode( 'evomembers_account_lite', array( $this, 'shortcode' ) );
    }
    public function shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="evomembers-lite-message">' . esc_html__( 'Please sign in to view your account.', 'evoxup-membership' ) . '</div>';
        }
        $user = wp_get_current_user();
        $name = $user->display_name ?: $user->user_login;
        $profile_url = get_edit_profile_url( $user->ID );
        return '<section class="evomembers-lite-account"><h3>' . esc_html( $name ) . '</h3><p>' . esc_html( $user->user_email ) . '</p><p><a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Edit profile', 'evoxup-membership' ) . '</a></p></section>';
    }
}
