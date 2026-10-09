<?php
namespace EvoMembers\Admin;

use EvoMembers\Core\Capabilities;
use EvoMembers\Core\ExtensionContext;
use EvoMembers\Services\CustomerService;
use EvoMembers\Services\LicenseService;
use EvoMembers\Services\PurchaseLinkService;

defined( 'ABSPATH' ) || exit;

final class MemberAdministration {
    private ExtensionContext $context;

    public function __construct() {
        $this->context = new ExtensionContext( 'evomembers-member-administration', array( 'version' => EVOMEMBERS_VERSION ) );
        $this->context->register_event(
            'member.admin_note',
            array(
                'schema_version' => '1.0.0',
                'aggregate'      => 'customer',
                'description'    => __( 'Private administrative note added to the member timeline.', 'evoxup-membership' ),
                'required'       => array( 'note' ),
            )
        );
    }

    public function assets( string $hook ): void {
        if ( false === strpos( $hook, 'evomembers-customers' ) ) { return; }
        // Keep this stylesheet self-contained. A missing optional parent style handle
        // must never prevent the member workspace from being styled.
        wp_enqueue_style(
            'evomembers-member-administration',
            EVOMEMBERS_URL . 'assets/member-administration.css',
            array(),
            EVOMEMBERS_VERSION
        );
    }

    public function render( bool $handled ): bool {
        if ( $handled || ! current_user_can( Capabilities::CUSTOMERS ) ) { return $handled; }

        $customer_id = absint( filter_input( INPUT_GET, 'customer_id', FILTER_SANITIZE_NUMBER_INT ) );
        $wp_user_id  = absint( filter_input( INPUT_GET, 'wp_user_id', FILTER_SANITIZE_NUMBER_INT ) );

        if ( $customer_id > 0 ) {
            $this->render_editor( $customer_id );
            return true;
        }

        if ( $wp_user_id > 0 ) {
            $this->render_wordpress_user( $wp_user_id );
            return true;
        }

        $this->render_list();
        return true;
    }

    public function actions(): void {
        if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) ) { return; }
        $action = isset( $_POST['evomembers_member_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['evomembers_member_action'] ) ) : '';
        if ( '' === $action ) { return; }

        $customer_id = absint( wp_unslash( $_POST['customer_id'] ?? 0 ) );

        $requirements = array(
            'save_profile'       => Capabilities::CUSTOMERS,
            'ensure_wp_user'     => Capabilities::CUSTOMERS,
            'adopt_wp_user'      => Capabilities::CUSTOMERS,
            'send_access'        => Capabilities::CUSTOMERS,
            'grant_membership'   => Capabilities::MEMBERSHIPS,
            'membership_status'  => Capabilities::MEMBERSHIPS,
            'renew_membership'   => Capabilities::MEMBERSHIPS,
            'issue_license'      => Capabilities::LICENSES_ISSUE,
            'license_status'     => Capabilities::LICENSES_REVOKE,
            'stop_activation'    => Capabilities::ACTIVATIONS,
            'add_note'           => Capabilities::CUSTOMERS,
        );
        $required = $requirements[ $action ] ?? '';
        if ( '' === $required || ! current_user_can( $required ) ) {
            $this->context->audit( 'member_admin.denied', 'customer', $customer_id, null, null, array( 'requested_action'=>$action, 'required_capability'=>$required, 'category'=>'security' ), 'denied', 'warning' );
            wp_die( esc_html__( 'You are not allowed to perform this member administration action.', 'evoxup-membership' ) );
        }
        check_admin_referer( 'evomembers_member_admin_action', 'evomembers_member_nonce' );

        $tab = sanitize_key( wp_unslash( (string) ( $_POST['return_tab'] ?? 'overview' ) ) );
        $customers = $this->context->customers();

        if ( 'adopt_wp_user' === $action ) {
            $wp_user_id = absint( wp_unslash( $_POST['wp_user_id'] ?? 0 ) );
            $result     = ( new CustomerService() )->create_or_link_from_wp_user( $wp_user_id );

            if ( is_wp_error( $result ) ) {
                $this->redirect_wordpress_user( $wp_user_id, $result->get_error_message(), true );
            }
            if ( (int) $result < 1 ) {
                $this->redirect_wordpress_user( $wp_user_id, __( 'The WordPress account could not be added as an EVO member.', 'evoxup-membership' ), true );
            }

            $this->redirect( (int) $result, 'overview', __( 'WordPress account added and linked as an EVO member.', 'evoxup-membership' ) );
        }

        if ( $customer_id < 1 ) { $this->redirect( 0, 'overview', __( 'A valid member is required.', 'evoxup-membership' ), true ); }

        if ( 'save_profile' === $action ) {
            $result = ( new CustomerService() )->update_profile(
                $customer_id,
                array(
                    'email' => sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ),
                    'secondary_email' => sanitize_email( wp_unslash( (string) ( $_POST['secondary_email'] ?? '' ) ) ),
                    'first_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['first_name'] ?? '' ) ) ),
                    'middle_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['middle_name'] ?? '' ) ) ),
                    'last_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['last_name'] ?? '' ) ) ),
                    'display_name' => sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) ),
                    'phone' => sanitize_text_field( wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) ),
                    'phone_country_code' => sanitize_text_field( wp_unslash( (string) ( $_POST['phone_country_code'] ?? '' ) ) ),
                    'company' => sanitize_text_field( wp_unslash( (string) ( $_POST['company'] ?? '' ) ) ),
                    'address_1' => sanitize_text_field( wp_unslash( (string) ( $_POST['address_1'] ?? '' ) ) ),
                    'address_2' => sanitize_text_field( wp_unslash( (string) ( $_POST['address_2'] ?? '' ) ) ),
                    'city' => sanitize_text_field( wp_unslash( (string) ( $_POST['city'] ?? '' ) ) ),
                    'state_region' => sanitize_text_field( wp_unslash( (string) ( $_POST['state_region'] ?? '' ) ) ),
                    'postal_code' => sanitize_text_field( wp_unslash( (string) ( $_POST['postal_code'] ?? '' ) ) ),
                    'country_code' => sanitize_text_field( wp_unslash( (string) ( $_POST['country_code'] ?? '' ) ) ),
                    'locale' => sanitize_text_field( wp_unslash( (string) ( $_POST['locale'] ?? '' ) ) ),
                    'timezone' => sanitize_text_field( wp_unslash( (string) ( $_POST['timezone'] ?? '' ) ) ),
                    'tax_id' => sanitize_text_field( wp_unslash( (string) ( $_POST['tax_id'] ?? '' ) ) ),
                    'vat_number' => sanitize_text_field( wp_unslash( (string) ( $_POST['vat_number'] ?? '' ) ) ),
                ),
                '1' === sanitize_text_field( wp_unslash( (string) ( $_POST['sync_wp_user'] ?? '' ) ) )
            );
            $this->redirect_result( $customer_id, 'overview', $result, __( 'Member profile updated.', 'evoxup-membership' ) );
        }

        if ( 'ensure_wp_user' === $action ) {
            $result = $customers->ensure_wp_user( $customer_id );
            $this->redirect_result( $customer_id, 'overview', $result, __( 'WordPress account linked.', 'evoxup-membership' ) );
        }

        if ( 'send_access' === $action ) {
            $result = ( new CustomerService() )->send_account_access( $customer_id );
            $this->redirect_result( $customer_id, 'overview', $result, __( 'Member access message queued/sent.', 'evoxup-membership' ) );
        }

        if ( 'grant_membership' === $action ) {
            $result = $this->context->memberships()->grant(
                $customer_id,
                absint( wp_unslash( $_POST['plan_id'] ?? 0 ) ),
                array(
                    'source' => 'member_admin',
                    'external_reference' => 'member-admin:' . wp_generate_uuid4(),
                    'duration_days' => absint( wp_unslash( $_POST['duration_days'] ?? 0 ) ),
                    'auto_renew' => '1' === sanitize_text_field( wp_unslash( (string) ( $_POST['auto_renew'] ?? '' ) ) ),
                    'notes' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) ),
                )
            );
            $this->redirect_result( $customer_id, 'memberships', $result, __( 'Membership granted.', 'evoxup-membership' ) );
        }

        if ( 'membership_status' === $action ) {
            $ok = $this->context->memberships()->set_status( absint( wp_unslash( $_POST['membership_id'] ?? 0 ) ), sanitize_key( wp_unslash( (string) ( $_POST['membership_status'] ?? '' ) ) ) );
            $this->redirect( $customer_id, 'memberships', $ok ? __( 'Membership status updated.', 'evoxup-membership' ) : __( 'Membership status could not be updated.', 'evoxup-membership' ), ! $ok );
        }

        if ( 'renew_membership' === $action ) {
            $days = absint( wp_unslash( $_POST['duration_days'] ?? 0 ) );
            $result = $this->context->memberships()->renew( absint( wp_unslash( $_POST['membership_id'] ?? 0 ) ), $days > 0 ? $days : null );
            $this->redirect_result( $customer_id, 'memberships', $result, __( 'Membership renewed and existing licenses extended.', 'evoxup-membership' ) );
        }

        if ( 'issue_license' === $action ) {
            $count = min( 100, max( 1, absint( wp_unslash( $_POST['license_count'] ?? 1 ) ) ) );
            $result = $this->context->licenses()->issue_many(
                array(
                    'customer_id' => $customer_id,
                    'product_id' => absint( wp_unslash( $_POST['product_id'] ?? 0 ) ),
                    'membership_id' => absint( wp_unslash( $_POST['membership_id'] ?? 0 ) ),
                    'duration_days' => absint( wp_unslash( $_POST['duration_days'] ?? 0 ) ),
                    'source' => 'member_admin',
                ),
                $count
            );
            $this->redirect_result( $customer_id, 'licenses', $result, __( 'License key(s) issued.', 'evoxup-membership' ) );
        }

        if ( 'license_status' === $action ) {
            $ok = $this->context->licenses()->set_status( absint( wp_unslash( $_POST['license_id'] ?? 0 ) ), sanitize_key( wp_unslash( (string) ( $_POST['license_status'] ?? '' ) ) ) );
            $this->redirect( $customer_id, 'licenses', $ok ? __( 'License status updated.', 'evoxup-membership' ) : __( 'License status could not be updated.', 'evoxup-membership' ), ! $ok );
        }

        if ( 'stop_activation' === $action ) {
            $ok = $this->context->licenses()->deactivate_activation_id( absint( wp_unslash( $_POST['activation_id'] ?? 0 ) ) );
            $this->redirect( $customer_id, 'licenses', $ok ? __( 'Activation stopped.', 'evoxup-membership' ) : __( 'Activation could not be stopped.', 'evoxup-membership' ), ! $ok );
        }

        if ( 'add_note' === $action ) {
            $note = trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ) );
            if ( '' === $note ) { $this->redirect( $customer_id, 'activity', __( 'Write a note before saving.', 'evoxup-membership' ), true ); }
            $result = $this->context->events()->emit( 'member.admin_note', array( 'note'=>$note ), 'customer', $customer_id, $customer_id );
            $this->redirect_result( $customer_id, 'activity', $result, __( 'Private administration note added.', 'evoxup-membership' ) );
        }

        $this->redirect( $customer_id, $tab, __( 'Unknown member administration action.', 'evoxup-membership' ), true );
    }

    private function render_list(): void {
        $customers = $this->context->customers()->all( 500 );
        $rows      = array();
        $by_wp_id  = array();
        $by_email  = array();

        foreach ( $customers as $customer ) {
            $index = count( $rows );
            $customer['_wp_user']     = false;
            $customer['_email_match'] = false;
            $rows[] = $customer;

            $customer_wp_user_id = absint( $customer['wp_user_id'] ?? 0 );
            if ( $customer_wp_user_id > 0 ) {
                $by_wp_id[ $customer_wp_user_id ] = $index;
            }

            $customer_email = strtolower( sanitize_email( (string) ( $customer['email'] ?? '' ) ) );
            if ( '' !== $customer_email ) {
                $by_email[ $customer_email ] = $index;
            }
        }

        // Customers is a unified local directory: every WordPress account is
        // visible even before it has an EVO customer record. This is read-only
        // discovery; an EVO record is created only after an administrator asks
        // to add/link that WordPress account.
        $wp_users = get_users(
            array(
                'number'  => 500,
                'orderby' => 'ID',
                'order'   => 'DESC',
                'fields'  => 'all',
            )
        );

        foreach ( $wp_users as $wp_user ) {
            if ( ! $wp_user instanceof \WP_User ) {
                continue;
            }

            $wp_user_id = (int) $wp_user->ID;
            $email      = strtolower( sanitize_email( (string) $wp_user->user_email ) );
            $index      = $by_wp_id[ $wp_user_id ] ?? ( '' !== $email ? ( $by_email[ $email ] ?? null ) : null );

            if ( null !== $index ) {
                $rows[ $index ]['_wp_user'] = $wp_user;
                if ( absint( $rows[ $index ]['wp_user_id'] ?? 0 ) < 1 ) {
                    $rows[ $index ]['_email_match'] = true;
                }
                continue;
            }

            $rows[] = array(
                'id'               => 0,
                'wp_user_id'       => $wp_user_id,
                'email'            => sanitize_email( (string) $wp_user->user_email ),
                'first_name'       => sanitize_text_field( (string) $wp_user->first_name ),
                'last_name'        => sanitize_text_field( (string) $wp_user->last_name ),
                'display_name'     => sanitize_text_field( (string) $wp_user->display_name ),
                'company'          => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_company', true ) ),
                'phone'            => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_phone', true ) ),
                'country_code'     => sanitize_text_field( (string) get_user_meta( $wp_user_id, 'billing_country', true ) ),
                'status'           => 'wordpress_only',
                'last_purchase_at' => null,
                '_wp_user'         => $wp_user,
                '_email_match'     => false,
            );
        }

        $query  = sanitize_text_field( (string) filter_input( INPUT_GET, 's', FILTER_UNSAFE_RAW ) );
        $status = sanitize_key( (string) filter_input( INPUT_GET, 'status', FILTER_UNSAFE_RAW ) );

        if ( '' !== $query ) {
            $needle = strtolower( $query );
            $rows = array_values(
                array_filter(
                    $rows,
                    static function ( array $row ) use ( $needle ): bool {
                        $wp_user = $row['_wp_user'] ?? false;
                        $haystack = array(
                            $row['id'] ?? '',
                            $row['wp_user_id'] ?? '',
                            $row['email'] ?? '',
                            $row['first_name'] ?? '',
                            $row['last_name'] ?? '',
                            $row['display_name'] ?? '',
                            $row['company'] ?? '',
                            $row['phone'] ?? '',
                        );
                        if ( $wp_user instanceof \WP_User ) {
                            $haystack[] = $wp_user->user_login;
                            $haystack[] = implode( ' ', (array) $wp_user->roles );
                        }
                        return false !== strpos( strtolower( implode( ' ', array_filter( array_map( 'strval', $haystack ) ) ) ), $needle );
                    }
                )
            );
        }

        if ( '' !== $status ) {
            $rows = array_values(
                array_filter(
                    $rows,
                    static fn( array $row ): bool => $status === sanitize_key( (string) ( $row['status'] ?? '' ) )
                )
            );
        }

        $this->head( __( 'Member Administration', 'evoxup-membership' ), __( 'WordPress users and EVO members in one local workspace. WordPress accounts remain native until you explicitly add or link them to EVO.', 'evoxup-membership' ) );
        echo '<form method="get" class="evox-member-filter"><input type="hidden" name="page" value="evomembers-customers"><input type="search" name="s" value="' . esc_attr( $query ) . '" placeholder="Search name, email, username, role, phone or ID"><select name="status"><option value="">All statuses</option>';
        foreach ( array( 'wordpress_only'=>'WordPress only','active'=>'Active','inactive'=>'Inactive','frozen'=>'Frozen','archived'=>'Archived' ) as $key=>$label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select><button class="button">Filter</button><a class="button" href="' . esc_url( admin_url( 'admin.php?page=evomembers-customers' ) ) . '">Reset</a></form>';

        echo '<div class="evox-member-list"><table class="widefat striped"><thead><tr><th>Member</th><th>Identity</th><th>Store</th><th>Status</th><th>Last purchase</th><th></th></tr></thead><tbody>';
        if ( ! $rows ) { echo '<tr><td colspan="6">No WordPress users or EVO members match the current filter.</td></tr>'; }

        foreach ( $rows as $row ) {
            $id          = absint( $row['id'] ?? 0 );
            $wp_user_id  = absint( $row['wp_user_id'] ?? 0 );
            $user        = $row['_wp_user'] ?? false;
            $email_match = ! empty( $row['_email_match'] );

            if ( ! $user && $wp_user_id > 0 ) {
                $user = get_userdata( $wp_user_id );
            }

            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( '' === $name ) {
                $name = (string) ( $row['display_name'] ?? '' );
            }
            if ( '' === $name && $user instanceof \WP_User ) {
                $name = (string) $user->display_name;
            }
            if ( '' === $name ) {
                $name = $id > 0 ? 'Member #' . $id : 'WordPress user #' . $wp_user_id;
            }

            $url_args = array( 'page'=>'evomembers-customers' );
            if ( $id > 0 ) {
                $url_args['customer_id'] = $id;
            } else {
                $url_args['wp_user_id'] = $wp_user_id;
            }
            $url = add_query_arg( $url_args, admin_url( 'admin.php' ) );

            $identity = $id > 0 ? 'EVO #' . $id : 'No EVO record';
            $wp_line  = 'No WordPress account';
            if ( $user instanceof \WP_User ) {
                $wp_line = 'WP #' . (int) $user->ID . ' · ' . implode( ', ', (array) $user->roles );
                if ( $email_match ) {
                    $wp_line .= ' · email match, not linked';
                }
            }

            echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></strong><br><small>' . esc_html( (string) ( $row['email'] ?? '' ) ) . '</small></td>';
            echo '<td><code>' . esc_html( $identity ) . '</code><br><small>' . esc_html( $wp_line ) . '</small></td>';
            echo '<td>' . esc_html( (string) ( ( $row['company'] ?? '' ) ?: '—' ) ) . '<br><small>' . esc_html( trim( (string) ( $row['country_code'] ?? '' ) . ' ' . (string) ( $row['phone'] ?? '' ) ) ?: '—' ) . '</small></td>';
            echo '<td>' . wp_kses_post( $this->badge( (string) ( $row['status'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( ( $row['last_purchase_at'] ?? '' ) ?: '—' ) ) . '</td><td><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( $id > 0 ? 'Open member' : 'Open WordPress user' ) . '</a></td></tr>';
        }

        echo '</tbody></table></div>';
        $this->end();
    }

    private function render_wordpress_user( int $wp_user_id ): void {
        $user = get_userdata( $wp_user_id );
        if ( ! $user ) {
            $this->head( __( 'WordPress user not found', 'evoxup-membership' ) );
            echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=evomembers-customers' ) ) . '">Back to members</a>';
            $this->end();
            return;
        }

        $customers = new CustomerService();
        $customer = $customers->find_by_wp_user( $wp_user_id );
        if ( ! $customer && is_email( (string) $user->user_email ) ) {
            $customer = $customers->find_by_email( (string) $user->user_email );
        }

        if ( $customer ) {
            $this->render_editor( (int) $customer['id'] );
            return;
        }

        $this->head( (string) $user->display_name, __( 'This is a native WordPress account. Add it to EVO only when you want to manage memberships, licenses or entitlements for it.', 'evoxup-membership' ) );

        $notice = sanitize_text_field( (string) filter_input( INPUT_GET, 'evomembers_member_notice', FILTER_UNSAFE_RAW ) );
        $error  = 1 === absint( filter_input( INPUT_GET, 'evomembers_member_error', FILTER_SANITIZE_NUMBER_INT ) );
        if ( '' !== $notice ) {
            echo '<div class="evox-member-result ' . ( $error ? 'is-error' : 'is-success' ) . '"><strong>' . esc_html( $error ? 'Action failed' : 'Saved' ) . '</strong><span>' . esc_html( $notice ) . '</span></div>';
        }

        echo '<div class="evox-member-commandbar"><a class="evox-member-back" href="' . esc_url( admin_url( 'admin.php?page=evomembers-customers' ) ) . '"><span class="dashicons dashicons-arrow-left-alt2"></span>All members</a>';
        echo '<div class="evox-member-identity"><span class="evox-member-avatar">' . esc_html( strtoupper( substr( (string) $user->display_name, 0, 1 ) ) ) . '</span><div><strong>' . esc_html( (string) $user->display_name ) . '</strong><small>' . esc_html( (string) $user->user_email ) . '</small></div></div>';
        echo '<div class="evox-member-command-status">' . wp_kses_post( $this->badge( 'wordpress_only' ) ) . '</div></div>';

        echo '<div class="evox-member-columns"><section class="evox-member-panel"><h2>WordPress identity</h2><dl class="evox-member-details">';
        echo '<div><dt>WordPress ID</dt><dd>#' . esc_html( (string) $user->ID ) . '</dd></div>';
        echo '<div><dt>Username</dt><dd>' . esc_html( (string) $user->user_login ) . '</dd></div>';
        echo '<div><dt>Email</dt><dd>' . esc_html( (string) $user->user_email ) . '</dd></div>';
        echo '<div><dt>Roles</dt><dd>' . esc_html( implode( ', ', (array) $user->roles ) ?: '—' ) . '</dd></div>';
        echo '<div><dt>Registered</dt><dd>' . esc_html( (string) $user->user_registered ) . '</dd></div>';
        echo '</dl></section><aside class="evox-member-panel"><h2>EVO membership record</h2><p>No EVO customer record exists for this WordPress account yet.</p>';
        echo '<p>Adding it creates only the local EVO customer identity and links it to this existing WordPress user. It does not grant a Plan, license or entitlement automatically.</p>';
        echo '<form method="post">';
        $this->nonce( 'adopt_wp_user', 0, 'overview' );
        echo '<input type="hidden" name="wp_user_id" value="' . esc_attr( (string) $wp_user_id ) . '">';
        echo '<button class="button button-primary button-hero">Add as EVO member</button></form>';
        if ( current_user_can( 'edit_user', $wp_user_id ) ) {
            echo '<p><a class="button" href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $wp_user_id ) ) . '">Open WordPress profile</a></p>';
        }
        echo '</aside></div>';

        $this->end();
    }

    private function render_editor( int $customer_id ): void {
        $customer = $this->context->customers()->get( $customer_id );
        if ( ! $customer ) { $this->head( __( 'Member not found', 'evoxup-membership' ) ); echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=evomembers-customers' ) ) . '">Back to members</a>'; $this->end(); return; }

        $tab = sanitize_key( (string) filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW ) ) ?: 'overview';
        $tabs = array( 'overview' => 'Overview' );
        if ( current_user_can( Capabilities::MEMBERSHIPS ) ) { $tabs['memberships'] = 'Memberships'; }
        if ( current_user_can( Capabilities::LICENSES ) ) { $tabs['licenses'] = 'Licenses & Activations'; }
        if ( current_user_can( Capabilities::ORDERS ) ) { $tabs['orders'] = 'Orders'; }
        $tabs['entitlements'] = 'Entitlements';
        $tabs['activity'] = 'Activity & Notes';
        if ( ! isset( $tabs[ $tab ] ) ) { $tab = 'overview'; }

        $memberships = current_user_can( Capabilities::MEMBERSHIPS ) ? $this->context->memberships()->for_customer( $customer_id ) : array();
        $licenses = current_user_can( Capabilities::LICENSES ) ? $this->context->licenses()->for_customer( $customer_id, false, 300 ) : array();
        $orders = current_user_can( Capabilities::ORDERS ) ? $this->context->orders()->for_customer( $customer_id, 200 ) : array();
        $entitled_product_ids = $this->context->entitlements()->product_ids_for_customer( $customer_id );
        $events = $this->context->events()->for_customer( $customer_id, 100 );
        $activations = current_user_can( Capabilities::LICENSES ) ? ( new LicenseService() )->activations_for_customer( $customer_id, 200 ) : array();
        $wp_user_id = absint( $customer['wp_user_id'] ?? 0 );
        $wp_user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        $name = trim( (string) ( $customer['first_name'] ?? '' ) . ' ' . (string) ( $customer['last_name'] ?? '' ) );

        $this->head( $name ?: (string) ( $customer['display_name'] ?? 'Member #' . $customer_id ), __( 'Unified member operations. WordPress remains the account identity; EVO remains the membership, licensing and entitlement identity.', 'evoxup-membership' ) );
        $notice = sanitize_text_field( (string) filter_input( INPUT_GET, 'evomembers_member_notice', FILTER_UNSAFE_RAW ) );
        $error = 1 === absint( filter_input( INPUT_GET, 'evomembers_member_error', FILTER_SANITIZE_NUMBER_INT ) );
        if ( '' !== $notice ) { echo '<div class="evox-member-result ' . ( $error ? 'is-error' : 'is-success' ) . '"><strong>' . esc_html( $error ? 'Action failed' : 'Saved' ) . '</strong><span>' . esc_html( $notice ) . '</span></div>'; }

        echo '<div class="evox-member-commandbar"><a class="evox-member-back" href="' . esc_url( admin_url( 'admin.php?page=evomembers-customers' ) ) . '"><span class="dashicons dashicons-arrow-left-alt2"></span>All members</a><div class="evox-member-identity"><span class="evox-member-avatar">' . esc_html( strtoupper( substr( $name ?: (string) ( $customer['display_name'] ?? 'M' ), 0, 1 ) ) ) . '</span><div><strong>' . esc_html( $name ?: (string) ( $customer['display_name'] ?? 'Member #' . $customer_id ) ) . '</strong><small>' . esc_html( (string) ( $customer['email'] ?? '' ) ) . '</small></div></div><div class="evox-member-command-status">' . wp_kses_post( $this->badge( (string) ( $customer['status'] ?? '' ) ) ) . '</div></div>';
        echo '<div class="evox-member-metrics"><article><span class="dashicons dashicons-id"></span><div><small>EVO customer</small><strong>#' . esc_html( (string) $customer_id ) . '</strong></div></article><article><span class="dashicons dashicons-wordpress"></span><div><small>WordPress account</small><strong>' . esc_html( $wp_user ? '#' . $wp_user_id : 'Not linked' ) . '</strong></div></article><article><span class="dashicons dashicons-groups"></span><div><small>Memberships</small><strong>' . esc_html( (string) count( $memberships ) ) . '</strong></div></article><article><span class="dashicons dashicons-admin-network"></span><div><small>Licenses</small><strong>' . esc_html( (string) count( $licenses ) ) . '</strong></div></article><article><span class="dashicons dashicons-cart"></span><div><small>Orders</small><strong>' . esc_html( (string) count( $orders ) ) . '</strong></div></article><article><span class="dashicons dashicons-yes-alt"></span><div><small>Entitlements</small><strong>' . esc_html( (string) count( $entitled_product_ids ) ) . '</strong></div></article></div>';
        echo '<nav class="evox-member-tabs">';
        foreach ( $tabs as $key=>$label ) { $url = add_query_arg( array( 'page'=>'evomembers-customers','customer_id'=>$customer_id,'tab'=>$key ), admin_url( 'admin.php' ) ); echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'; }
        echo '</nav>';

        switch ( $tab ) {
            case 'memberships':
                $this->memberships_tab( $customer_id, $memberships );
                break;
            case 'licenses':
                $this->licenses_tab( $customer_id, $memberships, $licenses, $activations );
                break;
            case 'orders':
                $this->orders_tab( $customer_id, $orders );
                break;
            case 'entitlements':
                $this->entitlements_tab( $entitled_product_ids );
                break;
            case 'activity':
                $this->activity_tab( $customer_id, $events );
                break;
            default:
                $this->overview_tab( $customer_id, $customer, $wp_user );
        }
        $this->end();
    }

    private function overview_tab( int $customer_id, array $customer, mixed $wp_user ): void {
        echo '<div class="evox-member-columns"><section class="evox-member-panel"><h2>Member identity</h2>';
        if ( current_user_can( Capabilities::CUSTOMERS ) ) {
            echo '<form method="post" class="evox-member-form">'; $this->nonce( 'save_profile', $customer_id, 'overview' );
            $this->input( 'first_name', 'First name', (string) ( $customer['first_name'] ?? '' ) );
            $this->input( 'middle_name', 'Middle name', (string) ( $customer['middle_name'] ?? '' ) );
            $this->input( 'last_name', 'Last name', (string) ( $customer['last_name'] ?? '' ) );
            $this->input( 'display_name', 'Display name', (string) ( $customer['display_name'] ?? '' ) );
            $this->input( 'email', 'Email', (string) ( $customer['email'] ?? '' ), 'email', true );
            $this->input( 'secondary_email', 'Secondary email', (string) ( $customer['secondary_email'] ?? '' ), 'email' );
            $this->input( 'phone', 'Phone', (string) ( $customer['phone'] ?? '' ) );
            $this->input( 'phone_country_code', 'Phone country code', (string) ( $customer['phone_country_code'] ?? '' ) );
            $this->input( 'company', 'Company', (string) ( $customer['company'] ?? '' ) );
            $this->input( 'address_1', 'Address 1', (string) ( $customer['address_1'] ?? '' ) );
            $this->input( 'address_2', 'Address 2', (string) ( $customer['address_2'] ?? '' ) );
            $this->input( 'city', 'City', (string) ( $customer['city'] ?? '' ) );
            $this->input( 'state_region', 'State / region', (string) ( $customer['state_region'] ?? '' ) );
            $this->input( 'postal_code', 'Postal code', (string) ( $customer['postal_code'] ?? '' ) );
            $this->input( 'country_code', 'Country code', (string) ( $customer['country_code'] ?? '' ) );
            $this->input( 'locale', 'Locale', (string) ( $customer['locale'] ?? '' ) );
            $this->input( 'timezone', 'Timezone', (string) ( $customer['timezone'] ?? '' ) );
            $this->input( 'tax_id', 'Tax ID', (string) ( $customer['tax_id'] ?? '' ) );
            $this->input( 'vat_number', 'VAT number', (string) ( $customer['vat_number'] ?? '' ) );
            if ( $wp_user && current_user_can( 'edit_user', (int) $wp_user->ID ) ) { echo '<label class="evox-member-wide"><input type="checkbox" name="sync_wp_user" value="1" checked> Synchronize shared identity/contact fields to the linked WordPress/WooCommerce account</label>'; }
            echo '<div class="evox-member-actions"><button class="button button-primary button-hero">Save member</button></div></form>';
        }
        echo '</section><aside class="evox-member-panel"><h2>Account operations</h2><dl class="evox-member-details"><div><dt>Registered</dt><dd>' . esc_html( (string) ( $customer['registered_at'] ?: '—' ) ) . '</dd></div><div><dt>First purchase</dt><dd>' . esc_html( (string) ( $customer['first_purchase_at'] ?: '—' ) ) . '</dd></div><div><dt>Last purchase</dt><dd>' . esc_html( (string) ( $customer['last_purchase_at'] ?: '—' ) ) . '</dd></div></dl>';
        if ( current_user_can( Capabilities::CUSTOMERS ) ) {
            if ( ! $wp_user ) { echo '<form method="post">'; $this->nonce( 'ensure_wp_user', $customer_id, 'overview' ); echo '<button class="button button-primary">Link existing WordPress account</button></form>'; }
            elseif ( current_user_can( 'edit_user', (int) $wp_user->ID ) ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'user-edit.php?user_id=' . (int) $wp_user->ID ) ) . '">Open WordPress profile</a></p>'; }
            echo '<form method="post">'; $this->nonce( 'send_access', $customer_id, 'overview' ); echo '<button class="button">Send account access</button></form>';
        }
        if ( $wp_user && current_user_can( Capabilities::ACCESS ) ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=evomembers-access&user_id=' . (int) $wp_user->ID ) ) . '">Access & Roles</a></p>'; }
        echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( ( new PurchaseLinkService() )->membership_center_url() ) . '">Open Membership Center</a></p></aside></div>';
    }

    private function memberships_tab( int $customer_id, array $memberships ): void {
        echo '<div class="evox-member-columns"><section class="evox-member-panel evox-member-grow"><h2>Memberships</h2><table class="widefat striped"><thead><tr><th>Plan</th><th>Status</th><th>Period</th><th>Renewals</th><th>Actions</th></tr></thead><tbody>';
        if ( ! $memberships ) { echo '<tr><td colspan="5">No memberships.</td></tr>'; }
        foreach ( $memberships as $m ) {
            echo '<tr><td><strong>' . esc_html( (string) ( $m['plan_name'] ?? '#' . $m['plan_id'] ) ) . '</strong><br><small>' . esc_html( strtoupper( (string) ( $m['tier'] ?? 'custom' ) ) ) . ' · #' . esc_html( (string) $m['id'] ) . '</small></td><td>' . wp_kses_post( $this->badge( (string) $m['status'] ) ) . '</td><td>' . esc_html( (string) ( $m['starts_at'] ?? '—' ) ) . '<br><small>to ' . esc_html( (string) ( $m['expires_at'] ?: 'Lifetime' ) ) . '</small></td><td>' . esc_html( (string) ( $m['renewal_count'] ?? 0 ) ) . '</td><td>';
            if ( current_user_can( Capabilities::MEMBERSHIPS ) ) {
                echo '<form method="post" class="evox-inline">'; $this->nonce( 'membership_status', $customer_id, 'memberships' ); echo '<input type="hidden" name="membership_id" value="' . esc_attr( (string) $m['id'] ) . '"><select name="membership_status">';
                foreach ( array( 'active','pending','past_due','suspended','expired','cancelled','refunded' ) as $s ) { echo '<option value="' . esc_attr( $s ) . '" ' . selected( (string) $m['status'], $s, false ) . '>' . esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ) . '</option>'; }
                echo '</select><button class="button button-small">Apply</button></form><form method="post" class="evox-inline">'; $this->nonce( 'renew_membership', $customer_id, 'memberships' ); echo '<input type="hidden" name="membership_id" value="' . esc_attr( (string) $m['id'] ) . '"><input class="small-text" type="number" min="0" name="duration_days" placeholder="Plan"><button class="button button-small">Renew</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></section>';
        if ( current_user_can( Capabilities::MEMBERSHIPS ) ) {
            echo '<aside class="evox-member-panel"><h2>Grant membership</h2><form method="post" class="evox-member-stack">'; $this->nonce( 'grant_membership', $customer_id, 'memberships' ); echo '<label>Plan<select name="plan_id" required><option value="">Select plan</option>';
            foreach ( $this->context->plans()->all( true ) as $plan ) { echo '<option value="' . esc_attr( (string) $plan['id'] ) . '">' . esc_html( (string) $plan['name'] . ' · ' . strtoupper( (string) $plan['tier'] ) ) . '</option>'; }
            echo '</select></label><label>Custom duration days <input type="number" min="0" name="duration_days" placeholder="Use plan default"></label><label><input type="checkbox" name="auto_renew" value="1"> Auto renew</label><label>Administrative note<textarea name="notes" rows="4"></textarea></label><button class="button button-primary">Grant membership</button></form></aside>';
        }
        echo '</div>';
    }

    private function licenses_tab( int $customer_id, array $memberships, array $licenses, array $activations ): void {
        echo '<div class="evox-member-panel"><h2>Licenses</h2><table class="widefat striped"><thead><tr><th>License</th><th>Product</th><th>Status</th><th>Expires</th><th>Actions</th></tr></thead><tbody>';
        if ( ! $licenses ) { echo '<tr><td colspan="5">No licenses.</td></tr>'; }
        foreach ( $licenses as $l ) { echo '<tr><td><strong>#' . esc_html( (string) $l['id'] ) . '</strong><br><small>•••• ' . esc_html( (string) ( $l['license_last4'] ?? '' ) ) . '</small></td><td>' . esc_html( (string) ( $l['product_name'] ?: 'EVO product #' . (string) ( $l['product_id'] ?? '—' ) ) ) . '</td><td>' . wp_kses_post( $this->badge( (string) $l['status'] ) ) . '<br><small>' . esc_html( (string) ( $l['verification_status'] ?? 'unverified' ) ) . '</small></td><td>' . esc_html( (string) ( $l['expires_at'] ?: 'Lifetime' ) ) . '</td><td>';
            if ( current_user_can( Capabilities::LICENSES_REVOKE ) ) { echo '<form method="post" class="evox-inline">'; $this->nonce( 'license_status', $customer_id, 'licenses' ); echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $l['id'] ) . '"><select name="license_status">'; foreach ( array( 'active','inactive','disabled','frozen','expired','revoked','pending' ) as $s ) { echo '<option value="' . esc_attr( $s ) . '" ' . selected( (string) $l['status'], $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; } echo '</select><button class="button button-small">Apply</button></form>'; }
            echo '</td></tr>'; }
        echo '</tbody></table></div>';

        echo '<div class="evox-member-columns"><section class="evox-member-panel evox-member-grow"><h2>Activations</h2><table class="widefat striped"><thead><tr><th>License</th><th>Site</th><th>Status</th><th>Activated</th><th></th></tr></thead><tbody>';
        if ( ! $activations ) { echo '<tr><td colspan="5">No activations.</td></tr>'; }
        foreach ( $activations as $a ) { echo '<tr><td>#' . esc_html( (string) $a['license_id'] ) . '</td><td>' . esc_html( (string) ( $a['site_url'] ?? '—' ) ) . '</td><td>' . wp_kses_post( $this->badge( (string) ( $a['status'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $a['activated_at'] ?? '—' ) ) . '</td><td>'; if ( current_user_can( Capabilities::ACTIVATIONS ) && 'active' === (string) ( $a['status'] ?? '' ) ) { echo '<form method="post">'; $this->nonce( 'stop_activation', $customer_id, 'licenses' ); echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $a['id'] ) . '"><button class="button button-small">Stop</button></form>'; } echo '</td></tr>'; }
        echo '</tbody></table></section>';
        if ( current_user_can( Capabilities::LICENSES_ISSUE ) ) {
            echo '<aside class="evox-member-panel"><h2>Issue license</h2><form method="post" class="evox-member-stack">'; $this->nonce( 'issue_license', $customer_id, 'licenses' ); echo '<label>Product<select name="product_id" required><option value="">Select product</option>';
            foreach ( $this->context->products()->all( 500, false, 'all', 0 ) as $p ) { if ( 'active' !== (string) ( $p['status'] ?? '' ) ) { continue; } echo '<option value="' . esc_attr( (string) $p['id'] ) . '">' . esc_html( (string) $p['name'] . ' · ' . (string) $p['code'] ) . '</option>'; }
            echo '</select></label><label>Membership<select name="membership_id"><option value="0">No membership link</option>'; foreach ( $memberships as $m ) { echo '<option value="' . esc_attr( (string) $m['id'] ) . '">' . esc_html( (string) ( $m['plan_name'] ?? '#' . $m['plan_id'] ) ) . '</option>'; } echo '</select></label><label>Keys / seats<input type="number" name="license_count" min="1" max="100" value="1"></label><label>Duration days<input type="number" name="duration_days" min="0" placeholder="Use product default"></label><button class="button button-primary">Issue license</button></form></aside>';
        }
        echo '</div>';
    }

    private function orders_tab( int $customer_id, array $orders ): void {
        echo '<div class="evox-member-panel"><h2>Orders</h2><p>Orders are shown here as operational context. Commerce-authoritative changes remain with the originating provider, so WooCommerce orders are edited in WooCommerce instead of mutating only the EVO ledger copy.</p><table class="widefat striped"><thead><tr><th>EVO Order</th><th>Provider reference</th><th>Status</th><th>Total</th><th>Purchased</th><th>Authority</th></tr></thead><tbody>';
        if ( ! $orders ) { echo '<tr><td colspan="6">No orders.</td></tr>'; }
        foreach ( $orders as $o ) {
            $wc_order_id = absint( $o['woocommerce_order_id'] ?? 0 );
            $external_ref = (string) ( $o['external_order_id'] ?? $o['external_transaction_id'] ?? '' );
            echo '<tr><td><strong>#' . esc_html( (string) $o['id'] ) . '</strong><br><small>' . esc_html( (string) ( $o['item_count'] ?? 0 ) ) . ' item(s)</small></td><td>' . esc_html( $wc_order_id > 0 ? 'WooCommerce #' . $wc_order_id : ( $external_ref ?: '—' ) ) . '</td><td>' . wp_kses_post( $this->badge( (string) ( $o['status'] ?? '' ) ) ) . '</td><td>' . esc_html( trim( (string) ( $o['currency'] ?? '' ) . ' ' . (string) ( $o['total'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $o['purchased_at'] ?? $o['created_at'] ?? '—' ) ) . '</td><td>';
            if ( $wc_order_id > 0 && current_user_can( 'edit_shop_orders' ) ) {
                $wc_url = admin_url( 'post.php?post=' . $wc_order_id . '&action=edit' );
                if ( function_exists( 'wc_get_order' ) ) {
                    $wc_order = wc_get_order( $wc_order_id );
                    if ( $wc_order && method_exists( $wc_order, 'get_edit_order_url' ) ) { $wc_url = $wc_order->get_edit_order_url(); }
                }
                echo '<a class="button button-small" href="' . esc_url( $wc_url ) . '">Open WooCommerce order</a>';
            } else {
                echo '<span class="evox-member-badge">Provider / ledger</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function entitlements_tab( array $product_ids ): void {
        echo '<div class="evox-member-panel"><h2>Effective product entitlements</h2><p>Resolved from active memberships, active licenses and recorded order ownership. This is read-only here so entitlement sources remain authoritative.</p><div class="evox-entitlement-grid">';
        if ( ! $product_ids ) { echo '<div class="evox-empty">No effective product entitlements.</div>'; }
        foreach ( $product_ids as $product_id ) { $p = $this->context->products()->get( (int) $product_id ); if ( ! $p ) { continue; } echo '<article><strong>' . esc_html( (string) $p['name'] ) . '</strong><code>' . esc_html( (string) $p['code'] ) . '</code><span>' . esc_html( strtoupper( (string) $p['source'] ) ) . '</span></article>'; }
        echo '</div></div>';
    }

    private function activity_tab( int $customer_id, array $events ): void {
        echo '<div class="evox-member-columns"><section class="evox-member-panel evox-member-grow"><h2>Member activity timeline</h2><div class="evox-timeline">';
        if ( ! $events ) { echo '<div class="evox-empty">No member events recorded.</div>'; }
        foreach ( $events as $e ) { $payload = json_decode( (string) ( $e['payload_json'] ?? '' ), true ); $payload = is_array( $payload ) ? $payload : array(); $is_note = 'member.admin_note' === (string) ( $e['event_name'] ?? '' ); echo '<article class="' . ( $is_note ? 'is-note' : '' ) . '"><time>' . esc_html( (string) ( $e['created_at'] ?? '' ) ) . '</time><strong>' . esc_html( $is_note ? 'Private note' : (string) ( $e['event_name'] ?? 'event' ) ) . '</strong>'; if ( $is_note ) { echo '<p>' . nl2br( esc_html( (string) ( $payload['note'] ?? '' ) ) ) . '</p>'; } else { echo '<small>' . esc_html( (string) ( $e['source'] ?? '' ) ) . '</small>'; } echo '</article>'; }
        echo '</div></section>';
        if ( current_user_can( Capabilities::CUSTOMERS ) ) { echo '<aside class="evox-member-panel"><h2>Private admin note</h2><p>Stored in the EVO event timeline and not shown to the member.</p><form method="post" class="evox-member-stack">'; $this->nonce( 'add_note', $customer_id, 'activity' ); echo '<textarea name="note" rows="8" required placeholder="Internal context, follow-up, exception or support note"></textarea><button class="button button-primary">Add note</button></form></aside>'; }
        echo '</div>';
    }

    private function nonce( string $action, int $customer_id, string $tab ): void {
        wp_nonce_field( 'evomembers_member_admin_action', 'evomembers_member_nonce' );
        echo '<input type="hidden" name="evomembers_member_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="customer_id" value="' . esc_attr( (string) $customer_id ) . '"><input type="hidden" name="return_tab" value="' . esc_attr( $tab ) . '">';
    }

    private function input( string $name, string $label, string $value, string $type = 'text', bool $required = false ): void {
        echo '<label><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . '></label>';
    }

    private function badge( string $status ): string {
        $status = sanitize_key( $status ) ?: 'unknown';
        $label  = 'wordpress_only' === $status ? 'WordPress only' : ucfirst( str_replace( '_', ' ', $status ) );
        return '<span class="evox-member-badge is-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    private function head( string $title, string $description = '' ): void {
        echo '<div class="wrap evo-wrap evox-member-admin"><header class="evox-member-hero"><div class="evox-member-hero-copy"><span class="evox-member-kicker">EVOXUP MEMBER OPERATIONS</span><h1>' . esc_html( $title ) . '</h1>';
        if ( '' !== $description ) { echo '<p>' . esc_html( $description ) . '</p>'; }
        echo '</div><div class="evox-member-hero-mark"><span class="dashicons dashicons-groups"></span><div><strong>Member Administration</strong><small>Integrated workspace · v' . esc_html( EVOMEMBERS_VERSION ) . '</small></div></div></header>';
    }

    private function end(): void { echo '</div>'; }

    private function redirect_result( int $customer_id, string $tab, mixed $result, string $success ): never {
        if ( is_wp_error( $result ) ) { $this->redirect( $customer_id, $tab, $result->get_error_message(), true ); }
        if ( false === $result || 0 === $result ) { $this->redirect( $customer_id, $tab, __( 'The requested operation could not be completed.', 'evoxup-membership' ), true ); }
        $this->redirect( $customer_id, $tab, $success, false );
    }

    private function redirect_wordpress_user( int $wp_user_id, string $notice, bool $error = false ): never {
        $args = array(
            'page'                     => 'evomembers-customers',
            'wp_user_id'               => $wp_user_id,
            'evomembers_member_notice' => $notice,
        );
        if ( $error ) {
            $args['evomembers_member_error'] = 1;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function redirect( int $customer_id, string $tab, string $notice, bool $error = false ): never {
        $this->context->audit(
            'member_admin.action',
            'customer',
            $customer_id,
            null,
            array( 'tab'=>$tab ),
            array( 'notice'=>$notice, 'category'=>'member_administration' ),
            $error ? 'failure' : 'success',
            $error ? 'warning' : 'info'
        );
        $args = array( 'page'=>'evomembers-customers', 'customer_id'=>$customer_id, 'tab'=>sanitize_key( $tab ), 'evomembers_member_notice'=>$notice );
        if ( $error ) { $args['evomembers_member_error'] = 1; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
