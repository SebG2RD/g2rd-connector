<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Plugin;
use G2RD\Connector\Rest\CommandRunsController;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Les réponses des deux routes d'exécution en arrière-plan (`POST /g2rd/v1/command-runs`,
 * `GET /g2rd/v1/command-runs/<numéro>`) ne sont jamais mises en cache : un cache de page
 * (LiteSpeed) qui servirait un état figé ferait croire à la plateforme qu'une commande
 * n'avance plus, ou qu'elle est encore « reçue » une fois finie.
 *
 * Elles le tiennent du filtre anti-cache de tout l'espace `g2rd/v1`
 * (Plugin::prevent_rest_cache(), `rest_pre_dispatch`, priorité 10), comme l'inventaire et
 * `/command` : il passe avant l'authentification, donc vaut aussi pour les refus (401,
 * 413 du garde de taille, priorité 5). La réponse « reçue » (202), écrite par
 * AfterResponseRunner, garde ces en-têtes : il n'en écrit aucun.
 *
 * header() et headers_sent() sont simulés (patchwork.json) : en ligne de commande, PHP ne
 * garde aucun en-tête, et les tient pour partis dès la première sortie de PHPUnit.
 */
final class CommandRunsNoCacheTest extends TestCase {

	private const NUMERO = '0123456789abcdef0123456789abcdef';

	/** En-têtes posés par le filtre anti-cache, dans l'ordre. */
	private const EN_TETES_ANTI_CACHE = [
		'X-LiteSpeed-Cache-Control: no-cache',
		'Cache-Control: no-cache, no-store, must-revalidate, max-age=0',
	];

	/** @var list<string> En-têtes posés par header(). */
	private array $headers = [];

	private bool $headers_sent = false;

	protected function setUp(): void {
		parent::setUp();
		$this->headers      = [];
		$this->headers_sent = false;
		Functions\when( 'headers_sent' )->alias( fn (): bool => $this->headers_sent );
		Functions\when( 'header' )->alias(
			function ( string $header ): void {
				$this->headers[] = $header;
			}
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		return [
			'acceptation' => [ 'POST', '/g2rd/v1/command-runs' ],
			'lecture'     => [ 'GET', '/g2rd/v1/command-runs/' . self::NUMERO ],
		];
	}

	#[DataProvider( 'routes' )]
	public function test_les_reponses_des_deux_routes_interdisent_la_mise_en_cache( string $method, string $route ): void {
		$result = Plugin::instance()->prevent_rest_cache( null, new WP_REST_Server(), new WP_REST_Request( $method, $route ) );

		self::assertNull( $result, 'Le filtre ne décide pas de la réponse : la route la traite.' );
		self::assertSame( self::EN_TETES_ANTI_CACHE, $this->headers );
		self::assertSame( 1, did_action( 'litespeed_control_set_nocache' ), 'Désactivation officielle de LiteSpeed Cache pour cette réponse.' );
	}

	/**
	 * WordPress compare les routes sans tenir compte de la casse
	 * (WP_REST_Server::match_request_to_handler()) : `/G2RD/v1/Command-Runs` est servie par
	 * les mêmes routes, et ne doit pas davantage être mise en cache.
	 */
	#[DataProvider( 'routes' )]
	public function test_la_route_ecrite_dans_une_autre_casse_n_est_pas_davantage_mise_en_cache( string $method, string $route ): void {
		Plugin::instance()->prevent_rest_cache( null, new WP_REST_Server(), new WP_REST_Request( $method, strtoupper( substr( $route, 0, 9 ) ) . ucwords( substr( $route, 9 ), '/-' ) ) );

		self::assertSame( self::EN_TETES_ANTI_CACHE, $this->headers );
		self::assertSame( 1, did_action( 'litespeed_control_set_nocache' ) );
	}

	/** Le refus 413 du garde de taille, décidé avant le filtre anti-cache, le reçoit aussi. */
	public function test_le_refus_d_un_corps_trop_gros_n_est_pas_mis_en_cache(): void {
		$request = new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' );
		$request->set_body( str_repeat( 'x', CommandRunsController::MAX_BODY_BYTES + 1 ) );

		$refus = CommandRunsController::guard_body_size( null, new WP_REST_Server(), $request );
		self::assertInstanceOf( WP_Error::class, $refus );
		self::assertSame( 413, $refus->get_error_data()['status'] ?? null );

		self::assertSame( $refus, Plugin::instance()->prevent_rest_cache( $refus, new WP_REST_Server(), $request ), 'Le refus passe tel quel.' );
		self::assertSame( self::EN_TETES_ANTI_CACHE, $this->headers );
	}

	/** En-têtes déjà partis : aucun header() (avertissement de PHP), LiteSpeed reste prévenu. */
	public function test_en_tetes_deja_partis_seul_litespeed_est_prevenu(): void {
		$this->headers_sent = true;

		Plugin::instance()->prevent_rest_cache( null, new WP_REST_Server(), new WP_REST_Request( 'GET', '/g2rd/v1/command-runs/' . self::NUMERO ) );

		self::assertSame( [], $this->headers );
		self::assertSame( 1, did_action( 'litespeed_control_set_nocache' ) );
	}

	/** Témoin : les routes des autres extensions et de WordPress ne sont pas touchées. */
	public function test_hors_de_l_espace_du_connecteur_rien_n_est_pose(): void {
		foreach ( [ '/wp/v2/posts', '/g2rd-autre/v1/command-runs', '/' ] as $route ) {
			Plugin::instance()->prevent_rest_cache( null, new WP_REST_Server(), new WP_REST_Request( 'GET', $route ) );
		}

		self::assertSame( [], $this->headers );
		self::assertSame( 0, did_action( 'litespeed_control_set_nocache' ) );
	}
}
