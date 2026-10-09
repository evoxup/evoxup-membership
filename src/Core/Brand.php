<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

final class Brand {
    public static function menu_icon(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0877ff"/><stop offset=".52" stop-color="#0b4da5"/><stop offset="1" stop-color="#e11d48"/></linearGradient></defs><path d="M32 4 56 17v22L32 60 8 39V17z" fill="url(#g)"/><circle cx="32" cy="22" r="7" fill="#fff"/><path d="M19 43c1-9 6-14 13-14s12 5 13 14" fill="#fff"/><path d="M17 38h30v12H17z" rx="4" fill="#071a3d"/><path d="M25 44h14" stroke="#fff" stroke-width="4" stroke-linecap="round"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    public static function logo_markup(): string {
        $src = defined( 'EVOMEMBERS_URL' ) ? EVOMEMBERS_URL . 'assets/branding/evoxup-membership-icon.png' : '';
        if ( '' !== $src ) {
            return '<span class="evo-brand-mark" aria-hidden="true"><img src="' . esc_url( $src ) . '" alt=""></span>';
        }
        return '<span class="evo-brand-mark evo-brand-mark-fallback" aria-hidden="true"><span class="evo-brand-e">E</span></span>';
    }

    public static function page_icon( string $title ): string {
        $map = array(
            'dashboard'=>'dashicons-chart-area','customers'=>'dashicons-groups','membership'=>'dashicons-id-alt',
            'products'=>'dashicons-products','licenses'=>'dashicons-admin-network','integrations'=>'dashicons-admin-plugins',
            'webhooks'=>'dashicons-randomize','modules'=>'dashicons-screenoptions','marketplace'=>'dashicons-store','settings'=>'dashicons-admin-generic',
        );
        $key = sanitize_key( $title );
        return $map[ $key ] ?? 'dashicons-shield';
    }
}
