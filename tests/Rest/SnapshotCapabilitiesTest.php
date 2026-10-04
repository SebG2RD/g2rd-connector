<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Tests\TestCase;

/**
 * Capacités annoncées au manager : `direct_login` lui dit, site par site, que la
 * connexion directe est possible (sinon refus `connector_outdated` côté manager).
 */
final class SnapshotCapabilitiesTest extends TestCase {

	public function test_la_connexion_directe_est_annoncee(): void {
		// Système de fichiers non direct : `restore_points` n'est pas annoncée, et la
		// liste ne dépend plus que de ce qui est toujours vrai.
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );

		self::assertSame( [ 'signed_commands', 'direct_login' ], ( new SnapshotController() )->capabilities() );
	}
}
