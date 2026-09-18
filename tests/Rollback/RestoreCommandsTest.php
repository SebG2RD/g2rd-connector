<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\PluginRestorer;
use G2RD\Connector\Rollback\RestoreCommands;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Services;
use G2RD\Connector\Rollback\Snapshotter;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Settings;
use WP_Error;
use WP_REST_Request;

final class RestoreCommandsTest extends FilesystemTestCase {

	private const FILE  = 'akismet/akismet.php';
	private const TOKEN = 'jeton-du-site-0123456789';

	private RestorePointStore $store;
	private Snapshotter $snapshotter;
	private bool $active = true;
	/** @var array<string, mixed> */
	private array $point = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store       = new RestorePointStore();
		$this->snapshotter = new Snapshotter( $this->store, $this->plugins );
		$this->active      = true;

		Functions\when( 'get_plugins' )->alias( fn (): array => $this->plugins_on_disk() );
		Functions\when( 'is_plugin_active' )->alias( fn (): bool => $this->active );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'plugin_basename' )->alias( static fn ( string $f ): string => 'g2rd-connector/g2rd-connector.php' );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'deactivate_plugins' )->alias( function (): void { $this->active = false; } );
		Functions\when( 'activate_plugin' )->alias( function (): ?WP_Error { $this->active = true; return null; } );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'home_url' )->alias( static fn ( string $p = '' ): string => 'https://site.test' . $p );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => 'https://site.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn ( $k, $v = null, $u = null ): string => is_array( $k ) ? $v . '?' . http_build_query( $k ) : $u . '?' . $k . '=' . $v );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		$health = new HealthChecker(
			static function ( string $url ): array {
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
				$body = str_contains( $url, 'admin-ajax' ) ? json_encode( [ 'ok' => true, 'token' => $q['token'] ?? '' ] ) : 'ok';
				return [ 'code' => 200, 'body' => (string) $body, 'error' => null ];
			}
		);
		Services::override( new Services( $this->store, $this->snapshotter, new PluginRestorer( $this->plugins ), $health, $this->plugins ) );

		// Point pris en 1.0, plugin ensuite en 2.0.
		$this->make_plugin( 'akismet', '1.0', [ 'assets/old.css' => 'old' ] );
		$this->point = $this->snapshotter->create( self::FILE, [ 'version' => '1.0', 'kind' => 'wporg', 'expires_at' => time() + 3600 ], time() );
		file_put_contents( $this->plugins . '/akismet/akismet.php', "<?php\n/**\n * Plugin Name: akismet\n * Version: 2.0\n */\n" );
		file_put_contents( $this->plugins . '/akismet/inc/new-module.php', "<?php\n" );
	}

	protected function tearDown(): void {
		Services::override( null );
		parent::tearDown();
	}

	public function test_manual_rollback_from_a_local_restore_point(): void {
		$outcome = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] );

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'restore_point', $r['via'] );
		self::assertSame( '2.0', $r['version_before'] );
		self::assertSame( '1.0', $r['version_after'] );
		self::assertSame( 'healthy', $r['health']['verdict'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertFileExists( $this->plugins . '/akismet/assets/old.css' );
		self::assertFileDoesNotExist( $this->plugins . '/akismet/inc/new-module.php' );
		self::assertTrue( $this->active );
		self::assertSame( [ self::FILE => '2.0' ], AutoUpdateGuard::all() );
		self::assertTrue( $this->store->get( $this->point['id'] )['hold'] );
	}

	/** La plateforme annonce un autre hash que l'index du site : intégrité, rien n'est touché. */
	public function test_hash_mismatch_with_platform_record_touches_nothing(): void {
		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'restore_point_id' => $this->point['id'],
			'expected_sha256'  => str_repeat( 'ab', 32 ),
			'expected_version' => '1.0',
		] )['result'];

		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertTrue( $this->active, 'pas même désactivé' );
	}

	public function test_tampered_archive_touches_nothing(): void {
		file_put_contents( (string) $this->store->path_for( $this->point ), 'corrompu' );

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'restore_point_id' => $this->point['id'],
			'expected_sha256'  => $this->point['sha256'],
			'expected_version' => '1.0',
		] )['result'];

		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
	}

	public function test_version_drift_is_refused(): void {
		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.1',
		] )['result'];

		self::assertSame( 'version_drift', $r['outcome'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
	}

	/** Point absent et pas d'archive de repli : la plateforme décide (wordpress.org ou échec explicite). */
	public function test_missing_point_without_source_reports_snapshot_missing(): void {
		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'restore_point_id' => 'inexistant',
			'expected_version' => '1.0',
		] )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SNAPSHOT_MISSING, $r['outcome'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
	}

	public function test_download_fallback_refuses_a_host_outside_the_allowlist(): void {
		Functions\when( 'download_url' )->alias( static fn (): never => throw new \LogicException( 'aucun téléchargement attendu' ) );

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'source_url'       => 'https://evil.example/akismet.1.0.zip',
			'expected_version' => '1.0',
		] )['result'];

		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertStringContainsString( 'evil.example', $r['error'] );
	}

	public function test_download_fallback_restores_from_an_allowed_host(): void {
		$archive = (string) $this->store->path_for( $this->point );
		$tmp     = $this->root . '/downloaded.zip';
		Functions\when( 'download_url' )->alias(
			static function ( string $url ) use ( $archive, $tmp ): string {
				copy( $archive, $tmp );
				return $tmp;
			}
		);

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'source_url'               => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'download', $r['via'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertFileDoesNotExist( $tmp, 'l\'archive téléchargée est nettoyée' );
	}

	public function test_the_connector_itself_cannot_be_rolled_back(): void {
		Functions\when( 'plugin_basename' )->alias( static fn (): string => self::FILE );

		$outcome = CommandExecutor::run( 'rollback_plugin', [ 'file' => self::FILE, 'expected_version' => '1.0' ] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringContainsString( 'cannot roll itself back', $outcome['error'] );
	}

	public function test_delete_restore_point_by_ids_and_all(): void {
		$this->make_plugin( 'hello-dolly', '1.0' );
		$other = $this->snapshotter->create( 'hello-dolly/hello-dolly.php', [ 'version' => '1.0' ], time() );

		$r = CommandExecutor::run( 'delete_restore_point', [ 'ids' => [ $this->point['id'], 'inconnu' ] ] )['result'];
		self::assertSame( [ $this->point['id'] ], $r['deleted'] );
		self::assertSame( 1, $r['remaining'] );
		self::assertFileDoesNotExist( (string) $this->store->path_for( $this->point ) );

		$r = CommandExecutor::run( 'delete_restore_point', [ 'all' => true ] )['result'];
		self::assertSame( [ $other['id'] ], $r['deleted'] );
		self::assertSame( 0, $r['remaining'] );
		self::assertSame( 0, $r['total_bytes'] );
	}

	public function test_set_signature_policy(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7, 'site_token' => self::TOKEN ];

		$r = CommandExecutor::run( 'set_signature_policy', [ 'policy' => 'required' ] )['result'];
		self::assertSame( 'required', $r['signature_policy'] );

		$r = CommandExecutor::run( 'set_signature_policy', [ 'policy' => 'nimporte' ] )['result'];
		self::assertSame( 'report', $r['signature_policy'], 'valeur inconnue → la plus permissive' );
	}

	/**
	 * Les nouvelles commandes exigent la signature MÊME en politique `report`, alors
	 * qu'une commande historique non signée passe toujours.
	 */
	public function test_new_commands_require_a_valid_signature_even_in_report_mode(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7, 'site_token' => self::TOKEN ];

		foreach ( CommandExecutor::SIGNED_ONLY as $command ) {
			$request = $this->request( $command );
			$result  = Auth::require_site_token( $request );
			self::assertInstanceOf( WP_Error::class, $result, $command . ' sans signature doit être refusée' );
			self::assertSame( 'g2rd_connector_signature_missing', $result->get_error_code() );

			// Nouvel objet : le résultat de vérification est mémorisé PAR requête (cf Auth).
			self::assertTrue( Auth::require_site_token( $this->signed( $this->request( $command ) ) ), $command . ' signée doit passer' );
		}

		self::assertTrue( Auth::require_site_token( $this->request( 'clear_cache' ) ), 'commande historique non signée : acceptée en mode rapport' );
	}

	private function request( string $command ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/g2rd/v1/command' );
		$request->set_header( 'Authorization', 'Bearer ' . self::TOKEN );
		$request->set_body( json_encode( [ 'command' => $command ] ) );
		$request->set_param( 'command', $command );
		return $request;
	}

	private function signed( WP_REST_Request $request ): WP_REST_Request {
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		$request->set_header( 'X-G2RD-Timestamp', $ts );
		$request->set_header( 'X-G2RD-Nonce', $nonce );
		$request->set_header( 'X-G2RD-Signature', 'v1=' . RequestSignature::sign( self::TOKEN, 'POST', '/g2rd/v1/command', $ts, $nonce, $request->get_body() ) );
		return $request;
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
}
