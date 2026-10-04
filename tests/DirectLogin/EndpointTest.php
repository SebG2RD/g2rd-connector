<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use Brain\Monkey\Functions;
use G2RD\Connector\DirectLogin\Endpoint;
use G2RD\Connector\DirectLogin\Refusal;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * `admin-ajax.php?action=g2rd_login&ticket=…` (spec connexion WordPress §5.5) :
 * session ouverte sans `wp_login`, redirection vers le tableau de bord, page 403
 * explicite sinon, réponses jamais mises en cache, événement `direct_login`
 * envoyé au manager après la réponse.
 */
final class EndpointTest extends TestCase {

	private const LOGIN_URL = 'https://site.test/acces-equipe';
	private const ADMIN_URL = 'https://site.test/wp-admin/';

	/** @var list<array<int, mixed>> */
	private array $cookies = [];

	private int $current_user = 0;

	private int $nocache = 0;

	/** @var list<array{0: string, 1: mixed}> */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => TicketFactory::SITE_ID,
			'site_token' => TicketFactory::TOKEN,
		];

		$admin             = new \WP_User();
		$admin->ID         = 1;
		$admin->user_login = 'admin-g2rd';

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( $text ) );
		Functions\when( 'nocache_headers' )->alias(
			function (): void {
				++$this->nocache;
			}
		);
		Functions\when( 'wp_login_url' )->justReturn( self::LOGIN_URL );
		Functions\when( 'admin_url' )->justReturn( self::ADMIN_URL );
		Functions\when( 'is_ssl' )->justReturn( true );
		// Usage unique : UsedTickets écrit le nonce par un INSERT atomique ($wpdb).
		$GLOBALS['wpdb'] = new FakeWpdb(
			fn (): array => array_map( 'strval', array_keys( $this->options ) ),
			function ( string $name, string $value ): void {
				$this->options[ $name ] = $value;
			}
		);
		Functions\when( 'get_userdata' )->alias( static fn ( int $id ) => 1 === $id ? $admin : false );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $id ): void {
				$this->current_user = $id;
			}
		);
		Functions\when( 'wp_set_auth_cookie' )->alias(
			function ( ...$args ): void {
				$this->cookies[] = $args;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $message = '', $title = '', $args = [] ): void {
				throw new ResponseEnded( 'die', (string) $message, (string) $title, (array) $args );
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $location ): void {
				throw new ResponseEnded( 'redirect', $location );
			}
		);
		// Envoi de l'événement au manager (ManagerClient::send_event).
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				$this->posts[] = [ $url, json_decode( (string) $args['body'], true ) ];
				return [ 'response' => [ 'code' => 202 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 202 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		// Un événement resté en attente d'un test précédent ne doit pas fausser celui-ci.
		Endpoint::report_pending();
		$this->posts = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		unset( $_GET['ticket'] );
		parent::tearDown();
	}

	public function test_le_point_d_entree_repond_avec_et_sans_session(): void {
		$endpoint = new Endpoint();
		$endpoint->register();

		self::assertNotFalse( has_action( 'wp_ajax_nopriv_g2rd_login', [ $endpoint, 'respond' ] ) );
		self::assertNotFalse( has_action( 'wp_ajax_g2rd_login', [ $endpoint, 'respond' ] ) );
	}

	public function test_un_ticket_valide_ouvre_la_session_et_redirige_vers_le_tableau_de_bord(): void {
		$_GET['ticket'] = TicketFactory::make( time() + 60 );

		$ended = $this->respond();

		self::assertSame( 'redirect', $ended->kind );
		self::assertSame( self::ADMIN_URL, $ended->target );
		self::assertSame( 1, $this->current_user );
		self::assertSame( [ [ 1, false, true ] ], $this->cookies, 'Session non persistante, cookie sécurisé sur un site en https.' );
	}

	/** Spec §5.5 et §9 : sinon la double authentification du site annulerait la connexion. */
	public function test_le_hook_wp_login_n_est_pas_declenche(): void {
		$_GET['ticket'] = TicketFactory::make( time() + 60 );

		$this->respond();

		self::assertSame( 0, did_action( 'wp_login' ) );
	}

	public function test_l_evenement_direct_login_part_apres_la_reponse(): void {
		$_GET['ticket'] = TicketFactory::make( time() + 60 );

		$this->respond();

		self::assertSame( [], $this->posts, 'Rien n\'est envoyé avant la fin de la réponse.' );
		self::assertNotFalse( has_action( 'shutdown', [ Endpoint::class, 'report_pending' ] ) );

		Endpoint::report_pending();

		self::assertCount( 1, $this->posts );
		self::assertSame( 'https://wp-manager.g2rd.fr/api/agent/sites/42/events', $this->posts[0][0] );
		self::assertSame( 'direct_login', $this->posts[0][1]['type'] );
		self::assertSame(
			[
				'user_login'      => 'admin-g2rd',
				'wp_user_id'      => 1,
				'manager_user_id' => 7,
			],
			$this->posts[0][1]['context']
		);
	}

	public function test_sans_connexion_ouverte_aucun_evenement_n_est_envoye(): void {
		Endpoint::report_pending();

		self::assertSame( [], $this->posts );
	}

	public function test_un_ticket_expire_affiche_une_page_explicite_en_403(): void {
		$_GET['ticket'] = TicketFactory::make( time() - 31 );

		$ended = $this->respond();

		self::assertSame( 'die', $ended->kind );
		self::assertSame( esc_html( Refusal::message( Refusal::EXPIRED ) ), $ended->target );
		self::assertSame( esc_html( Refusal::title() ), $ended->title );
		self::assertSame( 403, $ended->args['response'] );
		self::assertSame( esc_url( self::LOGIN_URL ), $ended->args['link_url'] );
		self::assertSame( esc_html__( 'Aller à la page de connexion', 'g2rd-connector' ), $ended->args['link_text'] );
		self::assertSame( [], $this->cookies );
		self::assertSame( 0, $this->current_user );
	}

	/**
	 * Sur un site à connexion masquée (WPS Hide Login, Solid Security…),
	 * wp_login_url() rend l'adresse cachée : elle ne doit jamais être montrée à qui
	 * appelle le point d'entrée sans ticket authentique.
	 */
	public function test_sans_ticket_la_page_403_ne_revele_pas_la_page_de_connexion(): void {
		$ended = $this->respond();

		self::assertSame( 'die', $ended->kind );
		self::assertSame( 403, $ended->args['response'] );
		self::assertArrayNotHasKey( 'link_url', $ended->args );
		self::assertArrayNotHasKey( 'link_text', $ended->args );
	}

	public function test_un_ticket_mal_signe_ne_revele_pas_la_page_de_connexion(): void {
		$_GET['ticket'] = 'v1.abc.def';

		$ended = $this->respond();

		self::assertSame( esc_html( Refusal::message( Refusal::INVALID ) ), $ended->target );
		self::assertSame( 403, $ended->args['response'] );
		self::assertArrayNotHasKey( 'link_url', $ended->args );
		self::assertArrayNotHasKey( 'link_text', $ended->args );
	}

	/** Signature valide (ticket authentique, case décochée) : le lien reste proposé. */
	public function test_un_ticket_authentique_refuse_garde_le_lien_vers_la_page_de_connexion(): void {
		$this->options[ Settings::OPTION_KEY ]['allow_direct_login'] = false;
		$_GET['ticket'] = TicketFactory::make( time() + 60 );

		$ended = $this->respond();

		self::assertSame( esc_url( self::LOGIN_URL ), $ended->args['link_url'] );
	}

	public function test_la_page_d_erreur_est_la_page_html_de_wordpress_et_non_du_texte_brut(): void {
		$_GET['ticket'] = 'v1.abc.def';

		$this->respond();

		self::assertNotFalse( has_filter( 'wp_die_ajax_handler', [ Endpoint::class, 'html_die_handler' ] ) );
		self::assertSame( '_default_wp_die_handler', Endpoint::html_die_handler() );
	}

	public function test_la_case_decochee_affiche_son_message(): void {
		$this->options[ Settings::OPTION_KEY ]['allow_direct_login'] = false;
		$_GET['ticket'] = TicketFactory::make( time() + 60 );

		$ended = $this->respond();

		self::assertSame( esc_html( Refusal::message( Refusal::DISABLED ) ), $ended->target );
		self::assertSame( [], $this->cookies );
	}

	public function test_sans_ticket_le_lien_n_est_pas_valide(): void {
		self::assertSame( esc_html( Refusal::message( Refusal::INVALID ) ), $this->respond()->target );
	}

	public function test_un_ticket_envoye_en_tableau_est_refuse_sans_avertissement(): void {
		$_GET['ticket'] = [ 'v1.abc.def' ];

		self::assertSame( esc_html( Refusal::message( Refusal::INVALID ) ), $this->respond()->target );
	}

	public function test_aucune_reponse_n_est_mise_en_cache(): void {
		$_GET['ticket'] = 'v1.abc.def';
		$this->respond();

		$_GET['ticket'] = TicketFactory::make( time() + 60 );
		$this->respond();

		self::assertSame( 2, $this->nocache );
		self::assertSame( 2, did_action( 'litespeed_control_set_nocache' ) );
	}

	private function respond(): ResponseEnded {
		try {
			( new Endpoint() )->respond();
		} catch ( ResponseEnded $ended ) {
			return $ended;
		}
		self::fail( 'La réponse aurait dû se terminer par une redirection ou une page d\'erreur.' );
	}
}
