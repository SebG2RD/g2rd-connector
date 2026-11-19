<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Core_Upgrader;
use G2RD\Connector\Rollback\Core\CoreReinstaller;
use G2RD\Connector\Rollback\Core\CoreRollbackRefused;
use G2RD\Connector\Rollback\UpdateTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Réinstallation par l'outil de WordPress (§ 4.3) : offre `reinstall` de même forme que celles de
 * l'API, paquet local déjà vérifié, fichiers contrôlés après, paquet supprimé dans tous les cas,
 * transaction rafraîchie entre le téléchargement et la copie.
 */
final class CoreReinstallerTest extends CoreRollbackTestCase {

	public function test_reinstalle_depuis_le_paquet_local_verifie(): void {
		$locale = CoreReinstaller::reinstall( '7.1.4', 'en_US' );

		self::assertSame( 'en_US', $locale );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertCount( 1, Core_Upgrader::$calls );
		$offer = Core_Upgrader::$calls[0]['offer'];
		self::assertSame( 'reinstall', $offer->response );
		self::assertSame( '7.1.4', $offer->version );
		self::assertSame( 'en_US', $offer->locale );
		self::assertSame( $this->downloads[0], $offer->packages->full, 'chemin local, déjà vérifié' );
		self::assertFalse( $offer->packages->no_content );
		self::assertFalse( $offer->packages->new_bundled );
		self::assertFalse( $offer->packages->partial );
		self::assertFalse( $offer->packages->rollback );
		self::assertSame( [ 'pre_check_md5' => false ], Core_Upgrader::$calls[0]['args'] );
		self::assertFileDoesNotExist( $this->downloads[0], 'rien n\'est gardé' );
	}

	/**
	 * @return iterable<string, array{mixed, string}>
	 */
	public static function upgrader_errors(): iterable {
		yield 'verrou de WordPress tenu' => [ new WP_Error( 'locked', 'Another update is currently in progress.' ), CoreRollbackRefused::BUSY ];
		yield 'copie en échec' => [ new WP_Error( 'copy_failed__copy_dir', 'Could not copy file.' ), CoreRollbackRefused::FAILED ];
		yield 'système de fichiers inaccessible' => [ false, CoreRollbackRefused::FAILED ];
	}

	#[DataProvider( 'upgrader_errors' )]
	public function test_erreur_de_core_upgrader( mixed $result, string $code ): void {
		Core_Upgrader::$on_upgrade  = null;
		Core_Upgrader::$next_result = $result;

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'refus attendu : ' . $code );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( $code, $refused->error_code() );
		}
		self::assertFileDoesNotExist( $this->downloads[0] );
	}

	public function test_fichiers_installes_differents_des_empreintes(): void {
		Core_Upgrader::$on_upgrade = static function (): void {
			file_put_contents( ABSPATH . 'wp-settings.php', "<?php // copie incomplète\n" );
		};

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'refus attendu' );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( CoreRollbackRefused::INTEGRITY, $refused->error_code() );
			self::assertStringContainsString( 'first: wp-settings.php', $refused->getMessage() );
		}
	}

	public function test_paquet_altere_rien_n_est_installe(): void {
		$this->zip = 'paquet-modifié';

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'refus attendu' );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( CoreRollbackRefused::INTEGRITY, $refused->error_code() );
		}
		self::assertSame( [], Core_Upgrader::$calls );
		self::assertSame( '7.1.5', $this->version_on_disk() );
	}

	/**
	 * Le téléchargement peut durer six minutes : la transaction d'une mise à jour protégée est
	 * rafraîchie AVANT la copie, pour qu'aucune reprise ne la tienne pour morte pendant celle-ci.
	 */
	public function test_signe_de_vie_apres_le_telechargement(): void {
		UpdateTransaction::open(
			[
				'plugin_file' => '',
				'kind'        => 'core',
			],
			time() - 500
		);
		$seen                      = null;
		Core_Upgrader::$on_upgrade = function ( object $offer ) use ( &$seen ): void {
			$seen = $this->options[ UpdateTransaction::OPTION_KEY ]['updated_at'] ?? null;
			$this->install( (string) $offer->version );
		};

		CoreReinstaller::reinstall( '7.1.4', 'en_US' );

		self::assertIsInt( $seen );
		self::assertGreaterThanOrEqual( time() - 5, $seen, 'transaction rafraîchie avant la copie' );
		UpdateTransaction::close();
	}
}
