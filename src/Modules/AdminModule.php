<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;
use POS\WooSync\Plugin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cental POS Dashboard and Admin UI orchestrator.
 */
class AdminModule extends AbstractModule {

    protected function init() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 60 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Registers the POS Panel under WooCommerce.
     */
    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'POS Panel', 'pos-rules' ),
            __( 'POS Panel', 'pos-rules' ),
            'manage_woocommerce',
            'pos-dashboard',
            [ $this, 'render_dashboard' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'woocommerce_page_pos-dashboard' !== $hook ) return;
        
        ?>
        <style>
            .pos-admin-wrap { margin: 20px 20px 0 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
            .pos-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: #fff; padding: 20px 30px; border-radius: 16px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
            .pos-header h1 { margin: 0; font-size: 24px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 12px; }
            .pos-header .version { font-size: 11px; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; color: #64748b; font-weight: 700; }
            
            .pos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
            .pos-card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 4px 20px rgba(0,0,0,0.03); overflow: hidden; height: 100%; transition: transform 0.2s ease; }
            .pos-card:hover { transform: translateY(-2px); }
            .pos-card-header { padding: 20px 25px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
            .pos-card-header h3 { margin: 0; font-size: 16px; font-weight: 700; color: #334155; }
            .pos-card-content { padding: 25px; }

            .status-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #f8fafc; }
            .status-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
            .status-label { font-weight: 600; color: #64748b; font-size: 13px; }
            .status-value { font-weight: 700; font-variant-numeric: tabular-nums; }
            
            .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
            .badge-ready { background: #dcfce7; color: #15803d; }
            .badge-error { background: #fee2e2; color: #b91c1c; }
            .badge-offline { background: #f1f5f9; color: #475569; }

            .pos-stats-hero { grid-column: 1 / -1; background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; border: none; }
            .pos-stats-hero h3 { color: #fff !important; }
            .pos-stats-hero .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px; }
            .stat-box { text-align: center; }
            .stat-box .val { font-size: 32px; font-weight: 800; display: block; margin-bottom: 5px; }
            .stat-box .lab { font-size: 12px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

            .pos-footer { margin-top: 40px; text-align: center; color: #94a3b8; font-size: 12px; font-weight: 500; }
        </style>
        <?php
    }

    public function render_dashboard() {
        $orchestrator = Plugin::instance();
        $modules = $orchestrator->get_module_statuses();
        
        $module_descs = [
            'ConfigModule'   => 'Restaurant Hours & Settings',
            'RulesModule'    => 'Product Modifiers & Stepper',
            'ShippingModule' => 'POS Shipping Bridge',
            'CheckoutModule' => 'Checkout Fields & Visibility',
            'SyncModule'     => 'Real-time SSE Stream',
            'StatsModule'    => 'API Analytics Engine',
            'AdminModule'    => 'Dashboard & Diagnostics',
            'CacheModule'    => 'Dynamic Data Caching',
            'SecurityModule' => 'CORS & Security Headers'
        ];

        $stats = get_option( 'pos_api_stats', [
            'total_requests' => 0,
            'avg_response'   => 0,
            'error_rate'     => 0,
            'active_streams' => 0
        ]);

        ?>
        <div class="pos-admin-wrap">
            <div class="pos-header">
                <h1>POS Unified Backend <span class="version">v4.0</span></h1>
                <div class="header-actions">
                    <button class="button button-primary" onclick="location.reload()">Refresh Diagnostics</button>
                </div>
            </div>

            <div class="pos-grid">
                <!-- API Performance Hero -->
                <div class="pos-card pos-stats-hero">
                    <div class="pos-card-header"><h3>Live API Analytics (Last 24h)</h3></div>
                    <div class="pos-card-content">
                        <div class="stats-grid">
                            <div class="stat-box">
                                <span class="val"><?php echo number_format($stats['total_requests']); ?></span>
                                <span class="lab">Total API Requests</span>
                            </div>
                            <div class="stat-box">
                                <span class="val"><?php echo number_format($stats['avg_response']); ?>ms</span>
                                <span class="lab">Avg Response Time</span>
                            </div>
                            <div class="stat-box">
                                <span class="val"><?php echo $stats['error_rate']; ?>%</span>
                                <span class="lab">Error Rate</span>
                            </div>
                            <div class="stat-box">
                                <span class="val"><?php echo $stats['active_streams']; ?></span>
                                <span class="lab">Active SSE Clients</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Module Health -->
                <div class="pos-card">
                    <div class="pos-card-header"><h3>Bridge Modules Health</h3></div>
                    <div class="pos-card-content">
                        <?php foreach ( $modules as $name => $status_data ): ?>
                            <div class="status-item">
                                <div>
                                    <span class="status-label"><?php echo esc_html($name); ?></span>
                                    <div style="font-size:11px; color:#94a3b8;"><?php echo isset($module_descs[$name]) ? esc_html($module_descs[$name]) : 'Core Module'; ?></div>
                                </div>
                                <span class="badge badge-<?php echo strtolower($status_data['status']); ?>"><?php echo esc_html($status_data['status']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- System Info -->
                <div class="pos-card">
                    <div class="pos-card-header"><h3>System Environment</h3></div>
                    <div class="pos-card-content">
                        <div class="status-item">
                            <span class="status-label">WP Version</span>
                            <span class="status-value"><?php echo get_bloginfo('version'); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">PHP Version</span>
                            <span class="status-value"><?php echo PHP_VERSION; ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Memory Limit</span>
                            <span class="status-value"><?php echo ini_get('memory_limit'); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">SSL Status</span>
                            <span class="status-value"><?php echo is_ssl() ? '✅ Secure' : '❌ Unsecured'; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pos-footer">
                Developed by Digitalstudio.PRO &copy; <?php echo date('Y'); ?> | Professional POS Connectivity Suite
            </div>
        </div>
        <?php
    }
}
