<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class AuthTest extends TestCase {

	private const TOKEN = 'jeton-du-site-0123456789';
	private const ROUTE = '/g2rd/v1/command';
	private const BODY  = '{"command":"clear_cache"}';

	protected function setUp(): void {
		parent::setUp();
		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::TOKEN,
		];
	}

	// ── Non-régression : comportement Bearer historique ─────────────────────────

	public function test_missing_bearer_is_401(): void {
		$result = Auth::require_site_token( new WP_REST_Request( 'POST', self::ROUTE ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_missing_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
		self::assertNull( Auth::last_signature_check() );
	}

	public function test_wrong_bearer_is_403(): void {
		$request = $this->request();
		$request->set_header( 'Authorization', 'Bearer mauvais-jeton' );

		$result = Auth::require_site_token( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_invalid_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 403 ], $result->get_error_data() );
	}

	/**
	 * Un manager qui ne signe pas encore (toutes les versions actuelles) doit
	 * continuer de passer exactement comme avant.
	 */
	public function test_unsigned_request_is_accepted_in_report_mode(): void {
		self::assertTrue( Auth::require_site_token( $this->request() ) );
		self::assertSame( [ 'status' => 'absent' ], Auth::last_signature_check() );
		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	// ── Repli `X-G2RD-Token` ────────────────────────────────────────────────────

	/**
	 * Le 2026-09-25, une règle mod_security a renvoyé 403 sur toute requête REST
	 * portant un en-tête `Authorization`, coupant la plateforme de 18 sites clients.
	 * Les sites répondaient parfaitement à leurs visiteurs ; seul le canal de gestion
	 * était mort, et aucun réenrôlement n'y pouvait rien — la requête n'atteignait
	 * jamais WordPress. `Authorization` est un en-tête que les pare-feu mutualisés
	 * filtrent volontiers ; il fallait une seconde porte.
	 */
	public function test_the_token_is_accepted_from_the_fallback_header(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'X-G2RD-Token', self::TOKEN );
		$request->set_body( self::BODY );
		$request->set_param( 'command', 'clear_cache' );

		self::assertTrue( Auth::require_site_token( $request ) );
	}

	/** Le repli n'affaiblit rien : un mauvais jeton reste refusé, même par cette voie. */
	public function test_a_wrong_token_in_the_fallback_header_is_still_refused(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'X-G2RD-Token', 'mauvais-jeton' );
		$request->set_body( self::BODY );

		$result = Auth::require_site_token( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_invalid_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 403 ], $result->get_error_data() );
	}

	/**
	 * Un `Authorization` présent et VALIDE garde la main : le repli ne sert que
	 * lorsque l'en-tête standard est absent ou inexploitable.
	 */
	public function test_a_valid_authorization_header_wins_over_the_fallback(): void {
		$request = $this->request();
		$request->set_header( 'X-G2RD-Token', 'mauvais-jeton' );

		self::assertTrue( Auth::require_site_token( $request ) );
	}

	/**
	 * Le garde-fou qui compte : un `Authorization` porteur d'un MAUVAIS jeton ne doit
	 * pas pouvoir être rattrapé par un repli valide. Sinon un attaquant qui contrôle
	 * un en-tête personnalisé contournerait l'en-tête standard.
	 */
	public function test_a_bad_bearer_cannot_be_rescued_by_a_valid_fallback(): void {
		$request = $this->request();
		$request->set_header( 'Authorization', 'Bearer mauvais-jeton' );
		$request->set_header( 'X-G2RD-Token', self::TOKEN );

		$result = Auth::require_site_token( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_invalid_token', $result->get_error_code() );
	}

	/** Un repli vide ne vaut pas mieux qu'un en-tête absent. */
	public function test_an_empty_fallback_header_is_a_missing_token(): void {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'X-G2RD-Token', '   ' );

		$result = Auth::require_site_token( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_missing_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
	}

	// ── Politique `report` ──────────────────────────────────────────────────────

	public function test_valid_signature_is_ok(): void {
		self::assertTrue( Auth::require_site_token( $this->signed_request() ) );
		self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );
	}

	public function test_invalid_signature_is_accepted_but_counted_in_report_mode(): void {
		$request = $this->signed_request();
		$request->set_body( '{"command":"update_core"}' ); // corps altéré après signature

		self::assertTrue( Auth::require_site_token( $request ) );
		self::assertSame( 'signature_invalid', Auth::last_signature_check()['code'] ?? null );
		self::assertSame( 1, SignatureState::stats()['failed_count'] );
	}

	public function test_replay_is_detected(): void {
		$nonce = bin2hex( random_bytes( 16 ) );

		self::assertTrue( Auth::require_site_token( $this->signed_request( $nonce ) ) );
		self::assertSame( 'ok', Auth::last_signature_check()['status'] );

		self::assertTrue( Auth::require_site_token( $this->signed_request( $nonce ) ) );
		self::assertSame( 'signature_replayed', Auth::last_signature_check()['code'] ?? null );
	}

	/**
	 * WordPress appelle le permission_callback deux fois pour la MÊME requête
	 * (dispatch, puis rest_send_allow_header pour l'en-tête Allow). Observé sur le
	 * banc de test : sans mémoire par requête, le second passage comptait un faux
	 * rejeu — 9 échecs comptés pour 3 réels.
	 */
	public function test_second_permission_check_of_the_same_request_is_not_a_replay(): void {
		$request = $this->signed_request();

		self::assertTrue( Auth::require_site_token( $request ) );
		self::assertTrue( Auth::require_site_token( $request ) );

		self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );
		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	public function test_a_real_failure_is_counted_once_even_if_checked_twice(): void {
		$request = $this->signed_request();
		$request->set_body( '{"command":"update_core"}' );

		Auth::require_site_token( $request );
		Auth::require_site_token( $request );

		self::assertSame( 1, SignatureState::stats()['failed_count'] );
	}

	/**
	 * Une signature invalide ne doit pas pouvoir remplir le registre des nonces.
	 */
	public function test_invalid_signature_does_not_store_its_nonce(): void {
		$request = $this->signed_request( str_repeat( 'ab', 16 ) );
		$request->set_header( 'X-G2RD-Signature', 'v1=' . str_repeat( '0', 64 ) );
		Auth::require_site_token( $request );

		self::assertSame( [], $this->options[ SignatureState::OPTION_KEY ]['nonces'] ?? [] );
	}

	public function test_verification_error_never_breaks_a_request_in_report_mode(): void {
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'base de données indisponible' );
			}
		);

		self::assertTrue( Auth::require_site_token( $this->signed_request() ) );
		self::assertSame( 'signature_error', Auth::last_signature_check()['code'] ?? null );
	}

	public function test_result_of_a_previous_request_does_not_leak(): void {
		Auth::require_site_token( $this->signed_request() );
		Auth::require_site_token( new WP_REST_Request( 'POST', self::ROUTE ) ); // sans Bearer
		self::assertNull( Auth::last_signature_check() );
	}

	// ── Politique `required` ────────────────────────────────────────────────────

	public function test_required_policy_refuses_unsigned_request(): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = 'required';

		$result = Auth::require_site_token( $this->request() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_signature_missing', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
	}

	public function test_required_policy_accepts_valid_signature(): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = 'required';
		self::assertTrue( Auth::require_site_token( $this->signed_request() ) );
	}

	public function test_required_policy_reports_clock_skew_with_server_time(): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = 'required';

		$result = Auth::require_site_token( $this->signed_request( null, time() - 3600 ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_clock_skew', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
		self::assertEqualsWithDelta( time(), $result->get_error_data()['server_time'], 5 );
	}

	// ── K2 : registre anti-rejeu borné par le temps ─────────────────────────────

	/**
	 * K2 — avant le correctif, un registre de plus de 500 nonces vivants évinçait
	 * les plus anciens : le nonce de la requête tout juste acceptée pouvait sortir
	 * du registre et redevenir rejouable.
	 */
	public function test_replay_after_heavy_volume_is_detected(): void {
		$nonce = bin2hex( random_bytes( 16 ) );
		self::assertTrue( Auth::require_site_token( $this->signed_request( $nonce ) ) );
		self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );

		// 600 autres requêtes signées arrivent dans la foulée (une seconde plus tard).
		for ( $i = 1; $i <= 600; $i++ ) {
			SignatureState::remember_nonce( 'suivant' . $i, time() + 1 );
		}

		self::assertTrue( Auth::require_site_token( $this->signed_request( $nonce ) ) );
		self::assertSame( 'signature_replayed', Auth::last_signature_check()['code'] ?? null );
	}

	/** Politique `report` (22 sites sur 23) : un registre plein ne refuse rien, il est signalé. */
	public function test_report_mode_accepts_when_registry_is_full_and_reports_it(): void {
		$this->fill_registry( SignatureState::MAX_LIVE_NONCES );

		self::assertTrue( Auth::require_site_token( $this->signed_request() ) );
		self::assertSame( RequestSignature::CODE_NONCE_STORE_FULL, Auth::last_signature_check()['code'] ?? null );
		self::assertSame( 1, SignatureState::stats()['failed_count'] );
		self::assertSame( 'nonce_store_full', SignatureState::stats()['last_code'] );
	}

	public function test_required_mode_refuses_explicitly_when_registry_is_full(): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = 'required';
		$this->fill_registry( SignatureState::MAX_LIVE_NONCES );

		$result = Auth::require_site_token( $this->signed_request() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_nonce_store_full', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
		self::assertStringContainsString( 'registre anti-rejeu', $result->get_error_message() );
		self::assertStringContainsString( 'Réessayez dans quelques minutes', $result->get_error_message() );
	}

	public function test_signed_only_command_is_refused_when_registry_is_full_even_in_report(): void {
		$this->fill_registry( SignatureState::MAX_LIVE_NONCES );
		$request = $this->signed_request();
		$request->set_param( 'command', 'rollback_plugin' );

		$result = Auth::require_site_token( $request );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_nonce_store_full', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	/** Les autres refus gardent leur message d'avant. */
	public function test_other_refusals_keep_the_generic_message(): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = 'required';

		$result = Auth::require_site_token( $this->request() );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'Signature de la requête absente ou invalide.', $result->get_error_message() );
	}

	// ── Réglage ─────────────────────────────────────────────────────────────────

	public function test_policy_defaults_to_report(): void {
		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
	}

	public function test_unknown_policy_value_falls_back_to_report(): void {
		\Brain\Monkey\Functions\when( 'esc_url_raw' )->returnArg();
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();

		self::assertSame( 'report', Settings::sanitize( [ 'signature_policy' => 'nimporte' ] )['signature_policy'] );
		self::assertSame( 'required', Settings::sanitize( [ 'signature_policy' => 'required' ] )['signature_policy'] );
		// Une sauvegarde de la page d'admin qui n'envoie pas la clé ne la modifie pas.
		self::assertSame( 'report', Settings::sanitize( [ 'heartbeat_enabled' => false ] )['signature_policy'] );
	}

	/**
	 * Registre prérempli de `$count` nonces vivants (vus à l'instant).
	 */
	private function fill_registry( int $count ): void {
		$nonces = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$nonces[ 'vivant' . $i ] = time();
		}
		$this->options[ SignatureState::OPTION_KEY ] = [ 'nonces' => $nonces ];
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Authorization', 'Bearer ' . self::TOKEN );
		$request->set_body( self::BODY );
		$request->set_param( 'command', 'clear_cache' );
		return $request;
	}

	private function signed_request( ?string $nonce = null, ?int $timestamp = null ): WP_REST_Request {
		$nonce     = $nonce ?? bin2hex( random_bytes( 16 ) );
		$timestamp = (string) ( $timestamp ?? time() );

		$request = $this->request();
		$request->set_header( 'X-G2RD-Timestamp', $timestamp );
		$request->set_header( 'X-G2RD-Nonce', $nonce );
		$request->set_header(
			'X-G2RD-Signature',
			'v1=' . RequestSignature::sign( self::TOKEN, 'POST', self::ROUTE, $timestamp, $nonce, self::BODY )
		);
		return $request;
	}
}
