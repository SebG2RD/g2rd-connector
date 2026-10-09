<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Rollback\PluginRestorer;
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Services;
use G2RD\Connector\Rollback\Snapshotter;
use G2RD\Connector\Rollback\UpdateTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugin_Upgrader;
use WP_Error;

/**
 * Mise à jour protégée de bout en bout, sur de vrais fichiers : le « WordPress » est
 * simulé (get_plugins lit les en-têtes sur le disque, Plugin_Upgrader remplace les
 * fichiers), le loopback est scripté.
 */
final class ProtectedUpdateTest extends FilesystemTestCase {

	private const FILE = 'akismet/akismet.php';

	private RestorePointStore $store;
	/** @var list<array{code:int, body:string|callable, error?:string|null}> Réponses loopback, consommées dans l'ordre (home, admin, home, admin…). */
	private array $responses = [];
	private int $response_index = 0;
	private bool $active       = true;
	/** @var list<string> */
	private array $deactivated = [];
	/** @var list<string> */
	private array $activated = [];
	/** @var list<array{0:int, 1:string}> Événements ponctuels programmés (date, hook). */
	private array $single_events = [];
	/** @var (\Closure(int): void)|null Appelé à chaque sonde loopback, avec son rang (0, 1, 2…). */
	private ?\Closure $on_probe = null;
	/** @var array<string, array<string, string>>|null Liste des extensions en cache (cf. cache_plugin_list()). */
	private ?array $plugin_list = null;

	protected function setUp(): void {
		parent::setUp();
		Plugin_Upgrader::reset();
		$this->store           = new RestorePointStore();
		$this->responses       = [];
		$this->response_index  = 0;
		$this->active          = true;
		$this->deactivated     = [];
		$this->activated       = [];
		$this->single_events   = [];
		$this->on_probe        = null;
		$this->plugin_list     = null;

		// ── WordPress simulé ────────────────────────────────────────────────────
		Functions\when( 'get_plugins' )->alias( fn (): array => $this->plugins_on_disk() );
		Functions\when( 'is_plugin_active' )->alias( fn (): bool => $this->active );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'register_shutdown_function' )->justReturn( null );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook ): bool {
				$this->single_events[] = [ $timestamp, $hook ];
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( string $hook ) {
				foreach ( $this->single_events as [ $timestamp, $event_hook ] ) {
					if ( $event_hook === $hook ) {
						return $timestamp;
					}
				}
				return false;
			}
		);
		Functions\when( 'plugin_basename' )->alias( static fn ( string $f ): string => 'g2rd-connector/g2rd-connector.php' );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'deactivate_plugins' )->alias(
			function ( $plugins ): void {
				$this->deactivated[] = (string) $plugins;
				$this->active        = false;
			}
		);
		Functions\when( 'activate_plugin' )->alias(
			function ( string $file ): ?WP_Error {
				$this->activated[] = $file;
				$this->active      = true;
				return null;
			}
		);
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_update_themes' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		// Seul `upgrader_pre_install` agit vraiment (Plugin_Upgrader simulé l'applique
		// avant de remplacer les fichiers) ; les autres filtres restent sans effet.
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ): bool {
				if ( 'upgrader_pre_install' === $hook ) {
					$this->filters[ $hook ][ $priority ][] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ): bool {
				foreach ( $this->filters[ $hook ][ $priority ] ?? [] as $i => $registered ) {
					if ( $registered === $callback ) {
						unset( $this->filters[ $hook ][ $priority ][ $i ] );
						return true;
					}
				}
				return false;
			}
		);
		// HealthChecker
		Functions\when( 'home_url' )->alias( static fn ( string $p = '' ): string => 'https://site.test' . $p );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://site.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ): string {
				if ( is_array( $key ) ) {
					return $value . '?' . http_build_query( $key );
				}
				return $url . '?' . $key . '=' . $value;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		$health = new HealthChecker(
			function ( string $url ): array {
				if ( null !== $this->on_probe ) {
					( $this->on_probe )( $this->response_index );
				}
				$r = $this->responses[ $this->response_index ] ?? [ 'code' => 200, 'body' => 'ok' ];
				++$this->response_index;
				$body = $r['body'];
				if ( is_callable( $body ) ) {
					parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
					$body = $body( (string) ( $q['token'] ?? '' ) );
				}
				return [
					'code'  => $r['code'],
					'body'  => $body,
					'error' => $r['error'] ?? null,
				];
			}
		);
		Services::override( new Services( $this->store, new Snapshotter( $this->store, $this->plugins ), new PluginRestorer( $this->plugins ), $health, $this->plugins ) );

		// Plugin 1.0 sur le disque ; l'« upgrade » le passe en 2.0 (fichier ajouté + en-tête).
		$this->make_plugin( 'akismet', '1.0', [ 'assets/old.css' => 'old' ] );
		Plugin_Upgrader::$on_upgrade = function (): void {
			$dir = $this->plugins . '/akismet';
			file_put_contents( $dir . '/akismet.php', "<?php\n/**\n * Plugin Name: akismet\n * Version: 2.0\n */\nnew_feature();\n" );
			file_put_contents( $dir . '/inc/new-module.php', "<?php\n" );
			unlink( $dir . '/assets/old.css' );
			$this->active = false; // le cœur désactive pendant l'upgrade
		};
	}

	protected function tearDown(): void {
		Services::override( null );
		parent::tearDown();
	}

	// ── Scénarios ─────────────────────────────────────────────────────────────

	public function test_healthy_update_keeps_the_restore_point_for_the_grace_period(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$before = time();

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) );

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertSame( 'healthy', $r['health']['verdict'] );
		// Forme historique conservée…
		self::assertTrue( $r['updated'] );
		self::assertSame( '1.0', $r['version_before'] );
		self::assertSame( '2.0', $r['version_after'] );
		self::assertTrue( $r['reactivated'] );
		// …plus le point de restauration, avec son délai de grâce.
		self::assertNotNull( $r['restore_point'] );
		self::assertSame( '1.0', $r['restore_point']['version'] );
		self::assertGreaterThanOrEqual( $before + 3600, $r['restore_point']['expires_at'] );
		self::assertFalse( $r['restore_point']['hold'] );
		self::assertNotNull( $this->store->get( $r['restore_point']['id'] ) );
		self::assertFileExists( (string) $this->store->path_for( $this->store->get( $r['restore_point']['id'] ) ) );
		self::assertNull( UpdateTransaction::current(), 'transaction close' );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
	}

	/** Plan Free : site sain → le point est purgé aussitôt (design D2). */
	public function test_healthy_update_without_keep_purges_the_restore_point(): void {
		$this->script_health( ok: 2, then_ok: 2 );

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => false ] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertNull( $r['restore_point'] );
		self::assertSame( [], $this->store->all() );
		self::assertSame( [], glob( $this->snapshots . '/*.zip' ) ?: [] );
	}

	/**
	 * LE cas qui justifie le chantier : la mise à jour casse le site → rollback
	 * automatique, arborescence 1.0 à l'identique, plugin réactivé, version 2.0 bloquée.
	 */
	public function test_broken_site_is_rolled_back_automatically(): void {
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		// Référence saine (home, admin) ; après : accueil en 500, admin en fatale ; après rollback : sain.
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => "<b>Fatal error</b>: Uncaught Error" ],
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
		];

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) );

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLED_BACK, $r['outcome'] );
		self::assertSame( 'broken', $r['health']['verdict'] );
		self::assertFalse( $r['updated'] );
		self::assertSame( '1.0', $r['version_after'], 'la version rapportée est celle APRÈS rollback' );
		self::assertSame( [ 'from' => '2.0', 'to' => '1.0' ], $r['rolled_back'] );
		self::assertSame( 'healthy', $r['health']['after_rollback'] === null ? '' : HealthChecker::verdict( $r['health']['baseline'], $r['health']['after_rollback'] ) );

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'fichiers 1.0 restaurés à l\'identique' );
		self::assertSame( [ self::FILE ], $this->deactivated, 'désactivé avant restauration' );
		self::assertTrue( $this->active, 'réactivé après restauration' );
		self::assertSame( [ self::FILE => '2.0' ], AutoUpdateGuard::all(), 'WordPress ne réinstallera pas 2.0 tout seul' );
		self::assertTrue( $r['restore_point']['hold'], 'le point est retenu jusqu\'à résolution' );
		self::assertNull( UpdateTransaction::current() );
	}

	/** Loopback impossible après la MAJ : « non vérifiable » ≠ cassé ; le point est gardé même sans plan (D3). */
	public function test_unverifiable_health_keeps_the_update_and_the_point(): void {
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 0, 'body' => '', 'error' => 'cURL error 7' ],
			[ 'code' => 403, 'body' => 'Forbidden' ],
		];

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => false ] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertSame( 'unverifiable', $r['health']['verdict'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'], 'la mise à jour est conservée' );
		self::assertNotNull( $r['restore_point'] );
		self::assertGreaterThanOrEqual( time() + 72 * 3600 - 5, $r['restore_point']['expires_at'] );
	}

	/** Budget insuffisant : refus AVANT la mise à jour, rien n'a changé. */
	public function test_disk_budget_refusal_happens_before_any_update(): void {
		$this->script_health( ok: 2, then_ok: 0 );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [ 'max_total_bytes' => 10 ] ) );

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( 'snapshot_failed_disk_space', $r['outcome'] );
		self::assertSame( 'snapshot_failed_disk_space', $r['error_code'] );
		self::assertFalse( $r['updated'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded, 'Plugin_Upgrader jamais appelé' );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertNull( UpdateTransaction::current() );
	}

	/** Version inchangée (premium sans licence) : pas de point à garder. */
	public function test_no_op_update_drops_the_restore_point(): void {
		Plugin_Upgrader::$on_upgrade = null;
		$this->script_health( ok: 2, then_ok: 0 );

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true ] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_NOT_UPDATED, $r['outcome'] );
		self::assertSame( 'version_unchanged', $r['reason'] );
		self::assertSame( [], $this->store->all() );
	}

	/** L'upgrader échoue : comportement historique (erreur) + point retenu. */
	public function test_upgrader_failure_keeps_the_point_on_hold_and_reports_the_error(): void {
		Plugin_Upgrader::$next_result = new WP_Error( 'download_failed', 'Téléchargement impossible.' );
		$this->script_health( ok: 2, then_ok: 0 );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Téléchargement impossible.', $outcome['error'] );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points );
		self::assertTrue( $points[0]['hold'] );
		self::assertNull( UpdateTransaction::current() );
	}

	public function test_a_fresh_concurrent_transaction_is_refused(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'autre/autre.php' ], time() );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'still running', $outcome['error'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded );
		self::assertSame( [], $this->single_events, 'Refusée avant ouverture : aucun contrôle de reprise à programmer.' );
	}

	/**
	 * Filet du filet : une requête tuée sans passer par le shutdown (processus arrêté
	 * par le serveur) laisse la transaction ouverte. La purge locale ne passant plus
	 * que deux fois par jour, un contrôle ponctuel est programmé dès l'ouverture, juste
	 * après le délai au-delà duquel la transaction est déclarée morte.
	 */
	public function test_the_update_schedules_a_recovery_check_when_the_transaction_opens(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$before = time();

		CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		$after = time();

		self::assertCount( 1, $this->single_events );
		[ $timestamp, $hook ] = $this->single_events[0];
		self::assertSame( RestorePointPurgeJob::RECOVERY_HOOK, $hook, 'Le contrôle a son propre hook : il ne lance pas la purge des points.' );
		self::assertGreaterThanOrEqual( $before + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
		self::assertLessThanOrEqual( $after + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
	}

	/**
	 * Mesure de référence lente (plus de 10 min d'horloge) : la transaction est
	 * rafraîchie juste après. Un contrôle de reprise qui tombe pendant la création du
	 * point (autre processus) ne prend donc pas la mise à jour vivante pour morte.
	 */
	public function test_a_slow_baseline_measure_does_not_let_a_concurrent_check_take_the_live_update_for_dead(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$this->on_probe = function ( int $probe ): void {
			if ( 1 === $probe ) { // Dernière sonde de la référence : la mesure a duré plus de 10 min.
				$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			}
		};
		$checked_during_snapshot = false;
		$this->on_filter         = function ( string $hook ) use ( &$checked_during_snapshot ): void {
			if ( 'g2rd_connector_snapshots_dir' === $hook && 2 === $this->response_index && ! $checked_during_snapshot ) {
				$checked_during_snapshot = true;
				ProtectedUpdate::recover( time() ); // Le contrôle de reprise du cron, pendant la création du point.
			}
		};

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) )['result'];

		self::assertTrue( $checked_during_snapshot );
		self::assertSame( [], PendingOutcomes::all(), 'Aucune reprise : la mise à jour était vivante.' );
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Restauration lente : la transaction est rafraîchie avant la mesure qui suit le
	 * rollback. Un contrôle de reprise qui tombe pendant cette mesure ne restaure pas
	 * une seconde fois sous les pieds de la requête vivante.
	 */
	public function test_a_slow_restore_does_not_let_a_concurrent_check_restore_again(): void {
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => "<b>Fatal error</b>: Uncaught Error" ],
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
		];
		$restore_started = false;
		$this->on_filter = function ( string $hook ) use ( &$restore_started ): void {
			// Premier accès au dossier des points après le contrôle : début de la restauration.
			if ( 'g2rd_connector_snapshots_dir' === $hook && 4 === $this->response_index && ! $restore_started ) {
				$restore_started = true;
				$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 ); // La restauration dure plus de 10 min.
			}
		};
		$this->on_probe = static function ( int $probe ): void {
			if ( 4 === $probe ) {
				ProtectedUpdate::recover( time() ); // Le contrôle de reprise du cron, pendant la mesure d'après rollback.
			}
		};

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) )['result'];

		self::assertTrue( $restore_started );
		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLED_BACK, $r['outcome'] );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une seule restauration.' );
		self::assertSame( [], PendingOutcomes::all(), 'Aucune reprise : la mise à jour était vivante.' );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Requête morte pendant l'upgrade : la reprise restaure le point et consigne le
	 * résultat pour la plateforme.
	 */
	public function test_recover_restores_a_stale_transaction(): void {
		$snapshotter = new Snapshotter( $this->store, $this->plugins );
		$point       = $snapshotter->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/akismet' );

		// Upgrade « à moitié » : nouveau fichier, en-tête 2.0, et transaction restée ouverte.
		( Plugin_Upgrader::$on_upgrade )();
		UpdateTransaction::open( [ 'plugin_file' => self::FILE, 'was_active' => true ], time() - 1000 );
		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => $point['id'] ], time() - 900 );

		ProtectedUpdate::recover( time() );

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertTrue( $this->active );
		self::assertNull( UpdateTransaction::current() );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovered_rolled_back', $pending[0]['outcome'] );
		self::assertSame( $point['id'], $pending[0]['restore_point_id'] );
		self::assertTrue( $this->store->get( $point['id'] )['hold'] );
	}

	/**
	 * Transaction morte que la reprise n'arrive pas à retirer (delete_option() en
	 * échec) : l'extension est restaurée une fois, pas à chaque passage d'un contrôle
	 * (ni au filet de shutdown d'une requête suivante).
	 */
	public function test_recover_restores_only_once_a_dead_transaction_it_cannot_close(): void {
		$snapshotter = new Snapshotter( $this->store, $this->plugins );
		$point       = $snapshotter->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		( Plugin_Upgrader::$on_upgrade )();
		UpdateTransaction::open( [ 'plugin_file' => self::FILE, 'was_active' => true ], time() - 1000 );
		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => $point['id'] ], time() - 900 );
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				if ( UpdateTransaction::OPTION_KEY === $key ) {
					return false;
				}
				unset( $this->options[ $key ] );
				return true;
			}
		);

		ProtectedUpdate::recover( time() );
		ProtectedUpdate::recover( time() );
		ProtectedUpdate::recover_on_shutdown();

		self::assertNotNull( UpdateTransaction::current(), 'Toujours là : delete_option() échoue.' );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une seule restauration.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertTrue( $this->active );
		self::assertSame( [ 'recovered_rolled_back' ], array_column( PendingOutcomes::all(), 'outcome' ), 'Un seul résultat pour la plateforme.' );
	}

	public function test_recover_ignores_a_fresh_transaction_unless_forced(): void {
		UpdateTransaction::open( [ 'plugin_file' => self::FILE ], time() );
		ProtectedUpdate::recover( time() );
		self::assertNotNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time(), true );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( 'interrupted_unverified', PendingOutcomes::all()[0]['outcome'] );
	}

	// ── Une transaction morte est reprise avant d'en ouvrir une nouvelle ─────

	/**
	 * Incident du 23/09 : mise à jour tuée pendant l'étape « mise à jour » (extension
	 * désactivée, fichiers à moitié remplacés), puis une autre mise à jour lancée plus
	 * de 10 min après, avant tout contrôle du cron. La transaction morte n'est plus
	 * écrasée : elle est reprise une fois, puis la nouvelle mise à jour s'ouvre.
	 */
	public function test_a_dead_transaction_of_another_plugin_is_recovered_once_before_the_update_opens(): void {
		$file        = $this->make_plugin( 'autre', '1.0', [ 'assets/a.css' => 'a' ] );
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( $file, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/autre' );
		$this->half_upgrade( 'autre' );
		$this->dead_transaction( $file, $point['id'] );
		$this->script_health( ok: 2, then_ok: 2 );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'done', $outcome['status'] );
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $outcome['result']['outcome'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/autre' ), 'Extension de la mise à jour morte restaurée.' );
		self::assertSame( [ $file ], $this->deactivated, 'Restaurée une fois.' );
		self::assertTrue( $this->active, 'Réactivée.' );
		self::assertSame( [ [ $file, 'recovered_rolled_back' ] ], $this->pending_outcomes(), 'Résultat consigné pour la plateforme.' );
		self::assertTrue( $this->store->get( $point['id'] )['hold'] );
		self::assertNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time() + 3600 ); // Le contrôle du cron, plus tard.
		self::assertSame( [ $file ], $this->deactivated, 'Pas de seconde restauration.' );
		self::assertCount( 1, PendingOutcomes::all() );
	}

	/**
	 * Même extension : la reprise rétablit la version d'origine, la mise à jour relancée
	 * part de cette version. La liste des extensions, en cache comme dans WordPress,
	 * est relue après la restauration.
	 */
	public function test_a_dead_transaction_of_the_same_plugin_is_recovered_before_the_update(): void {
		$this->cache_plugin_list();
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )(); // Mise à jour morte à moitié : en-tête 2.0, extension désactivée.
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->script_health( ok: 2, then_ok: 2 );

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertTrue( $r['updated'] );
		self::assertSame( '1.0', $r['version_before'], 'Version rétablie par la reprise, pas celle de la mise à jour morte.' );
		self::assertSame( '2.0', $r['version_after'] );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une restauration.' );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	/** Reprise en cours dans un autre processus (contrôle du cron) : la mise à jour est refusée, rien n'est touché. */
	public function test_the_update_is_refused_while_a_recovery_is_running(): void {
		$recovering = [
			'id'               => 'morte',
			'plugin_file'      => 'autre/autre.php',
			'was_active'       => true,
			'step'             => UpdateTransaction::STEP_RECOVERING,
			'recovering_from'  => UpdateTransaction::STEP_UPGRADING,
			'recovery_token'   => 'jeton-du-cron',
			'restore_point_id' => 'p-autre',
			'started_at'       => time() - 1000,
			'updated_at'       => time() - 20,
		];
		$this->options[ UpdateTransaction::OPTION_KEY ] = $recovering;

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringStartsWith( 'another protected update is still running on this site', $outcome['error'], 'Refus déjà connu de la plateforme.' );
		self::assertStringContainsString( 'retry in a few minutes', $outcome['error'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded );
		self::assertSame( [], $this->deactivated );
		self::assertSame( $recovering, $this->options[ UpdateTransaction::OPTION_KEY ] );
		self::assertSame( [], PendingOutcomes::all() );
		self::assertSame( [], $this->single_events );
	}

	/** Le contrôle du cron tombe pendant la reprise que mène la mise à jour : une seule restauration. */
	public function test_a_recovery_check_during_the_update_s_own_recovery_does_not_restore_again(): void {
		$file = $this->make_plugin( 'autre', '1.0', [ 'assets/a.css' => 'a' ] );
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( $file, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$this->half_upgrade( 'autre' );
		$this->dead_transaction( $file, $point['id'] );
		$this->script_health( ok: 2, then_ok: 2 );
		$checked         = false;
		$this->on_filter = function ( string $hook ) use ( &$checked ): void {
			if ( ! $checked && 'g2rd_connector_snapshots_dir' === $hook && $this->recovery_running() ) {
				$checked = true;
				$this->as_another_process( static fn () => ProtectedUpdate::recover( time() + 30 ) );
			}
		};

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];

		self::assertTrue( $checked, 'Le contrôle est bien tombé pendant la reprise.' );
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertSame( [ $file ], $this->deactivated, 'Une seule restauration.' );
		self::assertSame( [ [ $file, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
	}

	/** Une mise à jour demandée pendant la reprise du cron est refusée ; l'extension est restaurée une fois. */
	public function test_an_update_requested_during_a_cron_recovery_is_refused(): void {
		$file        = $this->make_plugin( 'autre', '1.0', [ 'assets/a.css' => 'a' ] );
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( $file, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/autre' );
		$this->half_upgrade( 'autre' );
		$this->dead_transaction( $file, $point['id'] );
		$outcome         = null;
		$this->on_filter = function ( string $hook ) use ( &$outcome ): void {
			if ( null === $outcome && 'g2rd_connector_snapshots_dir' === $hook && $this->recovery_running() ) {
				$this->as_another_process(
					function () use ( &$outcome ): void {
						$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
					}
				);
			}
		};

		ProtectedUpdate::recover( time() ); // Le contrôle du cron.

		self::assertIsArray( $outcome, 'La mise à jour a bien été demandée pendant la reprise.' );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'still running', $outcome['error'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded );
		self::assertSame( [ $file ], $this->deactivated, 'Une seule restauration.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/autre' ) );
		self::assertSame( [ [ $file, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	// ── Seule la transaction du processus ───────────────────────────────────

	/**
	 * Création du point plus longue que 10 min : un contrôle du cron prend la mise à
	 * jour pour morte et ferme sa transaction. Pas de mise à jour sans transaction :
	 * la requête s'arrête avant de toucher à l'extension, avec un résultat explicite.
	 */
	public function test_the_update_stops_before_upgrading_when_its_transaction_was_taken_over(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$taken_over      = false;
		$this->on_filter = function ( string $hook ) use ( &$taken_over ): void {
			if ( ! $taken_over && 'g2rd_connector_snapshots_dir' === $hook && 2 === $this->response_index ) {
				$taken_over = true;
				$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
				$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) );
			}
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) );

		self::assertTrue( $taken_over );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'the plugin was not updated', $outcome['error'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded, 'Aucune mise à jour sans transaction.' );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertSame( [], $this->deactivated );
		self::assertNull( UpdateTransaction::current() );
		self::assertNull( UpdateTransaction::held() );
	}

	/** Filet de shutdown : jamais la transaction d'un autre processus. */
	public function test_the_shutdown_net_leaves_alone_a_transaction_this_process_did_not_open(): void {
		$other = [
			'id'               => 'autre',
			'plugin_file'      => 'autre/autre.php',
			'was_active'       => true,
			'step'             => UpdateTransaction::STEP_UPGRADING,
			'restore_point_id' => 'p-autre',
			'started_at'       => time() - 30,
			'updated_at'       => time() - 10,
		];
		$this->options[ UpdateTransaction::OPTION_KEY ] = $other;

		ProtectedUpdate::recover_on_shutdown();

		self::assertSame( $other, $this->options[ UpdateTransaction::OPTION_KEY ] );
		self::assertSame( [], PendingOutcomes::all() );
		self::assertSame( [], $this->deactivated );
	}

	/** Ouverte par ce processus, mais reprise par le cron entre-temps : le filet ne la reprend pas une seconde fois. */
	public function test_the_shutdown_net_leaves_alone_its_transaction_once_taken_over_by_a_recovery(): void {
		UpdateTransaction::open( [ 'plugin_file' => self::FILE, 'was_active' => true ], time() - 1000 );
		$reserved = array_merge(
			$this->options[ UpdateTransaction::OPTION_KEY ],
			[
				'step'            => UpdateTransaction::STEP_RECOVERING,
				'recovering_from' => UpdateTransaction::STEP_SNAPSHOT,
				'recovery_token'  => 'jeton-du-cron',
				'updated_at'      => time() - 5,
			]
		);
		$this->options[ UpdateTransaction::OPTION_KEY ] = $reserved;

		ProtectedUpdate::recover_on_shutdown();

		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] );
		self::assertSame( [], PendingOutcomes::all() );
	}

	/** Et toujours la sienne : requête morte en route (erreur fatale pendant la mise à jour). */
	public function test_the_shutdown_net_recovers_the_transaction_this_process_opened(): void {
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		UpdateTransaction::open( [ 'plugin_file' => self::FILE, 'was_active' => true ], time() );
		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => $point['id'] ] );
		( Plugin_Upgrader::$on_upgrade )();

		ProtectedUpdate::recover_on_shutdown();

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	// ── Contrôle de reprise perdu ──────────────────────────────────────────

	/**
	 * Le contrôle programmé à l'ouverture a été perdu (liste des tâches réécrite en
	 * même temps par un autre processus) : il est reprogrammé juste avant de toucher
	 * aux fichiers, au lieu d'attendre la purge biquotidienne (jusqu'à 12 h).
	 */
	public function test_a_lost_recovery_check_is_scheduled_again_before_the_files_change(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$lost            = false;
		$this->on_filter = function ( string $hook ) use ( &$lost ): void {
			if ( ! $lost && 'g2rd_connector_snapshots_dir' === $hook && 2 === $this->response_index ) {
				$lost                = true;
				$this->single_events = [];
			}
		};
		$before = time();

		CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertTrue( $lost );
		self::assertCount( 1, $this->single_events, 'Reprogrammé, une fois.' );
		[ $timestamp, $hook ] = $this->single_events[0];
		self::assertSame( RestorePointPurgeJob::RECOVERY_HOOK, $hook );
		self::assertGreaterThanOrEqual( $before + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
		self::assertLessThanOrEqual( time() + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
	}

	// ── Reprise menée par le cron : filet et contrôle pendant la restauration ──

	/**
	 * Le contrôle du cron reprend une mise à jour morte. wp-cron.php a retiré
	 * l'événement avant de l'exécuter : si ce processus mourait pendant la
	 * restauration (erreur fatale, délai dépassé pendant l'extraction, processus
	 * tué), rien ne reprendrait sa réservation avant la purge biquotidienne. Pendant
	 * toute la restauration, un contrôle attend donc, et le filet de shutdown est armé.
	 */
	public function test_a_cron_recovery_keeps_a_check_pending_and_the_shutdown_net_armed_while_restoring(): void {
		$nets  = $this->record_shutdown_nets();
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->single_events = []; // Événement en cours : déjà retiré par wp-cron.php.
		$seen                = null;
		$this->on_filter     = function ( string $hook ) use ( &$seen, $nets ): void {
			if ( null === $seen && 'g2rd_connector_snapshots_dir' === $hook && $this->recovery_running() ) {
				$seen = [
					'check' => \wp_next_scheduled( RestorePointPurgeJob::RECOVERY_HOOK ),
					'net'   => in_array( [ ProtectedUpdate::class, 'recover_on_shutdown' ], $nets->getArrayCopy(), true ),
				];
			}
		};
		$before = time();

		( new RestorePointPurgeJob() )->run_recovery_check();

		self::assertNotNull( $seen, 'La restauration a bien eu lieu.' );
		self::assertNotFalse( $seen['check'], 'Un contrôle attend pendant la restauration.' );
		self::assertGreaterThanOrEqual( $before + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $seen['check'], 'Programmé après le délai au-delà duquel la réservation pourra être déclarée morte.' );
		self::assertTrue( $seen['net'], 'Filet de shutdown armé avant la restauration.' );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * La reprise du cron meurt pendant la restauration (erreur fatale) : le filet de
	 * shutdown reprend la réservation que tient ce processus, et restaure.
	 */
	public function test_the_shutdown_net_resumes_a_cron_recovery_that_died_while_restoring(): void {
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		// Ce que fait la reprise du cron juste avant de restaurer ; le processus meurt ensuite.
		self::assertNotNull( UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );

		ProtectedUpdate::recover_on_shutdown();

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertTrue( $this->active );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	/** La mise à jour reprend d'abord une transaction morte : un seul filet de shutdown pour les deux. */
	public function test_the_update_and_its_own_recovery_arm_a_single_shutdown_net(): void {
		$nets  = $this->record_shutdown_nets();
		$file  = $this->make_plugin( 'autre', '1.0', [ 'assets/a.css' => 'a' ] );
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( $file, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$this->half_upgrade( 'autre' );
		$this->dead_transaction( $file, $point['id'] );
		$this->script_health( ok: 2, then_ok: 2 );

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertSame( [ [ $file, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		$recovery_nets = array_filter( $nets->getArrayCopy(), static fn ( $cb ): bool => [ ProtectedUpdate::class, 'recover_on_shutdown' ] === $cb );
		self::assertCount( 1, $recovery_nets );
	}

	// ── Prise par une reprise pendant ou après la mise à jour ───────────────────

	/**
	 * Mise à jour plus longue que 10 min : un contrôle du cron la prend pour morte et
	 * restaure l'ancienne version (étape d'origine `upgrading`). La requête, encore
	 * vivante, ne touche plus à rien : ni mesure, ni restauration, ni suppression du
	 * point dont la reprise a besoin. Une seule restauration, un seul résultat.
	 */
	public function test_an_update_taken_over_while_upgrading_leaves_everything_to_the_recovery(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$tree_before                 = $this->tree( $this->plugins . '/akismet' );
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade ): void {
			$upgrade();
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 ); // L'étape dure plus de 10 min.
			$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'], 'Jamais « updated » ni « not_updated » : la reprise rend son propre résultat.' );
		self::assertArrayNotHasKey( 'result', $outcome );
		self::assertStringContainsString( 'taken over by a recovery', $outcome['error'] );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une seule restauration : celle de la reprise.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertTrue( $this->active );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertSame( 2, $this->response_index, 'Aucune mesure de santé après la prise.' );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points, 'Le point de la reprise reste.' );
		self::assertTrue( $points[0]['hold'] );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Même prise, mais la reprise restaure encore (autre processus) quand la requête
	 * relit la version : celle-ci voit la nouvelle version. Elle ne mesure pas la
	 * santé, ne restaure pas en même temps que la reprise et ne rend pas « updated ».
	 */
	public function test_an_update_taken_over_by_a_running_recovery_neither_measures_nor_restores(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$reserved                    = null;
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade, &$reserved ): void {
			$upgrade();
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			$this->as_another_process(
				static function () use ( &$reserved ): void {
					$reserved = UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() );
				}
			);
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertIsArray( $reserved, 'La reprise a bien réservé la transaction.' );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'taken over by a recovery', $outcome['error'] );
		self::assertSame( 2, $this->response_index, 'Aucune mesure de santé.' );
		self::assertSame( [], $this->deactivated, 'Aucune restauration par la requête : la reprise s\'en charge.' );
		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null, 'La réservation de la reprise reste en place.' );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points );
		self::assertTrue( $points[0]['hold'], 'Point retenu pour la reprise.' );
	}

	/**
	 * Mesure de santé plus longue que 10 min : un contrôle prend la transaction
	 * (étape d'origine `health` : il ne touchera pas aux fichiers). La requête a vu le
	 * site cassé, mais ne restaure pas sans sa transaction : elle s'arrête avec une
	 * erreur explicite et garde le point pour un rollback depuis la plateforme.
	 */
	public function test_an_update_taken_over_during_the_health_check_does_not_roll_back_without_its_transaction(): void {
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => '<b>Fatal error</b>: Uncaught Error' ],
		];
		$reserved       = null;
		$this->on_probe = function ( int $probe ) use ( &$reserved ): void {
			if ( 3 === $probe ) { // Dernière sonde d'après la mise à jour : la mesure a duré plus de 10 min.
				$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
				$this->as_another_process(
					static function () use ( &$reserved ): void {
						$reserved = UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() );
					}
				);
			}
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertIsArray( $reserved );
		self::assertSame( UpdateTransaction::STEP_HEALTH, $reserved['recovering_from'] );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'not rolled back automatically', $outcome['error'] );
		self::assertSame( [], $this->deactivated, 'Pas de restauration sans transaction.' );
		self::assertSame( 4, $this->response_index, 'Pas de mesure d\'après rollback.' );
		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points );
		self::assertTrue( $points[0]['hold'], 'Point gardé pour un rollback depuis la plateforme.' );
	}

	/**
	 * Le téléchargement (ou le rafraîchissement des transients qui le précède) dure
	 * plus de 10 min : un contrôle du cron prend la transaction AVANT que les fichiers
	 * changent, restaure l'ancienne version (déjà en place), consigne
	 * recovered_rolled_back et ferme. Juste avant de remplacer les fichiers, la mise à
	 * jour voit que sa transaction n'est plus la sienne : Plugin_Upgrader s'arrête sans
	 * rien toucher. Sans ce contrôle, la nouvelle version restait installée sans
	 * contrôle de santé, et la reprise annonçait à tort l'ancienne.
	 *
	 * WordPress ne garde le résultat d'install_package() qu'après une installation
	 * réussie : upgrade() peut rendre la WP_Error du filtre, ou autre chose (tableau
	 * vide). Les deux cas s'arrêtent de la même façon.
	 *
	 * @param mixed $result_on_error Retour de Plugin_Upgrader::upgrade() quand le filtre rend une WP_Error (null : la WP_Error).
	 */
	#[DataProvider( 'pre_install_error_results' )]
	public function test_an_update_taken_over_before_the_files_change_leaves_them_untouched( $result_on_error ): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$tree_before                                  = $this->tree( $this->plugins . '/akismet' );
		Plugin_Upgrader::$result_on_pre_install_error = $result_on_error;
		Plugin_Upgrader::$on_download                 = function (): void {
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 ); // Le téléchargement dure plus de 10 min.
			$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'protected update stopped before replacing the plugin files: its transaction was taken over by a recovery (a step took more than 10 minutes); the plugin was not updated, retry in a few minutes', $outcome['error'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'], 'Version 2.0 jamais copiée.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une seule restauration : celle de la reprise.' );
		self::assertTrue( $this->active );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes(), 'Un seul résultat, juste : l\'ancienne version est en place.' );
		self::assertSame( 2, $this->response_index, 'Aucune mesure de santé.' );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points );
		self::assertTrue( $points[0]['hold'] );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [], array_filter( $this->filters['upgrader_pre_install'] ?? [] ), 'Filtre retiré après la mise à jour.' );
	}

	/** @return array<string, array{0: mixed}> */
	public static function pre_install_error_results(): array {
		return [
			'upgrade() rend la WP_Error' => [ null ],
			'upgrade() rend un tableau vide' => [ [] ],
		];
	}

	/**
	 * Même prise avant la copie, mais la reprise restaure encore (autre processus) :
	 * la mise à jour ne déplace pas le même dossier qu'elle en même temps. Ni copie, ni
	 * désactivation par la requête ; la réservation de la reprise reste en place.
	 */
	public function test_an_update_taken_over_by_a_running_recovery_before_the_files_change_does_not_touch_them(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$tree_before                  = $this->tree( $this->plugins . '/akismet' );
		$reserved                     = null;
		Plugin_Upgrader::$on_download = function () use ( &$reserved ): void {
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			$this->as_another_process(
				static function () use ( &$reserved ): void {
					$reserved = UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() );
				}
			);
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertIsArray( $reserved, 'La reprise a bien réservé la transaction.' );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'stopped before replacing the plugin files', $outcome['error'] );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'Fichiers intacts.' );
		self::assertSame( [], $this->deactivated );
		self::assertTrue( $this->active );
		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null );
		self::assertSame( [], PendingOutcomes::all(), 'Le résultat est celui de la reprise, à venir.' );
		self::assertSame( 2, $this->response_index );
	}

	/** Téléchargement lent sans reprise : le contrôle d'avant la copie rafraîchit la transaction, la mise à jour continue. */
	public function test_the_check_before_the_files_change_refreshes_a_live_update(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$refreshed                    = null;
		Plugin_Upgrader::$on_download = function (): void {
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS - 10 );
		};
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade, &$refreshed ): void {
			$refreshed = UpdateTransaction::current();
			$upgrade();
		};

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertIsArray( $refreshed );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $refreshed['step'] );
		self::assertGreaterThanOrEqual( time() - 5, $refreshed['updated_at'], 'Rafraîchie juste avant la copie.' );
		self::assertNotEmpty( $refreshed['restore_point_id'], 'Le point reste attaché à la transaction.' );
		self::assertSame( [], array_filter( $this->filters['upgrader_pre_install'] ?? [] ) );
	}

	/**
	 * La copie elle-même dépasse 10 min : la reprise prend la transaction, puis le cœur
	 * échoue. L'erreur du cœur remonte, avec la prise : la plateforme reçoit aussi le
	 * résultat de la reprise et doit pouvoir relier les deux.
	 */
	public function test_an_upgrade_error_after_a_takeover_says_so(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		Plugin_Upgrader::$next_result = new WP_Error( 'copy_failed', 'Could not copy file.' );
		$upgrade                      = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade  = function () use ( $upgrade ): void {
			$upgrade();
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Could not copy file. (its transaction was taken over by a recovery, which reports its own result)', $outcome['error'] );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Une seule restauration : celle de la reprise.' );
	}

	// ── Reprise qui meurt à chaque tentative ─────────────────────────────────

	/**
	 * Chaque reprise meurt pendant la restauration (erreur fatale : code tiers lancé par
	 * la désactivation, archive qui fait planter l'extraction) : son `finally` ne
	 * s'exécute pas, aucun résultat n'est consigné, l'extension reste désactivée, et le
	 * contrôle reprogrammé la relance environ toutes les 11 min. Après
	 * MAX_RECOVERY_ATTEMPTS reprises commencées, la suivante ne restaure plus : elle
	 * consigne recovery_failed, ferme la transaction et réactive l'extension telle quelle.
	 */
	public function test_a_recovery_that_keeps_dying_gives_up_reports_and_reactivates(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )(); // Mise à jour morte à moitié : en-tête 2.0, extension désactivée.
		$this->dead_transaction( self::FILE, $point['id'] );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		// Chaque reprise réserve la transaction, puis son processus meurt : rien d'autre n'est écrit.
		for ( $i = 0; $i < UpdateTransaction::MAX_RECOVERY_ATTEMPTS; $i++ ) {
			$this->as_another_process(
				static function (): void {
					self::assertNotNull( UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
				}
			);
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
		}

		ProtectedUpdate::recover( time() ); // Le contrôle suivant.

		self::assertSame( [], $this->deactivated, 'Plus de restauration : elle rejouerait la même erreur fatale.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'Fichiers laissés tels quels.' );
		self::assertSame( [ self::FILE ], $this->activated, 'Extension réactivée telle quelle.' );
		self::assertTrue( $this->active );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $pending[0]['step'] );
		self::assertStringContainsString( 'gave up after ' . UpdateTransaction::MAX_RECOVERY_ATTEMPTS . ' recovery attempts', $pending[0]['detail'] );
		self::assertStringContainsString( 'roll it back from the platform', $pending[0]['detail'] );
		self::assertTrue( $this->store->get( $point['id'] )['hold'], 'Point gardé pour un rollback manuel.' );
		self::assertNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time() + 3600 ); // Le contrôle reprogrammé : plus rien à faire.
		self::assertCount( 1, PendingOutcomes::all() );
	}

	/** Jusqu'à MAX_RECOVERY_ATTEMPTS reprises commencées, la restauration est tentée (délai dépassé passager, par exemple). */
	public function test_a_recovery_still_restores_up_to_the_last_allowed_attempt(): void {
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		for ( $i = 1; $i < UpdateTransaction::MAX_RECOVERY_ATTEMPTS; $i++ ) {
			$this->as_another_process( static fn () => UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
		}

		ProtectedUpdate::recover( time() );

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertSame( [ self::FILE ], $this->deactivated );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertNull( UpdateTransaction::current() );
	}

	// ── Trace de reprise : seulement sur sa propre réservation ───────────────

	/**
	 * Restauration de la reprise R1 plus longue que 10 min : une reprise R2 réserve la
	 * même transaction (même identité, autre jeton). R1 termine : elle ne marque pas
	 * la réservation de R2 comme déjà reprise. Sinon une mise à jour pourrait s'ouvrir
	 * par-dessus la restauration de R2, ou un autre passage la retirer.
	 */
	public function test_a_recovery_that_outlived_its_reservation_does_not_trace_the_next_one(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$r2              = null;
		$this->on_filter = function ( string $hook ) use ( &$r2 ): void {
			if ( null === $r2 && 'g2rd_connector_snapshots_dir' === $hook && $this->recovery_running() ) {
				// R2, dans un autre processus, en base seulement.
				$r2 = array_merge(
					(array) UpdateTransaction::current(),
					[
						'recovery_token' => 'jeton-r2',
						'updated_at'     => time(),
					]
				);
				$this->options[ UpdateTransaction::OPTION_KEY ] = $r2;
			}
		};

		ProtectedUpdate::recover( time() ); // R1.

		self::assertIsArray( $r2 );
		self::assertSame( $r2, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null, 'La réservation de R2 reste en place.' );
		self::assertArrayNotHasKey( UpdateTransaction::RECOVERY_TRACE_KEY, $this->options, 'Pas de trace sur la réservation de R2.' );
		self::assertTrue( UpdateTransaction::is_live( $r2, time() ), 'R2 protège toujours sa restauration.' );
		self::assertFalse( UpdateTransaction::open( [ 'plugin_file' => self::FILE ], time() ), 'Aucune mise à jour par-dessus R2.' );
	}

	// ── Outils ────────────────────────────────────────────────────────────────

	/**
	 * Rappels passés à register_shutdown_function() pendant le test.
	 *
	 * @return \ArrayObject<int, mixed>
	 */
	private function record_shutdown_nets(): \ArrayObject {
		$nets = new \ArrayObject();
		Functions\when( 'register_shutdown_function' )->alias(
			static function ( $callback ) use ( $nets ): void {
				$nets->append( $callback );
			}
		);
		return $nets;
	}

	/**
	 * Mise à jour morte à moitié : en-tête 2.0, fichier ajouté, fichier retiré,
	 * extension désactivée par le cœur.
	 */
	private function half_upgrade( string $slug ): void {
		$dir = $this->plugins . '/' . $slug;
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: $slug\n * Version: 2.0\n */\n" );
		file_put_contents( $dir . '/inc/new-module.php', "<?php\n" );
		unlink( $dir . '/assets/a.css' );
		$this->active = false;
	}

	/**
	 * Transaction d'une mise à jour morte pendant l'étape « mise à jour », écrite par
	 * un autre processus (celui de la requête tuée), plus de 10 min plus tôt.
	 */
	private function dead_transaction( string $plugin_file, string $point_id ): void {
		$this->options[ UpdateTransaction::OPTION_KEY ] = [
			'plugin_file'      => $plugin_file,
			'version_before'   => '1.0',
			'was_active'       => true,
			'network_active'   => false,
			'id'               => 'transaction-morte',
			'step'             => UpdateTransaction::STEP_UPGRADING,
			'restore_point_id' => $point_id,
			'started_at'       => time() - 1000,
			'updated_at'       => time() - 900,
		];
	}

	private function recovery_running(): bool {
		$txn = UpdateTransaction::current();
		return null !== $txn && UpdateTransaction::STEP_RECOVERING === $txn['step'];
	}

	/**
	 * Exécute `$fn` comme un autre processus PHP : sans la transaction que tient
	 * celui du test (statique, propre à chaque processus).
	 */
	private function as_another_process( callable $fn ): void {
		$held = new \ReflectionProperty( UpdateTransaction::class, 'held' );
		$mine = $held->getValue();
		$held->setValue( null, null );
		try {
			$fn();
		} finally {
			$held->setValue( null, $mine );
		}
	}

	/** Liste des extensions en cache, comme get_plugins() : relue après wp_clean_plugins_cache() seulement. */
	private function cache_plugin_list(): void {
		Functions\when( 'get_plugins' )->alias(
			function (): array {
				$this->plugin_list ??= $this->plugins_on_disk();
				return $this->plugin_list;
			}
		);
		Functions\when( 'wp_clean_plugins_cache' )->alias(
			function (): void {
				$this->plugin_list = null;
			}
		);
	}

	/**
	 * @return list<array{0:string, 1:string}> Résultats consignés pour la plateforme (extension, résultat).
	 */
	private function pending_outcomes(): array {
		return array_map( static fn ( array $o ): array => [ (string) $o['plugin_file'], (string) $o['outcome'] ], PendingOutcomes::all() );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function payload( array $extra ): array {
		return array_merge(
			[
				'file'     => self::FILE,
				'snapshot' => true,
				'kind'     => 'wporg',
			],
			$extra
		);
	}

	/**
	 * Fait comme si `$seconds` s'étaient écoulées depuis la dernière étape de la
	 * transaction en cours (l'horloge des tests ne bouge pas).
	 */
	private function age_transaction( int $seconds ): void {
		self::assertNotNull( UpdateTransaction::current(), 'Une transaction doit être ouverte.' );
		$this->options[ UpdateTransaction::OPTION_KEY ]['updated_at'] -= $seconds;
	}

	private function script_health( int $ok, int $then_ok ): void {
		$this->responses = [];
		for ( $i = 0; $i < $ok + $then_ok; $i++ ) {
			$this->responses[] = 0 === $i % 2 ? [ 'code' => 200, 'body' => 'ok' ] : [ 'code' => 200, 'body' => self::ajax_ok() ];
		}
	}

	private static function ajax_ok(): callable {
		return static fn ( string $token ): string => json_encode( [ 'ok' => true, 'token' => $token ] );
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function plugins_on_disk(): array {
		$out = [];
		foreach ( (array) glob( $this->plugins . '/*/*.php' ) as $main ) {
			$version = PluginRestorer::version_from_header( (string) file_get_contents( (string) $main ) );
			if ( null !== $version ) {
				$out[ basename( dirname( (string) $main ) ) . '/' . basename( (string) $main ) ] = [ 'Version' => $version ];
			}
		}
		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private function tree( string $dir ): array {
		$out   = [];
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$rel         = str_replace( '\\', '/', substr( (string) $item, strlen( $dir ) + 1 ) );
			$out[ $rel ] = $item->isDir() ? 'dir' : hash_file( 'sha256', (string) $item );
		}
		ksort( $out );
		return $out;
	}
}
