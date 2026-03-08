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
    }

    /**
     * Registers all Shipping related types and resolvers for WPGraphQL.
     */
    public function register_graphql_types() {
        // POS Shipping Method Object
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

        // POS Shipping Zone Object
        register_graphql_object_type( 'POSShippingZone', [
            'description' => __( 'A shipping zone with its methods', 'pos-rules' ),
            'fields' => [
                'id'      => [ 'type' => 'Int' ],
                'name'    => [ 'type' => 'String' ],
                'order'   => [ 'type' => 'Int' ],
                'methods' => [ 'type' => [ 'list_of' => 'POSShippingMethod' ] ],
            ],
        ]);

        // Root Query: posShippingZones
        register_graphql_field( 'RootQuery', 'posShippingZones', [
            'type'        => [ 'list_of' => 'POSShippingZone' ],
            'description' => __( 'Gets all WooCommerce shipping zones and their methods', 'pos-rules' ),
            'resolve'     => [ $this, 'resolve_shipping_zones' ]
        ]);

        // Extension: ShippingLine Fields
        $this->register_shipping_line_fields();
    }

    /**
     * Resolves the consolidated shipping zones list.
     */
    public function resolve_shipping_zones() {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) return [];

        $zones = WC_Shipping_Zones::get_zones();
        $formatted = [];
        
        // Add "Rest of the World" zone (id 0)
        $zone_0 = new WC_Shipping_Zone(0);
        $zones[0] = [
            'zone_id'      => 0,
            'zone_name'    => $zone_0->get_zone_name(),
            'zone_order'   => 0,
            'shipping_methods' => $zone_0->get_shipping_methods(),
        ];

        foreach ( $zones as $z ) {
            $methods = [];
            if ( ! empty( $z['shipping_methods'] ) ) {
                foreach ( $z['shipping_methods'] as $m ) {
                    $methods[] = [
                        'id'          => $m->id,
                        'instanceId'  => $m->instance_id,
                        'title'       => $m->get_title(),
                        'methodTitle' => $m->method_title,
                        'enabled'     => $m->enabled === 'yes',
                        'settings'    => wp_json_encode( $m->instance_settings ),
                    ];
                }
            }
            $formatted[] = [
                'id'      => (int)$z['zone_id'],
                'name'    => $z['zone_name'],
                'order'   => (int)$z['zone_order'],
                'methods' => $methods,
            ];
        }

        // Sort by zone order
        usort( $formatted, function($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $formatted;
    }

    /**
     * Adds missing fields to the standard ShippingLine type in WPGraphQL.
     */
    private function register_shipping_line_fields() {
        register_graphql_field( 'ShippingLine', 'methodId', [
            'type'        => 'String',
            'description' => __( 'The method ID of the shipping line', 'pos-rules' ),
            'resolve'     => function( $shipping_line ) {
                if ( is_object( $shipping_line ) ) {
                    return method_exists( $shipping_line, 'get_method_id' ) ? $shipping_line->get_method_id() : null;
                }
                return ( is_array( $shipping_line ) && isset( $shipping_line['method_id'] ) ) ? $shipping_line['method_id'] : null;
            }
        ]);

        register_graphql_field( 'ShippingLine', 'instanceId', [
            'type'        => 'Int',
            'description' => __( 'The instance ID of the shipping line', 'pos-rules' ),
            'resolve'     => function( $shipping_line ) {
                if ( is_object( $shipping_line ) ) {
                    $val = method_exists( $shipping_line, 'get_instance_id' ) ? $shipping_line->get_instance_id() : null;
                    return is_numeric( $val ) ? (int) $val : $val;
                }
                $val = ( is_array( $shipping_line ) && isset( $shipping_line['instance_id'] ) ) ? $shipping_line['instance_id'] : null;
                return is_numeric( $val ) ? (int) $val : $val;
            }
        ]);
    }
}
