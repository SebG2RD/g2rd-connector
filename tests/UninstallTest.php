<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Functions;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Events\LoginFailedThrottle;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\DirectLogin\FakeWpdb;

/**
 * Désinstallation (uninstall.php) : ce que l'allègement du connecteur a ajouté est
 * retiré comme le reste — le compteur des connexions échouées, sur chaque blog en
 * multisite, et un contrôle de reprise ponctuel encore planifié.
 *
 * WordPress inclut uninstall.php sans charger le plugin ; le test fait de même,
 * dans un multisite simulé de deux blogs.
 */
final class UninstallTest extends TestCase {

	private int $blog = 1;

	/** @var list<string> Transients retirés, « blog:nom ». */
	private array $deleted_transients = [];

	/** @var list<string> Hooks dont la planification est retirée. */
	private array $cleared_hooks = [];

	protected function setUp(): void {
		parent::setUp();
		$this->blog               = 1;
		$this->deleted_transients = [];
		$this->cleared_hooks      = [];

		$GLOBALS['wpdb'] = new FakeWpdb(
			fn (): array => array_map( 'strval', array_keys( $this->options ) ),
			static function (): void {}
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( [ 1, 2 ] );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $blog_id ): bool {
				$this->blog = $blog_id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = 1;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$this->deleted_transients[] = $this->blog . ':' . $name;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->alias( static fn ( string $file ): string => dirname( $file ) . '/' );
		// Aucun dossier de points de restauration sur ce site.
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => 'g2rd_connector_snapshots_dir' === $hook ? sys_get_temp_dir() . '/g2rd-uninstall-absent' : $value
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_unschedule_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook ): int {
				$this->cleared_hooks[] = $hook;
				return 0;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_le_compteur_des_connexions_echouees_est_retire_sur_chaque_blog(): void {
		$this->uninstall();

		self::assertContains( '1:' . LoginFailedThrottle::TRANSIENT_KEY, $this->deleted_transients );
		self::assertContains( '2:' . LoginFailedThrottle::TRANSIENT_KEY, $this->deleted_transients );
	}

	public function test_la_purge_et_le_controle_de_reprise_sont_deplanifies(): void {
		$this->uninstall();

		self::assertContains( RestorePointPurgeJob::HOOK, $this->cleared_hooks );
		self::assertContains( RestorePointPurgeJob::RECOVERY_HOOK, $this->cleared_hooks );
	}

	public function test_la_trace_de_reprise_d_une_mise_a_jour_protegee_est_retiree(): void {
		$this->options[ UpdateTransaction::RECOVERY_TRACE_KEY ] = [
			'txn' => 'x|a/a.php|1',
			'at'  => 1,
		];

		$this->uninstall();

		self::assertArrayNotHasKey( UpdateTransaction::RECOVERY_TRACE_KEY, $this->options );
	}

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'g2rd-connector/g2rd-connector.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
	}
}
