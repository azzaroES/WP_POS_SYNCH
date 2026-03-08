<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Product Rules (_pos_rules) and related GraphQL resolvers.
 */
class RulesModule extends AbstractModule {
    
    /** @var array Local cache for decoded rules to prevent DB thrashing */
    private static $rules_cache = [];

    protected function init() {
        // Register Meta & Settings
        add_action( 'init', [ $this, 'register_product_meta' ] );
        add_action( 'admin_init', [ $this, 'register_product_admin_fields' ] );
        
        // GraphQL Fields
        add_action( 'graphql_register_types', [ $this, 'register_graphql_fields' ] );

        // Storefront UI
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'inject_modifiers_ui' ] );
        add_filter( 'woocommerce_loop_add_to_cart_link', [ $this, 'override_loop_add_to_cart' ], 10, 2 );

        // Cart Logic
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_modifiers' ], 10, 3 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_modifiers' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'calculate_cart_totals' ], 10, 1 );

        // Order Logic
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_line_item_modifiers' ], 10, 4 );
    }

    /**
     * Registers the high-capacity JSON blob meta field for products.
     */
    public function register_product_meta() {
        register_meta( 'post', '_pos_rules', [
            'object_subtype'    => 'product',
            'type'              => 'string',
            'description'       => 'POS Modifier Rules (JSON)',
            'single'            => true,
            'show_in_rest'      => true,
            'auth_callback'     => function() {
                return current_user_can( 'edit_posts' );
            }
        ]);
    }

    /**
     * Registers the posRules field on the Product type in WPGraphQL.
     */
    public function register_graphql_fields() {
        register_graphql_field( 'Product', 'posRules', [
            'type' => 'String',
            'description' => __( 'The POS rules for the product', 'pos-rules' ),
            'resolve' => [ $this, 'resolve_pos_rules' ]
        ]);

        register_graphql_field( 'RootQuery', 'posRestaurantConfig', [
            'type'        => 'String',
            'description' => __( 'Gets unified POS restaurant configuration', 'pos-rules' ),
            'resolve'     => function() {
                $config = get_option( 'pos_restaurant_config' );
                return empty($config) ? null : wp_json_encode( $config );
            }
        ]);
    }

    /**
     * Internal helper to get decoded rules consistently.
     */
    private function get_decoded_rules( $post_id ) {
        if ( isset( self::$rules_cache[ $post_id ] ) ) {
            return self::$rules_cache[ $post_id ];
        }

        $rules_json = get_post_meta( $post_id, '_pos_rules', true );
        $rules = ! empty( $rules_json ) ? json_decode( $rules_json, true ) : [];
        if ( ! is_array( $rules ) ) $rules = [];

        self::$rules_cache[ $post_id ] = $rules;
        return $rules;
    }

    /**
     * Optimised resolver with static caching.
     */
    public function resolve_pos_rules( $product ) {
        if ( ! $product || ! isset( $product->databaseId ) ) return null;
        $rules = $this->get_decoded_rules( $product->databaseId );
        return wp_json_encode( $rules );
    }

    /**
     * Registers the Product Admin UI fields for modifier rules in a dedicated tab.
     */
    public function register_product_admin_fields() {
        add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
            $tabs['pos_rules'] = [
                'label'    => __( 'POS Rules', 'pos-rules' ),
                'target'   => 'pos_rules_options',
                'class'    => [ 'show_if_simple', 'show_if_variable' ],
                'priority' => 100,
            ];
            return $tabs;
        });

        add_action( 'woocommerce_product_data_panels', function() {
            global $post;
            $rules_json = get_post_meta( $post->ID, '_pos_rules', true );
            $stepper_enabled = get_post_meta( $post->ID, '_pos_stepper_wizard_enabled', true ) === 'yes';
            
            echo '<div id="pos_rules_options" class="panel woocommerce_options_panel hidden">';
            echo '<div class="options_group">';
            wp_nonce_field( 'pos_rules_save', 'pos_rules_nonce' );
            
            woocommerce_wp_textarea_input([
                'id'          => '_pos_rules',
                'label'       => __( 'Rules JSON', 'pos-rules' ),
                'placeholder' => '{ "groups": [...] }',
                'description' => __( 'Modifier rules in JSON format (usually synced from POS).', 'pos-rules' ),
                'desc_tip'    => true,
                'style'       => 'height: 300px; font-family: monospace; background: #f8fafc;'
            ]);

            woocommerce_wp_checkbox([
                'id'          => '_pos_stepper_wizard_enabled',
                'label'       => __( 'Enable Stepper Wizard', 'pos-rules' ),
                'description' => __( 'Convert the addon selector into a step-by-step wizard format.', 'pos-rules' ),
                'value'       => $stepper_enabled ? 'yes' : 'no'
            ]);
            echo '</div>';
            echo '</div>';
        });

        add_action( 'woocommerce_process_product_meta', function( $post_id ) {
            if ( ! isset( $_POST['pos_rules_nonce'] ) || ! wp_verify_nonce( $_POST['pos_rules_nonce'], 'pos_rules_save' ) ) return;
            
            if ( isset( $_POST['_pos_rules'] ) ) {
                update_post_meta( $post_id, '_pos_rules', $_POST['_pos_rules'] );
            }
            $stepper = isset( $_POST['_pos_stepper_wizard_enabled'] ) ? 'yes' : 'no';
            update_post_meta( $post_id, '_pos_stepper_wizard_enabled', $stepper );
        });
    }

    /**
     * Injects the storefront modifiers UI.
     */
    public function inject_modifiers_ui() {
        global $product;
        if ( ! $product ) return;
        
        $product_id = $product->get_id();
        $data = $this->get_decoded_rules( $product_id );
        if ( empty( $data ) || ! isset( $data['groups'] ) || empty( $data['groups'] ) ) return;

        $stepper_enabled = get_post_meta( $product_id, '_pos_stepper_wizard_enabled', true ) === 'yes';

        // Styles and HTML logic from pos-woo-rules-sync.php (lines 151-536)
        // [TRUNCATED for brevity in chunk, but I'll write the full implementation]
        ?>
        <style>
            .pos-modifiers-container { margin: 25px 0 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important; clear: both !important; }
            .pos-modifier-group { margin-bottom: 20px !important; padding: 15px !important; background: #fff !important; border: 1.5px solid #eee !important; border-radius: 12px !important; display: <?php echo ($stepper_enabled ? 'none' : 'block'); ?> !important; }
            .pos-modifier-group:first-of-type { display: block !important; }
            .pos-modifier-group h4 { margin: 0 0 12px 0 !important; font-size: 1.1rem !important; font-weight: 700 !important; color: #222 !important; }
            .pos-mod-badge { font-size: 10px !important; padding: 3px 8px !important; border-radius: 4px !important; font-weight: 700 !important; text-transform: uppercase !important; margin-left: 5px !important; }
            .pos-modifier-option { display: flex !important; align-items: center !important; justify-content: space-between !important; gap: 10px !important; margin-bottom: 6px !important; padding: 8px 12px !important; border: 1px solid #f0f0f0 !important; border-radius: 8px !important; transition: all 0.2s !important; }
            .pos-modifier-option.is-selected { border-color: #3b82f6 !important; background: #eff6ff !important; }
            .pos-mod-controls { display: flex !important; align-items: center !important; gap: 8px !important; }
            .pos-mod-price { font-size: 13px !important; font-weight: 700 !important; color: #3b82f6 !important; }
            .pos-mod-stepper { display: flex !important; align-items: center !important; background: #fff !important; border: 1px solid #cbd5e1 !important; border-radius: 6px !important; padding: 2px !important; gap: 4px !important; }
            .pos-mod-stepper button { width: 28px !important; height: 28px !important; border: none !important; background: #f1f5f9 !important; border-radius: 4px !important; cursor: pointer !important; font-size: 18px !important; font-weight: 700 !important; line-height: 1 !important; color: #475569 !important; padding: 0 !important; display: flex !important; align-items: center !important; justify-content: center !important; transition: all 0.2s !important; }
            .pos-mod-stepper button:hover:not(:disabled) { background: #3b82f6 !important; color: #fff !important; }
            .pos-mod-stepper button:disabled { opacity: 0.3 !important; cursor: not-allowed !important; }
            .pos-mod-stepper input { width: 25px !important; text-align: center !important; border: none !important; background: transparent !important; font-size: 14px !important; font-weight: 800 !important; padding: 0 !important; margin: 0 !important; box-shadow: none !important; pointer-events: none !important; color: #1e293b !important; }
            .pos-modifier-option.is-disabled-option { opacity: 0.4 !important; filter: grayscale(1) !important; cursor: not-allowed !important; }
            .pos-mod-error { color: #ef4444 !important; font-size: 12px !important; margin-top: 5px !important; display: none !important; font-weight: 600 !important; }
            .pos-wizard-nav { display: flex !important; justify-content: space-between !important; align-items: center !important; margin-top: 20px !important; }
            .pos-wizard-btn { background: #1e293b !important; color: #fff !important; padding: 12px 24px !important; font-size: 14px !important; border: none !important; border-radius: 10px !important; cursor: pointer !important; font-weight: 700 !important; min-width: 120px !important; transition: all 0.2s !important; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1) !important; }
            .pos-wizard-btn:hover:not(:disabled) { background: #334155 !important; transform: translateY(-1px) !important; }
            .pos-wizard-btn:disabled { background: #cbd5e1 !important; cursor: not-allowed !important; box-shadow: none !important; }
            .pos-wizard-btn.btn-prev { background: #64748b !important; }
            .pos-wizard-step-info { text-align: center !important; color: #475569 !important; font-size: 13px !important; margin-bottom: 15px !important; font-weight: 800 !important; text-transform: uppercase !important; letter-spacing: 1px !important; }
            .pos-mod-check { width: 20px !important; height: 20px !important; margin: 0 !important; cursor: pointer !important; }
        </style>
        <div class="pos-modifiers-container" data-stepper="<?php echo ($stepper_enabled ? '1' : '0'); ?>" data-total-steps="<?php echo count($data['groups']); ?>">
            <?php if ($stepper_enabled): ?>
                <div class="pos-wizard-step-info">Step <span class="pos-curr-step">1</span> of <?php echo count($data['groups']); ?></div>
            <?php endif; ?>

            <?php
            $step = 1;
            foreach ( $data['groups'] as $group ) {
                $gid = esc_attr($group['id']);
                $min = intval($group['minSelections'] ?? 0);
                $max = intval($group['maxSelections'] ?? 99);
                $allowMultiple = isset($group['allowMultiple']) && $group['allowMultiple'];
                
                echo '<div class="pos-modifier-group" data-gid="' . $gid . '" data-min="' . $min . '" data-max="' . $max . '" data-multi="' . ($allowMultiple ? '1' : '0') . '">';
                echo '<h4>' . esc_html($group['name']);
                if ($min > 0) echo ' <span class="pos-mod-badge" style="background:#fee2e2; color:#ef4444;">Min ' . $min . '</span>';
                if ($max < 99 && $max > 0) echo ' <span class="pos-mod-badge" style="background:#dcfce7; color:#16a34a;">Max ' . $max . '</span>';
                echo '</h4>';
                
                foreach ( $group['options'] as $opt ) {
                    $price = floatval($opt['priceOverride']);
                    $optName = esc_attr($opt['optionName']);
                    
                    echo '<div class="pos-modifier-option">';
                    echo '<span>' . esc_html($opt['optionName']) . '</span>';
                    echo '<div class="pos-mod-controls">';
                    if ($price > 0) echo '<span class="pos-mod-price">+' . wc_price($price) . '</span>';
                    
                    if ($allowMultiple) {
                        echo '<div class="pos-mod-stepper">';
                        echo '<button type="button" class="pos-minus">-</button>';
                        echo '<input type="number" value="0" min="0" name="pos_mod_qty[' . $gid . '][' . $optName . ']" readonly>';
                        echo '<button type="button" class="pos-plus">+</button>';
                        echo '</div>';
                    } else {
                        $inputType = ($max == 1) ? 'radio' : 'checkbox';
                        $inputName = 'pos_mod[' . $gid . ']' . ($inputType == 'checkbox' ? '[]' : '');
                        echo '<input type="' . $inputType . '" name="' . $inputName . '" value="' . $optName . '" class="pos-mod-check">';
                    }
                    echo '</div>';
                    echo '</div>';
                }
                echo '<div class="pos-mod-error">Selection limit reached</div>';
                
                if ($stepper_enabled) {
                    echo '<div class="pos-wizard-nav">';
                    echo ($step > 1) ? '<button type="button" class="pos-wizard-btn btn-prev">Previous</button>' : '<div></div>';
                    echo ($step < count($data['groups'])) ? '<button type="button" class="pos-wizard-btn btn-next">Next</button>' : '<div></div>';
                    echo '</div>';
                }
                echo '</div>';
                $step++;
            }
            ?>
        </div>
        <script>
        (function() {
            const initPosWizard = () => {
                const container = document.querySelector('.pos-modifiers-container');
                if (!container) return;
                const cartBtn = document.querySelector('.woocommerce-variation-add-to-cart .single_add_to_cart_button, form.cart .single_add_to_cart_button');
                const isStepper = container.dataset.stepper === '1';
                let currentStep = Number(container.dataset.currentStep || 1);
                const groups = Array.from(container.querySelectorAll('.pos-modifier-group'));
                const totalSteps = groups.length;
                const validate = () => {
                    let allValid = true;
                    groups.forEach((group, idx) => {
                        const min = parseInt(group.dataset.min) || 0;
                        const max = parseInt(group.dataset.max) || 99;
                        const isMulti = group.dataset.multi === '1';
                        let total = 0;
                        if (isMulti) {
                            group.querySelectorAll('input[type="number"]').forEach(i => total += parseInt(i.value || 0));
                            const reachedMax = total >= max;
                            group.querySelectorAll('.pos-modifier-option').forEach(row => {
                                const input = row.querySelector('input[type="number"]');
                                if (reachedMax && parseInt(input.value || 0) === 0) row.classList.add('is-disabled-option');
                                else row.classList.remove('is-disabled-option');
                            });
                            group.querySelectorAll('.pos-plus').forEach(b => b.disabled = reachedMax);
                            group.querySelectorAll('.pos-minus').forEach(b => b.disabled = parseInt(b.parentElement.querySelector('input').value) <= 0);
                        } else {
                            const checked = group.querySelectorAll('input:checked');
                            total = checked.length;
                            const reachedMax = total >= max;
                            group.querySelectorAll('input').forEach(i => {
                                if (!i.checked && i.type !== 'radio') i.disabled = reachedMax;
                                else i.disabled = false;
                                const row = i.closest('.pos-modifier-option');
                                if (reachedMax && !i.checked && i.type !== 'radio') row.classList.add('is-disabled-option');
                                else row.classList.remove('is-disabled-option');
                            });
                        }
                        group.querySelectorAll('.pos-modifier-option').forEach(row => {
                            const input = row.querySelector('input');
                            const isSelected = (input.type === 'number') ? parseInt(input.value) > 0 : input.checked;
                            row.classList.toggle('is-selected', isSelected);
                        });
                        if (total < min) allValid = false;
                        const error = group.querySelector('.pos-mod-error');
                        if (total >= max && max < 99) { error.style.display = 'block'; error.textContent = `Maximum of ${max} reached`; }
                        else { error.style.display = 'none'; }
                        if (isStepper && (idx + 1 === currentStep)) {
                            const nextBtn = group.querySelector('.btn-next');
                            if (nextBtn) nextBtn.disabled = (total < min);
                        }
                    });
                    if (cartBtn) {
                        if (isStepper) {
                            const isFinalStep = currentStep === totalSteps || totalSteps === 0;
                            cartBtn.style.setProperty('display', isFinalStep ? 'block' : 'none', 'important');
                            cartBtn.disabled = !isFinalStep || !allValid;
                        } else { cartBtn.disabled = !allValid; }
                    }
                };
                const updateWizard = () => {
                    if (!isStepper) return;
                    container.dataset.currentStep = currentStep;
                    groups.forEach((g, idx) => { g.style.setProperty('display', (idx + 1 === currentStep) ? 'block' : 'none', 'important'); });
                    const info = container.querySelector('.pos-curr-step');
                    if (info) info.textContent = currentStep;
                    validate();
                };
                if (container.dataset.posEventsAttached !== '1') {
                    container.dataset.posEventsAttached = '1';
                    container.addEventListener('change', (e) => e.stopPropagation(), true);
                    container.addEventListener('input', (e) => e.stopPropagation(), true);
                    container.addEventListener('click', function(e) {
                        const isPlus = e.target.closest('.pos-plus');
                        const isMinus = e.target.closest('.pos-minus');
                        const isNext = e.target.closest('.btn-next');
                        const isPrev = e.target.closest('.btn-prev');
                        const isOptionRow = e.target.closest('.pos-modifier-option');
                        if (isPlus || isMinus) {
                            e.preventDefault(); e.stopPropagation();
                            const input = (isPlus || isMinus).parentElement.querySelector('input');
                            const val = parseInt(input.value || 0);
                            input.value = isPlus ? val + 1 : Math.max(0, val - 1);
                            validate(); return;
                        }
                        if (isNext) { e.preventDefault(); e.stopPropagation(); currentStep++; updateWizard(); return; }
                        if (isPrev) { e.preventDefault(); e.stopPropagation(); currentStep--; updateWizard(); return; }
                        if (isOptionRow) {
                            const input = isOptionRow.querySelector('input');
                            if (input && input.type !== 'number' && !input.disabled) {
                                e.stopPropagation();
                                if (e.target !== input) { if (input.type === 'radio') input.checked = true; else input.checked = !input.checked; }
                                validate();
                            }
                        }
                    }, true);
                }
                if (isStepper) updateWizard(); else validate();
            };
            if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", initPosWizard);
            else initPosWizard();
            const formObserver = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    if (m.type === 'childList') {
                        const hasMod = Array.from(m.addedNodes).some(n => n.nodeType === 1 && (n.classList.contains('pos-modifiers-container') || n.querySelector('.pos-modifiers-container')));
                        if (hasMod) { setTimeout(initPosWizard, 50); break; }
                    }
                }
            });
            const mainForm = document.querySelector('form.cart');
            if (mainForm) {
                formObserver.observe(mainForm, { childList: true, subtree: true });
                if (typeof jQuery !== 'undefined') { jQuery(mainForm).on('found_variation updated_wc_div', () => setTimeout(initPosWizard, 50)); }
            }
        })();
        </script>
        <?php
    }

    /**
     * Overrides the loop add to cart link for products with modifiers.
     */
    public function override_loop_add_to_cart( $html, $product ) {
        if ( $product->get_type() === 'simple' ) {
            $data = $this->get_decoded_rules( $product->get_id() );
            if ( ! empty( $data ) && isset($data['groups']) && !empty($data['groups']) ) {
                return sprintf(
                    '<a href="%s" data-product_id="%d" class="button product_type_simple add_to_cart_button alt">%s</a>',
                    esc_url( $product->get_permalink() ),
                    $product->get_id(),
                    esc_html__( 'Select Options', 'woocommerce' )
                );
            }
        }
        return $html;
    }

    /**
     * Adds modifier data to cart item.
     */
    public function add_cart_item_modifiers( $cart_item_data, $product_id, $variation_id ) {
        $mods = [];
        if ( isset( $_POST['pos_mod'] ) ) {
            foreach ( $_POST['pos_mod'] as $gid => $val ) {
                if ( is_array($val) ) {
                    foreach ($val as $v) $mods[] = ['group_id' => $gid, 'name' => $v, 'qty' => 1];
                } else {
                    $mods[] = ['group_id' => $gid, 'name' => $val, 'qty' => 1];
                }
            }
        }
        if ( isset( $_POST['pos_mod_qty'] ) ) {
            foreach ( $_POST['pos_mod_qty'] as $gid => $opts ) {
                foreach ( $opts as $name => $qty ) {
                    if ( intval($qty) > 0 ) $mods[] = ['group_id' => $gid, 'name' => $name, 'qty' => intval($qty)];
                }
            }
        }
        if ( isset( $_POST['pos_rules_selections'] ) && ! empty( $_POST['pos_rules_selections'] ) ) {
            $headless_mods = json_decode( stripslashes( $_POST['pos_rules_selections'] ), true );
            if ( is_array( $headless_mods ) ) {
                foreach ( $headless_mods as $mod ) {
                    $mods[] = [ 'group_id' => $mod['group_id'], 'name' => $mod['name'], 'qty' => intval( $mod['qty'] ), 'price' => floatval( $mod['price'] ) ];
                }
            }
        }
        if ( ! empty($mods) ) $cart_item_data['pos_modifiers_v2'] = $mods;
        return $cart_item_data;
    }

    /**
     * Displays modifiers in cart.
     */
    public function display_cart_item_modifiers( $item_data, $cart_item ) {
        if ( isset( $cart_item['pos_modifiers_v2'] ) ) {
            $product_id = $cart_item['product_id'];
            $data = $this->get_decoded_rules( $product_id );
            $groups = [];
            if ( ! empty( $data ) && isset($data['groups']) ) {
                foreach ( $data['groups'] as $g ) $groups[(string)$g['id']] = $g['name'];
            }
            foreach ( $cart_item['pos_modifiers_v2'] as $mod ) {
                $key = isset($groups[(string)$mod['group_id']]) ? $groups[(string)$mod['group_id']] : 'Add-on';
                $item_data[] = [ 'key' => $key, 'value' => $mod['name'] . ($mod['qty'] > 1 ? ' (x' . $mod['qty'] . ')' : '') ];
            }
        }
        return $item_data;
    }

    /**
     * Intercepts price calculation for modifiers.
     */
    public function calculate_cart_totals( $cart ) {
        if ( ( is_admin() && ! defined( 'DOING_AJAX' ) ) || ! empty( $GLOBALS['pos_calculating_totals'] ) ) return;
        $GLOBALS['pos_calculating_totals'] = true;
        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['pos_modifiers_v2'] ) ) {
                $extra_price = 0;
                $product_id = $cart_item['product_id'];
                $data = $this->get_decoded_rules( $product_id );
                if ( $data && isset($data['groups']) ) {
                    foreach ( $cart_item['pos_modifiers_v2'] as $mod ) {
                        foreach ( $data['groups'] as $group ) {
                            if ( (string)$group['id'] === (string)$mod['group_id'] ) {
                                foreach ( $group['options'] as $opt ) {
                                    if ( $opt['optionName'] === $mod['name'] ) {
                                        $extra_price += floatval($opt['priceOverride']) * intval($mod['qty']);
                                    }
                                }
                            }
                        }
                    }
                }
                $cart_item['data']->set_price( $cart_item['data']->get_price() + $extra_price );
            }
        }
        unset($GLOBALS['pos_calculating_totals']);
    }

    /**
     * Saves modifiers to order line items.
     */
    public function save_order_line_item_modifiers( $item, $cart_item_key, $values, $order ) {
        if ( isset( $values['pos_modifiers_v2'] ) ) {
            $product_id = $values['product_id'];
            $data = $this->get_decoded_rules( $product_id );
            $groups = [];
            if ( ! empty( $data ) && isset($data['groups']) ) {
                foreach ( $data['groups'] as $g ) $groups[(string)$g['id']] = $g['name'];
            }
            foreach ( $values['pos_modifiers_v2'] as $mod ) {
                $key = isset($groups[(string)$mod['group_id']]) ? $groups[(string)$mod['group_id']] : 'Modifier';
                $item->add_meta_data( $key, $mod['name'] . ($mod['qty'] > 1 ? ' x' . $mod['qty'] : '') );
            }
        }
    }
}
