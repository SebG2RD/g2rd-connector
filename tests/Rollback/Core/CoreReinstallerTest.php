<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Brain\Monkey\Actions;
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

	/**
	 * Une extension lève sur `upgrader_process_complete` APRÈS la copie : la version d'avant est en
	 * place, les empreintes tranchent, et le verrou `core_updater` (rendu par WordPress seulement après
	 * ce crochet) est rendu.
	 */
	public function test_exception_apres_la_copie_fichiers_controles(): void {
		$this->upgrade_throws( true, '7.1.4' );

		$locale = CoreReinstaller::reinstall( '7.1.4', 'en_US' );

		self::assertSame( 'en_US', $locale );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertArrayNotHasKey( 'core_updater.lock', $this->options, 'verrou de WordPress rendu' );
		self::assertFileDoesNotExist( $this->downloads[0] );
	}

	public function test_exception_apres_la_copie_fichiers_non_conformes(): void {
		$this->upgrade_throws( true, '7.1.4' );
		$this->wporg['https://api.wordpress.org/core/checksums/1.0/?version=7.1.4&locale=en_US']['body'] = (string) json_encode( [ 'checksums' => [ 'wp-settings.php' => md5( 'autre contenu' ) ] ] );

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'refus attendu' );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( CoreRollbackRefused::INTEGRITY, $refused->error_code() );
		}
		self::assertArrayNotHasKey( 'core_updater.lock', $this->options );
	}

	/**
	 * Exception AVANT la fin de la copie : échec à code stable ; seule la classe de l'exception est
	 * reprise (son texte peut porter un chemin du serveur, comme un avertissement converti).
	 */
	public function test_exception_avant_la_copie(): void {
		$this->upgrade_throws( false, '7.1.4' );

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'refus attendu' );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( CoreRollbackRefused::FAILED, $refused->error_code() );
			self::assertStringEndsWith( '(RuntimeException)', $refused->getMessage() );
			self::assertStringNotContainsString( 'Fatal in upgrader_process_complete', $refused->getMessage() );
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

	/** Paquet traduit absent (`.sha1` en 404) : le paquet anglais est réinstallé, et rendu comme tel. */
	public function test_paquet_de_langue_absent_repli_anglais(): void {
		$this->wporg['https://downloads.wordpress.org/release/fr_FR/wordpress-7.1.4.zip.sha1'] = [
			'code' => 404,
			'body' => '',
		];

		self::assertSame( 'en_US', CoreReinstaller::reinstall( '7.1.4', 'fr_FR' ) );
		self::assertSame( 'en_US', Core_Upgrader::$calls[0]['offer']->locale );
		self::assertSame( '7.1.4', $this->version_on_disk() );
	}

	/** Une erreur entre le téléchargement et la copie (signe de vie de la transaction) ne laisse pas le paquet sur le disque. */
	public function test_paquet_supprime_meme_sur_une_erreur_avant_la_copie(): void {
		UpdateTransaction::open(
			[
				'plugin_file' => '',
				'kind'        => 'core',
			],
			time()
		);
		Actions\expectDone( 'g2rd_connector_update_txn_progress' )->whenHappen(
			static function (): void {
				throw new \RuntimeException( 'base injoignable' );
			}
		);

		try {
			CoreReinstaller::reinstall( '7.1.4', 'en_US' );
			self::fail( 'exception attendue' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'base injoignable', $error->getMessage() );
		} finally {
			UpdateTransaction::close();
		}
		self::assertCount( 1, $this->downloads );
		self::assertFileDoesNotExist( $this->downloads[0] );
		self::assertSame( [], Core_Upgrader::$calls );
	}

	/**
	 * Contrôles de l'appelant refaits APRÈS le téléchargement, avant la copie : un refus arrête tout,
	 * paquet supprimé, rien de copié.
	 */
	public function test_un_refus_avant_la_copie_arrete_tout(): void {
		$seen = null;

		try {
			CoreReinstaller::reinstall(
				'7.1.4',
				'en_US',
				function () use ( &$seen ): void {
					$seen = is_file( $this->downloads[0] ?? '' );
					throw CoreRollbackRefused::because( CoreRollbackRefused::DB_CHANGED );
				}
			);
			self::fail( 'refus attendu' );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( CoreRollbackRefused::DB_CHANGED, $refused->error_code() );
		}
		self::assertTrue( $seen, 'appelée une fois le paquet téléchargé' );
		self::assertSame( [], Core_Upgrader::$calls );
		self::assertSame( '7.1.5', $this->version_on_disk() );
		self::assertFileDoesNotExist( $this->downloads[0] );
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
