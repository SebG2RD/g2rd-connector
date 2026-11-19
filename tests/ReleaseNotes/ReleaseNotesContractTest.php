<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\CommandRuns\Refusal;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\ReleaseNotes\HttpLimits;
use G2RD\Connector\ReleaseNotes\NotesAnswer;
use G2RD\Connector\ReleaseNotes\ReleaseNotesCommand;
use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Updates\UpdateSource;
use WP_REST_Request;

/**
 * Le connecteur produit exactement les corps du fichier de contrat partagé avec la plateforme (copie
 * à l'octet : backend/tests/fixtures/release-notes-command-v1.json du manager, comparée par cmp).
 */
final class ReleaseNotesContractTest extends ReleaseNotesTestCase {

	private const FIXTURE = __DIR__ . '/../fixtures/release-notes-command-v1.json';

	/** @var array<string, mixed> */
	private array $contrat;

	protected function setUp(): void {
		parent::setUp();
		$this->transients['update_plugins'] = self::update_plugins();
		( new \ReflectionProperty( Auth::class, 'last_signature_check' ) )->setValue( null, null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier de contrat local du dépôt.
		$this->contrat = json_decode( (string) file_get_contents( self::FIXTURE ), true, 16, JSON_THROW_ON_ERROR );
	}

	public function test_fichier_ecrit_comme_dans_le_manager(): void {
		$brut = (string) file_get_contents( self::FIXTURE );

		self::assertStringNotContainsString( "\r", $brut, 'Fins de ligne LF.' );
		self::assertStringStartsNotWith( "\xEF\xBB\xBF", $brut, 'Sans BOM.' );
		self::assertStringEndsWith( "}\n", $brut, 'Saut de ligne final.' );
	}

	public function test_commande_capacite_et_signature(): void {
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );

		self::assertSame( 1, $this->contrat['version'] );
		self::assertSame( ReleaseNotesCommand::NAME, $this->contrat['command'] );
		self::assertSame( ReleaseNotesCommand::CAPABILITY, $this->contrat['capability'] );
		self::assertTrue( $this->contrat['signed_only'] );
		self::assertContains( ReleaseNotesCommand::NAME, CommandExecutor::SIGNED_ONLY );
		self::assertContains( ReleaseNotesCommand::CAPABILITY, ( new SnapshotController() )->capabilities( static fn (): bool => false ) );
	}

	public function test_charges_bornes_provenance_et_codes(): void {
		self::assertSame(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			],
			$this->contrat['payload']['plugin']
		);
		self::assertSame(
			[
				'kind'       => 'theme',
				'stylesheet' => 'premium-pro',
			],
			$this->contrat['payload']['theme']
		);
		self::assertSame(
			[
				'notes_max_bytes'         => NotesAnswer::NOTES_MAX_BYTES,
				'http_budget_seconds'     => HttpLimits::BUDGET_SECONDS,
				'http_response_max_bytes' => HttpLimits::RESPONSE_MAX_BYTES,
				'http_max_redirects'      => HttpLimits::MAX_REDIRECTS,
				'homepage_scheme'         => 'https',
			],
			$this->contrat['limits']
		);
		self::assertSame( [ UpdateSource::WPORG, UpdateSource::VENDOR, null ], $this->contrat['update_source']['values'] );
		self::assertSame( ReleaseNotesRefused::CODES, $this->contrat['codes'] );
	}

	public function test_corps_des_notes(): void {
		$this->vendor_answer = self::acf_answer();
		$attendu             = $this->contrat['command_done'];

		$reponse = ( new CommandController() )->handle( $this->requete( $this->contrat['payload']['plugin'] ) );

		self::assertSame( $attendu['http_status'], $reponse->get_status() );
		$this->assert_conforme( $attendu, $reponse->get_data() );
	}

	public function test_corps_d_un_refus(): void {
		$attendu = $this->contrat['command_failed'];

		$reponse = ( new CommandController() )->handle( $this->requete( $this->contrat['payload']['plugin'] ) );

		self::assertSame( $attendu['http_status'], $reponse->get_status() );
		$this->assert_conforme( $attendu, $reponse->get_data() );
	}

	public function test_refus_de_la_route_en_arriere_plan(): void {
		$attendu = $this->contrat['command_run_refused'];
		$erreur  = Refusal::error( Refusal::COMMAND_NOT_ASYNC );

		self::assertFalse( AsyncCommands::is_admitted( ReleaseNotesCommand::NAME, true ) );
		self::assertSame( $attendu['body']['code'], $erreur->get_error_code() );
		self::assertSame( $attendu['body']['data'], $erreur->get_error_data() );
		self::assertSame( $attendu['http_status'], $erreur->get_error_data()['status'] );
	}

	public function test_resultat_de_la_file(): void {
		$attendu = $this->contrat['queue_result']['body'];

		$issue = HeartbeatJob::queue_outcome( ReleaseNotesCommand::NAME, $this->contrat['payload']['plugin'] );

		self::assertSame( $attendu['status'], $issue['status'] ?? null );
		self::assertSame( $attendu['result'], HeartbeatJob::result_of( (array) $issue ) );
		self::assertSame( [], $this->api_calls );
	}

	/**
	 * Mêmes clés ; mêmes valeurs hors des clés variables ; types du contrat (chemins pointés, « a|b »).
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
		foreach ( $attendu['types'] as $chemin => $types ) {
			$valeur = $corps;
			foreach ( explode( '.', $chemin ) as $morceau ) {
				self::assertIsArray( $valeur, $chemin );
				self::assertArrayHasKey( $morceau, $valeur, $chemin );
				$valeur = $valeur[ $morceau ];
			}
			self::assertContains( null === $valeur ? 'null' : gettype( $valeur ), explode( '|', $types ), $chemin );
		}
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
