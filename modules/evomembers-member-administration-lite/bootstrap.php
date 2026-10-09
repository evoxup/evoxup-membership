<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/MemberAdministration.php';

final class EvoMembersMemberAdministrationLite implements \EvoMembers\Contracts\ExtensionInterface {
    private ?\EvoMembers\Admin\MemberAdministration $workspace = null;

    public function boot( \EvoMembers\Core\ExtensionContext $context ): void {
        $context->add_action( 'admin_menu', array( $this, 'menu' ), 20, 0 );
        $context->add_action( 'admin_init', array( $this, 'actions' ), 20, 0 );
        $context->add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 20, 1 );
    }

    public function menu(): void {
        add_submenu_page(
            'evomembers-members',
            __( 'Customers', 'evoxup-membership' ),
            __( 'Customers', 'evoxup-membership' ),
            \EvoMembers\Core\Capabilities::CUSTOMERS,
            'evomembers-customers',
            array( $this, 'render' )
        );
    }

    public function actions(): void {
        $this->workspace()->actions();
    }

    public function assets( string $hook ): void {
        $this->workspace()->assets( $hook );
    }

    public function render(): void {
        $this->workspace()->render( false );
    }

    private function workspace(): \EvoMembers\Admin\MemberAdministration {
        if ( null === $this->workspace ) {
            $this->workspace = new \EvoMembers\Admin\MemberAdministration();
        }
        return $this->workspace;
    }
}
