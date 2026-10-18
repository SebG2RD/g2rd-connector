<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use G2RD\Connector\Rest\Auth;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;
use WP_REST_Request;

/**
 * Accès aux deux routes d'exécution en arrière-plan (`POST /g2rd/v1/command-runs`,
 * `GET /g2rd/v1/command-runs/<numéro>`) : le jeton ET une signature valide, quelle que
 * soit la politique du site (§ 6.1 de la spécification). Le renvoi à l'identique d'une
 * acceptation (même nonce, signature valide) est toléré et marqué, pour que la route
 * réponde « déjà reçue » sans rien relancer (§ 3.3) ; le renvoi d'une lecture est refusé.
 *
 * `/command` n'est pas concerné : AuthTest, inchangé, garde son comportement.
 */
final class CommandRunsAuthTest extends TestCase {

	private const TOKEN       = 'jeton-du-site-0123456789';
	private const NUMERO      = '0123456789abcdef0123456789abcdef';
	private const ACCEPTATION = '/g2rd/v1/command-runs';
	private const LECTURE     = '/g2rd/v1/command-runs/' . self::NUMERO;
	private const CORPS       = '{"command_id":"' . self::NUMERO . '","command":"update_plugin","payload":{"plugin":"akismet/akismet.php"}}';

	protected function setUp(): void {
		parent::setUp();
		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::TOKEN,
		];
	}

	/**
	 * La politique par défaut, `report`, laisse passer une requête non signée sur `/command`
	 * (22 sites sur 23 en dépendent) ; les deux routes nouvelles, elles, la refusent.
	 */
	public function test_sans_signature_refuse_meme_en_politique_report(): void {
		self::assertSame( 'report', Settings::get( 'signature_policy' ) );

		foreach ( [ $this->requete( 'POST', self::ACCEPTATION, self::CORPS ), $this->requete( 'GET', self::LECTURE, '' ) ] as $request ) {
			$result = Auth::require_signed_run_access( $request );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'g2rd_connector_signature_missing', $result->get_error_code() );
			self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
			self::assertSame( 'Signature de la requête absente ou invalide.', $result->get_error_message() );
			// Le contrôle reste remonté (en-tête X-G2RD-Signature-Check, lu par le banc).
			self::assertSame( [ 'status' => 'absent' ], Auth::last_signature_check() );
			self::assertFalse( Auth::is_replayed_acceptance( $request ) );
		}

		// Témoin : la même requête non signée passe toujours par le contrôle de `/command`.
		self::assertTrue( Auth::require_site_token( $this->requete( 'POST', self::ACCEPTATION, self::CORPS ) ) );
	}

	/**
	 * La signature lie la méthode, la route (avec le numéro) et le corps : une acceptation
	 * signée pour un numéro ne vaut ni pour un autre corps, ni pour la lecture.
	 */
	public function test_signature_invalide_refusee(): void {
		$autre_corps = $this->acceptation();
		$autre_corps->set_body( str_replace( self::NUMERO, str_repeat( 'f', 32 ), self::CORPS ) );

		$result = Auth::require_signed_run_access( $autre_corps );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_signature_invalid', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
		self::assertSame( 'signature_invalid', Auth::last_signature_check()['code'] ?? null );

		// Les en-têtes signés d'une acceptation, présentés sur la lecture.
		$acceptation = $this->acceptation();
		$lecture     = $this->requete( 'GET', self::LECTURE, '' );
		foreach ( [ 'X-G2RD-Timestamp', 'X-G2RD-Nonce', 'X-G2RD-Signature' ] as $header ) {
			$lecture->set_header( $header, (string) $acceptation->get_header( $header ) );
		}

		$result = Auth::require_signed_run_access( $lecture );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_signature_invalid', $result->get_error_code() );

		// Les échecs sont comptés, comme sur `/command`.
		self::assertSame( 2, SignatureState::stats()['failed_count'] );
	}

	public function test_horloge_decalee_refusee_avec_l_heure_du_site(): void {
		$result = Auth::require_signed_run_access( $this->acceptation( null, time() - 3600 ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_clock_skew', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
		self::assertEqualsWithDelta( time(), $result->get_error_data()['server_time'], 5 );
	}

	public function test_signature_valide_acceptee(): void {
		$acceptation = $this->acceptation();
		self::assertTrue( Auth::require_signed_run_access( $acceptation ) );
		self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );
		self::assertFalse( Auth::is_replayed_acceptance( $acceptation ) );

		self::assertTrue( Auth::require_signed_run_access( $this->lecture() ) );
		self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );

		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	/** Le jeton se présente comme sur `/command` : `Authorization`, ou le repli `X-G2RD-Token`. */
	public function test_jeton_par_l_en_tete_de_repli_accepte(): void {
		$request = new WP_REST_Request( 'POST', self::ACCEPTATION );
		$request->set_header( 'X-G2RD-Token', self::TOKEN );
		$request->set_body( self::CORPS );

		self::assertTrue( Auth::require_signed_run_access( $this->signer( $request ) ) );
	}

	/**
	 * Le cas du CDN : la même requête, renvoyée à l'identique. Elle est tolérée quelle que
	 * soit la politique du site, pour que la route d'acceptation réponde « déjà reçue »
	 * (état sans résultat) au lieu d'un refus que la plateforme ne saurait pas lire.
	 */
	#[DataProvider( 'politiques' )]
	public function test_renvoi_d_une_acceptation_tolere_et_marque( string $politique ): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = $politique;
		$nonce = bin2hex( random_bytes( 16 ) );

		$original = $this->acceptation( $nonce );
		self::assertFalse( Auth::is_replayed_acceptance( $original ), 'Une requête jamais contrôlée n’est pas marquée.' );
		self::assertTrue( Auth::require_signed_run_access( $original ) );
		self::assertFalse( Auth::is_replayed_acceptance( $original ) );

		$renvoi = $this->acceptation( $nonce );
		self::assertTrue( Auth::require_signed_run_access( $renvoi ) );
		self::assertTrue( Auth::is_replayed_acceptance( $renvoi ) );
		self::assertFalse( Auth::is_replayed_acceptance( $original ), 'Le marquage ne déborde pas sur une autre requête.' );

		// Le renvoi reste visible : remonté à la plateforme et compté au diagnostic du site.
		self::assertSame( 'signature_replayed', Auth::last_signature_check()['code'] ?? null );
		self::assertSame( 1, SignatureState::stats()['failed_count'] );
		self::assertSame( 'signature_replayed', SignatureState::stats()['last_code'] );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function politiques(): iterable {
		yield 'report' => [ 'report' ];
		yield 'required' => [ 'required' ];
	}

	/**
	 * La lecture ne déclenche rien, mais un renvoi n'a aucune raison d'être servi. La
	 * tolérance tient à la méthode ET à la route : un POST sur la route avec le numéro
	 * n'en profite pas davantage.
	 */
	public function test_renvoi_d_une_lecture_refuse(): void {
		foreach ( [ 'GET', 'POST' ] as $methode ) {
			$nonce = bin2hex( random_bytes( 16 ) );
			self::assertTrue( Auth::require_signed_run_access( $this->signer( $this->requete( $methode, self::LECTURE, '' ), $nonce ) ) );

			$renvoi = $this->signer( $this->requete( $methode, self::LECTURE, '' ), $nonce );
			$result = Auth::require_signed_run_access( $renvoi );

			self::assertInstanceOf( WP_Error::class, $result, $methode );
			self::assertSame( 'g2rd_connector_signature_replayed', $result->get_error_code() );
			self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
			self::assertFalse( Auth::is_replayed_acceptance( $renvoi ) );
		}
	}

	/**
	 * `signature_replayed` n'est rendu qu'après une signature reconnue valide : le nonce
	 * d'une vraie requête, repris avec une signature fausse, ne profite pas de la
	 * tolérance du renvoi.
	 */
	public function test_un_nonce_rejoue_avec_une_signature_fausse_reste_refuse(): void {
		$nonce = bin2hex( random_bytes( 16 ) );
		self::assertTrue( Auth::require_signed_run_access( $this->acceptation( $nonce ) ) );

		// Même nonce, corps changé (un autre numéro) : la signature ne correspond plus.
		$autre_numero = $this->acceptation( $nonce );
		$autre_numero->set_body( str_replace( self::NUMERO, str_repeat( 'e', 32 ), self::CORPS ) );

		// Même nonce, signature fabriquée.
		$signature_fabriquee = $this->acceptation( $nonce );
		$signature_fabriquee->set_header( 'X-G2RD-Signature', 'v1=' . str_repeat( '0', 64 ) );

		foreach ( [ $autre_numero, $signature_fabriquee ] as $request ) {
			$result = Auth::require_signed_run_access( $request );

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'g2rd_connector_signature_invalid', $result->get_error_code() );
			self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
			self::assertFalse( Auth::is_replayed_acceptance( $request ) );
		}
	}

	public function test_jeton_absent_401_jeton_faux_403(): void {
		// Un contrôle précédent ne doit pas être remonté avec le refus suivant.
		self::assertTrue( Auth::require_signed_run_access( $this->acceptation() ) );

		$sans_jeton = $this->signer( new WP_REST_Request( 'POST', self::ACCEPTATION ) );
		$result     = Auth::require_signed_run_access( $sans_jeton );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_missing_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 401 ], $result->get_error_data() );
		self::assertNull( Auth::last_signature_check() );
		self::assertFalse( Auth::is_replayed_acceptance( $sans_jeton ) );

		$jeton_faux = $this->acceptation();
		$jeton_faux->set_header( 'Authorization', 'Bearer mauvais-jeton' );
		$result = Auth::require_signed_run_access( $jeton_faux );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'g2rd_connector_invalid_token', $result->get_error_code() );
		self::assertSame( [ 'status' => 403 ], $result->get_error_data() );
		self::assertNull( Auth::last_signature_check() );
	}

	/**
	 * WordPress appelle le contrôle d'accès deux fois pour la MÊME requête (au dispatch,
	 * puis dans rest_send_allow_header() pour l'en-tête Allow) : le second passage ne doit
	 * ni compter un faux rejeu, ni faire passer l'acceptation pour un renvoi.
	 */
	public function test_le_double_appel_de_wordpress_ne_compte_pas_un_faux_rejeu(): void {
		foreach ( [ $this->acceptation(), $this->lecture() ] as $request ) {
			self::assertTrue( Auth::require_signed_run_access( $request ) );
			self::assertTrue( Auth::require_signed_run_access( $request ) );

			self::assertSame( [ 'status' => 'ok' ], Auth::last_signature_check() );
			self::assertFalse( Auth::is_replayed_acceptance( $request ) );
		}

		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	private function requete( string $methode, string $route, string $corps ): WP_REST_Request {
		$request = new WP_REST_Request( $methode, $route );
		$request->set_header( 'Authorization', 'Bearer ' . self::TOKEN );
		$request->set_body( $corps );
		return $request;
	}

	private function acceptation( ?string $nonce = null, ?int $horodatage = null ): WP_REST_Request {
		return $this->signer( $this->requete( 'POST', self::ACCEPTATION, self::CORPS ), $nonce, $horodatage );
	}

	private function lecture( ?string $nonce = null ): WP_REST_Request {
		return $this->signer( $this->requete( 'GET', self::LECTURE, '' ), $nonce );
	}

	/** Signe la requête comme la plateforme : méthode, route REST, corps. */
	private function signer( WP_REST_Request $request, ?string $nonce = null, ?int $horodatage = null ): WP_REST_Request {
		$nonce      = $nonce ?? bin2hex( random_bytes( 16 ) );
		$horodatage = (string) ( $horodatage ?? time() );

		$request->set_header( 'X-G2RD-Timestamp', $horodatage );
		$request->set_header( 'X-G2RD-Nonce', $nonce );
		$request->set_header(
			'X-G2RD-Signature',
			'v1=' . RequestSignature::sign( self::TOKEN, $request->get_method(), $request->get_route(), $horodatage, $nonce, $request->get_body() )
		);
		return $request;
	}
}
