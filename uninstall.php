<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * EVO deliberately keeps business/operational records on uninstall so an
 * accidental plugin removal never destroys memberships, licenses or audit
 * history. Plugin-owned runtime options and authorization grants are removed.
 */
delete_option( 'evomembers_db_version' );
delete_option( 'evomembers_settings' );
delete_option( 'evomembers_active_modules' );
delete_option( 'evomembers_center_settings' );
delete_option( 'evomembers_license_settings' );
delete_option( 'evomembers_license_generators' );
delete_option( 'evomembers_external_api_discovery_snapshot' );
delete_option( 'evomembers_security_guard_stats' );
delete_option( 'evomembers_webhook_fallback_events' );
delete_option( 'evomembers_webhook_last_received' );
delete_option( 'evomembers_webhook_last_storage_error' );

require_once __DIR__ . '/src/Core/Capabilities.php';
EvoMembers\Core\Capabilities::uninstall();
delete_option( 'evomembers_capabilities_version' );
delete_option( 'evomembers_capability_registry_defaults' );
