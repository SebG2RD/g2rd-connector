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
use PHPUnit\Framework\Attributes\DataProvider;

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

	/**
	 * `detail` d'une mise à jour en échec : texte de la WP_Error de Core_Upgrader (une extension branchée
	 * sur `upgrader_pre_download` ou `upgrader_source_selection` peut l'écrire), filtré comme les autres
	 * erreurs tierces ; sans message, son code. Le manager ne le lit pas : il le garde dans le résultat.
	 *
	 * @return iterable<string, array{\WP_Error, string, list<string>}>
	 */
	public static function erreurs_du_coeur(): iterable {
		yield 'doublon, chemin du serveur' => [
			new \WP_Error( 'copy_failed', "Duplicate entry '0612345678' for key 'tel' in /home/u123456789/domains/exemple.fr/public_html/wp-content/upgrade/x.php " . str_repeat( 'détail ', 2000 ) ),
			'Duplicate entry [sql]',
			[ '0612345678', '/home/', 'u123456789' ],
		];
		yield 'sans message' => [ new \WP_Error( 'download_failed', '' ), 'download_failed', [ '[unreadable]' ] ];
	}

	/**
	 * @param list<string> $absent
	 */
	#[DataProvider( 'erreurs_du_coeur' )]
	public function test_update_core_le_detail_d_un_echec_est_filtre( \WP_Error $error, string $start, array $absent ): void {
		$this->loaded_version       = '7.1.4';
		$this->install( '7.1.4' );
		Core_Upgrader::$next_result = $error;

		$outcome = CommandExecutor::run( 'update_core', [] );

		self::assertSame( 'done', $outcome['status'] );
		self::assertFalse( $outcome['result']['updated'] );
		self::assertStringStartsWith( $start, $outcome['result']['detail'] );
		foreach ( $absent as $text ) {
			self::assertStringNotContainsString( $text, $outcome['result']['detail'] );
		}
		self::assertLessThanOrEqual( 500, mb_strlen( $outcome['result']['detail'] ) );
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

	/**
	 * Rien à protéger (version de développement, offre `latest`) : chemin historique ; une exception
	 * levée après la copie remonte comme sans `snapshot`, jamais avalée en `upgrade_error` sans
	 * contrôle de santé.
	 *
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function rien_a_proteger(): iterable {
		yield 'version de développement' => [ '7.2-beta1', 'upgrade', '7.1.5' ];
		yield 'offre latest' => [ '7.1.4', 'latest', '7.1.4' ];
	}

	#[DataProvider( 'rien_a_proteger' )]
	public function test_update_core_rien_a_proteger_l_exception_apres_la_copie_remonte( string $loaded, string $response, string $offered ): void {
		$this->loaded_version = $loaded;
		$this->install( '7.1.4' );
		Functions\when( 'get_core_updates' )->justReturn(
			[
				(object) [
					'response' => $response,
					'version'  => $offered,
					'locale'   => 'en_US',
				],
			]
		);
		$this->upgrade_throws( true, $offered );

		$outcome = CommandExecutor::run( 'update_core', [ 'snapshot' => true ] );

		self::assertSame( 'failed', $outcome['status'] );
		self::assertSame( 'Fatal in upgrader_process_complete', $outcome['error'] );
		self::assertArrayNotHasKey( 'result', $outcome );
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
