<?php
/**
 * Bootstrap des tests unitaires : pas de WordPress chargé. Les fonctions WP sont
 * simulées par Brain Monkey dans chaque test, les quelques classes WP nécessaires
 * par tests/stubs/wp-classes.php.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/g2rd-connector-tests-abspath/' );
}
if ( ! defined( 'G2RD_CONNECTOR_VERSION' ) ) {
	define( 'G2RD_CONNECTOR_VERSION', '0.0.0-test' );
}
if ( ! defined( 'G2RD_CONNECTOR_REST_NS' ) ) {
	define( 'G2RD_CONNECTOR_REST_NS', 'g2rd/v1' );
}

require_once __DIR__ . '/stubs/wp-classes.php';
