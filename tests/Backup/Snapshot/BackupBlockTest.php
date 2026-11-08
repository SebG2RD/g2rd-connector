<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Snapshot;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Snapshot\BackupBlock;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class BackupBlockTest extends BackupTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'add_option' )->alias(
			function ( string $nom, $valeur ): bool {
				$this->options[ $nom ] = $valeur;
				return true;
			}
		);
	}

	public function test_eteint_rien_n_est_cree_et_les_cles_sont_celles_du_contrat(): void {
		$bloc    = BackupBlock::build( 1800000000 );
		$contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-backup-v1.json' ), true, 16, JSON_THROW_ON_ERROR );

		self::assertSame( array_keys( $contrat['example'] ), array_keys( $bloc ) );
		self::assertSame( array_keys( $contrat['example']['state'] ), array_keys( $bloc['state'] ) );
		self::assertFalse( $bloc['enabled'] );
		self::assertNull( $bloc['signing_public_key'] );
		self::assertArrayNotHasKey( SigningKey::OPTION, $this->options, 'aucune clé créée case décochée' );
	}

	public function test_allume_la_cle_de_signature_est_creee_et_son_empreinte_rendue(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::FILES, 'backup_id' => str_repeat( 'a', 32 ), 'segments' => 3, 'pending' => [ [], [] ] ] );

		$bloc = BackupBlock::build( 1800000000 );

		self::assertTrue( $bloc['enabled'] );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', (string) $bloc['signing_public_key'] );
		self::assertSame( substr( hash( 'sha256', (string) hex2bin( (string) $bloc['signing_public_key'] ) ), 0, 32 ), $bloc['fingerprint'] );
		self::assertSame( [ 'files', 3, 2 ], [ $bloc['state']['phase'], $bloc['state']['segments_written'], $bloc['state']['pending'] ] );
	}

	public function test_capacite_annoncee_seulement_allumee(): void {
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );
		self::assertNotContains( BackupSwitch::CAPABILITY, ( new SnapshotController() )->capabilities( static fn (): bool => false ) );
		$this->settings( [ 'backup_enabled' => true ] );
		self::assertContains( BackupSwitch::CAPABILITY, ( new SnapshotController() )->capabilities( static fn (): bool => false ) );
	}

	/**
	 * Site secondaire d'un réseau, case cochée : son snapshot ne crée pas la clé de signature unique
	 * du réseau et ne remonte ni l'état ni la dernière issue des sauvegardes du réseau. Processus
	 * séparé, car le test simule is_main_site() (cf. BackupSwitchTest).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_secondaire_bloc_au_repos_sans_cle_creee_ni_etat_du_reseau(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( false );
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );
		$this->settings(
			[
				'backup_enabled'          => true,
				'remote_commands_enabled' => true,
			]
		);
		$this->put_state(
			[
				'phase'     => BackupState::UPLOAD,
				'backup_id' => str_repeat( 'c', 32 ),
				'segments'  => 4,
				'last'      => [ 'backup_id' => str_repeat( 'd', 32 ), 'outcome' => 'done', 'at' => 1800000000, 'totals' => [ 'segments' => 9 ] ],
			]
		);
		$contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-backup-v1.json' ), true, 16, JSON_THROW_ON_ERROR );

		$bloc = BackupBlock::build( 1800000000 );

		self::assertSame( array_keys( $contrat['example'] ), array_keys( $bloc ) );
		self::assertSame( [ false, false, null, null, null, null ], [ $bloc['enabled'], $bloc['available'], $bloc['signing_public_key'], $bloc['signing_key_id'], $bloc['fingerprint'], $bloc['site_key'] ] );
		self::assertSame( [ 'idle', null, 0, 0 ], [ $bloc['state']['phase'], $bloc['state']['backup_id'], $bloc['state']['segments_written'], $bloc['state']['pending'] ] );
		self::assertNull( $bloc['last'], 'aucune issue des sauvegardes du réseau' );
		self::assertSame( ( new Sodium() )->backend(), $bloc['engine'], 'le moteur de ce serveur reste vrai' );
		self::assertArrayNotHasKey( SigningKey::OPTION, $this->options, 'aucune clé de signature créée depuis un site secondaire' );
		self::assertNotContains( BackupSwitch::CAPABILITY, ( new SnapshotController() )->capabilities( static fn (): bool => false ) );
	}

	public function test_une_lecture_qui_leve_rend_les_valeurs_de_repos(): void {
		Functions\when( 'get_option' )->alias(
			static function (): never {
				throw new \RuntimeException( 'base indisponible' );
			}
		);
		$contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-backup-v1.json' ), true, 16, JSON_THROW_ON_ERROR );

		$bloc = BackupBlock::build( 1800000000 );

		self::assertSame( array_keys( $contrat['example'] ), array_keys( $bloc ), 'le snapshot reste entier : seul le bloc backup prend ses valeurs de repos' );
		self::assertSame( [ false, null, null, 'idle' ], [ $bloc['enabled'], $bloc['signing_public_key'], $bloc['last'], $bloc['state']['phase'] ] );
	}

	public function test_la_derniere_issue_porte_son_etape(): void {
		$this->put_state( [ 'phase' => BackupState::IDLE, 'last' => [ 'backup_id' => str_repeat( 'e', 32 ), 'outcome' => 'failed', 'code' => 'self_test_failed', 'stage' => 'index', 'at' => 1800000000, 'totals' => null ] ] );
		$contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/snapshot-backup-v1.json' ), true, 16, JSON_THROW_ON_ERROR );

		$last = BackupBlock::build( 1800000000 )['last'];

		self::assertSame( array_keys( $contrat['example']['last'] ), array_keys( (array) $last ) );
		self::assertSame( [ 'self_test_failed', 'index' ], [ $last['code'], $last['stage'] ] );
	}

	public function test_les_deux_cases_sont_eteintes_par_defaut_et_enregistrables(): void {
		self::assertFalse( Settings::defaults()['backup_enabled'] );
		self::assertFalse( Settings::defaults()['force_ipv4_to_storage'] );
		self::assertTrue( Settings::sanitize( [ 'backup_enabled' => true, 'force_ipv4_to_storage' => true ] )['backup_enabled'] );
	}
}
