<?php
/**
 * Autoloader PSR-4 minimaliste pour le namespace G2RD\Connector.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'G2RD\\Connector\\';
    if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    $relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
    $file     = G2RD_CONNECTOR_DIR . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';

    if ( is_readable( $file ) ) {
        require_once $file;
    }
} );
