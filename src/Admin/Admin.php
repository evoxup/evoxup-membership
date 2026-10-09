<?php
namespace EvoMembers\Admin;

use EvoMembers\API\ApiConfig;
use EvoMembers\Core\Installer;
use EvoMembers\Core\Capabilities;
use EvoMembers\Core\Brand;
use EvoMembers\Core\Crypto;
use EvoMembers\Core\ModuleLoader;
use EvoMembers\Core\RequiredComponents;
use EvoMembers\Services\CustomerService;
use EvoMembers\Services\IntegrationService;
use EvoMembers\Services\LicenseService;
use EvoMembers\Services\LicenseTypeService;
use EvoMembers\Services\RestKeyService;
use EvoMembers\Services\AuthorityRouter;
use EvoMembers\Services\GeneratorProfileService;
use EvoMembers\Services\MappingService;
use EvoMembers\Services\MembershipService;
use EvoMembers\Services\MessageService;
use EvoMembers\Services\NotificationService;
use EvoMembers\Services\OrderService;
use EvoMembers\Services\ProductService;
use EvoMembers\Services\PlanService;
use EvoMembers\Services\PurchaseLinkService;
use EvoMembers\Services\EventBus;
use EvoMembers\Services\WebhookService;
use EvoMembers\Services\AuditTrail;
use EvoMembers\Services\WooCommerceService;
use EvoMembers\Frontend\CenterSettings;

defined( 'ABSPATH' ) || exit;

final class Admin {
    private bool $audit_recorded = false;

    public function boot(): void {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'actions' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }

    public function menu(): void {
        add_menu_page( 'Evoxup', 'Evoxup', Capabilities::DASHBOARD, 'evomembers-members', array( $this, 'dashboard' ), Brand::menu_icon(), 56 );
        add_submenu_page( 'evomembers-members', 'Dashboard', 'Dashboard', Capabilities::DASHBOARD, 'evomembers-members', array( $this, 'dashboard' ) );
        add_submenu_page( 'evomembers-members', 'Memberships', 'Memberships', Capabilities::MEMBERSHIPS, 'evomembers-membership', array( $this, 'membership' ) );
        add_submenu_page( 'evomembers-members', 'Products', 'Products', Capabilities::PRODUCTS, 'evomembers-products', array( $this, 'products' ) );
        add_submenu_page( 'evomembers-members', 'Orders', 'Orders', Capabilities::ORDERS, 'evomembers-orders', array( $this, 'orders' ) );
        add_submenu_page( 'evomembers-members', 'Licenses', 'Licenses', Capabilities::LICENSES, 'evomembers-licenses', array( $this, 'licenses' ) );
        add_submenu_page( 'evomembers-members', 'Integrations', 'Integrations', Capabilities::INTEGRATIONS, 'evomembers-integrations', array( $this, 'integrations' ) );
        add_submenu_page( 'evomembers-members', 'Webhooks', 'Webhooks', Capabilities::WEBHOOKS, 'evomembers-webhooks', array( $this, 'webhooks' ) );
        add_submenu_page( 'evomembers-members', 'Extensions', 'Extensions', Capabilities::MODULES, 'evomembers-modules', array( $this, 'modules' ) );
        add_submenu_page( 'evomembers-members', 'Access & Roles', 'Access & Roles', Capabilities::ACCESS, 'evomembers-access', array( $this, 'access' ) );
        add_submenu_page( 'evomembers-members', 'Settings', 'Settings', Capabilities::SETTINGS, 'evomembers-settings', array( $this, 'settings' ) );
    }

    public function assets( string $hook ): void {
        if ( false === strpos( $hook, 'evomembers-' ) ) {
            return;
        }
        wp_enqueue_style( 'evoxup-membership-admin', EVOMEMBERS_URL . 'assets/admin.css', array(), EVOMEMBERS_VERSION );
        wp_enqueue_script( 'evoxup-membership-admin', EVOMEMBERS_URL . 'assets/admin.js', array( 'jquery' ), EVOMEMBERS_VERSION, true );
        if ( false !== strpos( $hook, 'evomembers-products' ) ) {
            wp_enqueue_media();
        }
    }

    public function actions(): void {
        $method = strtoupper( (string) filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        if ( 'POST' !== $method ) {
            return;
        }

        $action = sanitize_key( (string) filter_input( INPUT_POST, 'evomembers_action', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        if ( '' === $action ) {
            return;
        }
        $required_capability = $this->required_capability( $action );
        if ( ! current_user_can( $required_capability ) ) {
            AuditTrail::denied(
                'admin.' . $action,
                'admin_action',
                '',
                array( 'required_capability' => $required_capability, 'category' => 'security' )
            );
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'evoxup-membership' ) );
        }
        check_admin_referer( 'evomembers_admin_action', 'evomembers_nonce' );

        $post         = $this->sanitize_request_value( wp_unslash( $_POST ) );
        $products     = new ProductService();
        $integrations = new IntegrationService();
        $mappings     = new MappingService();

        if ( 'save_woocommerce_integration' === $action ) {
            update_option( 'evomembers_woocommerce_enabled', ! empty( $post['woocommerce_enabled'] ) ? 1 : 0, false );
            $this->redirect( 'evomembers-integrations&tab=woocommerce', 'WooCommerce integration settings saved.' );
        }

        if ( 'sync_woocommerce_products' === $action ) {
            $result = $products->sync_woocommerce();
            $notice = sprintf(
                'WooCommerce discovery completed: %d created, %d updated, %d skipped. Strategy: %s.',
                (int) $result['created'],
                (int) $result['updated'],
                (int) $result['skipped'],
                sanitize_text_field( (string) ( $result['strategy'] ?? 'unknown' ) )
            );
            if ( ! empty( $result['message'] ) ) {
                $notice .= ' ' . sanitize_text_field( (string) $result['message'] );
            }
            $this->redirect( 'evomembers-products&source=woocommerce', $notice, (int) $result['total'] < 1 );
        }

        if ( 'create_product' === $action ) {
            $id = $products->create( $post );
            $this->redirect( 'evomembers-products', $id ? 'Product created.' : 'Product could not be created. Check the code/name and duplicates.', ! $id );
        }
        if ( 'update_product' === $action ) {
            $product_id = absint( $post['product_id'] ?? 0 );
            $product = $products->get( $product_id );
            $owner_id = absint( $product['owner_user_id'] ?? 0 );
            if ( ! $product || ( $owner_id !== get_current_user_id() && ! current_user_can( Capabilities::PRODUCTS_EDIT_OTHERS ) ) ) {
                $this->redirect( 'evomembers-products', 'You are not allowed to edit this product.', true );
            }
            $ok = $products->update( $product_id, $post );
            $this->redirect( 'evomembers-products', $ok ? 'Product updated.' : 'Product update failed.', ! $ok );
        }
        if ( 'delete_product' === $action ) {
            $product_id = absint( $post['product_id'] ?? 0 );
            $product = $products->get( $product_id );
            $owner_id = absint( $product['owner_user_id'] ?? 0 );
            if ( ! $product || ( $owner_id !== get_current_user_id() && ! current_user_can( Capabilities::PRODUCTS_DELETE_OTHERS ) ) ) {
                $this->redirect( 'evomembers-products', 'You are not allowed to delete this product.', true );
            }
            $ok = $products->delete( $product_id, ! empty( $post['force_delete'] ) );
            $this->redirect( 'evomembers-products', $ok ? 'Product deleted/archived according to history protection.' : 'Product could not be deleted.', ! $ok );
        }
        if ( 'set_order_status' === $action ) {
            $ok = ( new OrderService() )->set_status( absint( $post['order_id'] ?? 0 ), sanitize_key( (string) ( $post['order_status'] ?? '' ) ) );
            $this->redirect( 'evomembers-orders', $ok ? 'Order status updated.' : 'Order status update failed.', ! $ok );
        }
        if ( 'delete_order' === $action ) {
            $ok = ( new OrderService() )->delete( absint( $post['order_id'] ?? 0 ), ! empty( $post['force_delete'] ) );
            $this->redirect( 'evomembers-orders', $ok ? 'Order deleted/archived according to history protection.' : 'Order could not be deleted.', ! $ok );
        }
        if ( 'save_license_type' === $action ) {
            $id = ( new LicenseTypeService() )->save( $post );
            $this->redirect( 'evomembers-licenses', $id ? 'License type saved.' : 'License type could not be saved.', ! $id );
        }
        if ( 'delete_license_type' === $action ) {
            $ok = ( new LicenseTypeService() )->delete( absint( $post['id'] ?? 0 ) );
            $this->redirect( 'evomembers-licenses', $ok ? 'License type deleted.' : 'License type could not be deleted.', ! $ok );
        }
        if ( 'create_rest_key' === $action ) {
            $result = ( new RestKeyService() )->create( $post );
            if ( is_wp_error( $result ) ) {
                $this->redirect( 'evomembers-integrations', $result->get_error_message(), true );
            }
            set_transient( 'evomembers_rest_key_once_' . get_current_user_id(), array( 'public_id'=>(string)($result['public_id']??''), 'secret_encrypted'=>Crypto::encrypt( (string)($result['secret']??$result['key']??'') ) ), 300 );
            $this->redirect( 'evomembers-integrations', 'REST key created. Copy it now; it is shown once.' );
        }
        if ( 'reveal_rest_key' === $action ) {
            $result = ( new RestKeyService() )->reveal( absint( $post['id'] ?? 0 ) );
            if ( is_wp_error( $result ) ) {
                $this->redirect( 'evomembers-integrations', $result->get_error_message(), true );
            }
            set_transient( 'evomembers_rest_key_once_' . get_current_user_id(), array( 'public_id'=>(string)($result['public_id']??''), 'secret_encrypted'=>Crypto::encrypt( (string)($result['secret']??'') ) ), 120 );
            $this->redirect( 'evomembers-integrations', 'REST secret revealed for this administrator session. Copy it now.' );
        }
        if ( 'revoke_rest_key' === $action ) {
            $ok = ( new RestKeyService() )->revoke( absint( $post['id'] ?? 0 ) );
            $this->redirect( 'evomembers-integrations', $ok ? 'REST key revoked.' : 'REST key could not be revoked.', ! $ok );
        }
        if ( 'delete_rest_key' === $action ) {
            $id = absint( $post['id'] ?? 0 );
            $ok = ( new RestKeyService() )->delete( $id );
            if ( $ok ) {
                delete_transient( 'evomembers_rest_key_once_' . get_current_user_id() );
            }
            $this->redirect( 'evomembers-integrations', $ok ? 'REST key permanently deleted.' : 'REST key could not be deleted.', ! $ok );
        }
        if ( 'save_license_generator' === $action ) {
            update_option( 'evomembers_license_settings', array(
                'mode' => in_array( sanitize_key( (string) ( $post['generator_mode'] ?? 'alnum' ) ), array( 'alnum','hex','numeric','uuid' ), true ) ? sanitize_key( (string) $post['generator_mode'] ) : 'alnum',
                'prefix' => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $post['generator_prefix'] ?? 'EVO' ) ) ),
                'segments' => min( 8, max( 1, absint( $post['generator_segments'] ?? 3 ) ) ),
                'segment_length' => min( 32, max( 4, absint( $post['generator_length'] ?? 10 ) ) ),
                'separator' => in_array( (string) ( $post['generator_separator'] ?? '-' ), array( '-', '_', '.' ), true ) ? (string) $post['generator_separator'] : '-',
            ), false );
            $this->redirect( 'evomembers-licenses', 'License generator settings saved.' );
        }
        if ( 'save_generator_profile' === $action ) {
            $profile_id = ( new GeneratorProfileService() )->save(
                sanitize_key( (string) ( $post['profile_id'] ?? '' ) ),
                array(
                    'name'           => sanitize_text_field( (string) ( $post['profile_name'] ?? 'EVO Generator' ) ),
                    'mode'           => sanitize_key( (string) ( $post['profile_mode'] ?? 'alnum' ) ),
                    'prefix'         => sanitize_text_field( (string) ( $post['profile_prefix'] ?? 'EVO' ) ),
                    'segments'       => absint( $post['profile_segments'] ?? 3 ),
                    'segment_length' => absint( $post['profile_length'] ?? 10 ),
                    'separator'      => sanitize_text_field( (string) ( $post['profile_separator'] ?? '-' ) ),
                    'status'         => 'active',
                )
            );
            $this->redirect( 'evomembers-licenses', 'Generator profile saved: ' . $profile_id );
        }
        if ( 'delete_generator_profile' === $action ) {
            $ok = ( new GeneratorProfileService() )->delete( sanitize_key( (string) ( $post['profile_id'] ?? '' ) ) );
            $this->redirect( 'evomembers-licenses', $ok ? 'Generator profile deleted.' : 'The default generator cannot be deleted.', ! $ok );
        }
        if ( 'delete_license' === $action ) {
            $ok = ( new LicenseService() )->delete( absint( $post['license_id'] ?? 0 ) );
            $this->redirect( 'evomembers-licenses', $ok ? 'License deleted.' : 'License could not be deleted.', ! $ok );
        }
        if ( 'set_license_status' === $action ) {
            $ok = ( new LicenseService() )->set_status( absint( $post['license_id'] ?? 0 ), sanitize_key( (string) ( $post['license_status'] ?? '' ) ) );
            $this->redirect( 'evomembers-licenses', $ok ? 'License status updated.' : 'License status could not be updated.', ! $ok );
        }
        if ( 'license_item_action' === $action ) {
            $service = new LicenseService();
            $license_id = absint( $post['license_id'] ?? 0 );
            $operation  = sanitize_key( (string) ( $post['license_operation'] ?? '' ) );
            if ( 'delete' === $operation ) {
                $ok = $service->delete( $license_id );
                $this->redirect( 'evomembers-licenses', $ok ? 'License deleted.' : 'License could not be deleted.', ! $ok );
            }
            $status_map = array( 'activate'=>'active', 'stop'=>'inactive', 'disable'=>'disabled', 'freeze'=>'frozen' );
            $status = $status_map[ $operation ] ?? '';
            $ok = '' !== $status && $service->set_status( $license_id, $status );
            $this->redirect( 'evomembers-licenses', $ok ? 'License action applied.' : 'License action could not be applied.', ! $ok );
        }
        if ( 'bulk_license_action' === $action ) {
            $service = new LicenseService();
            $ids = isset( $post['license_ids'] ) && is_array( $post['license_ids'] ) ? $post['license_ids'] : array();
            $operation = sanitize_key( (string) ( $post['license_bulk_action'] ?? '' ) );
            if ( ! $ids ) {
                $this->redirect( 'evomembers-licenses', 'Select at least one license.', true );
            }
            if ( 'delete' === $operation ) {
                $result = $service->delete_many( $ids );
                $this->redirect( 'evomembers-licenses', sprintf( '%d selected license(s) deleted; %d failed.', (int) $result['deleted'], (int) $result['failed'] ), (int) $result['failed'] > 0 );
            }
            $status_map = array( 'activate'=>'active', 'stop'=>'inactive', 'disable'=>'disabled', 'freeze'=>'frozen' );
            $status = $status_map[ $operation ] ?? '';
            if ( '' === $status ) {
                $this->redirect( 'evomembers-licenses', 'Choose a valid bulk action.', true );
            }
            $result = $service->set_status_many( $ids, $status );
            $this->redirect( 'evomembers-licenses', sprintf( '%d selected license(s) updated; %d failed.', (int) $result['updated'], (int) $result['failed'] ), (int) $result['failed'] > 0 );
        }
        if ( 'delete_all_licenses' === $action ) {
            $deleted = ( new LicenseService() )->delete_all();
            $this->redirect( 'evomembers-licenses', false === $deleted ? 'All licenses could not be deleted.' : sprintf( '%d license(s) deleted.', (int) $deleted ), false === $deleted );
        }
        if ( 'verify_license_admin' === $action ) {
            $site_url = esc_url_raw( (string) ( $post['site_url'] ?? '' ) );
            $domain   = sanitize_text_field( (string) ( $post['domain'] ?? '' ) );
            if ( '' === $domain && '' !== $site_url ) { $domain = sanitize_text_field( (string) wp_parse_url( $site_url, PHP_URL_HOST ) ); }
            $result = ( new LicenseService() )->verify(
                sanitize_text_field( (string) ( $post['license_key'] ?? '' ) ),
                array(
                    'product_id'     => absint( $post['product_id'] ?? 0 ),
                    'site_url'       => $site_url,
                    'domain'         => $domain,
                    'server_ip'      => sanitize_text_field( (string) ( $post['server_ip'] ?? '' ) ),
                    'client_version' => sanitize_text_field( (string) ( $post['client_version'] ?? 'admin-test' ) ),
                )
            );
            set_transient( 'evomembers_verify_result_' . get_current_user_id(), is_wp_error( $result ) ? array( 'ok'=>false, 'message'=>$result->get_error_message(), 'code'=>$result->get_error_code() ) : array( 'ok'=>true, 'data'=>$result ), 180 );
            $notice = is_wp_error( $result ) ? 'Verification failed: ' . $result->get_error_message() : 'License is valid.';
            $this->redirect( 'evomembers-licenses', $notice, is_wp_error( $result ) );
        }
        if ( 'deactivate_activation' === $action ) {
            $ok = ( new LicenseService() )->deactivate_activation_id( absint( $post['activation_id'] ?? 0 ) );
            $this->redirect( 'evomembers-licenses', $ok ? 'Activation stopped.' : 'Activation could not be stopped.', ! $ok );
        }
        // WordPress.org build: executable add-ons are not installed from Core.
        if ( 'issue_license' === $action ) {
            $customer_id = absint( $post['customer_id'] ?? 0 );
            $email       = sanitize_email( (string) ( $post['customer_email'] ?? '' ) );
            if ( $customer_id < 1 && is_email( $email ) ) {
                $customer_id = ( new CustomerService() )->find_or_create(
                    array(
                        'email'        => $email,
                        'first_name'   => sanitize_text_field( (string) ( $post['first_name'] ?? '' ) ),
                        'last_name'    => sanitize_text_field( (string) ( $post['last_name'] ?? '' ) ),
                        'display_name' => trim( sanitize_text_field( (string) ( $post['first_name'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $post['last_name'] ?? '' ) ) ),
                    ),
                    ! empty( $post['link_wp_user'] )
                );
            } elseif ( $customer_id > 0 && ! empty( $post['link_wp_user'] ) ) {
                ( new CustomerService() )->ensure_wp_user( $customer_id );
            }

            $count = max( 1, absint( $post['license_count'] ?? $post['max_activations'] ?? 1 ) );
            $result = ( new LicenseService() )->issue_many(
                array(
                    'customer_id'     => $customer_id,
                    'product_id'      => absint( $post['product_id'] ?? 0 ),
                    'max_activations' => 1,
                    'duration_days'   => absint( $post['duration_days'] ?? 0 ),
                    'generator'       => array(
                        'mode' => sanitize_key( (string) ( $post['generator_mode'] ?? '' ) ),
                        'prefix' => sanitize_text_field( (string) ( $post['generator_prefix'] ?? '' ) ),
                        'segments' => absint( $post['generator_segments'] ?? 0 ),
                        'segment_length' => absint( $post['generator_length'] ?? 0 ),
                        'separator' => sanitize_text_field( (string) ( $post['generator_separator'] ?? '' ) ),
                    ),
                ),
                $count
            );
            if ( is_wp_error( $result ) ) { $this->redirect( 'evomembers-licenses', $result->get_error_message(), true ); }
            $keys = array_map( static fn( array $row ): string => (string) $row['license_key'], $result );
            set_transient( 'evomembers_new_license_' . get_current_user_id(), implode( "
", $keys ), 180 );
            $this->redirect( 'evomembers-licenses', sprintf( '%d independent license key(s) generated and issued.', count( $keys ) ) );
        }
        if ( 'create_integration' === $action ) {
            $id = $integrations->create( $post );
            if ( $id ) { $integrations->update_webhook_security( $id, $post ); }
            $this->redirect( 'evomembers-integrations', $id ? 'Platform added.' : 'Platform could not be added; check name/slug and duplicates.', ! $id );
        }
        if ( 'update_integration' === $action ) {
            $integration_id = absint( $post['integration_id'] ?? 0 );
            $ok = $integrations->update( $integration_id, $post );
            if ( $ok ) { $integrations->update_webhook_security( $integration_id, $post ); }
            $this->redirect( 'evomembers-integrations', $ok ? 'Platform updated.' : 'Platform update failed.', ! $ok );
        }
        if ( 'delete_integration' === $action ) {
            $ok = $integrations->delete( absint( $post['integration_id'] ?? 0 ) );
            $this->redirect( 'evomembers-integrations', $ok ? 'Platform disabled and removed from active integrations. Webhook history was preserved.' : 'Platform delete failed.', ! $ok );
        }
        if ( 'save_mapping' === $action ) {
            $post['target_id'] = 'woocommerce' === sanitize_key( (string) ( $post['target_type'] ?? 'evo' ) ) ? absint( $post['target_id_wc'] ?? 0 ) : absint( $post['target_id_evo'] ?? $post['product_id'] ?? 0 );
            $ok = $mappings->save( $post );
            $return_webhook = sanitize_text_field( (string) ( $post['return_webhook'] ?? '' ) );
            if ( $ok && '' !== $return_webhook ) {
                $retry = ( new WebhookService() )->retry( $return_webhook );
                $retry_ok = ! empty( $retry['replayed_event_id'] ) && ! str_starts_with( (string) $retry['replayed_event_id'], 'F-' );
                $notice = $retry_ok
                    ? 'Product mapping saved and webhook reprocessed as event ' . (string) $retry['replayed_event_id'] . '.'
                    : 'Product mapping saved, but webhook replay could not be completed. Review the Webhook Inbox.';
                $this->redirect( 'evomembers-webhooks', $notice, ! $retry_ok );
            }
            $this->redirect( 'evomembers-integrations', $ok ? 'Product route saved.' : 'Product mapping could not be saved. ' . $mappings->last_error(), ! $ok );
        }
        if ( 'delete_mapping' === $action ) {
            $ok = $mappings->delete( absint( $post['mapping_id'] ?? 0 ) );
            $this->redirect( 'evomembers-integrations', $ok ? 'Product route deleted.' : 'Product mapping delete failed. ' . $mappings->last_error(), ! $ok );
        }
        if ( 'save_webhook_security' === $action ) {
            $integration_id = absint( $post['integration_id'] ?? 0 );
            $ok = $integrations->update_webhook_security( $integration_id, $post );
            $this->redirect( 'evomembers-integrations', $ok ? 'Webhook security settings saved.' : 'Webhook security settings could not be saved.', ! $ok );
        }
        if ( 'repair_database' === $action ) {
            Installer::repair();
            $this->redirect( 'evomembers-webhooks', 'EVO database schema repaired and rechecked. Fallback events can now be retried from this page.' );
        }
        if ( 'retry_webhook_event' === $action ) {
            $event_id = sanitize_text_field( (string) ( $post['webhook_event_id'] ?? '' ) );
            $retry = ( new WebhookService() )->retry( $event_id );
            $new_id = (string) ( $retry['replayed_event_id'] ?? '' );
            $ok = '' !== $new_id && ! str_starts_with( $new_id, 'F-' );
            $notice = $ok ? 'Webhook reprocessed as event ' . $new_id . '.' : 'Webhook could not be reprocessed. Review storage, authentication and mapping settings.';
            $this->redirect( 'evomembers-webhooks', $notice, ! $ok );
        }
        if ( 'retry_fallback_webhooks' === $action ) {
            $result = ( new WebhookService() )->retry_all_fallback( 100 );
            $notice = sprintf( 'Fallback retry completed: %d attempted, %d moved into the database, %d remaining.', (int) $result['attempted'], (int) $result['migrated'], (int) $result['remaining'] );
            $this->redirect( 'evomembers-webhooks', $notice, (int) $result['failed'] > 0 && 0 === (int) $result['migrated'] );
        }
        if ( 'test_webhook_storage' === $action ) {
            $id = ( new WebhookService() )->write_test_event();
            $this->redirect( 'evomembers-webhooks', 'Webhook storage test recorded as ' . (string) $id . '.' );
        }
        if ( 'cleanup_webhooks' === $action ) {
            $days = min( 3650, max( 1, absint( $post['cleanup_days'] ?? 30 ) ) );
            $deleted = ( new WebhookService() )->cleanup_older_than( $days );
            $this->redirect(
                'evomembers-webhooks',
                false === $deleted ? 'Webhook cleanup failed.' : sprintf( 'Webhook cleanup completed. %d old event(s) deleted.', (int) $deleted ),
                false === $deleted
            );
        }
        if ( 'clear_webhooks' === $action ) {
            $deleted = ( new WebhookService() )->delete_all();
            $this->redirect(
                'evomembers-webhooks',
                false === $deleted ? 'Webhook history could not be cleared.' : sprintf( 'Webhook history cleared. %d event(s) deleted.', (int) $deleted ),
                false === $deleted
            );
        }
        if ( 'save_membership_plan' === $action ) {
            $id = ( new MembershipService() )->save_plan( $post, absint( $post['plan_id'] ?? 0 ) );
            $this->redirect( 'evomembers-membership', $id ? 'Membership plan saved.' : 'Membership plan could not be saved.', ! $id );
        }
        if ( 'grant_membership' === $action ) {
            $result = ( new MembershipService() )->grant( absint( $post['customer_id'] ?? 0 ), absint( $post['plan_id'] ?? 0 ), array( 'source'=>'admin', 'external_reference'=>'admin:' . wp_generate_uuid4(), 'auto_renew'=>! empty( $post['auto_renew'] ), 'notes'=>sanitize_textarea_field( (string) ( $post['notes'] ?? '' ) ) ) );
            $this->redirect( 'evomembers-membership', is_wp_error( $result ) ? $result->get_error_message() : 'Membership granted.', is_wp_error( $result ) );
        }
        if ( 'set_membership_status' === $action ) {
            $ok = ( new MembershipService() )->set_status( absint( $post['membership_id'] ?? 0 ), sanitize_key( (string) ( $post['membership_status'] ?? '' ) ) );
            $this->redirect( 'evomembers-membership', $ok ? 'Membership status updated.' : 'Membership status update failed.', ! $ok );
        }
        if ( 'renew_membership' === $action ) {
            $result = ( new MembershipService() )->renew( absint( $post['membership_id'] ?? 0 ), ! empty( $post['duration_days'] ) ? absint( $post['duration_days'] ) : null );
            $this->redirect( 'evomembers-membership', is_wp_error( $result ) ? $result->get_error_message() : 'Membership renewed and existing keys extended.', is_wp_error( $result ) );
        }
        if ( 'delete_membership' === $action ) {
            $ok = ( new MembershipService() )->delete( absint( $post['membership_id'] ?? 0 ), ! empty( $post['force_delete'] ) );
            $this->redirect( 'evomembers-membership', $ok ? 'Membership deleted/cancelled according to history protection.' : 'Membership could not be deleted.', ! $ok );
        }
        if ( 'delete_membership_plan' === $action ) {
            $ok = ( new PlanService() )->delete( absint( $post['plan_id'] ?? 0 ), ! empty( $post['force_delete'] ) );
            $this->redirect( 'evomembers-membership', $ok ? 'Membership plan deleted/archived.' : 'Membership plan could not be deleted.', ! $ok );
        }
        if ( 'send_member_message' === $action ) {
            $result = ( new MessageService() )->create( absint( $post['customer_id'] ?? 0 ), sanitize_text_field( (string) ( $post['subject'] ?? '' ) ), sanitize_textarea_field( (string) ( $post['message'] ?? '' ) ), sanitize_key( (string) ( $post['channel'] ?? 'in_app' ) ) );
            $this->redirect( 'evomembers-membership', is_wp_error( $result ) ? $result->get_error_message() : 'Member message created.', is_wp_error( $result ) );
        }
        if ( 'create_member_notification' === $action ) {
            $result = ( new NotificationService() )->create( absint( $post['customer_id'] ?? 0 ), sanitize_text_field( (string) ( $post['title'] ?? '' ) ), sanitize_textarea_field( (string) ( $post['message'] ?? '' ) ), sanitize_key( (string) ( $post['type'] ?? 'info' ) ), esc_url_raw( (string) ( $post['action_url'] ?? '' ) ), ! empty( $post['expires_at'] ) ? sanitize_text_field( (string) $post['expires_at'] ) : null );
            $this->redirect( 'evomembers-membership', is_wp_error( $result ) ? $result->get_error_message() : 'Notification created.', is_wp_error( $result ) );
        }

        if ( 'save_settings' === $action ) {
            $mode = sanitize_key( (string) ( $post['api_mode'] ?? 'internal' ) );
            if ( ! in_array( $mode, array( 'internal', 'external', 'hybrid' ), true ) ) {
                $mode = 'internal';
            }
            $existing = get_option( 'evomembers_settings', array() );
            $existing = is_array( $existing ) ? $existing : array();
            $old_external_url = (string) ( $existing['external_url'] ?? '' );
            $new_external_url = in_array( $mode, array( 'external', 'hybrid' ), true )
                ? untrailingslashit( esc_url_raw( (string) ( $post['external_url'] ?? '' ) ) )
                : (string) ( $existing['external_url'] ?? '' );
            if ( in_array( $mode, array( 'external', 'hybrid' ), true ) && '' !== $new_external_url ) {
                $validated_external_url = wp_http_validate_url( $new_external_url );
                $external_scheme = strtolower( (string) wp_parse_url( $new_external_url, PHP_URL_SCHEME ) );
                if ( false === $validated_external_url || 'https' !== $external_scheme ) {
                    $this->redirect( 'evomembers-settings', 'External API URL must be a valid HTTPS URL.', true );
                }
            }

            $existing['api_mode']           = $mode;
            $existing['external_url']       = $new_external_url;
            $existing['external_client_id'] = sanitize_text_field( (string) ( $post['external_client_id'] ?? ( $existing['external_client_id'] ?? '' ) ) );

            if ( ! empty( $post['clear_external_secret'] ) ) {
                $existing['external_secret_encrypted'] = '';
                $existing['external_secret_last4']     = '';
            } elseif ( isset( $post['external_secret'] ) && '' !== trim( (string) $post['external_secret'] ) ) {
                $plain_external_secret = trim( (string) $post['external_secret'] );
                $encrypted_external_secret = ApiConfig::encrypt_external_secret( $plain_external_secret );
                if ( '' === $encrypted_external_secret ) {
                    $this->redirect( 'evomembers-settings', 'External API secret could not be encrypted safely.', true );
                }
                $existing['external_secret_encrypted'] = $encrypted_external_secret;
                $existing['external_secret_last4']     = substr( $plain_external_secret, -4 );
            }

            update_option( 'evomembers_settings', $existing, false );
            if ( $old_external_url !== $new_external_url ) {
                delete_option( 'evomembers_external_api_discovery_snapshot' );
            }
            CenterSettings::save( is_array( $post['center'] ?? null ) ? $post['center'] : array() );
            Installer::ensure_frontend_pages();
            $this->redirect( 'evomembers-settings', 'Settings saved.' );
        }
        if ( 'discover_external_api' === $action ) {
            $staged = $this->stage_api_settings_from_post( $post );
            if ( is_wp_error( $staged ) ) { $this->redirect( 'evomembers-settings', $staged->get_error_message(), true ); }
            $router = new AuthorityRouter();
            $result = $router->discover_external();
            if ( is_wp_error( $result ) ) {
                set_transient( 'evomembers_api_test_' . get_current_user_id(), array( 'ok'=>false, 'message'=>$result->get_error_message(), 'details'=>array() ), 180 );
                $this->redirect( 'evomembers-settings', $result->get_error_message(), true );
            }
            $snapshot = array(
                'discovered_at' => current_time( 'mysql', true ),
                'data'          => $result,
            );
            update_option( 'evomembers_external_api_discovery_snapshot', $snapshot, false );
            set_transient( 'evomembers_api_test_' . get_current_user_id(), array( 'ok'=>true, 'message'=>'External API capabilities discovered successfully.', 'details'=>$result ), 180 );
            $this->redirect( 'evomembers-settings', 'External API capabilities discovered successfully.' );
        }
        if ( 'test_api_settings' === $action ) {
            $staged = $this->stage_api_settings_from_post( $post );
            if ( is_wp_error( $staged ) ) { $this->redirect( 'evomembers-settings', $staged->get_error_message(), true ); }
            $config = new ApiConfig();
            if ( 'internal' === $config->mode() ) {
                $result = array( 'ok'=>true, 'message'=>'Internal EVO REST API is enabled at ' . $config->internal_base_url() . '.', 'details'=>array( 'mode'=>'internal', 'overall'=>'ready' ) );
            } else {
                $readiness = ( new AuthorityRouter( $config ) )->readiness();
                $ok = 'blocked' !== (string) ( $readiness['overall'] ?? 'blocked' );
                $message = $ok
                    ? 'API readiness completed: ' . strtoupper( sanitize_key( (string) ( $readiness['overall'] ?? 'ready' ) ) ) . '.'
                    : 'External API is not ready. Review the diagnostic details below.';
                $result = array( 'ok'=>$ok, 'message'=>$message, 'details'=>$readiness );
            }
            set_transient( 'evomembers_api_test_' . get_current_user_id(), $result, 180 );
            $this->redirect( 'evomembers-settings', $result['message'], ! $result['ok'] );
        }
        if ( 'repair_center' === $action ) {
            Installer::ensure_frontend_pages();
            $this->redirect( 'evomembers-settings', 'Membership Center checked and repaired.' );
        }
        if ( 'save_user_access' === $action ) {
            $user_id = absint( $post['user_id'] ?? 0 );
            $user = $user_id > 0 ? get_userdata( $user_id ) : false;
            if ( ! $user ) { $this->redirect( 'evomembers-access', 'WordPress user was not found.', true ); }
            $before_access = array(
                'roles'        => array_values( (array) $user->roles ),
                'capabilities' => array_values( array_filter( Capabilities::all(), static fn( string $capability ): bool => user_can( $user, $capability ) ) ),
            );
            $role = sanitize_key( (string) ( $post['base_role'] ?? '' ) );
            if ( '' !== $role && in_array( $role, array_merge( array_keys( Capabilities::role_blueprints() ), array( 'customer', 'subscriber' ) ), true ) && ! user_can( $user, 'manage_options' ) ) {
                $user->set_role( $role );
            }
            $selected = array_map( 'sanitize_key', (array) ( $post['capabilities'] ?? array() ) );
            if ( ! user_can( $user, 'manage_options' ) ) {
                foreach ( Capabilities::all() as $capability ) {
                    if ( Capabilities::ACCESS === $capability && (int) $user_id === get_current_user_id() ) {
                        continue;
                    }
                    // A false user-level grant intentionally overrides an
                    // inherited role capability, enabling real least-privilege
                    // customization instead of merely hiding a checkbox.
                    $user->add_cap( $capability, in_array( $capability, $selected, true ) );
                }
            }
            clean_user_cache( $user_id );
            $updated_user = get_userdata( $user_id );
            $after_access = array(
                'roles'        => $updated_user ? array_values( (array) $updated_user->roles ) : array(),
                'capabilities' => $updated_user ? array_values( array_filter( Capabilities::all(), static fn( string $capability ): bool => user_can( $updated_user, $capability ) ) ) : array(),
            );
            AuditTrail::record( 'access.user_updated', 'user', $user_id, $before_access, $after_access, array( 'category' => 'security' ) );
            $this->audit_recorded = true;
            $this->redirect( 'evomembers-access&user_id=' . $user_id, 'EVO access updated.' );
        }
        if ( 'toggle_module' === $action ) {
            $id     = sanitize_key( (string) ( $post['module_id'] ?? '' ) );
            if ( RequiredComponents::is_required( $id ) ) {
                RequiredComponents::ensure( true );
                $this->redirect( 'evomembers-modules', 'This is a required Evoxup system component and is managed automatically by Membership.' );
            }
            $active = get_option( 'evomembers_active_modules', array() );
            $active = is_array( $active ) ? array_map( 'sanitize_key', $active ) : array();
            if ( in_array( $id, $active, true ) ) {
                $active = array_values( array_diff( $active, array( $id ) ) );
            } else {
                $active[] = $id;
                $active   = array_values( array_unique( $active ) );
            }
            update_option( 'evomembers_active_modules', $active, false );
            $this->redirect( 'evomembers-modules', 'Extension status updated.' );
        }
    }

    public function dashboard(): void {
        $this->head( 'Dashboard', 'One administration hub for the complete Evoxup Membership workflow, local Lite modules and future extension discovery.' );

        $member_admin_active = $this->module_active( 'evomembers-member-administration-lite' );
        $counts = array(
            array( 'Memberships', ( new MembershipService() )->count(), 'evomembers-membership', 'dashicons-id-alt' ),
            array( 'Products', ( new ProductService() )->count(), 'evomembers-products', 'dashicons-products' ),
            array( 'Orders', ( new OrderService() )->count(), 'evomembers-orders', 'dashicons-cart' ),
            array( 'Licenses', ( new LicenseService() )->count(), 'evomembers-licenses', 'dashicons-admin-network' ),
            array( 'Integrations', ( new IntegrationService() )->count(), 'evomembers-integrations', 'dashicons-rest-api' ),
            array( 'Webhooks', ( new WebhookService() )->count(), 'evomembers-webhooks', 'dashicons-rss' ),
        );
        if ( $member_admin_active ) {
            array_unshift( $counts, array( 'Customers', ( new CustomerService() )->directory_count(), 'evomembers-customers', 'dashicons-groups' ) );
        }
        echo '<div class="evomembers-dashboard-kpis">';
        foreach ( $counts as $item ) {
            $url = add_query_arg( 'page', $item[2], admin_url( 'admin.php' ) );
            echo '<a class="evomembers-kpi-card" href="' . esc_url( $url ) . '"><span class="dashicons ' . esc_attr( $item[3] ) . '"></span><span><strong>' . esc_html( number_format_i18n( (int) $item[1] ) ) . '</strong><small>' . esc_html( $item[0] ) . '</small></span><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
        }
        echo '</div>';

        $navigation = array(
            'Membership management' => array(
                array( 'Memberships & Plans', 'Plans, memberships, entitlements and lifecycle rules.', 'evomembers-membership', 'dashicons-id-alt', Capabilities::MEMBERSHIPS ),
                array( 'Products', 'Canonical EVO products and WooCommerce product relationships.', 'evomembers-products', 'dashicons-products', Capabilities::PRODUCTS ),
                array( 'Orders', 'Normalized local order ledger and purchase history.', 'evomembers-orders', 'dashicons-cart', Capabilities::ORDERS ),
                array( 'Licenses', 'Issue, verify and manage licenses, activations and generators.', 'evomembers-licenses', 'dashicons-admin-network', Capabilities::LICENSES ),
            ),
            'Connections & operations' => array(
                array( 'Integrations', 'WooCommerce, external platforms, product routing and REST credentials.', 'evomembers-integrations', 'dashicons-rest-api', Capabilities::INTEGRATIONS ),
                array( 'Webhooks', 'Verified inbound event history and replay tools.', 'evomembers-webhooks', 'dashicons-rss', Capabilities::WEBHOOKS ),
                array( 'Extensions & Add-ons', 'Enable bundled Lite modules and discover separate add-ons.', 'evomembers-modules', 'dashicons-admin-plugins', Capabilities::MODULES ),
                array( 'Access & Roles', 'Granular Evoxup capabilities mapped to WordPress users and roles.', 'evomembers-access', 'dashicons-shield-alt', Capabilities::ACCESS ),
                array( 'Settings', 'Membership center, API behavior, mail and local platform settings.', 'evomembers-settings', 'dashicons-admin-settings', Capabilities::SETTINGS ),
            ),
        );
        if ( $member_admin_active ) {
            array_unshift( $navigation['Membership management'], array( 'Customers', 'Member identities, WordPress account links and customer lifecycle.', 'evomembers-customers', 'dashicons-groups', Capabilities::CUSTOMERS ) );
        }
        $navigation = apply_filters( 'evomembers_dashboard_links', $navigation );

        echo '<div class="evomembers-dashboard-layout"><main class="evomembers-dashboard-main">';
        foreach ( $navigation as $section => $items ) {
            if ( ! is_array( $items ) ) { continue; }
            echo '<section class="evomembers-hub-section"><div class="evomembers-hub-section-head"><div><span class="evomembers-hub-kicker">CONTROL CENTER</span><h2>' . esc_html( (string) $section ) . '</h2></div></div><div class="evomembers-hub-grid">';
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || count( $item ) < 5 || ! current_user_can( (string) $item[4] ) ) { continue; }
                $url = add_query_arg( 'page', sanitize_key( (string) $item[2] ), admin_url( 'admin.php' ) );
                echo '<a class="evomembers-hub-link" href="' . esc_url( $url ) . '"><span class="evomembers-hub-icon"><span class="dashicons ' . esc_attr( sanitize_html_class( (string) $item[3] ) ) . '"></span></span><span class="evomembers-hub-copy"><strong>' . esc_html( (string) $item[0] ) . '</strong><small>' . esc_html( (string) $item[1] ) . '</small></span><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
            }
            echo '</div></section>';
        }

        $loader = new ModuleLoader();
        $modules = $loader->discover();
        $active = get_option( 'evomembers_active_modules', array() );
        $active = is_array( $active ) ? array_map( 'sanitize_key', $active ) : array();
        echo '<section class="evomembers-hub-section"><div class="evomembers-hub-section-head"><div><span class="evomembers-hub-kicker">LOCAL DISCOVERY</span><h2>Lite modules</h2><p>Bundled modules are discovered from their manifests, so future Lite modules automatically join this control center.</p></div><a class="button" href="' . esc_url( add_query_arg( 'page', 'evomembers-modules', admin_url( 'admin.php' ) ) ) . '">Manage all add-ons</a></div><div class="evomembers-extension-strip">';
        if ( empty( $modules ) ) {
            echo '<div class="evo-empty">No bundled Lite modules were discovered.</div>';
        }
        foreach ( $modules as $id => $module ) {
            $is_active = in_array( $id, $active, true );
            $detail_url = add_query_arg( array( 'page'=>'evomembers-modules', 'module'=>$id ), admin_url( 'admin.php' ) );
            $dashboard = is_array( $module['dashboard'] ?? null ) ? $module['dashboard'] : array();
            $icon = sanitize_html_class( (string) ( $dashboard['icon'] ?? 'dashicons-admin-plugins' ) );
            echo '<a class="evomembers-extension-chip" href="' . esc_url( $detail_url ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span><strong>' . esc_html( (string) ( $module['name'] ?? $id ) ) . '</strong><small>' . esc_html( $is_active ? 'Active' : 'Available' ) . '</small></span></a>';
        }
        echo '</div></section>';

        $events = EventBus::recent( 8 );
        echo '<section class="evomembers-hub-section"><div class="evomembers-hub-section-head"><div><span class="evomembers-hub-kicker">ACTIVITY</span><h2>Recent events</h2></div></div><table class="widefat striped evo-table"><thead><tr><th>Event</th><th>Source</th><th>Object</th><th>Time</th></tr></thead><tbody>';
        if ( ! $events ) { echo '<tr><td colspan="4">No events yet.</td></tr>'; }
        foreach ( $events as $event ) {
            echo '<tr><td><code>' . esc_html( (string) $event['event_name'] ) . '</code></td><td>' . esc_html( (string) $event['source'] ) . '</td><td>' . esc_html( trim( (string) ( $event['aggregate_type'] ?? '' ) . ' #' . (string) ( $event['aggregate_id'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) $event['created_at'] ) . '</td></tr>';
        }
        echo '</tbody></table></section></main><aside class="evomembers-dashboard-side">';

        $wc_available = ( new WooCommerceService() )->available();
        $wc_enabled = (bool) get_option( 'evomembers_woocommerce_enabled', 0 );
        echo '<section class="evomembers-side-card"><span class="evomembers-hub-kicker">SYSTEM STATUS</span><h2>Connections</h2><div class="evomembers-status-row"><span>WooCommerce</span>' . wp_kses_post( $this->status_badge( $wc_available && $wc_enabled ? 'active' : ( $wc_available ? 'installed' : 'inactive' ) ) ) . '</div><div class="evomembers-status-row"><span>Lite modules</span><strong>' . esc_html( count( array_intersect( array_keys( $modules ), $active ) ) . '/' . count( $modules ) ) . '</strong></div><div class="evomembers-status-row"><span>REST API</span><strong>v1</strong></div><p><code>' . esc_html( untrailingslashit( rest_url( 'evomembers/v1' ) ) ) . '</code></p></section>';

        echo '<section class="evomembers-side-card"><span class="evomembers-hub-kicker">QUICK ACTIONS</span><h2>Create & configure</h2><div class="evomembers-quick-actions">';
        $quick = array(
            array( 'Add product', array( 'page'=>'evomembers-products', 'editor'=>'new' ), Capabilities::PRODUCTS ),
            array( 'Add membership plan', array( 'page'=>'evomembers-membership', 'tab'=>'plans', 'editor'=>'new' ), Capabilities::MEMBERSHIPS ),
            array( 'Add integration', array( 'page'=>'evomembers-integrations', 'tab'=>'platforms', 'editor'=>'new' ), Capabilities::INTEGRATIONS ),
            array( 'Manage add-ons', array( 'page'=>'evomembers-modules' ), Capabilities::MODULES ),
        );
        foreach ( $quick as $item ) {
            if ( ! current_user_can( $item[2] ) ) { continue; }
            echo '<a class="button" href="' . esc_url( add_query_arg( $item[1], admin_url( 'admin.php' ) ) ) . '">' . esc_html( $item[0] ) . '</a>';
        }
        echo '</div></section>';
        do_action( 'evomembers_dashboard_sidebar' );
        echo '</aside></div>';
        do_action( 'evomembers_dashboard_after' );
        $this->end();
    }

    public function membership(): void {
        $service = new MembershipService();
        $section = sanitize_key( (string) filter_input( INPUT_GET, 'section', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ?: 'members';
        $sections = array(
            'members'       => 'Members',
            'plans'         => 'Plans',
            'products'      => 'Plan Products',
            'statistics'    => 'Statistics',
            'messages'      => 'Messages',
            'notifications' => 'Notifications',
        );
        if ( ! isset( $sections[ $section ] ) ) { $section = 'members'; }

        $this->head( 'Membership', 'Membership controls access and entitlements. Pricing and every license rule belong to Product Management.' );
        echo '<div class="evo-alert evo-alert-success"><strong>Stable core rule:</strong> a plan may contain one product or a bundle of products. Plans do not issue licenses and do not own product pricing.</div>';
        echo '<nav class="nav-tab-wrapper evo-tabs">';
        foreach ( $sections as $key=>$label ) {
            $url = add_query_arg( array( 'page'=>'evomembers-membership', 'section'=>$key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $section === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';

        $customers    = ( new CustomerService() )->all( 500 );
        $plan_service = new PlanService();
        $plans        = $plan_service->all();
        $stats        = $service->statistics();

        if ( 'statistics' === $section ) {
            echo '<div class="evo-grid evo-membership-stat-grid">';
            $this->card( 'All memberships', (int) $stats['total'] );
            $this->card( 'Active', (int) $stats['active'] );
            $this->card( 'Pending', (int) $stats['pending'] );
            $this->card( 'Past due', (int) $stats['past_due'] );
            $this->card( 'Suspended', (int) $stats['suspended'] );
            $this->card( 'Expired', (int) $stats['expired'] );
            echo '</div>';
            $counts = $plan_service->member_counts();
            echo '<div class="evo-panel"><h2>Plan health</h2><table class="widefat striped evo-table"><thead><tr><th>Plan</th><th>Mode</th><th>Products</th><th>Active</th><th>Pending</th><th>Past due</th><th>Expired</th><th>Status</th></tr></thead><tbody>';
            if ( ! $plans ) { echo '<tr><td colspan="8">No plans yet.</td></tr>'; }
            foreach ( $plans as $plan ) {
                $pc = $counts[(int)$plan['id']] ?? array();
                echo '<tr><td><strong>' . esc_html( $plan['name'] ) . '</strong><br><code>' . esc_html( $plan['code'] ) . '</code></td><td>' . esc_html( ucfirst( (string)($plan['plan_mode'] ?? 'empty') ) ) . '</td><td>' . esc_html( (string)($plan['product_count'] ?? 0) ) . '</td><td>' . esc_html( (string)($pc['active'] ?? 0) ) . '</td><td>' . esc_html( (string)($pc['pending'] ?? 0) ) . '</td><td>' . esc_html( (string)($pc['past_due'] ?? 0) ) . '</td><td>' . esc_html( (string)($pc['expired'] ?? 0) ) . '</td><td>' . wp_kses_post( $this->status_badge( (string)$plan['status'] ) ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            $this->end(); return;
        }

        if ( 'products' === $section ) {
            echo '<div class="evo-panel"><h2>Products included in membership plans</h2><p>This matrix is read-only here. Edit a plan to add, remove or reorder its products. License type, license provider and pricing stay on the product itself.</p></div>';
            echo '<table class="widefat striped evo-table"><thead><tr><th>Plan</th><th>Mode</th><th>Included products</th><th>Active members</th><th>Buy / Upgrade</th><th></th></tr></thead><tbody>';
            if ( ! $plans ) { echo '<tr><td colspan="6">No plans yet.</td></tr>'; }
            foreach ( $plans as $plan ) {
                $linked = $plan_service->products( (int)$plan['id'] );
                $chips = array();
                foreach ( $linked as $product ) {
                    $chips[] = '<span class="evo-badge evo-badge-gray"><code>' . esc_html( (string)$product['code'] ) . '</code> ' . esc_html( (string)$product['name'] ) . ' · ' . esc_html( strtoupper( (string)$product['source'] ) ) . '</span>';
                }
                $buy_url = ( new PurchaseLinkService() )->plan_url( (int)$plan['id'] );
                echo '<tr><td><strong>' . esc_html( (string)$plan['name'] ) . '</strong><br><code>' . esc_html( (string)$plan['code'] ) . '</code></td><td>' . esc_html( ucfirst( (string)($plan['plan_mode'] ?? 'empty') ) ) . '</td><td><div class="evo-chip-list">' . wp_kses_post( $chips ? implode( ' ', $chips ) : '—' ) . '</div></td><td>' . esc_html( (string)($plan['active_members'] ?? 0) ) . '</td><td>' . ( $buy_url ? '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url($buy_url) . '">Open</a>' : '<span class="evo-badge evo-badge-orange">No purchasable product</span>' ) . '</td><td><a class="button button-small" href="' . esc_url( add_query_arg( array('page'=>'evomembers-membership','section'=>'plans','edit_plan'=>(int)$plan['id']), admin_url('admin.php') ) ) . '">Edit plan</a></td></tr>';
            }
            echo '</tbody></table>';
            $this->end(); return;
        }

        if ( 'plans' === $section ) {
            $edit_plan_id = absint( filter_input( INPUT_GET, 'edit_plan', FILTER_SANITIZE_NUMBER_INT ) );
            $edit_plan    = $edit_plan_id ? $plan_service->get( $edit_plan_id ) : null;
            $plan_editor  = sanitize_key( (string) filter_input( INPUT_GET, 'editor', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
            $show_plan_editor = ( 'new' === $plan_editor ) || (bool) $edit_plan;
            $edit_entitlements = array();
            if ( $edit_plan ) {
                $decoded = json_decode( (string)($edit_plan['entitlements_json'] ?? ''), true );
                $edit_entitlements = is_array($decoded) ? $decoded : array();
            }
            $linked_product_ids = $edit_plan ? array_map( static fn(array $p): int => (int)$p['id'], $plan_service->products((int)$edit_plan['id']) ) : array();
            $all_products = ( new ProductService() )->linkable();

            $plan_choices = array( '0'=>'None' );
            foreach ( $plans as $candidate ) {
                if ( $edit_plan && (int)$candidate['id'] === (int)$edit_plan['id'] ) { continue; }
                $plan_choices[(string)$candidate['id']] = (string)$candidate['name'] . ' (' . (string)$candidate['code'] . ')';
            }

            echo '<div class="evo-editor-list-toolbar"><div><strong>Membership Plans</strong><span>Plans define access only; sale and price belong to products/commerce integrations.</span></div><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page'=>'evomembers-membership', 'section'=>'plans', 'editor'=>'new' ), admin_url( 'admin.php' ) ) ) . '">Add New Plan</a></div>';
            if ( $show_plan_editor ) {
            echo '<div class="evo-grid evo-plan-editor-grid evo-entity-editor"><div class="evo-panel"><div class="evo-editor-eyebrow">MEMBERSHIP PLAN EDITOR</div><h2>' . esc_html( $edit_plan ? 'Edit plan' : 'New plan' ) . '</h2><form method="post" class="evo-form evo-editor-form">';
            $this->nonce( 'save_membership_plan' );
            echo '<input type="hidden" name="plan_id" value="' . esc_attr( (string)($edit_plan['id'] ?? 0) ) . '">';
            $this->input( 'code','Plan code','pro',true,'text',(string)($edit_plan['code'] ?? '') );
            $this->input( 'name','Plan name','PRO',true,'text',(string)($edit_plan['name'] ?? '') );
            $this->select( 'tier','Tier',array('free'=>'FREE','pro'=>'PRO','super_star'=>'SUPER STAR','custom'=>'Custom'),(string)($edit_plan['tier'] ?? 'custom') );
            $this->input( 'duration_days','Duration days (0 = lifetime)','0',false,'number',(string)($edit_plan['duration_days'] ?? 0) );
            $this->input( 'grace_days','Grace days','0',false,'number',(string)($edit_plan['grace_days'] ?? 0) );
            $this->select( 'upgrade_plan_id','Upgrade to',$plan_choices,(string)($edit_plan['upgrade_plan_id'] ?? '0') );
            $this->select( 'downgrade_plan_id','Downgrade to',$plan_choices,(string)($edit_plan['downgrade_plan_id'] ?? '0') );
            $this->select( 'status','Status',array('active'=>'Active','inactive'=>'Inactive','frozen'=>'Frozen','archived'=>'Archived'),(string)($edit_plan['status'] ?? 'active') );
            echo '<label class="evo-check-card"><input type="checkbox" name="auto_renew_default" value="1" ' . checked( !empty($edit_plan['auto_renew_default']), true, false ) . '><span><strong>Default auto-renew</strong><small>Controls the default membership lifecycle behavior.</small></span></label>';
            echo '<div class="evo-full evo-editor-section"><h3>Description</h3>'; wp_editor( (string)($edit_plan['description'] ?? ''), 'evomembers_plan_description', array( 'textarea_name'=>'description', 'textarea_rows'=>7, 'media_buttons'=>false ) ); echo '</div>';
            echo '<label class="evo-full">Entitlements<textarea name="entitlements" rows="4" placeholder="elementor_kits&#10;image_library">' . esc_textarea( implode("\n",$edit_entitlements) ) . '</textarea><small>Core capabilities only; one per line or comma separated.</small></label>';
            echo '<fieldset class="evo-full evo-check-card"><legend><strong>Included products</strong> <small>one product = single plan, multiple products = bundle</small></legend><input type="hidden" name="product_ids[]" value="0"><div class="evo-permission-grid">';
            foreach ( $all_products as $product ) {
                $source = strtoupper((string)$product['source']);
                echo '<label><input type="checkbox" name="product_ids[]" value="' . esc_attr((string)$product['id']) . '" ' . checked(in_array((int)$product['id'],$linked_product_ids,true),true,false) . '> <code>' . esc_html((string)$product['code']) . '</code> ' . esc_html((string)$product['name']) . ' <small>[' . esc_html($source) . ']</small></label>';
            }
            echo '</div></fieldset>';
            submit_button( $edit_plan ? 'Save plan changes' : 'Create plan' );
            if ( $edit_plan ) { echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=evomembers-membership&section=plans')) . '">Cancel</a>'; }
            echo '</form></div><div class="evo-panel"><h2>Plan responsibility</h2><p><strong>Plan:</strong> access duration, tier, included products, entitlements, renewal behavior.</p><p><strong>Product:</strong> price, purchase URL, license provider/type, license duration, activations, delivery and source platform.</p><p>The plan mode is calculated automatically: <code>empty</code>, <code>single</code> or <code>bundle</code>.</p></div></div>';
            }

            echo '<table class="widefat striped evo-table"><thead><tr><th>Plan</th><th>Tier / Mode</th><th>Included Products</th><th>Members</th><th>Duration</th><th>Renewal</th><th>Entitlements</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            if ( !$plans ) { echo '<tr><td colspan="9">No membership plans yet.</td></tr>'; }
            foreach ( $plans as $plan ) {
                $ents = json_decode((string)($plan['entitlements_json'] ?? ''),true);
                $linked = $plan_service->products((int)$plan['id']);
                $linked_names = array_map(static fn(array $p): string => (string)$p['code'].' · '.(string)$p['name'],$linked);
                echo '<tr><td><strong>' . esc_html((string)$plan['name']) . '</strong><br><code>' . esc_html((string)$plan['code']) . '</code></td><td>' . esc_html(strtoupper(str_replace('_',' ',(string)$plan['tier']))) . '<br><small>' . esc_html(ucfirst((string)($plan['plan_mode'] ?? 'empty'))) . '</small></td><td>' . esc_html($linked_names ? implode(', ',$linked_names) : '—') . '</td><td>' . esc_html((string)($plan['active_members'] ?? 0)) . ' active</td><td>' . esc_html(!empty($plan['duration_days']) ? (string)$plan['duration_days'].' days' : 'Lifetime') . '</td><td>' . esc_html(!empty($plan['auto_renew_default']) ? 'Auto by default' : 'Manual/default off') . '</td><td>' . esc_html(is_array($ents) && $ents ? implode(', ',$ents) : '—') . '</td><td>' . wp_kses_post($this->status_badge((string)$plan['status'])) . '</td><td><div class="evo-actions">';
                echo '<a class="button button-small" href="' . esc_url(add_query_arg(array('page'=>'evomembers-membership','section'=>'plans','edit_plan'=>(int)$plan['id']),admin_url('admin.php'))) . '">Edit</a>';
                echo '<form method="post" class="evo-inline-form" onsubmit="return confirm(\'Delete/archive this plan?\');">'; $this->nonce('delete_membership_plan');
                echo '<input type="hidden" name="plan_id" value="' . esc_attr((string)$plan['id']) . '"><label class="evo-mini-check"><input type="checkbox" name="force_delete" value="1"> Permanent</label>'; submit_button('Delete','delete button-small','submit',false); echo '</form></div></td></tr>';
            }
            echo '</tbody></table>';
            $this->end(); return;
        }

        if ( 'messages' === $section ) {
            echo '<div class="evo-panel"><h2>Send member message</h2><form method="post" class="evo-form">'; $this->nonce('send_member_message');
            $this->customer_select($customers); $this->input('subject','Subject','Your EVO membership',true,'text');
            $this->select('channel','Delivery',array('in_app'=>'In-app','email'=>'Email','both'=>'In-app + Email'),'in_app');
            echo '<label class="evo-full">Message<textarea name="message" rows="5" required></textarea></label>'; submit_button('Send / create message'); echo '</form></div>';
            $rows=(new MessageService())->all();
            echo '<table class="widefat striped evo-table"><thead><tr><th>ID</th><th>Member</th><th>Subject</th><th>Channel</th><th>Status</th><th>Sent</th><th>Read</th></tr></thead><tbody>';
            if(!$rows){echo '<tr><td colspan="7">No messages yet.</td></tr>';}
            foreach($rows as $row){echo '<tr><td>'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)($row['email'] ?: '#'.$row['customer_id'])).'</td><td>'.esc_html((string)$row['subject']).'</td><td>'.esc_html((string)$row['channel']).'</td><td>'.wp_kses_post($this->status_badge((string)$row['status'])).'</td><td>'.esc_html((string)($row['sent_at'] ?: '—')).'</td><td>'.esc_html((string)($row['read_at'] ?: '—')).'</td></tr>';}
            echo '</tbody></table>'; $this->end(); return;
        }

        if ( 'notifications' === $section ) {
            echo '<div class="evo-panel"><h2>Create notification</h2><form method="post" class="evo-form">'; $this->nonce('create_member_notification');
            $this->customer_select($customers); $this->input('title','Title','Important update',true,'text');
            $this->select('type','Type',array('info'=>'Info','success'=>'Success','warning'=>'Warning','error'=>'Error','offer'=>'Offer','system'=>'System'),'info');
            $this->input('action_url','Action URL (optional)','https://example.com/',false,'url'); $this->input('expires_at','Expires at (optional)','2026-12-31 23:59:59',false,'text');
            echo '<label class="evo-full">Notification<textarea name="message" rows="4" required></textarea></label>'; submit_button('Create notification'); echo '</form></div>';
            $rows=(new NotificationService())->all();
            echo '<table class="widefat striped evo-table"><thead><tr><th>ID</th><th>Member</th><th>Type</th><th>Title</th><th>Status</th><th>Expires</th><th>Read</th></tr></thead><tbody>';
            if(!$rows){echo '<tr><td colspan="7">No notifications yet.</td></tr>';}
            foreach($rows as $row){echo '<tr><td>'.esc_html((string)$row['id']).'</td><td>'.esc_html((string)($row['email'] ?: '#'.$row['customer_id'])).'</td><td>'.esc_html((string)$row['type']).'</td><td>'.esc_html((string)$row['title']).'</td><td>'.wp_kses_post($this->status_badge((string)$row['status'])).'</td><td>'.esc_html((string)($row['expires_at'] ?: '—')).'</td><td>'.esc_html((string)($row['read_at'] ?: '—')).'</td></tr>';}
            echo '</tbody></table>'; $this->end(); return;
        }

        echo '<div class="evo-grid">';
        $this->card('Customers',count($customers)); $this->card('Memberships',(int)$stats['total']); $this->card('Active',(int)$stats['active']); $this->card('Plans',count($plans));
        echo '</div>';
        echo '<div class="evo-panel"><h2>Grant membership</h2><form method="post" class="evo-form">'; $this->nonce('grant_membership'); $this->customer_select($customers);
        echo '<label>Plan<select name="plan_id" required><option value="">Select...</option>';
        foreach($plans as $plan){if('active'===(string)$plan['status']){echo '<option value="'.esc_attr((string)$plan['id']).'">'.esc_html((string)$plan['name'].' ('.(string)$plan['code'].')').'</option>';}}
        echo '</select></label><label><input type="checkbox" name="auto_renew" value="1"> Auto renew</label><label class="evo-full">Admin notes<textarea name="notes" rows="3"></textarea></label>'; submit_button('Grant membership'); echo '</form></div>';
        $rows=$service->all();
        echo '<table class="widefat striped evo-table"><thead><tr><th>Membership Link</th><th>Member identity</th><th>Plan</th><th>Status</th><th>Products</th><th>Source</th><th>Period</th><th>Renewals</th><th>Actions</th></tr></thead><tbody>';
        if(!$rows){echo '<tr><td colspan="9">No memberships yet.</td></tr>';}
        foreach($rows as $row){
            $name=trim((string)$row['first_name'].' '.(string)$row['last_name']);
            $linked=$plan_service->products((int)$row['plan_id']);
            $product_codes=array_map(static fn(array $p): string=>(string)$p['code'],$linked);
            $wp_identity = ! empty( $row['wp_user_id'] ) ? 'WP User #' . (int) $row['wp_user_id'] : 'WP User: not linked';
            $customer_identity = 'EVO Customer #' . (int) $row['customer_id'];
            echo '<tr><td><strong>#'.esc_html((string)$row['id']).'</strong><br><small>Plan/product assignment</small></td><td><strong>'.esc_html($name ?: ((string)$row['display_name'] ?: 'Member')).'</strong><br><small>'.esc_html((string)$row['email']).'</small><br><small>'.esc_html($wp_identity.' · '.$customer_identity).'</small></td><td><strong>'.esc_html((string)$row['plan_name']).'</strong><br><code>'.esc_html((string)$row['plan_code']).'</code></td><td>'.wp_kses_post($this->status_badge((string)$row['status'])).'</td><td>'.esc_html($product_codes ? implode(', ',$product_codes) : '—').'</td><td>'.esc_html((string)($row['source'] ?: 'evo')).'</td><td>'.esc_html((string)($row['starts_at'] ?: '—')).'<br><small>→ '.esc_html((string)($row['expires_at'] ?: 'Lifetime')).'</small></td><td>'.esc_html((string)($row['renewal_count'] ?? 0)).'</td><td><form method="post" class="evo-inline-form">'; $this->nonce('set_membership_status');
            echo '<input type="hidden" name="membership_id" value="'.esc_attr((string)$row['id']).'"><select name="membership_status">';
            foreach(array('active'=>'Active','pending'=>'Pending','past_due'=>'Past due','suspended'=>'Suspended','cancelled'=>'Cancelled','refunded'=>'Refunded','expired'=>'Expired') as $k=>$v){echo '<option value="'.esc_attr($k).'" '.selected((string)$row['status'],$k,false).'>'.esc_html($v).'</option>';}
            echo '</select>'; submit_button('Apply','secondary button-small','submit',false); echo '</form><form method="post" class="evo-inline-form">'; $this->nonce('renew_membership');
            echo '<input type="hidden" name="membership_id" value="'.esc_attr((string)$row['id']).'"><input type="number" min="0" step="1" name="duration_days" value="0" title="0 uses plan duration">'; submit_button('Renew','secondary button-small','submit',false); echo '</form><form method="post" class="evo-inline-form" onsubmit="return confirm(\'Delete/cancel this membership?\');">'; $this->nonce('delete_membership');
            echo '<input type="hidden" name="membership_id" value="'.esc_attr((string)$row['id']).'"><label class="evo-mini-check"><input type="checkbox" name="force_delete" value="1"> Permanent</label>'; submit_button('Delete','delete button-small','submit',false); echo '</form></td></tr>';
        }
        echo '</tbody></table>';
        $this->end();
    }

    public function products(): void {
        $service = new ProductService();
        $source  = sanitize_key( (string) filter_input( INPUT_GET, 'source', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ?: 'all';
        if ( ! in_array( $source, array_merge( array( 'all' ), ProductService::sources() ), true ) ) { $source = 'all'; }

        $edit_id = absint( filter_input( INPUT_GET, 'edit_product', FILTER_SANITIZE_NUMBER_INT ) );
        $edit    = $edit_id ? $service->get( $edit_id ) : null;
        $editor  = sanitize_key( (string) filter_input( INPUT_GET, 'editor', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        $show_editor = ( 'new' === $editor ) || (bool) $edit;
        if ( $edit ) { $source = (string) $edit['source']; }

        $this->head( 'Products', 'Products stay as a compact list. Add New and Edit open the dedicated product editor so the management surface is easier to audit.' );
        echo '<nav class="nav-tab-wrapper evo-tabs">';
        $tabs=array('all'=>'All Products','evo'=>'EVO Internal','woocommerce'=>'WooCommerce','member'=>'Member Products','external'=>'External');
        foreach($tabs as $key=>$label){
            $url=add_query_arg(array('page'=>'evomembers-products','source'=>$key),admin_url('admin.php'));
            $count=$service->count($key,true);
            echo '<a class="nav-tab '.($source===$key?'nav-tab-active':'').'" href="'.esc_url($url).'">'.esc_html($label).' <span class="count">('.esc_html((string)$count).')</span></a>';
        }
        echo '</nav>';
        echo '<div class="evo-editor-list-toolbar"><div><strong>Product workspace</strong><span>List first; editor only when requested.</span></div><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page'=>'evomembers-products', 'source'=>( 'all' === $source ? 'evo' : $source ), 'editor'=>'new' ), admin_url( 'admin.php' ) ) ) . '">Add New Product</a></div>';

        if ( 'woocommerce' === $source || 'all' === $source ) {
            echo '<div class="evo-panel evo-product-sync"><div><strong>WooCommerce discovery</strong><p>EVO stores the native Woo ID and current name together. The ID is the technical link; the name is display/search data.</p></div><form method="post">'; $this->nonce('sync_woocommerce_products'); submit_button('Discover / Sync WooCommerce','secondary','submit',false); echo '</form></div>';
        }

        $form_source = $edit ? (string)$edit['source'] : ( in_array($source,array('evo','member','external'),true) ? $source : 'evo' );
        if ( $show_editor && 'woocommerce' !== $form_source ) {
            echo '<div class="evo-panel evo-entity-editor"><div class="evo-editor-toolbar"><div><a class="evo-editor-back" href="'.esc_url(admin_url('admin.php?page=evomembers-products&source='.$form_source)).'">← Products</a><div class="evo-editor-eyebrow">PRODUCT EDITOR</div><h2>'.esc_html($edit?'Edit product':'New product').'</h2></div></div><form method="post" class="evo-form evo-product-form evo-editor-form">';
            $this->nonce($edit?'update_product':'create_product');
            if($edit){echo '<input type="hidden" name="product_id" value="'.esc_attr((string)$edit['id']).'">';}
            echo '<input type="hidden" name="source" value="'.esc_attr($form_source).'">';
            echo '<label>Product Code<input type="text" value="'.esc_attr((string)($edit['code'] ?? 'Generated automatically after save')).'" readonly><small>Prefix is automatic: E = internal, W = WooCommerce, M = member, X = external.</small></label>';
            $this->input('name','Product name','EVO Product',true,'text',(string)($edit['name'] ?? ''));
            $this->input('owner_user_id','Owner WordPress User ID',(string)get_current_user_id(),false,'number',(string)($edit['owner_user_id'] ?? get_current_user_id()));
            if ( 'external' === $form_source ) {
                $this->input('external_source_id','Provider Product ID / Native ID','provider-product-123',false,'text',(string)($edit['external_source_id'] ?? ''));
                $this->input('source_name','Provider product name','Name reported by the external platform',false,'text',(string)($edit['source_name'] ?? ''));
            }
            $this->input('excerpt','Short / medium description','A compact product summary',false,'text',(string)($edit['excerpt'] ?? ''));
            $this->input('price','Price','0.00',false,'number',(string)($edit['price'] ?? ''));
            $this->input('sale_price','Sale price','',false,'number',(string)($edit['sale_price'] ?? ''));
            $this->input('currency','Currency','USD',false,'text',(string)($edit['currency'] ?? ''));
            echo '<label>WordPress image ID<div class="evo-media-field"><input type="number" name="image_id" id="evo-product-image-id" value="'.esc_attr((string)($edit['image_id'] ?? 0)).'" min="0" step="1"><button type="button" class="button" data-evo-media-select data-target="#evo-product-image-id">Upload / Select</button></div></label>';
            $this->input('image_url','External image URL','https://example.com/product.jpg',false,'url',(string)($edit['image_url'] ?? ''));
            $this->input('purchase_url','Purchase URL','https://example.com/buy',false,'url',(string)($edit['purchase_url'] ?? ''));
            $this->input('upgrade_url','Upgrade URL','https://example.com/upgrade',false,'url',(string)($edit['upgrade_url'] ?? ''));
            $this->select('product_type','Product type',array('digital'=>'Digital download','software'=>'Software','membership'=>'Membership product','course'=>'Course','service'=>'Service','other'=>'Other'),(string)($edit['product_type'] ?? 'digital'));
            $this->input('download_url','Digital file / delivery URL','https://example.com/file.zip',false,'url',(string)($edit['download_url'] ?? ''));
            $this->select('status','Status',array('active'=>'Active','inactive'=>'Inactive','frozen'=>'Frozen','archived'=>'Archived'),(string)($edit['status'] ?? 'active'));
            echo '<input type="hidden" name="requires_license" value="0"><label class="evo-check-card evo-full"><input type="checkbox" id="evo-requires-license" name="requires_license" value="1" '.checked(!empty($edit['requires_license']),true,false).'><span><strong>Requires a license</strong><small>All license policy belongs to this product, never to the membership plan.</small></span></label>';
            echo '<div id="evo-product-license-fields" class="evo-license-fields evo-full">';
            $this->select('verification_mode','Verification policy',array('portable'=>'Portable / key only','site'=>'Exact site URL','domain'=>'Domain','server_ip'=>'Server IP / CIDR','domain_ip'=>'Domain + server IP'),(string)($edit['verification_mode'] ?? 'portable'));
            $this->input('allowed_site_url','Exact site URL','https://example.com',false,'url',(string)($edit['allowed_site_url'] ?? ''));
            $this->input('allowed_domain','Allowed domain','example.com',false,'text',(string)($edit['allowed_domain'] ?? ''));
            $this->input('allowed_ip','Allowed server IP / CIDR','203.0.113.10 or 203.0.113.0/24',false,'text',(string)($edit['allowed_ip'] ?? ''));
            $provider_options=array('evo'=>'EVO issues keys','external'=>'External provider / adapter','none'=>'No license');
            foreach(\EvoMembers\Providers\LicenseProviderRegistry::labels() as $slug=>$label){$provider_options[$slug]='External: '.$label;}
            foreach((new IntegrationService())->all() as $integration){$provider_options['integration_'.(int)$integration['id']]='Integration: '.(string)$integration['name'];}
            $this->select('license_provider','License provider',$provider_options,(string)($edit['license_provider'] ?? 'evo'));
            $type_options=array('0'=>'Use product settings / defaults');
            foreach((new LicenseTypeService())->all() as $type){if('active'===(string)($type['status'] ?? 'active')){$type_options[(string)(int)$type['id']]=(string)$type['name'].' ('.(string)$type['code'].')';}}
            $this->select('license_type_id','License type',$type_options,(string)($edit['license_type_id'] ?? '0'));
            $this->input('license_seats','Independent license keys / sites','1',false,'number',(string)($edit['license_seats'] ?? 1));
            $this->input('license_duration_days','License duration days (0 = lifetime)','0',false,'number',(string)($edit['license_duration_days'] ?? 0));
            echo '</div>';
            echo '<fieldset class="evo-full evo-check-card"><legend><strong>Membership plans including this product</strong></legend><input type="hidden" name="plan_ids[]" value="0"><div class="evo-permission-grid">';
            $selected=$edit&&!empty($edit['plans'])?array_map(static fn(array $p):int=>(int)$p['id'],$edit['plans']):array();
            foreach((new PlanService())->all() as $plan){echo '<label><input type="checkbox" name="plan_ids[]" value="'.esc_attr((string)$plan['id']).'" '.checked(in_array((int)$plan['id'],$selected,true),true,false).'> '.esc_html((string)$plan['name'].' ('.(string)$plan['code'].')').'</label>';}
            echo '</div></fieldset>';
            echo '<div class="evo-full evo-editor-section"><h3>Full description</h3>'; wp_editor( (string)($edit['description'] ?? ''), 'evomembers_product_description', array( 'textarea_name'=>'description', 'textarea_rows'=>9, 'media_buttons'=>true ) ); echo '</div>';
            echo '<label class="evo-full">Internal notes<textarea name="notes" rows="3"></textarea></label>';
            submit_button($edit?'Save product':'Create product');
            if($edit){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=evomembers-products&source='.$form_source)).'">Cancel</a>';}
            echo '</form></div>';
        } elseif ( $edit ) {
            echo '<div class="evo-alert evo-alert-warning">WooCommerce identity fields are synchronized from WooCommerce. You can still configure EVO licensing by opening the product after sync.</div>';
        }

        if ( $edit && 'woocommerce' === (string)$edit['source'] ) {
            echo '<div class="evo-panel"><h2>WooCommerce product licensing</h2><form method="post" class="evo-form evo-product-form">'; $this->nonce('update_product');
            echo '<input type="hidden" name="product_id" value="'.esc_attr((string)$edit['id']).'"><input type="hidden" name="source" value="woocommerce">';
            echo '<label>Product Code<input type="text" readonly value="'.esc_attr((string)$edit['code']).'"></label><label>Native Woo ID<input type="text" readonly value="'.esc_attr((string)$edit['external_source_id']).'"></label><label>Name<input type="text" readonly value="'.esc_attr((string)$edit['name']).'"></label>';
            echo '<input type="hidden" name="requires_license" value="0"><label class="evo-check-card evo-full"><input type="checkbox" id="evo-requires-license" name="requires_license" value="1" '.checked(!empty($edit['requires_license']),true,false).'><span><strong>Requires a license</strong><small>EVO stores the license policy for this discovered WooCommerce product.</small></span></label><div id="evo-product-license-fields" class="evo-license-fields evo-full">';
            $provider_options=array('evo'=>'EVO issues keys','external'=>'External provider / adapter','none'=>'No license');
            foreach(\EvoMembers\Providers\LicenseProviderRegistry::labels() as $slug=>$label){$provider_options[$slug]='External: '.$label;}
            foreach((new IntegrationService())->all() as $integration){$provider_options['integration_'.(int)$integration['id']]='Integration: '.(string)$integration['name'];}
            $this->select('license_provider','License provider',$provider_options,(string)($edit['license_provider'] ?? 'evo'));
            $this->select('verification_mode','Verification policy',array('portable'=>'Portable / key only','site'=>'Exact site URL','domain'=>'Domain','server_ip'=>'Server IP / CIDR','domain_ip'=>'Domain + server IP'),(string)($edit['verification_mode'] ?? 'portable'));
            $this->input('license_seats','Independent keys / sites','1',false,'number',(string)($edit['license_seats'] ?? 1));
            $this->input('license_duration_days','License duration days','0',false,'number',(string)($edit['license_duration_days'] ?? 0));
            echo '</div><fieldset class="evo-full evo-check-card"><legend><strong>Membership plans</strong></legend><input type="hidden" name="plan_ids[]" value="0"><div class="evo-permission-grid">';
            $selected=!empty($edit['plans'])?array_map(static fn(array $p):int=>(int)$p['id'],$edit['plans']):array();
            foreach((new PlanService())->all() as $plan){echo '<label><input type="checkbox" name="plan_ids[]" value="'.esc_attr((string)$plan['id']).'" '.checked(in_array((int)$plan['id'],$selected,true),true,false).'> '.esc_html((string)$plan['name'].' ('.(string)$plan['code'].')').'</label>';}
            echo '</div></fieldset>'; submit_button('Save Woo product licensing'); echo '</form></div>';
        }

        $per_page = 50;
        $paged = max( 1, absint( filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT ) ) );
        $total = $service->count( $source, true );
        $max_pages = max( 1, (int) ceil( $total / $per_page ) );
        if ( $paged > $max_pages ) { $paged = $max_pages; }
        $rows = $service->all( $per_page, true, $source, ( $paged - 1 ) * $per_page );
        echo '<div class="evo-panel"><h2>'.esc_html($tabs[$source]).'</h2><p>Compact rows use a 24×24 image and two data lines. Description stays hidden until Full Preview. Only 50 products are loaded per page for predictable query and rendering cost.</p></div>';
        echo '<table class="widefat evo-table evo-product-compact-table"><thead><tr><th class="evo-product-thumb-col">Image</th><th>Product</th><th class="evo-product-action-col">Actions</th></tr></thead><tbody>';
        if(!$rows){echo '<tr><td colspan="3">No products in this section.</td></tr>';}
        foreach($rows as $r){
            $img='';
            if(!empty($r['image_id'])){$img=(string)wp_get_attachment_image_url((int)$r['image_id'],'thumbnail');}
            if(''===$img && !empty($r['image_url'])){$img=(string)$r['image_url'];}
            $source_label=$tabs[(string)$r['source']] ?? strtoupper((string)$r['source']);
            $price=(null!==($r['sale_price'] ?? null)&&''!==(string)$r['sale_price'])?$r['sale_price']:$r['price'];
            $price_text=(null!==$price&&''!==(string)$price)?trim((string)$r['currency'].' '.number_format_i18n((float)$price,2)):'—';
            $plans=(array)($r['plan_names'] ?? array());
            $plan_text=$plans ? (string)$plans[0].(count($plans)>1?' +'.(count($plans)-1):'') : 'No plan';
            $license=!empty($r['requires_license'])?('License: '.(string)($r['license_provider'] ?? 'evo').' · '.(string)($r['license_seats'] ?? 1).' key/site'):'No license';
            $native=!empty($r['external_source_id'])?'Native ID: '.(string)$r['external_source_id']:'Internal ID: '.(string)$r['id'];
            echo '<tr><td class="evo-product-thumb-col">';
            if($img){echo '<img class="evo-product-thumb" src="'.esc_url($img).'" alt="">';}else{echo '<span class="evo-product-thumb evo-product-thumb-placeholder">◫</span>';}
            echo '</td><td><div class="evo-product-row-main"><div class="evo-product-line evo-product-line-top"><code>'.esc_html((string)$r['code']).'</code><strong>'.esc_html((string)$r['name']).'</strong><span class="evo-badge evo-badge-gray">'.esc_html($source_label).'</span><span>'.esc_html($price_text).'</span>'.wp_kses_post($this->status_badge((string)$r['status'])).'</div><div class="evo-product-line evo-product-line-bottom"><span>'.esc_html($license).'</span><span>Plan: '.esc_html($plan_text).'</span><span>'.esc_html($native).'</span><span>'.esc_html((string)$r['product_type']).'</span></div></div>';
            echo '<details class="evo-product-full-preview"><summary>Full Preview</summary><div class="evo-product-preview-card">';
            if($img){echo '<img class="evo-product-preview-image" src="'.esc_url($img).'" alt="">';}
            echo '<div class="evo-product-preview-content"><h3>'.esc_html((string)$r['name']).' <code>'.esc_html((string)$r['code']).'</code></h3><p><strong>Source:</strong> '.esc_html($source_label).' · <strong>Price:</strong> '.esc_html($price_text).' · <strong>Status:</strong> '.esc_html((string)$r['status']).'</p><p>'.wp_kses_post((string)($r['excerpt'] ?? '')).'</p><div class="evo-product-description">'.wp_kses_post((string)($r['description'] ?? '')).'</div><p><strong>Licensing:</strong> '.esc_html($license).'</p><p><strong>Membership plans:</strong> '.esc_html($plans?implode(', ',$plans):'None').'</p>';
            \EvoMembers\Core\Hooks::do_action( 'admin_product_preview',$r);
            echo '</div></div></details></td><td class="evo-product-action-col"><div class="evo-actions"><a class="button button-small" href="'.esc_url(add_query_arg(array('page'=>'evomembers-products','source'=>(string)$r['source'],'edit_product'=>(int)$r['id']),admin_url('admin.php'))).'">Edit</a><form method="post" onsubmit="return confirm(\'Delete/archive this product?\');">'; $this->nonce('delete_product'); echo '<input type="hidden" name="product_id" value="'.esc_attr((string)$r['id']).'"><label class="evo-mini-check"><input type="checkbox" name="force_delete" value="1"> Permanent</label>'; submit_button('Delete','delete button-small','submit',false); echo '</form></div></td></tr>';
        }
        echo '</tbody></table>';
        if ( $max_pages > 1 ) {
            $base = str_replace( '999999999', '%#%', add_query_arg( array( 'page'=>'evomembers-products', 'source'=>$source, 'paged'=>999999999 ), admin_url( 'admin.php' ) ) );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
                paginate_links(
                    array(
                        'base'      => $base,
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $max_pages,
                        'prev_text' => '‹',
                        'next_text' => '›',
                    )
                ) ?: ''
            ) . '</div></div>';
        }
        $this->end();
    }

    public function orders(): void {
        $service = new OrderService();
        $rows = $service->all( 500 );
        $this->head( 'Orders', 'Unified EVO order ledger. WooCommerce and external providers remain the checkout owners; EVO stores the linked commercial record for fulfillment, memberships and licenses.' );
        echo '<div class="evo-alert evo-alert-success"><strong>Provider-neutral ledger:</strong> WooCommerce orders keep their native order ID, external providers keep their transaction reference, and EVO assigns one internal ledger ID for cross-system links.</div>';
        echo '<table class="widefat striped evo-table"><thead><tr><th>EVO ID</th><th>Source / Reference</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        if ( ! $rows ) { echo '<tr><td colspan="7">No orders yet.</td></tr>'; }
        foreach ( $rows as $row ) {
            $source = ! empty( $row['woocommerce_order_id'] ) ? 'WooCommerce' : ( ! empty( $row['integration_name'] ) ? (string) $row['integration_name'] : 'EVO / External' );
            $reference = ! empty( $row['woocommerce_order_id'] ) ? '#' . (string) $row['woocommerce_order_id'] : ( (string) ( $row['external_order_id'] ?? '' ) ?: (string) ( $row['external_transaction_id'] ?? '—' ) );
            $customer = (string) ( $row['customer_name'] ?? '' );
            if ( '' === trim( $customer ) ) { $customer = (string) ( $row['customer_email'] ?? '—' ); }
            $money = ( null !== ( $row['total'] ?? null ) && '' !== (string) $row['total'] ) ? trim( (string) ( $row['currency'] ?? '' ) . ' ' . (string) $row['total'] ) : '—';
            echo '<tr><td><strong>#' . esc_html( (string) $row['id'] ) . '</strong></td><td><strong>' . esc_html( $source ) . '</strong><br><code>' . esc_html( $reference ) . '</code></td><td>' . esc_html( $customer ) . '<br><small>' . esc_html( (string) ( $row['customer_email'] ?? '' ) ) . '</small></td><td>' . esc_html( (string) ( $row['item_count'] ?? 0 ) ) . '</td><td>' . esc_html( $money ) . '</td><td>' . wp_kses_post( $this->status_badge( (string) ( $row['status'] ?? 'received' ) ) ) . '</td><td><div class="evo-actions"><form method="post" class="evo-inline-form">';
            $this->nonce( 'set_order_status' );
            echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $row['id'] ) . '"><select name="order_status">';
            foreach ( OrderService::statuses() as $status ) { echo '<option value="' . esc_attr( $status ) . '" ' . selected( (string) $row['status'], $status, false ) . '>' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</option>'; }
            echo '</select>'; submit_button( 'Apply', 'secondary button-small', 'submit', false ); echo '</form>';
            echo '<form method="post" class="evo-inline-form" onsubmit="return confirm(\'Delete/archive this EVO order ledger entry?\');">';
            $this->nonce( 'delete_order' );
            echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $row['id'] ) . '"><label class="evo-mini-check"><input type="checkbox" name="force_delete" value="1"> Permanent</label>';
            submit_button( 'Delete', 'delete button-small', 'submit', false ); echo '</form></div></td></tr>';
            $items = $service->items( (int) $row['id'] );
            if ( $items ) {
                echo '<tr class="evo-order-items-row"><td></td><td colspan="6"><details><summary>Order items / routing</summary><table class="widefat striped"><thead><tr><th>Item</th><th>EVO Product</th><th>Plan</th><th>Qty</th><th>License provider</th><th>Keys/sites</th></tr></thead><tbody>';
                foreach ( $items as $item ) {
                    echo '<tr><td>' . esc_html( (string) ( $item['name'] ?? '—' ) ) . '<br><small>' . esc_html( (string) ( $item['provider_product_id'] ?? '' ) ) . '</small></td><td>' . esc_html( (string) ( $item['product_name'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $item['plan_name'] ?? '—' ) ) . '</td><td>' . esc_html( (string) ( $item['quantity'] ?? 1 ) ) . '</td><td>' . esc_html( (string) ( $item['license_provider'] ?? 'none' ) ) . '</td><td>' . esc_html( (string) ( $item['license_seats'] ?? 1 ) ) . '</td></tr>';
                }
                echo '</tbody></table></details></td></tr>';
            }
        }
        echo '</tbody></table>';
        $this->end();
    }

    public function licenses(): void {
        $service = new LicenseService();
        $tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        if ( ! in_array( $tab, array( 'licenses', 'types', 'generators', 'activations' ), true ) ) {
            $tab = 'licenses';
        }
        $this->head( 'Licenses', 'Issue, verify, freeze, stop and activate EVO licenses independently from WooCommerce.' );
        echo '<nav class="nav-tab-wrapper evo-license-tabs">';
        foreach ( array( 'licenses' => 'Licenses', 'types' => 'License Types', 'generators' => 'Generators', 'activations' => 'Activations' ) as $key => $label ) {
            $url = add_query_arg( array( 'page' => 'evomembers-licenses', 'tab' => $key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';

        if ( 'types' === $tab ) {
            $types=new LicenseTypeService();
            echo '<div class="evo-panel"><h2>License Types</h2><p>Reusable policies for portable, site, domain and server-IP controlled licenses.</p></div>';
            echo '<form method="post" class="evo-form">';$this->nonce('save_license_type');
            $this->input('code','Code','standard',true,'text','');$this->input('name','Name','Standard License',true,'text','');
            $this->select('verification_mode','Verification policy',['portable'=>'Portable / key only','site'=>'Exact site URL','domain'=>'Domain','server_ip'=>'Server IP / CIDR','domain_ip'=>'Domain + server IP'],'portable');
            $this->input('license_seats','Default independent keys/sites','1',false,'number','1');$this->input('duration_days','Default duration days (0 = lifetime)','0',false,'number','0');
            submit_button('Add license type');echo '</form>';
            $rows=$types->all();echo '<table class="widefat striped evo-table"><thead><tr><th>Code</th><th>Name</th><th>Policy</th><th>Keys/Sites</th><th>Duration</th><th>Status</th><th></th></tr></thead><tbody>';
            if(!$rows){echo '<tr><td colspan="7">No license types.</td></tr>';}
            foreach($rows as $r){echo '<tr><td><code>'.esc_html($r['code']).'</code></td><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['verification_mode']).'</td><td>'.esc_html($r['license_seats'] ?? 1).'</td><td>'.esc_html($r['duration_days']?:'Lifetime').'</td><td>'.wp_kses_post($this->status_badge((string)$r['status'])).'</td><td><form method="post" onsubmit="return confirm(\'Delete this license type?\');">';$this->nonce('delete_license_type');echo '<input type="hidden" name="id" value="'.esc_attr($r['id']).'">';submit_button('Delete','delete button-small','submit',false);echo '</form></td></tr>';}
            echo '</tbody></table>'; $this->end(); return;
        }

        if ( 'generators' === $tab ) {
            $generator = get_option( 'evomembers_license_settings', array() );
            $generator = is_array( $generator ) ? wp_parse_args( $generator, array( 'mode'=>'alnum','prefix'=>'EVO','segments'=>3,'segment_length'=>10,'separator'=>'-' ) ) : array( 'mode'=>'alnum','prefix'=>'EVO','segments'=>3,'segment_length'=>10,'separator'=>'-' );
            echo '<div class="evo-panel evo-license-generator"><h2>Default generator</h2><form method="post" class="evo-form">';
            $this->nonce( 'save_license_generator' );
            $this->select( 'generator_mode', 'Generation method', array( 'alnum'=>'Secure letters + numbers','hex'=>'Hexadecimal','numeric'=>'Numbers only','uuid'=>'UUID v4' ), (string) $generator['mode'] );
            $this->input( 'generator_prefix', 'Prefix', 'EVO', false, 'text', (string) $generator['prefix'] );
            $this->input( 'generator_segments', 'Number of groups', '3', true, 'number', (string) $generator['segments'] );
            $this->input( 'generator_length', 'Characters per group', '10', true, 'number', (string) $generator['segment_length'] );
            $this->select( 'generator_separator', 'Group separator', array( '-'=>'Dash (-)','_'=>'Underscore (_)', '.'=>'Dot (.)' ), (string) $generator['separator'] );
            submit_button( 'Save generator defaults' );
            echo '</form></div>';

            $profile_service = new GeneratorProfileService();
            $profiles = $profile_service->all();
            echo '<div class="evo-panel"><h2>EVO Generator Profiles</h2><form method="post" class="evo-form">';
            $this->nonce( 'save_generator_profile' );
            $this->input( 'profile_id', 'Profile ID', 'pro-generator', true, 'text' );
            $this->input( 'profile_name', 'Generator name', 'PRO Generator', true, 'text' );
            $this->select( 'profile_mode', 'Generation method', array( 'alnum'=>'Secure letters + numbers','hex'=>'Hexadecimal','numeric'=>'Numbers only','uuid'=>'UUID v4' ), 'alnum' );
            $this->input( 'profile_prefix', 'Prefix', 'EVO', false, 'text' );
            $this->input( 'profile_segments', 'Number of groups', '3', true, 'number' );
            $this->input( 'profile_length', 'Characters per group', '10', true, 'number' );
            $this->select( 'profile_separator', 'Separator', array( '-'=>'Dash (-)','_'=>'Underscore (_)', '.'=>'Dot (.)' ), '-' );
            submit_button( 'Save generator profile' );
            echo '</form><table class="widefat striped evo-table"><thead><tr><th>ID</th><th>Name</th><th>Format</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $profiles as $pid => $profile ) {
                $format = (string) ( $profile['prefix'] ?? '' ) . (string) ( $profile['separator'] ?? '-' ) . (string) ( $profile['segments'] ?? 3 ) . '×' . (string) ( $profile['segment_length'] ?? 10 );
                echo '<tr><td><code>' . esc_html( $pid ) . '</code></td><td>' . esc_html( $profile['name'] ?? $pid ) . '</td><td>' . esc_html( $format ) . '</td><td>' . wp_kses_post( $this->status_badge( (string) ( $profile['status'] ?? 'active' ) ) ) . '</td><td>';
                if ( 'default' !== $pid ) {
                    echo '<form method="post" onsubmit="return confirm(\'Delete generator?\');">';
                    $this->nonce( 'delete_generator_profile' );
                    echo '<input type="hidden" name="profile_id" value="' . esc_attr( $pid ) . '">';
                    submit_button( 'Delete', 'delete button-small', 'submit', false );
                    echo '</form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            $this->end();
            return;
        }

        if ( 'activations' === $tab ) {
            $rows = $service->activations();
            echo '<div class="evo-panel"><h2>License activations</h2><p>Each site activation is tracked independently. Stopping an activation frees a slot without disabling the license.</p><table class="widefat striped evo-table"><thead><tr><th>ID</th><th>License</th><th>Product</th><th>Customer</th><th>Site</th><th>Status</th><th>Activated</th><th>Last seen</th><th>Action</th></tr></thead><tbody>';
            if ( ! $rows ) {
                echo '<tr><td colspan="9">No activations yet.</td></tr>';
            }
            foreach ( $rows as $a ) {
                echo '<tr><td>' . esc_html( $a['id'] ) . '</td><td>#' . esc_html( $a['license_id'] ) . ' · …' . esc_html( $a['license_last4'] ) . '</td><td>' . esc_html( $a['product_name'] ?: '—' ) . '</td><td>' . esc_html( $a['customer_email'] ?: '—' ) . '</td><td><code>' . esc_html( $a['site_url'] ) . '</code></td><td>' . wp_kses_post( $this->status_badge( (string) $a['status'] ) ) . '</td><td>' . esc_html( $a['activated_at'] ) . '</td><td>' . esc_html( $a['last_seen_at'] ) . '</td><td>';
                if ( 'active' === $a['status'] ) {
                    echo '<form method="post">';
                    $this->nonce( 'deactivate_activation' );
                    echo '<input type="hidden" name="activation_id" value="' . esc_attr( $a['id'] ) . '">';
                    submit_button( 'Stop', 'secondary button-small', 'submit', false );
                    echo '</form>';
                } else {
                    echo '—';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
            $this->end();
            return;
        }

        $plain = get_transient( 'evomembers_new_license_' . get_current_user_id() );
        if ( $plain ) {
            delete_transient( 'evomembers_new_license_' . get_current_user_id() );
            echo '<div class="notice notice-success"><p><strong>Generated license:</strong> <code style="white-space:pre-wrap">' . esc_html( $plain ) . '</code> <button type="button" class="button" data-evo-copy="' . esc_attr( $plain ) . '">Copy</button></p></div>';
        }
        $verify = get_transient( 'evomembers_verify_result_' . get_current_user_id() );
        if ( is_array( $verify ) ) {
            delete_transient( 'evomembers_verify_result_' . get_current_user_id() );
            if ( ! empty( $verify['ok'] ) ) {
                $d = $verify['data'];
                echo '<div class="evo-alert evo-alert-success"><strong>Valid license</strong> · Product: ' . esc_html( (string) ( $d['product_name'] ?? '—' ) ) . ' · Activations: ' . esc_html( (string) ( $d['active_activations'] ?? 0 ) ) . '/' . esc_html( (string) ( $d['max_activations'] ?? 0 ) ) . '</div>';
            } else {
                echo '<div class="evo-alert evo-alert-danger"><strong>Verification failed:</strong> ' . esc_html( (string) ( $verify['message'] ?? 'Unknown error' ) ) . '</div>';
            }
        }

        echo '<div class="evo-grid"><div class="evo-panel"><h2>Verify a license</h2><p>Tests the same verification engine used by API clients. For site/domain/IP policies, provide the same context the client sends.</p><form method="post" class="evo-stack">';
        $this->nonce( 'verify_license_admin' );
        echo '<input type="text" name="license_key" class="regular-text" placeholder="Paste exact license key" required>';
        echo '<input type="url" name="site_url" class="regular-text" placeholder="Site URL (for site/domain policies)">';
        echo '<input type="text" name="domain" class="regular-text" placeholder="Domain (optional if Site URL is entered)">';
        echo '<input type="text" name="server_ip" class="regular-text" placeholder="Server IP (for IP/CIDR policies)">';
        echo '<input type="number" min="0" step="1" name="product_id" class="small-text" placeholder="EVO Product ID">';
        echo '<input type="text" name="client_version" class="regular-text" value="admin-test" placeholder="Client version">';
        submit_button( 'Verify license', 'secondary', 'submit', false );
        echo '</form></div><div class="evo-panel"><h2>Status meanings</h2><div class="evo-license-status-legend"><span class="evo-badge evo-badge-green">Active</span> usable <span class="evo-badge evo-badge-orange">Stopped</span> temporarily stopped <span class="evo-badge evo-badge-red">Disabled</span> revoked <span class="evo-badge evo-badge-blue">Frozen</span> suspended <span class="evo-badge evo-badge-red">Expired</span> validity ended</div><p><strong>Tip:</strong> portable keys can be checked with the key only. A bound Site/Domain/IP license must be verified with its matching context.</p></div></div>';

        $products = ( new ProductService() )->active();
        echo '<div class="evo-panel"><h2>Issue EVO license</h2><form method="post" class="evo-form">';
        $this->nonce( 'issue_license' );
        echo '<label>Product<select name="product_id" required><option value="">Select...</option>';
        foreach ( $products as $pr ) {
            echo '<option value="' . esc_attr( $pr['id'] ) . '">' . esc_html( $pr['name'] . ' (' . $pr['code'] . ')' ) . '</option>';
        }
        echo '</select></label>';
        $this->input( 'customer_email', 'Customer email', 'customer@example.com', false, 'email' );
        $this->input( 'first_name', 'First name', '', false, 'text' );
        $this->input( 'last_name', 'Last name', '', false, 'text' );
        $this->input( 'customer_id', 'Existing EVO customer ID (optional)', '0', false, 'number' );
        $this->input( 'license_count', 'Number of independent keys / sites', '1', true, 'number' );
        $this->input( 'duration_days', 'Duration days (0 = lifetime)', '0', false, 'number' );
        echo '<label><input type="checkbox" name="link_wp_user" value="1" checked> Link matching existing WordPress account</label>';
        submit_button( 'Generate & issue license' );
        echo '</form></div>';

        $rows = $service->all( 500 );
        echo '<div class="evo-license-toolbar evo-panel"><div class="evo-license-toolbar-left"><label class="evo-mini-check"><input type="checkbox" data-evo-license-select-all> Select all shown</label><span class="evo-muted">' . esc_html( (string) count( $rows ) ) . ' license(s)</span></div><div class="evo-license-toolbar-actions">';
        echo '<form method="post" id="evo-license-bulk-form" class="evo-inline-form">';
        $this->nonce( 'bulk_license_action' );
        echo '<select name="license_bulk_action" required><option value="">Bulk action…</option><option value="activate">Activate</option><option value="stop">Stop</option><option value="disable">Disable / revoke</option><option value="freeze">Freeze</option><option value="delete">Delete selected</option></select>';
        submit_button( 'Apply', 'secondary', 'submit', false );
        echo '<button type="submit" class="button button-link-delete" name="license_bulk_action" value="delete" onclick="return confirm(\'Delete the selected licenses and their activations?\');">Delete selected</button></form>';
        echo '<form method="post" class="evo-inline-form" onsubmit="return confirm(\'Delete ALL licenses and activations? This cannot be undone.\');">';
        $this->nonce( 'delete_all_licenses' );
        submit_button( 'Delete all', 'delete', 'submit', false );
        echo '</form></div></div>';

        echo '<div class="evo-license-list">';
        if ( ! $rows ) {
            echo '<div class="evo-empty">No licenses yet.</div>';
        }
        foreach ( $rows as $row ) {
            $key           = $service->get_plain_key( (int) $row['id'] );
            $status        = sanitize_key( (string) ( $row['status'] ?? 'inactive' ) );
            $verify_status = sanitize_key( (string) ( $row['verification_status'] ?? 'unverified' ) );
            $expired       = ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) < time();
            $effective     = $expired ? 'expired' : $status;
            $status_label  = array( 'active'=>'Active', 'inactive'=>'Stopped', 'disabled'=>'Disabled', 'frozen'=>'Frozen', 'expired'=>'Expired' )[ $effective ] ?? ucfirst( $effective );
            $order_ref     = ! empty( $row['source_order_id'] ) ? '#' . (string) $row['source_order_id'] : ( ! empty( $row['created_from_order_id'] ) ? '#' . (string) $row['created_from_order_id'] : '—' );
            $shown_key     = $key ?: '••••-' . (string) ( $row['license_last4'] ?? '' );
            $product_name  = (string) ( $row['product_name'] ?: ( ! empty( $row['product_id'] ) ? '#' . $row['product_id'] : 'External product' ) );
            $customer      = (string) ( $row['customer_email'] ?: ( $row['customer_id'] ?: '—' ) );

            echo '<article class="evo-license-item evo-license-status-' . esc_attr( $effective ) . '">';
            echo '<div class="evo-license-line evo-license-line-primary"><label class="evo-license-select"><input type="checkbox" name="license_ids[]" value="' . esc_attr( (string) $row['id'] ) . '" form="evo-license-bulk-form" data-evo-license-select> <span>#' . esc_html( (string) $row['id'] ) . '</span></label>';
            echo '<code class="evo-license-key">' . esc_html( $shown_key ) . '</code>';
            if ( $key ) { echo '<button type="button" class="button button-small" data-evo-copy="' . esc_attr( $key ) . '">Copy</button>'; }
            echo '<span class="evo-license-state evo-license-state-' . esc_attr( $effective ) . '">' . esc_html( $status_label ) . '</span>';
            echo '<form method="post" class="evo-license-item-action">';
            $this->nonce( 'license_item_action' );
            echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $row['id'] ) . '"><select name="license_operation" aria-label="License action"><option value="activate">Activate</option><option value="stop">Stop</option><option value="disable">Disable / revoke</option><option value="freeze">Freeze</option><option value="delete">Delete</option></select>';
            submit_button( 'Apply', 'secondary button-small', 'submit', false );
            echo '</form></div>';

            echo '<div class="evo-license-line evo-license-line-meta"><span><strong>Product:</strong> ' . esc_html( $product_name ) . '</span><span><strong>Customer:</strong> ' . esc_html( $customer ) . '</span><span><strong>Source:</strong> ' . esc_html( strtoupper( (string) ( $row['source'] ?? 'evo' ) ) ) . '</span><span><strong>Order:</strong> ' . esc_html( $order_ref ) . '</span></div>';
            echo '<div class="evo-license-line evo-license-line-meta"><span><strong>Sites:</strong> ' . esc_html( (string) ( $row['active_activations'] ?? 0 ) ) . '/' . esc_html( (string) $row['max_activations'] ) . '</span><span><strong>Verification:</strong> ' . wp_kses_post( $this->status_badge( $verify_status ) ) . ' <small>' . esc_html( (string) ( $row['verification_count'] ?? 0 ) ) . ' checks' . ( ! empty( $row['last_verified_at'] ) ? ' · ' . esc_html( (string) $row['last_verified_at'] ) : '' ) . '</small></span><span><strong>Issued:</strong> ' . esc_html( (string) $row['issued_at'] ) . '</span><span><strong>Expires:</strong> ' . esc_html( (string) ( $row['expires_at'] ?: 'Lifetime' ) ) . '</span></div>';
            echo '</article>';
        }
        echo '</div>';
        $this->end();
    }

    public function integrations(): void {
        $tab=sanitize_key((string)filter_input(INPUT_GET,'tab',FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        if(!in_array($tab,['platforms','routing','rest'],true)){$tab='platforms';}
        $this->head('Integrations','Connect external platforms, configure webhook/IPN security, route external products, and issue limited EVO REST credentials.');
        echo '<nav class="nav-tab-wrapper evo-tabs">';
        foreach(['platforms'=>'Platforms & IPN','routing'=>'Product Routing','rest'=>'EVO REST API'] as $k=>$label){
            echo '<a class="nav-tab '.($tab===$k?'nav-tab-active':'').'" href="'.esc_url(add_query_arg(['page'=>'evomembers-integrations','tab'=>$k],admin_url('admin.php'))).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';

        if('platforms'===$tab){
            $svc=new IntegrationService();
            $edit_id=absint(filter_input(INPUT_GET,'edit_integration',FILTER_SANITIZE_NUMBER_INT));
            $edit=$edit_id?$svc->get($edit_id):null;
            $integration_editor=sanitize_key((string)filter_input(INPUT_GET,'editor',FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $show_integration_editor=('new'===$integration_editor)||(bool)$edit;
            echo '<div class="evo-editor-list-toolbar"><div><strong>Integration platforms</strong><span>Keep platform list separate from create/edit settings.</span></div><a class="button button-primary" href="'.esc_url(add_query_arg(['page'=>'evomembers-integrations','tab'=>'platforms','editor'=>'new'],admin_url('admin.php'))).'">Add New Integration</a></div>';
            if($show_integration_editor){
            echo '<div class="evo-panel evo-entity-editor"><div class="evo-editor-eyebrow">INTEGRATION EDITOR</div><h2>'.esc_html($edit?'Edit integration':'New integration').'</h2><form method="post" class="evo-form evo-editor-form">';
            $this->nonce($edit?'update_integration':'create_integration');
            if($edit){echo '<input type="hidden" name="integration_id" value="'.esc_attr($edit['id']).'">';}
            $this->input('name','Platform name','JVZoo',true,'text',$edit['name']??'');
            $this->input('slug','Platform slug','jvzoo',true,'text',$edit['slug']??'');
            $this->input('api_url','Optional API URL','https://api.provider.com/',false,'url',$edit['api_url']??'');
            $this->select('status','Status',['active'=>'Active','inactive'=>'Inactive'],$edit['status']??'active');
            $this->select('security_mode','Webhook/IPN security',[
                'header'=>'Shared secret header','hmac_sha256'=>'HMAC SHA-256','bearer'=>'Bearer token','basic'=>'HTTP Basic','api_key'=>'API key header','ip_allowlist'=>'IP / CIDR allowlist','custom'=>'Custom hook/filter'
            ],$edit['security_mode']??'hmac_sha256');
            $this->input('signature_header','Signature / secret header','X-Signature',false,'text',$edit['signature_header']??'');
            $this->input('secret','Shared secret / HMAC secret','Leave blank to keep existing',false,'password','');
            $this->input('bearer_token','Bearer token','Leave blank to keep existing',false,'password','');
            $this->input('username','Basic auth username','',false,'text','');
            $this->input('password','Basic auth password','',false,'password','');
            $this->input('webhook_api_key_header','API key header','X-API-Key',false,'text','');
            $this->input('webhook_api_key','Webhook API key','Leave blank to keep existing',false,'password','');
            echo '<label class="evo-full">Allowed IPs / CIDR<textarea name="allowed_ips" rows="3" placeholder="203.0.113.10&#10;203.0.113.0/24">'.esc_textarea($edit['allowed_ips']??'').'</textarea></label>';
            echo '<label><input type="checkbox" name="link_wp_user" value="1" '.checked(!isset($edit['link_wp_user'])||!empty($edit['link_wp_user']),true,false).'> Link matching existing WordPress user when available</label>';
            submit_button($edit?'Save integration':'Create integration');
            echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=evomembers-integrations&tab=platforms')).'">Cancel</a></form></div>';
            }

            $rows=$svc->all();
            foreach($rows as $r){
                echo '<section class="evo-panel evo-integration-card"><div class="evo-card-row"><div><h3>'.esc_html($r['name']).'</h3><code>'.esc_html($r['slug']).'</code></div>'.wp_kses_post($this->status_badge((string)$r['status'])).'</div>';
                $this->webhook_box((string)$r['name'],(string)$r['slug']);
                if('gumroad'===(string)$r['slug']){echo '<div class="evo-alert evo-alert-warning"><strong>Gumroad:</strong> Unsigned webhook requests are rejected in this WordPress.org build. Use a provider-specific verification adapter or another authenticated integration path before enabling fulfillment.</div>';}
                echo '<p><strong>Security:</strong> '.esc_html((string)$r['security_mode']).'</p>';
                echo '<div class="evo-actions"><a class="button" href="'.esc_url(add_query_arg(['page'=>'evomembers-integrations','tab'=>'platforms','edit_integration'=>(int)$r['id']],admin_url('admin.php'))).'">Edit</a></div></section>';
            }
            $this->end(); return;
        }


        if('woocommerce'===$tab){
            $enabled = (bool) get_option( 'evomembers_woocommerce_enabled', false );
            $installed = class_exists( 'WooCommerce' ) || function_exists( 'wc_get_products' );
            echo '<div class="evo-panel evo-entity-editor"><div class="evo-editor-eyebrow">LOCAL INTEGRATION</div><h2>WooCommerce</h2>';
            echo '<p>WooCommerce remains responsible for catalog price, cart, checkout, payment and the native order. Evoxup handles the linked EVO Product identity, Membership Plan, licensing and entitlements only after a successful local WooCommerce event.</p>';
            echo '<div class="evo-grid"><div class="evo-check-card"><strong>WooCommerce status</strong><p>' . esc_html( $installed ? 'Installed / available' : 'Not installed' ) . '</p></div><div class="evo-check-card"><strong>Connection</strong><p>Local WordPress hooks only — no Evoxup Repository or external control plane is required.</p></div></div>';
            echo '<form method="post" class="evo-form evo-editor-form">';
            $this->nonce('save_woocommerce_integration');
            echo '<input type="hidden" name="woocommerce_enabled" value="0"><label class="evo-check-card evo-full"><input type="checkbox" name="woocommerce_enabled" value="1" ' . checked( $enabled, true, false ) . ' ' . disabled( ! $installed, true, false ) . '><span><strong>Enable WooCommerce integration</strong><small>Enable EVO product mapping, membership fulfillment and optional EVO licensing for WooCommerce purchases.</small></span></label>';
            echo '<div class="evo-editor-section evo-full"><h3>Responsibility boundary</h3><ul><li><strong>WooCommerce:</strong> price, sale price, currency, checkout, payment and order.</li><li><strong>Evoxup:</strong> EVO Product mapping, Membership Plan, entitlements and license policy.</li></ul></div>';
            submit_button('Save WooCommerce integration');
            echo '</form></div>';
            $this->end(); return;
        }

        if('routing'===$tab){
            $mapping_svc=new MappingService(); $integrations=(new IntegrationService())->all(); $products=(new ProductService())->active(); $wc=(new ProductService())->woocommerce_products();
            echo '<div class="evo-panel"><h2>Product Routing</h2><p>Map the product identifier sent by an external platform to its real target. Routing does not own licensing: EVO products use their own Product settings, while WooCommerce products choose their license provider inside the WooCommerce product editor.</p></div>';
            if(!$wc && function_exists('wc_get_products')){echo '<div class="evo-alert evo-alert-warning"><strong>No WooCommerce products are in EVO discovery yet.</strong> Open Products → WooCommerce and run Discover / Sync WooCommerce, then return here.</div>';}
            if(!$integrations){echo '<div class="evo-alert evo-alert-warning">Add a platform first.</div>';} else {
                echo '<form method="post" class="evo-form evo-routing-form">'; $this->nonce('save_mapping');
                echo '<label>Platform<select name="integration_id" required><option value="">Select platform</option>'; foreach($integrations as $i){echo '<option value="'.esc_attr($i['id']).'">'.esc_html($i['name']).'</option>';} echo '</select></label>';
                $this->input('external_product_id','External product ID / permalink','Gumroad product_id, short_product_id, JVZoo product ID...',true,'text','');
                $this->input('external_variant_id','Variant / price ID (optional)','',false,'text','');
                $this->select('target_type','Target',['evo'=>'EVO internal product','woocommerce'=>'WooCommerce product'],'evo');
                echo '<label class="evo-route-target evo-route-evo">EVO product<select name="target_id_evo"><option value="">Select EVO product</option>'; foreach($products as $p){echo '<option value="'.esc_attr($p['id']).'">'.esc_html($p['name'].' ('.$p['code'].')').'</option>';} echo '</select></label>';
                echo '<label class="evo-route-target evo-route-wc">WooCommerce product<select name="target_id_wc"><option value="">Select WooCommerce product</option>'; foreach($wc as $w){$native_wc_id=absint($w['external_source_id']??0); if($native_wc_id<1){continue;} echo '<option value="'.esc_attr((string)$native_wc_id).'">'.esc_html($w['name'].' #'.$native_wc_id.' · '.$w['code']).'</option>';} echo '</select></label>';
                echo '<div class="evo-alert evo-alert-info evo-full"><strong>Licensing is inherited from the target product.</strong> Configure EVO products under Products. For WooCommerce products, edit the WooCommerce product and choose <em>License provider → EVO License</em> only when EVO should issue the key.</div>';
                submit_button('Save route'); echo '</form>';
            }
            $rows=$mapping_svc->all();
            echo '<table class="widefat striped evo-table"><thead><tr><th>Platform</th><th>External ID</th><th>Target</th><th>License ownership</th><th>Actions</th></tr></thead><tbody>';
            if(!$rows){echo '<tr><td colspan="5">No routes yet.</td></tr>';}
            foreach($rows as $r){echo '<tr><td>'.esc_html($r['platform_name']??'—').'</td><td><code>'.esc_html($r['external_product_id']).'</code></td><td><strong>'.esc_html(strtoupper($r['target_type']??'evo')).'</strong><br>'.esc_html($r['target_name']??'—').'</td><td><span class="evo-badge evo-badge-gray">Target product</span></td><td><form method="post" onsubmit="return confirm(\'Delete this route?\');">'; $this->nonce('delete_mapping'); echo '<input type="hidden" name="mapping_id" value="'.esc_attr($r['id']).'">'; submit_button('Delete','delete button-small','submit',false); echo '</form></td></tr>';}
            echo '</tbody></table>'; $this->end(); return;
        }

        $rest = new RestKeyService();
        $created_key = get_transient( 'evomembers_rest_key_once_' . get_current_user_id() );
        if ( is_array( $created_key ) ) {
            delete_transient( 'evomembers_rest_key_once_' . get_current_user_id() );
            $created_public = sanitize_text_field( (string) ( $created_key['public_id'] ?? '' ) );
            $created_secret = ! empty( $created_key['secret_encrypted'] ) ? Crypto::decrypt( (string) $created_key['secret_encrypted'] ) : (string) ( $created_key['secret'] ?? '' );
            echo '<div class="evo-alert evo-alert-success evo-rest-key-created"><strong>REST credential created — copy the secret now.</strong><p>The Public / Client ID remains visible. The Secret is hidden by default after this message and can be revealed again only by an authorized EVO administrator.</p>';
            echo '<div class="evo-secret-row"><span>Public / Client ID</span><code>' . esc_html( $created_public ) . '</code><button type="button" class="button button-small" data-evo-copy="' . esc_attr( $created_public ) . '">Copy</button></div>';
            echo '<div class="evo-secret-row"><span>Secret</span><code class="evo-secret-once">' . esc_html( $created_secret ) . '</code><button type="button" class="button button-small" data-evo-copy="' . esc_attr( $created_secret ) . '">Copy secret</button></div></div>';
        }
        echo '<div class="evo-panel"><h2>EVO REST API keys</h2><p>Create separate server-to-server credentials. The public/client ID identifies the credential; the secret authenticates it and is shown only once after creation.</p></div>';
        echo '<form method="post" class="evo-form">'; $this->nonce( 'create_rest_key' ); $this->input( 'name', 'Key name', 'Repository Authority', true, 'text', '' );
        echo '<label class="evo-full">Permissions<div class="evo-permission-grid">';
        foreach ( array( 'verify_license'=>'Verify licenses', 'manage_licenses'=>'Activate / deactivate licenses', 'read_products'=>'Read products', 'manage_products'=>'Manage products', 'read_webhooks'=>'Read webhook inbox', 'write_webhooks'=>'Submit webhooks', 'read_customers'=>'Read customers' ) as $k=>$v ) { echo '<label><input type="checkbox" name="permissions[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, array( 'verify_license','read_products' ), true ), true, false ) . '> ' . esc_html( $v ) . '</label>'; }
        echo '</div></label><label class="evo-full">Allowed IPs / CIDR (optional)<textarea name="allowed_ips" rows="3"></textarea></label>'; submit_button( 'Generate REST credential' ); echo '</form>';
        $keys = $rest->all(); echo '<table class="widefat striped evo-table"><thead><tr><th>Name</th><th>Public / Client ID</th><th>Secret</th><th>Permissions</th><th>IP limit</th><th>Status</th><th>Last used</th><th></th></tr></thead><tbody>';
        if ( ! $keys ) { echo '<tr><td colspan="8">No REST keys.</td></tr>'; }
        foreach ( $keys as $k ) {
            $public_id = sanitize_text_field( (string) ( $k['public_id'] ?? '' ) );
            $legacy = '' === $public_id;
            echo '<tr><td>' . esc_html( $k['name'] ) . '</td><td>';
            if ( $legacy ) { echo '<code>' . esc_html( $k['key_prefix'] ) . '…</code> <small>(legacy credential)</small>'; }
            else { echo '<code>' . esc_html( $public_id ) . '</code> <button type="button" class="button button-small" data-evo-copy="' . esc_attr( $public_id ) . '">Copy</button>'; }
            echo '</td><td><code>' . esc_html( $k['key_prefix'] ) . '…</code><br><small>Hidden by default · authorized reveal available.</small></td><td>' . esc_html( implode( ', ', (array) json_decode( (string) $k['permissions_json'], true ) ) ) . '</td><td>' . esc_html( $k['allowed_ips'] ?: 'Any' ) . '</td><td>' . wp_kses_post( $this->status_badge( (string) $k['status'] ) ) . '</td><td>' . esc_html( $k['last_used_at'] ?: '—' ) . '</td><td>';
            if ( 'active' === $k['status'] ) {
                echo '<form method="post" style="display:inline-block;margin-right:6px">'; $this->nonce( 'reveal_rest_key' ); echo '<input type="hidden" name="id" value="' . esc_attr( $k['id'] ) . '">'; submit_button( 'Reveal / Copy', 'secondary button-small', 'submit', false ); echo '</form>';
                echo '<form method="post" style="display:inline-block;margin-right:6px">'; $this->nonce( 'revoke_rest_key' ); echo '<input type="hidden" name="id" value="' . esc_attr( $k['id'] ) . '">'; submit_button( 'Revoke', 'secondary button-small', 'submit', false ); echo '</form>';
            }
            $delete_confirm = 'Permanently delete REST key “' . sanitize_text_field( (string) $k['name'] ) . '”? This cannot be undone.';
            echo '<form method="post" style="display:inline-block" onsubmit="return confirm(' . esc_attr( wp_json_encode( $delete_confirm ) ) . ');">'; $this->nonce( 'delete_rest_key' ); echo '<input type="hidden" name="id" value="' . esc_attr( $k['id'] ) . '">'; submit_button( 'Delete', 'delete button-small', 'submit', false ); echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>'; $this->end();
    }
    public function webhooks(): void {
        $svc=new WebhookService();
        $this->head('Webhook Inbox','Inspect and maintain received webhook/IPN data. Platform configuration and security now live under Integrations.');
        echo '<div class="evo-grid">'; $this->card('Received',$svc->count_all()); $this->card('Processed',$svc->count_status('processed')); $this->card('Failed',$svc->count_status('failed')); $this->card('Pending',$svc->count_status('received')); echo '</div>';
        echo '<div class="evo-panel"><div class="evo-card-row"><div><h2>Inbox maintenance</h2><p>Cleanup affects only stored webhook history; it never removes platform configuration.</p></div><div class="evo-actions">';
        echo '<form method="post">';$this->nonce('cleanup_webhooks');$this->input('cleanup_days','Keep days','30',false,'number','30');submit_button('Clean old events','secondary','submit',false);echo '</form>';
        echo '<form method="post" onsubmit="return confirm(\'Delete ALL webhook history?\');">';$this->nonce('clear_webhooks');submit_button('Delete all history','delete','submit',false);echo '</form></div></div></div>';
        $rows=$svc->all(200);
        echo '<table class="widefat striped evo-table"><thead><tr><th>ID</th><th>Platform</th><th>Event</th><th>Received</th><th>Verify</th><th>Process</th><th>Source IP</th><th>Error</th><th>Data</th><th>Actions</th></tr></thead><tbody>';
        if(!$rows){echo '<tr><td colspan="10">No webhook events received.</td></tr>';}
        foreach($rows as $r){
            $payload=(string)($r['payload_json']??''); $normalized=(string)($r['normalized_json']??'');
            $error_code=(string)($r['error_code']??''); $error_message=(string)($r['error_message']??'');
            $error_html='—';
            if(''!==$error_code||''!==$error_message){$error_html='<code>'.esc_html($error_code?:'error').'</code>'.(''!==$error_message?'<br><small>'.esc_html($error_message).'</small>':'');}
            echo '<tr><td>'.esc_html($r['id']).'</td><td>'.esc_html($r['provider_slug']??'—').'</td><td>'.esc_html($r['event_type']??'—').'</td><td>'.esc_html($r['received_at']??'—').'</td><td>'.wp_kses_post($this->status_badge((string)($r['verification_status']??''))).'</td><td>'.wp_kses_post($this->status_badge((string)($r['processing_status']??''))).'</td><td><code>'.esc_html($r['source_ip']??'—').'</code></td><td>'.wp_kses_post($error_html).'</td><td><details><summary>View raw / normalized</summary><h4>Raw payload</h4><pre class="evo-json-view">'.esc_html($payload).'</pre><h4>Normalized</h4><pre class="evo-json-view">'.esc_html($normalized).'</pre></details></td><td><form method="post">';$this->nonce('retry_webhook_event');echo '<input type="hidden" name="webhook_event_id" value="'.esc_attr($r['id']).'">';submit_button('Replay','secondary button-small','submit',false);echo '</form></td></tr>';
        }
        echo '</tbody></table>'; $this->end();
    }
    public function modules(): void {
        $this->head(
            'Extensions & Add-ons',
            'Enable bundled Lite modules locally, or discover separately distributed PRO and SUPER STAR add-ons. Premium code is never included or downloaded by this WordPress.org build.'
        );

        $requested_module = sanitize_key( (string) filter_input( INPUT_GET, 'module', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        $installed = ( new ModuleLoader() )->discover();
        $active = get_option( 'evomembers_active_modules', array() );
        $active = is_array( $active ) ? array_map( 'sanitize_key', $active ) : array();

        if ( '' !== $requested_module && isset( $installed[ $requested_module ] ) ) {
            $module = $installed[ $requested_module ];
            $is_active = in_array( $requested_module, $active, true );
            $dashboard = is_array( $module['dashboard'] ?? null ) ? $module['dashboard'] : array();
            echo '<section class="evomembers-addon-detail"><div class="evomembers-addon-detail-head"><div><span class="evo-lite-badge">LITE MODULE</span><h2>' . esc_html( (string) ( $module['name'] ?? $requested_module ) ) . '</h2><p>' . esc_html( (string) ( $module['description'] ?? '' ) ) . '</p></div>' . wp_kses_post( $this->status_badge( $is_active ? 'active' : 'installed' ) ) . '</div>';
            echo '<div class="evomembers-addon-detail-grid"><div><strong>Version</strong><span>' . esc_html( (string) ( $module['version'] ?? '—' ) ) . '</span></div><div><strong>Category</strong><span>' . esc_html( (string) ( $dashboard['category'] ?? 'Lite module' ) ) . '</span></div><div><strong>Runtime</strong><span>' . esc_html( $is_active ? 'Enabled locally' : 'Available to enable' ) . '</span></div></div>';
            if ( ! empty( $dashboard['usage'] ) ) { echo '<div class="evomembers-addon-usage"><strong>Usage</strong><code>' . esc_html( (string) $dashboard['usage'] ) . '</code></div>'; }
            echo '<div class="evomembers-addon-detail-actions"><a class="button" href="' . esc_url( add_query_arg( 'page', 'evomembers-modules', admin_url( 'admin.php' ) ) ) . '">← All add-ons</a>';
            if ( $is_active && ! empty( $dashboard['admin_page'] ) ) {
                echo '<a class="button button-primary" href="' . esc_url( add_query_arg( 'page', sanitize_key( (string) $dashboard['admin_page'] ), admin_url( 'admin.php' ) ) ) . '">Open module</a>';
            }
            echo '</div></section>';
        }

        echo '<div class="evo-addon-hero">';
        echo '<div class="evo-addon-hero-copy"><span class="evo-addon-kicker">BUILD YOUR MEMBERSHIP STACK</span><h2>Start free. Add only what your site needs.</h2><p>Lite modules below are included in Evoxup Membership 1.8.6 and run entirely on this WordPress site. PRO and SUPER STAR cards are previews of separate products only.</p><div class="evo-addon-hero-pills"><span>✓ No feature locks</span><span>✓ No background downloads</span><span>✓ Local enable / disable</span></div></div>';
        echo '<div class="evo-addon-hero-badge"><strong>1.8.6</strong><span>WordPress.org build</span></div>';
        echo '</div>';

        $installed = ( new ModuleLoader() )->discover();
        $active = get_option( 'evomembers_active_modules', array() );
        $active = is_array( $active ) ? array_map( 'sanitize_key', $active ) : array();
        $tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) );
        $allowed_tabs = array( 'installed', 'free', 'pro', 'super' );
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'installed';
        }

        $tabs = array(
            'installed' => 'Installed',
            'free'      => 'Available / FREE',
            'pro'       => 'Premium / PRO',
            'super'     => 'Premium / SUPER STAR',
        );
        echo '<nav class="nav-tab-wrapper evomembers-addon-tabs" aria-label="Add-on catalog">';
        foreach ( $tabs as $tab_id => $tab_label ) {
            $tab_url = add_query_arg( array( 'page' => 'evomembers-modules', 'tab' => $tab_id ), admin_url( 'admin.php' ) );
            $tab_class = 'nav-tab' . ( $tab === $tab_id ? ' nav-tab-active' : '' );
            echo '<a class="' . esc_attr( $tab_class ) . '" href="' . esc_url( $tab_url ) . '">' . esc_html( $tab_label ) . '</a>';
        }
        echo '</nav>';

        if ( 'installed' === $tab || 'free' === $tab ) {
            $module_pool = array_filter(
                $installed,
                static function ( array $module, string $id ) use ( $active, $tab ): bool {
                    $is_active = in_array( $id, $active, true );
                    return 'installed' === $tab ? $is_active : ! $is_active;
                },
                ARRAY_FILTER_USE_BOTH
            );
            $section_title = 'installed' === $tab ? 'Installed' : 'Available Free';
            $section_intro = 'installed' === $tab
                ? 'Enabled Lite modules running locally on this WordPress site.'
                : 'Bundled Lite modules ready to enable. Every feature shown here is included in this ZIP and works without a paid key.';

            echo '<section class="evo-addon-tier evo-addon-tier-free"><div class="evo-addon-tier-head"><div><span class="evo-tier-label evo-tier-free">FREE</span><h2>' . esc_html( $section_title ) . '</h2><p>' . esc_html( $section_intro ) . '</p></div><span class="evo-tier-count">' . esc_html( (string) count( $module_pool ) ) . ' modules</span></div>';
            echo '<div class="evo-module-grid">';
            if ( empty( $module_pool ) ) {
                echo '<div class="evo-empty">' . esc_html( 'installed' === $tab ? 'No Lite modules are enabled yet. Open Available / FREE to enable one.' : 'All bundled Lite modules are already enabled.' ) . '</div>';
            }
            foreach ( $module_pool as $id => $module ) {
            $on = in_array( $id, $active, true );
            $compat = ! empty( $module['compatibility']['compatible'] );
            echo '<article class="evo-module-card evo-lite-card">';
            echo '<div class="evo-module-card-head"><div><span class="evo-lite-badge">LITE</span><h3>' . esc_html( (string) ( $module['name'] ?? $id ) ) . '</h3><span>v' . esc_html( (string) ( $module['version'] ?? '—' ) ) . '</span></div>' . wp_kses_post( $this->status_badge( $compat ? ( $on ? 'active' : 'installed' ) : 'blocked' ) ) . '</div>';
            echo '<p>' . esc_html( (string) ( $module['description'] ?? 'Local Evoxup Lite module.' ) ) . '</p>';
            $dashboard = is_array( $module['dashboard'] ?? null ) ? $module['dashboard'] : array();
            $detail_url = add_query_arg( array( 'page'=>'evomembers-modules', 'module'=>$id ), admin_url( 'admin.php' ) );
            echo '<div class="evomembers-addon-links"><a class="button button-small" href="' . esc_url( $detail_url ) . '">Details</a>';
            if ( $on && ! empty( $dashboard['admin_page'] ) ) {
                echo '<a class="button button-small button-primary" href="' . esc_url( add_query_arg( 'page', sanitize_key( (string) $dashboard['admin_page'] ), admin_url( 'admin.php' ) ) ) . '">Open</a>';
            }
            echo '</div>';
            if ( ! empty( $module['compatibility']['warnings'] ) ) {
                echo '<p class="evo-addon-note">' . esc_html( implode( ' ', (array) $module['compatibility']['warnings'] ) ) . '</p>';
            }
            if ( $compat ) {
                echo '<form method="post" class="evo-addon-card-action">';
                $this->nonce( 'toggle_module' );
                echo '<input type="hidden" name="module_id" value="' . esc_attr( $id ) . '">';
                submit_button( $on ? 'Disable module' : 'Enable module', $on ? 'secondary' : 'primary', 'submit', false );
                echo '</form>';
            } else {
                echo '<p class="evo-addon-note evo-addon-note-error">' . esc_html( implode( ' ', (array) ( $module['compatibility']['errors'] ?? array( 'This module is not compatible with the current environment.' ) ) ) ) . '</p>';
            }
                echo '</article>';
            }
            echo '</div></section>';
        }

        $pro = array(
            array( 'Affiliate', 'Referral and partner workflows with commission-ready reporting.' ),
            array( 'Analytics', 'Deeper membership, product and conversion analytics.' ),
            array( 'Backup Manager', 'Purpose-built backup and recovery workflows for Evoxup data.' ),
            array( 'Chat', 'Member communication and support conversations.' ),
            array( 'Connector REST', 'Dedicated REST connector for external application integrations.' ),
            array( 'Connector Webhook', 'Advanced outbound and inbound webhook connectivity.' ),
            array( 'Groups & Referrals', 'Member groups, referrals and network-oriented workflows.' ),
            array( 'Integration Hub', 'Centralized integration orchestration for connected services.' ),
            array( 'License API', 'External license verification and application integration endpoints.' ),
            array( 'LMS', 'Learning and membership integration for courses and access.' ),
            array( 'Market Products', 'Extended product catalog and market presentation tools.' ),
            array( 'Points & Reputation', 'Points, reputation and engagement mechanics.' ),
            array( 'Ratings & Reviews', 'Structured ratings, reviews and moderation tools.' ),
            array( 'Site Toolkit', 'A practical toolkit for membership-site operations.' ),
            array( 'Social Facebook', 'Separate Facebook identity/connectivity add-on.' ),
            array( 'Social GitHub', 'Separate GitHub identity/connectivity add-on.' ),
            array( 'Social Google', 'Separate Google identity/connectivity add-on.' ),
            array( 'Social Identity', 'Advanced social identity and account-linking workflows.' ),
            array( 'Social X', 'Separate X identity/connectivity add-on.' ),
            array( 'System Health Pro', 'Expanded operational diagnostics beyond the bundled Lite checks.' ),
            array( 'WooCommerce Pro', 'Advanced WooCommerce administration and commerce workflows.' ),
        );
        $super = array(
            array( 'Admin Center', 'Central administration workspace for larger Evoxup installations.' ),
            array( 'Automations', 'Rule-driven automation across membership and licensing events.' ),
            array( 'Billing', 'Advanced billing operations and commercial lifecycle tooling.' ),
            array( 'Central Audit', 'Extended audit visibility and operational governance.' ),
            array( 'Commerce Offers', 'Advanced offers and commerce campaign capabilities.' ),
            array( 'License API Advanced', 'Expanded licensing API controls and enterprise integrations.' ),
            array( 'LMS Builder', 'Advanced learning-program construction and membership delivery.' ),
            array( 'Member Administration', 'Dedicated member operations workspace for support teams.' ),
            array( 'Payment PayPal', 'Separate PayPal payment integration package.' ),
            array( 'Payment Stripe', 'Separate Stripe payment integration package.' ),
            array( 'Payment WooCommerce', 'Advanced payment handoff for WooCommerce environments.' ),
            array( 'Release & Site Manager', 'Release coordination and multi-site operational tooling.' ),
            array( 'System Health Advanced', 'Advanced diagnostics, governance and operational insights.' ),
        );

        if ( 'pro' === $tab ) {
            $this->render_premium_addon_tier( 'PRO', $pro, 'For growing membership businesses that need deeper operations and integrations.', 'evo-tier-pro' );
        } elseif ( 'super' === $tab ) {
            $this->render_premium_addon_tier( 'SUPER STAR', $super, 'For advanced operations, automation, administration and large-scale commercial workflows.', 'evo-tier-super' );
        }

        if ( 'pro' === $tab || 'super' === $tab ) {
            echo '<div class="evo-addon-compliance-note"><span class="dashicons dashicons-shield-alt"></span><div><strong>Separate premium add-ons</strong><p>Premium cards are informational. Premium executable code is not bundled, fetched, installed, activated or unlocked by this WordPress.org build. Learn more opens the Evoxup Membership product page.</p></div></div>';
        }
        $this->end();
    }

    /** @param array<int,array{0:string,1:string}> $items */
    private function render_premium_addon_tier( string $tier, array $items, string $intro, string $tier_class ): void {
        echo '<section class="evo-addon-tier ' . esc_attr( $tier_class ) . '"><div class="evo-addon-tier-head"><div><span class="evo-tier-label">' . esc_html( $tier ) . '</span><h2>' . esc_html( $tier . ' add-ons' ) . '</h2><p>' . esc_html( $intro ) . '</p></div><span class="evo-tier-count">' . esc_html( (string) count( $items ) ) . ' add-ons</span></div>';
        echo '<div class="evo-premium-grid">';
        foreach ( $items as $item ) {
            echo '<article class="evo-premium-card"><div class="evo-premium-card-top"><span class="evo-premium-orb"><span class="dashicons dashicons-admin-plugins"></span></span><span class="evo-premium-tier-chip">' . esc_html( $tier ) . '</span></div><h3>' . esc_html( $item[0] ) . '</h3><p>' . esc_html( $item[1] ) . '</p><div class="evo-premium-card-footer"><span>Separate add-on</span><a class="button" href="' . esc_url( 'https://evoxup.com/evo-membership' ) . '" target="_blank" rel="noopener noreferrer">Learn more</a></div></article>';
        }
        echo '</div></section>';
    }

    public function access(): void {
        $this->head( 'Access & Roles', 'Assign Evoxup management roles and granular capabilities to WordPress users. Administrator keeps full WordPress control.' );
        $user_id = absint( filter_input( INPUT_GET, 'user_id', FILTER_SANITIZE_NUMBER_INT ) );
        $users = get_users( array( 'number'=>200, 'orderby'=>'display_name', 'order'=>'ASC', 'fields'=>'all' ) );
        $labels = Capabilities::labels();
        $groups = Capabilities::grouped_labels();
        if ( $user_id > 0 ) {
            $selected = get_userdata( $user_id );
            if ( $selected ) {
                echo '<div class="evo-panel"><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=evomembers-access' ) ) . '">← All users</a></p><h2>' . esc_html( $selected->display_name ?: $selected->user_login ) . '</h2><p><code>' . esc_html( $selected->user_email ) . '</code></p><form method="post" class="evo-form">';
                $this->nonce( 'save_user_access' );
                echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
                echo '<label>Base role<select name="base_role"><option value="">Keep current role</option>';
                $role_options = array();
                foreach ( Capabilities::role_blueprints() as $role_slug => $blueprint ) { $role_options[ $role_slug ] = $blueprint['name']; }
                $role_options['customer'] = 'Customer';
                $role_options['subscriber'] = 'Subscriber';
                foreach ( $role_options as $role=>$label ) {
                    $role_caps = isset( Capabilities::role_blueprints()[ $role ] ) ? Capabilities::role_blueprints()[ $role ]['caps'] : array();
                    echo '<option value="' . esc_attr( $role ) . '" data-evox-capabilities="' . esc_attr( implode( ',', $role_caps ) ) . '">' . esc_html( $label ) . '</option>';
                }
                echo '</select><small>Choosing an Evoxup role loads its safe default capability preset below. You may then fine-tune it. Administrators are never downgraded here.</small></label>';
                echo '<fieldset class="evo-full evo-check-card"><legend><strong>EVO capabilities</strong></legend>';
                foreach ( $groups as $group_name => $group_caps ) {
                    echo '<h3>' . esc_html( $group_name ) . '</h3><div class="evo-permission-grid">';
                    foreach ( $group_caps as $capability=>$label ) { echo '<label><input type="checkbox" name="capabilities[]" value="' . esc_attr( $capability ) . '" ' . checked( user_can( $selected, $capability ), true, false ) . '> ' . esc_html( $label ) . '<br><small><code>' . esc_html( $capability ) . '</code></small></label>'; }
                    echo '</div>';
                }
                echo '</fieldset>'; submit_button( 'Save EVO access' ); echo '</form></div>';
                $this->end();
                return;
            }
        }
        echo '<div class="evo-panel"><h2>WordPress users</h2><p>Open a user to grant only the EVO permissions needed for their job or product ownership.</p><table class="widefat striped evo-table"><thead><tr><th>User</th><th>Email</th><th>Roles</th><th>EVO access</th><th></th></tr></thead><tbody>';
        foreach ( $users as $user ) {
            $evo_caps = array(); foreach ( Capabilities::all() as $capability ) { if ( user_can( $user, $capability ) ) { $evo_caps[] = $labels[$capability] ?? $capability; } }
            $url = add_query_arg( array( 'page'=>'evomembers-access','user_id'=>(int) $user->ID ), admin_url( 'admin.php' ) );
            echo '<tr><td><strong>' . esc_html( $user->display_name ?: $user->user_login ) . '</strong><br><small>#' . esc_html( (string) $user->ID ) . ' · ' . esc_html( $user->user_login ) . '</small></td><td>' . esc_html( $user->user_email ) . '</td><td>' . esc_html( implode( ', ', (array) $user->roles ) ) . '</td><td>' . esc_html( $evo_caps ? implode( ', ', array_slice( $evo_caps, 0, 4 ) ) . ( count( $evo_caps ) > 4 ? '…' : '' ) : 'None' ) . '</td><td><a class="button button-small" href="' . esc_url( $url ) . '">Manage access</a></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->end();
    }

    public function settings(): void {
        $this->head( 'Settings', 'Configure the Evoxup connection, API routing, Membership Center structure and frontend appearance.' );
        $settings = get_option( 'evomembers_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $center = CenterSettings::get();
        $config = new ApiConfig();
        $center_url = ( new PurchaseLinkService() )->membership_center_url();
        $center_id  = absint( get_option( 'evomembers_center_page_id', get_option( 'evomembers_my_membership_page_id', 0 ) ) );
        $api_test = get_transient( 'evomembers_api_test_' . get_current_user_id() );
        if ( is_array( $api_test ) ) {
            delete_transient( 'evomembers_api_test_' . get_current_user_id() );
            echo '<div class="evo-alert ' . ( ! empty( $api_test['ok'] ) ? 'evo-alert-success' : 'evo-alert-danger' ) . '">' . esc_html( (string) ( $api_test['message'] ?? '' ) ) . '</div>';
        }
        $external_snapshot = get_option( 'evomembers_external_api_discovery_snapshot', array() );
        $external_snapshot = is_array( $external_snapshot ) ? $external_snapshot : array();

        echo '<form method="post" class="evo-settings-form">';
        wp_nonce_field( 'evomembers_admin_action', 'evomembers_nonce' );

        echo '<div class="evo-panel"><h2>WordPress.org local configuration</h2><p>This build does not require an Evoxup Repository license, Marketplace connection or paid feature unlock.</p></div>';

        echo '<div class="evo-panel"><h2>API routing &amp; cooperative authority</h2><p>Choose local-only, remote-only, or Hybrid. Hybrid verifies and executes locally first, then uses the external EVO node only when the requested record is not owned locally. Remote integration endpoints always execute against their own local canonical data, preventing routing loops.</p><div class="evo-api-mode-cards">';
        echo '<label class="evo-api-mode-card"><input type="radio" name="api_mode" value="internal" ' . checked( 'internal', $config->mode(), false ) . '><span><strong>Internal API</strong><small>' . esc_html( $config->internal_base_url() ) . '</small></span></label>';
        echo '<label class="evo-api-mode-card"><input type="radio" name="api_mode" value="external" ' . checked( 'external', $config->mode(), false ) . '><span><strong>External API</strong><small>Use the external EVO node as the authority.</small></span></label>';
        echo '<label class="evo-api-mode-card"><input type="radio" name="api_mode" value="hybrid" ' . checked( 'hybrid', $config->mode(), false ) . '><span><strong>Internal + External</strong><small>Local-first cooperative authority with safe remote fallback.</small></span></label></div>';
        echo '<div class="evo-external-api-field" data-evo-api-mode="external,hybrid"><div class="evo-form">';
        echo '<label class="evo-full">External API base URL<input type="url" name="external_url" value="' . esc_attr( (string) ( $settings['external_url'] ?? '' ) ) . '" placeholder="https://remote.example.com/wp-json/evomembers/v1"></label>';
        echo '<label>Public / Client ID<input type="text" name="external_client_id" value="' . esc_attr( (string) ( $settings['external_client_id'] ?? '' ) ) . '" placeholder="evomembers_pub_..."></label>';
        echo '<label>Secret<input type="password" name="external_secret" value="" autocomplete="new-password" placeholder="' . ( '' !== $config->external_secret_last4() ? 'Saved · •••• ' . esc_attr( $config->external_secret_last4() ) : 'evomembers_sec_...' ) . '"></label>';
        if ( '' !== $config->external_secret_last4() ) { echo '<label class="evo-mini-check evo-full"><input type="checkbox" name="clear_external_secret" value="1"> Remove saved External API secret</label>'; }
        echo '<div class="evo-alert evo-alert-info evo-full"><strong>Server-to-server only.</strong> The secret is encrypted at rest and is never sent to browsers or customer plugins. Public clients should use their own license key, not this service credential.</div>';
        echo '</div></div>';
        echo '<p><strong>Routing mode:</strong> <code>' . esc_html( strtoupper( $config->mode() ) ) . '</code> · <strong>Internal:</strong> <code>' . esc_html( $config->internal_base_url() ) . '</code>';
        if ( $config->uses_external() ) { echo ' · <strong>External:</strong> <code>' . esc_html( $config->external_base_url() ?: 'not configured' ) . '</code>'; }
        echo '</p><div class="evo-action-row"><button type="submit" class="button button-primary" name="evomembers_action" value="save_settings">Save all settings</button> <button type="submit" class="button" name="evomembers_action" value="discover_external_api">Discover capabilities</button> <button type="submit" class="button" name="evomembers_action" value="test_api_settings">Run readiness test</button></div>';

        $snapshot_data = is_array( $external_snapshot['data'] ?? null ) ? $external_snapshot['data'] : array();
        if ( $snapshot_data ) {
            $server_caps = is_array( $snapshot_data['server_capabilities'] ?? null ) ? $snapshot_data['server_capabilities'] : array();
            $effective_caps = is_array( $snapshot_data['effective_capabilities'] ?? null ) ? $snapshot_data['effective_capabilities'] : array();
            $credential_permissions = is_array( $snapshot_data['credential_permissions'] ?? null ) ? $snapshot_data['credential_permissions'] : array();
            echo '<div class="evo-api-discovery"><h3>External API capability discovery</h3><p><strong>Last discovery:</strong> ' . esc_html( (string) ( $external_snapshot['discovered_at'] ?? '—' ) ) . ' · <strong>Remote:</strong> ' . esc_html( (string) ( $snapshot_data['plugin'] ?? 'EVO-compatible API' ) ) . ' ' . esc_html( (string) ( $snapshot_data['version'] ?? '' ) ) . '</p>';
            if ( ! $server_caps && is_array( $snapshot_data['capabilities'] ?? null ) ) {
                echo '<div class="evo-alert evo-alert-warning"><strong>Legacy capability discovery.</strong> This remote node reports: ' . esc_html( implode( ', ', array_map( 'sanitize_text_field', (array) $snapshot_data['capabilities'] ) ) ) . '. Credential-level qualification is unavailable until that node is upgraded.</div>';
            }
            echo '<table class="widefat striped evo-table"><thead><tr><th>Capability</th><th>Server</th><th>Credential</th><th>Effective</th></tr></thead><tbody>';
            $permission_map = array( 'licenses.verify'=>'verify_license', 'licenses.activate'=>'manage_licenses', 'licenses.deactivate'=>'manage_licenses', 'products.read'=>'read_products', 'products.manage'=>'manage_products', 'webhooks.read'=>'read_webhooks', 'webhooks.submit'=>'write_webhooks', 'customers.read'=>'read_customers' );
            foreach ( $server_caps as $capability => $supported ) {
                $required_permission = (string) ( $permission_map[ $capability ] ?? '' );
                $credential_ok = '' === $required_permission || in_array( $required_permission, $credential_permissions, true );
                $effective = array_key_exists( $capability, $effective_caps ) ? ! empty( $effective_caps[ $capability ] ) : ( ! empty( $supported ) && $credential_ok );
                echo '<tr><td><code>' . esc_html( (string) $capability ) . '</code></td><td>' . ( $supported ? '✓ Supported' : '— Not exposed' ) . '</td><td>' . ( $credential_ok ? '✓ Allowed' : '— Not allowed' ) . '</td><td><strong>' . ( $effective ? '✓ READY' : '—' ) . '</strong></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ( is_array( $api_test['details'] ?? null ) && $api_test['details'] ) {
            echo '<details class="evo-api-diagnostics"><summary><strong>Latest readiness diagnostics</strong></summary><pre class="evo-json-view">' . esc_html( wp_json_encode( $api_test['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
        }
        echo '</div>';

        echo '<div class="evo-panel"><h2>Membership Center structure</h2><p>These controls change the generated center without editing PHP. You can also compose a custom WordPress page with EVO blocks.</p><div class="evo-form evo-center-settings">';
        $this->input( 'center[hero_title]', 'Hero title', '', false, 'text', (string) $center['hero_title'] );
        $this->input( 'center[products_title]', 'Products section title', '', false, 'text', (string) $center['products_title'] );
        $this->input( 'center[plans_title]', 'Plans section title', '', false, 'text', (string) $center['plans_title'] );
        $this->input( 'center[account_title]', 'Member account title', '', false, 'text', (string) $center['account_title'] );
        echo '<label class="evo-full">Hero text<textarea name="center[hero_text]" rows="3">' . esc_textarea( (string) $center['hero_text'] ) . '</textarea></label>';
        echo '<label>Layout<select name="center[layout]"><option value="stacked" ' . selected( $center['layout'], 'stacked', false ) . '>Catalog first</option><option value="account_first" ' . selected( $center['layout'], 'account_first', false ) . '>Member account first</option><option value="compact" ' . selected( $center['layout'], 'compact', false ) . '>Compact catalog</option></select></label>';
        echo '<label>Catalog visibility<select name="center[catalog_visibility]"><option value="public" ' . selected( $center['catalog_visibility'], 'public', false ) . '>Public</option><option value="members" ' . selected( $center['catalog_visibility'], 'members', false ) . '>Signed-in members only</option></select></label>';
        echo '<label>Primary blue<input type="color" name="center[blue]" value="' . esc_attr( (string) $center['blue'] ) . '"></label><label>Crimson accent<input type="color" name="center[crimson]" value="' . esc_attr( (string) $center['crimson'] ) . '"></label>';
        echo '<fieldset class="evo-full evo-check-card"><legend><strong>Visible center sections</strong></legend><div class="evo-permission-grid">';
        foreach ( array( 'show_products'=>'Products catalog','show_plans'=>'Membership plans','show_account'=>'Member account','show_memberships'=>'Memberships','show_licenses'=>'Licenses','show_activations'=>'Activations','show_orders'=>'Orders','show_downloads'=>'Downloads','show_messages'=>'Messages' ) as $key=>$label ) { echo '<label><input type="checkbox" name="center[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $center[$key] ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
        echo '</div></fieldset></div><div class="evo-action-row"><button type="submit" class="button button-primary" name="evomembers_action" value="save_settings">Save all settings</button></div></div>';
        echo '</form>';

        echo '<div class="evo-grid"><div class="evo-settings-link-card"><strong>' . esc_html__( 'Evoxup Membership Center', 'evoxup-membership' ) . '</strong><p>' . esc_html__( 'Products, plans, memberships, licenses, activations, orders, downloads and member messages can live in this frontend center.', 'evoxup-membership' ) . '</p><p><code>' . esc_html( $center_url ) . '</code></p><p><strong>Full center shortcode:</strong> <code>[evoxup_membership_center]</code></p><p><a class="button button-primary" href="' . esc_url( $center_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View center', 'evoxup-membership' ) . '</a>';
        if ( $center_id > 0 && current_user_can( 'edit_post', $center_id ) ) { $edit_link = get_edit_post_link( $center_id, 'raw' ); if ( $edit_link ) { echo ' <a class="button" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit page', 'evoxup-membership' ) . '</a>'; } }
        echo '</p><form method="post">'; $this->nonce( 'repair_center' ); submit_button( 'Repair / recreate center', 'secondary', 'submit', false ); echo '</form></div>';
        echo '<div class="evo-panel"><h2>WordPress editor blocks</h2><p>In the block editor, insert the <strong>Evoxup Membership</strong> category and arrange these independently:</p><ul><li><code>EVO Membership Center</code> — complete center</li><li><code>EVO Products</code> — product catalog</li><li><code>EVO Membership Plans</code> — plans</li><li><code>EVO My Membership</code> — signed-in member account</li></ul><p>Shortcodes remain available: <code>[evoxup_products]</code> <code>[evoxup_plans]</code> <code>[evoxup_my_membership]</code>.</p></div></div>';
        $this->end();
    }

    private function status_badge( string $status ): string {
        $status = sanitize_key( $status );
        $class = in_array( $status, array( 'active','processed','verified','valid','installed','sent','read','success','not_required' ), true ) ? 'green' : ( in_array( $status, array( 'failed','blocked','rejected','disabled','error','cancelled','unsupported' ), true ) ? 'red' : ( in_array( $status, array( 'processing','received','pending','partially_processed','inactive','unread','warning' ), true ) ? 'orange' : ( 'frozen' === $status ? 'blue' : 'gray' ) ) );
        return '<span class="evo-badge evo-badge-' . esc_attr( $class ) . '">' . esc_html( $status ?: 'unknown' ) . '</span>';
    }

    private function webhook_box( string $name, string $slug ): void {
        $slug = sanitize_key( $slug );
        $url = rest_url( 'evomembers/v1/webhooks/' . $slug );
        echo '<div class="evo-webhook-box"><strong>' . esc_html( $name ) . ' Webhook</strong><div><small>Verified REST endpoint</small> <code>' . esc_html( $url ) . '</code> <button type="button" class="button button-small" data-evo-copy="' . esc_attr( $url ) . '">Copy</button></div></div>';
    }

    private function head( string $title, string $description = '' ): void {
        $icon = Brand::page_icon( $title );
        echo '<div class="wrap evo-members">';
        echo '<header class="evo-page-head"><div class="evo-page-head-brand">' . wp_kses_post( Brand::logo_markup() ) . '</div><div class="evo-page-head-copy"><div class="evo-page-kicker">EVOXUP MEMBERSHIP</div><h1><span class="dashicons ' . esc_attr( $icon ) . '"></span>' . esc_html( $title ) . '</h1>';
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
        echo '</div><div class="evo-page-head-actions"><a class="button evo-support-donate" href="' . esc_url( EVOMEMBERS_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support / Donate', 'evoxup-membership' ) . '</a><span class="evo-version-pill">v' . esc_html( EVOMEMBERS_VERSION ) . '</span></div></header>';
        $notice = (string) filter_input( INPUT_GET, 'evomembers_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        if ( '' !== $notice ) {
            $is_error = (bool) absint( filter_input( INPUT_GET, 'evomembers_error', FILTER_SANITIZE_NUMBER_INT ) );
            echo '<div class="notice ' . ( $is_error ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }
    }

    private function end(): void {
        echo '</div>';
    }

    private function card( string $label, int $value ): void {
        echo '<div class="evo-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>';
    }

    private function nonce( string $action ): void {
        wp_nonce_field( 'evomembers_admin_action', 'evomembers_nonce' );
        echo '<input type="hidden" name="evomembers_action" value="' . esc_attr( $action ) . '">';
    }

    private function customer_select( array $customers ): void {
        echo '<label>Member<select name="customer_id" required><option value="">Select...</option>';
        foreach ( $customers as $customer ) {
            $label = (string) ( $customer['email'] ?? '' );
            $name = trim( (string) ( $customer['first_name'] ?? '' ) . ' ' . (string) ( $customer['last_name'] ?? '' ) );
            if ( '' !== $name ) { $label = $name . ' — ' . $label; }
            echo '<option value="' . esc_attr( $customer['id'] ) . '">' . esc_html( $label ?: '#' . $customer['id'] ) . '</option>';
        }
        echo '</select></label>';
    }

    private function input( string $name, string $label, string $placeholder = '', bool $required = false, string $type = 'text', ?string $value = null ): void {
        $title = $placeholder ? 'Default/example: ' . $placeholder : $label;
        echo '<label>' . esc_html( $label );
        echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ?? '' ) . '" placeholder="' . esc_attr( $placeholder ) . '" title="' . esc_attr( $title ) . '"';
        if ( 'number' === $type ) {
            $decimal_names = array( 'price', 'sale_price', 'subtotal', 'discount', 'tax', 'shipping', 'total' );
            echo ' min="0" step="' . esc_attr( in_array( $name, $decimal_names, true ) ? '0.01' : '1' ) . '"';
        }
        if ( $required ) { echo ' required'; }
        echo '></label>';
    }

    private function select( string $name, string $label, array $options, string $value ): void {
        echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
        foreach ( $options as $key => $text ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>';
        }
        echo '</select></label>';
    }

    private function table( array $headers, array $rows ): void {
        echo '<table class="widefat striped evo-table"><thead><tr>';
        foreach ( $headers as $header ) {
            echo '<th>' . esc_html( $header ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ( ! $rows ) {
            echo '<tr><td colspan="' . esc_attr( (string) count( $headers ) ) . '">No data yet.</td></tr>';
        }
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $row as $cell ) {
                echo '<td>' . esc_html( (string) $cell ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }


    private function extension_asset_url( string $base_path, string $relative ): string {
        $base_path = trailingslashit( wp_normalize_path( $base_path ) );
        $relative = ltrim( str_replace( array( '..', '\\' ), '', $relative ), '/' );
        $plugin_root = trailingslashit( wp_normalize_path( EVOMEMBERS_PATH ) );
        $file = wp_normalize_path( $base_path . $relative );
        if ( '' === $relative || ! str_starts_with( $file, $plugin_root ) || ! is_file( $file ) ) { return ''; }
        $rel = ltrim( substr( $file, strlen( $plugin_root ) ), '/' );
        return plugins_url( $rel, EVOMEMBERS_FILE );
    }

    private function stage_api_settings_from_post( array $post ): true|\WP_Error {
        $mode = sanitize_key( (string) ( $post['api_mode'] ?? '' ) );
        if ( ! in_array( $mode, array( 'internal','external','hybrid' ), true ) ) {
            return true;
        }
        $settings = get_option( 'evomembers_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $old_fingerprint = hash( 'sha256', wp_json_encode( array(
            (string) ( $settings['api_mode'] ?? '' ),
            (string) ( $settings['external_url'] ?? '' ),
            (string) ( $settings['external_client_id'] ?? '' ),
            (string) ( $settings['external_secret_last4'] ?? '' ),
        ) ) );
        $settings['api_mode'] = $mode;
        if ( in_array( $mode, array( 'external','hybrid' ), true ) ) {
            $candidate_external_url = untrailingslashit( esc_url_raw( (string) ( $post['external_url'] ?? ( $settings['external_url'] ?? '' ) ) ) );
            if ( '' !== $candidate_external_url ) {
                $validated_external_url = wp_http_validate_url( $candidate_external_url );
                $external_scheme = strtolower( (string) wp_parse_url( $candidate_external_url, PHP_URL_SCHEME ) );
                if ( false === $validated_external_url || 'https' !== $external_scheme ) {
                    return new \WP_Error( 'evomembers_external_url_invalid', 'External API URL must be a valid HTTPS URL.' );
                }
            }
            $settings['external_url'] = $candidate_external_url;
            $settings['external_client_id'] = sanitize_text_field( (string) ( $post['external_client_id'] ?? ( $settings['external_client_id'] ?? '' ) ) );
        }
        if ( ! empty( $post['clear_external_secret'] ) ) {
            $settings['external_secret_encrypted'] = '';
            $settings['external_secret_last4'] = '';
        } elseif ( isset( $post['external_secret'] ) && '' !== trim( (string) $post['external_secret'] ) ) {
            $plain = trim( (string) $post['external_secret'] );
            $encrypted = ApiConfig::encrypt_external_secret( $plain );
            if ( '' === $encrypted ) {
                return new \WP_Error( 'evomembers_external_secret_crypto', 'External API secret could not be encrypted safely.' );
            }
            $settings['external_secret_encrypted'] = $encrypted;
            $settings['external_secret_last4'] = substr( $plain, -4 );
        }
        update_option( 'evomembers_settings', $settings, false );
        $new_fingerprint = hash( 'sha256', wp_json_encode( array(
            (string) ( $settings['api_mode'] ?? '' ),
            (string) ( $settings['external_url'] ?? '' ),
            (string) ( $settings['external_client_id'] ?? '' ),
            (string) ( $settings['external_secret_last4'] ?? '' ),
        ) ) );
        if ( ! hash_equals( $old_fingerprint, $new_fingerprint ) ) {
            delete_option( 'evomembers_external_api_discovery_snapshot' );
        }
        return true;
    }

    private function module_active( string $id ): bool {
        $active = get_option( 'evomembers_active_modules', array() );
        $active = is_array( $active ) ? array_values( array_unique( array_map( 'sanitize_key', $active ) ) ) : array();
        return in_array( sanitize_key( $id ), $active, true );
    }

    private function required_capability( string $action ): string {
        $map = array(
            'create_product' => Capabilities::PRODUCTS_CREATE,
            'sync_woocommerce_products' => Capabilities::PRODUCTS,
            'update_product' => Capabilities::PRODUCTS_EDIT_OWN,
            'delete_product' => Capabilities::PRODUCTS_DELETE_OWN,
            'set_order_status' => Capabilities::ORDERS,
            'delete_order' => Capabilities::ORDERS,
            'save_mapping' => Capabilities::INTEGRATIONS,
            'delete_mapping' => Capabilities::INTEGRATIONS,
            'save_license_generator' => Capabilities::LICENSES,
            'save_generator_profile' => Capabilities::LICENSES,
            'delete_generator_profile' => Capabilities::LICENSES,
            'delete_license' => Capabilities::LICENSES,
            'set_license_status' => Capabilities::LICENSES_REVOKE,
            'license_item_action' => Capabilities::LICENSES_REVOKE,
            'bulk_license_action' => Capabilities::LICENSES_REVOKE,
            'delete_all_licenses' => Capabilities::LICENSES_REVOKE,
            'verify_license_admin' => Capabilities::LICENSES,
            'issue_license' => Capabilities::LICENSES_ISSUE,
            'deactivate_activation' => Capabilities::ACTIVATIONS,
            'create_integration' => Capabilities::INTEGRATIONS,
            'update_integration' => Capabilities::INTEGRATIONS,
            'delete_integration' => Capabilities::INTEGRATIONS,
            'save_webhook_security' => Capabilities::INTEGRATIONS,
            'retry_webhook_event' => Capabilities::WEBHOOKS,
            'retry_fallback_webhooks' => Capabilities::WEBHOOKS,
            'test_webhook_storage' => Capabilities::WEBHOOKS,
            'cleanup_webhooks' => Capabilities::WEBHOOKS,
            'clear_webhooks' => Capabilities::WEBHOOKS,
            'save_membership_plan' => Capabilities::PLANS,
            'grant_membership' => Capabilities::MEMBERSHIPS,
            'set_membership_status' => Capabilities::MEMBERSHIPS,
            'renew_membership' => Capabilities::MEMBERSHIPS,
            'delete_membership' => Capabilities::MEMBERSHIPS,
            'delete_membership_plan' => Capabilities::PLANS,
            'send_member_message' => Capabilities::CUSTOMERS,
            'create_member_notification' => Capabilities::CUSTOMERS,
            'save_license_type' => Capabilities::LICENSES,
            'delete_license_type' => Capabilities::LICENSES,
            'create_rest_key' => Capabilities::INTEGRATIONS,
            'reveal_rest_key' => Capabilities::INTEGRATIONS,
            'revoke_rest_key' => Capabilities::INTEGRATIONS,
            'delete_rest_key' => Capabilities::INTEGRATIONS,
            'toggle_module' => Capabilities::MODULES,
            'repair_database' => Capabilities::SETTINGS,
            'save_settings' => Capabilities::SETTINGS,
            'discover_external_api' => Capabilities::SETTINGS,
            'test_api_settings' => Capabilities::SETTINGS,
            'repair_center' => Capabilities::SETTINGS,
            'save_user_access' => Capabilities::ACCESS,
        );
        return $map[ $action ] ?? Capabilities::SETTINGS;
    }

    private function audit_resource( string $action, array $post ): array {
        $map = array(
            'product' => array( 'create_product', 'update_product', 'delete_product' ),
            'customer' => array( 'send_member_message', 'create_member_notification' ),
            'order' => array( 'set_order_status', 'delete_order' ),
            'license' => array( 'delete_license', 'set_license_status', 'license_item_action', 'verify_license_admin', 'issue_license' ),
            'activation' => array( 'deactivate_activation' ),
            'integration' => array( 'create_integration', 'update_integration', 'delete_integration', 'save_mapping', 'delete_mapping' ),
            'membership' => array( 'grant_membership', 'set_membership_status', 'renew_membership', 'delete_membership' ),
            'plan' => array( 'save_membership_plan', 'delete_membership_plan' ),
            'user' => array( 'save_user_access' ),
            'extension' => array( 'toggle_module' ),
            'rest_credential' => array( 'create_rest_key', 'reveal_rest_key', 'revoke_rest_key', 'delete_rest_key' ),
        );
        $keys = array(
            'product' => 'product_id', 'customer' => 'customer_id', 'order' => 'order_id', 'license' => 'license_id',
            'activation' => 'activation_id', 'integration' => 'integration_id', 'membership' => 'membership_id',
            'plan' => 'plan_id', 'user' => 'user_id', 'extension' => 'module_id', 'rest_credential' => 'id',
        );
        foreach ( $map as $type => $actions ) {
            if ( in_array( $action, $actions, true ) ) {
                $key = $keys[ $type ];
                $id = $post[ $key ] ?? ( $post['id'] ?? '' );
                return array( 'type' => $type, 'id' => is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '' );
            }
        }
        return array( 'type' => 'admin_action', 'id' => '' );
    }

    private function sanitize_request_value( mixed $value ): mixed {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $key => $item ) {
                $safe_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
                if ( '' === (string) $safe_key && ! is_int( $key ) ) { continue; }
                $clean[ $safe_key ] = $this->sanitize_request_value( $item );
            }
            return $clean;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { return $value; }
        return sanitize_textarea_field( (string) $value );
    }

    private function sanitize_audit_value( mixed $value, string $key = '' ): mixed {
        $sensitive = array( 'repository_license_key','external_secret','secret','bearer_token','password','webhook_api_key','license_key','api_key','token','nonce' );
        if ( '' !== $key && in_array( sanitize_key( $key ), $sensitive, true ) ) {
            return '[redacted]';
        }
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $child_key => $child_value ) {
                $safe_key = is_int( $child_key ) ? $child_key : sanitize_key( (string) $child_key );
                if ( '' === (string) $safe_key && ! is_int( $child_key ) ) {
                    continue;
                }
                $clean[ $safe_key ] = $this->sanitize_audit_value( $child_value, (string) $safe_key );
            }
            return $clean;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }
        return sanitize_textarea_field( (string) $value );
    }

    private function redirect( string $page, string $notice, bool $error = false ): void {
        $nonce_valid = isset( $_POST['evomembers_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['evomembers_nonce'] ) ), 'evomembers_admin_action' );
        if ( ! $this->audit_recorded && $nonce_valid ) {
            $action = isset( $_POST['evomembers_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['evomembers_action'] ) ) : '';
            if ( '' !== $action ) {
                $post = is_array( $_POST ) ? $this->sanitize_audit_value( wp_unslash( $_POST ) ) : array();
                unset( $post['evomembers_nonce'], $post['_wp_http_referer'] );
                $resource = $this->audit_resource( $action, $post );
                AuditTrail::record(
                    'admin.' . $action,
                    $resource['type'],
                    $resource['id'],
                    null,
                    $post,
                    array( 'page' => sanitize_key( strtok( $page, '&' ) ?: $page ), 'notice' => sanitize_text_field( $notice ) ),
                    $error ? 'failure' : 'success'
                );
                $this->audit_recorded = true;
            }
        }
        $parts = explode( '&', $page );
        $page_slug = sanitize_key( (string) array_shift( $parts ) );
        $args = array( 'page'=>$page_slug, 'evomembers_notice'=>$notice, 'evomembers_error'=>$error ? 1 : 0 );
        if ( $parts ) {
            $extra = array();
            parse_str( implode( '&', $parts ), $extra );
            foreach ( $extra as $key=>$value ) { $args[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : ''; }
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
