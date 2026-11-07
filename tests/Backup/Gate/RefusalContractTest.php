<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\CommandRuns\Refusal;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use WP_REST_Request;

/**
 * Le connecteur produit exactement les corps du fichier de contrat partagé avec la plateforme
 * (copie à l'octet : backend/tests/fixtures/backup-refusal-v1.json, comparée par cmp).
 */
final class RefusalContractTest extends BackupTestCase {

	/** @var array<string, mixed> */
	private array $contrat;

	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier de contrat local du dépôt.
		$this->contrat = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/backup-refusal-v1.json' ), true, 16, JSON_THROW_ON_ERROR );
	}

	public function test_version_codes_et_bornes(): void {
		self::assertSame( 1, $this->contrat['version'] );
		self::assertSame( [ BackupRefused::SLICE_RUNNING, BackupRefused::UPDATE_IN_PROGRESS, BackupRefused::LOCK_UNAVAILABLE ], $this->contrat['codes'] );
		self::assertSame(
			[
				'min' => 1,
				'max' => BackupRefused::MAX_RETRY_AFTER,
			],
			$this->contrat['retry_after']
		);
	}

	public function test_refus_du_dump(): void {
		$codes = [ BackupRefused::DB_CHARSET_MISMATCH, BackupRefused::DB_NAME_UNSUPPORTED, BackupRefused::DB_TABLES_NOT_FOUND, BackupRefused::DB_CURSOR_INVALID ];

		self::assertSame( $codes, $this->contrat['dump_refusal']['codes'] );
		self::assertFalse( $this->contrat['dump_refusal']['retryable'] );
		foreach ( $codes as $code ) {
			$refus = new BackupRefused( $code );
			self::assertNull( $refus->retry_after(), $code . ' : non réessayable' );
			self::assertArrayNotHasKey( 'retry_after', $refus->outcome(), $code . ' : sans retry_after' );
			self::assertSame( $code, $refus->outcome()['error_code'] );
		}
	}

	public function test_corps_de_command(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::DB ] );
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;
		$requete = new WP_REST_Request( 'POST', '/g2rd/v1/command' );
		$requete->set_param( 'command', 'update_plugin' );

		$reponse = ( new CommandController() )->handle( $requete );
		$attendu = $this->contrat['command_failed'];

		self::assertSame( $attendu['http_status'], $reponse->get_status() );
		$this->assert_conforme( $attendu, $reponse->get_data() );
	}

	public function test_corps_de_la_route_en_arriere_plan(): void {
		$erreur  = Refusal::error( Refusal::BACKUP_SLICE_RUNNING, [ 'retry_after' => 17 ] );
		$attendu = $this->contrat['command_run_refused'];

		self::assertSame( $attendu['body']['code'], $erreur->get_error_code() );
		self::assertSame( $attendu['body']['data'], $erreur->get_error_data() );
		self::assertSame( $attendu['http_status'], $erreur->get_error_data()['status'] );
	}

	public function test_resultat_de_la_file(): void {
		$attendu = $this->contrat['queue_result']['body']['result'];

		self::assertSame( $attendu, HeartbeatJob::result_of( ( new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS ) )->outcome() ) );
	}

	public function test_etape_d_un_autotest_en_echec(): void {
		self::assertSame( BackupRefused::STAGES, $this->contrat['stage']['values'] );
		self::assertSame( BackupRefused::SELF_TEST_FAILED, $this->contrat['stage']['when_error_code'] );
		self::assertSame(
			[
				'error_code' => 'self_test_failed',
				'stage'      => 'index',
			],
			HeartbeatJob::result_of( ( new BackupRefused( BackupRefused::SELF_TEST_FAILED, null, '', 'index' ) )->outcome() )
		);
	}

	/**
	 * Mêmes clés ; mêmes valeurs hors des clés variables ; types du contrat.
	 *
	 * @param array<string, mixed> $attendu
	 * @param array<string, mixed> $corps
	 */
	private function assert_conforme( array $attendu, array $corps ): void {
		$cles = array_keys( $attendu['body'] );
		sort( $cles );
		$recues = array_keys( $corps );
		sort( $recues );
		self::assertSame( $cles, $recues );
		foreach ( $attendu['body'] as $cle => $valeur ) {
			if ( ! in_array( $cle, $attendu['variable_keys'], true ) ) {
				self::assertSame( $valeur, $corps[ $cle ], $cle );
			}
		}
		foreach ( $attendu['types'] as $cle => $type ) {
			self::assertSame( $type, gettype( $corps[ $cle ] ) === 'NULL' ? 'null' : gettype( $corps[ $cle ] ), $cle );
		}
	}
}
