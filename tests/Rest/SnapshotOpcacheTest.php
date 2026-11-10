<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Tests\TestCase;

/**
 * Invalidation d'OPcache par l'inventaire : aucune si le serveur revoit lui-même les
 * fichiers (`validate_timestamps` actif) ; sinon seulement si les fichiers du connecteur
 * ont changé depuis la dernière invalidation (empreinte gardée dans une option non
 * autochargée).
 *
 * La configuration d'OPcache est injectée : le test ne dépend pas de la machine.
 */
final class SnapshotOpcacheTest extends TestCase {

	private string $dir = '';

	/** @var list<string> */
	private array $files = [];

	/** @var list<string> Fichiers invalidés, dans l'ordre. */
	private array $invalidated = [];

	/** @var array<string, mixed> */
	private array $stored = [];

	/** @var list<array{string, mixed}> Écritures d'option de réseau : nom, valeur. */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir         = sys_get_temp_dir() . '/g2rd-opcache-' . uniqid( '', true );
		$this->invalidated = [];
		$this->stored      = [];
		$this->writes      = [];

		mkdir( $this->dir );
		foreach ( [ 'a.php', 'b.php', 'c.php' ] as $name ) {
			file_put_contents( $this->dir . '/' . $name, '<?php // ' . $name );
			touch( $this->dir . '/' . $name, 1_700_000_000 );
			$this->files[] = $this->dir . '/' . $name;
		}

		// Les fichiers et l'OPcache sont communs à tout le réseau : l'empreinte est une option
		// de réseau, jamais une option de blog (elle serait invalidée une fois par blog).
		Functions\when( 'get_option' )->alias(
			static function ( string $name ): void {
				throw new \LogicException( 'get_option( ' . $name . ' ) : empreinte = option de réseau.' );
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name ): void {
				throw new \LogicException( 'update_option( ' . $name . ' ) : empreinte = option de réseau.' );
			}
		);
		Functions\when( 'get_site_option' )->alias(
			fn ( string $name, $default = false ) => $this->stored[ $name ] ?? $default
		);
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ): bool {
				$this->writes[]        = [ $name, $value ];
				$this->stored[ $name ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = [];
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	private function sync( bool $validate_timestamps ): void {
		SnapshotController::sync_opcache(
			$validate_timestamps,
			function ( string $file ): bool {
				$this->invalidated[] = $file;
				return true;
			},
			$this->files
		);
	}

	public function test_validate_timestamps_actif_aucune_invalidation_ni_ecriture(): void {
		$this->sync( true );

		self::assertSame( [], $this->invalidated );
		self::assertSame( [], $this->writes );
	}

	public function test_premiere_synchro_sans_validation_invalide_tout_et_garde_l_empreinte(): void {
		$this->sync( false );

		self::assertSame( $this->files, $this->invalidated );
		self::assertCount( 1, $this->writes );
	}

	public function test_l_empreinte_est_gardee_dans_une_option_de_reseau(): void {
		$this->sync( false );

		self::assertSame( SnapshotController::OPCACHE_OPTION, $this->writes[0][0] );
		self::assertIsArray( $this->writes[0][1], 'Une empreinte par serveur.' );
	}

	public function test_empreinte_inchangee_aucune_invalidation_ni_ecriture(): void {
		$this->sync( false );
		$this->invalidated = [];
		$this->writes      = [];

		$this->sync( false );

		self::assertSame( [], $this->invalidated );
		self::assertSame( [], $this->writes );
	}

	public function test_un_mtime_change_invalide_tous_les_fichiers(): void {
		$this->sync( false );
		$this->invalidated = [];
		$this->writes      = [];

		touch( $this->files[1], 1_700_000_500 );
		$this->sync( false );

		self::assertSame( $this->files, $this->invalidated, 'Tous ensemble, jamais un seul.' );
		self::assertCount( 1, $this->writes );
	}

	public function test_une_taille_changee_invalide_tous_les_fichiers(): void {
		$this->sync( false );
		$this->invalidated = [];
		$this->writes      = [];

		file_put_contents( $this->files[2], '<?php // plus long qu avant' );
		touch( $this->files[2], 1_700_000_000 );
		$this->sync( false );

		self::assertSame( $this->files, $this->invalidated );
		self::assertCount( 1, $this->writes );
	}

	public function test_apres_un_changement_la_synchro_suivante_ne_fait_rien(): void {
		$this->sync( false );
		touch( $this->files[0], 1_700_000_900 );
		$this->sync( false );
		$this->invalidated = [];
		$this->writes      = [];

		$this->sync( false );

		self::assertSame( [], $this->invalidated );
		self::assertSame( [], $this->writes );
	}

	public function test_si_opcache_refuse_l_invalidation_rien_n_est_garde(): void {
		SnapshotController::sync_opcache( false, static fn ( string $file ): bool => false, $this->files );

		self::assertSame( [], $this->writes, 'Le prochain inventaire réessaie.' );
	}

	public function test_si_une_seule_invalidation_echoue_rien_n_est_garde_et_le_suivant_reessaie(): void {
		$failing = $this->files[1];
		SnapshotController::sync_opcache(
			false,
			function ( string $file ) use ( $failing ): bool {
				$this->invalidated[] = $file;
				return $file !== $failing;
			},
			$this->files
		);

		self::assertSame( $this->files, $this->invalidated, 'Les autres fichiers sont invalidés quand même.' );
		self::assertSame( [], $this->writes, 'Une invalidation manquée : le prochain inventaire réessaie.' );

		$this->invalidated = [];
		$this->sync( false );

		self::assertSame( $this->files, $this->invalidated );
		self::assertCount( 1, $this->writes );
	}

	public function test_api_restreinte_aucune_invalidation_ni_empreinte(): void {
		SnapshotController::sync_opcache(
			false,
			function ( string $file ): bool {
				$this->invalidated[] = $file;
				return true;
			},
			$this->files,
			api_allowed: false
		);

		self::assertSame( [], $this->invalidated, "opcache_invalidate() émettrait un avertissement : on ne l'appelle pas." );
		self::assertSame( [], $this->writes, "Rien n'a été invalidé : aucune empreinte." );
	}

	public function test_restrict_api_vide_autorise_l_api(): void {
		self::assertTrue( SnapshotController::opcache_api_allowed( '', '/var/www/index.php' ) );
		self::assertTrue( SnapshotController::opcache_api_allowed( '', null ) );
	}

	public function test_restrict_api_le_script_sous_le_chemin_autorise_l_api(): void {
		self::assertTrue( SnapshotController::opcache_api_allowed( '/var/www/admin', '/var/www/admin/index.php' ) );
	}

	public function test_restrict_api_le_script_hors_du_chemin_interdit_l_api(): void {
		self::assertFalse( SnapshotController::opcache_api_allowed( '/var/www/admin', '/var/www/site/index.php' ) );
		self::assertFalse( SnapshotController::opcache_api_allowed( '/var/www/admin', '/var/www' ), 'Chemin plus court que la restriction.' );
	}

	public function test_restrict_api_script_inconnu_interdit_l_api(): void {
		self::assertFalse( SnapshotController::opcache_api_allowed( '/var/www/admin', null ) );
		self::assertFalse( SnapshotController::opcache_api_allowed( '/var/www/admin', '' ) );
	}

	private function sync_on( string $server ): void {
		SnapshotController::sync_opcache(
			false,
			function ( string $file ): bool {
				$this->invalidated[] = $file;
				return true;
			},
			$this->files,
			api_allowed: true,
			server: $server
		);
	}

	public function test_un_second_serveur_sur_la_meme_base_invalide_a_son_tour(): void {
		$this->sync_on( 'sA' );
		$this->invalidated = [];

		$this->sync_on( 'sB' );

		self::assertSame( $this->files, $this->invalidated, "L'OPcache de B est distinct de celui de A." );
		self::assertSame( [ 'sA', 'sB' ], array_keys( $this->stored[ SnapshotController::OPCACHE_OPTION ] ) );
	}

	public function test_chaque_serveur_ne_reinvalide_pas_a_chaque_synchro(): void {
		$this->sync_on( 'sA' );
		$this->sync_on( 'sB' );
		$this->invalidated = [];
		$this->writes      = [];

		$this->sync_on( 'sA' );
		$this->sync_on( 'sB' );

		self::assertSame( [], $this->invalidated );
		self::assertSame( [], $this->writes );
	}

	public function test_un_changement_d_un_serveur_garde_l_empreinte_des_autres(): void {
		$this->sync_on( 'sA' );
		$this->sync_on( 'sB' );
		$before = $this->stored[ SnapshotController::OPCACHE_OPTION ]['sA'];

		touch( $this->files[0], 1_700_000_900 );
		$this->sync_on( 'sB' );

		self::assertSame( $before, $this->stored[ SnapshotController::OPCACHE_OPTION ]['sA'], "A n'a rien invalidé : son entrée reste celle d'avant." );
		self::assertNotSame( $before, $this->stored[ SnapshotController::OPCACHE_OPTION ]['sB'] );
	}

	public function test_le_nombre_de_serveurs_gardes_est_borne_et_le_plus_ancien_est_retire(): void {
		$max = SnapshotController::OPCACHE_MAX_SERVERS;
		self::assertSame( 8, $max );

		for ( $i = 1; $i <= $max + 2; $i++ ) {
			$this->sync_on( 's' . $i );
		}

		$kept = array_keys( $this->stored[ SnapshotController::OPCACHE_OPTION ] );
		self::assertCount( $max, $kept );
		self::assertNotContains( 's1', $kept );
		self::assertNotContains( 's2', $kept );
		self::assertSame( 's' . ( $max + 2 ), end( $kept ), 'Le plus récent est gardé.' );

		$this->invalidated = [];
		$this->sync_on( 's1' );
		self::assertSame( $this->files, $this->invalidated, 'Un serveur oublié invalide à nouveau, sans danger.' );
	}

	public function test_un_serveur_deja_connu_redevient_le_plus_recent(): void {
		$this->sync_on( 'sA' );
		$this->sync_on( 'sB' );
		touch( $this->files[0], 1_700_000_900 );
		$this->sync_on( 'sA' );

		self::assertSame( [ 'sB', 'sA' ], array_keys( $this->stored[ SnapshotController::OPCACHE_OPTION ] ) );
	}

	public function test_une_valeur_abimee_ou_ancienne_est_lue_comme_vide(): void {
		$this->stored[ SnapshotController::OPCACHE_OPTION ] = 'a3a074af394dcb8c79258d2c0d8b734b4df4b0c1';

		$this->sync_on( 'sA' );

		self::assertSame( $this->files, $this->invalidated );
		self::assertSame( [ 'sA' ], array_keys( $this->stored[ SnapshotController::OPCACHE_OPTION ] ) );
	}

	public function test_l_identite_du_serveur_est_stable_courte_et_sans_nom_d_hote(): void {
		$id = SnapshotController::opcache_server_id( 'web-12.cluster.interne', 'fpm-fcgi' );

		self::assertSame( $id, SnapshotController::opcache_server_id( 'web-12.cluster.interne', 'fpm-fcgi' ) );
		self::assertMatchesRegularExpression( '/^s[0-9a-f]{12}$/', $id );
		self::assertStringNotContainsString( 'web-12', $id );
		self::assertNotSame( $id, SnapshotController::opcache_server_id( 'web-13.cluster.interne', 'fpm-fcgi' ) );
		self::assertNotSame( $id, SnapshotController::opcache_server_id( 'web-12.cluster.interne', 'cli' ), "Le SAPI compte : FPM et CLI n'ont pas le même OPcache." );
	}

	public function test_l_option_ne_contient_aucun_nom_d_hote(): void {
		$this->sync( false );

		$stored = json_encode( $this->stored[ SnapshotController::OPCACHE_OPTION ] );
		self::assertStringNotContainsString( (string) php_uname( 'n' ), $stored );
	}

	public function test_l_empreinte_ne_depend_pas_de_l_ordre_des_fichiers(): void {
		$this->sync( false );
		$this->invalidated = [];
		$this->writes      = [];

		SnapshotController::sync_opcache(
			false,
			function ( string $file ): bool {
				$this->invalidated[] = $file;
				return true;
			},
			array_reverse( $this->files )
		);

		self::assertSame( [], $this->invalidated );
	}

	public function test_sans_la_fonction_opcache_invalidate_rien_ne_se_passe(): void {
		if ( function_exists( 'opcache_invalidate' ) ) {
			self::markTestSkipped( 'opcache_invalidate() existe sur cette machine.' );
		}

		SnapshotController::sync_opcache( false, null, $this->files );

		self::assertSame( [], $this->writes );
	}
}
