<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Tests\TestCase;

/**
 * Capacités annoncées au manager : `direct_login` lui dit, site par site, que la
 * connexion directe est possible (sinon refus `connector_outdated` côté manager) ;
 * `async_commands` lui dit que les commandes longues peuvent s'exécuter en arrière-plan.
 */
final class SnapshotCapabilitiesTest extends TestCase {

	public function test_la_connexion_directe_est_annoncee(): void {
		// Système de fichiers non direct : `restore_points` n'est pas annoncée, et la
		// liste ne dépend plus que de ce qui est toujours vrai.
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );

		self::assertSame( [ 'signed_commands', 'direct_login' ], ( new SnapshotController() )->capabilities() );
	}

	/**
	 * `async_commands` (1.15) : le site exécute les commandes longues en arrière-plan. La
	 * détection de la fonction de fin de réponse est injectée (en ligne de commande, aucune
	 * n'existe, et un test ne doit jamais les déclarer).
	 */
	public function test_async_commands_annoncee_quand_disponible(): void {
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );
		$litespeed = static fn ( string $name ): bool => 'litespeed_finish_request' === $name;

		self::assertSame(
			[ 'signed_commands', 'direct_login', 'async_commands' ],
			( new SnapshotController() )->capabilities( $litespeed )
		);
	}
}
