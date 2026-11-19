<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\ReleaseNotes\ReleaseNotesCommand;
use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Rest\CommandController;
use WP_REST_Request;

/**
 * `get_release_notes` est une commande du site, signée, instantanée : exécutée par `/command`,
 * refusée par la file du battement (sans rien lire) et par la route en arrière-plan.
 */
final class ReleaseNotesWiringTest extends ReleaseNotesTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->transients['update_plugins'] = self::update_plugins();
		// Aucune vérification de signature de la requête précédente : le corps n'a pas de signature_check.
		( new \ReflectionProperty( Auth::class, 'last_signature_check' ) )->setValue( null, null );
	}

	public function test_commande_du_site_signee_et_instantanee(): void {
		self::assertSame( 'get_release_notes', ReleaseNotesCommand::NAME );
		self::assertContains( ReleaseNotesCommand::NAME, CommandExecutor::ALLOWED );
		self::assertContains( ReleaseNotesCommand::NAME, CommandExecutor::SIGNED_ONLY );
		self::assertContains( ReleaseNotesCommand::NAME, AsyncCommands::EXCLUDED );
		self::assertNotContains( ReleaseNotesCommand::NAME, AsyncCommands::ADMITTED );
		self::assertFalse( AsyncCommands::is_admitted( ReleaseNotesCommand::NAME, true ) );
	}

	public function test_command_rend_les_notes(): void {
		$this->vendor_answer = self::acf_answer();

		$reponse = ( new CommandController() )->handle(
			$this->requete(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			)
		);
		$corps   = $reponse->get_data();

		self::assertSame( 200, $reponse->get_status() );
		self::assertSame( 'done', $corps['status'] );
		self::assertSame( '6.8.11', $corps['result']['version'] );
		self::assertNull( $corps['error'] );
		self::assertArrayNotHasKey( 'error_code', $corps );
	}

	public function test_command_un_refus_porte_son_code(): void {
		$reponse = ( new CommandController() )->handle(
			$this->requete(
				[
					'kind' => 'plugin',
					'file' => '../../wp-config.php',
				]
			)
		);

		self::assertSame( 500, $reponse->get_status() );
		self::assertSame( 'release_notes_not_installed', $reponse->get_data()['error_code'] );
		self::assertSame( [], $this->api_calls );
	}

	public function test_la_file_refuse_sans_rien_lire(): void {
		$issue = HeartbeatJob::queue_outcome(
			ReleaseNotesCommand::NAME,
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);

		self::assertSame( 'failed', $issue['status'] ?? null );
		self::assertSame( ReleaseNotesRefused::QUEUE_UNSUPPORTED, $issue['error_code'] ?? null );
		self::assertSame( [ 'error_code' => ReleaseNotesRefused::QUEUE_UNSUPPORTED ], HeartbeatJob::result_of( (array) $issue ) );
		self::assertSame( [], $this->api_calls );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function requete( array $payload ): WP_REST_Request {
		$requete = new WP_REST_Request( 'POST', '/g2rd/v1/command' );
		$requete->set_param( 'command', ReleaseNotesCommand::NAME );
		$requete->set_param( 'payload', $payload );

		return $requete;
	}
}
