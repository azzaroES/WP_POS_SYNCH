<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles Security Headers, CORS, and Frame Protection.
 */
class SecurityModule extends AbstractModule {
    
    protected function init() {
        // Core Security Hooks
        add_action( 'init', [ $this, 'pos_unified_headers' ] );
        add_action( 'send_headers', [ $this, 'pos_unified_headers' ] );
        
        // REST API Security
        add_filter( 'rest_pre_serve_request', [ $this, 'add_cors_to_rest' ], 10, 4 );
    }

    /**
     * Consolidates all headers for React app communication and frame embedding.
     */
    public function pos_unified_headers() {
        if ( headers_sent() ) return;
        
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '*';
        
        // CORS Headers
        header( "Access-Control-Allow-Origin: $origin" );
        header( "Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE" );
        header( "Access-Control-Allow-Credentials: true" );
        header( "Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With, X-WP-Nonce, X-HTTP-Method-Override" );

        // Frame Security (Allow our known dashboard origins)
        header( "Content-Security-Policy: frame-ancestors 'self' https://bbdd.space https://pos.bokfood.cat http://localhost:5173" );
        header( "X-Frame-Options: ALLOW-FROM https://bbdd.space" );

        // Exit early for preflight
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
            status_header( 200 );
            exit;
        }
    }

    /**
     * Ensures REST API responses also carry the same security posture.
     */
    public function add_cors_to_rest( $served, $result, $request, $server ) {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '*';
        
        // Remove default WP CORS to avoid duplicates
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
        
        header( "Access-Control-Allow-Origin: $origin" );
        header( "Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE" );
        header( "Access-Control-Allow-Credentials: true" );
        header( "Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With, X-WP-Nonce" );
        
        return $served;
    }
}
