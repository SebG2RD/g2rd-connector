<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use WP_REST_Request;

final class CommandRouteGateTest extends BackupTestCase {

	public function test_command_refus_de_tranche_porte_code_et_delai(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state(
			[
				'phase' => BackupState::DB,
				'slice' => [
					'started_at' => time(),
					'budget'     => 20,
				],
			]
		);
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;

		$reponse = ( new CommandController() )->handle( $this->requete( 'update_core' ) );

		self::assertSame( 500, $reponse->get_status() );
		$corps = $reponse->get_data();
		self::assertSame( 'failed', $corps['status'] );
		self::assertSame( 'backup_slice_running', $corps['error_code'] );
		self::assertIsInt( $corps['retry_after'] );
		self::assertGreaterThanOrEqual( 1, $corps['retry_after'] );
		self::assertLessThanOrEqual( 60, $corps['retry_after'] );
		self::assertStringContainsString( ' Action : ', $corps['error'] );
	}

	public function test_command_un_echec_ordinaire_garde_exactement_sa_forme(): void {
		$reponse = ( new CommandController() )->handle( $this->requete( 'update_plugin' ) );

		self::assertSame( 500, $reponse->get_status() );
		self::assertSame( [ 'command', 'status', 'result', 'error', 'at' ], array_keys( $reponse->get_data() ) );
		self::assertSame( 'payload.file required', $reponse->get_data()['error'] );
	}

	public function test_la_file_recopie_le_code_et_le_delai_dans_le_resultat(): void {
		self::assertSame(
			[
				'error_code'  => 'update_in_progress',
				'retry_after' => 60,
			],
			HeartbeatJob::result_of(
				[
					'status'      => 'failed',
					'error'       => 'phrase',
					'error_code'  => 'update_in_progress',
					'retry_after' => 60,
				]
			)
		);
		self::assertSame( [ 'a' => 1 ], HeartbeatJob::result_of( [ 'status' => 'done', 'result' => [ 'a' => 1 ] ] ) );
		self::assertNull( HeartbeatJob::result_of( [ 'status' => 'failed', 'error' => 'phrase' ] ), 'comportement d\'aujourd\'hui' );
		self::assertNull( HeartbeatJob::result_of( [ 'status' => 'failed', 'error' => 'x', 'error_code' => 'Pas Un Code' ] ) );
		self::assertSame(
			[
				'error_code' => 'self_test_failed',
				'stage'      => 'index',
			],
			HeartbeatJob::result_of(
				[
					'status'     => 'failed',
					'error'      => 'phrase',
					'error_code' => 'self_test_failed',
					'stage'      => 'index',
				]
			),
			'étape d\'un autotest en échec recopiée'
		);
	}

	public function test_les_champs_d_un_refus_recopies_dans_la_reponse(): void {
		self::assertSame(
			[
				'error_code' => 'self_test_failed',
				'stage'      => 'index',
			],
			CommandController::refusal_fields( [ 'status' => 'failed', 'error' => 'x', 'error_code' => 'self_test_failed', 'stage' => 'index' ] )
		);
		self::assertSame( [], CommandController::refusal_fields( [ 'status' => 'failed', 'error' => 'x' ] ), 'échec ordinaire : rien' );
		self::assertSame(
			[
				'error_code'  => 'backup_slice_running',
				'retry_after' => 17,
			],
			CommandController::refusal_fields( [ 'error_code' => 'backup_slice_running', 'retry_after' => 17, 'stage' => 'Pas Bon' ] ),
			'étape mal formée : ignorée'
		);
	}

	private function requete( string $commande ): WP_REST_Request {
		$requete = new WP_REST_Request( 'POST', '/g2rd/v1/command' );
		$requete->set_param( 'command', $commande );
		return $requete;
	}
}
