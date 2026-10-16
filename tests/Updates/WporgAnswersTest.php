<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Updates;

use Brain\Monkey\Functions;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\WporgAnswers;

/**
 * Heure de la dernière réponse REÇUE de wordpress.org, par vérification.
 *
 * wp_version_check(), wp_update_plugins() et wp_update_themes() écrivent
 * `last_checked = time()` dans le transient AVANT d'appeler wordpress.org, puis
 * s'arrêtent sur une erreur : `last_checked` dit quand WordPress a essayé, pas quand
 * wordpress.org a répondu. La réponse, elle, passe par l'action `http_api_debug`.
 */
final class WporgAnswersTest extends TestCase {

	private const MAX_AGE = 3 * 3600;

	/** @var array<string, mixed> Options de réseau, par nom. */
	private array $site_options = [];

	/** @var list<string> Options de réseau écrites, dans l'ordre. */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->site_options = [];
		$this->writes       = [];

		Functions\when( 'get_site_option' )->alias(
			fn ( string $name, $fallback = false ) => array_key_exists( $name, $this->site_options ) ? $this->site_options[ $name ] : $fallback
		);
		Functions\when( 'update_site_option' )->alias(
			function ( string $name, $value ): bool {
				$this->site_options[ $name ] = $value;
				$this->writes[]              = $name;
				return true;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
	}

	public function test_une_reponse_200_a_la_verification_des_extensions_est_notee(): void {
		$avant = time();
		self::response( 200, 'https://api.wordpress.org/plugins/update-check/1.1/' );

		$noted = $this->site_options[ WporgAnswers::OPTION ]['plugins'] ?? null;
		self::assertIsInt( $noted );
		self::assertGreaterThanOrEqual( $avant, $noted );
		self::assertLessThanOrEqual( time(), $noted );
		self::assertTrue( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ), 'Chaque vérification pour soi.' );
	}

	/** Repli de WordPress en http:// quand le https:// échoue : la réponse compte aussi. */
	public function test_le_repli_en_http_compte_aussi(): void {
		self::response( 200, 'http://api.wordpress.org/themes/update-check/1.1/' );

		self::assertTrue( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	public function test_le_coeur_compte_seulement_avec_des_offres(): void {
		$url = 'https://api.wordpress.org/core/version-check/1.7/?version=6.9.4&php=8.3';

		// Sans `offers`, wp_version_check() s'arrête sans rien écrire.
		self::response( 200, $url, '{"translations":[]}' );
		self::response( 200, $url, 'Service indisponible' );
		self::assertFalse( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );

		self::response( 200, $url, '{"offers":[{"response":"latest","current":"6.9.4"}]}' );
		self::assertTrue( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );
	}

	/**
	 * Un 200 dont le corps n'est pas du JSON (page de défi HTML d'un CDN devant
	 * api.wordpress.org) : wp_update_plugins() et wp_update_themes() (WordPress 6.9.4)
	 * écrivent quand même un transient neuf, mais vide (extensions : `response` et
	 * `no_update` vides ; thèmes : `checked` sans `response` ni `no_update`). Noté, il
	 * passerait pour frais pendant 3 h et cacherait une mise à jour publiée.
	 */
	public function test_un_200_au_corps_html_n_est_pas_note(): void {
		$defi = '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body>Vérification de votre navigateur</body></html>';

		self::response( 200, 'https://api.wordpress.org/plugins/update-check/1.1/', $defi );
		self::response( 200, 'https://api.wordpress.org/themes/update-check/1.1/', $defi );

		self::assertSame( [], $this->writes );
		self::assertFalse( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/** Un 200 au JSON vide ou sans la clé de sa vérification : même transient vide, rien n'est noté. */
	public function test_un_200_sans_la_cle_de_sa_verification_n_est_pas_note(): void {
		$extensions = 'https://api.wordpress.org/plugins/update-check/1.1/';
		$themes     = 'https://api.wordpress.org/themes/update-check/1.1/';

		foreach ( [ '', '{}', '[]', 'null', '"plugins"', '{"translations":[],"no_update":[]}' ] as $body ) {
			self::response( 200, $extensions, $body );
			self::response( 200, $themes, $body );
		}
		self::response( 200, $extensions, '{"themes":[],"translations":[],"no_update":[]}' );
		self::response( 200, $themes, '{"plugins":[],"translations":[],"no_update":[]}' );

		self::assertSame( [], $this->writes );
	}

	/** 403 du CDN, erreur réseau, délai dépassé : rien n'est noté. */
	public function test_un_echec_n_est_pas_note(): void {
		self::response( 403, 'https://api.wordpress.org/plugins/update-check/1.1/' );
		self::response( 500, 'https://api.wordpress.org/themes/update-check/1.1/' );
		WporgAnswers::record(
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			'response',
			'WpOrg\Requests\Requests',
			[],
			'https://api.wordpress.org/core/version-check/1.7/'
		);

		self::assertSame( [], $this->writes );
	}

	/** Le reste du trafic HTTP du site n'écrit rien. */
	public function test_les_autres_requetes_ne_sont_pas_notees(): void {
		self::response( 200, 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information' );
		self::response( 200, 'https://downloads.wordpress.org/plugin/akismet.5.3.zip' );
		self::response( 200, 'https://api.github.com/repos/g2rd/g2rd-connector/releases/latest' );
		self::response( 200, 'https://exemple.test/api.wordpress.org/plugins/update-check/1.1/' );

		self::assertSame( [], $this->writes );
	}

	public function test_une_reponse_garde_l_heure_des_autres_verifications(): void {
		$this->site_options[ WporgAnswers::OPTION ] = [ 'core' => time() - 600 ];

		self::response( 200, 'https://api.wordpress.org/plugins/update-check/1.1/' );

		self::assertSame( time() - 600, $this->site_options[ WporgAnswers::OPTION ]['core'] ?? null );
		self::assertTrue( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );
	}

	public function test_une_reponse_ancienne_absente_ou_datee_du_futur_n_est_pas_recente(): void {
		$now = time();
		self::assertFalse( WporgAnswers::answered_within( 'plugins', $now, self::MAX_AGE ), 'Jamais notée.' );

		$this->site_options[ WporgAnswers::OPTION ] = [
			'core'    => $now - self::MAX_AGE,
			'plugins' => $now + 3600,
			'themes'  => 'pas une date',
		];

		self::assertFalse( WporgAnswers::answered_within( 'core', $now, self::MAX_AGE ), '3 h ou plus : périmée.' );
		self::assertFalse( WporgAnswers::answered_within( 'plugins', $now, self::MAX_AGE ), 'Horloge revenue en arrière.' );
		self::assertFalse( WporgAnswers::answered_within( 'themes', $now, self::MAX_AGE ) );

		$this->site_options[ WporgAnswers::OPTION ] = 'illisible';
		self::assertFalse( WporgAnswers::answered_within( 'core', $now, self::MAX_AGE ) );
	}

	public function test_l_ecoute_est_branchee_sur_http_api_debug(): void {
		WporgAnswers::register();

		self::assertNotFalse( has_action( 'http_api_debug', [ WporgAnswers::class, 'record' ] ) );
	}

	/**
	 * Réponse telle que WP_Http::request() la passe à `http_api_debug`. Sans corps donné :
	 * celui qu'api.wordpress.org rend à la vérification visée par l'adresse.
	 */
	private static function response( int $code, string $url, ?string $body = null ): void {
		WporgAnswers::record(
			[
				'headers'  => [],
				'body'     => $body ?? self::answer_body( $url ),
				'response' => [
					'code'    => $code,
					'message' => '',
				],
				'cookies'  => [],
				'filename' => null,
			],
			'response',
			'WpOrg\Requests\Requests',
			[],
			$url
		);
	}

	/** Corps d'une vraie réponse d'api.wordpress.org, d'après l'adresse (wp-includes/update.php). */
	private static function answer_body( string $url ): string {
		if ( str_contains( $url, '/plugins/update-check/' ) ) {
			return '{"plugins":[],"translations":[],"no_update":[]}';
		}
		if ( str_contains( $url, '/themes/update-check/' ) ) {
			return '{"themes":[],"translations":[],"no_update":[]}';
		}

		return '{"offers":[{"response":"latest","current":"6.9.4"}],"translations":[]}';
	}
}
