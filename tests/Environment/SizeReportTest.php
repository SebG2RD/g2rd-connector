<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Environment;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2RD\Connector\Cron\Phase;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Environment\SizeMeter;
use G2RD\Connector\Environment\SizeReport;
use G2RD\Connector\Plugin;
use G2RD\Connector\Settings;
use G2RD\Connector\Support\MysqlLock;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mesure quotidienne des volumes en tâche de fond (D4, E2) : au plus une tentative par
 * 20 h et par installation, sous verrou, gardée datée dans une option de réseau que le
 * snapshot se contente de lire.
 *
 * Les risques couverts : une mesure relancée à chaque passage de la tâche (deux fois
 * par jour, sur un serveur partagé par une trentaine de sites) ; deux processus qui
 * mesurent en même temps parce que l'un relit une option périmée dans son cache ; un
 * serveur sans `GET_LOCK` qui ne mesurerait jamais ; une mesure tuée par le serveur
 * lue « ok » pour toujours ; en multisite, les médias d'un seul blog ; le snapshot qui
 * déclencherait une mesure.
 *
 * Les options de réseau sont simulées comme WordPress les sert : une base (ce qu'un
 * autre processus voit et écrit) et un cache propre à ce processus, qui garde une
 * valeur lue, ou son absence (`notoptions`), tant que wp_cache_delete() n'a pas visé
 * la bonne clé. Le mesureur est un vrai SizeMeter dont le parcours et l'horloge sont
 * injectés ; le $wpdb factice répond au verrou et à la taille de la base. Tout est
 * noté dans un journal ordonné.
 */
final class SizeReportTest extends TestCase {

	private const T = 1789000000;

	private const HOUR = 3600;

	/** Réseau courant (multisite) : visé par les clés du cache des options de réseau. */
	private const NETWORK = 3;

	/** Site principal du réseau. */
	private const MAIN_SITE = 1;

	/**
	 * Options de réseau en base : ce qu'un autre processus voit, et peut écrire.
	 *
	 * @var array<string, mixed>
	 */
	private array $db = [];

	/**
	 * Cache d'objets de CE processus : valeurs lues ou écrites.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	/**
	 * Options notées absentes par ce processus (`notoptions`).
	 *
	 * @var array<string, true>
	 */
	private array $notoptions = [];

	/**
	 * Journal ordonné des appels observés.
	 *
	 * @var list<string>
	 */
	private array $log = [];

	/**
	 * Valeurs écrites par update_site_option(), dans l'ordre.
	 *
	 * @var list<mixed>
	 */
	private array $writes = [];

	/** Parcours de wp-content demandés au mesureur. */
	private int $measures = 0;

	private bool $multisite = false;

	/** Blog courant. */
	private int $blog = self::MAIN_SITE;

	/**
	 * Blogs quittés par switch_to_blog(), à rétablir.
	 *
	 * @var list<int>
	 */
	private array $blog_stack = [];

	/** $wpdb global d'origine, rétabli après chaque test. */
	private bool $had_wpdb = false;

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->db         = [];
		$this->cache      = [];
		$this->notoptions = [];
		$this->log        = [];
		$this->writes     = [];
		$this->measures   = 0;
		$this->multisite  = false;
		$this->blog       = self::MAIN_SITE;
		$this->blog_stack = [];

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->install_wpdb( '1' );

		Functions\when( 'get_site_option' )->alias(
			function ( string $name, $fallback = false ) {
				$this->log[] = 'get_site_option';
				if ( array_key_exists( $name, $this->cache ) ) {
					return $this->cache[ $name ];
				}
				if ( isset( $this->notoptions[ $name ] ) ) {
					return $fallback;
				}
				if ( array_key_exists( $name, $this->db ) ) {
					$this->cache[ $name ] = $this->db[ $name ];
					return $this->db[ $name ];
				}
				$this->notoptions[ $name ] = true;
				return $fallback;
			}
		);
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ): bool {
				$this->log[]          = 'update_site_option';
				$this->writes[]       = $value;
				$this->db[ $name ]    = $value;
				$this->cache[ $name ] = $value;
				unset( $this->notoptions[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, string $group = '' ): bool {
				$this->log[] = 'wp_cache_delete(' . $group . ', ' . $key . ')';
				// Seules les clés que WordPress utilise vraiment pour une option de réseau vident le cache simulé.
				[ $option_key, $notoptions_key, $expected_group ] = $this->multisite
					? [ self::NETWORK . ':' . SizeReport::OPTION, self::NETWORK . ':notoptions', 'site-options' ]
					: [ SizeReport::OPTION, 'notoptions', 'options' ];
				if ( $expected_group === $group && $option_key === $key ) {
					unset( $this->cache[ SizeReport::OPTION ] );
				}
				if ( $expected_group === $group && $notoptions_key === $key ) {
					$this->notoptions = [];
				}
				return true;
			}
		);

		Functions\when( 'is_multisite' )->alias( fn (): bool => $this->multisite );
		Functions\when( 'get_current_network_id' )->justReturn( self::NETWORK );
		Functions\when( 'get_main_site_id' )->justReturn( self::MAIN_SITE );
		Functions\when( 'get_current_blog_id' )->alias( fn (): int => $this->blog );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $blog_id ): bool {
				$this->log[]        = 'switch_to_blog(' . $blog_id . ')';
				$this->blog_stack[] = $this->blog;
				$this->blog         = $blog_id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->log[] = 'restore_current_blog';
				$this->blog  = array_pop( $this->blog_stack ) ?? $this->blog;
				return true;
			}
		);
		// Dossier des médias du blog courant, comme WordPress : uploads/sites/N pour un blog secondaire.
		Functions\when( 'wp_get_upload_dir' )->alias(
			function (): array {
				$this->log[] = 'wp_get_upload_dir';
				$basedir     = WP_CONTENT_DIR . '/uploads';
				return [ 'basedir' => self::MAIN_SITE === $this->blog ? $basedir : $basedir . '/sites/' . $this->blog ];
			}
		);
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	// ── Une tentative au plus par 20 h ──────────────────────────────────────────

	public function test_mesure_puis_rien_pendant_20_h(): void {
		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 1, $this->measures );
		self::assertSame(
			[
				'at'           => self::T + 1,
				'attempted_at' => self::T,
				'sizes'        => [
					'status'           => 'ok',
					'wp_content_bytes' => 500,
					'uploads_bytes'    => 400,
					'files_counted'    => 2,
					'db_bytes'         => 7000,
					'db_status'        => 'ok',
					'scan_ms'          => 1500,
				],
			],
			$this->db[ SizeReport::OPTION ],
			'mesure gardée datée : at = fin de la mesure (1,5 s après le départ, à la seconde)'
		);
		$wpdb = $GLOBALS['wpdb'];
		self::assertSame(
			[
				[ 'GET_LOCK', MysqlLock::name( 'env', MysqlLock::SCOPE_NETWORK ), 0 ],
				[ 'RELEASE_LOCK', MysqlLock::name( 'env', MysqlLock::SCOPE_NETWORK ), null ],
			],
			$wpdb->lock_calls,
			'verrou commun à tout le réseau, sans attente, libéré après la mesure'
		);

		// Passage de la mi-journée (12 h plus tard), puis juste avant les 20 h : rien.
		$this->log = [];
		SizeReport::refresh_if_due( self::T + 12 * self::HOUR, $this->meter() );
		SizeReport::refresh_if_due( self::T + 20 * self::HOUR - 1, $this->meter() );

		self::assertSame( 1, $this->measures );
		self::assertSame( [ 'get_site_option', 'get_site_option' ], $this->log, 'une lecture d\'option, ni verrou ni écriture' );
	}

	public function test_mesure_de_nouveau_apres_24_h(): void {
		SizeReport::refresh_if_due( self::T, $this->meter() );
		SizeReport::refresh_if_due( self::T + 24 * self::HOUR, $this->meter() );

		self::assertSame( 2, $this->measures );
		self::assertSame( self::T + 24 * self::HOUR, $this->db[ SizeReport::OPTION ]['attempted_at'] );
	}

	/**
	 * La tâche passe deux fois par jour, déclenchée par WP-Cron, donc rarement à la
	 * seconde près : le passage du lendemain parti 10 min plus tôt que la veille n'est
	 * pas reporté de 12 h.
	 */
	public function test_le_passage_du_lendemain_parti_un_peu_plus_tot_mesure(): void {
		$this->db[ SizeReport::OPTION ] = self::stored( self::T - 24 * self::HOUR + 10 * 60 );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 1, $this->measures );
	}

	public function test_une_tentative_ratee_n_est_pas_relancee_avant_20_h(): void {
		SizeReport::refresh_if_due( self::T, $this->failing_meter() );
		SizeReport::refresh_if_due( self::T + 12 * self::HOUR, $this->failing_meter() );
		SizeReport::refresh_if_due( self::T + 20 * self::HOUR - 1, $this->failing_meter() );

		self::assertSame( 1, $this->measures );
		self::assertSame( 'failed', $this->db[ SizeReport::OPTION ]['sizes']['status'] );
	}

	/**
	 * Une tentative notée dans le futur proche est celle d'un processus parti une
	 * seconde plus tard : elle compte. Notée à plus de 20 h dans le futur (horloge du
	 * serveur reculée, valeur abîmée), elle ne bloque pas la mesure indéfiniment.
	 */
	public function test_une_tentative_datee_du_futur_lointain_ne_bloque_pas_la_mesure(): void {
		$this->db[ SizeReport::OPTION ] = self::stored( self::T + 60 );
		SizeReport::refresh_if_due( self::T, $this->meter() );
		self::assertSame( 0, $this->measures, 'tentative d\'un processus parti juste après : rien' );

		$this->cache                    = [];
		$this->db[ SizeReport::OPTION ] = self::stored( self::T + 2 * 24 * self::HOUR );
		SizeReport::refresh_if_due( self::T, $this->meter() );
		self::assertSame( 1, $this->measures, 'date impossible : la mesure a lieu' );
	}

	// ── Verrou ───────────────────────────────────────────────────────────────────

	public function test_verrou_tenu_ailleurs_rien_n_est_mesure(): void {
		$this->install_wpdb( '0' );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 0, $this->measures );
		self::assertSame( [], $this->writes, 'aucune écriture : l\'autre processus note sa propre tentative' );
		self::assertSame( [ 'get_site_option', 'GET_LOCK' ], $this->log, 'rien à libérer' );
	}

	/**
	 * @return array<string, array{string|null|\Throwable}>
	 */
	public static function unavailable_locks(): array {
		return [
			'GET_LOCK rend NULL'  => [ null ],
			'GET_LOCK lève'       => [ new \RuntimeException( 'FUNCTION GET_LOCK does not exist' ) ],
		];
	}

	/**
	 * Sans GET_LOCK (fonction refusée par l'hébergeur, cluster Galera), la mesure a lieu
	 * sans verrou : sinon elle n'aurait jamais lieu sur ce serveur.
	 *
	 * @param string|null|\Throwable $get_lock Réponse de GET_LOCK.
	 */
	#[DataProvider( 'unavailable_locks' )]
	public function test_get_lock_indisponible_mesure_sans_verrou( string|null|\Throwable $get_lock ): void {
		$this->install_wpdb( $get_lock );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 1, $this->measures );
		self::assertSame(
			[
				'at'           => null,
				'attempted_at' => self::T,
				'sizes'        => null,
			],
			$this->writes[0],
			'la tentative est notée d\'abord'
		);
		self::assertLessThan(
			array_search( 'mesure', $this->log, true ),
			array_search( 'update_site_option', $this->log, true ),
			'… avant la mesure'
		);
		self::assertSame( self::T + 1, $this->db[ SizeReport::OPTION ]['at'], 'mesure gardée datée' );
		self::assertSame( 'ok', $this->db[ SizeReport::OPTION ]['sizes']['status'] );
		self::assertNotContains( 'RELEASE_LOCK', $this->log, 'aucun verrou pris, rien à libérer' );
	}

	public function test_get_lock_indisponible_la_garde_de_20_h_s_applique(): void {
		$this->install_wpdb( null );

		SizeReport::refresh_if_due( self::T, $this->meter() );
		SizeReport::refresh_if_due( self::T + 12 * self::HOUR, $this->meter() );

		self::assertSame( 1, $this->measures );
	}

	/**
	 * Un autre processus a mesuré pendant que celui-ci prenait le verrou : l'option lue
	 * avant le verrou, gardée dans le cache de ce processus, est périmée. Purge du cache
	 * puis relecture sous verrou : aucune seconde mesure.
	 */
	public function test_relecture_sous_verrou_evite_une_double_mesure(): void {
		$this->db[ SizeReport::OPTION ] = self::stored( self::T - 24 * self::HOUR );
		$this->install_wpdb( '1', $this->another_process_measures() );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 0, $this->measures );
		self::assertSame( [], $this->writes );
		self::assertSame(
			[
				'get_site_option',
				'GET_LOCK',
				'wp_cache_delete(options, ' . SizeReport::OPTION . ')',
				'wp_cache_delete(options, notoptions)',
				'get_site_option',
				'RELEASE_LOCK',
			],
			$this->log,
			'purge du cache AVANT la seconde lecture'
		);
	}

	/** Première mesure : l'option absente est notée dans `notoptions`, que la purge vide aussi. */
	public function test_relecture_sous_verrou_voit_une_premiere_mesure_ecrite_ailleurs(): void {
		$this->install_wpdb( '1', $this->another_process_measures() );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 0, $this->measures );
		self::assertSame( [], $this->writes );
	}

	public function test_la_purge_vise_les_cles_de_reseau_en_multisite(): void {
		$this->multisite                = true;
		$this->db[ SizeReport::OPTION ] = self::stored( self::T - 24 * self::HOUR );
		$this->install_wpdb( '1', $this->another_process_measures() );

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( 0, $this->measures );
		self::assertSame(
			[
				'get_site_option',
				'GET_LOCK',
				'wp_cache_delete(site-options, ' . self::NETWORK . ':' . SizeReport::OPTION . ')',
				'wp_cache_delete(site-options, ' . self::NETWORK . ':notoptions)',
				'get_site_option',
				'RELEASE_LOCK',
			],
			$this->log
		);
	}

	// ── Résultat de la mesure ────────────────────────────────────────────────────

	public function test_exception_du_mesureur_statut_failed_et_valeurs_precedentes_gardees(): void {
		$previous                       = self::stored( self::T - 24 * self::HOUR );
		$this->db[ SizeReport::OPTION ] = $previous;

		SizeReport::refresh_if_due( self::T, $this->failing_meter() );

		self::assertSame( 1, $this->measures );
		self::assertSame(
			[
				'at'           => $previous['at'],
				'attempted_at' => self::T,
				'sizes'        => [ 'status' => 'failed' ] + $previous['sizes'],
			],
			$this->db[ SizeReport::OPTION ]
		);
		self::assertSame(
			[ 'status' => 'failed', 'measured_at' => gmdate( 'c', $previous['at'] ) ] + self::values( $previous['sizes'] ),
			SizeReport::read( self::T + 60 )
		);
		// Ni le message de SizeMeter ni celui d'origine (qui porte un chemin du serveur) ne sont gardés.
		$stored = serialize( $this->db );
		self::assertStringNotContainsString( 'could not be opened', $stored );
		self::assertStringNotContainsString( 'u123', $stored );
	}

	public function test_une_premiere_mesure_ratee_est_failed_sans_valeurs(): void {
		SizeReport::refresh_if_due( self::T, $this->failing_meter() );

		self::assertSame( self::empty_sizes( 'failed' ), SizeReport::read( self::T + 60 ) );
	}

	public function test_mesure_partielle_statut_partial(): void {
		$files = [
			'plugins/extension.php' => 100,
			'themes/theme.css'      => 50,
			'uploads/photo.jpg'     => 400,
		];
		SizeReport::refresh_if_due( self::T, $this->meter( $files, 2 ) );

		$read = SizeReport::read( self::T + 60 );
		self::assertSame( 'partial', $read['status'] );
		self::assertSame( 2, $read['files_counted'], 'valeurs minimales : la borne a arrêté le parcours' );
		self::assertSame( 150, $read['wp_content_bytes'] );
	}

	/**
	 * Bornes réglables par filtre, toujours ramenées dans [1, 20] s et [1 000, 500 000]
	 * entrées ; une valeur illisible garde le défaut.
	 *
	 * @return array<string, array{mixed, mixed, int, int}>
	 */
	public static function filtered_bounds(): array {
		return [
			'défauts'           => [ null, null, 5, 100000 ],
			'dans l\'intervalle' => [ 12, 250000, 12, 250000 ],
			'trop bas'          => [ 0, 10, 1, 1000 ],
			'négatifs'          => [ -3, -1, 1, 1000 ],
			'trop haut'         => [ 100, 10000000, 20, 500000 ],
			'texte numérique'   => [ '7', '2000', 7, 2000 ],
			'illisibles'        => [ 'beaucoup', [ 1 ], 5, 100000 ],
		];
	}

	/**
	 * @param mixed $seconds          Valeur rendue par le filtre du temps (null : défaut inchangé).
	 * @param mixed $entries          Valeur rendue par le filtre des entrées (null : défaut inchangé).
	 * @param int   $expected_seconds Borne de temps attendue.
	 * @param int   $expected_entries Borne d'entrées attendue.
	 */
	#[DataProvider( 'filtered_bounds' )]
	public function test_bornes_des_filtres_ramenees_dans_l_intervalle( mixed $seconds, mixed $entries, int $expected_seconds, int $expected_entries ): void {
		$defaults = [];
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( &$defaults, $seconds, $entries ) {
				$defaults[ $hook ] = $value;
				$filtered          = [
					'g2rd_connector_environment_scan_seconds' => $seconds,
					'g2rd_connector_environment_scan_entries' => $entries,
				];
				return $filtered[ $hook ] ?? $value;
			}
		);

		$bounds = ( new \ReflectionMethod( SizeReport::class, 'scan_bounds' ) )->invoke( null );

		self::assertSame( [ $expected_seconds, $expected_entries ], $bounds );
		self::assertSame(
			[
				'g2rd_connector_environment_scan_seconds' => 5,
				'g2rd_connector_environment_scan_entries' => 100000,
			],
			$defaults
		);
	}

	/**
	 * Option de RÉSEAU : en multisite, wp-content et la base sont communs ; en site
	 * simple, update_site_option() écrit une option non autochargée.
	 */
	public function test_option_de_reseau_non_autochargee(): void {
		$blog_options = [];
		foreach ( [ 'get_option', 'update_option', 'add_option' ] as $function ) {
			Functions\when( $function )->alias(
				static function () use ( &$blog_options, $function ): bool {
					$blog_options[] = $function;
					return false;
				}
			);
		}

		SizeReport::refresh_if_due( self::T, $this->meter() );
		SizeReport::read( self::T );

		self::assertSame( [], $blog_options, 'jamais une option du blog (autochargée par défaut)' );
		self::assertArrayHasKey( SizeReport::OPTION, $this->db );
	}

	// ── Lecture pour le snapshot ─────────────────────────────────────────────────

	public function test_lecture_du_cache_vide_statut_pending(): void {
		self::assertSame( self::empty_sizes( 'pending' ), SizeReport::read( self::T ) );
	}

	/** Le snapshot lit le cache, rien d'autre : ni verrou, ni filtre, ni parcours, ni écriture. */
	public function test_lecture_ne_mesure_jamais(): void {
		$this->db[ SizeReport::OPTION ] = self::stored( self::T - 3 * 24 * self::HOUR );
		Filters\expectApplied( 'g2rd_connector_environment_scan_seconds' )->never();
		Filters\expectApplied( 'g2rd_connector_environment_scan_entries' )->never();

		$read = SizeReport::read( self::T );

		self::assertSame( 'ok', $read['status'], 'cache vieux de 3 jours : lu tel quel' );
		self::assertSame( [ 'get_site_option' ], $this->log, 'une seule lecture d\'option, rien d\'autre' );
		self::assertSame( 0, $this->measures );
		self::assertSame( [], $GLOBALS['wpdb']->lock_calls );
	}

	public function test_lecture_d_une_mesure_reussie(): void {
		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame(
			[
				'status'           => 'ok',
				'measured_at'      => gmdate( 'c', self::T + 1 ),
				'wp_content_bytes' => 500,
				'uploads_bytes'    => 400,
				'files_counted'    => 2,
				'db_bytes'         => 7000,
				'db_status'        => 'ok',
				'scan_ms'          => 1500,
			],
			SizeReport::read( self::T + 60 )
		);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unreadable_options(): array {
		return [
			'texte'               => [ 'illisible' ],
			'statut inconnu'      => [
				[
					'at'           => self::T - 60,
					'attempted_at' => self::T - 60,
					'sizes'        => [ 'status' => 'parfait' ],
				],
			],
			'tailles illisibles'  => [
				[
					'at'           => 'hier',
					'attempted_at' => self::T - 60,
					'sizes'        => 'beaucoup',
				],
			],
		];
	}

	/**
	 * @param mixed $stored Option abîmée.
	 */
	#[DataProvider( 'unreadable_options' )]
	public function test_une_option_illisible_est_lue_comme_jamais_mesuree( mixed $stored ): void {
		$this->db[ SizeReport::OPTION ] = $stored;

		self::assertSame( self::empty_sizes( 'pending' ), SizeReport::read( self::T ) );
	}

	/**
	 * Mesure tuée par le serveur (délai, mémoire) : aucun `finally` ne s'exécute, le
	 * statut stocké reste celui de la veille. Au-delà de 10 min sans résultat, la
	 * tentative est lue comme ratée, jamais « ok ».
	 */
	public function test_une_mesure_tuee_est_lue_comme_failed(): void {
		$yesterday                      = self::stored( self::T - 24 * self::HOUR );
		$yesterday['attempted_at']      = self::T - 11 * 60;
		$this->db[ SizeReport::OPTION ] = $yesterday;

		self::assertSame(
			[ 'status' => 'failed', 'measured_at' => gmdate( 'c', $yesterday['at'] ) ] + self::values( $yesterday['sizes'] ),
			SizeReport::read( self::T )
		);
	}

	public function test_une_premiere_mesure_tuee_est_lue_comme_failed_sans_valeurs(): void {
		$this->db[ SizeReport::OPTION ] = [
			'at'           => null,
			'attempted_at' => self::T - 11 * 60,
			'sizes'        => null,
		];

		self::assertSame( self::empty_sizes( 'failed' ), SizeReport::read( self::T ) );
	}

	public function test_une_mesure_en_cours_n_est_pas_lue_comme_failed(): void {
		$yesterday                      = self::stored( self::T - 24 * self::HOUR );
		$yesterday['attempted_at']      = self::T - 30;
		$this->db[ SizeReport::OPTION ] = $yesterday;

		self::assertSame( 'ok', SizeReport::read( self::T )['status'], 'les valeurs de la veille, en attendant' );

		$this->cache                    = [];
		$this->db[ SizeReport::OPTION ] = [
			'at'           => null,
			'attempted_at' => self::T - 30,
			'sizes'        => null,
		];
		self::assertSame( self::empty_sizes( 'pending' ), SizeReport::read( self::T ), 'première mesure en cours' );
	}

	// ── Multisite ────────────────────────────────────────────────────────────────

	/**
	 * Le WP-Cron d'un blog secondaire passe le premier : les médias mesurés sont ceux du
	 * site principal, qui contiennent `uploads/sites/N` de tous les blogs.
	 */
	public function test_multisite_mesure_les_medias_du_site_principal(): void {
		$this->multisite = true;
		$this->blog      = 2;

		SizeReport::refresh_if_due(
			self::T,
			$this->meter(
				[
					'plugins/extension.php'       => 100,
					'uploads/2026/10/photo.jpg'   => 400,
					'uploads/sites/2/carte.jpg'   => 50,
					'uploads/sites/5/affiche.jpg' => 25,
				]
			)
		);

		self::assertSame( 475, $this->db[ SizeReport::OPTION ]['sizes']['uploads_bytes'], 'médias de tout le réseau' );
		self::assertSame( [ 'switch_to_blog(1)', 'wp_get_upload_dir', 'restore_current_blog' ], $this->blog_calls() );
		self::assertSame( 2, $this->blog, 'blog courant rétabli' );
	}

	public function test_le_blog_courant_est_retabli_meme_si_la_lecture_echoue(): void {
		$this->multisite = true;
		$this->blog      = 2;
		Functions\when( 'wp_get_upload_dir' )->alias(
			function (): array {
				$this->log[] = 'wp_get_upload_dir';
				throw new \RuntimeException( 'lecture impossible' );
			}
		);

		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( [ 'switch_to_blog(1)', 'wp_get_upload_dir', 'restore_current_blog' ], $this->blog_calls() );
		self::assertSame( 2, $this->blog );
		self::assertSame( 0, $this->measures );
		self::assertSame( 'failed', $this->db[ SizeReport::OPTION ]['sizes']['status'] );
	}

	public function test_site_simple_ne_change_jamais_de_blog(): void {
		SizeReport::refresh_if_due( self::T, $this->meter() );

		self::assertSame( [ 'wp_get_upload_dir' ], $this->blog_calls() );
		self::assertSame( 400, $this->db[ SizeReport::OPTION ]['sizes']['uploads_bytes'] );
	}

	// ── Branchement ──────────────────────────────────────────────────────────────

	/**
	 * `do_action( HOOK )` sans argument passe '' en premier argument : un paramètre
	 * `?int` lèverait une TypeError dans le WP-Cron du site.
	 */
	public function test_le_rappel_du_hook_accepte_un_appel_sans_argument(): void {
		self::assertSame( 0, ( new \ReflectionMethod( SizeReport::class, 'refresh_now' ) )->getNumberOfParameters() );

		// Tentative à l'instant même : le rappel s'arrête à la garde des 20 h.
		$this->db[ SizeReport::OPTION ] = self::stored( time() );

		call_user_func( [ SizeReport::class, 'refresh_now' ], '' );

		self::assertSame( [ 'get_site_option' ], $this->log );
	}

	public function test_seul_refresh_now_est_branche_sur_la_tache_biquotidienne(): void {
		$this->boot( true );

		self::assertNotFalse( has_action( RestorePointPurgeJob::HOOK, [ SizeReport::class, 'refresh_now' ] ) );
		self::assertFalse( has_action( RestorePointPurgeJob::HOOK, [ SizeReport::class, 'refresh_if_due' ] ) );
		self::assertSame( [], $this->writes, 'rien n\'est mesuré au démarrage' );
		self::assertSame( 0, $this->measures );
	}

	/** Aucune écriture avant l'accord explicite de l'administrateur (guideline 7). */
	public function test_un_site_non_enrole_ne_branche_pas_la_mesure(): void {
		$this->boot( false );

		self::assertFalse( has_action( RestorePointPurgeJob::HOOK, [ SizeReport::class, 'refresh_now' ] ) );
	}

	// ── Outils ───────────────────────────────────────────────────────────────────

	/**
	 * Mesureur réel au parcours injecté : les entrées données (chemin relatif à
	 * wp-content => octets), une mesure de 1,5 s sur son horloge.
	 *
	 * @param array<string, int> $files       Fichiers de wp-content.
	 * @param int                $max_entries Borne d'entrées.
	 */
	private function meter(
		array $files = [
			'plugins/extension.php' => 100,
			'uploads/photo.jpg'     => 400,
		],
		int $max_entries = 100000
	): SizeMeter {
		$entries  = function ( string $dir ) use ( $files ): \Iterator {
			++$this->measures;
			$this->log[] = 'mesure';
			$list        = [];
			foreach ( $files as $relative => $bytes ) {
				$list[] = self::file( $dir . '/' . $relative, $bytes );
			}
			return new \ArrayIterator( $list );
		};
		$readings = 0;
		$clock    = static function () use ( &$readings ): int {
			return 0 === $readings++ ? 10000 : 11500;
		};
		return new SizeMeter( 5, $max_entries, $clock, $entries );
	}

	/**
	 * Mesureur dont wp-content ne s'ouvre pas : SizeMeter lève alors une exception
	 * (le message d'origine porte un chemin du serveur).
	 */
	private function failing_meter(): SizeMeter {
		$entries = function (): \Iterator {
			++$this->measures;
			$this->log[] = 'mesure';
			throw new \UnexpectedValueException( 'RecursiveDirectoryIterator::__construct(/home/u123/domains/site/wp-content): Failed to open directory' );
		};
		return new SizeMeter( 5, 100000, null, $entries );
	}

	/**
	 * Entrée factice de $bytes octets (aucun fichier réel).
	 */
	private static function file( string $path, int $bytes ): \SplFileInfo {
		return new class( $path, $bytes ) extends \SplFileInfo {

			public function __construct( string $path, private int $bytes ) {
				parent::__construct( $path );
			}

			public function getSize(): int|false {
				return $this->bytes;
			}

			public function isLink(): bool {
				return false;
			}
		};
	}

	/**
	 * Un autre processus mesure et écrit en base pendant que celui-ci attend le verrou.
	 *
	 * @return \Closure(): void
	 */
	private function another_process_measures(): \Closure {
		return function (): void {
			$this->db[ SizeReport::OPTION ] = self::stored( self::T - 5 );
		};
	}

	/**
	 * Option telle que l'écrit une mesure réussie qui a démarré à $attempted_at.
	 *
	 * @return array{at: int, attempted_at: int, sizes: array<string, int|string>}
	 */
	private static function stored( int $attempted_at ): array {
		return [
			'at'           => $attempted_at + 2,
			'attempted_at' => $attempted_at,
			'sizes'        => [
				'status'           => 'ok',
				'wp_content_bytes' => 5000,
				'uploads_bytes'    => 3000,
				'files_counted'    => 42,
				'db_bytes'         => 7000,
				'db_status'        => 'ok',
				'scan_ms'          => 1200,
			],
		];
	}

	/**
	 * Valeurs mesurées d'un bloc stocké, sans son statut.
	 *
	 * @param array<string, int|string> $sizes Bloc stocké.
	 * @return array<string, int|string>
	 */
	private static function values( array $sizes ): array {
		unset( $sizes['status'] );
		return $sizes;
	}

	/**
	 * Bloc `sizes` sans aucune valeur.
	 *
	 * @return array<string, string|null>
	 */
	private static function empty_sizes( string $status ): array {
		return [
			'status'           => $status,
			'measured_at'      => null,
			'wp_content_bytes' => null,
			'uploads_bytes'    => null,
			'files_counted'    => null,
			'db_bytes'         => null,
			'db_status'        => null,
			'scan_ms'          => null,
		];
	}

	/**
	 * Appels du journal qui touchent au blog courant ou au dossier des médias.
	 *
	 * @return list<string>
	 */
	private function blog_calls(): array {
		return array_values(
			array_filter(
				$this->log,
				static fn ( string $entry ): bool => str_starts_with( $entry, 'switch_to_blog' ) || 'restore_current_blog' === $entry || 'wp_get_upload_dir' === $entry
			)
		);
	}

	/**
	 * Démarre le plugin, toutes les tâches déjà planifiées et phasées.
	 */
	private function boot( bool $enrolled ): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		Functions\when( 'wp_next_scheduled' )->justReturn( self::T );
		Functions\when( 'wp_get_scheduled_event' )->justReturn(
			(object) [
				'timestamp' => self::T,
				'schedule'  => 'twicedaily',
			]
		);
		$this->options[ Phase::OPTION ] = Phase::VERSION;
		if ( $enrolled ) {
			$this->options[ Settings::OPTION_KEY ] = [
				'site_id'    => 7,
				'site_token' => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
			];
		}

		Plugin::instance()->boot();
	}

	/**
	 * Faux $wpdb : verrou consultatif (GET_LOCK programmé, RELEASE_LOCK) et taille de la
	 * base (SHOW TABLE STATUS), journalisés.
	 *
	 * @param string|null|\Throwable $get_lock      Réponse de GET_LOCK (`'1'` pris, `'0'` tenu ailleurs, null ou exception : indisponible).
	 * @param (\Closure(): void)|null $while_locking Joué pendant la prise du verrou (autre processus).
	 */
	private function install_wpdb( string|null|\Throwable $get_lock, ?\Closure $while_locking = null ): void {
		$log             = function ( string $entry ): void {
			$this->log[] = $entry;
		};
		$GLOBALS['wpdb'] = new class( $get_lock, $while_locking, $log ) {

			public string $options = 'wp_options';

			public string $base_prefix = 'wp_';

			public string $last_error = '';

			/** @var list<array{string, string, int|null}> */
			public array $lock_calls = [];

			public function __construct(
				private string|null|\Throwable $get_lock,
				private ?\Closure $while_locking,
				private \Closure $log
			) {}

			public function prepare( string $query, mixed ...$args ): string {
				return (string) preg_replace_callback(
					'/%[sd]/',
					static function ( array $placeholder ) use ( &$args ): string {
						$value = array_shift( $args );
						return '%d' === $placeholder[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
					},
					$query
				);
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function suppress_errors( bool $suppress = true ): bool {
				return false;
			}

			public function get_var( string $query ): ?string {
				if ( 1 !== preg_match( "/^SELECT (GET_LOCK|RELEASE_LOCK)\('([^']*)'(?:, (\d+))?\)$/", $query, $m ) ) {
					throw new \LogicException( 'requête inattendue : ' . $query );
				}
				( $this->log )( $m[1] );
				$this->lock_calls[] = [ $m[1], $m[2], isset( $m[3] ) ? (int) $m[3] : null ];
				if ( 'RELEASE_LOCK' === $m[1] ) {
					return '1';
				}
				if ( null !== $this->while_locking ) {
					( $this->while_locking )();
				}
				if ( $this->get_lock instanceof \Throwable ) {
					throw $this->get_lock;
				}
				return $this->get_lock;
			}

			/**
			 * @return list<array<string, string>>
			 */
			public function get_results( string $query, mixed $output ): array {
				unset( $output );
				if ( ! str_starts_with( $query, 'SHOW TABLE STATUS LIKE ' ) ) {
					throw new \LogicException( 'requête inattendue : ' . $query );
				}
				( $this->log )( 'SHOW TABLE STATUS' );
				return [
					[
						'Name'         => 'wp_options',
						'Data_length'  => '6000',
						'Index_length' => '1000',
					],
				];
			}
		};
	}
}
