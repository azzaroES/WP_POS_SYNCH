<?php
namespace POS\WooSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The Plugin Orchestrator (Manager)
 * Responsible for booting modules and registering the health API.
 */
class Plugin {
    private static $instance = null;
    private $modules = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_modules();
        
        // Register the health check logic
        add_action( 'graphql_register_types', [ $this, 'register_health_check' ] );
    }

    /**
     * Instantiates all modules found in the Modules directory.
     */
    private function load_modules() {
        $modules_dir = __DIR__ . '/Modules';
        if ( ! is_dir( $modules_dir ) ) return;

        foreach ( glob( $modules_dir . '/*.php' ) as $file ) {
            $class_name = __NAMESPACE__ . '\\Modules\\' . basename( $file, '.php' );
            if ( class_exists( $class_name ) ) {
                $this->modules[] = new $class_name();
            }
        }
    }

    /**
     * Returns the health status of all registered modules.
     */
    public function get_module_statuses() {
        $statuses = [];
        foreach ( $this->modules as $module ) {
            if ( method_exists( $module, 'get_status' ) ) {
                $status = $module->get_status();
                $statuses[ basename( str_replace( '\\', '/', get_class( $module ) ) ) ] = $status;
            }
        }
        return $statuses;
    }

    /**
     * Registers a GraphQL field to expose module health status.
     */
    public function register_health_check() {
        register_graphql_object_type( 'POSModuleStatus', [
            'fields' => [
                'module' => [ 'type' => 'String' ],
                'status' => [ 'type' => 'String' ], // READY, ERROR, OFFLINE
                'error'  => [ 'type' => 'String' ],
            ]
        ]);

        register_graphql_field( 'RootQuery', 'posSystemStatus', [
            'type' => [ 'list_of' => 'POSModuleStatus' ],
            'description' => __( 'Reports health of POS backend modules', 'pos-rules' ),
            'resolve' => function() {
                return array_values( $this->get_module_statuses() );
            }
        ]);
    }
}
