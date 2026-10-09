<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

final class Installer {
    public static function activate(): void {
        self::migrate_legacy_options();
        self::install();
        flush_rewrite_rules( false );
        RequiredComponents::install_on_activation();
    }

    public static function repair(): void {
        self::migrate_legacy_options();
        self::install();
    }

    public static function maybe_upgrade(): void {
        self::migrate_legacy_options();
        if ( EVOMEMBERS_DB_VERSION !== get_option( 'evomembers_db_version' ) ) {
            self::install();
        }
    }

    /** Migrate legacy short-prefix options once, preserving existing installations. */
    private static function migrate_legacy_options(): void {
        $map = array(
            'evo_active_modules' => 'evomembers_active_modules',
            'evo_capabilities_version' => 'evomembers_capabilities_version',
            'evo_capability_registry_defaults' => 'evomembers_capability_registry_defaults',
            'evo_core_schema_reset_3_completed' => 'evomembers_core_schema_reset_3_completed',
            'evo_db_migration_errors' => 'evomembers_db_migration_errors',
            'evo_db_version' => 'evomembers_db_version',
            'evo_event_message_channel' => 'evomembers_event_message_channel',
            'evo_ext_evoxup-update-manager_settings' => 'evomembers_update_manager_settings',
            'evo_external_api_discovery_snapshot' => 'evomembers_external_api_discovery_snapshot',
            'evo_license_settings' => 'evomembers_license_settings',
            'evo_marketplace_settings' => 'evomembers_marketplace_settings',
            'evo_membership_center_page_id' => 'evomembers_center_page_id',
            'evo_my_membership_page_id' => 'evomembers_my_membership_page_id',
            'evo_settings' => 'evomembers_settings',
            'evo_webhook_fallback_events' => 'evomembers_webhook_fallback_events',
            'evo_webhook_last_received' => 'evomembers_webhook_last_received',
            'evo_webhook_last_storage_error' => 'evomembers_webhook_last_storage_error',
            'evo_center_settings' => 'evomembers_center_settings',
            'evo_license_generators' => 'evomembers_license_generators',
        );
        foreach ( $map as $legacy => $canonical ) {
            if ( false !== get_option( $canonical, false ) ) { continue; }
            $value = get_option( $legacy, null );
            if ( null === $value ) { continue; }
            update_option( $canonical, $value, false );
            delete_option( $legacy );
        }

        // Migrate the previous evoxup_membership_* canonical keys to evomembers_* once.
        $legacy_canonical = array(
            'evoxup_membership_active_modules' => 'evomembers_active_modules',
            'evoxup_membership_capabilities_version' => 'evomembers_capabilities_version',
            'evoxup_membership_capability_registry_defaults' => 'evomembers_capability_registry_defaults',
            'evoxup_membership_core_schema_reset_3_completed' => 'evomembers_core_schema_reset_3_completed',
            'evoxup_membership_db_migration_errors' => 'evomembers_db_migration_errors',
            'evoxup_membership_db_version' => 'evomembers_db_version',
            'evoxup_membership_event_message_channel' => 'evomembers_event_message_channel',
            'evoxup_membership_external_api_discovery_snapshot' => 'evomembers_external_api_discovery_snapshot',
            'evoxup_membership_license_settings' => 'evomembers_license_settings',
            'evoxup_membership_center_page_id' => 'evomembers_center_page_id',
            'evoxup_membership_my_membership_page_id' => 'evomembers_my_membership_page_id',
            'evoxup_membership_settings' => 'evomembers_settings',
            'evoxup_membership_webhook_fallback_events' => 'evomembers_webhook_fallback_events',
            'evoxup_membership_webhook_last_received' => 'evomembers_webhook_last_received',
            'evoxup_membership_webhook_last_storage_error' => 'evomembers_webhook_last_storage_error',
            'evoxup_membership_center_settings' => 'evomembers_center_settings',
            'evoxup_membership_license_generators' => 'evomembers_license_generators'
        );
        foreach ( $legacy_canonical as $legacy => $canonical ) {
            if ( false !== get_option( $canonical, false ) ) { continue; }
            $value = get_option( $legacy, null );
            if ( null === $value ) { continue; }
            update_option( $canonical, $value, false );
        }
    }

    private static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $migration_errors = Database::migrate_legacy_table_prefix();
        $previous_version = (string) get_option( 'evomembers_db_version', '' );

        // Schema 3.0 is the deliberate clean-core reset requested for the
        // stable rebuild. Only EVO-owned legacy tables are removed. Once
        // 3.0.0 has been installed, all later upgrades are non-destructive.
        $needs_epoch_reset = ( '' !== $previous_version && version_compare( $previous_version, '3.0.0', '<' ) )
            || Database::requires_epoch_3_reset();
        if ( $needs_epoch_reset ) {
            $migration_errors = array_merge( $migration_errors, Database::drop_all_tables() );
            update_option( 'evomembers_core_schema_reset_3_completed', current_time( 'mysql', true ), false );
        }

        foreach ( Database::schema() as $sql ) {
            dbDelta( $sql );
        }

        $migration_errors = array_merge( $migration_errors, Database::run_post_dbdelta_migrations() );
        if ( $migration_errors ) {
            update_option( 'evomembers_db_migration_errors', $migration_errors, false );
        } else {
            delete_option( 'evomembers_db_migration_errors' );
        }

        Capabilities::install();

        update_option( 'evomembers_db_version', EVOMEMBERS_DB_VERSION, false );

        $settings = get_option( 'evomembers_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
        $settings = wp_parse_args(
            $settings,
            array(
                'api_mode'                  => 'internal',
                'external_url'              => '',
                'external_client_id'        => '',
                'external_secret_encrypted'=> '',
                'external_secret_last4'    => '',
            )
        );
        update_option( 'evomembers_settings', $settings, false );
        self::ensure_frontend_pages();
    }

    /**
     * Ensure the public Membership center exists even if a previous upgrade,
     * manual deletion or interrupted activation left the page missing.
     */
    public static function ensure_frontend_pages(): void {
        if ( wp_installing() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $center_id = absint( get_option( 'evomembers_center_page_id', 0 ) );
        if ( $center_id > 0 && 'trash' !== get_post_status( $center_id ) ) {
            $center_page = get_post( $center_id );
            if ( $center_page instanceof \WP_Post ) {
                $content = trim( (string) $center_page->post_content );
                $update = array( 'ID' => $center_id );
                if ( '' === $content || '[evoxup_my_membership]' === $content ) {
                    $update['post_content'] = '[evoxup_membership_center]';
                }
                if ( 'evoxup-membership' !== (string) $center_page->post_name ) {
                    $update['post_name']  = 'evoxup-membership';
                    $update['post_title'] = __( 'Evoxup Membership', 'evoxup-membership' );
                }
                if ( count( $update ) > 1 ) {
                    wp_update_post( $update );
                }
                update_option( 'evomembers_my_membership_page_id', $center_id, false );
                return;
            }
        }

        $existing = get_page_by_path( 'evoxup-membership', OBJECT, 'page' );
        if ( ! ( $existing instanceof \WP_Post ) ) {
            $legacy = get_page_by_path( 'my-membership', OBJECT, 'page' );
            if ( $legacy instanceof \WP_Post ) {
                $existing = $legacy;
                if ( '[evoxup_my_membership]' === trim( (string) $legacy->post_content ) ) {
                    wp_update_post(
                        array(
                            'ID'           => (int) $legacy->ID,
                            'post_content' => '[evoxup_membership_center]',
                        )
                    );
                }
            }
        }

        if ( $existing instanceof \WP_Post ) {
            $content = trim( (string) $existing->post_content );
            if ( '' === $content || '[evoxup_my_membership]' === $content ) {
                wp_update_post( array( 'ID'=>(int)$existing->ID, 'post_content'=>'[evoxup_membership_center]' ) );
            }
            update_option( 'evomembers_center_page_id', (int) $existing->ID, false );
            update_option( 'evomembers_my_membership_page_id', (int) $existing->ID, false );
            return;
        }

        $page_id = wp_insert_post(
            array(
                'post_title'   => __( 'Evoxup Membership', 'evoxup-membership' ),
                'post_name'    => 'evoxup-membership',
                'post_content' => '[evoxup_membership_center]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ),
            true
        );
        if ( ! is_wp_error( $page_id ) ) {
            update_option( 'evomembers_center_page_id', (int) $page_id, false );
            update_option( 'evomembers_my_membership_page_id', (int) $page_id, false );
        }
    }
}
