<?php
namespace EvoMembers\SDK;

defined( 'ABSPATH' ) || exit;

final class EventCatalog {
    /** @var array<string,array<string,mixed>> */
    private static array $registered = array();

    public static function register( string $event, array $definition, string $owner = 'extension' ): bool {
        $event = self::canonical( $event );
        if ( '' === $event || isset( self::builtins()[ $event ] ) || isset( self::$registered[ $event ] ) ) { return false; }
        self::$registered[ $event ] = self::normalize_definition( $definition, $owner );
        return true;
    }

    public static function definition( string $event ): ?array {
        $event = self::canonical( $event );
        $all = self::all();
        return $all[ $event ] ?? null;
    }

    public static function all(): array {
        return array_replace( self::builtins(), self::$registered );
    }

    public static function validate( string $event, array $payload ): true|\WP_Error {
        $definition = self::definition( $event );
        if ( null === $definition ) {
            return new \WP_Error( 'evoxup_event_not_registered', __( 'The event is not registered in the Evoxup event catalog.', 'evoxup-membership' ) );
        }
        foreach ( (array) ( $definition['required'] ?? array() ) as $key ) {
            if ( ! array_key_exists( $key, $payload ) ) {
                return new \WP_Error( 'evomembers_event_payload_missing_field', sprintf( 'Event %1$s is missing required payload field %2$s.', self::canonical( $event ), $key ) );
            }
        }
        return true;
    }

    private static function normalize_definition( array $definition, string $owner ): array {
        return array(
            'schema_version' => sanitize_text_field( (string) ( $definition['schema_version'] ?? '1.0.0' ) ),
            'owner'          => sanitize_key( $owner ) ?: 'extension',
            'aggregate'      => sanitize_key( (string) ( $definition['aggregate'] ?? '' ) ),
            'description'    => sanitize_text_field( (string) ( $definition['description'] ?? '' ) ),
            'required'       => array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $definition['required'] ?? array() ) ) ) ) ),
        );
    }

    private static function canonical( string $event ): string {
        $parts = array_values( array_filter( array_map( 'sanitize_key', explode( '.', strtolower( trim( $event ) ) ) ) ) );
        return implode( '.', $parts );
    }

    private static function builtins(): array {
        $d = static fn( string $aggregate, array $required, string $description ): array => array(
            'schema_version' => '1.0.0', 'owner' => 'core', 'aggregate' => $aggregate, 'required' => $required, 'description' => $description,
        );
        return array(
            'product.created' => $d( 'product', array( 'product_id','code','source','status' ), 'Product created.' ),
            'product.updated' => $d( 'product', array( 'product_id','code','source','status' ), 'Product updated.' ),
            'product.status_changed' => $d( 'product', array( 'product_id','status' ), 'Product status changed.' ),
            'product.deleted' => $d( 'product', array( 'product_id','forced' ), 'Product deleted.' ),
            'product.woocommerce_synced' => $d( 'product', array(), 'WooCommerce product synchronization completed.' ),
            'plan.saved' => $d( 'plan', array( 'plan_id','tier','mode','status' ), 'Membership plan created or updated.' ),
            'membership.created' => $d( 'membership', array( 'membership_id','plan_id','status' ), 'Membership granted.' ),
            'membership.renewed' => $d( 'membership', array( 'membership_id','expires_at' ), 'Membership renewed.' ),
            'membership.status_changed' => $d( 'membership', array( 'membership_id','from','to' ), 'Membership status changed.' ),
            'membership.deleted' => $d( 'membership', array( 'membership_id','forced' ), 'Membership deleted.' ),
            'license.issued' => $d( 'license', array( 'license_id','product_id','seat_number','seat_total' ), 'Evoxup license issued.' ),
            'license.external_registered' => $d( 'license', array( 'license_id','provider' ), 'External provider license registered.' ),
            'license.verified' => $d( 'license', array( 'license_id','product_id' ), 'License verification succeeded.' ),
            'license.activated' => $d( 'license', array( 'license_id','activation_id','site_url' ), 'License activated.' ),
            'license.deactivated' => $d( 'license', array( 'license_id','activation_id','site_url' ), 'License deactivated.' ),
            'license.status_changed' => $d( 'license', array( 'license_id','status' ), 'License status changed.' ),
            'order.created' => $d( 'order', array( 'order_id' ), 'Order created.' ),
            'order.updated' => $d( 'order', array( 'order_id' ), 'Order updated.' ),
            'order.status_changed' => $d( 'order', array( 'order_id','from','to' ), 'Order status changed.' ),
            'order.deleted' => $d( 'order', array( 'order_id','forced' ), 'Order deleted.' ),
            'customer.wp_user_created' => $d( 'customer', array( 'customer_id','wp_user_id' ), 'WordPress user linked or created for a customer.' ),
            'customer.status_changed' => $d( 'customer', array( 'customer_id','from','to' ), 'Customer status changed.' ),
            'customer.deleted' => $d( 'customer', array( 'customer_id','forced' ), 'Customer deleted.' ),
        );
    }
}
