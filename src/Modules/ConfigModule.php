<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;
use WP_REST_Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Restaurant Configuration, Opening Hours, and Schedule UI.
 */
class ConfigModule extends AbstractModule {

    protected function init() {
        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Status Logic
        add_action( 'wp', [ $this, 'handle_shop_purchasable_status' ], 5 );

        // Sidebar / UI Styles & Scripts
        add_action( 'wp_head', [ $this, 'inject_sidebar_styles' ], 100 );
        add_action( 'wp_footer', [ $this, 'inject_sidebar_html' ] );

        // Product Alerts
        add_action( 'woocommerce_single_product_summary', [ $this, 'inject_product_alerts' ], 15 );
    }

    /**
     * Registers REST routes for consolidated configuration.
     */
    public function register_rest_routes() {
        $routes = [
            'checkout-config' => 'pos_checkout_fields_config',
            'services-config' => 'pos_services_config'
        ];

        foreach ( $routes as $path => $option ) {
            register_rest_route( 'pos/v1', '/' . $path, [
                'methods'  => 'POST',
                'callback' => function( WP_REST_Request $request ) use ( $option ) {
                    $params = $request->get_json_params();
                    if ( empty( $params ) && $request->get_body() ) {
                        $params = json_decode( $request->get_body(), true );
                    }
                    if ( ! is_array( $params ) ) {
                        return new WP_Error( 'invalid_content', 'Invalid JSON payload', [ 'status' => 400 ] );
                    }
                    update_option( $option, $params );
                    return rest_ensure_response( [ 'success' => true, 'option' => $option ] );
                },
                'permission_callback' => '__return_true',
            ]);
        }
    }

    /**
     * Helper: Get Restaurant Status and Next Event.
     */
    public function get_restaurant_status( $config ) {
        if ( empty( $config['openingHours'] ) ) return [ 'is_open' => true, 'status' => 'open' ];

        $has_any_session = false;
        foreach ( $config['openingHours'] as $day_sessions ) {
            if ( ! empty( $day_sessions ) ) { $has_any_session = true; break; }
        }
        if ( ! $has_any_session ) return [ 'is_open' => true, 'status' => 'open' ];

        $now = current_time( 'timestamp' );
        $day = strtolower( date( 'l', $now ) );
        $current_h_i = date( 'H:i', $now );
        
        $sessions = isset( $config['openingHours'][$day] ) ? $config['openingHours'][$day] : [];
        
        $is_open = false;
        $time_left_closed = 0;
        
        foreach ( $sessions as $session ) {
            if ( $current_h_i >= $session['open'] && $current_h_i <= $session['close'] ) {
                $is_open = true;
                $close_ts = strtotime( date( 'Y-m-d', $now ) . ' ' . $session['close'] );
                $time_left_closed = floor( ( $close_ts - $now ) / 60 );
                break;
            }
        }

        if ( $is_open ) {
            return [
                'status'    => ( $time_left_closed <= 30 ) ? 'warning' : 'open',
                'is_open'   => true,
                'time_left' => $time_left_closed
            ];
        }

        $next_open_ts = 0;
        foreach ( $sessions as $session ) {
            $open_ts = strtotime( date( 'Y-m-d', $now ) . ' ' . $session['open'] );
            if ( $open_ts > $now ) {
                $next_open_ts = $open_ts;
                break;
            }
        }
        
        if ( ! $next_open_ts ) {
            for ( $i = 1; $i <= 7; $i++ ) {
                $check_ts = $now + ( $i * 86400 );
                $check_day = strtolower( date( 'l', $check_ts ) );
                $check_sessions = isset( $config['openingHours'][$check_day] ) ? $config['openingHours'][$check_day] : [];
                if ( ! empty( $check_sessions ) ) {
                    $next_open_ts = strtotime( date( 'Y-m-d', $check_ts ) . ' ' . $check_sessions[0]['open'] );
                    break;
                }
            }
        }

        return [
            'status'          => 'closed',
            'is_open'         => false,
            'time_left'       => $next_open_ts ? floor( ( $next_open_ts - $now ) / 60 ) : 0,
            'next_open_label' => $next_open_ts ? date( 'l H:i', $next_open_ts ) : 'Soon'
        ];
    }

    /**
     * Disables purchasing if the shop is closed.
     */
    public function handle_shop_purchasable_status() {
        if ( is_admin() || ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) ) return;
        
        $config = $this->get_config();
        if ( empty( $config ) ) return;

        $res_status = $this->get_restaurant_status( $config );
        if ( isset( $res_status['is_open'] ) && ! $res_status['is_open'] ) {
            add_filter( 'woocommerce_is_purchasable', '__return_false' );
        }
    }

    /**
     * Injects sidebar CSS into wp_head.
     */
    public function inject_sidebar_styles() {
        if ( is_admin() ) return;
        $config = $this->get_config();
        if ( empty( $config ) ) return;
        $res_status = $this->get_restaurant_status( $config );
        
        $bg = 'rgba(71, 85, 105, 0.95)';
        if ( $res_status['status'] === 'closed' ) $bg = 'rgba(239, 68, 68, 0.95)';
        elseif ( $res_status['status'] === 'warning' ) $bg = 'rgba(245, 158, 11, 0.95)';
        elseif ( $res_status['status'] === 'open' ) $bg = 'rgba(16, 185, 129, 0.95)';
        
        ?>
        <style>
            #pos-schedule-panel {
                position: fixed; top: 180px; right: -300px; width: 300px;
                background: rgba(255, 255, 255, 0.98); z-index: 10001; 
                transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
                border-radius: 12px 0 0 12px; border: 1px solid rgba(0,0,0,0.08);
                box-shadow: -10px 0 35px rgba(0,0,0,0.1); backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                overflow: visible;
            }
            #pos-schedule-panel .panel-content {
                padding: 12px 20px; height: fit-content; overflow: visible;
            }
            #pos-schedule-panel .panel-content::-webkit-scrollbar { display: none; }
            #pos-schedule-panel.open { right: 0; box-shadow: -15px 0 50px rgba(0,0,0,0.15); }
            #pos-schedule-panel .panel-tab {
                position: absolute; left: -44px; top: 40px; width: 44px; height: 120px;
                background: <?php echo $bg; ?> !important; color: white !important; display: flex !important;
                align-items: center; justify-content: center;
                writing-mode: vertical-rl; text-transform: uppercase; font-size: 11px; font-weight: 800;
                letter-spacing: 1.5px; cursor: pointer; border-radius: 12px 0 0 12px;
                box-shadow: -4px 0 12px rgba(0,0,0,0.1);
                visibility: visible !important; opacity: 1 !important;
                transition: all 0.3s ease;
            }
            #pos-schedule-panel .panel-tab:hover { width: 48px; left: -48px; }
            #pos-schedule-panel h4 { 
                margin: 0 0 15px; font-size: 16px; font-weight: 700;
                border-bottom: 2px solid <?php echo $bg; ?>; padding-bottom: 10px; color: #1e293b;
            }
            #pos-schedule-panel .current-time { 
                font-size: 11px; color: #64748b; margin-bottom: 15px; 
                font-weight: 600; background: #f8fafc; padding: 12px; border-radius: 12px;
                display: flex; align-items: center; gap: 15px; border: 1px solid #f1f5f9;
            }
            .analog-clock {
                width: 60px; height: 60px; position: relative;
                background: #fff; border-radius: 50%; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05), 0 2px 8px rgba(0,0,0,0.05);
                border: 2px solid #fff; flex-shrink: 0;
            }
            .clock-face {
                width: 100%; height: 100%; position: relative; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
            }
            .time-slice {
                position: absolute; width: 100%; height: 100%; border-radius: 50%;
                background: conic-gradient(transparent 0deg, transparent 360deg);
                opacity: 0.25; transform: rotate(-90deg); /* Start from 12 o clock */
                pointer-events: none;
            }
            .hand {
                position: absolute; bottom: 50%; left: 50%; transform-origin: bottom center;
                border-top-left-radius: 50%; border-top-right-radius: 50%;
                transition: transform 0.5s cubic-bezier(0.1, 2.7, 0.58, 1);
            }
            .hand.hour { width: 3px; height: 15px; background: #1e293b; z-index: 3; }
            .hand.minute { width: 2px; height: 22px; background: #475569; z-index: 2; }
            .hand.second { width: 1px; height: 24px; background: <?php echo $bg; ?>; z-index: 4; transition: transform 0.2s linear; }
            .center-dot {
                width: 6px; height: 6px; background: #1e293b; border-radius: 50%;
                z-index: 5; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            #pos-schedule-panel .digital-time { flex: 1; }
            #pos-schedule-panel .day-row { 
                display: flex; justify-content: space-between; padding: 6px 0; 
                font-size: 13px; border-bottom: 1px solid #f1f5f9; color: #334155;
            }
            #pos-schedule-panel .day-row.today { 
                color: <?php echo $bg; ?>; font-weight: 700; 
                background: color-mix(in srgb, <?php echo $bg; ?> 8%, white); 
                margin: 0 -15px; padding: 6px 15px; border-radius: 8px;
                border-bottom: none;
            }
            #pos-schedule-panel .day-row .times { text-align: right; font-variant-numeric: tabular-nums; }
            #pos-schedule-panel .day-row .closed { color: #94a3b8; font-weight: 500; }
            #pos-schedule-panel { -ms-overflow-style: none; scrollbar-width: none; }
            @media (max-width: 768px) {
                #pos-schedule-panel { top: 110px !important; }
                #pos-schedule-panel .panel-tab { 
                    width: 35px !important; height: 96px !important; left: -35px !important; 
                    font-size: 9px !important; letter-spacing: 1px !important;
                }
                #pos-schedule-panel .panel-tab:hover { width: 38px !important; left: -38px !important; }
            }
            .pos-product-alert {
                background: #fffbeb; color: #92400e; padding: 16px; 
                border-radius: 10px; margin-bottom: 25px; font-weight: 600; 
                border: 1px solid #fde68a; border-left: 4px solid #f59e0b;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
                font-size: 14px;
            }
            .pos-product-alert.closed {
                background: #fef2f2; color: #991b1b; border-color: #fecaca; border-left-color: #ef4444;
            }
        </style>
        <?php
    }

    /**
     * Injects sidebar HTML into wp_footer.
     */
    public function inject_sidebar_html() {
        if ( is_admin() ) return;
        $config = $this->get_config();
        if ( empty( $config ) ) return;
        $res_status = $this->get_restaurant_status( $config );
        
        $msg_config = isset( $config['messages'] ) ? $config['messages'] : [];
        $schedule_title = $msg_config['scheduleTitle'] ?? 'Horario';
        $days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
        $now = current_time( 'timestamp' );
        $current_day = strtolower( date( 'l', $now ) );
        $current_h_i = date( 'H:i', $now );
        
        $sessions = isset( $config['openingHours'][$current_day] ) ? $config['openingHours'][$current_day] : [];
        $active_session = null;
        if ( $res_status['is_open'] ) {
            foreach ( $sessions as $s ) {
                if ( $current_h_i >= $s['open'] && $current_h_i <= $s['close'] ) {
                    $active_session = $s;
                    break;
                }
            }
        }

        echo '<div id="pos-schedule-panel">
            <div class="panel-tab">' . esc_html( $schedule_title ) . '</div>
            <div class="panel-content">
                <h4>' . esc_html( $schedule_title ) . '</h4>
                <div class="current-time" 
                     data-now="' . esc_attr( $current_h_i ) . '"
                     data-open="' . esc_attr( $active_session ? $active_session['open'] : '' ) . '"
                     data-close="' . esc_attr( $active_session ? $active_session['close'] : '' ) . '"
                     data-status="' . esc_attr( $res_status['status'] ) . '">
                    <div class="analog-clock">
                        <div class="clock-face">
                            <div class="time-slice"></div>
                            <div class="hand hour"></div>
                            <div class="hand minute"></div>
                            <div class="hand second"></div>
                            <div class="center-dot"></div>
                        </div>
                    </div>
                    <div class="digital-time">Shop Time: ' . date( 'H:i', $now ) . '</div>
                </div>';
                
                foreach ( $days as $d ) {
                    $is_today = ( $d === $current_day ) ? 'today' : '';
                    $d_sessions = isset( $config['openingHours'][$d] ) ? $config['openingHours'][$d] : [];
                    echo '<div class="day-row ' . $is_today . '">
                        <span class="day-name">' . ucfirst( $d ) . '</span>
                        <span class="times">';
                    if ( empty( $d_sessions ) ) {
                        echo '<span class="closed">Closed</span>';
                    } else {
                        $time_strs = [];
                        foreach ( $d_sessions as $s ) {
                            $time_strs[] = $s['open'] . '-' . $s['close'];
                        }
                        echo implode( '<br>', $time_strs );
                    }
                    echo '</span></div>';
                }
            echo '</div>
        </div>';
        ?>
        <script type="text/javascript">
            jQuery(function($){
                function updateAnalogClock() {
                    const now = new Date();
                    const s = now.getSeconds();
                    const m = now.getMinutes();
                    const h = now.getHours();
                    
                    const sDeg = (s / 60) * 360;
                    const mDeg = (m / 60) * 360 + (s / 60) * 6;
                    const hDeg = (h % 12 / 12) * 360 + (m / 60) * 30;
                    
                    $('.clock-face .second').css('transform', `rotate(${sDeg}deg)`);
                    $('.clock-face .minute').css('transform', `rotate(${mDeg}deg)`);
                    $('.clock-face .hour').css('transform', `rotate(${hDeg}deg)`);
                    
                    const $container = $('.current-time');
                    const closeStr = $container.data('close');
                    const openStr = $container.data('open');
                    
                    if (closeStr && openStr) {
                        const parseTime = (str) => {
                            const [hrs, mins] = str.split(':').map(Number);
                            const d = new Date();
                            d.setHours(hrs, mins, 0, 0);
                            return d;
                        };
                        
                        const openTime = parseTime(openStr);
                        const closeTime = parseTime(closeStr);
                        const currentTime = new Date();
                        
                        if (currentTime >= openTime && currentTime <= closeTime) {
                            const sliceStart = (currentTime.getHours() % 12 * 60 + currentTime.getMinutes()) / 720 * 360;
                            const sliceEnd = (closeTime.getHours() % 12 * 60 + closeTime.getMinutes()) / 720 * 360;
                            
                            let diff = sliceEnd - sliceStart;
                            if (diff < 0) diff += 360;
                            
                            const status = $container.data('status');
                            const color = (status === 'warning') ? '#f59e0b' : '#10b981';
                            
                            $('.time-slice').css({
                                'background': `conic-gradient(${color} 0deg, ${color} ${diff}deg, transparent ${diff}deg)`,
                                'transform': `rotate(${sliceStart - 90}deg)`
                            });
                        } else {
                            $('.time-slice').css('background', 'transparent');
                        }
                    }
                }
                setInterval(updateAnalogClock, 1000);
                updateAnalogClock();

                $(document).on('click', '#pos-schedule-panel .panel-tab, #pos-schedule-panel', function(e){
                    if ($(e.target).closest('.panel-content').length) return;
                    e.preventDefault();
                    e.stopPropagation();
                    $('#pos-schedule-panel').toggleClass('open');
                });
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('#pos-schedule-panel').length) {
                        $('#pos-schedule-panel').removeClass('open');
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Injects product-specific alerts.
     */
    public function inject_product_alerts() {
        $config = $this->get_config();
        if ( empty( $config ) ) return;
        $res_status = $this->get_restaurant_status( $config );
        
        if ( $res_status['status'] === 'closed' || $res_status['status'] === 'warning' ) {
            $msg_config = isset( $config['messages'] ) ? $config['messages'] : [];
            $template = ( $res_status['status'] === 'closed' ) 
                ? ( $msg_config['closedTemplate'] ?? "Restaurant is CLOSED. Opens in {timeLeftOpen}" )
                : ( $msg_config['warningTemplate'] ?? "Closing soon! {timeLeftClosed} left to order" );
            
            $time_val = $res_status['time_left'];
            $time_formatted = ( $time_val >= 60 ) ? ( floor( $time_val / 60 ) . 'h ' . ( $time_val % 60 ) . 'm' ) : ( $time_val . 'm' );
            $final_msg = str_replace( [ '{timeLeftOpen}', '{timeLeftClosed}' ], $time_formatted, $template );
            $class = ( $res_status['status'] === 'closed' ) ? 'closed' : '';
            
            echo '<div class="pos-product-alert ' . $class . '">
                ' . ( $res_status['status'] === 'closed' ? '🚫' : '⚠️' ) . ' ' . esc_html( $final_msg ) . '
            </div>';
        }
    }

    private function get_config() {
        $config = get_option( 'pos_restaurant_config' );
        if ( ! $config ) return [];
        if ( is_array( $config ) ) return $config;
        return is_string( $config ) ? json_decode( $config, true ) : [];
    }
}
