<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Actions;
use G2RD\Connector\Rest\SnapshotController;

/**
 * Le point d'entrée REST de l'inventaire (handle()) passe bien par la synchronisation
 * d'OPcache : sans cet appel, un connecteur mis à jour hors de WordPress sur un serveur à
 * `opcache.validate_timestamps=0` annoncerait indéfiniment l'ancienne version.
 */
final class SnapshotOpcacheHandleTest extends SnapshotTestCase {

	public function test_handle_appelle_sync_opcache_une_fois(): void {
		$calls = 0;
		\Patchwork\redefine(
			SnapshotController::class . '::sync_opcache',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->update_transients_checked( 3600 );
		$this->snapshot();

		self::assertSame( 1, $calls, 'handle() doit synchroniser l\'OPcache, une seule fois.' );
	}

	public function test_si_sync_opcache_leve_handle_repond_quand_meme(): void {
		$calls  = 0;
		$failed = [];
		\Patchwork\redefine(
			SnapshotController::class . '::sync_opcache',
			static function () use ( &$calls ): void {
				++$calls;
				throw new \Error( 'Call to undefined function php_uname()' );
			}
		);
		Actions\expectDone( 'g2rd_connector_opcache_sync_failed' )->once()->whenHappen(
			static function ( $e ) use ( &$failed ): void {
				$failed[] = $e;
			}
		);

		$this->update_transients_checked( 3600 );
		$data = $this->snapshot();

		self::assertSame( 1, $calls, 'Le bouchon qui lève a bien été appelé.' );
		self::assertCount( 1, $failed );
		self::assertInstanceOf( \Throwable::class, $failed[0], 'L\'action reçoit l\'erreur.' );

		self::assertArrayHasKey( 'plugins', $data, 'Une optimisation ne casse jamais l\'inventaire.' );
	}
}
