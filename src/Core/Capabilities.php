<?php
namespace EvoMembers\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central Evoxup capability registry and built-in role blueprints.
 *
 * Core owns only the shared authorization contract. Extensions may register
 * additional capabilities at runtime through ExtensionContext without changing
 * Core files or creating private role systems.
 */
final class Capabilities {
    public const DASHBOARD = 'evomembers_view_dashboard';
    public const CUSTOMERS = 'evomembers_manage_customers';
    public const MEMBERSHIPS = 'evomembers_manage_memberships';
    public const PRODUCTS = 'evomembers_manage_products';
    public const LICENSES = 'evomembers_manage_licenses';
    public const ACTIVATIONS = 'evomembers_manage_activations';
    public const INTEGRATIONS = 'evomembers_manage_integrations';
    public const WEBHOOKS = 'evomembers_manage_webhooks';
    public const MODULES = 'evomembers_manage_modules';
    public const LOGS = 'evomembers_view_logs';
    public const SETTINGS = 'evomembers_manage_settings';
    public const ACCESS = 'evomembers_manage_access';

    // Granular product permissions for owners/collaborators.
    public const PRODUCTS_CREATE = 'evomembers_create_products';
    public const PRODUCTS_EDIT_OWN = 'evomembers_edit_own_products';
    public const PRODUCTS_EDIT_OTHERS = 'evomembers_edit_others_products';
    public const PRODUCTS_DELETE_OWN = 'evomembers_delete_own_products';
    public const PRODUCTS_DELETE_OTHERS = 'evomembers_delete_others_products';
    public const PLANS = 'evomembers_manage_plans';
    public const ORDERS = 'evomembers_manage_orders';
    public const LICENSES_ISSUE = 'evomembers_issue_licenses';
    public const LICENSES_REVOKE = 'evomembers_revoke_licenses';
    public const MESSAGES = 'evomembers_manage_messages';
    public const EVENTS = 'evomembers_view_events';

    // Shared platform capabilities used by official extensions.

    /** @var array<string,array{label:string,group:string,roles:array<int,string>}> */
    private static array $registered = array();

    /**
     * Register an extension-owned capability in the central registry.
     *
     * @param array<int,string> $default_roles Built-in Evoxup role slugs that
     *                                         should receive the capability.
     */
    public static function register( string $capability, string $label, string $group = 'Extensions', array $default_roles = array() ): bool {
        $capability = sanitize_key( $capability );
        if ( '' === $capability || ! str_starts_with( $capability, 'evomembers_' ) ) {
            return false;
        }

        $roles = array_values(
            array_intersect(
                array_map( 'sanitize_key', $default_roles ),
                array_keys( self::role_blueprints() )
            )
        );
        self::$registered[ $capability ] = array(
            'label' => sanitize_text_field( $label ?: $capability ),
            'group' => sanitize_text_field( $group ?: 'Extensions' ),
            'roles' => $roles,
        );

        // Administrators always retain every registered Evoxup capability.
        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            $administrator->add_cap( $capability );
        }

        // Apply extension defaults only the first time a capability is seen.
        // Re-applying them on every request would silently undo an explicit
        // Access & Roles decision made by an administrator.
        $defaults = get_option( 'evomembers_capability_registry_defaults', array() );
        $defaults = is_array( $defaults ) ? $defaults : array();
        if ( ! array_key_exists( $capability, $defaults ) ) {
            foreach ( $roles as $role_slug ) {
                $role = get_role( $role_slug );
                if ( $role ) {
                    $role->add_cap( $capability );
                }
            }
            $defaults[ $capability ] = $roles;
            update_option( 'evomembers_capability_registry_defaults', $defaults, false );
        }

        return true;
    }

    /** @return array<string,array{label:string,group:string,roles:array<int,string>}> */
    public static function definitions(): array {
        return array_replace( self::base_definitions(), self::$registered );
    }

    /** @return array<int,string> */
    public static function all(): array {
        return array_keys( self::definitions() );
    }

    /** @return array<string,string> */
    public static function labels(): array {
        $labels = array();
        foreach ( self::definitions() as $capability => $definition ) {
            $labels[ $capability ] = $definition['label'];
        }
        return $labels;
    }

    /** @return array<string,array<string,string>> */
    public static function grouped_labels(): array {
        $groups = array();
        foreach ( self::definitions() as $capability => $definition ) {
            $group = $definition['group'] ?: 'Other';
            if ( ! isset( $groups[ $group ] ) ) {
                $groups[ $group ] = array();
            }
            $groups[ $group ][ $capability ] = $definition['label'];
        }
        return $groups;
    }

    /** @return array<string,array{name:string,caps:array<int,string>}> */
    public static function role_blueprints(): array {
        return array(
            'evomembers_manager' => array(
                'name' => __( 'Evoxup Manager', 'evoxup-membership' ),
                'caps' => array(
                    self::DASHBOARD, self::CUSTOMERS, self::MEMBERSHIPS, self::PRODUCTS,
                    self::PRODUCTS_CREATE, self::PRODUCTS_EDIT_OWN, self::PRODUCTS_EDIT_OTHERS,
                    self::PRODUCTS_DELETE_OWN, self::PRODUCTS_DELETE_OTHERS, self::PLANS, self::ORDERS,
                    self::LICENSES, self::LICENSES_ISSUE, self::LICENSES_REVOKE, self::ACTIVATIONS,
                    self::INTEGRATIONS, self::WEBHOOKS, self::MESSAGES, self::EVENTS, self::LOGS,
                ),
            ),
            'evomembers_support' => array(
                'name' => __( 'Evoxup Support', 'evoxup-membership' ),
                'caps' => array(
                    self::DASHBOARD, self::CUSTOMERS, self::MEMBERSHIPS,
                    self::LICENSES, self::ACTIVATIONS, self::MESSAGES,
                ),
            ),
        );
    }

    public static function maybe_install(): void {
        $version = '1.8.4';
        if ( $version === (string) get_option( 'evomembers_capabilities_version', '' ) ) {
            return;
        }
        self::install();
        update_option( 'evomembers_capabilities_version', $version, false );
    }

    public static function install(): void {
        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            foreach ( self::all() as $capability ) {
                $administrator->add_cap( $capability );
            }
        }

        foreach ( self::role_blueprints() as $slug => $blueprint ) {
            if ( ! get_role( $slug ) ) {
                add_role( $slug, $blueprint['name'], array( 'read' => true ) );
            }
            $role = get_role( $slug );
            if ( ! $role ) {
                continue;
            }
            $role->add_cap( 'read' );
            foreach ( array_keys( self::base_definitions() ) as $capability ) {
                if ( in_array( $capability, $blueprint['caps'], true ) ) {
                    $role->add_cap( $capability );
                } else {
                    $role->remove_cap( $capability );
                }
            }
        }
    }

    public static function uninstall(): void {
        // Remove every Evoxup capability, including capabilities registered by
        // extensions that may not be loaded during WordPress uninstall.
        foreach ( wp_roles()->roles as $role_name => $data ) {
            $role = get_role( (string) $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( array_keys( (array) $role->capabilities ) as $capability ) {
                if ( str_starts_with( sanitize_key( (string) $capability ), 'evomembers_' ) ) {
                    $role->remove_cap( (string) $capability );
                }
            }
        }

        // Uninstall is rare, but it must remain safe on large user tables. Avoid a
        // meta_key/meta_value LIKE scan (which Plugin Check identifies as potentially
        // slow) and process WordPress users in bounded batches instead.
        $offset     = 0;
        $batch_size = 250;
        do {
            $user_ids = get_users(
                array(
                    'fields'  => 'ID',
                    'number'  => $batch_size,
                    'offset'  => $offset,
                    'orderby' => 'ID',
                    'order'   => 'ASC',
                )
            );
            foreach ( $user_ids as $user_id ) {
                $user = get_userdata( (int) $user_id );
                if ( ! $user ) {
                    continue;
                }
                foreach ( array_keys( (array) $user->caps ) as $capability ) {
                    if ( str_starts_with( sanitize_key( (string) $capability ), 'evomembers_' ) ) {
                        $user->remove_cap( (string) $capability );
                    }
                }
            }
            $offset += $batch_size;
        } while ( count( $user_ids ) === $batch_size );
    }

    /** @return array<string,array{label:string,group:string,roles:array<int,string>}> */
    private static function base_definitions(): array {
        return array(
            self::DASHBOARD => array( 'label' => __( 'View Evoxup dashboard', 'evoxup-membership' ), 'group' => 'General', 'roles' => array() ),
            self::CUSTOMERS => array( 'label' => __( 'Manage customers and members', 'evoxup-membership' ), 'group' => 'Members', 'roles' => array() ),
            self::MEMBERSHIPS => array( 'label' => __( 'Manage memberships', 'evoxup-membership' ), 'group' => 'Members', 'roles' => array() ),
            self::PRODUCTS => array( 'label' => __( 'View and manage products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PRODUCTS_CREATE => array( 'label' => __( 'Create products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PRODUCTS_EDIT_OWN => array( 'label' => __( 'Edit own products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PRODUCTS_EDIT_OTHERS => array( 'label' => __( 'Edit other users products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PRODUCTS_DELETE_OWN => array( 'label' => __( 'Delete own products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PRODUCTS_DELETE_OTHERS => array( 'label' => __( 'Delete other users products', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::PLANS => array( 'label' => __( 'Manage membership plans', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::ORDERS => array( 'label' => __( 'Manage orders', 'evoxup-membership' ), 'group' => 'Commerce', 'roles' => array() ),
            self::LICENSES => array( 'label' => __( 'Manage licenses', 'evoxup-membership' ), 'group' => 'Licensing', 'roles' => array() ),
            self::LICENSES_ISSUE => array( 'label' => __( 'Issue licenses', 'evoxup-membership' ), 'group' => 'Licensing', 'roles' => array() ),
            self::LICENSES_REVOKE => array( 'label' => __( 'Revoke or freeze licenses', 'evoxup-membership' ), 'group' => 'Licensing', 'roles' => array() ),
            self::ACTIVATIONS => array( 'label' => __( 'Manage license activations', 'evoxup-membership' ), 'group' => 'Licensing', 'roles' => array() ),
            self::INTEGRATIONS => array( 'label' => __( 'Manage integrations and API keys', 'evoxup-membership' ), 'group' => 'Platform', 'roles' => array() ),
            self::WEBHOOKS => array( 'label' => __( 'Manage webhooks', 'evoxup-membership' ), 'group' => 'Platform', 'roles' => array() ),
            self::MESSAGES => array( 'label' => __( 'Manage member messages', 'evoxup-membership' ), 'group' => 'Communication', 'roles' => array() ),
            self::EVENTS => array( 'label' => __( 'View platform events', 'evoxup-membership' ), 'group' => 'Observability', 'roles' => array() ),
            self::LOGS => array( 'label' => __( 'View technical logs', 'evoxup-membership' ), 'group' => 'Observability', 'roles' => array() ),
            self::MODULES => array( 'label' => __( 'Manage installed extensions', 'evoxup-membership' ), 'group' => 'Platform', 'roles' => array() ),
            self::SETTINGS => array( 'label' => __( 'Manage Evoxup Core settings', 'evoxup-membership' ), 'group' => 'Security', 'roles' => array() ),
            self::ACCESS => array( 'label' => __( 'Manage Evoxup access and roles', 'evoxup-membership' ), 'group' => 'Security', 'roles' => array() ),
        );
    }
}
