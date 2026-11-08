<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Gate\UpdateGate;
use G2RD\Connector\Backup\Gate\UpdateWatcher;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class UpdateWatcherTest extends BackupTestCase {

	public function test_une_mise_a_jour_hors_plateforme_pendant_la_copie_l_interrompt(): void {
		$this->put_state( [ 'phase' => BackupState::DB ] );

		UpdateWatcher::on_upgrade_complete( new \stdClass(), [ 'type' => 'plugin' ] );

		self::assertSame( BackupState::ABORT, BackupState::load()['state']['phase'] );
		self::assertSame( 'interrupted_by_update', BackupState::load()['state']['reason'] );
	}

	public function test_sans_copie_en_cours_rien_n_est_ecrit(): void {
		UpdateWatcher::on_upgrade_complete();
		$this->put_state( [ 'phase' => BackupState::FINALIZE, 'created_at' => 1 ] );
		UpdateWatcher::on_upgrade_complete();

		self::assertSame( [], $this->wpdb->locks_and_writes() );
	}

	public function test_une_base_qui_refuse_ne_fait_jamais_echouer_la_mise_a_jour(): void {
		$this->wpdb->read_fails = true;

		self::assertSame( 'rien', UpdateWatcher::on_upgrade_start( 'rien' ) );
		UpdateWatcher::on_upgrade_complete();

		self::assertSame( [], $this->wpdb->locks_and_writes() );
	}

	public function test_sauvegardes_eteintes_deux_lectures_et_rien_d_ecrit(): void {
		self::assertFalse( UpdateWatcher::on_upgrade_start( false ), 'valeur du filtre rendue telle quelle' );
		UpdateWatcher::on_upgrade_complete();

		self::assertSame( [], $this->wpdb->locks_and_writes(), 'ni verrou, ni témoin, ni écriture' );
		self::assertCount( 2, $this->wpdb->queries, 'une lecture au début, une à la fin' );
	}

	public function test_sauvegardes_allumees_le_debut_interrompt_la_copie_et_pose_un_temoin_sans_verrou(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::FILES, 'backup_id' => str_repeat( 'a', 32 ), 'created_at' => time() - 100 ] );

		UpdateWatcher::on_upgrade_start( false );

		self::assertSame( BackupState::ABORT, BackupState::load()['state']['phase'], 'copie interrompue dès le début' );
		$temoin = UpdateWitness::load()['entries'];
		self::assertCount( 1, $temoin );
		self::assertFalse( $temoin[0]['held'] );
		self::assertEqualsWithDelta( time() + UpdateWitness::UNLOCKED_TTL_SECONDS, $temoin[0]['until'], 2, 'échéance si la fin manque' );

		UpdateWatcher::on_upgrade_complete();

		self::assertSame( [], UpdateWitness::load()['entries'], 'entrée retirée à la fin' );
	}

	public function test_une_sauvegarde_partie_pendant_la_mise_a_jour_est_interrompue_meme_en_envoi(): void {
		UpdateWatcher::on_upgrade_start( false );
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::UPLOAD, 'backup_id' => str_repeat( 'c', 32 ), 'created_at' => time() ] );

		UpdateWatcher::on_upgrade_complete();

		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::ABORT, 'interrupted_by_update' ], [ $etat['phase'], $etat['reason'] ], 'commencée pendant la mise à jour : jamais gardée (E17)' );
	}

	public function test_une_sauvegarde_plus_ancienne_que_la_mise_a_jour_continue_en_envoi(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::UPLOAD, 'backup_id' => str_repeat( 'd', 32 ), 'created_at' => time() - 3600 ] );

		UpdateWatcher::on_upgrade_start( false );
		UpdateWatcher::on_upgrade_complete();

		self::assertSame( BackupState::UPLOAD, BackupState::load()['state']['phase'], 'tout était copié avant la mise à jour (choix n° 3)' );
	}

	public function test_une_commande_passee_par_la_porte_ne_relit_rien_au_debut(): void {
		( new \ReflectionProperty( UpdateGate::class, 'transparent' ) )->setValue( null, true );

		UpdateWatcher::on_upgrade_start( false );

		self::assertSame( [], $this->wpdb->queries, 'la porte a déjà lu l\'état' );
	}

	public function test_les_crochets_sont_branches_par_le_plugin(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Plugin.php' );

		self::assertStringContainsString( "add_filter( 'upgrader_pre_download', [ UpdateWatcher::class, 'on_upgrade_start' ], 1, 1 );", $source );
		self::assertStringContainsString( "add_action( 'upgrader_process_complete', [ UpdateWatcher::class, 'on_upgrade_complete' ], 10, 2 );", $source );
	}
}
