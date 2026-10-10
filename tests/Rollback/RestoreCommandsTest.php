<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Actions;
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
use G2RD\Connector\Rollback\UnverifiedDownloads;
use G2RD\Connector\Rollback\UpdateTransaction;
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
	private int $deactivations = 0;
	/** @var array<string, mixed> */
	private array $point = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store         = new RestorePointStore();
		$this->snapshotter   = new Snapshotter( $this->store, $this->plugins );
		$this->active        = true;
		$this->deactivations = 0;

		Functions\when( 'get_plugins' )->alias( fn (): array => $this->plugins_on_disk() );
		Functions\when( 'is_plugin_active' )->alias( fn (): bool => $this->active );
		Functions\when( 'is_plugin_active_for_network' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'plugin_basename' )->alias( static fn ( string $f ): string => 'g2rd-connector/g2rd-connector.php' );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'deactivate_plugins' )->alias(
			function (): void {
				++$this->deactivations;
				$this->active = false;
			}
		);
		// Comme sur un vrai site : l'extension est active au démarrage de la requête qui
		// reçoit la commande, WordPress a inclus son fichier principal. Un include_once ne
		// le rechargerait plus (cf. CommandExecutor::try_activate()).
		Functions\when( 'get_included_files' )->justReturn( [ WP_PLUGIN_DIR . '/' . self::FILE ] );
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
		self::assertTrue( $this->active, 'une restauration refusée ne doit pas laisser l\'extension éteinte' );
	}

	/**
	 * Une erreur fatale pendant la réactivation ne doit plus laisser l'extension
	 * éteinte.
	 *
	 * `activate_plugin()` inclut le fichier principal de l'extension et laisse courir
	 * du code tiers, le tout AVANT d'écrire `active_plugins`. Le 2026-09-23 sur
	 * g2rd.fr, Imagify a été correctement restauré en 2.3.2 puis laissé DÉSACTIVÉ,
	 * avec « Call to a member function dirlist() on null » renvoyé à la plateforme.
	 * Sur un site client, perdre une extension est pire que le problème qu'on venait
	 * réparer.
	 *
	 * La voie de secours n'exécute aucun code tiers : elle écrit l'option directement.
	 */
	public function test_a_fatal_during_reactivation_still_leaves_the_plugin_active(): void {
		Functions\when( 'activate_plugin' )->alias(
			static function (): void {
				throw new \Error( 'Call to a member function dirlist() on null' );
			}
		);

		$outcome = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] );

		self::assertSame( 'done', $outcome['status'], 'la commande ne doit pas remonter l\'erreur fatale' );
		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $outcome['result']['outcome'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertContains(
			self::FILE,
			(array) ( $this->options['active_plugins'] ?? [] ),
			'la voie de secours doit rallumer l\'extension sans passer par le code tiers',
		);
	}

	/**
	 * La voie de secours ne vaut QUE pour une restauration réussie. Restauration qui
	 * échoue en pleine extraction (disque plein) : le dossier peut être à moitié
	 * remplacé. L'extension n'est réactivée que si elle se charge sans erreur ; ici
	 * elle lève une Error, elle reste inactive (jamais d'écriture forcée
	 * d'`active_plugins`, qui mettrait tout le site en « erreur critique »), et le
	 * résultat le dit.
	 */
	public function test_a_failed_restore_never_forces_a_plugin_that_does_not_load(): void {
		$s = Services::make();
		Services::override(
			new Services(
				$s->store,
				$s->snapshotter,
				new PluginRestorer(
					$this->plugins,
					static function ( string $zip, string $destination ): void {
						mkdir( $destination . '/akismet', 0777, true );
						file_put_contents( $destination . '/akismet/akismet.php', "<?php\n/**\n * Plugin Name: akismet\n" );
						throw new \RuntimeException( 'disk full' );
					}
				),
				$s->health,
				$s->plugins_root
			)
		);
		$level = ob_get_level();
		Functions\when( 'activate_plugin' )->alias(
			static function (): void {
				ob_start(); // Comme activate_plugin(), avant d'inclure le fichier principal.
				throw new \Error( 'Class "Akismet\\Module" not found' );
			}
		);

		$outcome = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] );

		self::assertSame( 'done', $outcome['status'] );
		$r = $outcome['result'];
		self::assertSame( 'rollback_failed', $r['outcome'] );
		self::assertStringStartsWith( 'extraction failed: disk full; the plugin was left inactive:', $r['error'] );
		self::assertFalse( $this->active, 'L\'extension reste inactive : le site reste debout.' );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ), 'active_plugins jamais écrite de force.' );
		self::assertSame( $level, ob_get_level() );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'], 'Dossier d\'origine remis en place par le restaurateur.' );
	}

	/**
	 * Une reprise du cron (ou une mise à jour protégée) est en cours : elle peut
	 * restaurer la même extension. Le rollback manuel est refusé avant tout
	 * changement, avec le message déjà connu de la plateforme.
	 */
	public function test_manual_rollback_is_refused_while_a_protected_update_or_a_recovery_is_running(): void {
		$recovering = [
			'id'               => 'morte',
			'plugin_file'      => self::FILE,
			'was_active'       => true,
			'step'             => UpdateTransaction::STEP_RECOVERING,
			'recovering_from'  => UpdateTransaction::STEP_UPGRADING,
			'recovery_token'   => 'jeton-du-cron',
			'restore_point_id' => $this->point['id'],
			'started_at'       => time() - 1000,
			'updated_at'       => time() - 20,
		];
		$this->options[ UpdateTransaction::OPTION_KEY ] = $recovering;

		$outcome = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringStartsWith( 'another protected update is still running on this site', $outcome['error'] );
		self::assertStringContainsString( 'nothing was changed, retry in a few minutes', $outcome['error'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'], 'Rien n\'a changé.' );
		self::assertTrue( $this->active );
		self::assertFalse( $this->store->get( $this->point['id'] )['hold'] );
		self::assertSame( $recovering, $this->options[ UpdateTransaction::OPTION_KEY ] );
	}

	/** Une transaction morte (plus de 10 min sans signe de vie) ne bloque pas le rollback manuel, comme avant. */
	public function test_a_dead_transaction_does_not_block_the_manual_rollback(): void {
		$this->options[ UpdateTransaction::OPTION_KEY ] = [
			'id'          => 'morte',
			'plugin_file' => 'autre/autre.php',
			'step'        => UpdateTransaction::STEP_HEALTH,
			'started_at'  => time() - 2000,
			'updated_at'  => time() - 1000,
		];

		$outcome = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $outcome['result']['outcome'] );
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

	/**
	 * Refus d'avant la mise de côté du dossier (version installée qui n'est plus celle
	 * attendue, archive d'une autre version) : l'extension n'est même pas désactivée.
	 * Désactivée plus tôt, elle ne pourrait pas être rallumée dans cette requête, qui a
	 * déjà chargé son fichier principal : une extension intacte resterait éteinte.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refusals_before_the_folder_is_set_aside' )]
	public function test_a_refusal_before_the_folder_is_set_aside_leaves_the_plugin_active( array $payload, string $error_code ): void {
		$r = CommandExecutor::run(
			'rollback_plugin',
			$payload + [
				'file'             => self::FILE,
				'restore_point_id' => $this->point['id'],
				'expected_sha256'  => $this->point['sha256'],
			]
		)['result'];

		self::assertSame( $error_code, $r['outcome'] );
		self::assertSame( $error_code, $r['error_code'] );
		self::assertStringNotContainsString( 'left inactive', $r['error'] );
		self::assertSame( 0, $this->deactivations, 'Jamais désactivée.' );
		self::assertTrue( $this->active );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
	}

	/** @return array<string, array{0: array<string, string>, 1: string}> */
	public static function refusals_before_the_folder_is_set_aside(): array {
		return [
			'version_drift'               => [ [ 'expected_version' => '1.0', 'expected_current_version' => '2.1' ], 'version_drift' ],
			'archive d\'une autre version' => [ [ 'expected_version' => '1.1', 'expected_current_version' => '2.0' ], 'rollback_failed_integrity' ],
		];
	}

	/**
	 * Restauration qui échoue en pleine extraction, extension qui se chargerait sans
	 * erreur. Mais cette requête l'a chargée à son démarrage : le bac à sable ne la
	 * rechargerait pas et l'activerait sans l'avoir essayée. Elle reste désactivée, et
	 * le résultat le dit (même code d'erreur).
	 */
	public function test_a_failed_restore_leaves_the_plugin_inactive_in_the_request_that_loaded_it(): void {
		$s = Services::make();
		Services::override(
			new Services(
				$s->store,
				$s->snapshotter,
				new PluginRestorer(
					$this->plugins,
					static function (): void {
						throw new \RuntimeException( 'disk full' );
					}
				),
				$s->health,
				$s->plugins_root
			)
		);
		$activations = 0;
		Functions\when( 'activate_plugin' )->alias(
			function () use ( &$activations ): ?WP_Error {
				++$activations;
				$this->active = true;
				return null;
			}
		);

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => $this->point['id'],
			'expected_sha256'          => $this->point['sha256'],
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] )['result'];

		self::assertSame( 'rollback_failed', $r['outcome'] );
		self::assertSame( 'rollback_failed', $r['error_code'] );
		self::assertStringStartsWith( 'extraction failed: disk full; the plugin was left inactive:', $r['error'] );
		self::assertStringContainsString( 'which could not be checked because its code was already loaded earlier in this request', $r['error'] );
		self::assertSame( 0, $activations, 'Aucun essai : il n\'essaierait rien.' );
		self::assertFalse( $this->active );
		self::assertNotContains( self::FILE, (array) ( $this->options['active_plugins'] ?? [] ) );
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
		// Fonction classique, pas fléchée : PHP 8.1 refuse `fn (): never => throw …`.
		Functions\when( 'download_url' )->alias(
			static function (): never {
				throw new \LogicException( 'aucun téléchargement attendu' );
			}
		);

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

	/**
	 * K3 — l'archive wordpress.org est comparée à l'empreinte envoyée par la
	 * plateforme (`source_sha256`) : différente, rien n'est touché.
	 */
	public function test_download_fallback_refuses_an_archive_whose_hash_differs_from_source_sha256(): void {
		$tmp = $this->serve_point_archive_as_download();

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'source_url'               => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
			'source_sha256'            => str_repeat( 'cd', 32 ),
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] )['result'];

		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertSame( 'download', $r['via'] );
		self::assertStringContainsString( 'source_sha256', $r['error'] );
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'], 'rien n\'est installé' );
		self::assertFileExists( $this->plugins . '/akismet/inc/new-module.php', 'le dossier actuel n\'est pas touché' );
		self::assertTrue( $this->active, 'pas même désactivé' );
		self::assertFileDoesNotExist( $tmp, 'l\'archive téléchargée est nettoyée' );
		self::assertArrayNotHasKey( 'g2rd_connector_unverified_downloads', $this->options, 'un refus n\'est pas une installation non vérifiée' );
	}

	/** L'empreinte est acceptée en majuscules ou entourée d'espaces (normalisation). */
	public function test_download_fallback_accepts_an_archive_matching_source_sha256(): void {
		$sha = (string) hash_file( 'sha256', (string) $this->store->path_for( $this->point ) );
		$this->serve_point_archive_as_download();

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'source_url'               => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
			'source_sha256'            => ' ' . strtoupper( $sha ) . ' ',
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'download', $r['via'] );
		self::assertSame( 'verified', $r['source_integrity'] );
		self::assertSame( $sha, $r['source_sha256_actual'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertArrayNotHasKey( 'g2rd_connector_unverified_downloads', $this->options );
	}

	/** Empreinte mal formée : refus AVANT tout téléchargement. */
	public function test_download_fallback_rejects_a_malformed_source_sha256_before_downloading(): void {
		Functions\when( 'download_url' )->alias(
			static function (): never {
				throw new \LogicException( 'aucun téléchargement attendu' );
			}
		);

		foreach ( [ 'pas-un-hash', str_repeat( 'g', 64 ), str_repeat( 'a', 63 ), [ 'tableau' ] ] as $bad ) {
			$r = CommandExecutor::run( 'rollback_plugin', [
				'file'             => self::FILE,
				'source_url'       => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
				'source_sha256'    => $bad,
				'expected_version' => '1.0',
			] )['result'];

			self::assertSame( 'rollback_failed_integrity', $r['outcome'], var_export( $bad, true ) );
			self::assertSame( 'download', $r['via'] );
			self::assertStringContainsString( 'source_sha256', $r['error'] );
		}
		self::assertSame( '2.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );
		self::assertTrue( $this->active );
	}

	/**
	 * Payload exact du manager actuel (pas de `source_sha256`) : comportement
	 * inchangé, mais l'installation non vérifiée est tracée (compteur borné) et
	 * annoncée aux intégrateurs.
	 */
	public function test_download_fallback_without_source_sha256_keeps_current_behaviour_and_records_it(): void {
		$sha = (string) hash_file( 'sha256', (string) $this->store->path_for( $this->point ) );
		$this->serve_point_archive_as_download();
		$url = 'https://downloads.wordpress.org/plugin/akismet.1.0.zip';
		Actions\expectDone( 'g2rd_connector_rollback_source_unverified' )->twice()->with( self::FILE, $url, $sha );

		$payload = [
			'file'                     => self::FILE,
			'source_url'               => $url,
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		];
		$r = CommandExecutor::run( 'rollback_plugin', $payload )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'download', $r['via'] );
		self::assertSame( 'unverified', $r['source_integrity'] );
		self::assertSame( $sha, $r['source_sha256_actual'] );
		self::assertSame( '1.0', $this->plugins_on_disk()[ self::FILE ]['Version'] );

		$trace = UnverifiedDownloads::stats();
		self::assertSame( 1, $trace['count'] );
		self::assertSame( self::FILE, $trace['last_file'] );
		self::assertSame( $url, $trace['last_url'] );
		self::assertSame( $sha, $trace['last_sha256'] );
		self::assertIsInt( $trace['last_at'] );

		// Deuxième restauration : le compteur avance, la trace ne grossit pas.
		file_put_contents( $this->plugins . '/akismet/akismet.php', "<?php\n/**\n * Plugin Name: akismet\n * Version: 2.0\n */\n" );
		$this->serve_point_archive_as_download();
		CommandExecutor::run( 'rollback_plugin', $payload );
		self::assertSame( 2, UnverifiedDownloads::stats()['count'] );
		self::assertCount( 5, (array) $this->options[ UnverifiedDownloads::OPTION_KEY ], 'trace bornée : compteur + dernier cas' );
	}

	/** Une trace impossible à écrire ne fait jamais échouer la restauration. */
	public function test_unverified_trace_failure_never_breaks_the_restoration(): void {
		$this->serve_point_archive_as_download();
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				if ( 'g2rd_connector_unverified_downloads' === $key ) {
					throw new \RuntimeException( 'base indisponible' );
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'source_url'       => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
			'expected_version' => '1.0',
		] )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'unverified', $r['source_integrity'] );
	}

	/**
	 * Verrou du piège manager : `expected_sha256` est l'empreinte du POINT LOCAL,
	 * envoyée dans la même commande que `source_url`. Si le point a disparu, elle
	 * ne doit jamais être appliquée à l'archive wordpress.org (sinon tout retour
	 * arrière de ce cas serait refusé).
	 */
	public function test_point_hash_is_never_applied_to_the_downloaded_archive(): void {
		$this->serve_point_archive_as_download();

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'                     => self::FILE,
			'restore_point_id'         => 'inexistant',
			'expected_sha256'          => str_repeat( 'ab', 32 ),
			'source_url'               => 'https://downloads.wordpress.org/plugin/akismet.1.0.zip',
			'expected_version'         => '1.0',
			'expected_current_version' => '2.0',
		] )['result'];

		self::assertSame( RestoreCommands::OUTCOME_SUCCESS, $r['outcome'] );
		self::assertSame( 'download', $r['via'] );
		self::assertSame( 'unverified', $r['source_integrity'] );
	}

	/** Un échec d'intégrité dit d'où il vient : copie locale ou archive téléchargée. */
	public function test_integrity_failure_reports_which_source_failed(): void {
		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'source_url'       => 'https://evil.example/akismet.1.0.zip',
			'expected_version' => '1.0',
		] )['result'];
		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertSame( 'download', $r['via'] );

		$r = CommandExecutor::run( 'rollback_plugin', [
			'file'             => self::FILE,
			'restore_point_id' => $this->point['id'],
			'expected_sha256'  => str_repeat( 'ab', 32 ),
			'expected_version' => '1.0',
		] )['result'];
		self::assertSame( 'rollback_failed_integrity', $r['outcome'] );
		self::assertSame( 'restore_point', $r['via'] );
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
	 * Simule le téléchargement wordpress.org : `download_url()` rend une copie de
	 * l'archive du point local (version 1.0).
	 *
	 * @return string Chemin du fichier temporaire « téléchargé ».
	 */
	private function serve_point_archive_as_download(): string {
		$archive = (string) $this->store->path_for( $this->point );
		$tmp     = $this->root . '/downloaded.zip';
		Functions\when( 'download_url' )->alias(
			static function () use ( $archive, $tmp ): string {
				copy( $archive, $tmp );
				return $tmp;
			}
		);
		return $tmp;
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
