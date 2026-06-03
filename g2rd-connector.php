<?php
/**
 * Plugin Name:       G2RD Connector
 * Plugin URI:        https://github.com/SebG2RD/g2rd-connector
 * Description:       Connecte un site WordPress au tableau de bord G2RD WP Manager (https://wp-manager.g2rd.fr) — inventaire, télémétrie, événements et commandes distantes.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            G2RD Web Agency
 * Author URI:        https://g2rd.fr
 * License:           EUPL-1.2
 * License URI:       https://joinup.ec.europa.eu/collection/eupl
 * Text Domain:       g2rd-connector
 * Domain Path:       /languages
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'G2RD_CONNECTOR_VERSION', '0.1.0' );
define( 'G2RD_CONNECTOR_FILE', __FILE__ );
define( 'G2RD_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'G2RD_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'G2RD_CONNECTOR_REST_NS', 'g2rd/v1' );

require_once G2RD_CONNECTOR_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    Plugin::instance()->boot();
} );
