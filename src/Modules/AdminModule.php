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

        // System Metrics
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
        $mem_usage = memory_get_usage(true);
        $mem_limit = $this->parse_size(ini_get('memory_limit'));
        $mem_pct = $mem_limit > 0 ? round(($mem_usage / $mem_limit) * 100, 1) : 0;
        
        $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : 0;
        $disk_total = function_exists('disk_total_space') ? @disk_total_space(ABSPATH) : 0;
        $disk_pct = $disk_total > 0 ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0;

        $active_clients = get_option('pos_active_clients', []);
        $now = time();
        // Prune stale clients on view
        $active_clients = array_filter($active_clients, function($c) use ($now) {
            return ($now - $c['last_seen']) < 120;
        });
        if (count($active_clients) !== count(get_option('pos_active_clients', []))) {
            update_option('pos_active_clients', $active_clients);
        }

        ?>
        <div class="pos-admin-wrap">
            <div class="pos-header">
                <h1>POS Unified Backend <span class="version">v4.1</span></h1>
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
                                <span class="val"><?php echo count($active_clients); ?></span>
                                <span class="lab">Connected Clients</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Server Health -->
                <div class="pos-card">
                    <div class="pos-card-header"><h3>Server Health & Usage</h3></div>
                    <div class="pos-card-content">
                        <div class="status-item">
                            <span class="status-label">CPU Load (1/5/15m)</span>
                            <span class="status-value"><?php echo implode(' / ', $load); ?></span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">RAM Usage (<?php echo $mem_pct; ?>%)</span>
                            <div class="status-value">
                                <?php echo size_format($mem_usage); ?> / <?php echo ini_get('memory_limit'); ?>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Disk Usage (<?php echo $disk_pct; ?>%)</span>
                            <div class="status-value">
                                <?php echo size_format($disk_total - $disk_free); ?> / <?php echo size_format($disk_total); ?>
                            </div>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Processor</span>
                            <span class="status-value" style="font-size:10px;"><?php echo PHP_OS; ?> (<?php echo php_uname('m'); ?>)</span>
                        </div>
                    </div>
                </div>

                <!-- Active Clients Details -->
                <div class="pos-card">
                    <div class="pos-card-header"><h3>Live Connections (SSE)</h3></div>
                    <div class="pos-card-content">
                        <?php if (empty($active_clients)): ?>
                            <p style="text-align:center; color:#94a3b8; padding:20px;">No active POS/KDS connections found.</p>
                        <?php else: ?>
                            <?php foreach ($active_clients as $ip => $client): ?>
                                <div class="status-item">
                                    <div>
                                        <span class="status-label"><?php echo esc_html($ip); ?></span>
                                        <div style="font-size:10px; color:#94a3b8; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($client['ua']); ?>">
                                            <?php echo esc_html($client['ua']); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="badge badge-ready"><?php echo esc_html($client['source']); ?></span>
                                        <div style="font-size:9px; color:#94a3b8; text-align:right;">active <?php echo human_time_diff($client['last_seen']); ?> ago</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
                    <div class="pos-card-header"><h3>Environment</h3></div>
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
                            <span class="status-label">SQL Mode</span>
                            <span class="status-value" style="font-size:10px;">Standard</span>
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

    private function parse_size($size) {
        $unit = preg_replace('/[^bkmgtpe]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        if ($unit) {
            return (int)round($size * pow(1024, stripos('bkmgtpe', $unit[0])));
        }
        return (int)round($size);
    }
}
