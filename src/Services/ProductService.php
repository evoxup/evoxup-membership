<?php
namespace EvoMembers\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- EVO owns versioned evomembers_* operational tables and deliberately reads transactional state fresh.

use EvoMembers\Core\Capabilities;
use EvoMembers\Core\Database;

defined( 'ABSPATH' ) || exit;

final class ProductService {
    public const SOURCE_EVO = 'evo';
    public const SOURCE_WOOCOMMERCE = 'woocommerce';
    public const SOURCE_MEMBER = 'member';
    public const SOURCE_EXTERNAL = 'external';

    public function create( array $data ): int {
        global $wpdb;

        $source = $this->source( $data['source'] ?? self::SOURCE_EVO );
        $external_id = $this->nullable_text( $data['external_source_id'] ?? null, 190 );

        if ( self::SOURCE_WOOCOMMERCE === $source && null === $external_id ) {
            return 0;
        }

        $temporary_code = 'tmp-' . wp_generate_uuid4();
        $data['code']   = $temporary_code;
        $data['source'] = $source;

        $row = $this->normalize( $data, null );
        if ( null === $row ) {
            return 0;
        }

        $now               = current_time( 'mysql', true );
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        if ( false === $wpdb->insert( Database::table( 'products' ), $row ) ) {
            return 0;
        }

        $id   = (int) $wpdb->insert_id;
        $code = $this->build_code( $source, $id, $external_id );
        if ( false === $wpdb->update( Database::table( 'products' ), array( 'code' => $code ), array( 'id' => $id ) ) ) {
            $wpdb->delete( Database::table( 'products' ), array( 'id' => $id ), array( '%d' ) );
            return 0;
        }

        if ( isset( $data['plan_ids'] ) ) {
            $this->sync_plans( $id, (array) $data['plan_ids'] );
        }

        EventBus::emit(
            'product.created',
            array( 'product_id' => $id, 'code' => $code, 'source' => $source, 'status' => $row['status'] ),
            'product',
            $id,
            0,
            'evo'
        );

        return $id;
    }

    public function update( int $id, array $data ): bool {
        global $wpdb;

        $current = $this->get( $id );
        if ( ! $current ) {
            return false;
        }

        // Source identity is immutable after creation. Woo native ID is also
        // immutable; sync_woocommerce() is the authority that updates it.
        $data['source'] = $current['source'];
        if ( self::SOURCE_WOOCOMMERCE === (string) $current['source'] ) {
            $data['external_source_id'] = $current['external_source_id'];
        }
        $data['code'] = $current['code'];

        $row = $this->normalize( $data, $current );
        if ( null === $row ) {
            return false;
        }

        $row['updated_at'] = current_time( 'mysql', true );
        $result            = $wpdb->update( Database::table( 'products' ), $row, array( 'id' => $id ) );
        if ( false === $result ) {
            return false;
        }

        if ( isset( $data['plan_ids'] ) ) {
            $this->sync_plans( $id, (array) $data['plan_ids'] );
        }

        EventBus::emit(
            'product.updated',
            array( 'product_id' => $id, 'code' => $row['code'], 'source' => $row['source'], 'status' => $row['status'] ),
            'product',
            $id,
            0,
            'evo'
        );

        return true;
    }

    public function set_status( int $id, string $status ): bool {
        global $wpdb;

        $status = $this->status( $status );
        if ( $id < 1 ) {
            return false;
        }

        $ok = $wpdb->update(
            Database::table( 'products' ),
            array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id )
        );

        if ( false !== $ok ) {
            EventBus::emit( 'product.status_changed', array( 'product_id' => $id, 'status' => $status ), 'product', $id );
        }

        return false !== $ok;
    }

    public function delete( int $id, bool $force = false ): bool {
        global $wpdb;

        if ( $id < 1 || ! $this->get( $id ) ) {
            return false;
        }

        $licenses = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id=%d', Database::table( 'licenses' ), $id )
        );
        $memberships = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i pp INNER JOIN %i m ON m.plan_id=pp.plan_id WHERE pp.product_id=%d',
                Database::table( 'plan_products' ),
                Database::table( 'memberships' ),
                $id
            )
        );

        if ( ( $licenses > 0 || $memberships > 0 ) && ! $force ) {
            return $this->set_status( $id, 'archived' );
        }

        $wpdb->delete( Database::table( 'product_mappings' ), array( 'product_id' => $id ), array( '%d' ) );
        $wpdb->delete( Database::table( 'plan_products' ), array( 'product_id' => $id ), array( '%d' ) );
        $ok = $wpdb->delete( Database::table( 'products' ), array( 'id' => $id ), array( '%d' ) );

        if ( false !== $ok ) {
            EventBus::emit( 'product.deleted', array( 'product_id' => $id, 'forced' => $force ), 'product', $id );
        }

        return false !== $ok;
    }

    public function get( int $id ): ?array {
        global $wpdb;

        if ( $id < 1 ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', Database::table( 'products' ), $id ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['plans'] = ( new PlanService() )->plans_for_product( $id );
        return $row;
    }

    public function get_by_code( string $code ): ?array {
        global $wpdb;

        $code = sanitize_text_field( $code );
        if ( '' === $code ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE code=%s LIMIT 1', Database::table( 'products' ), $code ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function get_by_source( string $source, string $external_source_id ): ?array {
        global $wpdb;

        $source             = $this->source( $source );
        $external_source_id = sanitize_text_field( $external_source_id );
        if ( '' === $external_source_id ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE source=%s AND external_source_id=%s LIMIT 1',
                Database::table( 'products' ),
                $source,
                $external_source_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function all( int $limit = 300, bool $include_archived = false, string $source = 'all', int $offset = 0 ): array {
        global $wpdb;

        $limit        = min( 1000, max( 1, $limit ) );
        $offset       = max( 0, absint( $offset ) );
        $source       = sanitize_key( $source );
        $filter_source = 'all' !== $source && in_array( $source, self::sources(), true );
        $table        = Database::table( 'products' );

        if ( $filter_source && ! $include_archived ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,owner_user_id,code,name,product_type,excerpt,description,image_id,image_url,price,sale_price,currency,purchase_url,upgrade_url,download_url,requires_license,license_type_id,verification_mode,allowed_site_url,allowed_domain,allowed_ip,source,external_source_id,source_name,source_synced_at,fulfillment_mode,license_provider,status,license_seats,max_activations,license_duration_days,metadata_json,created_at,updated_at FROM %i WHERE source=%s AND status<>%s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    $source,
                    'archived',
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ( $filter_source ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,owner_user_id,code,name,product_type,excerpt,description,image_id,image_url,price,sale_price,currency,purchase_url,upgrade_url,download_url,requires_license,license_type_id,verification_mode,allowed_site_url,allowed_domain,allowed_ip,source,external_source_id,source_name,source_synced_at,fulfillment_mode,license_provider,status,license_seats,max_activations,license_duration_days,metadata_json,created_at,updated_at FROM %i WHERE source=%s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    $source,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } elseif ( ! $include_archived ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,owner_user_id,code,name,product_type,excerpt,description,image_id,image_url,price,sale_price,currency,purchase_url,upgrade_url,download_url,requires_license,license_type_id,verification_mode,allowed_site_url,allowed_domain,allowed_ip,source,external_source_id,source_name,source_synced_at,fulfillment_mode,license_provider,status,license_seats,max_activations,license_duration_days,metadata_json,created_at,updated_at FROM %i WHERE status<>%s ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    'archived',
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id,owner_user_id,code,name,product_type,excerpt,description,image_id,image_url,price,sale_price,currency,purchase_url,upgrade_url,download_url,requires_license,license_type_id,verification_mode,allowed_site_url,allowed_domain,allowed_ip,source,external_source_id,source_name,source_synced_at,fulfillment_mode,license_provider,status,license_seats,max_activations,license_duration_days,metadata_json,created_at,updated_at FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
                    $table,
                    $limit,
                    $offset
                ),
                ARRAY_A
            );
        }

        $rows = is_array( $rows ) ? $rows : array();
        return $this->attach_plan_summaries( $rows );
    }

    public function active(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,name,code,source,external_source_id,image_id,image_url,download_url,purchase_url,upgrade_url,price,sale_price,currency,requires_license,license_provider,license_seats,max_activations,license_duration_days FROM %i WHERE status=%s ORDER BY name',
                Database::table( 'products' ),
                'active'
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    public function count( string $source = 'all', bool $include_archived = false ): int {
        global $wpdb;

        $source        = sanitize_key( $source );
        $filter_source = 'all' !== $source && in_array( $source, self::sources(), true );
        $table         = Database::table( 'products' );

        if ( $filter_source && ! $include_archived ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE source=%s AND status<>%s',
                    $table,
                    $source,
                    'archived'
                )
            );
        }

        if ( $filter_source ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE source=%s',
                    $table,
                    $source
                )
            );
        }

        if ( ! $include_archived ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE status<>%s',
                    $table,
                    'archived'
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i',
                $table
            )
        );
    }

    /** Lightweight rows for plan/product selectors without loading descriptions. */
    public function linkable( int $limit = 2000 ): array {
        global $wpdb;
        $limit = min( 5000, max( 1, absint( $limit ) ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,code,name,source,external_source_id,status FROM %i WHERE status<>%s ORDER BY source,name,id LIMIT %d',
                Database::table( 'products' ),
                'archived',
                $limit
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Discover WooCommerce products and upsert lightweight source references.
     * The native WooCommerce product ID is always external_source_id; EVO's
     * internal row ID is never used as a WooCommerce identifier.
     *
     * @return array{created:int,updated:int,skipped:int,total:int,strategy:string,message:string}
     */
    public function sync_woocommerce( int $page_size = 100 ): array {
        if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
            return array(
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'total' => 0,
                'strategy' => 'unavailable',
                'message' => 'WooCommerce product APIs are not available. Make sure WooCommerce is active and loaded.',
            );
        }

        $page_size = min( 200, max( 20, $page_size ) );
        $page      = 1;
        $created   = 0;
        $updated   = 0;
        $skipped   = 0;
        $strategy  = 'wc_get_products';
        $message   = '';
        $max_pages = 1;

        do {
            $ids = array();
            try {
                $result = wc_get_products(
                    array(
                        'status'   => array( 'publish', 'private', 'draft', 'pending' ),
                        'limit'    => $page_size,
                        'page'     => $page,
                        'paginate' => true,
                        'return'   => 'ids',
                        'orderby'  => 'id',
                        'order'    => 'ASC',
                    )
                );

                $raw_products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : (array) $result;
                foreach ( $raw_products as $candidate ) {
                    if ( is_object( $candidate ) && is_callable( array( $candidate, 'get_id' ) ) ) {
                        $candidate = $candidate->get_id();
                    }
                    $candidate = absint( $candidate );
                    if ( $candidate > 0 ) { $ids[] = $candidate; }
                }
                $max_pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1;
            } catch ( \Throwable $throwable ) {
                $message  = sanitize_text_field( $throwable->getMessage() );
                $strategy = 'wp_query_fallback';
                $ids      = array();
            }

            // Some stores/plugins alter WC_Product_Query and can return an empty
            // first page. Fall back to WordPress' canonical product post IDs, then
            // still hydrate every product through wc_get_product().
            if ( 1 === $page && ! $ids && class_exists( '\\WP_Query' ) ) {
                $strategy = 'wp_query_fallback';
                $query = new \WP_Query(
                    array(
                        'post_type'              => 'product',
                        'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
                        'fields'                 => 'ids',
                        'posts_per_page'         => $page_size,
                        'paged'                  => $page,
                        'orderby'                => 'ID',
                        'order'                  => 'ASC',
                        'no_found_rows'          => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    )
                );
                $ids       = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
                $max_pages = max( 1, (int) $query->max_num_pages );
                if ( ! $ids && '' === $message ) {
                    $message = 'No WooCommerce products were found.';
                }
            } elseif ( 'wp_query_fallback' === $strategy && class_exists( '\\WP_Query' ) ) {
                $query = new \WP_Query(
                    array(
                        'post_type'              => 'product',
                        'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
                        'fields'                 => 'ids',
                        'posts_per_page'         => $page_size,
                        'paged'                  => $page,
                        'orderby'                => 'ID',
                        'order'                  => 'ASC',
                        'no_found_rows'          => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    )
                );
                $ids       = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
                $max_pages = max( 1, (int) $query->max_num_pages );
            }

            foreach ( array_values( array_unique( $ids ) ) as $native_id ) {
                $product = wc_get_product( $native_id );
                if ( ! $product ) {
                    ++$skipped;
                    continue;
                }

                $existing  = $this->get_by_source( self::SOURCE_WOOCOMMERCE, (string) $native_id );
                $image_id  = absint( $product->get_image_id() );
                $image_url = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'full' ) : '';
                $status    = in_array( (string) $product->get_status(), array( 'publish', 'private' ), true ) ? 'active' : 'inactive';

                $data = array(
                    'source'             => self::SOURCE_WOOCOMMERCE,
                    'external_source_id' => (string) $native_id,
                    'source_name'        => sanitize_text_field( (string) $product->get_name() ),
                    'name'               => sanitize_text_field( (string) $product->get_name() ),
                    'excerpt'            => wp_strip_all_tags( (string) $product->get_short_description() ),
                    'description'        => wp_kses_post( (string) $product->get_description() ),
                    'image_id'           => $image_id,
                    'image_url'          => esc_url_raw( $image_url ),
                    'price'              => $product->get_regular_price(),
                    'sale_price'         => $product->get_sale_price(),
                    'currency'           => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
                    'purchase_url'       => get_permalink( $native_id ),
                    'product_type'       => $product->is_virtual() ? 'digital' : 'other',
                    'status'             => $status,
                    'source_synced_at'   => current_time( 'mysql', true ),
                );

                if ( $existing ) {
                    $ok = $this->update_source_fields( (int) $existing['id'], $data );
                    $ok ? ++$updated : ++$skipped;
                } else {
                    $id = $this->create( $data );
                    $id > 0 ? ++$created : ++$skipped;
                }
            }

            ++$page;
        } while ( $page <= $max_pages );

        $summary = array(
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'total'    => $created + $updated + $skipped,
            'strategy' => $strategy,
            'message'  => $message,
        );

        EventBus::emit( 'product.woocommerce_synced', $summary, 'product', 0, 0, 'woocommerce' );
        return $summary;
    }

    public function woocommerce_products(): array {
        return $this->all( 1000, true, self::SOURCE_WOOCOMMERCE );
    }

    public function sync_plans( int $product_id, array $plan_ids ): void {
        global $wpdb;

        if ( $product_id < 1 ) {
            return;
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $plan_ids ) ) ) );
        $old_plan_ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT plan_id FROM %i WHERE product_id=%d',
                Database::table( 'plan_products' ),
                $product_id
            )
        );
        $old_plan_ids = is_array( $old_plan_ids ) ? array_values( array_filter( array_map( 'absint', $old_plan_ids ) ) ) : array();

        $wpdb->delete( Database::table( 'plan_products' ), array( 'product_id' => $product_id ), array( '%d' ) );

        $now = current_time( 'mysql', true );
        foreach ( $ids as $plan_id ) {
            // Product-side linking must not steal the primary-product flag from
            // another product already in the plan. It becomes primary only when
            // it is the first product in that plan.
            $has_products = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE plan_id=%d',
                    Database::table( 'plan_products' ),
                    $plan_id
                )
            );
            $wpdb->insert(
                Database::table( 'plan_products' ),
                array(
                    'plan_id'    => $plan_id,
                    'product_id' => $product_id,
                    'quantity'   => 1,
                    'is_primary' => 0 === $has_products ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                )
            );
        }

        $plan_service = new PlanService();
        foreach ( array_values( array_unique( array_merge( $old_plan_ids, $ids ) ) ) as $plan_id ) {
            $plan_service->refresh_mode( $plan_id );
        }
    }

    public static function sources(): array {
        return array( self::SOURCE_EVO, self::SOURCE_WOOCOMMERCE, self::SOURCE_MEMBER, self::SOURCE_EXTERNAL );
    }

    private function update_source_fields( int $id, array $data ): bool {
        global $wpdb;

        $row = array(
            'name'             => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'excerpt'          => sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) ) ?: null,
            'description'      => wp_kses_post( (string) ( $data['description'] ?? '' ) ) ?: null,
            'image_id'         => absint( $data['image_id'] ?? 0 ) ?: null,
            'image_url'        => $this->url_or_null( $data['image_url'] ?? '' ),
            'price'            => $this->decimal_or_null( $data['price'] ?? null ),
            'sale_price'       => $this->decimal_or_null( $data['sale_price'] ?? null ),
            'currency'         => $this->currency( $data['currency'] ?? '' ),
            'purchase_url'     => $this->url_or_null( $data['purchase_url'] ?? '' ),
            'product_type'     => $this->product_type( $data['product_type'] ?? 'digital' ),
            'source_name'      => sanitize_text_field( (string) ( $data['source_name'] ?? $data['name'] ?? '' ) ) ?: null,
            'source_synced_at' => sanitize_text_field( (string) ( $data['source_synced_at'] ?? current_time( 'mysql', true ) ) ),
            'status'           => $this->status( $data['status'] ?? 'active' ),
            'updated_at'       => current_time( 'mysql', true ),
        );

        return false !== $wpdb->update( Database::table( 'products' ), $row, array( 'id' => $id ) );
    }

    private function attach_plan_summaries( array $rows ): array {
        global $wpdb;

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map( static fn( array $row ): int => absint( $row['id'] ?? 0 ), $rows )
                )
            )
        );
        if ( ! $ids ) {
            return $rows;
        }

        $id_list = implode( ',', $ids );
        $links   = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT pp.product_id,p.id AS plan_id,p.code,p.name,p.status FROM %i pp INNER JOIN %i p ON p.id=pp.plan_id WHERE pp.product_id BETWEEN %d AND %d AND FIND_IN_SET(CAST(pp.product_id AS CHAR), %s) > 0 ORDER BY pp.product_id,pp.is_primary DESC,p.id',
                Database::table( 'plan_products' ),
                Database::table( 'plans' ),
                min( $ids ),
                max( $ids ),
                $id_list
            ),
            ARRAY_A
        );
        $by_product = array();

        foreach ( is_array( $links ) ? $links : array() as $link ) {
            $pid = absint( $link['product_id'] ?? 0 );
            if ( $pid > 0 ) {
                $by_product[ $pid ][] = $link;
            }
        }

        foreach ( $rows as &$row ) {
            $pid = absint( $row['id'] ?? 0 );
            $plans = $by_product[ $pid ] ?? array();
            $row['plan_count'] = count( $plans );
            $row['plan_names'] = array_values( array_map( static fn( array $p ): string => (string) $p['name'], $plans ) );
        }
        unset( $row );

        return $rows;
    }

    private function normalize( array $data, ?array $current ): ?array {
        $name = sanitize_text_field( (string) ( $data['name'] ?? $current['name'] ?? '' ) );
        if ( '' === $name ) {
            return null;
        }

        $source = $current ? $this->source( $current['source'] ?? self::SOURCE_EVO ) : $this->source( $data['source'] ?? self::SOURCE_EVO );
        $code   = sanitize_text_field( (string) ( $data['code'] ?? $current['code'] ?? '' ) );
        if ( '' === $code ) {
            return null;
        }

        $requires = array_key_exists( 'requires_license', $data )
            ? ( ! empty( $data['requires_license'] ) ? 1 : 0 )
            : (int) ( $current['requires_license'] ?? 0 );

        $provider = sanitize_key( (string) ( $data['license_provider'] ?? $current['license_provider'] ?? ( $requires ? 'evo' : 'none' ) ) );
        if ( ! $requires ) {
            $provider = 'none';
        } elseif ( '' === $provider || 'none' === $provider ) {
            $provider = 'evo';
        }

        $seats = $requires ? max( 1, absint( $data['license_seats'] ?? $data['max_activations'] ?? $current['license_seats'] ?? 1 ) ) : 1;
        $type  = $this->product_type( $data['product_type'] ?? $current['product_type'] ?? 'digital' );

        $metadata = array();
        if ( $current && ! empty( $current['metadata_json'] ) ) {
            $old = json_decode( (string) $current['metadata_json'], true );
            if ( is_array( $old ) ) {
                $metadata = $old;
            }
        }
        $metadata['notes']         = sanitize_textarea_field( (string) ( $data['notes'] ?? $metadata['notes'] ?? '' ) );
        $metadata['checkout_mode'] = sanitize_key( (string) ( $data['checkout_mode'] ?? $metadata['checkout_mode'] ?? 'wordpress' ) );

        $current_user_id = get_current_user_id();
        $owner_user_id   = absint( $current['owner_user_id'] ?? $current_user_id );
        $requested_owner = absint( $data['owner_user_id'] ?? $owner_user_id );
        if ( $requested_owner > 0 && ( $requested_owner === $current_user_id || current_user_can( Capabilities::PRODUCTS_EDIT_OTHERS ) ) ) {
            $owner_user_id = $requested_owner;
        }

        return array(
            'owner_user_id'         => $owner_user_id ?: null,
            'code'                  => $code,
            'name'                  => $name,
            'product_type'          => $type,
            'excerpt'               => sanitize_textarea_field( (string) ( $data['excerpt'] ?? $current['excerpt'] ?? '' ) ) ?: null,
            'description'           => wp_kses_post( (string) ( $data['description'] ?? $current['description'] ?? '' ) ) ?: null,
            'image_id'              => absint( $data['image_id'] ?? $current['image_id'] ?? 0 ) ?: null,
            'image_url'             => $this->url_or_null( $data['image_url'] ?? $current['image_url'] ?? '' ),
            'price'                 => $this->decimal_or_null( $data['price'] ?? $current['price'] ?? null ),
            'sale_price'            => $this->decimal_or_null( $data['sale_price'] ?? $current['sale_price'] ?? null ),
            'currency'              => $this->currency( $data['currency'] ?? $current['currency'] ?? '' ),
            'purchase_url'          => $this->url_or_null( $data['purchase_url'] ?? $current['purchase_url'] ?? '' ),
            'upgrade_url'           => $this->url_or_null( $data['upgrade_url'] ?? $current['upgrade_url'] ?? '' ),
            'download_url'          => $this->url_or_null( $data['download_url'] ?? $current['download_url'] ?? '' ),
            'requires_license'      => $requires,
            'license_type_id'       => $requires ? ( absint( $data['license_type_id'] ?? $current['license_type_id'] ?? 0 ) ?: null ) : null,
            'verification_mode'     => $requires ? $this->verification_mode( $data['verification_mode'] ?? $current['verification_mode'] ?? 'portable' ) : 'portable',
            'allowed_site_url'      => $requires ? $this->url_or_null( $data['allowed_site_url'] ?? $current['allowed_site_url'] ?? '' ) : null,
            'allowed_domain'        => $requires ? $this->nullable_text( $data['allowed_domain'] ?? $current['allowed_domain'] ?? null, 255 ) : null,
            'allowed_ip'            => $requires ? $this->nullable_text( $data['allowed_ip'] ?? $current['allowed_ip'] ?? null, 190 ) : null,
            'source'                => $source,
            'external_source_id'    => $this->nullable_text( $data['external_source_id'] ?? $current['external_source_id'] ?? null, 190 ),
            'source_name'           => $this->nullable_text( $data['source_name'] ?? $current['source_name'] ?? null, 190 ),
            'source_synced_at'      => $this->nullable_datetime( $data['source_synced_at'] ?? $current['source_synced_at'] ?? null ),
            'fulfillment_mode'      => sanitize_key( (string) ( $data['fulfillment_mode'] ?? $current['fulfillment_mode'] ?? 'evo' ) ) ?: 'evo',
            'license_provider'      => $provider,
            'status'                => $this->status( $data['status'] ?? $current['status'] ?? 'active' ),
            'license_seats'         => $seats,
            'max_activations'       => 1,
            'license_duration_days' => $requires && ! empty( $data['license_duration_days'] ?? $current['license_duration_days'] ?? 0 ) ? absint( $data['license_duration_days'] ?? $current['license_duration_days'] ) : null,
            'metadata_json'         => wp_json_encode( $metadata ),
        );
    }

    private function build_code( string $source, int $id, ?string $external_source_id ): string {
        return match ( $source ) {
            self::SOURCE_WOOCOMMERCE => 'W' . absint( $external_source_id ),
            self::SOURCE_MEMBER      => 'M' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
            self::SOURCE_EXTERNAL    => 'X' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
            default                  => 'E' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
        };
    }

    private function source( mixed $value ): string {
        $value = sanitize_key( (string) $value );
        if ( 'manual' === $value ) {
            $value = self::SOURCE_EVO;
        }
        return in_array( $value, self::sources(), true ) ? $value : self::SOURCE_EVO;
    }

    private function product_type( mixed $value ): string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'digital', 'membership', 'service', 'software', 'course', 'other' ), true ) ? $value : 'digital';
    }

    private function status( mixed $value ): string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'active', 'inactive', 'frozen', 'archived' ), true ) ? $value : 'active';
    }

    private function verification_mode( mixed $value ): string {
        $value = sanitize_key( (string) $value );
        return in_array( $value, array( 'portable', 'site', 'domain', 'server_ip', 'domain_ip' ), true ) ? $value : 'portable';
    }

    private function nullable_text( mixed $value, int $max ): ?string {
        $value = sanitize_text_field( (string) $value );
        if ( '' === $value ) {
            return null;
        }
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    private function nullable_datetime( mixed $value ): ?string {
        $value = sanitize_text_field( (string) $value );
        if ( '' === $value ) {
            return null;
        }
        $time = strtotime( $value . ( str_contains( $value, 'UTC' ) ? '' : ' UTC' ) );
        return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
    }

    private function decimal_or_null( mixed $value ): ?string {
        if ( '' === trim( (string) $value ) ) {
            return null;
        }
        return number_format( (float) $value, 8, '.', '' );
    }

    private function currency( mixed $value ): ?string {
        $value = strtoupper( preg_replace( '/[^A-Z]/i', '', (string) $value ) );
        return '' !== $value ? substr( $value, 0, 12 ) : null;
    }

    private function url_or_null( mixed $value ): ?string {
        $value = esc_url_raw( (string) $value );
        return '' !== $value ? $value : null;
    }
}
