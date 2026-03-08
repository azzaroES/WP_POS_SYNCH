<?php
/**
 * Plugin Name: POS Woo Rules Sync (Modular)
 * Description: High-performance modular refactor of the POS WooCommerce sync engine. Handles modifiers, shipping, caching, and real-time SSE.
 * Version: 4.0
 * Author: AzZar0 | Digitalstudio.PRO
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ============ PSR-4 AUTOLOADER ============
 * Boots the modular architecture from the src/ directory.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'POS\\WooSync\\';
    $base_dir = __DIR__ . '/src/';
    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) return;
    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) require $file;
});

/**
 * ============ PLUGIN INITIALIZATION ============
 * Instantiates the orchestrator which loads all modules.
 */
add_action( 'plugins_loaded', function() {
    // Direct singleton access boots the orchestrator
    \POS\WooSync\Plugin::instance();
});

/**
 * ============ GLOBAL HELPERS (LEGACY COMPAT) ============
 * Optional: Redirect old function calls to the new modular system if needed.
 */
if ( ! function_exists( 'pos_get_restaurant_config' ) ) {
    function pos_get_restaurant_config() {
        return get_option( 'pos_restaurant_config', [] );
    }
}
