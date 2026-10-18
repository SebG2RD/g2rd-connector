<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Cron;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Cron\Phase;
use G2RD\Connector\Plugin;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use WP_Error;

/**
 * E13 — Battement de cœur réparé au démarrage.
 *
 * Le battement n'était planifié qu'à l'enrôlement ; la désactivation le retire et ni
 * l'activation ni le démarrage ne le remettaient : après une désactivation puis une
 * réactivation, le site ne donnait plus signe de vie ET ne relevait plus la file des
 * commandes (les deux travaux de HeartbeatJob::run()). Désormais, chaque démarrage
 * d'un site enrôlé le replanifie s'il a disparu, à une minute tirée au hasard dans
 * l'heure (cf. Phase).
 *
 * Décision du propriétaire du 17/10 : la tâche est aussi branchée quand seules les
 * commandes à distance sont actives (battement coupé) ; sinon WP-Cron déclenchait le
 * hook sans aucun rappel accroché et la file n'était jamais relevée.
 *
 * Le planificateur de WordPress est simulé en mémoire, au format de l'option `cron`
 * ([ date => [ hook => [ clé => événement ] ] ]), comme dans PhaseTest.
 */
final class HeartbeatScheduleTest extends TestCase {

	private const T = 1789000000;

	private const SITE_ID = 7;

	private const HOUR = 3600;

	/** @var array<int, array<string, array<string, array{schedule: string|false, args: array<mixed>, interval?: int}>>> */
	private array $cron = [];

	/** @var list<string> Adresses demandées en GET au manager. */
	private array $gets = [];

	/** @var list<string> Adresses demandées en POST au manager. */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->cron  = [];
		$this->gets  = [];
		$this->posts = [];

		// Démarrage hors administration, réglages déjà autochargés (aucune migration).
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_load_alloptions' )->justReturn( [ Settings::OPTION_KEY => 'autochargée' ] );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );

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
					'hourly'     => self::HOUR,
					'twicedaily' => 12 * self::HOUR,
					'daily'      => 24 * self::HOUR,
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
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( int $timestamp, string $hook, array $args = [] ): bool {
				$key = md5( serialize( $args ) );
				if ( ! isset( $this->cron[ $timestamp ][ $hook ][ $key ] ) ) {
					return false;
				}
				unset( $this->cron[ $timestamp ][ $hook ][ $key ] );
				$this->prune( $timestamp, $hook );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook ): int {
				$count = 0;
				foreach ( array_keys( $this->cron ) as $timestamp ) {
					$count += count( $this->cron[ $timestamp ][ $hook ] ?? [] );
					unset( $this->cron[ $timestamp ][ $hook ] );
					$this->prune( $timestamp, $hook );
				}
				return $count;
			}
		);
	}

	public function test_planifie_si_absent_avec_une_phase_tiree_dans_l_heure(): void {
		$bornes = [];

		$avant = time();
		$fait  = HeartbeatJob::ensure_scheduled(
			static function ( int $min, int $max ) use ( &$bornes ): int {
				$bornes[] = [ $min, $max ];
				return 1234;
			}
		);
		$apres = time();

		self::assertTrue( $fait );
		self::assertSame( self::HOUR, HeartbeatJob::INTERVAL_SECONDS );
		self::assertSame( [ [ Phase::MIN_DELAY, self::HOUR ] ], $bornes, 'Tirage dans [délai minimal, une heure].' );
		$event = $this->recurring( HeartbeatJob::HOOK );
		self::assertSame( 'hourly', $event['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 1234, $event['timestamp'] );
		self::assertLessThanOrEqual( $apres + 1234, $event['timestamp'] );
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );
	}

	public function test_ne_touche_pas_un_battement_deja_planifie(): void {
		$this->schedule( self::T, 'hourly', HeartbeatJob::HOOK );
		Functions\expect( 'wp_schedule_event' )->never();
		$jamais = static function (): int {
			throw new \LogicException( 'Aucun tirage attendu.' );
		};

		self::assertFalse( HeartbeatJob::ensure_scheduled( $jamais ) );

		self::assertSame( self::T, $this->recurring( HeartbeatJob::HOOK )['timestamp'] );
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );
	}

	public function test_repare_apres_desactivation_puis_reactivation(): void {
		$this->enrol( [ 'heartbeat_enabled' => true ] );
		// Battement planifié à l'enrôlement.
		$this->schedule( self::T + 60, 'hourly', HeartbeatJob::HOOK );

		Plugin::deactivate();
		self::assertSame( 0, $this->events_of( HeartbeatJob::HOOK ), 'La désactivation retire le battement.' );
		Plugin::activate();
		self::assertSame( 0, $this->events_of( HeartbeatJob::HOOK ), 'L\'activation ne planifie rien d\'elle-même.' );

		$avant = time();
		Plugin::instance()->boot();
		$apres = time();

		$event = $this->recurring( HeartbeatJob::HOOK );
		self::assertSame( 'hourly', $event['schedule'] );
		self::assertGreaterThanOrEqual( $avant + Phase::MIN_DELAY, $event['timestamp'] );
		self::assertLessThanOrEqual( $apres + self::HOUR, $event['timestamp'] );
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );

		// Démarrages suivants : le battement réparé est gardé, jamais doublé.
		Plugin::instance()->boot();
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ), 'Jamais deux événements.' );
		self::assertSame( $event['timestamp'], $this->recurring( HeartbeatJob::HOOK )['timestamp'] );
	}

	/** Site pas encore enrôlé : rien n'est planifié (aucun appel sortant sans consentement). */
	public function test_rien_avant_l_enrolement(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'manager_url' => 'https://manager.invalid' ];
		Actions\expectAdded( HeartbeatJob::HOOK )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		Plugin::instance()->boot();

		self::assertSame( [], $this->cron );
	}

	public function test_rien_si_battement_et_commandes_coupes(): void {
		$this->enrol(
			[
				'heartbeat_enabled'       => false,
				'remote_commands_enabled' => false,
			]
		);
		Actions\expectAdded( HeartbeatJob::HOOK )->never();

		Plugin::instance()->boot();

		self::assertFalse( has_action( HeartbeatJob::HOOK ) );
		self::assertSame( 0, $this->events_of( HeartbeatJob::HOOK ) );
	}

	/** Battement coupé, commandes à distance actives : la tâche horaire est branchée et planifiée (décision du 17/10). */
	public function test_commandes_seules_branchent_et_planifient_la_tache(): void {
		$this->enrol(
			[
				'heartbeat_enabled'       => false,
				'remote_commands_enabled' => true,
			]
		);

		Plugin::instance()->boot();

		self::assertNotFalse( has_action( HeartbeatJob::HOOK ) );
		self::assertSame( 'hourly', $this->recurring( HeartbeatJob::HOOK )['schedule'] );
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );
	}

	/**
	 * Même réglage, rappel joué : la file des commandes est relevée, aucun signe de vie
	 * n'est envoyé. Brain Monkey n'exécute pas les rappels sur do_action() : le rappel
	 * accroché par le démarrage est capturé, puis appelé comme WP-Cron le ferait.
	 */
	public function test_commandes_seules_relevent_la_file_sans_signe_de_vie(): void {
		$this->enrol(
			[
				'heartbeat_enabled'       => false,
				'remote_commands_enabled' => true,
			]
		);
		$rappel = null;
		Actions\expectAdded( HeartbeatJob::HOOK )->once()->whenHappen(
			static function ( $callback ) use ( &$rappel ): void {
				$rappel = $callback;
			}
		);
		$this->stub_manager();

		Plugin::instance()->boot();
		self::assertIsCallable( $rappel );
		$rappel();

		self::assertSame( [ 'https://manager.invalid/api/agent/sites/7/commands' ], $this->gets, 'La file est relevée.' );
		self::assertSame(
			[],
			array_values( array_filter( $this->posts, static fn ( string $url ): bool => str_ends_with( $url, '/heartbeat' ) ) ),
			'Aucun signe de vie envoyé, battement coupé.'
		);
	}

	/** Les deux réglages actifs : un seul rappel, donc un seul passage par heure. */
	public function test_battement_et_commandes_actifs_un_seul_rappel(): void {
		$this->enrol(
			[
				'heartbeat_enabled'       => true,
				'remote_commands_enabled' => true,
			]
		);
		Actions\expectAdded( HeartbeatJob::HOOK )->once();

		Plugin::instance()->boot();

		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );
	}

	public function test_battement_seul_inchange(): void {
		$this->enrol(
			[
				'heartbeat_enabled'       => true,
				'remote_commands_enabled' => false,
			]
		);

		Plugin::instance()->boot();

		self::assertNotFalse( has_action( HeartbeatJob::HOOK ) );
		self::assertSame( 'hourly', $this->recurring( HeartbeatJob::HOOK )['schedule'] );
		self::assertSame( 1, $this->events_of( HeartbeatJob::HOOK ) );
	}

	/** L'enrôlement (schedule()) garde son premier signe de vie une minute après. */
	public function test_l_enrolement_garde_son_premier_battement_rapide(): void {
		$avant = time();
		HeartbeatJob::schedule();
		$apres = time();

		$event = $this->recurring( HeartbeatJob::HOOK );
		self::assertSame( 'hourly', $event['schedule'] );
		self::assertGreaterThanOrEqual( $avant + 60, $event['timestamp'] );
		self::assertLessThanOrEqual( $apres + 60, $event['timestamp'] );
	}

	// ── Outils ──────────────────────────────────────────────────────────────────

	/**
	 * Site enrôlé, avec les réglages donnés.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function enrol( array $settings ): void {
		$this->options[ Settings::OPTION_KEY ] = array_merge(
			[
				'manager_url' => 'https://manager.invalid',
				'site_id'     => self::SITE_ID,
				'site_token'  => Settings::encrypt_token( 'jeton-du-site', 'v1' ),
			],
			$settings
		);
	}

	/**
	 * Doublure du manager : file vide, chaque adresse demandée est enregistrée. Le
	 * signe de vie a tout ce qu'il lui faut : s'il partait, c'est l'assertion qui le
	 * dirait, pas une fonction manquante.
	 */
	private function stub_manager(): void {
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'count_users' )->justReturn( [ 'total_users' => 1 ] );
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ): array {
				$this->gets[] = $url;
				return [ 'body' => '{"commands":[]}' ];
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url ): array {
				$this->posts[] = $url;
				return [ 'body' => '{"ok":true}' ];
			}
		);
	}

	private function schedule( int $timestamp, string $recurrence, string $hook ): void {
		wp_schedule_event( $timestamp, $recurrence, $hook );
	}

	/** Retire les niveaux vides du planificateur simulé. */
	private function prune( int $timestamp, string $hook ): void {
		if ( isset( $this->cron[ $timestamp ][ $hook ] ) && [] === $this->cron[ $timestamp ][ $hook ] ) {
			unset( $this->cron[ $timestamp ][ $hook ] );
		}
		if ( isset( $this->cron[ $timestamp ] ) && [] === $this->cron[ $timestamp ] ) {
			unset( $this->cron[ $timestamp ] );
		}
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
				return [
					'hook'      => $hook,
					'timestamp' => (int) $timestamp,
				] + $event;
			}
		}
		return null;
	}
}
