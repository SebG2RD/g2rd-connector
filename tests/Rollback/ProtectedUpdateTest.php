<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Rollback\PluginRestorer;
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Services;
use G2RD\Connector\Rollback\Snapshotter;
use G2RD\Connector\Rollback\UpdateTransaction;
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

	protected function setUp(): void {
		parent::setUp();
		Plugin_Upgrader::reset();
		$this->store           = new RestorePointStore();
		$this->responses       = [];
		$this->response_index  = 0;
		$this->active          = true;
		$this->deactivated     = [];
		$this->activated       = [];

		// ── WordPress simulé ────────────────────────────────────────────────────
		Functions\when( 'get_plugins' )->alias( fn (): array => $this->plugins_on_disk() );
		Functions\when( 'is_plugin_active' )->alias( fn (): bool => $this->active );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'register_shutdown_function' )->justReturn( null );
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
		Functions\when( 'add_filter' )->justReturn( true );
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

	public function test_recover_ignores_a_fresh_transaction_unless_forced(): void {
		UpdateTransaction::open( [ 'plugin_file' => self::FILE ], time() );
		ProtectedUpdate::recover( time() );
		self::assertNotNull( UpdateTransaction::current() );

		ProtectedUpdate::recover( time(), true );
		self::assertNull( UpdateTransaction::current() );
		self::assertSame( 'interrupted_unverified', PendingOutcomes::all()[0]['outcome'] );
	}

	// ── Outils ────────────────────────────────────────────────────────────────

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
