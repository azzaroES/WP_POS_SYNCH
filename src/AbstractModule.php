<?php
namespace POS\WooSync;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base class for all plugin modules.
 * Ensures strict error isolation and health reporting.
 */
abstract class AbstractModule {
    /** @var string READY, ERROR, OFFLINE */
    protected $status = 'OFFLINE';
    protected $error_message = '';

    public function __construct() {
        try {
            $this->init();
            $this->status = 'READY';
        } catch ( \Throwable $e ) {
            $this->status = 'ERROR';
            $this->error_message = $e->getMessage();
            error_log( "[POS Module Error] " . get_class($this) . ": " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() );
        }
    }

    /**
     * Logic for module initialization.
     * Hooks should be registered here.
     */
    abstract protected function init();

    /**
     * Returns the health status for the system diagnostics API.
     */
    public function get_status() {
        return [
            'module' => (new \ReflectionClass($this))->getShortName(),
            'status' => $this->status,
            'error'  => $this->error_message
        ];
    }
}
