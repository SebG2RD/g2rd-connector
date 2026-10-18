<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunReaper;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Rest\CommandRunsController;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Route de lecture `GET /g2rd/v1/command-runs/<numéro>` (§ 3.6 de la spécification) : la
 * plateforme interroge le site sur une exécution qu'elle lui a confiée. Elle doit toujours
 * pouvoir distinguer « en cours », « terminée » (avec le résultat), « interrompue »,
 * « abandonnée », « inconnue » (404 `run_unknown`) et « route absente » (404
 * `rest_no_route` de WordPress, que la route ne laisse jamais rendre pour un numéro mal
 * formé).
 *
 * La lecture ne fait qu'un ramassage léger (ce numéro, le détenteur du créneau) et une
 * relecture : ni inventaire, ni requête sortante, aucune autre écriture.
 */
final class CommandRunsReadTest extends TestCase {

	/** 01/09/2026 03:46:40 UTC. */
	private const NOW = 1788234400;

	private const NUMERO = '0123456789abcdef0123456789abcdef';
	private const AUTRE  = 'fedcba9876543210fedcba9876543210';
	private const TIERCE = '9999999999abcdef9999999999abcdef';

	/** Motif de la route, sous l'espace `g2rd/v1` : large, volontairement. */
	private const MOTIF = '/command-runs/(?P<command_id>[^/]+)';

	private FakeOptionsTable $table;

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_la_route_de_lecture_exige_la_signature_et_admet_tout_numero(): void {
		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $espace, string $route, array $args ) use ( &$routes ): bool {
				$routes[ $route ] = [ $espace, $args ];
				return true;
			}
		);

		$controleur = $this->controleur();
		$controleur->register();

		self::assertArrayHasKey( self::MOTIF, $routes );
		[ $espace, $args ] = $routes[ self::MOTIF ];
		self::assertSame( 'g2rd/v1', $espace );
		self::assertSame( 'GET', $args['methods'] );
		self::assertSame( [ $controleur, 'read' ], $args['callback'] );
		self::assertSame( [ Auth::class, 'require_signed_run_access' ], $args['permission_callback'], 'Jeton ET signature (méthode, route avec le numéro, corps vide), quelle que soit la politique du site.' );
		// Un schéma ferait refuser par WordPress, avec ses propres codes, un numéro mal formé.
		self::assertArrayNotHasKey( 'args', $args );
	}

	public function test_lecture_d_une_execution_en_cours(): void {
		$this->enregistre(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_core',
				'body_sha256' => str_repeat( 'ab', 32 ),
				'state'       => RunState::RUNNING,
				'accepted_at' => self::NOW - 95,
				'started_at'  => self::NOW - 94,
				'alive_at'    => self::NOW - 20,
				'finished_at' => null,
			]
		);
		$avant = $this->table->rows;

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertSame(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_core',
				'state'       => 'running',
				'accepted_at' => '2026-09-01T03:45:05+00:00',
				'started_at'  => '2026-09-01T03:45:06+00:00',
				'alive_at'    => '2026-09-01T03:46:20+00:00',
				'finished_at' => null,
				'served_at'   => '2026-09-01T03:46:40+00:00',
			],
			$reponse->get_data(),
			'Ni empreinte du corps, ni résultat, ni étape absente.'
		);
		self::assertSame( $avant, $this->table->rows, 'Une exécution vivante n’est pas touchée.' );
	}

	/**
	 * Résultats de `/command` (CommandController::handle(), sans `signature_check`), tels
	 * que l'exécution les écrit.
	 *
	 * @return iterable<string, array{string, array<string, mixed>}>
	 */
	public static function resultats(): iterable {
		yield 'réussie' => [
			RunState::DONE,
			[
				'command'      => 'update_plugin',
				'status'       => 'done',
				'result'       => [
					'plugin'      => 'akismet/akismet.php',
					'old_version' => '5.3',
					'new_version' => '5.4',
				],
				'error'        => null,
				'at'           => '2026-09-01T03:45:00+00:00',
				'stray_output' => 'Notice : sortie d’une extension tierce',
			],
		];
		yield 'échouée' => [
			RunState::FAILED,
			[
				'command' => 'update_plugin',
				'status'  => 'failed',
				'result'  => null,
				'error'   => 'La mise à jour a échoué : le dossier de l’extension n’est pas accessible en écriture.',
				'at'      => '2026-09-01T03:45:00+00:00',
			],
		];
	}

	/**
	 * @param array<string, mixed> $resultat
	 */
	#[DataProvider( 'resultats' )]
	public function test_lecture_d_une_execution_terminee_avec_son_resultat( string $etat, array $resultat ): void {
		$this->enregistre(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_plugin',
				'body_sha256' => str_repeat( 'ab', 32 ),
				'state'       => $etat,
				'accepted_at' => self::NOW - 300,
				'started_at'  => self::NOW - 299,
				'alive_at'    => self::NOW - 100,
				'finished_at' => self::NOW - 100,
				'outcome'     => $resultat,
			]
		);

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		$data = $reponse->get_data();
		self::assertSame( $etat, $data['state'] ?? null );
		self::assertSame( '2026-09-01T03:45:00+00:00', $data['finished_at'] ?? null );
		self::assertSame( $resultat, $data['outcome'] ?? null, 'Le résultat de `/command`, tel quel.' );
		self::assertSame( [ 'command_id', 'command', 'state', 'accepted_at', 'started_at', 'alive_at', 'finished_at', 'outcome', 'served_at' ], array_keys( $data ) );
	}

	/**
	 * Le résultat n'est rendu que pour une exécution conclue par lui (done, failed) : la
	 * route le garantit elle-même, quel que soit le contenu de l'enregistrement.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function etats_sans_resultat(): iterable {
		yield 'acceptée' => [ RunState::ACCEPTED ];
		yield 'en cours' => [ RunState::RUNNING ];
		yield 'interrompue' => [ RunState::INTERRUPTED ];
		yield 'abandonnée' => [ RunState::ABANDONED ];
	}

	#[DataProvider( 'etats_sans_resultat' )]
	public function test_resultat_jamais_rendu_avant_la_fin( string $etat ): void {
		$record            = $this->recente( self::NUMERO, $etat );
		$record['outcome'] = [
			'status' => 'done',
			'result' => [ 'new_version' => '5.4' ],
			'error'  => null,
		];
		$this->enregistre( $record );

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( $etat, $reponse->get_data()['state'] ?? null );
		self::assertArrayNotHasKey( 'outcome', $reponse->get_data() );
	}

	public function test_numero_inconnu_404_run_unknown(): void {
		// Une autre exécution existe : seule celle demandée compte.
		$this->enregistre( $this->recente( self::AUTRE, RunState::RUNNING ) );
		$this->table->queries = [];

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		// Jamais `rest_no_route` : la plateforme y lirait un connecteur sans la route.
		$this->assertRefus( $reponse, 'g2rd_connector_run_unknown', 404 );
		self::assertSame( [ 'status' => 404 ], $reponse->get_error_data() );
		self::assertNull( RunStore::read( self::NUMERO ), 'Une lecture ne crée rien.' );
		self::assertSame( [], preg_grep( '/^(INSERT|UPDATE|DELETE)/', $this->table->queries ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function numeros_mal_formes(): iterable {
		yield 'majuscules' => [ strtoupper( self::NUMERO ) ];
		yield '31 caractères' => [ substr( self::NUMERO, 1 ) ];
		yield '33 caractères' => [ self::NUMERO . 'a' ];
		yield 'pas hexadécimal' => [ str_repeat( 'g', 32 ) ];
		yield 'saut de ligne final' => [ self::NUMERO . "\n" ];
		yield 'texte quelconque' => [ 'pas-un-numero' ];
		yield 'joker de recherche' => [ '%' ];
	}

	/**
	 * Le motif de la route admet tout numéro (sauf « / ») : WordPress passe la main à la
	 * route, qui répond 400 `invalid_command_id`. Jamais la 404 `rest_no_route`, que la
	 * plateforme lirait comme « connecteur sans la route » (elle repasserait alors en
	 * synchrone une commande peut-être acceptée). Rien n'est lu en base.
	 */
	#[DataProvider( 'numeros_mal_formes' )]
	public function test_numero_mal_forme_400_et_non_rest_no_route( string $numero ): void {
		$chemin = '/g2rd/v1/command-runs/' . $numero;
		// Comme WP_REST_Server::match_request_to_handler().
		self::assertSame( 1, preg_match( '@^/' . G2RD_CONNECTOR_REST_NS . self::MOTIF . '$@i', $chemin, $correspondance ) );
		self::assertSame( $numero, $correspondance['command_id'] );

		$reponse = $this->controleur()->read( $this->lecture( $numero ) );

		$this->assertRefus( $reponse, 'g2rd_connector_invalid_command_id', 400 );
		self::assertSame( [], $this->table->queries );
	}

	/**
	 * Morte en route (plus de 600 s sans signe de vie) : la lecture la déclare interrompue,
	 * rend son créneau, et sert aussitôt le nouvel état, avec la cause.
	 */
	public function test_la_lecture_ramasse_une_execution_morte(): void {
		self::assertTrue( ExecutionSlot::acquire( self::NUMERO, 'update_plugin', self::NOW - 900 )['acquired'] );
		$record = $this->recente( self::NUMERO, RunState::RUNNING );
		$record['alive_at'] = self::NOW - RunReaper::SILENCE_TIMEOUT_SECONDS - 1;
		$record['step']     = UpdateTransaction::STEP_UPGRADING;
		$this->enregistre( $record );

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		$data = $reponse->get_data();
		self::assertSame( RunState::INTERRUPTED, $data['state'] ?? null );
		self::assertSame( '2026-09-01T03:46:40+00:00', $data['finished_at'] ?? null );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $data['step'] ?? null, 'L’étape où elle s’est tue.' );
		self::assertSame( RunReaper::REASON_NO_SIGN_OF_LIFE, $data['interruption']['reason'] ?? null );
		self::assertStringContainsString( 'Action :', $data['interruption']['error'] ?? '' );
		self::assertArrayNotHasKey( 'outcome', $data );
		self::assertSame( RunState::INTERRUPTED, RunStore::read( self::NUMERO )['state'] ?? null );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows, 'Le site est libre aussitôt.' );
	}

	/**
	 * La lecture ramasse aussi le détenteur du créneau, quel que soit le numéro lu : une
	 * exécution acceptée qui n'a jamais démarré ne retient pas le site jusqu'à l'acceptation
	 * suivante. Un détenteur vivant n'est pas touché.
	 */
	public function test_la_lecture_ramasse_aussi_le_detenteur_du_creneau(): void {
		$this->enregistre( $this->terminee( self::NUMERO ) );
		$morte                = $this->recente( self::AUTRE, RunState::ACCEPTED );
		$morte['accepted_at'] = self::NOW - RunReaper::ACCEPTED_TIMEOUT_SECONDS - 1;
		$this->enregistre( $morte );
		self::assertTrue( ExecutionSlot::acquire( self::AUTRE, 'update_core', self::NOW - RunReaper::ACCEPTED_TIMEOUT_SECONDS - 1 )['acquired'] );

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( RunState::DONE, $reponse->get_data()['state'] ?? null );
		self::assertSame( RunState::ABANDONED, RunStore::read( self::AUTRE )['state'] ?? null );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows );

		// Témoin : un détenteur vivant garde le créneau, et son enregistrement ne bouge pas.
		$this->enregistre( $this->recente( self::TIERCE, RunState::RUNNING ) );
		self::assertTrue( ExecutionSlot::acquire( self::TIERCE, 'update_theme', self::NOW - 30 )['acquired'] );
		$avant = $this->table->rows;

		$this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertSame( $avant, $this->table->rows );
	}

	/**
	 * `served_at` est l'heure de la réponse, jamais une date de l'enregistrement : la
	 * plateforme écarte ainsi une réponse figée par un cache.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function etats(): iterable {
		foreach ( [ RunState::ACCEPTED, RunState::RUNNING, RunState::DONE, RunState::FAILED, RunState::INTERRUPTED, RunState::ABANDONED ] as $etat ) {
			yield $etat => [ $etat ];
		}
	}

	#[DataProvider( 'etats' )]
	public function test_served_at_toujours_present( string $etat ): void {
		$this->enregistre( $this->recente( self::NUMERO, $etat ) );

		$reponse = $this->controleur( self::NOW + 7 )->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		$data = $reponse->get_data();
		self::assertSame( $etat, $data['state'] ?? null );
		self::assertSame( '2026-09-01T03:46:47+00:00', $data['served_at'] ?? null );
		self::assertSame( 'served_at', array_key_last( $data ) );
	}

	/**
	 * Mise à jour protégée en cours : l'étape de sa transaction (copie de sécurité,
	 * installation, contrôle du site, retour arrière) est rendue telle quelle. Sans étape
	 * enregistrée (cœur, thème, optimisation…), pas de clé.
	 */
	public function test_etape_rendue_pour_une_mise_a_jour_protegee(): void {
		$record         = $this->recente( self::NUMERO, RunState::RUNNING );
		$record['step'] = UpdateTransaction::STEP_HEALTH;
		$this->enregistre( $record );

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_plugin',
				'state'       => 'running',
				'accepted_at' => '2026-09-01T03:46:30+00:00',
				'started_at'  => '2026-09-01T03:46:30+00:00',
				'alive_at'    => '2026-09-01T03:46:30+00:00',
				'finished_at' => null,
				'step'        => 'health',
				'served_at'   => '2026-09-01T03:46:40+00:00',
			],
			$reponse->get_data()
		);

		$this->enregistre( $this->recente( self::AUTRE, RunState::RUNNING ) );
		$reponse = $this->controleur()->read( $this->lecture( self::AUTRE ) );
		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertArrayNotHasKey( 'step', $reponse->get_data() );
	}

	/**
	 * Une lecture que la base refuse n'est jamais « numéro inconnu » : la plateforme en
	 * conclurait que la commande n'a jamais été reçue. Réponse 500, ambiguë : elle
	 * interroge de nouveau.
	 */
	public function test_lecture_refusee_par_la_base_500_jamais_run_unknown(): void {
		$this->enregistre( $this->recente( self::NUMERO, RunState::RUNNING ) );
		$this->table->read_fails = true;
		$this->table->queries    = [];

		$reponse = $this->controleur()->read( $this->lecture( self::NUMERO ) );

		$this->assertRefus( $reponse, 'g2rd_connector_store_unavailable', 500 );
		self::assertStringContainsString( 'n’a pas pu être lu', $reponse->get_error_message() );
		self::assertSame( [], preg_grep( '/^(INSERT|UPDATE|DELETE)/', $this->table->queries ) );
	}

	/**
	 * Le numéro lu est celui du chemin, couvert par la signature (méthode, route avec le
	 * numéro, corps vide). Un `?command_id=…` ajouté à l'adresse, hors signature, passerait
	 * avant lui dans WP_REST_Request::get_param() : il est ignoré.
	 */
	public function test_le_numero_lu_est_celui_de_la_route_signee(): void {
		$this->enregistre( $this->terminee( self::NUMERO ) );
		$this->enregistre( $this->terminee( self::AUTRE ) );
		$request = $this->lecture( self::NUMERO );
		$request->set_param( 'command_id', self::AUTRE );
		self::assertSame( self::AUTRE, $request->get_param( 'command_id' ) );

		$reponse = $this->controleur()->read( $request );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( self::NUMERO, $reponse->get_data()['command_id'] ?? null );
	}

	private function controleur( int $now = self::NOW ): CommandRunsController {
		return new CommandRunsController(
			static function (): void {
				self::fail( 'Une lecture ne programme jamais d’exécution.' );
			},
			static fn (): int => $now
		);
	}

	/**
	 * Requête de lecture telle que WordPress la passe à la route : la route REST (celle qui
	 * est signée), et le numéro tiré de cette route par le motif.
	 */
	private function lecture( string $numero ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/g2rd/v1/command-runs/' . $numero );
		$request->set_url_params( [ 'command_id' => $numero ] );
		return $request;
	}

	/**
	 * Enregistrement d'une exécution dans l'état `$etat`, aux dates récentes : ni le
	 * ramassage ni la purge n'y touchent.
	 *
	 * @return array<string, mixed>
	 */
	private function recente( string $numero, string $etat ): array {
		$demarre = RunState::ACCEPTED !== $etat && RunState::ABANDONED !== $etat;

		return [
			'command_id'  => $numero,
			'command'     => 'update_plugin',
			'body_sha256' => str_repeat( 'ab', 32 ),
			'state'       => $etat,
			'accepted_at' => self::NOW - 10,
			'started_at'  => $demarre ? self::NOW - 10 : null,
			'alive_at'    => $demarre ? self::NOW - 10 : null,
			'finished_at' => RunState::is_terminal( $etat ) ? self::NOW - 5 : null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function terminee( string $numero ): array {
		$record            = $this->recente( $numero, RunState::DONE );
		$record['outcome'] = [
			'command' => 'update_plugin',
			'status'  => 'done',
			'result'  => [ 'new_version' => '5.4' ],
			'error'   => null,
			'at'      => '2026-09-01T03:46:35+00:00',
		];
		return $record;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function enregistre( array $record ): void {
		self::assertTrue( RunStore::create( $record ) );
	}

	/**
	 * Un refus explicite : code lu par la plateforme, statut du contrat, message qui dit quoi
	 * faire.
	 *
	 * @phpstan-assert WP_Error $reponse
	 */
	private function assertRefus( mixed $reponse, string $code, int $statut ): void {
		self::assertInstanceOf( WP_Error::class, $reponse );
		self::assertSame( $code, $reponse->get_error_code() );
		self::assertSame( $statut, $reponse->get_error_data()['status'] ?? null );
		self::assertStringContainsString( 'Action :', $reponse->get_error_message() );
	}
}
