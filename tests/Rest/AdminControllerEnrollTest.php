<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Rest\AdminController;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Enrôlement refusé, depuis le panneau React (POST /g2rd/v1/admin/enroll).
 *
 * - L'erreur dit si le manager a répondu lui-même : il répond 403, avec un message
 *   JSON, à une invitation invalide ou expirée ; un 403 sans ce message vient d'un
 *   CDN ou d'un pare-feu en chemin (incident IPv6 du 25/09), où une nouvelle
 *   invitation ne servirait à rien. Le panneau en tire sa cause probable
 *   (assets/admin/src/connection.ts, enrollFailureKind).
 * - L'adresse et les interrupteurs sont enregistrés avant la tentative : l'erreur
 *   porte ces réglages tels que retenus (« boot »), pour que le panneau, et la
 *   copie que garde l'onglet de la console, ne reviennent pas aux anciens.
 */
final class AdminControllerEnrollTest extends TestCase {

	/**
	 * Réponse simulée de wp_remote_post vers le manager.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $reponse_manager = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.3' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $valeur ): bool => $valeur instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );
		Functions\when( 'wp_remote_post' )->alias( fn () => $this->reponse_manager );
	}

	public function test_un_403_du_cdn_n_est_pas_une_reponse_du_manager(): void {
		$this->reponse_manager = $this->http( 403, '<html><body>403 Forbidden</body></html>' );

		$data = $this->echec( 'g2rd_connector_enroll_failed' );

		self::assertSame( 403, $data['status'] );
		self::assertFalse( $data['manager_answered'] );
	}

	public function test_une_invitation_expiree_est_une_reponse_du_manager(): void {
		$this->reponse_manager = $this->http( 403, (string) json_encode( [ 'message' => "Token d'invitation expiré." ] ) );

		$data = $this->echec( 'g2rd_connector_enroll_failed' );

		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['manager_answered'] );
	}

	public function test_un_manager_injoignable_rend_502(): void {
		$this->reponse_manager = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		$data = $this->echec( 'http_request_failed' );

		self::assertSame( 502, $data['status'] );
		self::assertFalse( $data['manager_answered'] );
	}

	/**
	 * Les réglages envoyés avec la demande sont enregistrés malgré l'échec, et
	 * l'erreur les porte tels que retenus.
	 */
	public function test_l_echec_porte_les_reglages_enregistres(): void {
		$this->reponse_manager = $this->http( 403, 'Forbidden' );

		$data = $this->echec( 'g2rd_connector_enroll_failed' );

		self::assertSame( 'https://wp-manager.g2rd.fr', $this->options[ Settings::OPTION_KEY ]['manager_url'] );
		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['force_ipv4_to_manager'] );
		self::assertIsArray( $data['boot'] );
		self::assertSame( 'https://wp-manager.g2rd.fr', $data['boot']['managerUrl'] );
		self::assertTrue( $data['boot']['forceIpv4ToManager'] );
		self::assertFalse( $data['boot']['heartbeatEnabled'] );
		self::assertFalse( $data['boot']['enrolled'] );
	}

	/**
	 * Enrôlement tenté depuis le panneau, refusé : données de l'erreur rendue.
	 *
	 * @return array<string, mixed>
	 */
	private function echec( string $code_attendu ): array {
		$request = new WP_REST_Request( 'POST', '/g2rd/v1/admin/enroll' );
		$request->set_param( 'manager_url', 'https://wp-manager.g2rd.fr' );
		$request->set_param( 'invitation_token', 'invitation' );
		$request->set_param( 'heartbeat_enabled', false );
		$request->set_param( 'force_ipv4_to_manager', true );

		$result = ( new AdminController() )->handle_enroll( $request );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( $code_attendu, $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function http( int $code, string $body ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => $body,
		];
	}
}
