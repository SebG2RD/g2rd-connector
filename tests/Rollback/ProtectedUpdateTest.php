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
use G2RD\Connector\Rollback\RestoreException;
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

	/** Ce que dit l'erreur d'une restauration manquée quand l'extension est restée inactive. */
	private const LEFT_INACTIVE = '; the plugin was left inactive:';

	/** …quand elle n'a pas pu être essayée : son fichier principal était déjà chargé par le processus. */
	private const NOT_CHECKED = 'which could not be checked because its code was already loaded earlier in this request';

	/** Début du texte d'`upgrade_error` : exception levée une fois la nouvelle version copiée. */
	private const INSTALLED_THEN_FAILED = '; WordPress had already installed the new version when this error occurred (its files are complete), so the protected update did not stop on it and went on as after a successful copy (see outcome)';

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
	/** Le fichier principal de l'extension lève une Error au chargement (cf. restore_fails()). */
	private bool $activation_throws = false;
	/**
	 * Fichiers inclus par « ce processus » (get_included_files()) : le fichier principal
	 * d'une extension active au démarrage de la requête y figure (cf. loaded_at_boot()).
	 *
	 * @var list<string>
	 */
	private array $loaded = [];

	protected function setUp(): void {
		parent::setUp();
		Plugin_Upgrader::reset();
		$this->store             = new RestorePointStore();
		$this->responses         = [];
		$this->response_index    = 0;
		$this->active            = true;
		$this->deactivated       = [];
		$this->activated         = [];
		$this->single_events     = [];
		$this->on_probe          = null;
		$this->plugin_list       = null;
		$this->activation_throws = false;
		$this->loaded            = [];

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
				if ( $this->activation_throws ) {
					// Comme activate_plugin() : tampon ouvert, puis inclusion du fichier
					// principal, qui lève avant l'écriture de `active_plugins`.
					ob_start();
					throw new \Error( 'Class "Akismet\\Module" not found' );
				}
				$this->activated[] = $file;
				$this->active      = true;
				return null;
			}
		);
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_update_themes' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		// Seuls `upgrader_pre_install` et `upgrader_post_install` agissent vraiment
		// (Plugin_Upgrader simulé les applique avant et après le remplacement des
		// fichiers) ; les autres filtres restent sans effet.
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, $callback, int $priority = 10 ): bool {
				if ( in_array( $hook, [ 'upgrader_pre_install', 'upgrader_post_install' ], true ) ) {
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

	/**
	 * Multisite, extension active pour tout le réseau : le rollback automatique la
	 * réactive pour tout le réseau, comme la réactivation d'après la mise à jour. run()
	 * lisait `network_active` dans le résultat de perform_plugin_upgrade(), qui ne le
	 * rend pas : elle n'était réactivée que pour le site courant.
	 */
	public function test_an_automatic_rollback_reactivates_a_network_active_plugin_network_wide(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_plugin_active_for_network' )->alias( fn (): bool => $this->active );
		$network_wide = [];
		Functions\when( 'activate_plugin' )->alias(
			function ( string $file, string $redirect = '', bool $network = false ) use ( &$network_wide ): ?WP_Error {
				$network_wide[]    = $network;
				$this->activated[] = $file;
				$this->active      = true;
				return null;
			}
		);
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => '<b>Fatal error</b>: Uncaught Error' ],
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
		];

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];

		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLED_BACK, $r['outcome'] );
		self::assertSame( [ true, true ], $network_wide, 'Après la mise à jour, puis après le rollback : pour tout le réseau.' );
		self::assertTrue( $this->active );
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
	 *
	 * La reprise a désactivé l'extension et extrait l'archive : la requête ne la
	 * réactive pas, ni à la réactivation nominale (upgrade() rend un tableau vide) ni
	 * par son filet de shutdown (upgrade() rend la WP_Error). activate_plugin()
	 * inclurait un fichier à moitié extrait, puis l'option serait forcée, en même temps
	 * que la reprise l'écrit. C'est la reprise qui réactive, à la fin de restore().
	 *
	 * @param mixed $result_on_error Retour de Plugin_Upgrader::upgrade() quand le filtre rend une WP_Error (null : la WP_Error).
	 */
	#[DataProvider( 'pre_install_error_results' )]
	public function test_an_update_taken_over_by_a_running_recovery_before_the_files_change_does_not_touch_them( $result_on_error ): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$tree_before                                  = $this->tree( $this->plugins . '/akismet' );
		$reserved                                     = null;
		Plugin_Upgrader::$result_on_pre_install_error = $result_on_error;
		Plugin_Upgrader::$on_download                 = function () use ( &$reserved ): void {
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			$this->as_another_process(
				static function () use ( &$reserved ): void {
					$reserved = UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() );
				}
			);
			// La reprise (autre processus) désactive l'extension avant d'extraire l'archive.
			$this->active = false;
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		// Fin de la requête pendant que la reprise restaure encore.
		foreach ( $nets as $net ) {
			$net();
		}

		self::assertIsArray( $reserved, 'La reprise a bien réservé la transaction.' );
		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'stopped before replacing the plugin files', $outcome['error'] );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'Fichiers intacts.' );
		self::assertSame( [], $this->deactivated, 'La requête ne désactive rien.' );
		self::assertSame( [], $this->activated, 'La requête ne réactive pas pendant que la reprise restaure.' );
		self::assertFalse( $this->active );
		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null );
		self::assertSame( [], PendingOutcomes::all(), 'Le résultat est celui de la reprise, à venir.' );
		self::assertSame( 2, $this->response_index );

		// La reprise a fermé sans réactiver (restauration manquée, extension qui ne se
		// charge pas, par exemple). Décision du 2026-10-10 : la requête d'origine ne
		// réactive jamais l'extension après une reprise faite par un autre processus,
		// pas même par son filet (avant : il la rétablissait).
		unset( $this->options[ UpdateTransaction::OPTION_KEY ] );
		foreach ( $nets as $net ) {
			$net();
		}
		self::assertSame( [], $this->activated, 'La reprise a décidé : la requête n\'y revient pas.' );
		self::assertFalse( $this->active );
	}

	/**
	 * Croisement entre processus (décision du 2026-10-10) : le remplacement des fichiers
	 * dure plus de 10 minutes, la reprise du cron prend la transaction, restaure, échoue
	 * et laisse l'extension inactive (elle ne se charge pas). La requête d'origine, encore
	 * vivante, ne la réactive jamais : ni à la réactivation nominale (la reprise a déjà
	 * fermé la transaction), ni par son filet de shutdown. Avant, elle la forçait active :
	 * « erreur critique » sur toutes les pages.
	 */
	public function test_the_update_never_reactivates_a_plugin_that_a_recovery_from_another_process_left_inactive(): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$this->loaded_at_boot();
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade ): void {
			$upgrade();
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 ); // La copie dure plus de 10 min.
			$this->restore_fails( then_activation_throws: true );
			$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) ); // Le contrôle du cron.
		};
		$level = ob_get_level();

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		foreach ( $nets as $net ) { // Fin de la requête d'origine.
			$net();
		}
		$leaked = 0;
		while ( ob_get_level() > $level ) { // Tampon laissé par un activate_plugin() qui lève (code d'avant).
			ob_end_clean();
			++$leaked;
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'taken over by a recovery', $outcome['error'] );
		self::assertSame( [ [ self::FILE, 'recovery_failed' ] ], $this->pending_outcomes() );
		self::assertStringContainsString( self::LEFT_INACTIVE, PendingOutcomes::all()[0]['detail'] );
		self::assertSame( [], $this->activated );
		self::assertFalse( $this->active, 'Laissée inactive par la reprise, elle le reste.' );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ), 'active_plugins jamais écrite de force par la requête d\'origine.' );
		self::assertSame( 0, $leaked, 'Aucun essai d\'activation par la requête d\'origine.' );
	}

	/**
	 * Du code branché sur la mise à jour lève en pleine copie (extension déjà désactivée
	 * par le cœur) : upgrade() n'a pas rendu la main, les fichiers sont peut-être à
	 * moitié remplacés. Ni réactivation nominale, ni filet (décision du 2026-10-10) :
	 * l'extension reste désactivée, l'erreur le dit, le point est retenu.
	 */
	public function test_an_exception_while_upgrading_leaves_the_plugin_inactive_and_keeps_the_point(): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$this->loaded_at_boot();
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = static function () use ( $upgrade ): void {
			$upgrade();
			throw new \RuntimeException( 'Copy interrupted by a third-party hook.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		foreach ( $nets as $net ) { // Fin de la requête.
			$net();
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringStartsWith( 'Copy interrupted by a third-party hook.; the plugin was left inactive:', $outcome['error'] );
		self::assertSame( [], $this->activated );
		self::assertFalse( $this->active );
		$points = array_values( $this->store->all() );
		self::assertCount( 1, $points );
		self::assertTrue( $points[0]['hold'], 'Point retenu pour un rollback depuis la plateforme.' );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [], PendingOutcomes::all() );
	}

	/**
	 * Une exception levée par `upgrader_process_complete`, une fois la nouvelle version
	 * copiée : fichiers complets, transaction encore la sienne. La mise à jour protégée ne
	 * s'arrête pas là : réactivation nominale, contrôle de santé, et le résultat normal
	 * porte l'erreur (`upgrade_error`). Elle s'arrêtait sans contrôle de santé, nouvelle
	 * version réactivée sans vérification.
	 */
	public function test_an_exception_once_the_files_are_installed_still_runs_the_health_check_and_reports_it(): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$this->loaded_at_boot();
		Plugin_Upgrader::$on_complete = static function (): void {
			throw new \RuntimeException( 'Language pack download timed out.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		foreach ( $nets as $net ) { // Fin de la requête.
			$net();
		}

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertSame( 'healthy', $r['health']['verdict'] );
		self::assertSame( 4, $this->response_index, 'Référence, puis mesure d\'après la mise à jour.' );
		self::assertTrue( $r['updated'] );
		self::assertSame( '2.0', $r['version_after'] );
		self::assertTrue( $r['reactivated'] );
		self::assertStringStartsWith( 'Language pack download timed out.' . self::INSTALLED_THEN_FAILED, $r['upgrade_error'] );
		self::assertStringContainsString( 'check the PHP error log', $r['upgrade_error'] );
		self::assertSame( [ self::FILE ], $this->activated );
		self::assertTrue( $this->active );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [], PendingOutcomes::all() );
	}

	/**
	 * Même exception : elle saute `$this->skin->footer()`, et le tampon ouvert par
	 * Automatic_Upgrader_Skin::header() reste ouvert pendant la suite (contrôle de santé).
	 * En fin de commande, CommandExecutor::run() referme ce niveau ET le sien, et remonte
	 * leur contenu (`stray_output`) : aucun tampon de la commande ne reste ouvert.
	 */
	public function test_an_exception_once_the_files_are_installed_leaves_no_buffer_of_the_command_open(): void {
		$this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$this->loaded_at_boot();
		Plugin_Upgrader::$on_download = static function (): void {
			ob_start(); // Comme Automatic_Upgrader_Skin::header().
			echo 'Notice: written inside the skin buffer.';
		};
		Plugin_Upgrader::$on_complete = static function (): void {
			throw new \RuntimeException( 'Language pack download timed out.' ); // footer() n'est jamais appelé.
		};

		$level = ob_get_level();
		try {
			$outcome     = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
			$level_after = ob_get_level();
		} finally {
			// Ne laisser aucun tampon ouvert à PHPUnit, même quand le test échoue.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		self::assertSame( $level, $level_after, 'Tampon de la skin et tampon de la commande refermés.' );
		self::assertSame( 'done', $outcome['status'] );
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $outcome['result']['outcome'] );
		self::assertSame( 'Notice: written inside the skin buffer.', $outcome['stray_output'] ?? null );
	}

	/**
	 * Même exception, nouvelle version qui casse le site : le contrôle de santé le voit,
	 * le rollback automatique remet l'ancienne version, et le résultat porte l'erreur.
	 * Avant, la nouvelle version restait active sans contrôle : « erreur critique » sur
	 * toutes les pages, sans rollback.
	 */
	public function test_an_exception_once_the_files_are_installed_still_rolls_a_broken_update_back(): void {
		$nets        = $this->record_shutdown_nets();
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => '<b>Fatal error</b>: Uncaught Error' ],
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
		];
		$this->loaded_at_boot();
		Plugin_Upgrader::$on_complete = static function (): void {
			throw new \RuntimeException( 'Language pack download timed out.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		foreach ( $nets as $net ) { // Fin de la requête.
			$net();
		}

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLED_BACK, $r['outcome'] );
		self::assertSame( 'broken', $r['health']['verdict'] );
		self::assertSame( [ 'from' => '2.0', 'to' => '1.0' ], $r['rolled_back'] );
		self::assertSame( '1.0', $r['version_after'] );
		self::assertStringStartsWith( 'Language pack download timed out.' . self::INSTALLED_THEN_FAILED, $r['upgrade_error'] );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'Ancienne version remise à l\'identique.' );
		self::assertTrue( $this->active, 'Ancienne version réactivée.' );
		self::assertSame( [ self::FILE => '2.0' ], AutoUpdateGuard::all() );
		self::assertTrue( $r['restore_point']['hold'] );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [], PendingOutcomes::all() );
	}

	/**
	 * Même exception, mais une reprise d'un autre processus a pris la transaction pendant
	 * la copie (plus de 10 minutes) et remis l'ancienne version : la mise à jour s'arrête
	 * comme toute mise à jour prise en route, sans réactiver ni mesurer, et son erreur dit
	 * aussi celle qu'a levée WordPress (elle ne se perd pas).
	 */
	public function test_an_exception_once_the_files_are_installed_after_a_takeover_keeps_both_errors(): void {
		$this->script_health( ok: 2, then_ok: 2 );
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade ): void {
			$upgrade();
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
			$this->as_another_process( static fn () => ProtectedUpdate::recover( time() ) );
		};
		Plugin_Upgrader::$on_complete = static function (): void {
			throw new \RuntimeException( 'Language pack download timed out.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringStartsWith( 'protected update was taken over by a recovery while it was updating the plugin', $outcome['error'] );
		self::assertStringEndsWith( '; WordPress had also raised an error once the new version was installed: Language pack download timed out.; check the PHP error log for the code that raised it', $outcome['error'] );
		self::assertStringNotContainsString( 'see outcome', $outcome['error'], 'Pas de résultat ici : une erreur.' );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertSame( 2, $this->response_index, 'Aucune mesure d\'après la mise à jour.' );
		self::assertSame( [ self::FILE ], $this->activated, 'Réactivée par la reprise seulement.' );
	}

	/**
	 * Réservation non atomique (cf. UpdateTransaction::reserve()) : une reprise d'un autre
	 * processus avait lu la transaction encore ouverte, et sa réservation arrive juste
	 * APRÈS que la mise à jour l'a fermée. La transaction réapparaît, réservée par cette
	 * reprise, qui a désactivé l'extension et restaure. Le filet de la mise à jour ne la
	 * réactive pas pendant ce temps (cf. ProtectedUpdate::may_reactivate()).
	 */
	public function test_the_shutdown_net_leaves_alone_a_recovery_whose_reservation_landed_after_the_update_closed(): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$open           = null;
		$this->on_probe = static function () use ( &$open ): void {
			$open ??= UpdateTransaction::current();
		};

		$r = CommandExecutor::run( 'update_plugin', $this->payload( [] ) )['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_UPDATED, $r['outcome'] );
		self::assertNull( UpdateTransaction::current(), 'Fermée par la mise à jour.' );
		self::assertIsArray( $open );

		// La réservation de la reprise arrive après la fermeture ; elle désactive l'extension.
		$this->options[ UpdateTransaction::OPTION_KEY ] = array_merge(
			$open,
			[
				'step'              => UpdateTransaction::STEP_RECOVERING,
				'recovering_from'   => $open['step'],
				'recovery_token'    => 'jeton-de-la-reprise',
				'recovery_attempts' => 1,
				'updated_at'        => time(),
			]
		);
		$this->active = false;
		$activated    = $this->activated;
		foreach ( $nets as $net ) { // Fin de la requête de mise à jour.
			$net();
		}

		self::assertSame( $activated, $this->activated, 'La reprise en cours décide seule.' );
		self::assertFalse( $this->active );
	}

	/**
	 * Le cœur échoue (WP_Error) sans qu'aucune reprise ait pris la transaction : la
	 * requête l'a fermée elle-même, son filet rétablit l'extension désactivée par le
	 * cœur, comme avant (WordPress a remis les fichiers d'origine).
	 */
	public function test_the_shutdown_net_still_reactivates_after_an_upgrader_error_without_takeover(): void {
		$nets                         = $this->record_shutdown_nets();
		Plugin_Upgrader::$next_result = new WP_Error( 'copy_failed', 'Could not copy file.' );
		$this->script_health( ok: 2, then_ok: 0 );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		self::assertFalse( $this->active, 'Désactivée par le cœur.' );
		foreach ( $nets as $net ) {
			$net();
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Could not copy file.', $outcome['error'] );
		self::assertSame( [ self::FILE ], $this->activated );
		self::assertTrue( $this->active );
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
	 * la désactivation, archive qui fait planter l'extraction) : la fin de restore() ne
	 * s'exécute pas, aucun résultat n'est consigné, l'extension reste désactivée, et le
	 * contrôle reprogrammé la relance environ toutes les 11 min. Après
	 * MAX_RECOVERY_ATTEMPTS reprises commencées, la suivante ne restaure plus : elle
	 * consigne recovery_failed, ferme la transaction et réactive l'extension si elle se
	 * charge sans erreur (ici, oui).
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
		// `$wp_filesystem` prêt AVANT le chargement de l'extension (contexte cron ou REST).
		$activated_before_filesystem = null;
		Functions\when( 'WP_Filesystem' )->alias(
			function () use ( &$activated_before_filesystem ): bool {
				$activated_before_filesystem = $this->activated;
				return true;
			}
		);

		ProtectedUpdate::recover( time() ); // Le contrôle suivant.

		self::assertSame( [], $this->deactivated, 'Plus de restauration : elle rejouerait la même erreur fatale.' );
		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ), 'Fichiers laissés tels quels.' );
		self::assertSame( [], $activated_before_filesystem, '$wp_filesystem initialisé avant activate_plugin().' );
		self::assertSame( [ self::FILE ], $this->activated, 'Extension réactivée telle quelle.' );
		self::assertTrue( $this->active );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $pending[0]['step'] );
		self::assertStringContainsString( 'gave up after ' . UpdateTransaction::MAX_RECOVERY_ATTEMPTS . ' recovery attempts', $pending[0]['detail'] );
		// Résultat complété après l'essai : ce qui s'est réellement passé.
		self::assertSame( CommandExecutor::ACTIVATION_ACTIVE, $pending[0]['reactivation'] );
		self::assertStringContainsString( 'the plugin was not restored and was reactivated: it loads without error', $pending[0]['detail'] );
		self::assertStringNotContainsString( 'otherwise it stays inactive', $pending[0]['detail'] );
		self::assertStringContainsString( 'roll it back from the platform', $pending[0]['detail'] );
		self::assertTrue( $this->store->get( $point['id'] )['hold'], 'Point gardé pour un rollback manuel.' );
		self::assertNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time() + 3600 ); // Le contrôle reprogrammé : plus rien à faire.
		self::assertCount( 1, PendingOutcomes::all() );
	}

	/**
	 * À l'abandon, les fichiers sont les plus douteux : trois reprises sont mortes
	 * pendant la restauration, le dossier est peut-être à moitié extrait. Si le fichier
	 * principal lève une Error (classe ou fichier inclus manquant), ou si l'extension
	 * est introuvable (WP_Error), elle reste inactive : `active_plugins` n'est jamais
	 * écrite de force, sans quoi toutes les pages du site tomberaient en « erreur
	 * critique ». Le résultat, déjà consigné, ne l'est qu'une fois.
	 */
	#[DataProvider( 'activation_failures' )]
	public function test_a_recovery_that_gives_up_never_forces_a_plugin_that_does_not_load( string $failure, string $reactivation, string $cause ): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->options['active_plugins'] = [ 'hello/hello.php' ];
		$attempts                        = 0;
		$seen_at_activation              = null;
		Functions\when( 'activate_plugin' )->alias(
			static function () use ( $failure, &$attempts, &$seen_at_activation ): ?WP_Error {
				++$attempts;
				$seen_at_activation = [ count( PendingOutcomes::all() ), UpdateTransaction::current() ];
				if ( 'error' === $failure ) {
					// Comme activate_plugin() : tampon ouvert, puis inclusion du fichier principal.
					ob_start();
					echo 'sortie partielle de l\'extension';
					throw new \Error( 'Class "Akismet\\New_Module" not found' );
				}
				return new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' );
			}
		);
		for ( $i = 0; $i < UpdateTransaction::MAX_RECOVERY_ATTEMPTS; $i++ ) {
			$this->as_another_process( static fn () => UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
		}
		$level = ob_get_level();

		ProtectedUpdate::recover( time() );

		self::assertSame( 1, $attempts, 'Une tentative, par le bac à sable d\'activate_plugin().' );
		self::assertSame( [ 1, null ], $seen_at_activation, 'Résultat consigné et transaction fermée AVANT la réactivation.' );
		self::assertSame( [ 'hello/hello.php' ], $this->options['active_plugins'], 'active_plugins jamais écrite de force.' );
		self::assertFalse( $this->active, 'L\'extension reste inactive : le site reste debout.' );
		self::assertSame( $level, ob_get_level(), 'Tampon ouvert par activate_plugin() refermé, sortie écartée.' );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		// Résultat complété après l'essai : la cause exacte, et ce qu'il faut faire.
		self::assertSame( $reactivation, $pending[0]['reactivation'] );
		self::assertStringContainsString( 'the plugin was not restored and was left inactive: ' . $cause, $pending[0]['detail'] );
		self::assertStringNotContainsString( 'otherwise it stays inactive', $pending[0]['detail'] );
		self::assertStringContainsString( 'check its files and the PHP error log, then roll it back from the platform: its restore point is kept', $pending[0]['detail'] );
		self::assertNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time() + 3600 ); // Le contrôle reprogrammé : plus rien à faire.
		self::assertCount( 1, PendingOutcomes::all(), 'recovery_failed consigné une seule fois.' );
		self::assertSame( 1, $attempts );
		self::assertSame( [ 'hello/hello.php' ], $this->options['active_plugins'] );
	}

	/**
	 * Valeurs de CommandExecutor::ACTIVATION_*, écrites en clair : un fournisseur de
	 * données s'exécute avant Brain Monkey, et y charger CommandExecutor le compilerait
	 * sans Patchwork (get_included_files() et register_shutdown_function() n'y seraient
	 * plus simulables).
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function activation_failures(): array {
		return [
			'le fichier principal lève une Error' => [ 'error', 'left_inactive_load_error', 'it raised an error while loading' ],
			'activate_plugin() rend une WP_Error' => [ 'wp_error', 'left_inactive_invalid', 'WordPress refused to activate it' ],
		];
	}

	/**
	 * Abandon dans un processus qui a déjà chargé le fichier principal (filet de shutdown
	 * d'une requête où l'extension était active, par exemple) : rien ne peut y vérifier
	 * qu'elle se charge, elle reste inactive, et le résultat dit pourquoi.
	 */
	public function test_a_recovery_that_gives_up_where_the_plugin_is_already_loaded_says_it_could_not_check(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		for ( $i = 0; $i < UpdateTransaction::MAX_RECOVERY_ATTEMPTS; $i++ ) {
			$this->as_another_process( static fn () => UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
		}
		$this->loaded_at_boot();

		ProtectedUpdate::recover( time() );

		self::assertSame( [], $this->activated, 'Aucun essai : il n\'essaierait rien.' );
		self::assertFalse( $this->active );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( CommandExecutor::ACTIVATION_ALREADY_LOADED, $pending[0]['reactivation'] );
		self::assertStringContainsString( 'the plugin was not restored and was left inactive: whether it loads without error could not be checked, because its code was already loaded earlier in this request', $pending[0]['detail'] );
	}

	/** Abandon, extension inactive avant la mise à jour : jamais essayée, et le résultat le dit. */
	public function test_a_recovery_that_gives_up_leaves_a_plugin_inactive_before_the_update_as_it_was(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->options[ UpdateTransaction::OPTION_KEY ]['was_active'] = false;
		for ( $i = 0; $i < UpdateTransaction::MAX_RECOVERY_ATTEMPTS; $i++ ) {
			$this->as_another_process( static fn () => UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
			$this->age_transaction( UpdateTransaction::STALE_AFTER_SECONDS + 100 );
		}

		ProtectedUpdate::recover( time() );

		self::assertSame( [], $this->activated );
		$pending = PendingOutcomes::all();
		self::assertSame( ProtectedUpdate::REACTIVATION_AS_BEFORE, $pending[0]['reactivation'] );
		self::assertStringContainsString( 'the plugin was not restored and is left inactive, as it was before the update', $pending[0]['detail'] );
	}

	/**
	 * Reprise dont la restauration échoue, extension inactive avant la mise à jour : elle
	 * n'est pas essayée, et le résultat dit qu'elle reste inactive comme avant (rien ne
	 * le disait).
	 */
	public function test_a_recovery_whose_restore_fails_says_a_plugin_inactive_before_the_update_stays_so(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->options[ UpdateTransaction::OPTION_KEY ]['was_active'] = false;
		$this->restore_fails( then_activation_throws: false );

		ProtectedUpdate::recover( time() );

		self::assertSame( [], $this->activated );
		self::assertFalse( $this->active );
		$pending = PendingOutcomes::all();
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertSame( ProtectedUpdate::REACTIVATION_AS_BEFORE, $pending[0]['reactivation'] );
		self::assertSame( 'extraction failed: disk full; the plugin was inactive before the update and stays inactive; check its files, then roll it back from the platform: its restore point is kept', $pending[0]['detail'] );
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

	// ── Restauration qui échoue : réactivation seulement si l'extension se charge ──

	/**
	 * Rollback automatique dont la restauration échoue en pleine extraction (disque
	 * plein, par exemple) : le dossier peut être à moitié remplacé. L'extension n'est
	 * réactivée que si elle se charge sans erreur, jamais par une écriture forcée de
	 * `active_plugins`, qui ferait tomber toutes les pages en « erreur critique ». Ici
	 * son fichier principal lève une Error : elle reste inactive, y compris après les
	 * filets de shutdown de la requête (celui de la mise à jour la forçait), et le
	 * résultat remonté à la plateforme le dit.
	 */
	public function test_a_failed_automatic_rollback_never_forces_a_plugin_that_does_not_load(): void {
		$nets            = $this->record_shutdown_nets();
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => '<b>Fatal error</b>: Uncaught Error' ],
		];
		$this->restore_fails( then_activation_throws: true );
		$level = ob_get_level();

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [ 'keep_on_success' => true, 'grace_seconds' => 3600 ] ) );
		foreach ( $nets as $net ) { // Fin de la requête.
			$net();
		}

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLBACK_FAILED, $r['outcome'] );
		self::assertSame( RestoreException::FAILED, $r['error_code'] );
		self::assertStringStartsWith( 'extraction failed: disk full', $r['error'] );
		self::assertStringContainsString( self::LEFT_INACTIVE, $r['error'] );
		self::assertFalse( $this->active, 'Extension inactive : le site reste debout.' );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ), 'active_plugins jamais écrite de force, filets de shutdown compris.' );
		self::assertSame( $level, ob_get_level(), 'Tampon ouvert par activate_plugin() refermé.' );
		self::assertTrue( $r['restore_point']['hold'], 'Point gardé pour un rollback depuis la plateforme.' );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Même échec, extension qui se chargerait sans erreur. Mais la requête de la mise à
	 * jour l'a chargée à son démarrage (elle était active) : le bac à sable
	 * d'activate_plugin() ne rechargerait rien (include_once) et l'activerait sans avoir
	 * lu le dossier laissé par la restauration manquée. Décision du 2026-10-10 : un
	 * rollback automatique en échec laisse l'extension désactivée, filets de shutdown
	 * compris, et l'erreur remontée à la plateforme dit pourquoi (même code d'erreur).
	 *
	 * Remplace « la réactive si elle se charge » : dans cette requête, rien ne peut le
	 * vérifier.
	 */
	public function test_a_failed_automatic_rollback_leaves_the_plugin_inactive_in_the_request_that_loaded_it(): void {
		$nets            = $this->record_shutdown_nets();
		$this->responses = [
			[ 'code' => 200, 'body' => 'ok' ],
			[ 'code' => 200, 'body' => self::ajax_ok() ],
			[ 'code' => 500, 'body' => '' ],
			[ 'code' => 200, 'body' => '<b>Fatal error</b>: Uncaught Error' ],
		];
		$this->loaded_at_boot();
		$this->restore_fails( then_activation_throws: false );

		$outcome = CommandExecutor::run( 'update_plugin', $this->payload( [] ) );
		foreach ( $nets as $net ) { // Fin de la requête.
			$net();
		}

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( ProtectedUpdate::OUTCOME_AUTO_ROLLBACK_FAILED, $r['outcome'] );
		self::assertSame( RestoreException::FAILED, $r['error_code'], 'Même code d\'erreur.' );
		self::assertStringStartsWith( 'extraction failed: disk full' . self::LEFT_INACTIVE, $r['error'] );
		self::assertStringContainsString( self::NOT_CHECKED, $r['error'] );
		self::assertStringContainsString( 'roll it back again from the platform or reinstall it', $r['error'] );
		self::assertSame( [ self::FILE ], $this->activated, 'Réactivée après la mise à jour, jamais après la restauration manquée.' );
		self::assertFalse( $this->active, 'Extension désactivée : le site reste debout.' );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ) );
		self::assertTrue( $r['restore_point']['hold'] );
	}

	/**
	 * La requête de la mise à jour meurt en plein remplacement des fichiers (erreur
	 * fatale, délai dépassé) : son filet de shutdown reprend sa transaction et restaure.
	 * Si cette restauration échoue, l'extension, chargée au démarrage de la requête,
	 * reste désactivée : ni le bac à sable (rien ne serait rechargé), ni le filet de la
	 * mise à jour ne la rallument.
	 */
	public function test_the_shutdown_net_of_an_update_that_died_leaves_the_plugin_inactive_when_its_restore_fails(): void {
		$nets = $this->record_shutdown_nets();
		$this->script_health( ok: 2, then_ok: 2 );
		$this->loaded_at_boot();
		$at_death                    = null;
		$upgrade                     = Plugin_Upgrader::$on_upgrade;
		Plugin_Upgrader::$on_upgrade = function () use ( $upgrade, $nets, &$at_death ): void {
			$upgrade(); // Fichiers en cours de remplacement, extension désactivée par le cœur…
			$this->restore_fails( then_activation_throws: false );
			// …puis la requête meurt : PHP exécute ses filets de shutdown, dans l'ordre.
			foreach ( $nets as $net ) {
				$net();
			}
			$at_death = [
				'active'    => $this->active,
				'activated' => $this->activated,
				'pending'   => PendingOutcomes::all(),
				'options'   => (array) ( $this->options['active_plugins'] ?? [] ),
			];
		};

		CommandExecutor::run( 'update_plugin', $this->payload( [] ) );

		self::assertIsArray( $at_death );
		self::assertSame( [ self::FILE ], $this->deactivated, 'Restauration tentée par le filet.' );
		self::assertFalse( $at_death['active'], 'Extension désactivée : le site reste debout.' );
		self::assertSame( [], $at_death['activated'], 'Ni bac à sable, ni filet de la mise à jour.' );
		self::assertNotContains( self::FILE, $at_death['options'] );
		self::assertCount( 1, $at_death['pending'] );
		self::assertSame( 'recovery_failed', $at_death['pending'][0]['outcome'] );
		self::assertStringStartsWith( 'extraction failed: disk full' . self::LEFT_INACTIVE, $at_death['pending'][0]['detail'] );
		self::assertStringContainsString( self::NOT_CHECKED, $at_death['pending'][0]['detail'] );
		self::assertSame( CommandExecutor::ACTIVATION_ALREADY_LOADED, $at_death['pending'][0]['reactivation'] );
	}

	/**
	 * Reprise par le cron d'une mise à jour tuée en plein remplacement des fichiers, dont
	 * la restauration échoue à son tour : les fichiers sont à moitié remplacés. Une
	 * extension qui ne se charge pas reste inactive, et `recovery_failed` le dit.
	 */
	public function test_a_recovery_whose_restore_fails_never_forces_a_plugin_that_does_not_load(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )(); // Mise à jour morte à moitié : en-tête 2.0, extension désactivée.
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->restore_fails( then_activation_throws: true );
		$level = ob_get_level();

		ProtectedUpdate::recover( time() ); // Le contrôle du cron.

		self::assertSame( [ self::FILE ], $this->deactivated, 'Restauration tentée.' );
		self::assertSame( [], $this->activated );
		self::assertFalse( $this->active );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ), 'active_plugins jamais écrite de force.' );
		self::assertSame( $level, ob_get_level() );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertStringStartsWith( 'extraction failed: disk full', $pending[0]['detail'] );
		self::assertStringContainsString( self::LEFT_INACTIVE, $pending[0]['detail'] );
		self::assertSame( CommandExecutor::ACTIVATION_LOAD_ERROR, $pending[0]['reactivation'] );
		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Reprise dont la restauration échoue : le point est retenu quand même. Sans cela il
	 * gardait sa date d'expiration (celle d'une mise à jour sans délai de grâce : tout de
	 * suite) et la purge qui suit le supprimait, alors qu'il reste le seul moyen de
	 * remettre l'ancienne version à la main. Ici, c'est la purge elle-même qui reprend.
	 */
	public function test_a_recovery_whose_restore_fails_keeps_its_restore_point_out_of_the_purge(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create(
			self::FILE,
			[
				'version'    => '1.0',
				'kind'       => 'wporg',
				'expires_at' => time() - 900, // Mise à jour sans délai de grâce : expiré dès l'ouverture.
				'hold'       => false,
			],
			time() - 1000
		);
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->restore_fails( then_activation_throws: false );
		$now = time();

		$purged = RestorePointPurgeJob::purge( $this->store, $now );

		self::assertSame( [ [ self::FILE, 'recovery_failed' ] ], $this->pending_outcomes() );
		self::assertIsArray( $purged );
		self::assertSame( 0, $purged['expired'] );
		$kept = $this->store->get( $point['id'] );
		self::assertNotNull( $kept, 'Point gardé pour une intervention manuelle.' );
		self::assertTrue( $kept['hold'] );
		self::assertNull( $kept['expires_at'] );
		self::assertSame( $now + RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS, $kept['hold_until'] );
		self::assertFileExists( (string) $this->store->path_for( $kept ) );
	}

	/**
	 * Restauration RÉUSSIE par la reprise, rétention du point qui lève (index des points
	 * impossible à écrire) : l'ancienne version est bien en place, le résultat le dit.
	 * Avant, l'erreur de la rétention faisait consigner `recovery_failed`.
	 */
	public function test_a_successful_recovery_is_reported_even_when_its_point_cannot_be_held(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				if ( RestorePointStore::OPTION_KEY === $key ) {
					throw new \RuntimeException( 'base indisponible' );
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);

		ProtectedUpdate::recover( time() );

		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertTrue( $this->active );
	}

	/**
	 * Même reprise manquée, extension qui se charge : réactivée par le bac à sable. Le
	 * détail dit qu'elle a été réactivée, que ses fichiers ne sont peut-être pas ceux du
	 * point, et quoi faire. Il se réduisait à l'erreur brute.
	 */
	public function test_a_recovery_whose_restore_fails_reactivates_a_plugin_that_loads_and_says_so(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->restore_fails( then_activation_throws: false );

		ProtectedUpdate::recover( time() );

		self::assertSame( [ self::FILE ], $this->activated );
		self::assertTrue( $this->active );
		$pending = PendingOutcomes::all();
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertSame( 'extraction failed: disk full; the plugin was reactivated: it loads without error, but its files may not be those of the restore point; check the site, then roll it back again from the platform: its restore point is kept', $pending[0]['detail'] );
		self::assertSame( CommandExecutor::ACTIVATION_ACTIVE, $pending[0]['reactivation'] );
		self::assertTrue( $this->store->get( $point['id'] )['hold'], 'Point retenu, comme le dit le détail.' );
	}

	/**
	 * Reprise manquée, WordPress refuse d'activer l'extension AVANT de la charger
	 * (fichier principal introuvable, en-tête illisible) : le détail dit cette cause-là,
	 * pas une erreur de chargement (« which it did not »).
	 */
	public function test_a_recovery_whose_restore_fails_says_when_wordpress_refused_the_plugin(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->restore_fails( then_activation_throws: false );
		Functions\when( 'activate_plugin' )->justReturn( new WP_Error( 'no_plugin_header', 'The plugin does not have a valid header.' ) );

		ProtectedUpdate::recover( time() );

		self::assertFalse( $this->active );
		$pending = PendingOutcomes::all();
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertSame( CommandExecutor::ACTIVATION_INVALID, $pending[0]['reactivation'] );
		self::assertSame( 'extraction failed: disk full; the plugin was left inactive: after this failed restore WordPress refused to activate it before loading it (main file missing, plugin header unreadable, or requirements not met); reinstall it or roll it back again from the platform', $pending[0]['detail'] );
		self::assertStringNotContainsString( 'which it did not', $pending[0]['detail'] );
	}

	/**
	 * Restauration RÉUSSIE par la reprise du cron : l'ancienne version, complète et
	 * vérifiée, est en place. Réactivation garantie comme avant, même quand
	 * activate_plugin() lève hors de l'administration (cas du 2026-09-23) : la voie de
	 * secours écrit l'option. Verrouille le choix de garder force_reactivate() ici.
	 */
	public function test_a_successful_recovery_still_reactivates_the_plugin_when_its_activation_throws(): void {
		$point       = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		$tree_before = $this->tree( $this->plugins . '/akismet' );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->activation_throws = true;
		$level                   = ob_get_level();

		ProtectedUpdate::recover( time() );

		self::assertSame( $tree_before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ), 'Version saine remise en place : réactivée comme avant.' );
		self::assertSame( [ [ self::FILE, 'recovered_rolled_back' ] ], $this->pending_outcomes() );
		// force_reactivate() ne referme pas le tampon qu'activate_plugin() laisse ouvert sur
		// une erreur (comportement historique, inchangé) : refermé ici pour PHPUnit.
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
	}

	/**
	 * La reprise du cron meurt DANS le bac à sable (fichier principal qui inclut un
	 * fichier manquant : erreur fatale que rien ne rattrape). Le filet de shutdown
	 * reprend sa réservation et restaure de nouveau. Si cette restauration échoue aussi,
	 * activate_plugin() n'inclurait plus le fichier principal (include_once : déjà fait
	 * dans ce processus) et activerait l'extension sans l'avoir chargée. Elle reste
	 * inactive.
	 */
	public function test_the_shutdown_net_never_activates_a_plugin_whose_sandbox_already_failed_in_this_process(): void {
		$point = ( new Snapshotter( $this->store, $this->plugins ) )->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg' ], time() - 1000 );
		( Plugin_Upgrader::$on_upgrade )();
		$this->dead_transaction( self::FILE, $point['id'] );
		$this->restore_fails( then_activation_throws: false );
		$calls = 0;
		Functions\when( 'activate_plugin' )->alias(
			function ( string $file ) use ( &$calls ): ?WP_Error {
				++$calls;
				if ( 1 === $calls ) {
					// Tient lieu de l'erreur fatale : le fichier principal est déjà inclus.
					throw new \Error( 'Failed opening required inc/missing.php' );
				}
				// include_once déjà fait : rien n'est chargé, l'option est écrite.
				$this->options['active_plugins'][] = $file;
				$this->active                      = true;
				return null;
			}
		);
		// Ce qu'a fait la reprise du cron avant de mourir dans le bac à sable.
		self::assertNotNull( UpdateTransaction::reserve( (array) UpdateTransaction::current(), time() ) );
		self::assertFalse( CommandExecutor::try_activate( self::FILE, false ) );

		ProtectedUpdate::recover_on_shutdown();

		self::assertSame( 1, $calls, 'Pas de second passage par activate_plugin() : il n\'inclurait plus rien.' );
		self::assertFalse( $this->active );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ) );
		$pending = PendingOutcomes::all();
		self::assertCount( 1, $pending );
		self::assertSame( 'recovery_failed', $pending[0]['outcome'] );
		self::assertStringContainsString( self::LEFT_INACTIVE, $pending[0]['detail'] );
		// Cause exacte : son chargement a échoué à l'essai précédent (pas « code déjà chargé au démarrage »).
		self::assertSame( CommandExecutor::ACTIVATION_LOAD_ERROR, $pending[0]['reactivation'] );
		self::assertStringNotContainsString( self::NOT_CHECKED, $pending[0]['detail'] );
	}

	/**
	 * Une WP_Error de validation (fichier introuvable, en-tête absent) arrive AVANT
	 * l'inclusion du fichier principal : un nouvel essai dans le même processus reste un
	 * vrai chargement, il n'est pas refusé.
	 */
	public function test_try_activate_retries_after_an_error_raised_before_loading_the_plugin(): void {
		$this->active = false;
		$calls        = 0;
		Functions\when( 'activate_plugin' )->alias(
			function () use ( &$calls ): ?WP_Error {
				++$calls;
				if ( 1 === $calls ) {
					return new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' );
				}
				$this->active = true;
				return null;
			}
		);

		self::assertFalse( CommandExecutor::try_activate( self::FILE, false ) );
		self::assertTrue( CommandExecutor::try_activate( self::FILE, false ) );
		self::assertSame( 2, $calls );
	}

	/**
	 * Fichier principal déjà inclus par ce processus (extension active au démarrage de la
	 * requête) : activate_plugin() ne le rechargerait pas et l'activerait sans l'avoir
	 * essayé. Refus, sans appel ; `active_plugins` n'est pas touchée.
	 */
	public function test_try_activate_refuses_a_plugin_whose_main_file_this_process_already_loaded(): void {
		$this->active = false;
		$this->loaded_at_boot();
		$calls = 0;
		Functions\when( 'activate_plugin' )->alias(
			function () use ( &$calls ): ?WP_Error {
				++$calls;
				$this->active = true;
				return null;
			}
		);

		self::assertFalse( CommandExecutor::try_activate( self::FILE, false ) );
		self::assertSame( 0, $calls );
		self::assertFalse( $this->active );

		$this->active = true; // Déjà active : rien à essayer, elle l'est.
		self::assertTrue( CommandExecutor::try_activate( self::FILE, false ) );
		self::assertSame( 0, $calls );
	}

	/**
	 * Détection réelle, sans simulation : un fichier inclus par un autre chemin qui mène
	 * au même endroit est reconnu, comme include_once le reconnaîtrait.
	 */
	public function test_main_file_loaded_recognises_a_file_this_process_really_included(): void {
		$slug = 'g2rd-test-' . bin2hex( random_bytes( 6 ) );
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		mkdir( $dir . '/inc', 0777, true );
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n// Extension factice, sans effet.\n" );
		$file = $slug . '/' . $slug . '.php';
		try {
			self::assertFalse( CommandExecutor::main_file_loaded( $file ) );

			include_once $dir . '/inc/../' . $slug . '.php';

			self::assertTrue( CommandExecutor::main_file_loaded( $file ) );
			self::assertFalse( CommandExecutor::main_file_loaded( 'akismet/akismet.php' ) );
		} finally {
			unlink( $dir . '/' . $slug . '.php' );
			rmdir( $dir . '/inc' );
			rmdir( $dir );
		}
	}

	/**
	 * Données simulées : get_included_files() rend des chemins Windows (antislashs), ou
	 * ne contient pas l'extension ; dossier présent ou absent (remise en place manquée :
	 * realpath() faux). Le chemin tel qu'écrit suffit à reconnaître un fichier inclus au
	 * démarrage ; un dossier absent ne fait ni erreur, ni faux positif.
	 */
	#[DataProvider( 'included_files_cases' )]
	public function test_main_file_loaded_with_simulated_included_files( bool $folder_exists, string $included, bool $expected ): void {
		$slug = 'g2rd-test-' . bin2hex( random_bytes( 6 ) );
		$file = $slug . '/' . $slug . '.php';
		$dir  = WP_PLUGIN_DIR . '/' . $slug;
		if ( $folder_exists ) {
			mkdir( $dir, 0777, true );
			file_put_contents( $dir . '/' . $slug . '.php', "<?php\n" );
		}
		$path = match ( $included ) {
			'antislashs' => str_replace( '/', '\\', WP_PLUGIN_DIR . '/' . $file ),
			'résolu'     => (string) realpath( $dir . '/' . $slug . '.php' ),
			'écrit'      => WP_PLUGIN_DIR . '/' . $file,
			default      => WP_PLUGIN_DIR . '/akismet/akismet.php',
		};
		Functions\when( 'get_included_files' )->justReturn( [ __FILE__, $path ] );
		try {
			self::assertSame( $expected, CommandExecutor::main_file_loaded( $file ) );
		} finally {
			if ( $folder_exists ) {
				unlink( $dir . '/' . $slug . '.php' );
				rmdir( $dir );
			}
		}
	}

	/** @return array<string, array{0: bool, 1: string, 2: bool}> */
	public static function included_files_cases(): array {
		return [
			'chemin Windows (antislashs), dossier présent' => [ true, 'antislashs', true ],
			'chemin Windows (antislashs), dossier absent'  => [ false, 'antislashs', true ],
			'chemin résolu par realpath()'                 => [ true, 'résolu', true ],
			'dossier absent, inclus au chemin écrit'       => [ false, 'écrit', true ],
			'dossier absent, jamais inclus'                => [ false, 'autre', false ],
			'dossier présent, jamais inclus'               => [ true, 'autre', false ],
		];
	}

	/**
	 * Vrai lien symbolique (dossier d'extension qui pointe ailleurs, possible chez
	 * certains hébergeurs) : PHP enregistre le chemin résolu dans get_included_files(),
	 * comme include_once le compare. Sous Windows sans le droit de créer des liens
	 * symboliques, une jonction (même résolution par realpath()) en tient lieu ; ignoré
	 * si le système refuse les deux.
	 */
	public function test_main_file_loaded_recognises_a_plugin_folder_that_is_a_symbolic_link(): void {
		$slug   = 'g2rd-test-' . bin2hex( random_bytes( 6 ) );
		$target = $this->root . '/ailleurs/' . $slug;
		$link   = WP_PLUGIN_DIR . '/' . $slug;
		mkdir( $target, 0777, true );
		file_put_contents( $target . '/' . $slug . '.php', "<?php\n// Extension factice, sans effet.\n" );
		if ( ! is_dir( WP_PLUGIN_DIR ) ) {
			mkdir( WP_PLUGIN_DIR, 0777, true );
		}
		if ( ! @symlink( $target, $link ) && ! self::windows_junction( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
		$file = $slug . '/' . $slug . '.php';
		try {
			self::assertNotSame(
				str_replace( '\\', '/', $link . '/' . $slug . '.php' ),
				str_replace( '\\', '/', (string) realpath( $link . '/' . $slug . '.php' ) ),
				'Le chemin résolu mène ailleurs que WP_PLUGIN_DIR.'
			);
			self::assertFalse( CommandExecutor::main_file_loaded( $file ) );

			include_once $link . '/' . $slug . '.php';

			self::assertTrue( CommandExecutor::main_file_loaded( $file ) );
		} finally {
			// Le lien d'abord (sans suivre la cible) : rmdir() sous Windows, unlink() ailleurs.
			if ( ! ( '\\' === DIRECTORY_SEPARATOR ? @rmdir( $link ) : @unlink( $link ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
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
	 * Jonction Windows (`mklink /J`, sans droit particulier) de `$link` vers `$target` :
	 * realpath() et get_included_files() la résolvent comme un lien symbolique. Faux
	 * hors de Windows, ou si elle n'a pas pu être créée.
	 */
	private static function windows_junction( string $target, string $link ): bool {
		if ( '\\' !== DIRECTORY_SEPARATOR || ! function_exists( 'exec' ) ) {
			return false;
		}
		$to_windows = static fn ( string $path ): string => '"' . str_replace( '/', '\\', $path ) . '"';
		exec( 'cmd /c mklink /J ' . $to_windows( $link ) . ' ' . $to_windows( $target ) . ' 2>&1', $output, $code );
		return 0 === $code && is_dir( $link );
	}

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
	 * La prochaine restauration échoue en pleine extraction (« disk full ») : fichier
	 * principal de l'ancienne version écrit à moitié, puis erreur ; le restaurateur
	 * retire l'extraction partielle et remet le dossier d'avant. Avec
	 * `$then_activation_throws`, le fichier principal lève ensuite au chargement
	 * (activate_plugin() lève une Error), à partir de cette restauration seulement.
	 */
	private function restore_fails( bool $then_activation_throws ): void {
		$s        = Services::make();
		$restorer = new PluginRestorer(
			$this->plugins,
			function ( string $zip, string $destination ) use ( $then_activation_throws ): void {
				mkdir( $destination . '/akismet', 0777, true );
				file_put_contents( $destination . '/akismet/akismet.php', "<?php\n/**\n * Plugin Name: akismet\n" );
				$this->activation_throws = $then_activation_throws;
				throw new \RuntimeException( 'disk full' );
			}
		);
		Services::override( new Services( $s->store, $s->snapshotter, $restorer, $s->health, $s->plugins_root ) );
	}

	/**
	 * L'extension était active au démarrage de la requête : WordPress a inclus son
	 * fichier principal (wp-settings.php), au chemin qu'utilise activate_plugin(). Un
	 * include_once ne le rechargerait plus dans ce processus.
	 */
	private function loaded_at_boot(): void {
		$this->loaded = [ WP_PLUGIN_DIR . '/' . self::FILE ];
		Functions\when( 'get_included_files' )->alias( fn (): array => $this->loaded );
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
	 * celui du test, ni ses rollbacks commencés, ni ses essais d'activation (statiques,
	 * propres à chaque processus), ni les fichiers qu'il a inclus (l'autre processus a
	 * démarré extension désactivée).
	 */
	private function as_another_process( callable $fn ): void {
		$statics = [
			[ new \ReflectionProperty( UpdateTransaction::class, 'held' ), null ],
			[ new \ReflectionProperty( ProtectedUpdate::class, 'rollback_started' ), [] ],
			[ new \ReflectionProperty( ProtectedUpdate::class, 'closed_here' ), [] ],
			[ new \ReflectionProperty( CommandExecutor::class, 'sandbox_failed' ), [] ],
		];
		$mine = [];
		foreach ( $statics as $i => [ $property, $fresh ] ) {
			$mine[ $i ] = $property->getValue();
			$property->setValue( null, $fresh );
		}
		$loaded       = $this->loaded;
		$this->loaded = [];
		try {
			$fn();
		} finally {
			foreach ( $statics as $i => [ $property ] ) {
				$property->setValue( null, $mine[ $i ] );
			}
			$this->loaded = $loaded;
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
