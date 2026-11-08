<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Commands;

use G2RD\Connector\Backup\Commands\BackupCommands;
use G2RD\Connector\Backup\Run\BackupJob;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class BackupCommandsTest extends BackupTestCase {

	public function test_quatre_commandes_signees_admises_en_arriere_plan(): void {
		foreach ( BackupCommands::NAMES as $commande ) {
			self::assertContains( $commande, CommandExecutor::ALLOWED );
			self::assertContains( $commande, CommandExecutor::SIGNED_ONLY );
			self::assertContains( $commande, AsyncCommands::ADMITTED );
		}
	}

	public function test_sauvegardes_eteintes_refus_a_code_stable_sous_verrou(): void {
		$issue = CommandExecutor::run( 'backup_start', [ 'backup_id' => str_repeat( 'a', 32 ) ] );

		self::assertSame( 'failed', $issue['status'] ?? null );
		self::assertSame( 'backup_disabled', $issue['error_code'] ?? null );
		self::assertArrayNotHasKey( 'retry_after', (array) $issue );
		self::assertSame( [], $this->wpdb->held_here, 'verrou rendu' );
	}

	public function test_pendant_une_tranche_refus_reessayable(): void {
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;

		$issue = CommandExecutor::run( 'backup_step', [ 'backup_id' => str_repeat( 'a', 32 ) ] );

		self::assertSame( 'backup_slice_running', $issue['error_code'] ?? null );
		self::assertIsInt( $issue['retry_after'] ?? null );
	}

	public function test_la_file_refuse_les_commandes_de_sauvegarde(): void {
		$issue = HeartbeatJob::queue_outcome( 'backup_step', [ 'backup_id' => str_repeat( 'a', 32 ) ] );

		self::assertSame( 'backup_queue_unsupported', $issue['error_code'] ?? null );
		self::assertSame( [], $this->wpdb->queries, 'rien lu, rien verrouillé' );
		self::assertSame( [ 'error_code' => 'backup_queue_unsupported' ], HeartbeatJob::result_of( (array) $issue ) );
	}

	/**
	 * Le bloc `backup` du snapshot (Snapshot\BackupBlock) n'existe qu'à la tâche 31, qui en vérifie
	 * les clés sur son propre contrat (snapshot-backup-v1.json) : ce test-ci vérifie les commandes et
	 * les limites que la plateforme lit dans backup-commands-v1.json.
	 */
	public function test_le_contrat_des_commandes_est_celui_du_fichier(): void {
		$contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/backup-commands-v1.json' ), true, 16, JSON_THROW_ON_ERROR );

		self::assertSame( BackupCommands::NAMES, $contrat['commands'] );
		self::assertSame(
			[ BackupJob::MAX_PENDING, BackupJob::MAX_PARTS_PER_STEP, BackupJob::INDEX_CHUNK_BYTES, BackupJob::REPORT_MAX_BYTES ],
			[ $contrat['limits']['max_pending'], $contrat['limits']['max_parts_per_step'], $contrat['limits']['index_chunk_bytes'], $contrat['limits']['report_max_bytes'] ]
		);
	}
}
