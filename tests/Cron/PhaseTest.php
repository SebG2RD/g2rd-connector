<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Cron;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Cron\Phase;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Cron\UpdatesDiscoveryJob;
use G2RD\Connector\Plugin;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Rest\CommandRunsController;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\WporgAnswers;

/**
 * Phases des tâches WP-Cron récurrentes du connecteur, tirées au hasard.
 *
 * Mesuré en production le 11/10/2026 : la purge des points de restauration passait à
 * :33-:34 sur 18 sites, la découverte des mises à jour vers 02:05 et 14:05 UTC sur 20
 * sites — toutes planifiées au premier démarrage qui suivait la même mise à jour du
 * connecteur (`time() + 300`, `time() + 120`), et WordPress garde la phase d'un
 * événement récurrent d'un passage à l'autre (wp_reschedule_event()). Sur un serveur
 * mutualisé partagé avec une trentaine de sites, tout partait à la même minute.
 *
 * Le planificateur de WordPress est simulé en mémoire, au format de l'option `cron`
 * ([ date => [ hook => [ clé => événement ] ] ]).
 */
final class PhaseTest extends TestCase {

	private const T = 1789000000;

	private const TWICE_DAILY = 12 * 3600;

	/** @var array<int, array<string, array<string, array{schedule: string|false, args: array<mixed>, interval?: int}>>> */
	private array $cron = [];

	private bool $unschedule_fails = false;

	protected function setUp(): void {
		parent::setUp();
		$this->cron             = [];
		$this->unschedule_fails = false;

		Functions\when( '_get_cron_array' )->alias( fn (): array => $this->cron );
		Functions\when( 'wp_next_scheduled' )->alias(
			fn ( string $hook ) => $this->next( $hook )['timestamp'] ?? false
		);
		Functions\when( 'wp_get_scheduled_event' )->alias(
			function ( string $hook ) {
				$next = $this->next( $hook );
				return null === $next ? false : (object) $next;
			}
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool {
				$intervals = [
					'hourly'     => 3600,
					'twicedaily' => self::TWICE_DAILY,
					'daily'      => 86400,
				];
				$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = [
					'schedule' => $recurrence,
					'args'     => $args,
					'interval' => $intervals[ $recurrence ],
				];
				ksort( $this->cron );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook, array $args = [] ): bool {
				$this->cron[ $timestamp ][ $hook ][ md5( serialize( $args ) ) ] = [
					'schedule' => false,
					'args'     => $args,
				];
				ksort( $this->cron );
				return true;
			}
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( int $timestamp, string $hook, array $args = [] ): bool {
				$key = md5( serialize( $args ) );
				if ( $this->unschedule_fails || ! isset( $this->cron[ $timestamp ][ $hook ][ $key ] ) ) {
					return false;
				}
				unset( $this->cron[ $timestamp ][ $hook ][ $key ] );
				if ( [] === $this->cron[ $timestamp ][ $hook ] ) {
					unset( $this->cron[ $timestamp ][ $hook ] );
				}
				if ( [] === $this->cron[ $timestamp ] ) {
					unset( $this->cron[ $timestamp ] );
				}
				return true;
			}
		);
	}

	public function test_le_premier_passage_est_tire_entre_le_delai_minimal_et_l_intervalle(): void {
		$bornes = [];
		$tirage = static function ( int $min, int $max ) use ( &$bornes ): int {
			$bornes[] = [ $min, $max ];
			return $max;
		};

		self::assertSame( self::T + self::TWICE_DAILY, Phase::first_run( self::T, self::TWICE_DAILY, $tirage ) );
		self::assertSame( [ [ Phase::MIN_DELAY, self::TWICE_DAILY ] ], $bornes, 'Tirage dans [délai minimal, intervalle], bornes incluses.' );

		self::assertSame( self::T + Phase::MIN_DELAY, Phase::first_run( self::T, self::TWICE_DAILY, static fn ( int $min ): int => $min ) );
		self::assertGreaterThan( 0, Phase::MIN_DELAY, 'Jamais dans le passé ni à l\'instant même.' );
	}

	/** Un générateur injecté qui sortirait des bornes ne place jamais l'événement ailleurs. */
	public function test_un_tirage_hors_bornes_est_ramene_dans_l_intervalle(): void {
		self::assertSame( self::T + Phase::MIN_DELAY, Phase::first_run( self::T, self::TWICE_DAILY, static fn (): int => -5 ) );
		self::assertSame( self::T + self::TWICE_DAILY, Phase::first_run( self::T, self::TWICE_DAILY, static fn (): int => PHP_INT_MAX ) );
	}

	public function test_le_tirage_par_defaut_reste_dans_les_bornes_et_varie(): void {
		$dates = [];
		for ( $i = 0; $i < 200; $i++ ) {
			$dates[] = Phase::first_run( self::T, self::TWICE_DAILY );
		}

		self::assertGreaterThanOrEqual( self::T + Phase::MIN_DELAY, min( $dates ) );
		self::assertLessThanOrEqual( self::T + self::TWICE_DAILY, max( $dates ) );
		self::assertGreaterThan( 1, count( array_unique( $dates ) ), 'Deux sites ne tirent pas la même phase.' );
	}

	public function test_une_decouverte_neuve_part_au_hasard_dans_les_douze_heures(): void {
		$bornes = [];
		$avant  = time();
		UpdatesDiscoveryJob::schedule(
			static function ( int $min, int $max ) use ( &$bornes ): int {
				$bornes[] = [ $min, $max ];
				return 7777;
			}
		);
		$apres = time();

		self::assertSame( [ [ Phase::MIN_DELAY, self::TWICE_DAILY ] ], $bornes );
		$event = $this->recurring( UpdatesDiscoveryJob::HOOK );
		self::assertSame( 'twicedaily', $event['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 7777, $event['timestamp'] );
		self::assertLessThanOrEqual( $apres + 7777, $event['timestamp'] );
	}

	public function test_une_purge_neuve_part_au_hasard_dans_les_douze_heures(): void {
		$bornes = [];
		$avant  = time();
		RestorePointPurgeJob::schedule(
			static function ( int $min, int $max ) use ( &$bornes ): int {
				$bornes[] = [ $min, $max ];
				return 9999;
			}
		);
		$apres = time();

		self::assertSame( [ [ Phase::MIN_DELAY, self::TWICE_DAILY ] ], $bornes );
		$event = $this->recurring( RestorePointPurgeJob::HOOK );
		self::assertSame( RestorePointPurgeJob::RECURRENCE, $event['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 9999, $event['timestamp'] );
		self::assertLessThanOrEqual( $apres + 9999, $event['timestamp'] );
	}

	/** Le démarrage ne retire jamais une planification existante : il ne tire rien. */
	public function test_une_planification_existante_n_est_pas_retiree_au_demarrage(): void {
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		$this->schedule( self::T + 60, 'twicedaily', RestorePointPurgeJob::HOOK );
		$jamais = static function (): int {
			throw new \LogicException( 'Aucun tirage attendu.' );
		};

		UpdatesDiscoveryJob::schedule( $jamais );
		RestorePointPurgeJob::schedule( $jamais );

		self::assertSame( self::T, $this->recurring( UpdatesDiscoveryJob::HOOK )['timestamp'] );
		self::assertSame( self::T + 60, $this->recurring( RestorePointPurgeJob::HOOK )['timestamp'] );
	}

	public function test_la_migration_re_phase_une_fois_les_taches_existantes(): void {
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		$this->schedule( self::T + 60, 'twicedaily', RestorePointPurgeJob::HOOK );
		$this->schedule( self::T + 120, 'hourly', HeartbeatJob::HOOK );
		$tirages = [ 1000, 2000 ];

		$avant = time();
		self::assertTrue(
			Phase::maybe_rephase(
				[ UpdatesDiscoveryJob::HOOK, RestorePointPurgeJob::HOOK ],
				static function () use ( &$tirages ): int {
					return (int) array_shift( $tirages );
				}
			)
		);
		$apres = time();

		$decouverte = $this->recurring( UpdatesDiscoveryJob::HOOK );
		self::assertSame( 'twicedaily', $decouverte['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 1000, $decouverte['timestamp'] );
		self::assertLessThanOrEqual( $apres + 1000, $decouverte['timestamp'] );

		$purge = $this->recurring( RestorePointPurgeJob::HOOK );
		self::assertSame( 'twicedaily', $purge['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 2000, $purge['timestamp'] );
		self::assertLessThanOrEqual( $apres + 2000, $purge['timestamp'] );

		self::assertSame( 1, $this->events_of( UpdatesDiscoveryJob::HOOK ), 'Aucun doublon.' );
		self::assertSame( 1, $this->events_of( RestorePointPurgeJob::HOOK ), 'Aucun doublon.' );
		self::assertSame( self::T + 120, $this->recurring( HeartbeatJob::HOOK )['timestamp'], 'Le heartbeat, déjà réparti par l\'heure d\'enrôlement, n\'est pas touché.' );
		self::assertSame( Phase::VERSION, $this->options[ Phase::OPTION ] ?? null );
	}

	public function test_la_migration_n_a_lieu_qu_une_fois(): void {
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], static fn (): int => 1000 );
		$apres_migration = $this->cron;

		$jamais = static function (): int {
			throw new \LogicException( 'Aucun tirage attendu.' );
		};
		self::assertFalse( Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], $jamais ) );
		self::assertFalse( Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], $jamais ) );

		self::assertSame( $apres_migration, $this->cron );
	}

	/** Le marqueur est écrit AVANT : s'il ne peut pas l'être, rien n'est re-phasé (sinon chaque démarrage repousserait la tâche). */
	public function test_sans_marqueur_ecrit_rien_n_est_re_phase(): void {
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		Functions\when( 'update_option' )->justReturn( false );
		Functions\expect( 'wp_unschedule_event' )->never();

		self::assertFalse( Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], static fn (): int => 1000 ) );

		self::assertSame( self::T, $this->recurring( UpdatesDiscoveryJob::HOOK )['timestamp'] );
	}

	/** Si WordPress refuse de retirer l'événement, rien n'est ajouté : la tâche garde sa date, sans doublon. */
	public function test_un_retrait_refuse_ne_cree_pas_de_doublon(): void {
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		$this->unschedule_fails = true;
		Functions\expect( 'wp_schedule_event' )->never();

		Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], static fn (): int => 1000 );

		self::assertSame( 1, $this->events_of( UpdatesDiscoveryJob::HOOK ) );
		self::assertSame( self::T, $this->recurring( UpdatesDiscoveryJob::HOOK )['timestamp'] );
	}

	/**
	 * Une découverte immédiate en attente (UpdatesDiscoveryJob::request_now(), même
	 * hook) n'est pas prise pour la tâche récurrente : elle reste, la récurrente est
	 * re-phasée.
	 */
	public function test_un_passage_ponctuel_en_attente_n_est_pas_pris_pour_la_tache_recurrente(): void {
		$this->cron[ self::T - 100 ][ UpdatesDiscoveryJob::HOOK ][ md5( serialize( [] ) ) ] = [
			'schedule' => false,
			'args'     => [],
		];
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );

		$avant = time();
		Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK ], static fn (): int => 1000 );

		self::assertArrayHasKey( self::T - 100, $this->cron, 'Le passage ponctuel reste programmé.' );
		self::assertGreaterThanOrEqual( $avant + 1000, $this->recurring( UpdatesDiscoveryJob::HOOK )['timestamp'] );
		self::assertSame( 2, $this->events_of( UpdatesDiscoveryJob::HOOK ) );
	}

	/** Rien de planifié : la migration ne crée rien (c'est le rôle de schedule()). */
	public function test_sans_evenement_la_migration_ne_cree_rien(): void {
		self::assertTrue( Phase::maybe_rephase( [ UpdatesDiscoveryJob::HOOK, RestorePointPurgeJob::HOOK ], static fn (): int => 1000 ) );

		self::assertSame( [], $this->cron );
	}

	/** Une purge encore horaire (migration d'avant la 1.13 pas encore faite) garde son rythme, re-phasée dans l'heure. */
	public function test_le_rythme_de_l_evenement_est_conserve(): void {
		$this->schedule( self::T, 'hourly', RestorePointPurgeJob::HOOK );
		$bornes = [];

		Phase::maybe_rephase(
			[ RestorePointPurgeJob::HOOK ],
			static function ( int $min, int $max ) use ( &$bornes ): int {
				$bornes[] = [ $min, $max ];
				return 600;
			}
		);

		self::assertSame( [ [ Phase::MIN_DELAY, 3600 ] ], $bornes );
		self::assertSame( 'hourly', $this->recurring( RestorePointPurgeJob::HOOK )['schedule'] );
	}

	/** Branchement : le démarrage d'un site enrôlé re-phase la découverte et la purge déjà planifiées. */
	public function test_le_demarrage_d_un_site_enrole_re_phase_les_taches_existantes(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
		];
		$this->schedule( self::T, 'twicedaily', UpdatesDiscoveryJob::HOOK );
		$this->schedule( self::T + 60, 'twicedaily', RestorePointPurgeJob::HOOK );

		$avant = time();
		Plugin::instance()->boot();
		$apres = time();

		foreach ( [ UpdatesDiscoveryJob::HOOK, RestorePointPurgeJob::HOOK ] as $hook ) {
			$event = $this->recurring( $hook );
			self::assertSame( 'twicedaily', $event['schedule'] );
			self::assertGreaterThanOrEqual( $avant + Phase::MIN_DELAY, $event['timestamp'], $hook );
			self::assertLessThanOrEqual( $apres + self::TWICE_DAILY, $event['timestamp'], $hook );
			self::assertSame( 1, $this->events_of( $hook ), $hook );
		}
		self::assertSame( Phase::VERSION, $this->options[ Phase::OPTION ] ?? null );
	}

	/**
	 * Même démarrage d'un site enrôlé (ce banc d'essai simule le planificateur qu'il
	 * touche) : l'écoute des réponses de wordpress.org est branchée, sans quoi
	 * l'inventaire rafraîchirait à chaque synchronisation (cf. Updates\WporgAnswers).
	 */
	public function test_le_demarrage_d_un_site_enrole_note_les_reponses_de_wordpress_org(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
		];

		Plugin::instance()->boot();

		self::assertNotFalse( has_action( 'http_api_debug', [ WporgAnswers::class, 'record' ] ) );
		self::assertNotFalse( has_filter( 'pre_http_request', [ WporgAnswers::class, 'observe_preempted' ] ), 'Requêtes court-circuitées (mu-plugin Hostinger) aussi.' );
	}

	/**
	 * Même démarrage d'un site enrôlé : la purge des exécutions en arrière-plan (cf.
	 * CommandRuns\RunStore) part avec la tâche biquotidienne, comme celle des tickets de
	 * connexion directe, sans toucher à RestorePointPurgeJob::purge().
	 */
	public function test_le_demarrage_d_un_site_enrole_branche_la_purge_des_executions(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
		];

		Plugin::instance()->boot();

		self::assertNotFalse( has_action( RestorePointPurgeJob::HOOK, [ RunStore::class, 'purge_now' ] ) );
	}

	/**
	 * Même démarrage d'un site enrôlé, commandes à distance permises (réglage par défaut) :
	 * les deux routes des commandes en arrière-plan sont enregistrées comme `/command`, la
	 * garde de taille passe avant l'anti-cache (priorité 5, trois arguments, sinon elle ne
	 * voit pas la requête) et le filtre « répondre, puis exécuter » passe en dernier
	 * (priorité 1000, quatre arguments), après les filtres qui posent des en-têtes.
	 */
	public function test_le_demarrage_d_un_site_enrole_branche_les_commandes_en_arriere_plan(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
		];
		$runner = AfterResponseRunner::instance();
		Filters\expectAdded( 'rest_pre_dispatch' )->once()->with( [ CommandRunsController::class, 'guard_body_size' ], 5, 3 );
		Filters\expectAdded( 'rest_pre_dispatch' )->with( [ Plugin::instance(), 'prevent_rest_cache' ], 10, 3 );
		Filters\expectAdded( 'rest_pre_serve_request' )->once()->with( [ $runner, 'maybe_serve' ], 1000, 4 );

		Plugin::instance()->boot();

		self::assertSame( 10, has_action( 'rest_api_init', CommandRunsController::class . '->register()' ) );
		self::assertSame( 10, has_action( 'rest_api_init', CommandController::class . '->register()' ) );
	}

	/**
	 * Commandes à distance coupées : ni `/command`, ni les deux routes, ni leurs filtres
	 * (les routes existent dans les mêmes conditions que `/command`). L'inventaire reste.
	 */
	public function test_commandes_distantes_coupees_rien_n_est_branche(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'                 => 7,
			'site_token'              => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
			'remote_commands_enabled' => false,
		];

		Plugin::instance()->boot();

		self::assertFalse( has_action( 'rest_api_init', CommandRunsController::class . '->register()' ) );
		self::assertFalse( has_action( 'rest_api_init', CommandController::class . '->register()' ) );
		self::assertFalse( has_filter( 'rest_pre_dispatch', [ CommandRunsController::class, 'guard_body_size' ] ) );
		self::assertFalse( has_filter( 'rest_pre_serve_request' ) );
		self::assertSame( 10, has_action( 'rest_api_init', SnapshotController::class . '->register()' ) );
	}

	/** Site pas encore enrôlé : rien n'est écrit, donc rien n'est écouté (guideline 7). */
	public function test_un_site_non_enrole_n_ecoute_pas_les_reponses_de_wordpress_org(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [] );

		Plugin::instance()->boot();

		self::assertFalse( has_action( 'http_api_debug', [ WporgAnswers::class, 'record' ] ) );
		self::assertFalse( has_filter( 'pre_http_request', [ WporgAnswers::class, 'observe_preempted' ] ) );
	}

	private function schedule( int $timestamp, string $recurrence, string $hook ): void {
		wp_schedule_event( $timestamp, $recurrence, $hook );
	}

	/**
	 * Événement récurrent d'un hook.
	 *
	 * @return array{timestamp: int, schedule: string}
	 */
	private function recurring( string $hook ): array {
		foreach ( $this->cron as $timestamp => $hooks ) {
			foreach ( $hooks[ $hook ] ?? [] as $event ) {
				if ( false !== $event['schedule'] ) {
					return [
						'timestamp' => (int) $timestamp,
						'schedule'  => (string) $event['schedule'],
					];
				}
			}
		}
		self::fail( 'Aucun événement récurrent pour ' . $hook );
	}

	/** Nombre d'événements (récurrents et ponctuels) d'un hook. */
	private function events_of( string $hook ): int {
		$count = 0;
		foreach ( $this->cron as $hooks ) {
			$count += count( $hooks[ $hook ] ?? [] );
		}
		return $count;
	}

	/**
	 * Prochain événement d'un hook (comme wp_get_scheduled_event() sans date).
	 *
	 * @return array{hook: string, timestamp: int, schedule: string|false, args: array<mixed>, interval?: int}|null
	 */
	private function next( string $hook ): ?array {
		foreach ( $this->cron as $timestamp => $hooks ) {
			foreach ( $hooks[ $hook ] ?? [] as $event ) {
				return [ 'hook' => $hook, 'timestamp' => (int) $timestamp ] + $event;
			}
		}
		return null;
	}
}
