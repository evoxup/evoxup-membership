<?php
namespace EvoMembers\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This class owns install/upgrade migrations and schema checks for EVO custom tables.

defined( 'ABSPATH' ) || exit;

final class Database {
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'evomembers_' . sanitize_key( $name );
    }

    public static function schema(): array {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        return array(
            'customers' => "CREATE TABLE " . self::table( 'customers' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                wp_user_id bigint(20) unsigned NULL,
                email varchar(190) NULL,
                secondary_email varchar(190) NULL,
                first_name varchar(100) NULL,
                middle_name varchar(100) NULL,
                last_name varchar(100) NULL,
                display_name varchar(190) NULL,
                phone varchar(60) NULL,
                phone_country_code varchar(12) NULL,
                company varchar(190) NULL,
                address_1 varchar(255) NULL,
                address_2 varchar(255) NULL,
                city varchar(120) NULL,
                state_region varchar(120) NULL,
                postal_code varchar(40) NULL,
                country_code varchar(12) NULL,
                locale varchar(40) NULL,
                timezone varchar(80) NULL,
                tax_id varchar(100) NULL,
                vat_number varchar(100) NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                registered_at datetime NULL,
                first_purchase_at datetime NULL,
                last_purchase_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                KEY wp_user_id (wp_user_id),
                KEY phone (phone),
                KEY status (status)
            ) $charset;",
            'products' => "CREATE TABLE " . self::table( 'products' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_user_id bigint(20) unsigned NULL,
                code varchar(100) NOT NULL,
                name varchar(190) NOT NULL,
                product_type varchar(50) NOT NULL DEFAULT 'digital',
                excerpt text NULL,
                description longtext NULL,
                image_id bigint(20) unsigned NULL,
                image_url varchar(1000) NULL,
                price decimal(20,8) NULL,
                sale_price decimal(20,8) NULL,
                currency varchar(12) NULL,
                purchase_url varchar(1000) NULL,
                upgrade_url varchar(1000) NULL,
                download_url varchar(1000) NULL,
                requires_license tinyint(1) NOT NULL DEFAULT 0,
                license_type_id bigint(20) unsigned NULL,
                verification_mode varchar(40) NOT NULL DEFAULT 'portable',
                allowed_site_url varchar(1000) NULL,
                allowed_domain varchar(255) NULL,
                allowed_ip varchar(190) NULL,
                source varchar(50) NOT NULL DEFAULT 'evo',
                external_source_id varchar(190) NULL,
                source_name varchar(190) NULL,
                source_synced_at datetime NULL,
                fulfillment_mode varchar(40) NOT NULL DEFAULT 'evo',
                license_provider varchar(100) NOT NULL DEFAULT 'evo',
                status varchar(30) NOT NULL DEFAULT 'active',
                license_seats int unsigned NOT NULL DEFAULT 1,
                max_activations int unsigned NOT NULL DEFAULT 1,
                license_duration_days int unsigned NULL,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code),
                UNIQUE KEY source_external (source,external_source_id),
                KEY owner_user_id (owner_user_id),
                KEY name (name),
                KEY source_status (source,status),
                KEY status (status),
                KEY fulfillment_mode (fulfillment_mode),
                KEY license_provider (license_provider),
                KEY source_synced_at (source_synced_at)
            ) $charset;",
            'plans' => "CREATE TABLE " . self::table( 'plans' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                code varchar(100) NOT NULL,
                name varchar(190) NOT NULL,
                description text NULL,
                tier varchar(40) NOT NULL DEFAULT 'custom',
                plan_mode varchar(30) NOT NULL DEFAULT 'bundle',
                status varchar(30) NOT NULL DEFAULT 'active',
                duration_days int unsigned NULL,
                grace_days int unsigned NOT NULL DEFAULT 0,
                auto_renew_default tinyint(1) NOT NULL DEFAULT 0,
                upgrade_plan_id bigint(20) unsigned NULL,
                downgrade_plan_id bigint(20) unsigned NULL,
                entitlements_json longtext NULL,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code),
                KEY name (name),
                KEY tier (tier),
                KEY status (status),
                KEY upgrade_plan_id (upgrade_plan_id),
                KEY downgrade_plan_id (downgrade_plan_id)
            ) $charset;",
            'plan_products' => "CREATE TABLE " . self::table( 'plan_products' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                plan_id bigint(20) unsigned NOT NULL,
                product_id bigint(20) unsigned NOT NULL,
                quantity int unsigned NOT NULL DEFAULT 1,
                is_primary tinyint(1) NOT NULL DEFAULT 0,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY plan_product (plan_id,product_id),
                KEY plan_id (plan_id),
                KEY product_id (product_id)
            ) $charset;",
            'memberships' => "CREATE TABLE " . self::table( 'memberships' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL,
                plan_id bigint(20) unsigned NOT NULL,
                previous_plan_id bigint(20) unsigned NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                source varchar(80) NULL,
                external_reference varchar(190) NULL,
                source_order_id bigint(20) unsigned NULL,
                starts_at datetime NULL,
                expires_at datetime NULL,
                auto_renew tinyint(1) NOT NULL DEFAULT 0,
                renewal_count int unsigned NOT NULL DEFAULT 0,
                last_renewed_at datetime NULL,
                notes text NULL,
                cancelled_at datetime NULL,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY customer_id (customer_id),
                KEY plan_id (plan_id),
                KEY previous_plan_id (previous_plan_id),
                KEY customer_status (customer_id,status),
                KEY plan_status (plan_id,status),
                KEY status (status),
                KEY expires_at (expires_at),
                KEY external_reference (external_reference),
                KEY source_order_id (source,source_order_id)
            ) $charset;",
            'licenses' => "CREATE TABLE " . self::table( 'licenses' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NULL,
                product_id bigint(20) unsigned NULL,
                membership_id bigint(20) unsigned NULL,
                license_group_uuid char(36) NULL,
                seat_number int unsigned NOT NULL DEFAULT 1,
                seat_total int unsigned NOT NULL DEFAULT 1,
                license_hash char(64) NOT NULL,
                license_key_encrypted longtext NULL,
                license_last4 varchar(8) NOT NULL,
                external_license_provider varchar(100) NULL,
                external_license_id varchar(190) NULL,
                external_reference varchar(190) NULL,
                external_license_key_encrypted longtext NULL,
                external_license_status varchar(30) NULL,
                notified_at datetime NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                license_type_id bigint(20) unsigned NULL,
                verification_mode varchar(40) NOT NULL DEFAULT 'portable',
                allowed_site_url varchar(1000) NULL,
                allowed_domain varchar(255) NULL,
                allowed_ip varchar(190) NULL,
                verification_status varchar(30) NOT NULL DEFAULT 'unverified',
                verification_count int unsigned NOT NULL DEFAULT 0,
                last_verified_at datetime NULL,
                status_changed_at datetime NULL,
                max_activations int unsigned NOT NULL DEFAULT 1,
                issued_at datetime NOT NULL,
                expires_at datetime NULL,
                created_from_order_id bigint(20) unsigned NULL,
                source varchar(50) NOT NULL DEFAULT 'evo',
                source_order_id bigint(20) unsigned NULL,
                source_order_item_id bigint(20) unsigned NULL,
                generator_profile varchar(100) NULL,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY license_hash (license_hash),
                KEY customer_id (customer_id),
                KEY product_id (product_id),
                KEY membership_id (membership_id),
                KEY external_provider_id (external_license_provider,external_license_id),
                KEY external_reference (external_license_provider,external_reference),
                KEY license_group_uuid (license_group_uuid),
                KEY status (status),
                KEY expires_at (expires_at),
                KEY source_order_id (source,source_order_id),
                KEY source_order_item_id (source,source_order_item_id)
            ) $charset;",
            'license_activations' => "CREATE TABLE " . self::table( 'license_activations' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                license_id bigint(20) unsigned NOT NULL,
                site_url varchar(255) NOT NULL,
                site_hash char(64) NOT NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                client_version varchar(60) NULL,
                domain varchar(255) NULL,
                server_ip varchar(80) NULL,
                activated_at datetime NOT NULL,
                last_seen_at datetime NOT NULL,
                deactivated_at datetime NULL,
                metadata_json longtext NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY license_site (license_id,site_hash),
                KEY license_id (license_id),
                KEY status (status),
                KEY last_seen_at (last_seen_at)
            ) $charset;",
            'orders' => "CREATE TABLE " . self::table( 'orders' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NULL,
                integration_id bigint(20) unsigned NULL,
                woocommerce_order_id bigint(20) unsigned NULL,
                external_order_id varchar(190) NULL,
                external_transaction_id varchar(190) NULL,
                status varchar(40) NOT NULL DEFAULT 'received',
                currency varchar(12) NULL,
                subtotal decimal(20,8) NULL,
                discount decimal(20,8) NULL,
                tax decimal(20,8) NULL,
                shipping decimal(20,8) NULL,
                total decimal(20,8) NULL,
                refunded_amount decimal(20,8) NULL,
                coupon_code varchar(100) NULL,
                payment_method varchar(100) NULL,
                payment_status varchar(50) NULL,
                purchased_at datetime NULL,
                paid_at datetime NULL,
                refunded_at datetime NULL,
                cancelled_at datetime NULL,
                fulfillment_mode varchar(40) NULL,
                fulfillment_status varchar(40) NOT NULL DEFAULT 'pending',
                fulfillment_result_json longtext NULL,
                fulfilled_at datetime NULL,
                provider_data_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY customer_id (customer_id),
                KEY integration_id (integration_id),
                KEY woocommerce_order_id (woocommerce_order_id),
                KEY external_order_id (external_order_id),
                KEY external_transaction_id (external_transaction_id),
                KEY status (status)
            ) $charset;",
            'order_items' => "CREATE TABLE " . self::table( 'order_items' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint(20) unsigned NOT NULL,
                product_id bigint(20) unsigned NULL,
                plan_id bigint(20) unsigned NULL,
                provider_product_id varchar(190) NULL,
                provider_item_id varchar(190) NULL,
                name varchar(255) NULL,
                quantity int unsigned NOT NULL DEFAULT 1,
                unit_price decimal(20,8) NULL,
                total decimal(20,8) NULL,
                license_provider varchar(100) NULL,
                license_seats int unsigned NOT NULL DEFAULT 1,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY order_id (order_id),
                KEY product_id (product_id),
                KEY plan_id (plan_id),
                KEY provider_item_id (provider_item_id)
            ) $charset;",
            'transactions' => "CREATE TABLE " . self::table( 'transactions' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_id bigint(20) unsigned NULL,
                provider varchar(100) NULL,
                external_transaction_id varchar(190) NULL,
                customer_id bigint(20) unsigned NULL,
                license_id bigint(20) unsigned NULL,
                order_id bigint(20) unsigned NULL,
                action varchar(50) NOT NULL,
                status varchar(40) NOT NULL DEFAULT 'processed',
                amount decimal(20,8) NULL,
                currency varchar(12) NULL,
                processed_at datetime NULL,
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_transaction (provider,external_transaction_id),
                KEY customer_id (customer_id),
                KEY license_id (license_id),
                KEY order_id (order_id)
            ) $charset;",
            'license_types' => "CREATE TABLE " . self::table( 'license_types' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                code varchar(100) NOT NULL,
                name varchar(190) NOT NULL,
                verification_mode varchar(40) NOT NULL DEFAULT 'portable',
                license_seats int unsigned NOT NULL DEFAULT 1,
                max_activations int unsigned NOT NULL DEFAULT 1,
                duration_days int unsigned NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                settings_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code),
                KEY status (status)
            ) $charset;",
            'rest_keys' => "CREATE TABLE " . self::table( 'rest_keys' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                public_id varchar(80) NULL,
                key_prefix varchar(24) NOT NULL,
                key_hash char(64) NOT NULL,
                secret_encrypted longtext NULL,
                permissions_json longtext NULL,
                allowed_ips text NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                last_used_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY key_hash (key_hash),
                UNIQUE KEY public_id (public_id),
                KEY status (status)
            ) $charset;",
            'integrations' => "CREATE TABLE " . self::table( 'integrations' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                slug varchar(100) NOT NULL,
                adapter varchar(190) NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                security_mode varchar(40) NOT NULL DEFAULT 'hmac_sha256',
                api_url varchar(500) NULL,
                api_key_encrypted longtext NULL,
                secret_encrypted longtext NULL,
                bearer_encrypted longtext NULL,
                username_encrypted longtext NULL,
                password_encrypted longtext NULL,
                signature_header varchar(190) NULL,
                allowed_ips text NULL,
                link_wp_user tinyint(1) NOT NULL DEFAULT 1,
                create_wc_order tinyint(1) NOT NULL DEFAULT 1,
                settings_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug),
                KEY status (status)
            ) $charset;",
            'integration_endpoints' => "CREATE TABLE " . self::table( 'integration_endpoints' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_id bigint(20) unsigned NOT NULL,
                endpoint_type varchar(80) NOT NULL,
                name varchar(190) NOT NULL,
                base_url varchar(500) NULL,
                api_version varchar(50) NULL,
                auth_type varchar(50) NOT NULL DEFAULT 'none',
                credentials_json longtext NULL,
                headers_json longtext NULL,
                settings_json longtext NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY integration_id (integration_id),
                KEY endpoint_type (endpoint_type),
                KEY status (status)
            ) $charset;",
            'integration_routes' => "CREATE TABLE " . self::table( 'integration_routes' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                endpoint_id bigint(20) unsigned NOT NULL,
                operation varchar(100) NOT NULL,
                http_method varchar(12) NOT NULL DEFAULT 'GET',
                route_path varchar(500) NOT NULL,
                api_version varchar(50) NULL,
                request_mapping_json longtext NULL,
                response_mapping_json longtext NULL,
                settings_json longtext NULL,
                status varchar(30) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY endpoint_id (endpoint_id),
                KEY operation (operation),
                KEY status (status)
            ) $charset;",
            'product_mappings' => "CREATE TABLE " . self::table( 'product_mappings' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_id bigint(20) unsigned NOT NULL,
                external_product_id varchar(190) NOT NULL,
                external_variant_id varchar(190) NULL,
                product_id bigint(20) unsigned NULL,
                target_type varchar(30) NOT NULL DEFAULT 'evo',
                target_id bigint(20) unsigned NULL,
                plan_id bigint(20) unsigned NULL,
                fulfillment_mode varchar(40) NOT NULL DEFAULT 'inherit',
                status varchar(30) NOT NULL DEFAULT 'active',
                metadata_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY integration_product_variant (integration_id,external_product_id,external_variant_id),
                KEY product_id (product_id),
                KEY plan_id (plan_id),
                KEY fulfillment_mode (fulfillment_mode),
                KEY status (status)
            ) $charset;",
            'webhook_events' => "CREATE TABLE " . self::table( 'webhook_events' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                integration_id bigint(20) unsigned NULL,
                provider_slug varchar(100) NULL,
                event_type varchar(100) NULL,
                external_event_id varchar(190) NULL,
                provider_created_at datetime NULL,
                provider_updated_at datetime NULL,
                received_at datetime NOT NULL,
                request_method varchar(12) NULL,
                content_type varchar(190) NULL,
                source_ip varchar(80) NULL,
                user_agent varchar(500) NULL,
                verification_status varchar(40) NOT NULL DEFAULT 'pending',
                processing_status varchar(40) NOT NULL DEFAULT 'received',
                attempts int unsigned NOT NULL DEFAULT 0,
                last_attempt_at datetime NULL,
                processed_at datetime NULL,
                failed_at datetime NULL,
                error_code varchar(100) NULL,
                error_message text NULL,
                headers_json longtext NULL,
                payload_json longtext NOT NULL,
                normalized_json longtext NULL,
                processing_trace_json longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY integration_id (integration_id),
                KEY provider_event (provider_slug,external_event_id),
                KEY processing_status (processing_status),
                KEY verification_status (verification_status),
                KEY received_at (received_at),
                KEY provider_received (provider_slug,received_at)
            ) $charset;",
            'messages' => "CREATE TABLE " . self::table( 'messages' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL,
                subject varchar(190) NOT NULL,
                message longtext NOT NULL,
                channel varchar(30) NOT NULL DEFAULT 'in_app',
                event_key varchar(190) NULL,
                status varchar(30) NOT NULL DEFAULT 'unread',
                attempts int unsigned NOT NULL DEFAULT 0,
                last_error text NULL,
                scheduled_at datetime NULL,
                sent_at datetime NULL,
                delivered_at datetime NULL,
                read_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY customer_id (customer_id),
                KEY status (status),
                KEY event_key (event_key),
                KEY scheduled_at (scheduled_at),
                KEY created_at (created_at)
            ) $charset;",
            'notifications' => "CREATE TABLE " . self::table( 'notifications' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL,
                type varchar(30) NOT NULL DEFAULT 'info',
                title varchar(190) NOT NULL,
                message longtext NOT NULL,
                action_url varchar(500) NULL,
                status varchar(30) NOT NULL DEFAULT 'unread',
                expires_at datetime NULL,
                read_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY customer_id (customer_id),
                KEY type (type),
                KEY status (status),
                KEY expires_at (expires_at),
                KEY created_at (created_at)
            ) $charset;",
            'events' => "CREATE TABLE " . self::table( 'events' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_name varchar(120) NOT NULL,
                aggregate_type varchar(80) NULL,
                aggregate_id bigint(20) unsigned NULL,
                customer_id bigint(20) unsigned NULL,
                source varchar(80) NULL,
                payload_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY event_name (event_name),
                KEY aggregate (aggregate_type,aggregate_id),
                KEY customer_id (customer_id),
                KEY created_at (created_at)
            ) $charset;",
            'logs' => "CREATE TABLE " . self::table( 'logs' ) . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                level varchar(20) NOT NULL DEFAULT 'info',
                event varchar(100) NOT NULL,
                customer_id bigint(20) unsigned NULL,
                license_id bigint(20) unsigned NULL,
                actor_user_id bigint(20) unsigned NULL,
                request_id varchar(64) NULL,
                context_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY level (level),
                KEY event (event),
                KEY customer_id (customer_id),
                KEY license_id (license_id),
                KEY created_at (created_at)
            ) $charset;"
        );
    }


    /**
     * Detect the pre-3.0 plan schema even when the version option was removed
     * by an older uninstall. This avoids carrying legacy commercial/license
     * columns into the clean stable core on reinstall.
     */

    /** Rename legacy wp_evo_* tables to the unique wp_evomembers_* prefix without data loss. */
    public static function migrate_legacy_table_prefix(): array {
        global $wpdb;
        $errors = array();
        foreach ( array_keys( self::schema() ) as $name ) {
            $legacy = $wpdb->prefix . 'evo_' . sanitize_key( $name );
            $target = self::table( $name );
            $legacy_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) );
            $target_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $target ) );
            if ( $legacy === $legacy_exists && $target !== $target_exists ) {
                $ok = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy, $target ) );
                if ( false === $ok ) {
                    $errors[] = 'Could not migrate legacy table ' . $legacy . ': ' . sanitize_text_field( (string) $wpdb->last_error );
                }
            }
        }
        return $errors;
    }

    public static function requires_epoch_3_reset(): bool {
        global $wpdb;

        $table = self::table( 'plans' );
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== (string) $found ) {
            return false;
        }

        $legacy_columns = array( 'price', 'currency', 'purchase_url', 'upgrade_url', 'license_seats' );
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
        if ( ! is_array( $columns ) ) {
            return false;
        }

        return (bool) array_intersect( $legacy_columns, array_map( 'strval', $columns ) );
    }

    /**
     * Remove EVO-owned operational tables only.
     *
     * This is used once for the 3.0 schema epoch. It never touches WordPress
     * or WooCommerce core tables and must not be used for routine upgrades.
     *
     * @return array<int,string> Drop errors, if any.
     */
    public static function drop_all_tables(): array {
        global $wpdb;

        $errors = array();
        $names  = array_reverse( array_keys( self::schema() ) );

        foreach ( $names as $name ) {
            $table = self::table( $name );
            $ok    = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
            if ( false === $ok ) {
                $errors[] = 'Could not drop ' . $table . ': ' . sanitize_text_field( (string) $wpdb->last_error );
            }
        }

        return $errors;
    }

    /**
     * Repair schema details that dbDelta cannot always change reliably when
     * upgrading an existing installation. This is intentionally narrow and
     * idempotent so normal fresh installs still rely on schema().
     *
     * @return array<int,string> Human-readable migration errors, if any.
     */
    public static function run_post_dbdelta_migrations(): array {
        global $wpdb;

        $errors = array();
        $table  = self::table( 'product_mappings' );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table !== $exists ) {
            $errors[] = 'Product mappings table is missing after dbDelta.';
            return $errors;
        }

        $columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
        $by_name = array();
        if ( is_array( $columns ) ) {
            foreach ( $columns as $column ) {
                if ( isset( $column['Field'] ) ) {
                    $by_name[ (string) $column['Field'] ] = $column;
                }
            }
        }

        if ( ! isset( $by_name['target_type'] ) ) {
            $ok = $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `target_type` varchar(30) NOT NULL DEFAULT 'evo' AFTER `product_id`", $table ) );
            if ( false === $ok ) {
                $errors[] = 'Could not add product_mappings.target_type: ' . sanitize_text_field( (string) $wpdb->last_error );
            }
        }
        if ( ! isset( $by_name['target_id'] ) ) {
            $ok = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `target_id` bigint(20) unsigned NULL AFTER `target_type`', $table ) );
            if ( false === $ok ) {
                $errors[] = 'Could not add product_mappings.target_id: ' . sanitize_text_field( (string) $wpdb->last_error );
            }
        }

        // 0.9.0 created product_id as NOT NULL. WooCommerce routes do not own an
        // EVO product, so 0.9.1+ needs this column to be nullable. dbDelta does
        // not consistently alter nullability on all MySQL/MariaDB hosts.
        if ( isset( $by_name['product_id'] ) && 'NO' === strtoupper( (string) ( $by_name['product_id']['Null'] ?? '' ) ) ) {
            $ok = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY COLUMN `product_id` bigint(20) unsigned NULL', $table ) );
            if ( false === $ok ) {
                $errors[] = 'Could not make product_mappings.product_id nullable: ' . sanitize_text_field( (string) $wpdb->last_error );
            }
        }

        // 3.1.0 gives every historical REST credential a stable public/client
        // identifier without changing its existing secret/hash. This keeps old
        // integrations working while enabling the new public-ID + secret UX.
        $rest_table = self::table( 'rest_keys' );
        $rest_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rest_table ) );
        if ( $rest_table === $rest_exists ) {
            $rest_columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $rest_table ), ARRAY_A );
            $rest_by_name = array();
            if ( is_array( $rest_columns ) ) {
                foreach ( $rest_columns as $column ) {
                    if ( isset( $column['Field'] ) ) { $rest_by_name[ (string) $column['Field'] ] = $column; }
                }
            }
            if ( ! isset( $rest_by_name['public_id'] ) ) {
                $ok = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `public_id` varchar(80) NULL AFTER `name`', $rest_table ) );
                if ( false === $ok ) { $errors[] = 'Could not add rest_keys.public_id: ' . sanitize_text_field( (string) $wpdb->last_error ); }
            }
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, public_id FROM %i ORDER BY id ASC', $rest_table ), ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( '' !== trim( (string) ( $row['public_id'] ?? '' ) ) ) { continue; }
                    $public_id = 'evomembers_pub_' . strtolower( wp_generate_password( 18, false, false ) );
                    $updated = $wpdb->update( $rest_table, array( 'public_id'=>$public_id, 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>absint( $row['id'] ?? 0 ) ) );
                    if ( false === $updated ) { $errors[] = 'Could not assign public ID to REST credential #' . absint( $row['id'] ?? 0 ) . ': ' . sanitize_text_field( (string) $wpdb->last_error ); }
                }
            }
        }

        // 3.1.1 renames the legacy integration flag so its name matches
        // actual behavior: EVO only links an already-existing WordPress user.
        $integration_table = self::table( 'integrations' );
        $integration_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $integration_table ) );
        if ( $integration_table === $integration_exists ) {
            $integration_columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $integration_table ), ARRAY_A );
            $integration_by_name = array();
            if ( is_array( $integration_columns ) ) {
                foreach ( $integration_columns as $column ) {
                    if ( isset( $column['Field'] ) ) { $integration_by_name[ (string) $column['Field'] ] = $column; }
                }
            }
            if ( isset( $integration_by_name['create_wp_user'] ) ) {
                if ( ! isset( $integration_by_name['link_wp_user'] ) ) {
                    $ok = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN `link_wp_user` tinyint(1) NOT NULL DEFAULT 1 AFTER `allowed_ips`', $integration_table ) );
                    if ( false === $ok ) {
                        $errors[] = 'Could not add integrations.link_wp_user: ' . sanitize_text_field( (string) $wpdb->last_error );
                    }
                }
                if ( empty( $errors ) || isset( $integration_by_name['link_wp_user'] ) ) {
                    $copied = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET `link_wp_user` = `create_wp_user`', $integration_table ) );
                    if ( false === $copied ) {
                        $errors[] = 'Could not migrate integrations legacy WordPress-link flag: ' . sanitize_text_field( (string) $wpdb->last_error );
                    } else {
                        $dropped = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN `create_wp_user`', $integration_table ) );
                        if ( false === $dropped ) {
                            $errors[] = 'Could not remove the obsolete integrations legacy WordPress-link column: ' . sanitize_text_field( (string) $wpdb->last_error );
                        }
                    }
                }
            }
        }

        return array_values( array_filter( $errors ) );
    }

}
