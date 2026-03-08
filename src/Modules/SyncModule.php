<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;
use WP_REST_Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Real-Time Order Sync via Server-Sent Events (SSE) and Settings.
 */
class SyncModule extends AbstractModule {

    protected function init() {
        // Admin Menu & Settings
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Track Order Changes
        add_action( 'woocommerce_new_order', [ $this, 'track_order_changes' ], 10, 1 );
        add_action( 'woocommerce_update_order', [ $this, 'track_order_changes' ], 10, 1 );
        add_action( 'woocommerce_trash_order', [ $this, 'track_order_changes' ], 10, 1 );

        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    public function register_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=product',
            'POS Settings',
            'POS Settings',
            'manage_woocommerce',
            'pos-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'pos_settings_group', 'pos_enable_sse_stream' );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        ?>
        <div class="wrap">
            <h1>POS Settings & Configuration</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'pos_settings_group' ); ?>
                <?php do_settings_sections( 'pos_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Real-Time SSE Stream</th>
                        <td>
                            <input type="checkbox" name="pos_enable_sse_stream" value="yes" <?php checked( 'yes', get_option( 'pos_enable_sse_stream', 'no' ) ); ?> />
                            <p class="description">Enable Server-Sent Events to push WooCommerce orders instantly to the POS/KDS without polling.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function track_order_changes( $order_id ) {
        if ( get_option( 'pos_enable_sse_stream', 'no' ) !== 'yes' ) return;
        if ( get_post_type( $order_id ) !== 'shop_order' && get_post_type( $order_id ) !== 'shop_order_placehold' ) return;

        $changed = get_option( 'pos_sse_changed_orders', [] );
        if ( ! is_array( $changed ) ) $changed = [];
        
        if ( ! in_array( $order_id, $changed ) ) {
            $changed[] = $order_id;
            update_option( 'pos_sse_changed_orders', $changed );
            update_option( 'pos_sse_last_update', time() );
        }
    }

    public function register_rest_routes() {
        register_rest_route( 'pos/v1', '/stream', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_sse_stream' ],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route( 'pos/v1', '/restaurant-config', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_config_rest' ],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_config_rest( WP_REST_Request $request ) {
        if ( $request->get_method() === 'POST' ) {
            $params = $request->get_json_params();
            if ( empty( $params ) && $request->get_body() ) {
                $params = json_decode( $request->get_body(), true );
            }
            if ( empty( $params ) ) {
                return new WP_Error( 'invalid_data', 'No configuration data provided.', [ 'status' => 400 ] );
            }
            
            $existing = get_option( 'pos_restaurant_config', [] );
            if ( ! is_array( $existing ) ) $existing = json_decode( $existing, true ) ?: [];
            
            $updated_config = array_replace_recursive( $existing, $params );
            update_option( 'pos_restaurant_config', $updated_config );
            
            return [ 'success' => true, 'message' => 'Configuration saved successfully.' ];
        }

        $config = get_option( 'pos_restaurant_config', [] );
        return is_array( $config ) ? $config : json_decode( $config, true );
    }

    public function handle_sse_stream( WP_REST_Request $request ) {
        if ( get_option( 'pos_enable_sse_stream', 'no' ) !== 'yes' ) {
            return new WP_Error( 'disabled', 'SSE Stream is disabled.', [ 'status' => 403 ] );
        }

        if ( function_exists( 'apache_setenv' ) ) @apache_setenv( 'no-gzip', 1 );
        @ini_set( 'zlib.output_compression', 0 );
        @ini_set( 'implicit_flush', 1 );
        @set_time_limit( 0 );

        if ( session_id() ) session_write_close();

        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        do_action( 'pos_sse_stream_started' );

        echo "event: connected\ndata: {\"status\":\"listening\"}\n\n";
        @ob_flush();
        @flush();

        $max_execution_time = 45;
        $start_time = time();
        $last_check = array_sum( explode( ' ', microtime() ) );

        while ( time() - $start_time < $max_execution_time ) {
            if ( connection_aborted() ) break;

            $last_update = get_option( 'pos_sse_last_update', 0 );
            if ( $last_update >= floor( $last_check ) ) {
                $changed_orders = get_option( 'pos_sse_changed_orders', [] );
                if ( ! empty( $changed_orders ) ) {
                    update_option( 'pos_sse_changed_orders', [] );
                    echo "event: order_update\ndata: " . wp_json_encode( [ 'order_ids' => $changed_orders ] ) . "\n\n";
                    @ob_flush();
                    @flush();
                }
            }
            $last_check = array_sum( explode( ' ', microtime() ) );
            if ( time() % 15 == 0 ) {
                echo ": heartbeat\n\n";
                @ob_flush();
                @flush();
            }
            sleep( 1 ); 
        }

        echo "event: reconnect\ndata: timeout\n\n";
        @ob_flush();
        @flush();
        exit;
    }
}
