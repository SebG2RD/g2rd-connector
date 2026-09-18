<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Commands;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Tests\TestCase;
use Plugin_Upgrader;
use WP_Error;

/**
 * TEST DE CARACTÉRISATION : fige le comportement d'`update_plugin` SANS le drapeau
 * `snapshot` — le chemin emprunté par toutes les plateformes actuelles. Il a été
 * écrit AVANT l'ajout du chemin « point de restauration » et doit rester vert tel
 * quel jusqu'à la fin du chantier : si l'un de ces tests casse, c'est une régression
 * sur le parc, pas un test à adapter.
 */
final class UpdatePluginLegacyTest extends TestCase {

	/** @var array<string, array<string, string>> */
	private array $plugins = [];
	private bool $active   = true;
	/** @var list<array<string, mixed>> */
	private array $activations = [];

	protected function setUp(): void {
		parent::setUp();
		Plugin_Upgrader::reset();

		$this->plugins = [ 'akismet/akismet.php' => [ 'Version' => '1.0' ] ];
		$this->active  = true;

		Functions\when( 'get_plugins' )->alias( fn (): array => $this->plugins );
		Functions\when( 'is_plugin_active' )->alias( fn (): bool => $this->active );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'wp_clean_plugins_cache' )->justReturn( null );
		Functions\when( 'register_shutdown_function' )->justReturn( null );
		Functions\when( 'activate_plugin' )->alias(
			function ( string $file, string $redirect, bool $network, bool $silent ): ?WP_Error {
				$this->activations[] = compact( 'file', 'network', 'silent' );
				$this->active        = true;
				return null;
			}
		);
		// PremiumUpdatesBridge::refresh_update_transients()
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_update_themes' )->justReturn( null );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
	}

	public function test_nominal_result_shape_is_unchanged(): void {
		Plugin_Upgrader::$on_upgrade = function (): void {
			$this->plugins['akismet/akismet.php']['Version'] = '2.0';
			$this->active                                    = false; // le cœur désactive pendant l'upgrade
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertSame(
			[
				'updated'        => true,
				'file'           => 'akismet/akismet.php',
				'version_before' => '1.0',
				'version_after'  => '2.0',
				'was_active'     => true,
				'reactivated'    => true,
				'reason'         => null,
			],
			$outcome['result']
		);
		self::assertSame( [ 'akismet/akismet.php' ], Plugin_Upgrader::$upgraded );
		self::assertCount( 1, $this->activations, 'réactivation nominale, silencieuse' );
		self::assertTrue( $this->activations[0]['silent'] );
		self::assertArrayNotHasKey( 'stray_output', $outcome );
	}

	public function test_inactive_plugin_is_not_reactivated(): void {
		$this->active                = false;
		Plugin_Upgrader::$on_upgrade = function (): void {
			$this->plugins['akismet/akismet.php']['Version'] = '2.0';
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		self::assertFalse( $outcome['result']['was_active'] );
		self::assertFalse( $outcome['result']['reactivated'] );
		self::assertSame( [], $this->activations );
	}

	public function test_unchanged_version_is_reported_not_updated(): void {
		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertFalse( $outcome['result']['updated'] );
		self::assertSame( 'version_unchanged', $outcome['result']['reason'] );
		self::assertSame( '1.0', $outcome['result']['version_after'] );
	}

	public function test_upgrader_error_is_a_failed_outcome(): void {
		Plugin_Upgrader::$next_result = new WP_Error( 'download_failed', 'Téléchargement impossible.' );

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Téléchargement impossible.', $outcome['error'] );
	}

	public function test_unknown_plugin_is_refused(): void {
		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'inconnu/inconnu.php' ] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'plugin not installed: inconnu/inconnu.php', $outcome['error'] );
		self::assertSame( [], Plugin_Upgrader::$upgraded );
	}

	public function test_missing_file_is_refused(): void {
		$outcome = CommandExecutor::run( 'update_plugin', [] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'payload.file required', $outcome['error'] );
	}

	/**
	 * Sans le drapeau, RIEN du module Rollback ne s'exécute : ni point de
	 * restauration, ni index, ni contrôle de santé.
	 */
	public function test_legacy_path_never_touches_the_rollback_module(): void {
		Functions\when( 'wp_remote_get' )->alias(
			static function (): never {
				throw new \LogicException( 'aucune requête loopback attendue sur le chemin historique' );
			}
		);
		Plugin_Upgrader::$on_upgrade = function (): void {
			$this->plugins['akismet/akismet.php']['Version'] = '2.0';
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertArrayNotHasKey( RestorePointStore::OPTION_KEY, $this->options );
		self::assertArrayNotHasKey( 'restore_point', $outcome['result'] );
		self::assertArrayNotHasKey( 'health', $outcome['result'] );
	}

	public function test_unknown_command_returns_null(): void {
		self::assertNull( CommandExecutor::run( 'format_disk', [] ) );
	}
}
