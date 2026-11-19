<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Brain\Monkey\Functions;
use Core_Upgrader;
use G2RD\Connector\Backup\Gate\UpdateGate;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Rollback\Core\CoreRollbackCommand;
use G2RD\Connector\Rollback\Core\ProtectedCoreUpdate;
use G2RD\Connector\Rollback\PluginRestorer;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Services;
use G2RD\Connector\Rollback\Snapshotter;

/**
 * `rollback_core` est une commande du site, signée, admise en arrière-plan, derrière la porte des
 * mises à jour (une sauvegarde lui cède la place) ; `update_core` garde sa forme historique sans
 * `snapshot` ; la capacité `core_rollback` suit `restore_points`.
 */
final class CoreRollbackWiringTest extends CoreRollbackTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_core_updates' )->justReturn(
			[
				(object) [
					'response' => 'upgrade',
					'version'  => '7.1.5',
					'locale'   => 'en_US',
				],
			]
		);
		$store = new RestorePointStore();
		Services::override( new Services( $store, new Snapshotter( $store, WP_PLUGIN_DIR ), new PluginRestorer( WP_PLUGIN_DIR ), $this->health, WP_PLUGIN_DIR ) );
	}

	protected function tearDown(): void {
		Services::override( null );
		parent::tearDown();
	}

	public function test_commande_signee_admise_en_arriere_plan_et_derriere_la_porte(): void {
		self::assertSame( 'rollback_core', CoreRollbackCommand::NAME );
		self::assertContains( 'rollback_core', CommandExecutor::ALLOWED );
		self::assertContains( 'rollback_core', CommandExecutor::SIGNED_ONLY );
		self::assertContains( 'rollback_core', AsyncCommands::ADMITTED );
		self::assertNotContains( 'rollback_core', AsyncCommands::EXCLUDED );
		self::assertTrue( AsyncCommands::is_admitted( 'rollback_core', false ) );
		self::assertTrue( UpdateGate::applies_to( 'rollback_core' ) );
	}

	public function test_capacite_annoncee_avec_les_points_de_restauration(): void {
		$capabilities = ( new SnapshotController() )->capabilities();
		$restore      = array_search( 'restore_points', $capabilities, true );

		self::assertIsInt( $restore );
		self::assertSame( 'core_rollback', $capabilities[ $restore + 1 ] ?? null );

		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );
		self::assertNotContains( 'core_rollback', ( new SnapshotController() )->capabilities() );
	}

	public function test_update_core_sans_snapshot_garde_la_forme_historique(): void {
		$this->loaded_version = '7.1.4';
		$this->install( '7.1.4' );

		$outcome = CommandExecutor::run( 'update_core', [] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertSame(
			[
				'updated'        => true,
				'version_before' => '7.1.4',
				'version_after'  => '7.1.5',
				'detail'         => '7.1.5',
			],
			$outcome['result']
		);
		self::assertCount( 1, Core_Upgrader::$calls );
	}

	public function test_update_core_protegee_par_la_commande(): void {
		$this->loaded_version = '7.1.4';
		$this->install( '7.1.4' );

		$outcome = CommandExecutor::run(
			'update_core',
			[
				'snapshot'        => true,
				'keep_on_success' => true,
				'grace_seconds'   => 3600,
			]
		);

		self::assertSame( 'done', $outcome['status'] );
		self::assertTrue( $outcome['result']['protected'] );
		self::assertSame( 'healthy', $outcome['result']['health']['verdict'] );
		self::assertIsInt( $outcome['result']['restore_until'] );
		self::assertArrayNotHasKey( 'outcome', $outcome['result'] );
	}

	public function test_rollback_core_par_la_commande(): void {
		$this->options[ ProtectedCoreUpdate::RECORD_OPTION ] = [
			'version_before' => '7.1.4',
			'version_after'  => '7.1.5',
			'locale'         => 'en_US',
			'db_version'     => 61833,
			'until'          => time() + 3600,
		];

		$outcome = CommandExecutor::run(
			'rollback_core',
			[
				'expected_current' => '7.1.5',
				'target'           => '7.1.4',
			]
		);

		self::assertSame( 'done', $outcome['status'] );
		self::assertSame( 'rollback_success', $outcome['result']['outcome'] );
		self::assertSame( '7.1.4', $this->version_on_disk() );
	}
}
