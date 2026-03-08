<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Custom Checkout Fields, Field Visibility, and Shipping UI Transformation.
 */
class CheckoutModule extends AbstractModule {

    protected function init() {
        // UI Injection
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_custom_checkout_fields' ] );
        
        // Field Filtering
        add_filter( 'woocommerce_checkout_fields' , [ $this, 'apply_field_visibility' ], 9999 );
        
        // Saving Meta
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_order_meta' ] );
        
        // Validation
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_fields' ] );

        // Cache/Session Clearing
        add_action( 'woocommerce_checkout_init', [ $this, 'clear_shipping_sessions' ] );
    }

    /**
     * Renders custom fields (Table, Time) and injects JS for Shipping Dropdown.
     */
    public function render_custom_checkout_fields( $checkout ) {
        $config = $this->get_config();
        $svcs = isset($config['services']) ? $config['services'] : [];
        $enabled_svcs = array_filter($svcs, function($s) { return !empty($s['enabled']); });

        $merged_vis = [];
        foreach ($enabled_svcs as $svc) {
            $fv = $svc['fieldVisibility'] ?? [];
            foreach ($fv as $k => $v) { 
                if ($v) $merged_vis[$k] = true; 
                elseif (!isset($merged_vis[$k])) $merged_vis[$k] = false; 
            }
        }

        $show_table = $merged_vis['table'] ?? true;
        $show_time  = $merged_vis['time'] ?? true;
        
        $tables = $config['tables'] ?? ['1', '2', '3', '4', '5'];
        $table_options = [];
        foreach($tables as $t) { $table_options[$t] = 'Table ' . $t; }
        $time_slots = $this->get_available_time_slots($config);

        echo '<div id="pos_custom_checkout_fields" class="woocommerce-billing-fields" style="clear: both; width: 100% !important; display: block !important;">';
        echo '<h3>' . esc_html__( 'Restaurant Service Details', 'woocommerce' ) . '</h3>';
        echo '<div class="woocommerce-billing-fields__field-wrapper">';

        // Time Slot Selection
        echo '<div id="pos_time_slot_container" class="form-row-wide" style="clear: both; width: 100% !important; ' . ($show_time ? '' : 'display:none;') . '">';
        woocommerce_form_field( 'pos_order_time_slot', array(
            'type'          => 'select',
            'class'         => array('form-row-wide'),
            'label'         => 'Select Pickup / Reservation Time',
            'required'      => false,
            'options'       => array('' => 'Choose a time...') + $time_slots
        ), $checkout->get_value( 'pos_order_time_slot' ));
        echo '</div>';

        // Table Selection
        echo '<div id="pos_table_field_container" class="form-row-wide" style="clear: both; width: 100% !important; ' . ($show_table ? '' : 'display:none;') . '">';
        woocommerce_form_field( 'pos_table_number', array(
            'type'          => 'select',
            'class'         => array('form-row-wide'),
            'label'         => 'Select Your Table',
            'required'      => false,
            'options'       => $table_options
        ), $checkout->get_value( 'pos_table_number' ));
        echo '</div>';
        echo '</div>';
        echo '</div>';
        ?>
        <script type="text/javascript">
            jQuery(function($){
                var config = <?php echo wp_json_encode( $config ); ?>;
                
                function transformShippingToDropdown() {
                    if ($('#pos_shipping_dropdown').length) return;
                    var $inputs = $('input.shipping_method');
                    if (!$inputs.length) return;

                    if (!$('#pos-blinker-style').length) {
                        $('head').append('<style id="pos-blinker-style">\
                            .pos-blinker { width: 10px; height: 10px; background: #f6a623; border-radius: 50%; display: inline-block; margin-right: 8px; box-shadow: 0 0 5px rgba(246, 166, 35, 0.6); animation: pos-pulse-blinker 1.5s infinite; }\
                            @keyframes pos-pulse-blinker { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.3); opacity: 0.6; } 100% { transform: scale(1); opacity: 1; } }\
                            #pos_shipping_dropdown { width: 100% !important; }\
                        </style>');
                    }

                    var $container = $('.woocommerce-shipping-methods');
                    var customLabel = config.checkoutServiceLabel || 'Order Type';
                    var $select = $('<select id="pos_shipping_dropdown" class="select"></select>');
                    var currentSelection = $inputs.filter(':checked').val();
                    
                    $inputs.each(function(){
                        var $input = $(this);
                        var val = $input.val();
                        var $label = $('label[for="'+$input.attr('id')+'"]');
                        var labelText = $label.length ? $label.text().trim() : val;
                        $select.append('<option value="'+val+'" '+(val === currentSelection ? 'selected' : '')+'>'+labelText+'</option>');
                        $input.hide(); $label.hide(); $input.closest('li').hide();
                    });

                    $container.find('> p, > label:first-child').hide(); 
                    $container.prepend($select);
                    var $header = $('tr.shipping th, .woocommerce-shipping-totals th');
                    if ($header.length) $header.html('<span class="pos-blinker"></span> ' + customLabel);

                    $select.on('change', function(){
                        $('input.shipping_method[value="'+$(this).val()+'"]').prop('checked', true).trigger('change');
                    });
                }

                function updateVisibility() {
                    var selected = $('input.shipping_method:checked').val() || '';
                    var mappedType = '';
                    if (selected.indexOf('pos_takeaway') !== -1) mappedType = 'TAKE_OUT';
                    else if (selected.indexOf('pos_delivery') !== -1) mappedType = 'DELIVERY';
                    else if (selected.indexOf('pos_tableservice') !== -1) mappedType = 'DINE_IN';
                    else if (selected.indexOf('pos_svc_') !== -1) mappedType = selected.replace('pos_bridge_shipping:', '').replace('pos_svc_', '').toUpperCase();

                    var activeService = (config.services || []).find(s => s.id === mappedType && s.enabled);
                    if (activeService) {
                        $('#pos_custom_checkout_fields').show();
                        var vis = activeService.fieldVisibility || {};
                        var req = activeService.fieldRequired || {};
                        
                        if (vis.time !== false) {
                            $('#pos_time_slot_container').show();
                            $('#pos_order_time_slot_field label .required').remove();
                            if (req.time) $('#pos_order_time_slot_field label').append(' <abbr class="required">*</abbr>');
                        } else $('#pos_time_slot_container').hide();

                        if (vis.table !== false) {
                            $('#pos_table_field_container').show();
                            $('#pos_table_number_field label .required').remove();
                            if (req.table) $('#pos_table_number_field label').append(' <abbr class="required">*</abbr>');
                        } else $('#pos_table_field_container').hide();
                    } else $('#pos_custom_checkout_fields').hide();

                    if (!$('#pos_detected_order_type').length) $('form.checkout').append('<input type="hidden" id="pos_detected_order_type" name="pos_detected_order_type" />');
                    $('#pos_detected_order_type').val(mappedType);
                }

                $(document.body).on('updated_checkout change', 'input[name^="shipping_method"], #pos_shipping_dropdown', function(){
                    transformShippingToDropdown();
                    updateVisibility();
                });

                transformShippingToDropdown();
                updateVisibility();
            });
        </script>
        <?php
    }

    /**
     * Filters standard checkout fields based on the selected POS service.
     */
    public function apply_field_visibility( $fields ) {
        if ( ! function_exists( 'WC' ) || is_null( WC() ) || ! isset( WC()->session ) ) return $fields;
        
        $config = $this->get_config();
        $chosen_method = WC()->session->get('chosen_shipping_methods')[0] ?? '';
        if (empty($chosen_method) && isset($_POST['shipping_method'])) $chosen_method = $_POST['shipping_method'][0];

        $mappedType = '';
        if (strpos($chosen_method, 'pos_takeaway') !== false) $mappedType = 'TAKE_OUT';
        elseif (strpos($chosen_method, 'pos_delivery') !== false) $mappedType = 'DELIVERY';
        elseif (strpos($chosen_method, 'pos_tableservice') !== false) $mappedType = 'DINE_IN';
        
        $active_service = null;
        foreach ( ($config['services'] ?? []) as $s ) {
            if ( !empty($s['enabled']) && $s['id'] === $mappedType ) { $active_service = $s; break; }
        }

        if ( ! $active_service ) return $fields;

        $vis = $active_service['fieldVisibility'] ?? [];
        $req = $active_service['fieldRequired'] ?? [];

        $map = [
            'firstName' => 'billing_first_name', 'lastName' => 'billing_last_name',
            'phone' => 'billing_phone', 'email' => 'billing_email',
            'address1' => 'billing_address_1', 'city' => 'billing_city',
            'postcode' => 'billing_postcode'
        ];

        foreach ( $map as $pos_key => $woo_key ) {
            if ( isset( $vis[$pos_key] ) && ! $vis[$pos_key] ) {
                unset( $fields['billing'][$woo_key] );
            } elseif ( isset( $req[$pos_key] ) ) {
                $fields['billing'][$woo_key]['required'] = (bool) $req[$pos_key];
            }
        }

        return $fields;
    }

    public function save_order_meta( $order_id ) {
        if ( ! empty( $_POST['pos_detected_order_type'] ) ) {
            update_post_meta( $order_id, '_pos_type', sanitize_text_field( $_POST['pos_detected_order_type'] ) );
        }
        if ( ! empty( $_POST['pos_table_number'] ) ) {
            update_post_meta( $order_id, '_pos_table', sanitize_text_field( $_POST['pos_table_number'] ) );
        }
        if ( ! empty( $_POST['pos_order_time_slot'] ) ) {
            update_post_meta( $order_id, '_pos_pickup_time', sanitize_text_field( $_POST['pos_order_time_slot'] ) );
        }
    }

    public function validate_checkout_fields() {
        $config = $this->get_config();
        $order_type = $_POST['pos_detected_order_type'] ?? '';
        $active_service = null;
        foreach ( ($config['services'] ?? []) as $s ) {
            if ( !empty($s['enabled']) && $s['id'] === $order_type ) { $active_service = $s; break; }
        }
        if ( ! $active_service ) return;

        $req = $active_service['fieldRequired'] ?? [];
        if ( !empty($req['table']) && empty($_POST['pos_table_number']) ) wc_add_notice( '<strong>Table Number</strong> is required.', 'error' );
        if ( !empty($req['time']) && empty($_POST['pos_order_time_slot']) ) wc_add_notice( '<strong>Time Slot</strong> is required.', 'error' );
    }

    public function clear_shipping_sessions() {
        if ( isset( WC()->session ) ) WC()->session->set( 'shipping_method_counts', [] );
    }

    private function get_available_time_slots( $config ) {
        $interval = $config['intervals']['timeframe'] ?? 30;
        $preorder_days = $config['preorderDays'] ?? 0;
        $now = current_time('timestamp');
        $slots = [];

        for ( $i = 0; $i <= $preorder_days; $i++ ) {
            $ts = $now + ($i * 86400);
            $day = strtolower(date('l', $ts));
            $sessions = $config['openingHours'][$day] ?? [];
            if ( empty($sessions) ) continue;

            $prefix = ($i === 0) ? "" : date('D d M', $ts) . " - ";
            $base = date('Y-m-d', $ts);

            foreach ( $sessions as $s ) {
                $start = strtotime($base . ' ' . $s['open']);
                $end = strtotime($base . ' ' . $s['close']);
                $curr = $start;
                while ( $curr <= $end ) {
                    if ( $curr > ($now + 600) ) {
                        $time = date('H:i', $curr);
                        $slots[$prefix . $time] = $prefix . $time;
                    }
                    $curr += ($interval * 60);
                }
            }
        }
        return $slots;
    }

    private function get_config() {
        $config = get_option( 'pos_restaurant_config' );
        if ( ! $config ) return [];
        return is_string( $config ) ? json_decode( $config, true ) : $config;
    }
}
