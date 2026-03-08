<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles High-Performance GraphQL Caching via Transients.
 */
class CacheModule extends AbstractModule {
    
    private $cacheable_operations = [
        'GetWooInitData', 
        'GetCatalog', 
        'GetProducts', 
        'GetOrderDates', 
        'GetShippingData'
    ];

    protected function init() {
        // Intercept REST API dispatch for /graphql
        add_filter( 'rest_pre_dispatch', [ $this, 'intercept_graphql_pre' ], 10, 3 );
        add_filter( 'rest_post_dispatch', [ $this, 'intercept_graphql_post' ], 10, 3 );

        // Cache Invalidation Hooks
        add_action( 'save_post_product', [ $this, 'invalidate_cache' ] );
        add_action( 'woocommerce_new_order', [ $this, 'invalidate_cache' ] );
        add_action( 'woocommerce_update_order', [ $this, 'invalidate_cache' ] );
        add_action( 'update_option_pos_restaurant_config', [ $this, 'invalidate_cache' ] );
    }

    /**
     * Bypasses GraphQL execution if a cached version exists.
     */
    public function intercept_graphql_pre( $result, $server, $request ) {
        if ( strpos( $request->get_route(), '/graphql' ) === false ) return $result;

        $params = $request->get_json_params() ?: $request->get_body_params();
        $op = isset( $params['operationName'] ) ? $params['operationName'] : '';

        if ( in_array( $op, $this->cacheable_operations ) ) {
            $key = 'pos_gql_cache_' . md5( $op );
            $cached = get_transient( $key );

            if ( $cached !== false ) {
                $response = new WP_REST_Response( $cached, 200 );
                $response->header( 'X-POS-Cache', 'HIT' );
                return $response;
            }
        }

        return $result;
    }

    /**
     * Saves successful GraphQL responses to transients.
     */
    public function intercept_graphql_post( $response, $server, $request ) {
        if ( strpos( $request->get_route(), '/graphql' ) === false ) return $response;

        $params = $request->get_json_params() ?: $request->get_body_params();
        $op = isset( $params['operationName'] ) ? $params['operationName'] : '';

        if ( in_array( $op, $this->cacheable_operations ) && ! is_wp_error( $response ) && $response->get_status() === 200 ) {
            $data = $response->get_data();

            // Only cache if there are no errors in the GraphQL response
            if ( empty( $data['errors'] ) ) {
                $key = 'pos_gql_cache_' . md5( $op );
                set_transient( $key, $data, HOUR_IN_SECONDS );
                $response->header( 'X-POS-Cache', 'MISS-SAVED' );
            }
        }

        return $response;
    }

    /**
     * Clears all POS transients.
     */
    public function invalidate_cache() {
        foreach ( $this->cacheable_operations as $op ) {
            delete_transient( 'pos_gql_cache_' . md5( $op ) );
        }
        
        // Also clear legacy shipping method counts transients if any
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_shipping_method_counts_%' OR option_name LIKE '_transient_timeout_shipping_method_counts_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_ship_%' OR option_name LIKE '_transient_timeout_wc_ship_%'" );
    }
}
