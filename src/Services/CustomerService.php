<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO uses versioned custom evomembers_* operational tables; there is no equivalent WordPress CRUD API, and transactional data is intentionally read fresh.

use EvoMembers\Core\Database;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CustomerService {
    public function find_or_create( array $data, bool $link_wp_user = false ): int {
        global $wpdb;
        $email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
        if ( '' === $email || ! is_email( $email ) ) {
            return 0;
        }

        $table = Database::table( 'customers' );
        $id = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s LIMIT 1', $table, $email )
        );
        $now = current_time( 'mysql', true );

        $row = array(
            'email'               => $email,
            'secondary_email'     => $this->email_or_null( $data['secondary_email'] ?? null ),
            'first_name'          => $this->text_or_null( $data['first_name'] ?? null, 100 ),
            'middle_name'         => $this->text_or_null( $data['middle_name'] ?? null, 100 ),
            'last_name'           => $this->text_or_null( $data['last_name'] ?? null, 100 ),
            'display_name'        => $this->text_or_null( $data['display_name'] ?? null, 190 ),
            'phone'               => $this->text_or_null( $data['phone'] ?? null, 60 ),
            'phone_country_code'  => $this->text_or_null( $data['phone_country_code'] ?? null, 12 ),
            'company'             => $this->text_or_null( $data['company'] ?? null, 190 ),
            'address_1'           => $this->text_or_null( $data['address_1'] ?? null, 255 ),
            'address_2'           => $this->text_or_null( $data['address_2'] ?? null, 255 ),
            'city'                => $this->text_or_null( $data['city'] ?? null, 120 ),
            'state_region'        => $this->text_or_null( $data['state_region'] ?? null, 120 ),
            'postal_code'         => $this->text_or_null( $data['postal_code'] ?? null, 40 ),
            'country_code'        => $this->text_or_null( $data['country_code'] ?? null, 12 ),
            'locale'              => $this->text_or_null( $data['locale'] ?? null, 40 ),
            'timezone'            => $this->text_or_null( $data['timezone'] ?? null, 80 ),
            'tax_id'              => $this->text_or_null( $data['tax_id'] ?? null, 100 ),
            'vat_number'          => $this->text_or_null( $data['vat_number'] ?? null, 100 ),
            'last_purchase_at'    => $this->date_or_null( $data['purchased_at'] ?? null ),
            'updated_at'          => $now,
        );
        $row = array_filter( $row, static fn( $value ) => null !== $value );

        if ( $id > 0 ) {
            $wpdb->update( $table, $row, array( 'id' => $id ) );
        } else {
            $row['status']            = 'active';
            $row['registered_at']     = $now;
            $row['first_purchase_at'] = $this->date_or_null( $data['purchased_at'] ?? null );
            $row['created_at']        = $now;
            if ( false === $wpdb->insert( $table, $row ) ) {
                return 0;
            }
            $id = (int) $wpdb->insert_id;
        }

        if ( $id > 0 && $link_wp_user ) {
            $this->ensure_wp_user( $id, $data );
        }

        return $id;
    }

    public function find_by_wp_user( int $wp_user_id ): ?array {
        global $wpdb;
        if ( $wp_user_id < 1 ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id=%d LIMIT 1', Database::table( 'customers' ), $wp_user_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function find_by_email( string $email ): ?array {
        global $wpdb;
        $email = sanitize_email( $email );
        if ( '' === $email || ! is_email( $email ) ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s LIMIT 1', Database::table( 'customers' ), $email ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function get( int $id ): ?array {
        global $wpdb;
        if ( $id < 1 ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', Database::table( 'customers' ), $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Create or link a local EVO customer identity from an existing WordPress user.
     *
     * This is an explicit administrator-only local workflow. It never creates a
     * WordPress account and it never grants a membership, license or entitlement.
     */
    public function create_or_link_from_wp_user( int $wp_user_id ): int|WP_Error {
        $wp_user_id = absint( $wp_user_id );
        if ( $wp_user_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_invalid' ), __( 'A valid WordPress user is required.', 'evoxup-membership' ) );
        }

        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_not_found' ), __( 'The WordPress account could not be found.', 'evoxup-membership' ) );
        }

        $existing = $this->find_by_wp_user( $wp_user_id );
        if ( $existing ) {
            return (int) $existing['id'];
        }

        $email = sanitize_email( (string) $user->user_email );
        if ( '' === $email || ! is_email( $email ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_email_invalid' ), __( 'The WordPress account does not have a valid email address.', 'evoxup-membership' ) );
        }

        $existing = $this->find_by_email( $email );
        if ( $existing ) {
            $linked = $this->ensure_wp_user( (int) $existing['id'] );
            return is_wp_error( $linked ) ? $linked : (int) $existing['id'];
        }

        $customer_id = $this->find_or_create(
            array(
                'email'         => $email,
                'first_name'    => sanitize_text_field( (string) $user->first_name ),
                'last_name'     => sanitize_text_field( (string) $user->last_name ),
                'display_name'  => sanitize_text_field( (string) $user->display_name ),
                'company'       => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_company', true ) ),
                'phone'         => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_phone', true ) ),
                'address_1'     => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_address_1', true ) ),
                'address_2'     => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_address_2', true ) ),
                'city'          => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_city', true ) ),
                'state_region'  => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_state', true ) ),
                'postal_code'   => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_postcode', true ) ),
                'country_code'  => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_country', true ) ),
                'locale'        => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'locale', true ) ),
            ),
            false
        );

        if ( $customer_id < 1 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_create_failed' ), __( 'The EVO member record could not be created.', 'evoxup-membership' ) );
        }

        $linked = $this->ensure_wp_user( $customer_id );
        if ( is_wp_error( $linked ) ) {
            return $linked;
        }

        EventBus::emit(
            'customer.created_from_wp_user',
            array( 'customer_id' => $customer_id, 'wp_user_id' => $wp_user_id ),
            'customer',
            $customer_id,
            $customer_id,
            'wordpress'
        );

        return $customer_id;
    }

    /**
     * Link an existing WordPress account to an EVO customer.
     *
     * WordPress.org compliance note: remote/webhook fulfillment must never
     * create WordPress users. Account creation remains owned by WordPress,
     * WooCommerce or a local administrator workflow. EVO may safely link an
     * already-existing account by its verified email address.
     */
    public function ensure_wp_user( int $customer_id, array $data = array() ): int|WP_Error {
        global $wpdb;
        $customer = $this->get( $customer_id );
        if ( ! $customer || empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_email_missing' ), __( 'Customer email is required to link a WordPress account.', 'evoxup-membership' ) );
        }

        if ( ! empty( $customer['wp_user_id'] ) ) {
            $existing = get_user_by( 'id', (int) $customer['wp_user_id'] );
            if ( $existing ) {
                return (int) $existing->ID;
            }
        }

        $email = sanitize_email( (string) $customer['email'] );
        $user  = get_user_by( 'email', $email );
        if ( ! $user ) {
            return new WP_Error(
                \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_not_found' ),
                __( 'No existing WordPress account uses this customer email. The EVO member was created successfully, but WordPress account creation must be completed locally by WordPress, WooCommerce, or a site administrator.', 'evoxup-membership' )
            );
        }

        $wpdb->update(
            Database::table( 'customers' ),
            array( 'wp_user_id' => (int) $user->ID, 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $customer_id )
        );

        $billing_map = array(
            'billing_first_name' => $customer['first_name'] ?? '',
            'billing_last_name'  => $customer['last_name'] ?? '',
            'billing_company'    => $customer['company'] ?? '',
            'billing_address_1'  => $customer['address_1'] ?? '',
            'billing_address_2'  => $customer['address_2'] ?? '',
            'billing_city'       => $customer['city'] ?? '',
            'billing_state'      => $customer['state_region'] ?? '',
            'billing_postcode'   => $customer['postal_code'] ?? '',
            'billing_country'    => $customer['country_code'] ?? '',
            'billing_email'      => $email,
            'billing_phone'      => $customer['phone'] ?? '',
        );
        foreach ( $billing_map as $meta_key => $meta_value ) {
            if ( '' !== (string) $meta_value ) {
                update_user_meta( (int) $user->ID, $meta_key, sanitize_text_field( (string) $meta_value ) );
            }
        }

        \EvoMembers\Core\Hooks::do_action( 'wp_user_linked', (int) $user->ID, $customer_id, $email );
        Logger::write( 'wp_user_linked', array( 'wp_user_id' => (int) $user->ID, 'email' => $email ), 'info', $customer_id );
        EventBus::emit( 'customer.wp_user_linked', array( 'customer_id'=>$customer_id, 'wp_user_id'=>(int)$user->ID ), 'customer', $customer_id, $customer_id, 'wordpress' );

        return (int) $user->ID;
    }

    /**
     * Update an EVO member profile from a local administrator workflow.
     *
     * This method never creates a WordPress user. When requested, it can sync
     * shared identity/contact fields only to an already-linked local account.
     */
    public function update_profile( int $customer_id, array $data, bool $sync_wp_user = false ): array|WP_Error {
        global $wpdb;

        $customer = $this->get( $customer_id );
        if ( ! $customer ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_not_found' ), __( 'Customer was not found.', 'evoxup-membership' ) );
        }

        $email = sanitize_email( (string) ( $data['email'] ?? $customer['email'] ?? '' ) );
        if ( '' === $email || ! is_email( $email ) ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_email_invalid' ), __( 'A valid customer email is required.', 'evoxup-membership' ) );
        }

        $duplicate_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE email=%s AND id<>%d LIMIT 1',
                Database::table( 'customers' ),
                $email,
                $customer_id
            )
        );
        if ( $duplicate_id > 0 ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_email_exists' ), __( 'Another Evoxup member already uses this email address.', 'evoxup-membership' ) );
        }

        $row = array(
            'email'              => $email,
            'secondary_email'    => $this->email_or_null( $data['secondary_email'] ?? null ),
            'first_name'         => $this->text_or_null( $data['first_name'] ?? null, 100 ),
            'middle_name'        => $this->text_or_null( $data['middle_name'] ?? null, 100 ),
            'last_name'          => $this->text_or_null( $data['last_name'] ?? null, 100 ),
            'display_name'       => $this->text_or_null( $data['display_name'] ?? null, 190 ),
            'phone'              => $this->text_or_null( $data['phone'] ?? null, 60 ),
            'phone_country_code' => $this->text_or_null( $data['phone_country_code'] ?? null, 12 ),
            'company'            => $this->text_or_null( $data['company'] ?? null, 190 ),
            'address_1'          => $this->text_or_null( $data['address_1'] ?? null, 255 ),
            'address_2'          => $this->text_or_null( $data['address_2'] ?? null, 255 ),
            'city'               => $this->text_or_null( $data['city'] ?? null, 120 ),
            'state_region'       => $this->text_or_null( $data['state_region'] ?? null, 120 ),
            'postal_code'        => $this->text_or_null( $data['postal_code'] ?? null, 40 ),
            'country_code'       => $this->text_or_null( $data['country_code'] ?? null, 12 ),
            'locale'             => $this->text_or_null( $data['locale'] ?? null, 40 ),
            'timezone'           => $this->text_or_null( $data['timezone'] ?? null, 80 ),
            'tax_id'             => $this->text_or_null( $data['tax_id'] ?? null, 100 ),
            'vat_number'         => $this->text_or_null( $data['vat_number'] ?? null, 100 ),
            'updated_at'         => current_time( 'mysql', true ),
        );

        $wp_user_id = absint( $customer['wp_user_id'] ?? 0 );
        if ( $sync_wp_user && $wp_user_id > 0 ) {
            if ( ! current_user_can( 'edit_user', $wp_user_id ) ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_edit_forbidden' ), __( 'You are not allowed to update the linked WordPress account.', 'evoxup-membership' ) );
            }
            $wp_user = get_userdata( $wp_user_id );
            if ( ! $wp_user ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_unavailable' ), __( 'The linked WordPress account could not be resolved.', 'evoxup-membership' ) );
            }
            $email_owner = email_exists( $email );
            if ( $email_owner && (int) $email_owner !== $wp_user_id ) {
                return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_email_exists' ), __( 'Another WordPress account already uses this email address.', 'evoxup-membership' ) );
            }

            $wp_result = wp_update_user(
                array(
                    'ID'           => $wp_user_id,
                    'user_email'   => $email,
                    'first_name'   => (string) ( $row['first_name'] ?? '' ),
                    'last_name'    => (string) ( $row['last_name'] ?? '' ),
                    'display_name' => (string) ( $row['display_name'] ?: trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ) ?: $wp_user->display_name ),
                )
            );
            if ( is_wp_error( $wp_result ) ) {
                return $wp_result;
            }

            if ( ! empty( $row['locale'] ) ) {
                update_user_meta( $wp_user_id, 'locale', sanitize_text_field( (string) $row['locale'] ) );
            }
            $billing_map = array(
                'billing_first_name' => $row['first_name'] ?? '',
                'billing_last_name'  => $row['last_name'] ?? '',
                'billing_company'    => $row['company'] ?? '',
                'billing_address_1'  => $row['address_1'] ?? '',
                'billing_address_2'  => $row['address_2'] ?? '',
                'billing_city'       => $row['city'] ?? '',
                'billing_state'      => $row['state_region'] ?? '',
                'billing_postcode'   => $row['postal_code'] ?? '',
                'billing_country'    => $row['country_code'] ?? '',
                'billing_email'      => $email,
                'billing_phone'      => $row['phone'] ?? '',
            );
            foreach ( $billing_map as $meta_key => $meta_value ) {
                update_user_meta( $wp_user_id, $meta_key, sanitize_text_field( (string) $meta_value ) );
            }
        }

        $updated = $wpdb->update( Database::table( 'customers' ), $row, array( 'id' => $customer_id ) );
        if ( false === $updated ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_update_failed' ), __( 'The member profile could not be updated.', 'evoxup-membership' ) );
        }

        EventBus::emit(
            'customer.profile_updated',
            array( 'customer_id' => $customer_id, 'wp_user_synced' => $sync_wp_user && $wp_user_id > 0 ),
            'customer',
            $customer_id,
            $customer_id,
            'admin'
        );

        return $this->get( $customer_id ) ?: array();
    }

    public function send_account_access( int $customer_id ): bool|WP_Error {
        $customer = $this->get( $customer_id );
        if ( ! $customer ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'customer_not_found' ), __( 'Customer was not found.', 'evoxup-membership' ) );
        }
        $user_id = $this->ensure_wp_user( $customer_id );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }
        $user = get_userdata( (int) $user_id );
        if ( ! $user ) {
            return new WP_Error( \EvoMembers\Core\MembershipIdentifiers::error_code( 'wp_user_unavailable' ), __( 'WordPress account could not be resolved.', 'evoxup-membership' ) );
        }
        $center_url = ( new PurchaseLinkService() )->membership_center_url();
        $subject = __( 'Your Evoxup member access', 'evoxup-membership' );
        /* translators: 1: line break, 2: WordPress username, 3: login URL, 4: password reset URL, 5: membership center URL. */
        $message_template = __( 'Your Evoxup member access details:%1$s%1$sUsername: %2$s%1$sLogin: %3$s%1$sSet or reset password: %4$s%1$sMembership Center: %5$s', 'evoxup-membership' );
        $message = sprintf(
            $message_template,
            "\n",
            (string) $user->user_login,
            wp_login_url( $center_url ),
            wp_lostpassword_url( $center_url ),
            $center_url
        );
        $created = ( new MessageService() )->create( $customer_id, $subject, $message, 'both' );
        return ! is_wp_error( $created ) && 'failed' !== (string) ( $created['status'] ?? '' );
    }

    public function set_status( int $id, string $status ): bool {
        global $wpdb;
        $status = sanitize_key( $status );
        if ( $id < 1 || ! in_array( $status, array( 'active', 'inactive', 'frozen', 'archived' ), true ) ) { return false; }
        $customer = $this->get( $id );
        if ( ! $customer ) { return false; }
        $ok = false !== $wpdb->update(
            Database::table( 'customers' ),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id )
        );
        if ( $ok ) {
            EventBus::emit( 'customer.status_changed', array( 'customer_id'=>$id, 'from'=>$customer['status'] ?? '', 'to'=>$status ), 'customer', $id, $id );
        }
        return $ok;
    }

    public function delete( int $id, bool $force = false, bool $delete_wp_user = false ): bool {
        global $wpdb;
        $customer = $this->get( $id );
        if ( ! $customer ) { return false; }
        $membership_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'memberships' ), $id ) );
        $license_count    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'licenses' ), $id ) );
        $order_count      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id=%d', Database::table( 'orders' ), $id ) );
        if ( ! $force && ( $membership_count > 0 || $license_count > 0 || $order_count > 0 ) ) {
            return $this->set_status( $id, 'archived' );
        }
        if ( $force ) {
            $membership_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_id=%d', Database::table( 'memberships' ), $id ) );
            foreach ( (array) $membership_ids as $membership_id ) { ( new MembershipService() )->delete( (int) $membership_id, true ); }
            $license_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_id=%d', Database::table( 'licenses' ), $id ) );
            foreach ( (array) $license_ids as $license_id ) { ( new LicenseService() )->delete( (int) $license_id ); }
            $wpdb->delete( Database::table( 'messages' ), array( 'customer_id'=>$id ), array( '%d' ) );
            $wpdb->delete( Database::table( 'notifications' ), array( 'customer_id'=>$id ), array( '%d' ) );
            // Financial/order history is retained but anonymized from the deleted EVO customer.
            $wpdb->update( Database::table( 'orders' ), array( 'customer_id'=>null ), array( 'customer_id'=>$id ) );
        }
        $ok = false !== $wpdb->delete( Database::table( 'customers' ), array( 'id'=>$id ), array( '%d' ) );
        if ( ! $ok ) { return false; }
        if ( $delete_wp_user && ! empty( $customer['wp_user_id'] ) && current_user_can( 'delete_users' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( (int) $customer['wp_user_id'] );
        }
        EventBus::emit( 'customer.deleted', array( 'customer_id'=>$id, 'forced'=>$force, 'wp_user_deleted'=>$delete_wp_user ), 'customer', $id );
        return true;
    }

    public function all( int $limit = 200 ): array {
        global $wpdb;
        $limit = min( 500, max( 1, $limit ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Database::table( 'customers' ), $limit ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::table( 'customers' ) ) );
    }

    /**
     * Count the unified Customers directory: native WordPress users plus EVO
     * customers that do not correspond to any local WordPress account.
     */
    public function directory_count(): int {
        global $wpdb;

        $user_counts = count_users();
        $wp_total    = absint( $user_counts['total_users'] ?? 0 );
        $evo_only    = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i c LEFT JOIN %i u ON (u.ID=c.wp_user_id) OR ((c.wp_user_id IS NULL OR c.wp_user_id=0) AND LOWER(u.user_email)=LOWER(c.email)) WHERE u.ID IS NULL',
                Database::table( 'customers' ),
                $wpdb->users
            )
        );

        return $wp_total + max( 0, $evo_only );
    }

    private function text_or_null( mixed $value, int $max ): ?string {
        if ( null === $value || '' === trim( (string) $value ) ) {
            return null;
        }
        return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
    }

    private function email_or_null( mixed $value ): ?string {
        $email = sanitize_email( (string) $value );
        return is_email( $email ) ? $email : null;
    }

    private function date_or_null( mixed $value ): ?string {
        if ( empty( $value ) ) {
            return null;
        }
        $time = strtotime( (string) $value );
        return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
    }
}
