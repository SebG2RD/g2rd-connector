<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Rest\CommandRunsController;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Route d'acceptation `POST /g2rd/v1/command-runs` (§ 3.3 et § 3.6 de la spécification) :
 * le site enregistre la commande sous le numéro choisi par la plateforme, répond « reçu »,
 * et l'exécution est programmée une seule fois par numéro. Un renvoi (CDN de l'hébergeur,
 * plateforme qui réessaie) ne reçoit que l'état, jamais le résultat, et ne déclenche rien.
 *
 * Les six lignes du tableau du § 3.3, une par test, puis les refus (400, 413, 503), les
 * courses et les pannes de la base. L'exécution elle-même (AfterResponseRunner, tâche 21)
 * est remplacée par une fermeture qui note ce qui est programmé.
 */
final class CommandRunsAcceptTest extends TestCase {

	/** 01/09/2026 03:46:40 UTC. */
	private const NOW = 1788234400;

	private const TOKEN  = 'jeton-du-site-0123456789';
	private const NUMERO = '0123456789abcdef0123456789abcdef';
	private const AUTRE  = 'fedcba9876543210fedcba9876543210';
	private const ROUTE  = '/g2rd/v1/command-runs';
	private const CORPS  = '{"command_id":"' . self::NUMERO . '","command":"update_plugin","payload":{"file":"akismet/akismet.php"}}';

	private FakeOptionsTable $table;

	/**
	 * Exécutions programmées par la route : [ numéro, commande, charge ].
	 *
	 * @var list<array{0: string, 1: string, 2: array<mixed>|null}>
	 */
	private array $programmees = [];

	/**
	 * Pauses demandées par la route (microsecondes), dans l'ordre : aucune n'est réellement
	 * attendue.
	 *
	 * @var list<int>
	 */
	private array $pauses = [];

	/**
	 * Ce qui se passe ailleurs pendant une pause (une autre requête qui écrit) : reçoit le
	 * rang de la pause, à partir de 1.
	 *
	 * @var (\Closure(int): void)|null
	 */
	private ?\Closure $pendant_la_pause = null;

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::TOKEN,
		];
		$this->programmees      = [];
		$this->pauses           = [];
		$this->pendant_la_pause = null;
		ignore_user_abort( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		ignore_user_abort( false );
		parent::tearDown();
	}

	public function test_la_route_exige_la_signature_et_n_a_pas_de_schema(): void {
		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $espace, string $route, array $args ) use ( &$routes ): bool {
				$routes[] = [ $espace, $route, $args ];
				return true;
			}
		);

		$controleur = $this->controleur();
		$controleur->register();

		// Acceptation, puis lecture (CommandRunsReadTest).
		self::assertCount( 2, $routes );
		[ $espace, $route, $args ] = $routes[0];
		self::assertSame( 'g2rd/v1', $espace );
		self::assertSame( '/command-runs', $route );
		self::assertSame( 'POST', $args['methods'] );
		self::assertSame( [ $controleur, 'accept' ], $args['callback'] );
		self::assertSame( [ Auth::class, 'require_signed_run_access' ], $args['permission_callback'], 'Jeton ET signature, quelle que soit la politique du site.' );
		// La validation est faite par la route, après le contrôle de taille : un schéma
		// ferait refuser par WordPress, avec ses propres codes, ce que la plateforme lit.
		self::assertArrayNotHasKey( 'args', $args );
	}

	/** § 3.3, ligne 1 : numéro inconnu, site libre → 202, exécution une fois. */
	public function test_numero_inconnu_site_libre_202_et_execution_programmee_une_fois(): void {
		$controleur = $this->controleur();

		$reponse = $controleur->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 202, $reponse->get_status() );
		self::assertSame( [ [ self::NUMERO, 'update_plugin', [ 'file' => 'akismet/akismet.php' ] ] ], $this->programmees );
		self::assertSame( 1, ignore_user_abort(), 'Une fermeture du client ne doit pas laisser une acceptation à moitié faite.' );

		$record = RunStore::read( self::NUMERO );
		self::assertSame( RunState::ACCEPTED, $record['state'] ?? null );
		self::assertSame( hash( 'sha256', self::CORPS ), $record['body_sha256'] ?? null, 'Empreinte du corps brut, tel que signé.' );
		self::assertSame( self::NUMERO, ExecutionSlot::holder()['command_id'] ?? null, 'Le créneau est tenu jusqu’à la fin de l’exécution.' );

		// La même demande, reçue une seconde fois : rien de plus n'est programmé.
		$reponse = $controleur->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertTrue( $reponse->get_data()['duplicate'] ?? null );
		self::assertCount( 1, $this->programmees );
		self::assertSame( [], $this->pauses, 'Ni l’acceptation ni le renvoi n’attendent.' );
	}

	public function test_reponse_202_a_la_forme_attendue(): void {
		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 202, $reponse->get_status() );
		self::assertSame(
			[
				'command_id'         => self::NUMERO,
				'state'              => 'accepted',
				'accepted_at'        => '2026-09-01T03:46:40+00:00',
				'poll_after_seconds' => 2,
				'served_at'          => '2026-09-01T03:46:40+00:00',
			],
			$reponse->get_data()
		);
		self::assertSame( gmdate( 'c', self::NOW ), '2026-09-01T03:46:40+00:00' );

		// L'enregistrement : rien d'autre que ce que la lecture servira, sans la charge.
		self::assertSame(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_plugin',
				'body_sha256' => hash( 'sha256', self::CORPS ),
				'state'       => RunState::ACCEPTED,
				'accepted_at' => self::NOW,
				'started_at'  => null,
				'alive_at'    => null,
				'finished_at' => null,
			],
			RunStore::read( self::NUMERO )
		);
	}

	/**
	 * § 3.3, ligne 2 : numéro inconnu, site occupé → 409 `site_busy`, rien n'est enregistré :
	 * la plateforme réessaie plus tard sous un nouveau numéro, celui-ci n'a rien déclenché.
	 *
	 * @return iterable<string, array{string, ?string, ?string}>
	 */
	public static function occupations(): iterable {
		yield 'autre exécution en arrière-plan' => [ 'execution', self::AUTRE, '2026-09-01T03:46:10+00:00' ];
		yield 'mise à jour protégée (reprise du cron)' => [ 'transaction', null, '2026-09-01T03:42:40+00:00' ];
	}

	#[DataProvider( 'occupations' )]
	public function test_numero_inconnu_site_occupe_409_rien_enregistre( string $occupation, ?string $busy_with, ?string $busy_since ): void {
		if ( 'execution' === $occupation ) {
			self::assertTrue( ExecutionSlot::acquire( self::AUTRE, 'update_theme', self::NOW - 30 )['acquired'] );
		} else {
			$this->options[ UpdateTransaction::OPTION_KEY ] = [
				'id'          => 'a1b2c3d4e5f60708',
				'plugin_file' => 'akismet/akismet.php',
				'step'        => UpdateTransaction::STEP_UPGRADING,
				'started_at'  => self::NOW - 240,
				'updated_at'  => self::NOW - 30,
			];
		}
		$creneau = $this->table->rows[ ExecutionSlot::OPTION ] ?? null;

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_site_busy', 409 );
		self::assertSame( $busy_with, $reponse->get_error_data()['busy_with'] );
		self::assertSame( $busy_since, $reponse->get_error_data()['busy_since'] );
		self::assertNull( RunStore::read( self::NUMERO ), 'Rien n’est enregistré.' );
		self::assertSame( $creneau, $this->table->rows[ ExecutionSlot::OPTION ] ?? null, 'Le créneau reste à son détenteur.' );
		self::assertSame( [], $this->programmees );
		self::assertSame( [], $this->pauses, 'Occupé par une autre commande : réponse immédiate, sans attente.' );
	}

	/** § 3.3, ligne 3 : numéro connu, même corps → 200 `duplicate`, l'état sans le résultat. */
	public function test_numero_connu_meme_corps_200_duplicate_sans_resultat(): void {
		$this->enregistre(
			[
				'command_id'  => self::NUMERO,
				'command'     => 'update_plugin',
				'body_sha256' => hash( 'sha256', self::CORPS ),
				'state'       => RunState::DONE,
				'accepted_at' => self::NOW - 300,
				'started_at'  => self::NOW - 299,
				'alive_at'    => self::NOW - 100,
				'finished_at' => self::NOW - 100,
				'step'        => UpdateTransaction::STEP_HEALTH,
				'outcome'     => [
					'status' => 'done',
					'result' => [
						'plugin'      => 'akismet/akismet.php',
						'new_version' => '5.4',
					],
					'error'  => null,
				],
			]
		);
		$avant = $this->table->rows[ RunStore::option_name( self::NUMERO ) ];

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertSame(
			[
				'command_id'  => self::NUMERO,
				'state'       => 'done',
				'duplicate'   => true,
				'accepted_at' => '2026-09-01T03:41:40+00:00',
				'started_at'  => '2026-09-01T03:41:41+00:00',
				'alive_at'    => '2026-09-01T03:45:00+00:00',
				'finished_at' => '2026-09-01T03:45:00+00:00',
				'served_at'   => '2026-09-01T03:46:40+00:00',
			],
			$reponse->get_data(),
			'Ni résultat, ni étape : le résultat se lit par l’interrogation signée.'
		);
		self::assertSame( $avant, $this->table->rows[ RunStore::option_name( self::NUMERO ) ], 'Rien n’est réécrit.' );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows );
		self::assertSame( [], $this->programmees );
	}

	/** § 3.3, ligne 4 : numéro connu, autre corps → 409 `command_id_conflict`, rien n'est exécuté. */
	public function test_numero_connu_corps_different_409_conflit(): void {
		$this->enregistre( $this->acceptee( self::NUMERO, hash( 'sha256', '{"autre":"corps"}' ) ) );
		$avant = $this->table->rows;

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_command_id_conflict', 409 );
		self::assertSame( $avant, $this->table->rows, 'Ni l’enregistrement ni le créneau ne bougent.' );
		self::assertSame( [], $this->programmees );
	}

	/**
	 * § 3.3, ligne 5, le cas du CDN : la même requête, renvoyée à l'identique (même nonce,
	 * signature valide), trouve le numéro déjà reçu et ne reçoit que l'état.
	 */
	public function test_renvoi_du_cdn_numero_connu_200_duplicate(): void {
		$controleur = $this->controleur();
		$nonce      = bin2hex( random_bytes( 16 ) );

		$original = $this->signee( self::CORPS, $nonce );
		self::assertTrue( Auth::require_signed_run_access( $original ) );
		self::assertSame( 202, $this->statut( $controleur->accept( $original ) ) );

		$renvoi = $this->signee( self::CORPS, $nonce );
		self::assertTrue( Auth::require_signed_run_access( $renvoi ) );
		self::assertTrue( Auth::is_replayed_acceptance( $renvoi ) );
		$reponse = $controleur->accept( $renvoi );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertTrue( $reponse->get_data()['duplicate'] ?? null );
		self::assertSame( 'accepted', $reponse->get_data()['state'] ?? null );
		self::assertArrayNotHasKey( 'outcome', $reponse->get_data() );
		self::assertCount( 1, $this->programmees, 'Une seule exécution pour les deux arrivées.' );
	}

	/**
	 * § 3.3, ligne 6 : nonce déjà vu, numéro inconnu → 401 `signature_replayed`. La copie
	 * d'une requête dont rien n'a été enregistré ne peut rien déclencher.
	 */
	public function test_renvoi_du_cdn_numero_inconnu_401(): void {
		$nonce = bin2hex( random_bytes( 16 ) );
		// L'original a passé le contrôle d'accès, mais rien n'est enregistré sous ce numéro.
		self::assertTrue( Auth::require_signed_run_access( $this->signee( self::CORPS, $nonce ) ) );

		$renvoi = $this->signee( self::CORPS, $nonce );
		self::assertTrue( Auth::require_signed_run_access( $renvoi ) );
		$reponse = $this->controleur()->accept( $renvoi );

		$this->assertRefus( $reponse, 'g2rd_connector_' . RequestSignature::CODE_REPLAYED, 401 );
		self::assertSame( [ 'status' => 401 ], $reponse->get_error_data() );
		self::assertStringContainsString( 'n’a rien lancé', $reponse->get_error_message() );
		$this->assertRienEnregistre();
	}

	/**
	 * Corps de plus de 16 Ko : 413 dès `rest_pre_dispatch`, avant que WordPress ne le décode
	 * (un corps qui n'est pas du JSON reçoit 413, pas 400).
	 */
	public function test_corps_de_plus_de_16_ko_413_avant_decodage(): void {
		$trop_gros = $this->requete( str_repeat( 'x', 16385 ) );

		$this->assertRefus( CommandRunsController::guard_body_size( null, null, $trop_gros ), 'g2rd_connector_body_too_large', 413 );
		// WordPress compare les routes sans tenir compte de la casse : le garde aussi.
		$casse = new WP_REST_Request( 'POST', '/G2RD/v1/Command-Runs' );
		$casse->set_body( str_repeat( 'x', 16385 ) );
		$this->assertRefus( CommandRunsController::guard_body_size( null, null, $casse ), 'g2rd_connector_body_too_large', 413 );

		// 16 Ko tout juste : le garde laisse passer.
		self::assertNull( CommandRunsController::guard_body_size( null, null, $this->requete( str_repeat( 'x', 16384 ) ) ) );

		// La route elle-même refuse aussi, si le filtre a été retiré par une autre extension.
		$this->assertRefus( $this->controleur()->accept( $trop_gros ), 'g2rd_connector_body_too_large', 413 );
		$this->assertRienEnregistre();
	}

	public function test_le_garde_de_taille_ignore_les_autres_routes(): void {
		$routes = [
			'/g2rd/v1/command',
			'/g2rd/v1/command-runs/' . self::NUMERO,
			'/g2rd/v1/command-runs-bis',
			'/wp/v2/posts',
		];
		foreach ( $routes as $route ) {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_body( str_repeat( 'x', 20000 ) );

			self::assertNull( CommandRunsController::guard_body_size( null, null, $request ), $route );
			self::assertSame( 'deja', CommandRunsController::guard_body_size( 'deja', null, $request ), $route );
		}

		// Sur la route, une réponse déjà décidée par un filtre précédent est gardée.
		$deja = new WP_Error( 'autre_extension', 'Refusé par une autre extension.', [ 'status' => 403 ] );
		self::assertSame( $deja, CommandRunsController::guard_body_size( $deja, null, $this->requete( str_repeat( 'x', 20000 ) ) ) );

		// Branché sur toutes les requêtes REST du site : il ne lève jamais.
		self::assertNull( CommandRunsController::guard_body_size( null, null, 'pas une requête' ) );
		self::assertNull( CommandRunsController::guard_body_size( null ) );
	}

	/**
	 * Numéro absent ou mal formé : 400, rien n'est enregistré.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function numeros_mal_formes(): iterable {
		$corps = static fn ( string $numero ): string => '{"command_id":' . $numero . ',"command":"update_plugin"}';

		yield 'absent' => [ '{"command":"update_plugin"}' ];
		yield 'majuscules' => [ $corps( '"' . strtoupper( self::NUMERO ) . '"' ) ];
		yield '31 caractères' => [ $corps( '"' . substr( self::NUMERO, 1 ) . '"' ) ];
		yield '33 caractères' => [ $corps( '"' . self::NUMERO . 'a"' ) ];
		yield 'saut de ligne final' => [ $corps( '"' . self::NUMERO . '\n"' ) ];
		yield 'nombre' => [ $corps( '12345' ) ];
		yield 'corps qui n’est pas du JSON' => [ 'command_id=' . self::NUMERO ];
		yield 'liste JSON' => [ '["' . self::NUMERO . '"]' ];
		yield 'corps vide' => [ '' ];
	}

	#[DataProvider( 'numeros_mal_formes' )]
	public function test_numero_mal_forme_400( string $corps ): void {
		$reponse = $this->controleur()->accept( $this->requete( $corps ) );

		$this->assertRefus( $reponse, 'g2rd_connector_invalid_command_id', 400 );
		$this->assertRienEnregistre();
	}

	/**
	 * Commandes instantanées (restées sur `/command`), inconnues, ou mal écrites : 400.
	 *
	 * @return iterable<string, array{mixed}>
	 */
	public static function commandes_refusees(): iterable {
		foreach ( AsyncCommands::EXCLUDED as $command ) {
			yield $command => [ $command ];
		}
		yield 'inconnue' => [ 'drop_tables' ];
		yield 'casse différente' => [ 'Update_Plugin' ];
		yield 'nombre' => [ 1 ];
		yield 'absente' => [ null ];
	}

	#[DataProvider( 'commandes_refusees' )]
	public function test_commande_exclue_400( mixed $commande ): void {
		$corps = [ 'command_id' => self::NUMERO ];
		if ( null !== $commande ) {
			$corps['command'] = $commande;
		}

		$reponse = $this->controleur()->accept( $this->requete( (string) wp_json_encode( $corps ) ) );

		$this->assertRefus( $reponse, 'g2rd_connector_command_not_async', 400 );
		$this->assertRienEnregistre();
	}

	/**
	 * Charge absente, ou objet (un tableau PHP vide est encodé `[]`) : admise, passée telle
	 * quelle à l'exécution.
	 *
	 * @return iterable<string, array{string, array<mixed>|null}>
	 */
	public static function charges_admises(): iterable {
		yield 'absente' => [ '', null ];
		yield 'objet vide' => [ ',"payload":{}', [] ];
		yield 'tableau vide' => [ ',"payload":[]', [] ];
		yield 'objet' => [ ',"payload":{"stylesheet":"twentytwentyfour"}', [ 'stylesheet' => 'twentytwentyfour' ] ];
	}

	/**
	 * @param array<mixed>|null $attendue
	 */
	#[DataProvider( 'charges_admises' )]
	public function test_charge_absente_ou_objet_acceptee( string $charge, ?array $attendue ): void {
		$reponse = $this->controleur()->accept( $this->requete( '{"command_id":"' . self::NUMERO . '","command":"update_theme"' . $charge . '}' ) );

		self::assertSame( 202, $this->statut( $reponse ) );
		self::assertSame( [ [ self::NUMERO, 'update_theme', $attendue ] ], $this->programmees );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function charges_refusees(): iterable {
		yield 'nulle' => [ 'null' ];
		yield 'texte' => [ '"akismet/akismet.php"' ];
		yield 'nombre' => [ '12' ];
		yield 'booléen' => [ 'true' ];
		yield 'liste' => [ '["akismet/akismet.php"]' ];
	}

	#[DataProvider( 'charges_refusees' )]
	public function test_charge_qui_n_est_pas_un_objet_400( string $charge ): void {
		$reponse = $this->controleur()->accept( $this->requete( '{"command_id":"' . self::NUMERO . '","command":"update_plugin","payload":' . $charge . '}' ) );

		$this->assertRefus( $reponse, 'g2rd_connector_invalid_payload', 400 );
		$this->assertRienEnregistre();
	}

	/** Sans `G2RD_CONNECTOR_ASYNC_PROBE` dans wp-config.php, la sonde est une commande inconnue. */
	public function test_sonde_inconnue_sans_constante(): void {
		self::assertFalse( defined( 'G2RD_CONNECTOR_ASYNC_PROBE' ) );

		$reponse = $this->controleur()->accept( $this->requete( '{"command_id":"' . self::NUMERO . '","command":"async_probe","payload":{"seconds":60}}' ) );

		$this->assertRefus( $reponse, 'g2rd_connector_command_not_async', 400 );
		$this->assertRienEnregistre();
	}

	/**
	 * Avec la constante : une durée entière de 10 à 600 s, par pas de 10 s. Processus à part :
	 * une constante définie le reste jusqu'à la fin du processus PHP.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sonde_acceptee_avec_constante_et_bornes(): void {
		define( 'G2RD_CONNECTOR_ASYNC_PROBE', true );
		$controleur = $this->controleur();

		foreach ( [ '10', '600', '60', '{"seconds":0}', '"60"', '60.5', '-10', '5', '15', '605', '610' ] as $index => $duree ) {
			$numero  = sprintf( '%032x', $index + 1 );
			$charge  = str_starts_with( $duree, '{' ) ? $duree : '{"seconds":' . $duree . '}';
			$reponse = $controleur->accept( $this->requete( '{"command_id":"' . $numero . '","command":"async_probe","payload":' . $charge . '}' ) );

			if ( in_array( $duree, [ '10', '600', '60' ], true ) ) {
				self::assertSame( 202, $this->statut( $reponse ), $duree );
				self::assertSame( [ $numero, 'async_probe', [ 'seconds' => (int) $duree ] ], end( $this->programmees ) );
				// Fin de l'exécution : le créneau est rendu pour la suivante.
				self::assertTrue( ExecutionSlot::release( $numero ) );
				continue;
			}
			$this->assertRefus( $reponse, 'g2rd_connector_invalid_payload', 400 );
			self::assertNull( RunStore::read( $numero ), $duree );
		}

		// Charge absente, ou sans durée.
		foreach ( [ '', ',"payload":{}' ] as $index => $charge ) {
			$numero  = sprintf( '%032x', 100 + $index );
			$reponse = $controleur->accept( $this->requete( '{"command_id":"' . $numero . '","command":"async_probe"' . $charge . '}' ) );
			$this->assertRefus( $reponse, 'g2rd_connector_invalid_payload', 400 );
		}

		self::assertCount( 3, $this->programmees );
	}

	/** Constante d'arrêt posée dans wp-config.php : 503, rien n'est enregistré ni exécuté. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_constante_d_arret_503_et_rien_execute(): void {
		define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_async_disabled', 503 );
		$this->assertRienEnregistre();
	}

	/**
	 * Le numéro connu passe avant la constante d'arrêt : un renvoi d'une commande déjà
	 * acceptée, arrivé après la pose de la constante, reçoit « déjà reçue ». Avec 503, la
	 * plateforme la croirait jamais partie et la repasserait en synchrone : double exécution.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_renvoi_d_une_commande_connue_apres_la_constante_reste_duplicate(): void {
		$controleur = $this->controleur();
		self::assertSame( 202, $this->statut( $controleur->accept( $this->requete( self::CORPS ) ) ) );

		define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );
		$reponse = $controleur->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertTrue( $reponse->get_data()['duplicate'] ?? null );
		self::assertCount( 1, $this->programmees );

		// Témoin : un numéro neuf, lui, est bien refusé.
		$neuf = str_replace( self::NUMERO, self::AUTRE, self::CORPS );
		$this->assertRefus( $controleur->accept( $this->requete( $neuf ) ), 'g2rd_connector_async_disabled', 503 );
	}

	/**
	 * Un autre processus enregistre le même numéro entre la lecture et la création : le
	 * créneau pris est rendu, et la réponse est celle d'une commande déjà reçue.
	 */
	public function test_course_a_la_creation_rend_le_creneau_et_repond_duplicate(): void {
		// Juste avant la prise du créneau (première écriture de l'acceptation).
		$this->table->before_write = function (): void {
			self::assertTrue( RunStore::create( $this->acceptee( self::NUMERO, hash( 'sha256', self::CORPS ) ) ) );
		};

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		self::assertTrue( $reponse->get_data()['duplicate'] ?? null );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows, 'Le créneau pris pour rien est rendu.' );
		self::assertSame( [], $this->programmees, 'L’autre processus exécute : pas celui-ci.' );
	}

	/**
	 * Le créneau est tenu par ce même numéro, sans enregistrement encore : une autre
	 * requête est en train d'accepter cette commande (la plateforme qui renvoie le même
	 * numéro). Ce n'est pas « site occupé » : la plateforme repartirait sous un nouveau
	 * numéro, et la commande s'exécuterait deux fois. L'enregistrement ne paraît pas pendant
	 * la courte attente (réception interrompue, base très lente) : réponse ambiguë (500),
	 * après une attente bornée, et le renvoi suivant trouve le numéro.
	 */
	public function test_meme_numero_en_cours_de_reception_jamais_site_busy(): void {
		self::assertTrue( ExecutionSlot::acquire( self::NUMERO, 'update_plugin', self::NOW - 1 )['acquired'] );
		$creneau = $this->table->rows[ ExecutionSlot::OPTION ];

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_acceptance_in_progress', 500 );
		self::assertStringContainsString( 'Rien n’a été exécuté par cette demande', $reponse->get_error_message() );
		self::assertSame( $creneau, $this->table->rows[ ExecutionSlot::OPTION ], 'Le créneau reste à la requête qui accepte.' );
		self::assertNull( RunStore::read( self::NUMERO ) );
		self::assertSame( [], $this->programmees );
		self::assertNotSame( [], $this->pauses, 'L’enregistrement est relu après une attente avant la 500.' );
		self::assertLessThanOrEqual( 1500000, array_sum( $this->pauses ), 'Attente bornée : 1,5 s au plus, l’acceptation reste courte.' );

		// La requête qui accepte termine : le renvoi suivant reçoit « déjà reçue », sans attente.
		$this->pauses = [];
		$this->enregistre( $this->acceptee( self::NUMERO, hash( 'sha256', self::CORPS ) ) );
		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );
		self::assertSame( 200, $this->statut( $reponse ) );
		self::assertSame( [], $this->programmees );
		self::assertSame( [], $this->pauses );
	}

	/**
	 * § 8.2, S10 : deux envois simultanés du même numéro et du même corps (deux nonces).
	 * Le second trouve le créneau tenu par ce numéro avant que la requête qui accepte n'ait
	 * écrit l'enregistrement (une requête SQL plus loin). Il relit après de courtes pauses :
	 * dès que l'enregistrement paraît, « déjà reçue », comme un renvoi ordinaire. Un seul
	 * démarrage : celui de la requête qui accepte.
	 */
	public function test_meme_numero_en_cours_de_reception_attend_l_enregistrement_et_repond_duplicate(): void {
		self::assertTrue( ExecutionSlot::acquire( self::NUMERO, 'update_plugin', self::NOW - 1 )['acquired'] );
		$creneau = $this->table->rows[ ExecutionSlot::OPTION ];
		// La requête qui accepte écrit l'enregistrement pendant la deuxième pause.
		$this->pendant_la_pause = function ( int $rang ): void {
			if ( 2 === $rang ) {
				$this->enregistre( $this->acceptee( self::NUMERO, hash( 'sha256', self::CORPS ), self::NOW ) );
			}
		};

		$reponse = $this->controleur()->accept( $this->signee( self::CORPS, 'nonce-du-second-envoi' ) );

		self::assertInstanceOf( WP_REST_Response::class, $reponse );
		self::assertSame( 200, $reponse->get_status() );
		$donnees = $reponse->get_data();
		self::assertTrue( $donnees['duplicate'] ?? null );
		self::assertSame( self::NUMERO, $donnees['command_id'] ?? null );
		self::assertSame( RunState::ACCEPTED, $donnees['state'] ?? null );
		self::assertArrayNotHasKey( 'outcome', $donnees, 'Jamais le résultat à l’acceptation.' );
		self::assertCount( 2, $this->pauses, 'Relu dès la fin de la pause où il paraît : pas d’attente de plus.' );
		self::assertSame( $creneau, $this->table->rows[ ExecutionSlot::OPTION ], 'Le créneau reste à la requête qui accepte.' );
		self::assertSame( [], $this->programmees, 'La requête qui accepte exécute : pas celle-ci.' );
	}

	/**
	 * Pendant l'attente, l'enregistrement paraît sous un autre corps : c'est le conflit,
	 * comme pour un numéro connu (409 `command_id_conflict`), rien n'est exécuté.
	 */
	public function test_meme_numero_en_cours_de_reception_autre_corps_409_conflit(): void {
		self::assertTrue( ExecutionSlot::acquire( self::NUMERO, 'update_plugin', self::NOW - 1 )['acquired'] );
		$this->pendant_la_pause = function ( int $rang ): void {
			if ( 1 === $rang ) {
				$this->enregistre( $this->acceptee( self::NUMERO, str_repeat( 'ef', 32 ), self::NOW ) );
			}
		};

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_command_id_conflict', 409 );
		self::assertCount( 1, $this->pauses );
		self::assertSame( [], $this->programmees );
	}

	/**
	 * Pendant l'attente, la base refuse la relecture : 500 `store_unavailable` aussitôt,
	 * sans attendre davantage, et jamais « numéro neuf ».
	 */
	public function test_meme_numero_en_cours_de_reception_lecture_refusee_500(): void {
		self::assertTrue( ExecutionSlot::acquire( self::NUMERO, 'update_plugin', self::NOW - 1 )['acquired'] );
		$this->pendant_la_pause = function (): void {
			$this->table->read_fails = true;
		};

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_store_unavailable', 500 );
		self::assertCount( 1, $this->pauses );
		self::assertSame( [], $this->programmees );
	}

	/**
	 * La base refuse l'écriture du créneau, ou n'écrit pas l'enregistrement : 500, rien
	 * n'est exécuté.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function ecritures_refusees(): iterable {
		yield 'créneau refusé' => [ 'creneau' ];
		yield 'enregistrement non écrit' => [ 'enregistrement' ];
	}

	#[DataProvider( 'ecritures_refusees' )]
	public function test_base_qui_refuse_l_ecriture_500_et_rien_execute( string $refus ): void {
		if ( 'creneau' === $refus ) {
			$this->table->fails = true;
		} else {
			// Le créneau s'écrit ; l'enregistrement, lui, n'est pas écrit (INSERT IGNORE qui
			// change l'erreur en avertissement).
			$this->table->before_write = function (): void {
				$this->table->before_write = function (): void {
					$this->table->insert_ignore_writes_nothing = true;
				};
			};
		}

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_store_unavailable', 500 );
		self::assertStringContainsString( 'Rien n’a été exécuté par cette demande', $reponse->get_error_message() );
		self::assertNull( RunStore::read( self::NUMERO ) );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows, 'Créneau jamais pris, ou rendu.' );
		self::assertSame( [], $this->programmees );
	}

	/**
	 * Une lecture refusée par la base n'est jamais prise pour un numéro neuf : rien n'est
	 * écrit, rien n'est exécuté, la réponse est ambiguë (500).
	 */
	public function test_lecture_refusee_500_jamais_numero_neuf(): void {
		$this->table->read_fails = true;

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		$this->assertRefus( $reponse, 'g2rd_connector_store_unavailable', 500 );
		self::assertSame( [], $this->table->rows );
		self::assertSame( [], preg_grep( '/^(INSERT|UPDATE|DELETE)/', $this->table->queries ) );
		self::assertSame( [], $this->programmees );
	}

	/**
	 * Avant de prendre le créneau : ramassage (une exécution morte avant de démarrer libère
	 * le site) et purge des comptes rendus de plus de 7 jours.
	 */
	public function test_l_acceptation_ramasse_et_purge_avant_de_prendre_le_creneau(): void {
		// Acceptée il y a 121 s, jamais démarrée : morte entre la réponse et le démarrage.
		$this->enregistre( $this->acceptee( self::AUTRE, str_repeat( 'ab', 32 ), self::NOW - 121 ) );
		self::assertTrue( ExecutionSlot::acquire( self::AUTRE, 'update_core', self::NOW - 121 )['acquired'] );
		// Terminée il y a 8 jours.
		$ancienne = str_repeat( '9', 32 );
		$this->enregistre(
			[
				'command_id'  => $ancienne,
				'command'     => 'optimize_database',
				'body_sha256' => str_repeat( 'cd', 32 ),
				'state'       => RunState::DONE,
				'accepted_at' => self::NOW - 8 * 86400,
				'started_at'  => self::NOW - 8 * 86400,
				'alive_at'    => self::NOW - 8 * 86400,
				'finished_at' => self::NOW - 8 * 86400,
			]
		);

		$reponse = $this->controleur()->accept( $this->requete( self::CORPS ) );

		self::assertSame( 202, $this->statut( $reponse ) );
		self::assertSame( RunState::ABANDONED, RunStore::read( self::AUTRE )['state'] ?? null );
		self::assertNull( RunStore::read( $ancienne ) );
		self::assertSame( self::NUMERO, ExecutionSlot::holder()['command_id'] ?? null );
	}

	private function controleur(): CommandRunsController {
		return new CommandRunsController(
			function ( string $command_id, string $command, ?array $payload ): void {
				$this->programmees[] = [ $command_id, $command, $payload ];
			},
			static fn (): int => self::NOW,
			function ( int $microsecondes ): void {
				$this->pauses[] = $microsecondes;
				if ( null !== $this->pendant_la_pause ) {
					( $this->pendant_la_pause )( count( $this->pauses ) );
				}
			}
		);
	}

	private function requete( string $corps ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Authorization', 'Bearer ' . self::TOKEN );
		$request->set_body( $corps );
		return $request;
	}

	/** Requête signée comme la plateforme : méthode, route REST, corps. */
	private function signee( string $corps, string $nonce ): WP_REST_Request {
		$request    = $this->requete( $corps );
		$horodatage = (string) time();
		$request->set_header( 'X-G2RD-Timestamp', $horodatage );
		$request->set_header( 'X-G2RD-Nonce', $nonce );
		$request->set_header(
			'X-G2RD-Signature',
			'v1=' . RequestSignature::sign( self::TOKEN, 'POST', self::ROUTE, $horodatage, $nonce, $corps )
		);
		return $request;
	}

	/**
	 * Enregistrement d'une exécution acceptée, jamais démarrée.
	 *
	 * @return array<string, mixed>
	 */
	private function acceptee( string $numero, string $empreinte, int $at = self::NOW - 10 ): array {
		return [
			'command_id'  => $numero,
			'command'     => 'update_plugin',
			'body_sha256' => $empreinte,
			'state'       => RunState::ACCEPTED,
			'accepted_at' => $at,
			'started_at'  => null,
			'alive_at'    => null,
			'finished_at' => null,
		];
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private function enregistre( array $record ): void {
		self::assertTrue( RunStore::create( $record ) );
	}

	private function statut( WP_REST_Response|WP_Error $reponse ): int {
		return $reponse instanceof WP_REST_Response ? $reponse->get_status() : (int) ( $reponse->get_error_data()['status'] ?? 0 );
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

	/** Rien n'est enregistré, le créneau n'est pas pris, rien n'est programmé. */
	private function assertRienEnregistre(): void {
		self::assertSame( [], $this->table->rows );
		self::assertSame( [], $this->programmees );
	}
}
