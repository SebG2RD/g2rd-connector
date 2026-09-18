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
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/g2rd-connector-tests-content' );
}
if ( ! defined( 'G2RD_CONNECTOR_VERSION' ) ) {
	define( 'G2RD_CONNECTOR_VERSION', '0.0.0-test' );
}
if ( ! defined( 'G2RD_CONNECTOR_REST_NS' ) ) {
	define( 'G2RD_CONNECTOR_REST_NS', 'g2rd/v1' );
}

require_once __DIR__ . '/stubs/wp-classes.php';

// CommandExecutor fait des require_once de fichiers de wp-admin : on les fournit vides.
foreach ( [ 'plugin', 'class-wp-upgrader', 'file', 'misc', 'update', 'class-pclzip' ] as $g2rd_stub ) {
	$g2rd_path = ABSPATH . 'wp-admin/includes/' . $g2rd_stub . '.php';
	if ( ! is_dir( dirname( $g2rd_path ) ) ) {
		mkdir( dirname( $g2rd_path ), 0777, true );
	}
	if ( ! file_exists( $g2rd_path ) ) {
		file_put_contents( $g2rd_path, "<?php\n" );
	}
}
unset( $g2rd_stub, $g2rd_path );
