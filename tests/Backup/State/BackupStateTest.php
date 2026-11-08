<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\State;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\CommandRuns\StoreUnavailable;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class BackupStateTest extends BackupTestCase {

	public function test_sans_option_l_etat_est_idle(): void {
		$charge = BackupState::load();

		self::assertNull( $charge['raw'] );
		self::assertSame( BackupState::IDLE, $charge['state']['phase'] );
		self::assertFalse( BackupState::is_active( $charge['state'] ) );
	}

	public function test_une_valeur_abimee_vaut_idle(): void {
		$this->wpdb->tables['wp_options'][ BackupState::OPTION ] = '{"phase":"nimporte"}';

		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	public function test_ecriture_puis_relecture_avec_revision(): void {
		$brut = BackupState::save( null, [ 'phase' => BackupState::DB, 'backup_id' => str_repeat( 'a', 32 ) ] );

		self::assertIsString( $brut );
		$relu = BackupState::load();
		self::assertSame( $brut, $relu['raw'] );
		self::assertSame( BackupState::DB, $relu['state']['phase'] );
		self::assertSame( 1, $relu['state']['rev'] );
		self::assertSame( 1, $relu['state']['v'] );
	}

	public function test_une_ecriture_d_un_autre_processus_fait_perdre_la_sienne(): void {
		$brut = (string) BackupState::save( null, [ 'phase' => BackupState::DB ] );
		$this->wpdb->before_write = function (): void {
			$this->put_state( [ 'phase' => BackupState::ABORT, 'rev' => 9 ] );
		};

		self::assertNull( BackupState::save( $brut, [ 'phase' => BackupState::FILES, 'rev' => 1 ] ) );
		self::assertSame( BackupState::ABORT, BackupState::load()['state']['phase'], 'la valeur de l\'autre processus reste' );
	}

	public function test_lecture_refusee_leve_et_peek_rend_null(): void {
		$this->wpdb->read_fails = true;

		self::assertNull( BackupState::peek() );
		$this->expectException( StoreUnavailable::class );
		BackupState::load();
	}

	public function test_un_wpdb_incomplet_rend_null_sans_avertissement(): void {
		$GLOBALS['wpdb'] = new \stdClass();
		self::assertNull( BackupState::peek(), 'objet sans get_var ni last_error : aucun avertissement (failOnWarning)' );

		unset( $GLOBALS['wpdb'] );
		self::assertNull( BackupState::peek(), '$wpdb absent' );
	}

	public function test_une_sauvegarde_partie_pendant_la_mise_a_jour_est_interrompue_meme_apres_la_copie(): void {
		$this->put_state( [ 'phase' => BackupState::UPLOAD, 'backup_id' => str_repeat( 'c', 32 ), 'created_at' => 1800000100 ] );

		self::assertFalse( BackupState::abort_if_started_since( 1800000200, 'interrupted_by_update', 1800000300 ), 'partie avant la mise à jour : gardée' );
		self::assertSame( BackupState::UPLOAD, BackupState::load()['state']['phase'] );
		self::assertTrue( BackupState::abort_if_started_since( 1800000050, 'interrupted_by_update', 1800000300 ) );
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::ABORT, 'interrupted_by_update', 1800000300 ], [ $etat['phase'], $etat['reason'], $etat['aborted_at'] ] );
	}

	public function test_seule_la_copie_est_interrompue(): void {
		$this->put_state( [ 'phase' => BackupState::FILES, 'backup_id' => str_repeat( 'b', 32 ) ] );

		self::assertTrue( BackupState::abort_if_capturing( 'interrupted_by_update', 1800000000 ) );
		$etat = BackupState::load()['state'];
		self::assertSame( BackupState::ABORT, $etat['phase'] );
		self::assertSame( 'interrupted_by_update', $etat['reason'] );
		self::assertSame( 1800000000, $etat['aborted_at'] );

		$this->put_state( [ 'phase' => BackupState::UPLOAD ] );
		self::assertFalse( BackupState::abort_if_capturing( 'interrupted_by_update', 1800000000 ), 'tout est copié : rien à interrompre' );
		self::assertSame( BackupState::UPLOAD, BackupState::load()['state']['phase'] );
	}

	public function test_un_seul_etat_en_multisite_quel_que_soit_le_blog(): void {
		$wpdb = $this->wpdb;
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'switch_to_blog' )->alias(
			static function ( int $blog ) use ( $wpdb ): bool {
				$wpdb->options = 1 === $blog ? 'wp_options' : 'wp_' . $blog . '_options';
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			static function () use ( $wpdb ): bool {
				$wpdb->options = 'wp_3_options';
				return true;
			}
		);
		$wpdb->options = 'wp_3_options';
		$this->put_state( [ 'phase' => BackupState::DB ] );

		self::assertSame( BackupState::DB, BackupState::load()['state']['phase'], 'lu dans la table du site principal' );
		self::assertNotNull( BackupState::save( BackupState::load()['raw'], [ 'phase' => BackupState::FILES ] ) );
		self::assertArrayNotHasKey( 'wp_3_options', $wpdb->tables, 'rien écrit dans la table du blog courant' );
		self::assertSame( 'wp_3_options', $wpdb->options, 'blog courant rétabli' );
	}
}
