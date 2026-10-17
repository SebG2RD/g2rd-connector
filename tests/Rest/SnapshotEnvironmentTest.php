<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2RD\Connector\Environment\SizeReport;
use G2RD\Connector\Environment\SnapshotBlock;

/**
 * Bloc `environment` dans la réponse de GET /snapshot (D4, E2) : ce que le serveur du
 * site permet, lu dans la requête même, et les volumes gardés par la mesure
 * quotidienne, jamais calculés ici.
 *
 * Les risques couverts : une mesure coûteuse (parcours de wp-content, taille de la
 * base) déclenchée par une synchronisation, sur un serveur partagé par une trentaine
 * de sites ; des lectures d'options ajoutées à chaque synchronisation ; un bloc
 * existant (`server`, `capabilities`, `db_health`) modifié au passage ; un chemin du
 * serveur ou un nom de base remonté à la plateforme.
 */
final class SnapshotEnvironmentTest extends SnapshotTestCase {

	/** @var list<string> Options lues, dans l'ordre : « fonction:nom ». */
	private array $option_reads = [];

	protected function setUp(): void {
		parent::setUp();
		$this->option_reads = [];
		// Vérifications de mises à jour fraîches : aucun appel à wordpress.org.
		$this->update_transients_checked( 3600 );
	}

	public function test_le_snapshot_porte_le_bloc_environment(): void {
		$at                                       = time() - 3600;
		$this->site_options[ SizeReport::OPTION ] = self::measured( $at );

		$snapshot = $this->snapshot();

		self::assertSame( 'environment', array_key_last( $snapshot ), 'dernière clé, après pending_outcomes' );
		$environment = $snapshot['environment'];
		self::assertSame(
			[ 'version', 'php_sapi', 'extensions', 'sodium_library_version', 'exec_available', 'memory_limit_bytes', 'max_execution_seconds', 'disk', 'sizes' ],
			array_keys( $environment )
		);
		self::assertSame( SnapshotBlock::VERSION, $environment['version'] );
		// Lu dans le PHP qui sert la requête (ici, celui des tests).
		self::assertSame( PHP_SAPI, $environment['php_sapi'] );
		self::assertSame( extension_loaded( 'sodium' ), $environment['extensions']['sodium'] );
		self::assertSame( 'ok', $environment['disk']['status'], 'volume de WP_CONTENT_DIR, que le banc crée' );
		// Volumes de la dernière mesure, tels que le cache les garde.
		self::assertSame(
			[
				'status'           => 'partial',
				'measured_at'      => gmdate( 'c', $at ),
				'wp_content_bytes' => 2147483648,
				'uploads_bytes'    => 1610612736,
				'files_counted'    => 100000,
				'db_bytes'         => 52428800,
				'db_status'        => 'ok',
				'scan_ms'          => 3120,
			],
			$environment['sizes']
		);
	}

	/**
	 * SizeMeter est une classe finale que seul SizeReport construit, une fois lus ses
	 * deux filtres de bornes ; la mesure note sa tentative dans l'option, puis cherche
	 * le dossier des médias. Ces trois passages sont les espions de toute mesure.
	 */
	public function test_le_snapshot_ne_parcourt_jamais_le_disque(): void {
		// Dernière mesure il y a trois jours : une nouvelle mesure serait due.
		$stored                                   = self::measured( time() - 3 * DAY_IN_SECONDS );
		$this->site_options[ SizeReport::OPTION ] = $stored;
		Filters\expectApplied( 'g2rd_connector_environment_scan_seconds' )->never();
		Filters\expectApplied( 'g2rd_connector_environment_scan_entries' )->never();
		Functions\expect( 'wp_get_upload_dir' )->never();

		$environment = $this->snapshot()['environment'];

		self::assertSame( 'partial', $environment['sizes']['status'], 'cache vieux de trois jours, lu tel quel' );
		self::assertSame( $stored, $this->site_options[ SizeReport::OPTION ], 'aucune tentative notée' );
		self::assertSame( [], preg_grep( '/GET_LOCK/', $this->wpdb->queries ), 'aucun verrou de mesure demandé' );
	}

	public function test_le_snapshot_ajoute_une_seule_lecture_d_option(): void {
		$this->record_option_reads();

		SnapshotBlock::build( time() );

		self::assertSame( [ 'get_site_option:' . SizeReport::OPTION ], $this->option_reads, 'le bloc lit une option, une fois, et rien d\'autre' );

		$this->option_reads = [];
		$this->snapshot();

		self::assertCount( 1, array_keys( $this->option_reads, 'get_site_option:' . SizeReport::OPTION, true ), 'lue une seule fois par synchronisation' );
	}

	public function test_les_blocs_existants_sont_inchanges(): void {
		// Serveur web du parc (absent en ligne de commande).
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		try {
			$before   = time();
			$snapshot = $this->snapshot();
			$after    = time();
		} finally {
			unset( $_SERVER['SERVER_SOFTWARE'] );
		}

		self::assertSame(
			[
				'php_version'     => PHP_VERSION,
				'wp_version'      => self::WP_VERSION,
				'server_software' => 'LiteSpeed',
				'mysql_version'   => '10.11.6-MariaDB',
				// Texte brut, comme avant : sa valeur en octets est dans `environment`.
				'memory_limit'    => (string) ini_get( 'memory_limit' ),
				'max_execution'   => (int) ini_get( 'max_execution_time' ),
				'upload_max_size' => (string) ini_get( 'upload_max_filesize' ),
			],
			$snapshot['server']
		);
		self::assertSame( [ 'signed_commands', 'direct_login' ], $snapshot['capabilities'], 'aucune capacité nouvelle' );

		$collected_at = $snapshot['db_health']['collected_at'];
		self::assertSame(
			[
				'spam_comments'         => 3,
				'post_revisions'        => 12,
				'trashed_posts'         => 4,
				'expired_transients'    => 7,
				'autoloaded_options_mb' => 2.0,
				'db_overhead_mb'        => 1.5,
				'collected_at'          => $collected_at,
			],
			$snapshot['db_health']
		);
		self::assertContains( $collected_at, array_map( static fn ( int $at ): string => gmdate( 'c', $at ), range( $before, $after ) ) );
	}

	public function test_aucun_chemin_ni_nom_de_base_dans_le_bloc(): void {
		$stored = self::measured( time() - 3600 );
		// Ce qu'une version boguée, ou un tiers, aurait pu laisser dans l'option : jamais recopié.
		$stored['sizes'] += [
			'path'    => WP_CONTENT_DIR,
			'error'   => "Table 'u123456789_wp.wp_posts' doesn't exist",
			'db_name' => 'u123456789_wp',
		];
		$stored['dir'] = ABSPATH;
		$this->site_options[ SizeReport::OPTION ] = $stored;

		$environment = $this->snapshot()['environment'];

		self::assertSame(
			[ 'status', 'measured_at', 'wp_content_bytes', 'uploads_bytes', 'files_counted', 'db_bytes', 'db_status', 'scan_ms' ],
			array_keys( $environment['sizes'] )
		);
		$texts = [];
		array_walk_recursive(
			$environment,
			static function ( mixed $value ) use ( &$texts ): void {
				if ( is_string( $value ) ) {
					$texts[] = $value;
				}
			}
		);
		self::assertNotSame( [], $texts );
		foreach ( $texts as $text ) {
			// Identifiants, versions, états et dates : ni séparateur de chemin, ni espace, ni guillemet.
			self::assertMatchesRegularExpression( '/\A[A-Za-z0-9.:+_-]{1,32}\z/', $text );
			foreach ( [ WP_CONTENT_DIR, ABSPATH, sys_get_temp_dir(), 'u123456789', 'wp_posts' ] as $forbidden ) {
				self::assertStringNotContainsString( $forbidden, $text );
			}
		}
	}

	// ── Outils ───────────────────────────────────────────────────────────────────

	/**
	 * Cache de SizeReport après une mesure bornée finie à $at.
	 *
	 * @return array{at: int, attempted_at: int, sizes: array<string, int|string>}
	 */
	private static function measured( int $at ): array {
		return [
			'at'           => $at,
			'attempted_at' => $at,
			'sizes'        => [
				'status'           => 'partial',
				'wp_content_bytes' => 2147483648,
				'uploads_bytes'    => 1610612736,
				'files_counted'    => 100000,
				'db_bytes'         => 52428800,
				'db_status'        => 'ok',
				'scan_ms'          => 3120,
			],
		];
	}

	/**
	 * Lectures d'options notées, avec les mêmes réponses que le banc.
	 */
	private function record_option_reads(): void {
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				$this->option_reads[] = 'get_option:' . $name;
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			function ( string $name, $fallback = false ) {
				$this->option_reads[] = 'get_site_option:' . $name;
				if ( 'g2rd_updates_snapshot' === $name ) {
					return $this->capture;
				}
				return array_key_exists( $name, $this->site_options ) ? $this->site_options[ $name ] : $fallback;
			}
		);
	}
}
