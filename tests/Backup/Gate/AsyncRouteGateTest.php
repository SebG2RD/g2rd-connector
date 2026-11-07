<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Gate\UpdateGate;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\Refusal;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rest\CommandRunsController;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AsyncRouteGateTest extends BackupTestCase {

	private const NOW    = 1788234400;
	private const NUMERO = '0123456789abcdef0123456789abcdef';
	private const CORPS  = '{"command_id":"' . self::NUMERO . '","command":"update_plugin","payload":{"file":"akismet/akismet.php"}}';

	/** @var list<array{0: string, 1: string, 2: array<mixed>|null}> */
	private array $programmees = [];

	protected function setUp(): void {
		parent::setUp();
		$this->programmees = [];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'        => 7,
			'site_token'     => 'jeton-du-site-0123456789',
			'backup_enabled' => true,
		];
		ignore_user_abort( false );
	}

	protected function tearDown(): void {
		ignore_user_abort( false );
		parent::tearDown();
	}

	public function test_mise_a_jour_pendant_une_tranche_sans_creneau_refus_409_creneau_rendu(): void {
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;

		$reponse = $this->controleur()->accept( $this->requete() );

		self::assertInstanceOf( WP_Error::class, $reponse );
		self::assertSame( 'g2rd_connector_backup_slice_running', $reponse->get_error_code() );
		self::assertSame( 409, $reponse->get_error_data()['status'] );
		self::assertIsInt( $reponse->get_error_data()['retry_after'] );
		self::assertNull( ExecutionSlot::holder(), 'créneau rendu' );
		self::assertSame( [], $this->programmees, 'rien de programmé' );
		self::assertNull( RunStore::read( self::NUMERO ), 'rien d\'enregistré' );
	}

	public function test_le_passage_ouvert_a_l_acceptation_est_ferme_par_le_nettoyage(): void {
		$reponse = $this->controleur()->accept( $this->requete() );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 202, $reponse->get_status() );
		self::assertTrue( UpdateGate::holding() );
		self::assertSame( [ $this->lock_name() => 1 ], $this->wpdb->held_here );
		self::assertCount( 1, UpdateWitness::load()['entries'] );

		$nettoyage = new \ReflectionMethod( AfterResponseRunner::class, 'clean_up' );
		$nettoyage->invoke( null, self::NUMERO );

		self::assertFalse( UpdateGate::holding() );
		self::assertSame( [], $this->wpdb->held_here, 'verrou rendu par clean_up()' );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_sauvegardes_eteintes_aucune_requete_de_verrou(): void {
		$this->options[ Settings::OPTION_KEY ]['backup_enabled'] = false;

		$reponse = $this->controleur()->accept( $this->requete() );

		self::assertSame( 202, $reponse->get_status() );
		self::assertSame( [], preg_grep( '/GET_LOCK/', $this->wpdb->queries ) );
		self::assertFalse( UpdateGate::holding() );
	}

	public function test_enregistrement_perdu_la_porte_est_rendue(): void {
		$this->wpdb->insert_fails_for = RunStore::OPTION_PREFIX . self::NUMERO;

		$this->controleur()->accept( $this->requete() );

		self::assertFalse( UpdateGate::holding() );
		self::assertSame( [], $this->wpdb->held_here );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_le_compte_rendu_garde_code_et_delai(): void {
		$coureur = new AfterResponseRunner( null, null, static fn (): int => self::NOW );
		$outcome = ( new \ReflectionMethod( AfterResponseRunner::class, 'outcome' ) )->invoke(
			$coureur,
			'backup_step',
			[
				'status'      => 'failed',
				'error'       => 'phrase',
				'error_code'  => 'update_in_progress',
				'retry_after' => 60,
			]
		);

		self::assertSame( 'update_in_progress', $outcome['error_code'] );
		self::assertSame( 60, $outcome['retry_after'] );
	}

	public function test_refus_409_ne_rend_que_statut_et_delai(): void {
		$erreur = Refusal::error(
			Refusal::BACKUP_SLICE_RUNNING,
			[
				'retry_after' => 17,
				'busy_with'   => self::NUMERO,
			]
		);

		self::assertSame(
			[
				'status'      => 409,
				'retry_after' => 17,
			],
			$erreur->get_error_data()
		);
		self::assertSame( [ 'status' => 409 ], Refusal::error( Refusal::BACKUP_SLICE_RUNNING, [ 'retry_after' => 0 ] )->get_error_data(), 'délai hors bornes écarté' );
	}

	private function controleur(): CommandRunsController {
		return new CommandRunsController(
			function ( string $command_id, string $command, ?array $payload ): void {
				$this->programmees[] = [ $command_id, $command, $payload ];
			},
			static fn (): int => self::NOW,
			static function ( int $microsecondes ): void {
				unset( $microsecondes );
			}
		);
	}

	private function requete(): WP_REST_Request {
		$requete = new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' );
		$requete->set_header( 'Authorization', 'Bearer jeton-du-site-0123456789' );
		$requete->set_body( self::CORPS );
		return $requete;
	}
}
