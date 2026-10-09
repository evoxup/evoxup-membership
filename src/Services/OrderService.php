<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom wp_evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical EVO order ledger.
 *
 * WooCommerce remains the checkout/order engine for WooCommerce sales, while
 * this service keeps a lightweight EVO reference for unified administration,
 * entitlements, memberships and provider-neutral reporting.
 */
final class OrderService {
    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) { return null; }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT o.*, c.email AS customer_email, c.display_name AS customer_name, i.name AS integration_name, i.slug AS integration_slug FROM %i o LEFT JOIN %i c ON c.id=o.customer_id LEFT JOIN %i i ON i.id=o.integration_id WHERE o.id=%d LIMIT 1',
                Database::table( 'orders' ), Database::table( 'customers' ), Database::table( 'integrations' ), $id
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) ) { return null; }
        $row['items'] = $this->items( $id );
        return $row;
    }

    /**
     * Find a provider-owned order mirrored into the EVO ledger.
     *
     * The provider reference is namespaced before storage so two providers may
     * safely use the same native order identifier.
     */
    public function find_by_provider_reference( string $provider, string $provider_order_id ): ?array {
        global $wpdb;
        $provider = sanitize_key( $provider );
        $provider_order_id = sanitize_text_field( $provider_order_id );
        if ( '' === $provider || '' === $provider_order_id ) { return null; }
        $external = $provider . ':' . $provider_order_id;
        $id = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE external_order_id=%s LIMIT 1', Database::table( 'orders' ), $external )
        );
        if ( $id < 1 && 'woocommerce' === $provider ) {
            $wc_id = absint( $provider_order_id );
            if ( $wc_id > 0 ) {
                $id = (int) $wpdb->get_var(
                    $wpdb->prepare( 'SELECT id FROM %i WHERE woocommerce_order_id=%d LIMIT 1', Database::table( 'orders' ), $wc_id )
                );
            }
        }
        return $id > 0 ? $this->get( $id ) : null;
    }

    /**
     * Public provider-neutral order import used by first-class commerce
     * integrations. The external platform remains owner of checkout/order
     * state; EVO stores only a normalized ledger reference for entitlements,
     * memberships, automation and unified administration.
     *
     * Required order fields: provider, provider_order_id.
     */
    public function upsert_provider_order( array $order, array $items = array() ): int|WP_Error {
        global $wpdb;
        $provider = sanitize_key( (string) ( $order['provider'] ?? '' ) );
        $provider_order_id = sanitize_text_field( (string) ( $order['provider_order_id'] ?? '' ) );
        if ( '' === $provider || '' === $provider_order_id ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'provider_order_missing_reference' ), __( 'Provider and provider order reference are required.', 'evoxup-membership' ) );
        }

        $external_order_id = $provider . ':' . $provider_order_id;
        $wc_order_id = 'woocommerce' === $provider ? absint( $order['woocommerce_order_id'] ?? $provider_order_id ) : 0;
        $existing = $this->find_by_provider_reference( $provider, $provider_order_id );
        $existing_id = absint( $existing['id'] ?? 0 );
        $now = current_time( 'mysql', true );
        $status = sanitize_key( (string) ( $order['status'] ?? 'received' ) ) ?: 'received';
        $metadata = is_array( $order['metadata'] ?? null ) ? $order['metadata'] : array();
        $metadata = array_merge(
            $metadata,
            array(
                'provider'          => $provider,
                'provider_order_id' => $provider_order_id,
            )
        );

        $row = array(
            'customer_id'             => absint( $order['customer_id'] ?? 0 ) ?: null,
            'integration_id'          => absint( $order['integration_id'] ?? 0 ) ?: null,
            'woocommerce_order_id'    => $wc_order_id > 0 ? $wc_order_id : null,
            'external_order_id'       => $external_order_id,
            'external_transaction_id' => sanitize_text_field( (string) ( $order['transaction_id'] ?? '' ) ) ?: null,
            'status'                  => substr( $status, 0, 40 ),
            'currency'                => sanitize_text_field( (string) ( $order['currency'] ?? '' ) ) ?: null,
            'subtotal'                => array_key_exists( 'subtotal', $order ) ? $this->decimal( $order['subtotal'] ) : null,
            'discount'                => array_key_exists( 'discount', $order ) ? $this->decimal( $order['discount'] ) : null,
            'tax'                     => array_key_exists( 'tax', $order ) ? $this->decimal( $order['tax'] ) : null,
            'shipping'                => array_key_exists( 'shipping', $order ) ? $this->decimal( $order['shipping'] ) : null,
            'total'                   => array_key_exists( 'total', $order ) ? $this->decimal( $order['total'] ) : null,
            'refunded_amount'         => array_key_exists( 'refunded_amount', $order ) ? $this->decimal( $order['refunded_amount'] ) : null,
            'coupon_code'             => sanitize_text_field( (string) ( $order['coupon_code'] ?? '' ) ) ?: null,
            'payment_method'          => sanitize_text_field( (string) ( $order['payment_method'] ?? '' ) ) ?: null,
            'payment_status'          => sanitize_key( (string) ( $order['payment_status'] ?? '' ) ) ?: null,
            'purchased_at'            => $this->normalized_datetime( $order['purchased_at'] ?? null ),
            'paid_at'                 => $this->normalized_datetime( $order['paid_at'] ?? null ),
            'refunded_at'             => $this->normalized_datetime( $order['refunded_at'] ?? null ),
            'cancelled_at'            => $this->normalized_datetime( $order['cancelled_at'] ?? null ),
            'fulfillment_mode'        => sanitize_key( (string) ( $order['fulfillment_mode'] ?? $provider ) ) ?: $provider,
            'fulfillment_status'      => sanitize_key( (string) ( $order['fulfillment_status'] ?? ( $existing['fulfillment_status'] ?? 'pending' ) ) ) ?: 'pending',
            'provider_data_json'      => wp_json_encode( $metadata ),
            'updated_at'              => $now,
        );

        if ( $existing_id > 0 ) {
            if ( false === $wpdb->update( Database::table( 'orders' ), $row, array( 'id' => $existing_id ) ) ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'provider_order_update_failed' ), __( 'Provider order could not be updated in the EVO ledger.', 'evoxup-membership' ) );
            }
            $id = $existing_id;
        } else {
            $row['created_at'] = $now;
            if ( false === $wpdb->insert( Database::table( 'orders' ), $row ) ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'provider_order_insert_failed' ), __( 'Provider order could not be created in the EVO ledger.', 'evoxup-membership' ) );
            }
            $id = (int) $wpdb->insert_id;
        }

        $this->sync_provider_items( $id, $items );
        EventBus::emit(
            $existing_id > 0 ? 'order.updated' : 'order.created',
            array( 'order_id' => $id, 'provider' => $provider, 'provider_order_id' => $provider_order_id, 'status' => $status ),
            'order',
            $id,
            absint( $row['customer_id'] ?? 0 ),
            $provider
        );
        return $id;
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status<>%s', Database::table( 'orders' ), 'archived' )
        );
    }

    public function count_for_customer( int $customer_id ): int {
        global $wpdb;
        if ( $customer_id < 1 ) { return 0; }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'orders' ), $customer_id ) );
    }

    public function all( int $limit = 300 ): array {
        global $wpdb;
        $limit = min( 1000, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT o.*, c.email AS customer_email, c.display_name AS customer_name, i.name AS integration_name, i.slug AS integration_slug, (SELECT COUNT(*) FROM %i oi WHERE oi.order_id=o.id) AS item_count FROM %i o LEFT JOIN %i c ON c.id=o.customer_id LEFT JOIN %i i ON i.id=o.integration_id ORDER BY o.id DESC LIMIT %d',
                Database::table( 'order_items' ), Database::table( 'orders' ), Database::table( 'customers' ), Database::table( 'integrations' ), $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function for_customer( int $customer_id, int $limit = 100 ): array {
        global $wpdb;
        if ( $customer_id < 1 ) { return array(); }
        $limit = min( 500, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT o.*, (SELECT COUNT(*) FROM %i oi WHERE oi.order_id=o.id) AS item_count FROM %i o WHERE o.customer_id=%d ORDER BY o.id DESC LIMIT %d',
                Database::table( 'order_items' ), Database::table( 'orders' ), $customer_id, $limit
            ), ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function items( int $order_id ): array {
        global $wpdb;
        if ( $order_id < 1 ) { return array(); }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT oi.*, p.name AS product_name, p.code AS product_code, pl.name AS plan_name, pl.code AS plan_code FROM %i oi LEFT JOIN %i p ON p.id=oi.product_id LEFT JOIN %i pl ON pl.id=oi.plan_id WHERE oi.order_id=%d ORDER BY oi.id ASC',
                Database::table( 'order_items' ), Database::table( 'products' ), Database::table( 'plans' ), $order_id
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function set_status( int $id, string $status ): bool {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( $id < 1 || ! in_array( $status, self::statuses(), true ) ) { return false; }
        $order = $this->get( $id );
        if ( ! $order ) { return false; }
        $now = current_time( 'mysql', true );
        $row = array( 'status'=>$status, 'updated_at'=>$now );
        if ( 'cancelled' === $status ) { $row['cancelled_at'] = $now; }
        if ( 'refunded' === $status ) { $row['refunded_at'] = $now; }
        $ok = false !== $wpdb->update( Database::table( 'orders' ), $row, array( 'id'=>$id ) );
        if ( $ok ) {
            EventBus::emit( 'order.status_changed', array( 'order_id'=>$id, 'from'=>$order['status'] ?? '', 'to'=>$status ), 'order', $id, (int) ( $order['customer_id'] ?? 0 ), $this->source_from_row( $order ) );
        }
        return $ok;
    }

    /**
     * Create or update the EVO ledger entry for a native WooCommerce order.
     * Does not replace or mutate the WooCommerce order itself.
     */
    public function upsert_woocommerce_order( object $order ): int|WP_Error {
        global $wpdb;
        if ( ! method_exists( $order, 'get_id' ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'order_invalid' ), __( 'WooCommerce order is invalid.', 'evoxup-membership' ) );
        }
        $wc_order_id = absint( $order->get_id() );
        if ( $wc_order_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'order_invalid' ), __( 'WooCommerce order ID is missing.', 'evoxup-membership' ) );
        }
        $customer_id = 0;
        $email = method_exists( $order, 'get_billing_email' ) ? sanitize_email( (string) $order->get_billing_email() ) : '';
        if ( is_email( $email ) ) {
            $customer_id = ( new CustomerService() )->find_or_create(
                array(
                    'email'=>$email,
                    'first_name'=>method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '',
                    'last_name'=>method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '',
                    'phone'=>method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '',
                    'country_code'=>method_exists( $order, 'get_billing_country' ) ? (string) $order->get_billing_country() : '',
                    'purchased_at'=>current_time( 'mysql', true ),
                ),
                false
            );
        }
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE woocommerce_order_id=%d LIMIT 1', Database::table( 'orders' ), $wc_order_id )
        );
        $now = current_time( 'mysql', true );
        $created_at = $this->wc_date( method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null ) ?: $now;
        $paid_at = $this->wc_date( method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null );
        $status = method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : 'received';
        $row = array(
            'customer_id'          => $customer_id ?: null,
            'woocommerce_order_id' => $wc_order_id,
            'external_order_id'    => 'wc-' . $wc_order_id,
            'external_transaction_id' => method_exists( $order, 'get_transaction_id' ) ? sanitize_text_field( (string) $order->get_transaction_id() ) ?: null : null,
            'status'               => $status ?: 'received',
            'currency'             => method_exists( $order, 'get_currency' ) ? sanitize_text_field( (string) $order->get_currency() ) : null,
            'subtotal'             => method_exists( $order, 'get_subtotal' ) ? $this->decimal( $order->get_subtotal() ) : null,
            'discount'             => method_exists( $order, 'get_discount_total' ) ? $this->decimal( $order->get_discount_total() ) : null,
            'tax'                  => method_exists( $order, 'get_total_tax' ) ? $this->decimal( $order->get_total_tax() ) : null,
            'shipping'             => method_exists( $order, 'get_shipping_total' ) ? $this->decimal( $order->get_shipping_total() ) : null,
            'total'                => method_exists( $order, 'get_total' ) ? $this->decimal( $order->get_total() ) : null,
            'payment_method'       => method_exists( $order, 'get_payment_method' ) ? sanitize_text_field( (string) $order->get_payment_method() ) ?: null : null,
            'payment_status'       => method_exists( $order, 'is_paid' ) && $order->is_paid() ? 'paid' : 'pending',
            'purchased_at'         => $created_at,
            'paid_at'              => $paid_at,
            'fulfillment_mode'     => 'woocommerce',
            'provider_data_json'   => wp_json_encode( array( 'source'=>'woocommerce', 'woocommerce_order_id'=>$wc_order_id ) ),
            'updated_at'           => $now,
        );
        if ( $existing > 0 ) {
            if ( false === $wpdb->update( Database::table( 'orders' ), $row, array( 'id'=>$existing ) ) ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'order_update_failed' ), __( 'EVO order ledger could not be updated.', 'evoxup-membership' ) );
            }
            $id = $existing;
        } else {
            $row['created_at'] = $now;
            if ( false === $wpdb->insert( Database::table( 'orders' ), $row ) ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'order_insert_failed' ), __( 'EVO order ledger could not be created.', 'evoxup-membership' ) );
            }
            $id = (int) $wpdb->insert_id;
        }
        $this->sync_woocommerce_items( $id, $order );
        EventBus::emit( $existing > 0 ? 'order.updated' : 'order.created', array( 'order_id'=>$id, 'woocommerce_order_id'=>$wc_order_id, 'status'=>$status ), 'order', $id, $customer_id, 'woocommerce' );
        return $id;
    }

    public function sync_external_item( int $order_id, array $normalized ): void {
        global $wpdb;
        if ( $order_id < 1 ) { return; }
        $routing = is_array( $normalized['_evo_routing'] ?? null ) ? $normalized['_evo_routing'] : array();
        $product = is_array( $normalized['product'] ?? null ) ? $normalized['product'] : array();
        $order   = is_array( $normalized['order'] ?? null ) ? $normalized['order'] : array();
        $quantity = max( 1, absint( $product['quantity'] ?? 1 ) );
        $total = isset( $order['total'] ) && '' !== (string) $order['total'] ? $this->decimal( $order['total'] ) : null;
        $unit = null !== $total ? $this->decimal( (float) $total / $quantity ) : null;
        $license = is_array( $routing['license_settings'] ?? null ) ? $routing['license_settings'] : array();
        $now = current_time( 'mysql', true );
        $wpdb->delete( Database::table( 'order_items' ), array( 'order_id'=>$order_id ), array( '%d' ) );
        $wpdb->insert(
            Database::table( 'order_items' ),
            array(
                'order_id'=>$order_id,
                'product_id'=>absint( $routing['product_id'] ?? 0 ) ?: null,
                'plan_id'=>absint( $routing['plan_id'] ?? 0 ) ?: null,
                'provider_product_id'=>sanitize_text_field( (string) ( $product['external_product_id'] ?? '' ) ) ?: null,
                'provider_item_id'=>sanitize_text_field( (string) ( $product['external_variant_id'] ?? '' ) ) ?: null,
                'name'=>sanitize_text_field( (string) ( $product['name'] ?? '' ) ) ?: null,
                'quantity'=>$quantity,
                'unit_price'=>$unit,
                'total'=>$total,
                'license_provider'=>sanitize_key( (string) ( $license['provider'] ?? 'none' ) ) ?: 'none',
                'license_seats'=>max( 1, absint( $license['license_seats'] ?? $license['max_activations'] ?? 1 ) ),
                'metadata_json'=>wp_json_encode( array( 'target_type'=>$routing['target_type'] ?? '', 'target_id'=>$routing['target_id'] ?? 0 ) ),
                'created_at'=>$now,
                'updated_at'=>$now,
            )
        );
    }

    public function delete( int $id, bool $force = false ): bool {
        global $wpdb;
        $order = $this->get( $id );
        if ( ! $order ) { return false; }
        $license_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_from_order_id=%d OR source_order_id=%d', Database::table( 'licenses' ), $id, $id ) );
        $membership_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source_order_id=%d', Database::table( 'memberships' ), $id ) );
        if ( ! $force && ( $license_count > 0 || $membership_count > 0 ) ) {
            return $this->set_status( $id, 'archived' );
        }
        if ( $force ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_from_order_id=NULL WHERE created_from_order_id=%d', Database::table( 'licenses' ), $id ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET source_order_id=NULL WHERE source_order_id=%d', Database::table( 'licenses' ), $id ) );
            $wpdb->query( $wpdb->prepare( 'UPDATE %i SET source_order_id=NULL WHERE source_order_id=%d', Database::table( 'memberships' ), $id ) );
            $wpdb->delete( Database::table( 'transactions' ), array( 'order_id'=>$id ), array( '%d' ) );
        }
        $wpdb->delete( Database::table( 'order_items' ), array( 'order_id'=>$id ), array( '%d' ) );
        $ok = false !== $wpdb->delete( Database::table( 'orders' ), array( 'id'=>$id ), array( '%d' ) );
        if ( $ok ) { EventBus::emit( 'order.deleted', array( 'order_id'=>$id, 'forced'=>$force ), 'order', $id, (int) ( $order['customer_id'] ?? 0 ), $this->source_from_row( $order ) ); }
        return $ok;
    }

    public static function statuses(): array {
        return array( 'received','pending','processing','completed','cancelled','refunded','failed','archived' );
    }

    private function sync_woocommerce_items( int $evo_order_id, object $order ): void {
        global $wpdb;
        if ( ! method_exists( $order, 'get_items' ) ) { return; }
        $wpdb->delete( Database::table( 'order_items' ), array( 'order_id'=>$evo_order_id ), array( '%d' ) );
        $now = current_time( 'mysql', true );
        foreach ( $order->get_items( 'line_item' ) as $item_id=>$item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) { continue; }
            $wc_product_id = absint( method_exists( $item, 'get_variation_id' ) ? $item->get_variation_id() : 0 );
            if ( $wc_product_id < 1 ) { $wc_product_id = absint( $item->get_product_id() ); }
            $settings = class_exists( '\\EvoMembers\\Integrations\\WooCommerce\\ProductLicensing' )
                ? \EvoMembers\Integrations\WooCommerce\ProductLicensing::settings_for_product( $wc_product_id )
                : array();
            $quantity = max( 1, absint( method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1 ) );
            $total = method_exists( $item, 'get_total' ) ? $this->decimal( $item->get_total() ) : null;
            $unit = null !== $total ? $this->decimal( (float) $total / $quantity ) : null;
            $wpdb->insert(
                Database::table( 'order_items' ),
                array(
                    'order_id'=>$evo_order_id,
                    'product_id'=>absint( $settings['evo_product_id'] ?? 0 ) ?: null,
                    'plan_id'=>absint( $settings['plan_id'] ?? 0 ) ?: null,
                    'provider_product_id'=>(string) $wc_product_id,
                    'provider_item_id'=>(string) absint( $item_id ),
                    'name'=>method_exists( $item, 'get_name' ) ? sanitize_text_field( (string) $item->get_name() ) : null,
                    'quantity'=>$quantity,
                    'unit_price'=>$unit,
                    'total'=>$total,
                    'license_provider'=>sanitize_key( (string) ( $settings['provider'] ?? 'none' ) ),
                    'license_seats'=>max( 1, absint( $settings['max_activations'] ?? 1 ) ),
                    'metadata_json'=>wp_json_encode( array( 'woocommerce_product_id'=>$wc_product_id ) ),
                    'created_at'=>$now,
                    'updated_at'=>$now,
                )
            );
        }
    }

    private function sync_provider_items( int $order_id, array $items ): void {
        global $wpdb;
        if ( $order_id < 1 ) { return; }
        $wpdb->delete( Database::table( 'order_items' ), array( 'order_id' => $order_id ), array( '%d' ) );
        $now = current_time( 'mysql', true );
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $quantity = max( 1, absint( $item['quantity'] ?? 1 ) );
            $total = array_key_exists( 'total', $item ) ? $this->decimal( $item['total'] ) : null;
            $unit = array_key_exists( 'unit_price', $item ) ? $this->decimal( $item['unit_price'] ) : ( null !== $total ? $this->decimal( (float) $total / $quantity ) : null );
            $metadata = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
            $wpdb->insert(
                Database::table( 'order_items' ),
                array(
                    'order_id'            => $order_id,
                    'product_id'          => absint( $item['product_id'] ?? 0 ) ?: null,
                    'plan_id'             => absint( $item['plan_id'] ?? 0 ) ?: null,
                    'provider_product_id' => sanitize_text_field( (string) ( $item['provider_product_id'] ?? '' ) ) ?: null,
                    'provider_item_id'    => sanitize_text_field( (string) ( $item['provider_item_id'] ?? '' ) ) ?: null,
                    'name'                => sanitize_text_field( (string) ( $item['name'] ?? '' ) ) ?: null,
                    'quantity'            => $quantity,
                    'unit_price'          => $unit,
                    'total'               => $total,
                    'license_provider'    => sanitize_key( (string) ( $item['license_provider'] ?? 'none' ) ) ?: 'none',
                    'license_seats'       => max( 1, absint( $item['license_seats'] ?? 1 ) ),
                    'metadata_json'       => wp_json_encode( $metadata ),
                    'created_at'          => $now,
                    'updated_at'          => $now,
                )
            );
        }
    }

    private function normalized_datetime( mixed $value ): ?string {
        if ( null === $value || '' === $value ) { return null; }
        if ( is_object( $value ) && method_exists( $value, 'getTimestamp' ) ) {
            return gmdate( 'Y-m-d H:i:s', (int) $value->getTimestamp() );
        }
        if ( is_numeric( $value ) ) { return gmdate( 'Y-m-d H:i:s', (int) $value ); }
        $ts = strtotime( (string) $value );
        return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
    }

    private function source_from_row( array $row ): string {
        if ( ! empty( $row['woocommerce_order_id'] ) ) { return 'woocommerce'; }
        return sanitize_key( (string) ( $row['integration_slug'] ?? 'evo' ) ) ?: 'evo';
    }

    private function decimal( mixed $value ): string {
        return number_format( (float) $value, 8, '.', '' );
    }

    private function wc_date( mixed $date ): ?string {
        if ( ! is_object( $date ) || ! method_exists( $date, 'getTimestamp' ) ) { return null; }
        return gmdate( 'Y-m-d H:i:s', (int) $date->getTimestamp() );
    }
}
