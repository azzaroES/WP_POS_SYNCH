<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;
use WC_Shipping_Zones;
use WC_Shipping_Zone;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Shipping Zone Bridges and GraphQL Type Extensions.
 */
class ShippingModule extends AbstractModule {
    
    protected function init() {
        // Register GraphQL Types & Fields
        add_action( 'graphql_register_types', [ $this, 'register_graphql_types' ] );

        // Register POS Shipping Method
        add_filter( 'woocommerce_shipping_methods', [ $this, 'add_pos_shipping_method' ] );
    }

    public function add_pos_shipping_method( $methods ) {
        $methods['pos_bridge_shipping'] = 'POS\WooSync\Modules\POS_Shipping_Method';
        return $methods;
    }

    public function register_graphql_types() {
        register_graphql_object_type( 'POSShippingMethod', [
            'description' => __( 'Shipping method within a zone', 'pos-rules' ),
            'fields' => [
                'id'          => [ 'type' => 'String' ],
                'instanceId'  => [ 'type' => 'Int' ],
                'title'       => [ 'type' => 'String' ],
                'methodTitle' => [ 'type' => 'String' ],
                'enabled'     => [ 'type' => 'Boolean' ],
                'settings'    => [ 'type' => 'String' ], 
            ],
        ]);
        register_graphql_object_type( 'POSShippingZone', [
            'description' => __( 'A shipping zone with its methods', 'pos-rules' ),
            'fields' => [
                'id'      => [ 'type' => 'Int' ],
                'name'    => [ 'type' => 'String' ],
                'order'   => [ 'type' => 'Int' ],
                'methods' => [ 'type' => [ 'list_of' => 'POSShippingMethod' ] ],
            ],
        ]);
        register_graphql_field( 'RootQuery', 'posShippingZones', [
            'type'        => [ 'list_of' => 'POSShippingZone' ],
            'description' => __( 'Gets all WooCommerce shipping zones and their methods', 'pos-rules' ),
            'resolve'     => [ $this, 'resolve_shipping_zones' ]
        ]);
        $this->register_shipping_line_fields();
    }

    public function resolve_shipping_zones() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return [];
        $zones = WC_Shipping_Zones::get_zones();
        $formatted = [];
        $zone_0 = new WC_Shipping_Zone(0);
        $zones[0] = [ 'zone_id' => 0, 'zone_name' => $zone_0->get_zone_name(), 'zone_order' => 0, 'shipping_methods' => $zone_0->get_shipping_methods() ];
        foreach ( $zones as $z ) {
            $methods = [];
            if ( ! empty( $z['shipping_methods'] ) ) {
                foreach ( $z['shipping_methods'] as $m ) {
                    $methods[] = [ 'id' => $m->id, 'instanceId' => $m->instance_id, 'title' => $m->get_title(), 'methodTitle' => $m->method_title, 'enabled' => $m->enabled === 'yes', 'settings' => wp_json_encode( $m->instance_settings ) ];
                }
            }
            $formatted[] = [ 'id' => (int)$z['zone_id'], 'name' => $z['zone_name'], 'order' => (int)$z['zone_order'], 'methods' => $methods ];
        }
        return $formatted;
    }

    private function register_shipping_line_fields() {
        register_graphql_field( 'ShippingLine', 'methodId', [ 
            'type' => 'String', 
            'resolve' => function($l) { return is_object($l) ? (method_exists($l, 'get_method_id') ? $l->get_method_id() : null) : ($l['method_id'] ?? null); } 
        ]);
        register_graphql_field( 'ShippingLine', 'methodTitle', [ 
            'type' => 'String', 
            'resolve' => function($l) { return is_object($l) ? (method_exists($l, 'get_method_title') ? $l->get_method_title() : null) : ($l['method_title'] ?? null); } 
        ]);
        register_graphql_field( 'ShippingLine', 'instanceId', [ 
            'type' => 'Int', 
            'resolve' => function($l) { return is_object($l) ? (method_exists($l, 'get_instance_id') ? (int)$l->get_instance_id() : null) : (int)($l['instance_id'] ?? 0); } 
        ]);
    }
}

/**
 * ============ POS Shipping Method Class ============
 */
add_action( 'woocommerce_shipping_init', function() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) return;

    class POS_Shipping_Method extends \WC_Shipping_Method {
        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'pos_bridge_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = 'Restaurant Service (POS)';
            $this->method_description = 'Take Away, Delivery, Table Service controlled by POS';
            $this->enabled            = "yes";
            $this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
            $this->init();
        }
        function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option( 'title', 'Restaurant Service' );
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }
        public function init_form_fields() { $this->form_fields = array( 'title' => array( 'title' => 'Method Title', 'type' => 'text', 'default' => 'Restaurant Service' ) ); }
        public function calculate_shipping( $package = array() ) {
            $config = get_option( 'pos_restaurant_config' );
            if ( ! $config ) return;
            if ( is_string( $config ) ) $config = json_decode( $config, true );
            $services = isset($config['services']) ? $config['services'] : array();
            if ( ! function_exists( 'WC' ) || is_null( WC() ) || ! isset( WC()->cart ) ) return;
            $subtotal = WC()->cart->get_subtotal();
            $free_threshold = isset($config['freeShippingThreshold']) ? floatval($config['freeShippingThreshold']) : 0;
            $is_free = ($free_threshold > 0 && $subtotal >= $free_threshold);

            foreach ( (array)$services as $s ) {
                if ( empty($s['enabled']) ) continue;
                $cost = 0; $label = $s['label']; $rate_id = '';
                if ($s['id'] === 'TAKE_OUT') { $rate_id = 'pos_takeaway'; }
                elseif ($s['id'] === 'DELIVERY') { $rate_id = 'pos_delivery'; $cost = floatval($s['fee'] ?? 0); if ($is_free) { $cost = 0; $label .= ' (FREE)'; } }
                elseif ($s['id'] === 'DINE_IN') { $rate_id = 'pos_tableservice'; }
                else { $rate_id = 'pos_svc_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $s['id'])); }
                $this->add_rate( [ 'id' => $rate_id, 'label' => $label, 'cost' => $cost ] );
            }
        }
    }
});
