<?php
/**
 * Bootstrap PHPStan — déclare les constantes définies à l'exécution dans
 * g2rd-connector.php pour qu'elles soient résolues dans les fichiers consommateurs.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

define( 'G2RD_CONNECTOR_VERSION', '0.0.0' );
define( 'G2RD_CONNECTOR_FILE', __FILE__ );
define( 'G2RD_CONNECTOR_DIR', __DIR__ . '/' );
define( 'G2RD_CONNECTOR_URL', 'https://example.test/' );
define( 'G2RD_CONNECTOR_REST_NS', 'g2rd/v1' );

// Constantes de PclZip (wp-admin/includes/class-pclzip.php), chargé à l'exécution
// seulement quand ZipArchive est absent : les stubs WordPress ne les déclarent pas.
define( 'PCLZIP_OPT_REMOVE_PATH', 77001 );
