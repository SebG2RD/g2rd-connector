<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Commands;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

	// ── Filet de shutdown (décision du 2026-10-10) ──────────────────────────────

	/**
	 * La requête meurt pendant que WordPress remplace les fichiers (erreur fatale, délai
	 * dépassé) : upgrade() n'a pas rendu la main, les fichiers sont peut-être à moitié
	 * copiés. Le filet ne force plus la réactivation (activate_plugin() ne rechargerait
	 * pas un fichier principal inclus au démarrage, puis l'option serait forcée) :
	 * l'extension reste désactivée. Vaut aussi pour le connecteur lui-même, qui passe
	 * toujours par ce chemin : la plateforme le verra injoignable.
	 *
	 * @param array<string, mixed> $payload
	 */
	#[DataProvider( 'updated_plugins' )]
	public function test_the_shutdown_net_leaves_the_plugin_inactive_when_the_request_dies_while_upgrading( string $file, array $payload ): void {
		$this->plugins[ $file ]      = [ 'Version' => '1.0' ];
		$nets                        = $this->record_shutdown_nets();
		$at_death                    = null;
		Plugin_Upgrader::$on_upgrade = function () use ( $nets, &$at_death ): void {
			$this->active = false; // Le cœur désactive avant de copier…
			// …puis la requête meurt en pleine copie : PHP exécute ses filets de shutdown.
			foreach ( $nets as $net ) {
				$net();
			}
			$at_death = [
				'active'      => $this->active,
				'activations' => $this->activations,
				'option'      => $this->options['active_plugins'] ?? null,
			];
		};

		CommandExecutor::run( 'update_plugin', [ 'file' => $file ] + $payload );

		self::assertSame(
			[
				'active'      => false,
				'activations' => [],
				'option'      => null,
			],
			$at_death,
			'Ni activate_plugin(), ni écriture forcée d\'active_plugins.'
		);
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>}> */
	public static function updated_plugins(): array {
		return [
			'une extension'          => [ 'akismet/akismet.php', [] ],
			// Point de restauration demandé, mais jamais pour le connecteur : chemin simple.
			'le connecteur lui-même' => [ 'g2rd-connector/g2rd-connector.php', [ 'snapshot' => true ] ],
		];
	}

	/**
	 * upgrade() a rendu la main sur un échec (WP_Error) : WordPress a remis les fichiers
	 * d'origine. Le filet rétablit l'extension désactivée par le cœur, comme avant.
	 */
	public function test_the_shutdown_net_still_reactivates_when_the_upgrade_returned_an_error(): void {
		$nets                         = $this->record_shutdown_nets();
		Plugin_Upgrader::$next_result = new WP_Error( 'copy_failed', 'Could not copy file.' );
		Plugin_Upgrader::$on_upgrade  = function (): void {
			$this->active = false;
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );
		foreach ( $nets as $net ) {
			$net();
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Could not copy file.', $outcome['error'] );
		self::assertCount( 1, $this->activations );
		self::assertTrue( $this->active );
	}

	/**
	 * upgrade() lève (code tiers branché sur la copie) après que le cœur a désactivé
	 * l'extension : elle n'a pas rendu la main, les fichiers sont peut-être à moitié
	 * copiés. L'extension reste désactivée, et l'erreur le dit à la plateforme.
	 */
	public function test_an_exception_while_upgrading_leaves_the_plugin_inactive_and_says_so(): void {
		$nets                        = $this->record_shutdown_nets();
		Plugin_Upgrader::$on_upgrade = function (): void {
			$this->active = false;
			throw new \RuntimeException( 'Copy interrupted by a third-party hook.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );
		foreach ( $nets as $net ) {
			$net();
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertStringStartsWith( 'Copy interrupted by a third-party hook.; the plugin was left inactive:', $outcome['error'] );
		self::assertStringContainsString( 'its files may be incomplete', $outcome['error'] );
		self::assertSame( [], $this->activations );
		self::assertFalse( $this->active );
	}

	/** Même exception avant toute désactivation (téléchargement) : extension active, erreur inchangée. */
	public function test_an_exception_before_the_plugin_is_deactivated_leaves_it_active(): void {
		$nets                         = $this->record_shutdown_nets();
		Plugin_Upgrader::$on_download = static function (): void {
			throw new \RuntimeException( 'Download interrupted.' );
		};

		$outcome = CommandExecutor::run( 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );
		foreach ( $nets as $net ) {
			$net();
		}

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Download interrupted.', $outcome['error'] );
		self::assertSame( [], $this->activations );
		self::assertTrue( $this->active );
	}

	/**
	 * Rappels passés à register_shutdown_function() pendant le test.
	 *
	 * @return \ArrayObject<int, mixed>
	 */
	private function record_shutdown_nets(): \ArrayObject {
		$nets = new \ArrayObject();
		Functions\when( 'register_shutdown_function' )->alias(
			static function ( $callback, ...$args ) use ( $nets ): void {
				$nets->append( static fn () => $callback( ...$args ) );
			}
		);
		// Chemin simple choisi pour le connecteur malgré `snapshot` (cf. update_plugin()).
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'plugin_basename' )->justReturn( 'g2rd-connector/g2rd-connector.php' );
		return $nets;
	}
}
