<?php
namespace POS\WooSync\Modules;

use POS\WooSync\AbstractModule;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tracks API Traffic, Response Times, and Health Metrics.
 */
class StatsModule extends AbstractModule {

    private $start_time;

    protected function init() {
        // Track REST API
        add_filter( 'rest_pre_dispatch', [ $this, 'start_timer' ], 10, 3 );
        add_filter( 'rest_post_dispatch', [ $this, 'track_rest_request' ], 10, 3 );

        // Track GraphQL (approximated via init + post)
        add_action( 'graphql_init', [ $this, 'start_timer' ] );
        add_filter( 'graphql_response_view_data', [ $this, 'track_graphql_request' ], 10, 1 );

        // SSE Tracking (Hooks into SyncModule's Stream)
        add_action( 'pos_sse_stream_started', [ $this, 'increment_stream_count' ] );
    }

    public function start_timer() {
        $this->start_time = microtime( true );
    }

    /**
     * Logs REST API performance and errors.
     */
    public function track_rest_request( $response, $server, $request ) {
        if ( strpos( $request->get_route(), 'pos/v1' ) === false ) return $response;

        $duration = $this->get_duration();
        $is_error = is_wp_error( $response ) || ( method_exists( $response, 'is_error' ) && $response->is_error() );
        
        $status = 200;
        if ( is_wp_error( $response ) ) {
            $status = 500;
        } elseif ( method_exists( $response, 'get_status' ) ) {
            $status = $response->get_status();
        }

        $this->update_stats( $duration, $status >= 400 );
        return $response;
    }

    /**
     * Logs GraphQL performance.
     */
    public function track_graphql_request( $data ) {
        $duration = $this->get_duration();
        $has_errors = ! empty( $data['errors'] );
        
        $this->update_stats( $duration, $has_errors );
        return $data;
    }

    public function increment_stream_count() {
        $stats = get_option( 'pos_api_stats', $this->get_default_stats() );
        $stats['active_streams']++;
        update_option( 'pos_api_stats', $stats );
    }

    private function get_duration() {
        if ( ! $this->start_time ) return 0;
        return round( ( microtime( true ) - $this->start_time ) * 1000 );
    }

    /**
     * Updates the persistent stats store.
     */
    private function update_stats( $ms, $is_error ) {
        $stats = get_option( 'pos_api_stats', $this->get_default_stats() );
        
        $stats['total_requests']++;
        $stats['total_ms'] += $ms;
        $stats['avg_response'] = round( $stats['total_ms'] / $stats['total_requests'] );
        
        if ( $is_error ) $stats['errors']++;
        $stats['error_rate'] = round( ( $stats['errors'] / $stats['total_requests'] ) * 100, 1 );

        // Reset if too large (keep it within last ~1000 requests for relevance)
        if ( $stats['total_requests'] > 10000 ) {
            $stats = $this->get_default_stats();
        }

        update_option( 'pos_api_stats', $stats );
    }

    private function get_default_stats() {
        return [
            'total_requests' => 0,
            'total_ms'       => 0,
            'avg_response'   => 0,
            'errors'         => 0,
            'error_rate'     => 0,
            'active_streams' => 1 // Initializing for UI test
        ];
    }
}
