<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Updates;

use Brain\Monkey\Functions;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\WporgAnswers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Heure de la dernière réponse REÇUE de wordpress.org, par vérification, liée à
 * l'écriture du transient qui la porte.
 *
 * wp_version_check(), wp_update_plugins() et wp_update_themes() (wp-includes/update.php,
 * WordPress 6.9.4) écrivent `last_checked = time()` dans le transient AVANT d'appeler
 * wordpress.org, puis s'arrêtent sur une erreur : `last_checked` dit quand WordPress a
 * essayé, pas quand wordpress.org a répondu. La réponse passe par l'action
 * `http_api_debug` — ou, requête court-circuitée (mu-plugin Hostinger, cf.
 * HostingerProxy), par la valeur finale du filtre `pre_http_request` ; elle n'est notée
 * que si WordPress écrit ensuite le transient.
 *
 * Le banc rejoue l'ordre de WordPress : écriture d'avant l'appel (filtre
 * `pre_set_site_transient_{$transient}` puis action `set_site_transient_{$transient}`,
 * wp-includes/option.php), réponse (`http_api_debug`), écriture de la réponse quand
 * WordPress la fait (cœur : 200 portant des offres ; extensions et thèmes : tout 200).
 */
final class WporgAnswersTest extends TestCase {

	private const MAX_AGE = 3 * 3600;

	private const PLUGINS_URL = 'https://api.wordpress.org/plugins/update-check/1.1/';

	private const THEMES_URL = 'https://api.wordpress.org/themes/update-check/1.1/';

	private const CORE_URL = 'https://api.wordpress.org/core/version-check/1.7/?version=6.9.4&php=8.3';

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
		Functions\when( 'maybe_serialize' )->alias( static fn ( $data ) => is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data );
		HostingerProxy::reset();
	}

	public function test_une_reponse_200_a_la_verification_des_extensions_est_notee(): void {
		$avant = time();
		self::check( 200, self::PLUGINS_URL );

		$noted = $this->site_options[ WporgAnswers::OPTION ]['plugins'] ?? null;
		self::assertIsInt( $noted );
		self::assertGreaterThanOrEqual( $avant, $noted );
		self::assertLessThanOrEqual( time(), $noted );
		self::assertTrue( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ), 'Chaque vérification pour soi.' );
	}

	/** Une seule écriture en base par vérification réussie, au moment où WordPress écrit la réponse. */
	public function test_une_seule_ecriture_en_base_par_verification_reussie(): void {
		self::write( 'update_plugins' );
		self::response( 200, self::PLUGINS_URL );
		self::assertSame( [], $this->writes, 'Rien n\'est écrit à la réception.' );

		self::write( 'update_plugins' );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
	}

	/**
	 * Repli de WordPress en http:// quand le https:// échoue (erreur réseau) : la
	 * réponse du repli compte, l'écriture d'avant l'appel vaut pour les deux.
	 */
	public function test_le_repli_en_http_compte_aussi(): void {
		self::write( 'update_themes' );
		self::failure( self::THEMES_URL );
		self::response( 200, 'http://api.wordpress.org/themes/update-check/1.1/' );
		self::write( 'update_themes' );

		self::assertTrue( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/**
	 * Erreur réseau sur l'adresse en http:// : c'est le dernier essai de WordPress (repli
	 * après un échec en https://, ou essai unique sans SSL), qui s'arrête ensuite sans rien
	 * écrire (wp-includes/update.php, WordPress 6.9.4). L'amorce tombe : un 200 qu'une
	 * autre extension obtient ensuite d'update-check, dans la même requête PHP, ne reste pas
	 * en attente et n'est pas emporté par l'écriture suivante du transient.
	 *
	 * @param list<string> $failed Adresses en échec, dans l'ordre des essais de WordPress.
	 */
	#[DataProvider( 'last_attempt_failures' )]
	public function test_un_echec_du_dernier_essai_en_http_retire_l_amorce( string $transient, array $failed, string $other_url ): void {
		self::write( $transient );
		foreach ( $failed as $url ) {
			self::failure( $url );
		}
		self::response( 200, $other_url );
		self::write( $transient );

		self::assertSame( [], $this->writes );
	}

	/** @return array<string, array{string, list<string>, string}> */
	public static function last_attempt_failures(): array {
		return [
			'extensions, https puis http' => [
				'update_plugins',
				[ self::PLUGINS_URL, 'http://api.wordpress.org/plugins/update-check/1.1/' ],
				self::PLUGINS_URL,
			],
			'thèmes, https puis http'     => [
				'update_themes',
				[ self::THEMES_URL, 'http://api.wordpress.org/themes/update-check/1.1/' ],
				self::THEMES_URL,
			],
			'cœur, https puis http'       => [
				'update_core',
				[ self::CORE_URL, 'http://api.wordpress.org/core/version-check/1.7/?version=6.9.4&php=8.3' ],
				self::CORE_URL,
			],
			'extensions, http seul'       => [
				'update_plugins',
				[ 'http://api.wordpress.org/plugins/update-check/1.1/' ],
				'http://api.wordpress.org/plugins/update-check/1.1/',
			],
		];
	}

	public function test_le_coeur_compte_seulement_avec_des_offres(): void {
		// Sans `offers`, wp_version_check() s'arrête sans rien écrire.
		self::check( 200, self::CORE_URL, '{"translations":[]}' );
		self::check( 200, self::CORE_URL, 'Service indisponible' );
		self::assertFalse( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );

		self::check( 200, self::CORE_URL, '{"offers":[{"response":"latest","current":"6.9.4"}]}' );
		self::assertTrue( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );
	}

	/**
	 * Un 200 que WordPress n'écrit pas (filtre `http_response` qui le transforme, appel
	 * d'une autre extension) : rien n'est noté, même si le transient a été écrit avant
	 * l'appel.
	 */
	public function test_un_200_sans_ecriture_du_transient_n_est_pas_note(): void {
		self::response( 200, self::PLUGINS_URL );
		self::write( 'update_themes' );
		self::write( 'update_core' );

		self::write( 'update_themes' );
		self::response( 200, self::THEMES_URL );

		self::assertSame( [], $this->writes );
		self::assertFalse( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/**
	 * Un filtre `http_response` transforme le 200 (WP_Http::request() l'applique juste
	 * après `http_api_debug`) : WordPress lit la réponse transformée et n'écrit pas le
	 * transient. La réponse ne reste pas en attente : une seconde vérification dans la
	 * même requête PHP (cron qui enchaîne wp_update_plugins et la découverte du
	 * connecteur), ratée, ne l'emporte pas à son écriture d'avant l'appel.
	 */
	public function test_un_200_transforme_par_un_filtre_http_response_n_est_pas_note(): void {
		self::write( 'update_plugins' );
		self::response( 200, self::PLUGINS_URL, null, 503 );

		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::failure( 'http://api.wordpress.org/plugins/update-check/1.1/' );

		self::write( 'update_core' );
		self::response( 200, self::CORE_URL, null, 200, '<html>Maintenance</html>' );
		self::write( 'update_core' );

		self::assertSame( [], $this->writes );
	}

	/** Un filtre `http_response` qui garde un 200 porteur des données : WordPress l'écrit, il est noté. */
	public function test_un_200_que_le_filtre_http_response_garde_valable_est_note(): void {
		self::write( 'update_themes' );
		self::response( 200, self::THEMES_URL, null, 200, '{"themes":{"twentytwentyfive":{"new_version":"1.4"}},"translations":[],"no_update":[]}' );
		self::write( 'update_themes' );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
	}

	/**
	 * Écriture d'avant l'appel (`last_checked`, update.php l. 76-77, 424-425, 711-712),
	 * puis échec : aucune réponse n'était en attente, rien n'est noté — ni à cette
	 * écriture, ni à une écriture suivante du même transient.
	 */
	public function test_l_ecriture_d_avant_l_appel_puis_un_echec_n_est_pas_notee(): void {
		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::failure( 'http://api.wordpress.org/plugins/update-check/1.1/' );

		self::write( 'update_core' );
		self::response( 403, self::CORE_URL );

		self::write( 'update_plugins' );
		self::write( 'update_core' );

		self::assertSame( [], $this->writes );
	}

	/**
	 * Une autre extension appelle elle-même update-check, puis WordPress vérifie dans la
	 * même requête PHP et échoue : l'écriture d'avant l'appel de WordPress ne doit pas
	 * emporter la réponse de l'extension (anciennes données sous une date neuve).
	 */
	public function test_la_reponse_d_une_autre_extension_ne_compte_pas_pour_la_verification_de_wordpress(): void {
		self::response( 200, self::PLUGINS_URL );
		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::failure( 'http://api.wordpress.org/plugins/update-check/1.1/' );

		self::assertSame( [], $this->writes );

		// La vérification suivante de WordPress, réussie, est notée normalement.
		self::check( 200, self::PLUGINS_URL );
		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
	}

	/**
	 * Réponse identique à ce que le transient porte déjà, dans la même seconde que
	 * l'écriture d'avant l'appel : update_network_option() (wp-includes/option.php) ne
	 * réécrit pas la base et set_site_transient() ne déclenche pas son action. La base
	 * porte pourtant exactement la réponse : notée.
	 */
	public function test_une_reecriture_identique_note_la_reponse(): void {
		self::write( 'update_core' );
		self::response( 200, self::CORE_URL );
		self::write( 'update_core', 'identical' );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
		self::assertTrue( WporgAnswers::answered_within( 'core', time(), self::MAX_AGE ) );
	}

	/**
	 * Écriture de la réponse refusée par la base (ni action, ni valeur identique) : rien
	 * n'est noté, et la réponse ne survit pas à cette écriture — l'écriture d'avant
	 * l'appel d'une vérification suivante, ratée, ne l'emporte pas.
	 */
	public function test_une_reponse_dont_l_ecriture_echoue_n_est_jamais_notee(): void {
		self::write( 'update_plugins' );
		self::response( 200, self::PLUGINS_URL );
		self::write( 'update_plugins', 'failed' );

		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::write( 'update_plugins' );

		self::assertSame( [], $this->writes );
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

		self::check( 200, self::PLUGINS_URL, $defi );
		self::check( 200, self::THEMES_URL, $defi );

		self::assertSame( [], $this->writes );
		self::assertFalse( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/** Un 200 au JSON vide ou sans la clé de sa vérification : même transient vide, rien n'est noté. */
	public function test_un_200_sans_la_cle_de_sa_verification_n_est_pas_note(): void {
		foreach ( [ '', '{}', '[]', 'null', '"plugins"', '{"translations":[],"no_update":[]}' ] as $body ) {
			self::check( 200, self::PLUGINS_URL, $body );
			self::check( 200, self::THEMES_URL, $body );
		}
		self::check( 200, self::PLUGINS_URL, '{"themes":[],"translations":[],"no_update":[]}' );
		self::check( 200, self::THEMES_URL, '{"plugins":[],"translations":[],"no_update":[]}' );

		self::assertSame( [], $this->writes );
	}

	/** 403 du CDN, erreur réseau, délai dépassé : rien n'est noté. */
	public function test_un_echec_n_est_pas_note(): void {
		self::check( 403, self::PLUGINS_URL );
		self::check( 500, self::THEMES_URL );
		self::write( 'update_core' );
		self::failure( 'https://api.wordpress.org/core/version-check/1.7/' );

		self::assertSame( [], $this->writes );
	}

	/** Le reste du trafic HTTP du site n'écrit rien, même entre deux écritures des transients. */
	public function test_les_autres_requetes_ne_sont_pas_notees(): void {
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::write( $transient );
		}
		self::response( 200, 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information', self::answer_body( self::PLUGINS_URL ) );
		self::response( 200, 'https://downloads.wordpress.org/plugin/akismet.5.3.zip', self::answer_body( self::PLUGINS_URL ) );
		self::response( 200, 'https://api.github.com/repos/g2rd/g2rd-connector/releases/latest', self::answer_body( self::PLUGINS_URL ) );
		self::response( 200, 'https://exemple.test/api.wordpress.org/plugins/update-check/1.1/', self::answer_body( self::PLUGINS_URL ) );
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::write( $transient );
		}

		self::assertSame( [], $this->writes );
	}

	/**
	 * Les adresses réelles des trois vérifications (wp-includes/update.php, WordPress
	 * 6.9.4) : https://, et le repli en http:// quand le https:// échoue.
	 */
	#[DataProvider( 'real_check_urls' )]
	public function test_les_adresses_reelles_des_verifications_sont_acceptees( string $url ): void {
		self::check( 200, $url, self::answer_body( $url ) );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes, $url );
	}

	/**
	 * Adresses refusées : autre schéma que http ou https, port, identifiants avant « @ »,
	 * chemin qui n'est pas exactement celui d'une vérification, autre hôte.
	 */
	#[DataProvider( 'refused_urls' )]
	public function test_une_adresse_qui_n_est_pas_exactement_celle_d_une_verification_est_refusee( string $url ): void {
		self::check( 200, $url, self::answer_body( $url ) );

		self::assertSame( [], $this->writes, $url );
	}

	/** @return array<string, array{string}> */
	public static function real_check_urls(): array {
		$query = '?version=6.9.4&php=8.3.14&locale=fr_FR&mysql=8.0.36&local_package=&blogs=1&users=3&multisite_enabled=0&initial_db_version=58975';

		return [
			'cœur, https'           => [ 'https://api.wordpress.org/core/version-check/1.7/' . $query ],
			'cœur, repli http'      => [ 'http://api.wordpress.org/core/version-check/1.7/' . $query ],
			'extensions, https'     => [ 'https://api.wordpress.org/plugins/update-check/1.1/' ],
			'extensions, repli http' => [ 'http://api.wordpress.org/plugins/update-check/1.1/' ],
			'thèmes, https'         => [ 'https://api.wordpress.org/themes/update-check/1.1/' ],
			'thèmes, repli http'    => [ 'http://api.wordpress.org/themes/update-check/1.1/' ],
		];
	}

	/** @return array<string, array{string}> */
	public static function refused_urls(): array {
		return [
			'schéma ftp'                    => [ 'ftp://api.wordpress.org/plugins/update-check/1.1/' ],
			'schéma file'                   => [ 'file://api.wordpress.org/plugins/update-check/1.1/' ],
			'sans schéma'                   => [ '//api.wordpress.org/plugins/update-check/1.1/' ],
			'port'                          => [ 'https://api.wordpress.org:8443/plugins/update-check/1.1/' ],
			'port 443 explicite'            => [ 'https://api.wordpress.org:443/themes/update-check/1.1/' ],
			'port 80 explicite'             => [ 'http://api.wordpress.org:80/core/version-check/1.7/?version=6.9.4' ],
			'identifiant et mot de passe'   => [ 'https://user:secret@api.wordpress.org/plugins/update-check/1.1/' ],
			'identifiant seul'              => [ 'https://user@api.wordpress.org/themes/update-check/1.1/' ],
			'hôte caché derrière « @ »'     => [ 'https://api.wordpress.org@exemple.test/plugins/update-check/1.1/' ],
			'autre version de l\'API'       => [ 'https://api.wordpress.org/plugins/update-check/1.0/' ],
			'chemin prolongé'               => [ 'https://api.wordpress.org/plugins/update-check/1.1/autre' ],
			'chemin sans la version'        => [ 'https://api.wordpress.org/themes/update-check/' ],
			'sans la barre finale'          => [ 'https://api.wordpress.org/core/version-check/1.7?version=6.9.4' ],
			'chemin préfixé'                => [ 'https://api.wordpress.org/x/plugins/update-check/1.1/' ],
			'sous-domaine'                  => [ 'https://api.wordpress.org.exemple.test/plugins/update-check/1.1/' ],
			'hôte dans la requête'          => [ 'https://exemple.test/?u=https://api.wordpress.org/plugins/update-check/1.1/' ],
		];
	}

	public function test_une_reponse_garde_l_heure_des_autres_verifications(): void {
		$this->site_options[ WporgAnswers::OPTION ] = [ 'core' => time() - 600 ];

		self::check( 200, self::PLUGINS_URL );

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

	// ─── Requête court-circuitée par `pre_http_request` (mu-plugin Hostinger) ─────

	/**
	 * Le cas du pilote (g2rd.fr, WordPress 7.1.3, 16/10/2026) : le mu-plugin Hostinger refait
	 * la requête vers api.wordpress.org vers son proxy et rend la réponse du proxy par le
	 * filtre `pre_http_request` (cf. HostingerProxy). WordPress l'utilise comme réponse de
	 * wordpress.org et écrit le transient qui la porte : notée, une fois, quel que soit
	 * l'hôte du proxy. Seul le proxy est appelé.
	 */
	#[DataProvider( 'proxied_checks' )]
	public function test_une_verification_servie_par_le_proxy_hostinger_est_notee( string $check, string $url, string $proxy_host ): void {
		HostingerProxy::$proxy_host = $proxy_host;

		self::proxied_check( $url, self::answering( self::answer_body( $url ) ) );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
		self::assertTrue( WporgAnswers::answered_within( $check, time(), self::MAX_AGE ) );
		self::assertSame( [ str_replace( 'api.wordpress.org', $proxy_host, $url ) ], HostingerProxy::$network );
	}

	/** @return array<string, array{string, string, string}> */
	public static function proxied_checks(): array {
		return [
			'cœur'                  => [ 'core', self::CORE_URL, 'wpapi.hostinger.io' ],
			'extensions'            => [ 'plugins', self::PLUGINS_URL, 'wpapi.hostinger.io' ],
			'thèmes'                => [ 'themes', self::THEMES_URL, 'wpapi.hostinger.io' ],
			'extensions, autre hôte' => [ 'plugins', self::PLUGINS_URL, 'miroir.exemple.test' ],
		];
	}

	/** Une seule écriture en base, au moment où WordPress écrit la réponse servie par le proxy. */
	public function test_une_seule_ecriture_en_base_par_verification_servie_par_le_proxy(): void {
		self::write( 'update_plugins' );
		HostingerProxy::request( self::PLUGINS_URL, self::answering( self::answer_body( self::PLUGINS_URL ) ) );
		self::assertSame( [], $this->writes, 'Rien n\'est écrit à la réception.' );

		self::write( 'update_plugins' );
		self::write( 'update_plugins' );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
	}

	/**
	 * Le proxy ne répond pas, en https:// puis au repli en http:// : WordPress s'arrête sans
	 * rien écrire. Rien n'est noté, et l'amorce tombe : un 200 qu'une autre extension obtient
	 * ensuite d'update-check par le proxy, dans la même requête PHP, n'est pas emporté par
	 * l'écriture suivante du transient.
	 */
	public function test_une_erreur_reseau_du_proxy_puis_du_repli_http_n_est_pas_notee(): void {
		foreach ( [ self::PLUGINS_URL, self::THEMES_URL, self::CORE_URL ] as $url ) {
			$received = self::proxied_check( $url, self::unreachable() );
			self::assertInstanceOf( \WP_Error::class, $received );

			HostingerProxy::request( $url, self::answering( self::answer_body( $url ) ) );
			self::write( self::transient_of( $url ) );
		}

		self::assertSame( [], $this->writes );
		self::assertContains( 'http://wpapi.hostinger.io/plugins/update-check/1.1/', HostingerProxy::$network, 'Le repli en http:// passe aussi par le proxy.' );
	}

	/** Le proxy échoue en https:// et répond au repli en http:// : la réponse du repli compte. */
	public function test_le_repli_en_http_servi_par_le_proxy_compte(): void {
		self::proxied_check(
			self::THEMES_URL,
			static fn ( string $url ) => str_starts_with( $url, 'https://' )
				? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
				: self::http_response( 200, self::answer_body( $url ) )
		);

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
		self::assertTrue( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/**
	 * Le proxy rend un 200 au corps HTML : WordPress écrit un transient vide (extensions,
	 * thèmes) ou s'arrête (cœur). Rien n'est noté.
	 */
	public function test_un_200_au_corps_html_servi_par_le_proxy_n_est_pas_note(): void {
		$defi = '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body>Vérification de votre navigateur</body></html>';

		foreach ( [ self::PLUGINS_URL, self::THEMES_URL, self::CORE_URL ] as $url ) {
			self::proxied_check( $url, self::answering( $defi ) );
		}

		self::assertSame( [], $this->writes );
		foreach ( [ 'core', 'plugins', 'themes' ] as $check ) {
			self::assertFalse( WporgAnswers::answered_within( $check, time(), self::MAX_AGE ), $check );
		}
	}

	/**
	 * Interception que WordPress n'écrit pas ensuite : appel d'update-check fait par une
	 * autre extension (ni écriture avant, ni après), ou vérification amorcée que WordPress
	 * n'achève pas. Rien n'est noté.
	 */
	public function test_une_interception_sans_ecriture_du_transient_ensuite_n_est_pas_notee(): void {
		HostingerProxy::request( self::PLUGINS_URL, self::answering( self::answer_body( self::PLUGINS_URL ) ) );
		self::write( 'update_themes' );
		HostingerProxy::request( self::THEMES_URL, self::answering( self::answer_body( self::THEMES_URL ) ) );

		self::assertSame( [], $this->writes );
		self::assertFalse( WporgAnswers::answered_within( 'plugins', time(), self::MAX_AGE ) );
		self::assertFalse( WporgAnswers::answered_within( 'themes', time(), self::MAX_AGE ) );
	}

	/**
	 * Une autre extension appelle update-check par le proxy, puis WordPress vérifie dans la
	 * même requête PHP et échoue : l'écriture d'avant l'appel de WordPress n'emporte pas la
	 * réponse de l'extension. La vérification suivante de WordPress, réussie, est notée.
	 */
	public function test_la_reponse_servie_par_le_proxy_a_une_autre_extension_ne_compte_pas(): void {
		HostingerProxy::request( self::PLUGINS_URL, self::answering( self::answer_body( self::PLUGINS_URL ) ) );
		self::proxied_check( self::PLUGINS_URL, self::unreachable() );

		self::assertSame( [], $this->writes );

		self::proxied_check( self::PLUGINS_URL, self::answering( self::answer_body( self::PLUGINS_URL ) ) );
		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );
	}

	/**
	 * Requêtes interceptées vers d'autres adresses (informations d'extension, traductions,
	 * autre version de l'API, téléchargements, autre hôte), même entre deux écritures des
	 * transients et avec un corps qui porte toutes les clés : rien n'est noté.
	 */
	public function test_une_autre_adresse_interceptee_n_est_pas_notee(): void {
		$body = '{"offers":[{"response":"latest"}],"plugins":[],"themes":[],"translations":[],"no_update":[]}';
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::write( $transient );
		}
		foreach ( [
			'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=akismet',
			'https://api.wordpress.org/themes/info/1.2/?action=theme_information',
			'https://api.wordpress.org/translations/core/1.0/?version=7.1.3',
			'https://api.wordpress.org/core/version-check/1.6/?version=7.1.3',
			'https://api.wordpress.org/plugins/update-check/1.0/',
		] as $url ) {
			HostingerProxy::request( $url, self::answering( $body ) );
		}
		WporgAnswers::observe_preempted( self::http_response( 200, $body ), [], 'https://downloads.wordpress.org/plugin/akismet.5.3.zip' );
		WporgAnswers::observe_preempted( self::http_response( 200, $body ), [], 'https://wpapi.hostinger.io/plugins/update-check/1.1/' );
		WporgAnswers::observe_preempted( self::http_response( 200, $body ), [], 'https://api.wordpress.org:8443/plugins/update-check/1.1/' );
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::write( $transient );
		}

		self::assertSame( [], $this->writes );
	}

	/**
	 * Un filtre qui refait la requête vers la MÊME adresse (requête imbriquée vers
	 * api.wordpress.org, vue par `http_api_debug`) puis rend sa réponse : une seule réponse
	 * en attente, une seule écriture. S'il la transforme en autre chose qu'un 200 porteur
	 * des données, WordPress s'arrête : elle n'est plus en attente, et l'écriture d'avant
	 * l'appel d'une vérification suivante, ratée, ne l'emporte pas.
	 */
	public function test_une_interception_qui_refait_la_meme_requete_ne_compte_qu_une_fois(): void {
		$answer = self::http_response( 200, self::answer_body( self::PLUGINS_URL ) );

		self::write( 'update_plugins' );
		WporgAnswers::record( $answer, 'response', 'WpOrg\Requests\Requests', [], self::PLUGINS_URL );
		WporgAnswers::confirm_response( $answer, [], self::PLUGINS_URL );
		WporgAnswers::observe_preempted( $answer, [], self::PLUGINS_URL );
		self::write( 'update_plugins' );

		self::assertSame( [ WporgAnswers::OPTION ], $this->writes );

		$this->writes = [];
		unset( $this->site_options[ WporgAnswers::OPTION ] );
		self::write( 'update_themes' );
		$themes = self::http_response( 200, self::answer_body( self::THEMES_URL ) );
		WporgAnswers::record( $themes, 'response', 'WpOrg\Requests\Requests', [], self::THEMES_URL );
		WporgAnswers::confirm_response( $themes, [], self::THEMES_URL );
		WporgAnswers::observe_preempted( self::http_response( 503, 'Service indisponible' ), [], self::THEMES_URL );

		self::write( 'update_themes' );
		self::failure( self::THEMES_URL );
		self::failure( 'http://api.wordpress.org/themes/update-check/1.1/' );

		self::assertSame( [], $this->writes );
	}

	/**
	 * Seule une erreur réseau (WP_Error) en https:// fait retenter WordPress en http://
	 * (`$ssl && is_wp_error( … )`, wp-includes/update.php) : toute autre valeur qui n'est
	 * pas un tableau, rendue par un filtre, l'arrête sans rien écrire. L'amorce tombe : un
	 * 200 qu'une autre extension obtient ensuite d'update-check n'est pas emporté par
	 * l'écriture suivante du transient.
	 *
	 * @param mixed $value Valeur rendue par le filtre.
	 */
	#[DataProvider( 'non_response_values' )]
	public function test_une_interception_qui_ne_rend_ni_tableau_ni_erreur_retire_l_amorce( $value ): void {
		self::write( 'update_plugins' );
		WporgAnswers::observe_preempted( $value, [], self::PLUGINS_URL );

		self::response( 200, self::PLUGINS_URL );
		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::failure( 'http://api.wordpress.org/plugins/update-check/1.1/' );

		self::assertSame( [], $this->writes );
	}

	/** @return array<string, array{mixed}> */
	public static function non_response_values(): array {
		return [
			'true'          => [ true ],
			'chaîne'        => [ '{"plugins":[]}' ],
			'entier'        => [ 200 ],
			'null'          => [ null ],
		];
	}

	/**
	 * wp_remote_retrieve_response_code() (wp-includes/http.php) rend le code tel quel, et
	 * update.php le compare strictement (`200 !== …`) : un code « 200 » en chaîne, rendu par
	 * un filtre `pre_http_request` ou `http_response`, arrête WordPress sans écrire le
	 * transient. Ce n'est pas une réponse : rien n'est noté, ni à une écriture suivante.
	 */
	public function test_un_code_200_en_chaine_n_est_pas_une_reponse(): void {
		$plugins                     = self::http_response( 200, self::answer_body( self::PLUGINS_URL ) );
		$plugins['response']['code'] = '200';
		self::write( 'update_plugins' );
		WporgAnswers::observe_preempted( $plugins, [], self::PLUGINS_URL );
		self::write( 'update_plugins' );
		self::failure( self::PLUGINS_URL );
		self::failure( 'http://api.wordpress.org/plugins/update-check/1.1/' );

		$received                 = self::http_response( 200, self::answer_body( self::THEMES_URL ) );
		$read                     = $received;
		$read['response']['code'] = '200';
		self::write( 'update_themes' );
		WporgAnswers::record( $received, 'response', 'WpOrg\Requests\Requests', [], self::THEMES_URL );
		WporgAnswers::confirm_response( $read, [], self::THEMES_URL );
		self::write( 'update_themes' );
		self::failure( self::THEMES_URL );
		self::failure( 'http://api.wordpress.org/themes/update-check/1.1/' );

		self::assertSame( [], $this->writes );
	}

	/**
	 * Le filtre rend toujours la valeur reçue, sans la modifier (identité stricte), qu'elle
	 * compte ou non, pour une vérification amorcée comme pour une autre adresse.
	 *
	 * @param mixed $value Valeur reçue par le filtre.
	 */
	#[DataProvider( 'preempted_values' )]
	public function test_la_valeur_de_pre_http_request_est_rendue_telle_quelle( $value, string $url ): void {
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::write( $transient );
		}

		self::assertSame( $value, WporgAnswers::observe_preempted( $value, [ 'timeout' => 30 ], $url ) );
	}

	/** @return array<string, array{mixed, string}> */
	public static function preempted_values(): array {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

		return [
			'pas de court-circuit'     => [ false, self::PLUGINS_URL ],
			'200 des extensions'       => [ self::http_response( 200, self::answer_body( self::PLUGINS_URL ) ), self::PLUGINS_URL ],
			'200 du cœur'              => [ self::http_response( 200, self::answer_body( self::CORE_URL ) ), self::CORE_URL ],
			'200 au corps HTML'        => [ self::http_response( 200, '<html></html>' ), self::THEMES_URL ],
			'403'                      => [ self::http_response( 403, 'Forbidden' ), self::THEMES_URL ],
			'erreur réseau'            => [ $error, self::PLUGINS_URL ],
			'erreur réseau, repli'     => [ $error, 'http://api.wordpress.org/plugins/update-check/1.1/' ],
			'autre valeur'             => [ true, self::CORE_URL ],
			'autre adresse'            => [ self::http_response( 200, self::answer_body( self::PLUGINS_URL ) ), 'https://exemple.test/' ],
		];
	}

	/**
	 * Réponse sur `http_api_debug`, puis telle que WordPress la lit (filtre
	 * `http_response`, en dernier) ; écritures des trois transients : filtre
	 * `pre_set_site_transient_{$transient}` (en dernier, après les updaters tiers), action
	 * `set_site_transient_{$transient}`, et filtre `pre_update_site_option_{$option}` pour
	 * la réécriture identique. Requête court-circuitée : valeur finale du filtre
	 * `pre_http_request`, en dernier (après le mu-plugin Hostinger, priorité 10).
	 */
	public function test_l_ecoute_est_branchee_sur_http_api_debug_et_sur_l_ecriture_des_transients(): void {
		WporgAnswers::register();

		self::assertNotFalse( has_action( 'http_api_debug', [ WporgAnswers::class, 'record' ] ) );
		self::assertSame( PHP_INT_MAX, has_filter( 'http_response', [ WporgAnswers::class, 'confirm_response' ] ) );
		self::assertSame( PHP_INT_MAX, has_filter( 'pre_http_request', [ WporgAnswers::class, 'observe_preempted' ] ) );
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $transient ) {
			self::assertSame( PHP_INT_MAX, has_filter( 'pre_set_site_transient_' . $transient, [ WporgAnswers::class, 'before_write' ] ), $transient );
			self::assertNotFalse( has_action( 'set_site_transient_' . $transient, [ WporgAnswers::class, 'after_write' ] ), $transient );
			self::assertSame( PHP_INT_MAX, has_filter( 'pre_update_site_option__site_transient_' . $transient, [ WporgAnswers::class, 'before_option_update' ] ), $transient );
		}
	}

	/**
	 * Vérification complète, dans l'ordre de wp-includes/update.php : écriture d'avant
	 * l'appel, réponse, puis écriture de la réponse si WordPress la fait (cœur : 200 dont
	 * le JSON porte `offers` ; extensions et thèmes : tout 200).
	 */
	private static function check( int $code, string $url, ?string $body = null ): void {
		$transient = self::transient_of( $url );
		$body    ??= self::answer_body( $url );

		self::write( $transient );
		self::response( $code, $url, $body );
		if ( 200 !== $code ) {
			return;
		}
		if ( 'update_core' === $transient ) {
			$decoded = json_decode( trim( $body ), true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['offers'] ) ) {
				return;
			}
		}
		self::write( $transient );
	}

	/**
	 * Vérification complète sur un site qui a le mu-plugin Hostinger, dans l'ordre de
	 * wp-includes/update.php (WordPress 6.9.4 et 7.1.3) : écriture d'avant l'appel, requête
	 * (HostingerProxy::request()), repli en http:// sur une erreur réseau en https://, puis
	 * écriture de la réponse si WordPress la fait — code 200 exactement
	 * (`200 !== wp_remote_retrieve_response_code()`), et pour le cœur un JSON qui porte
	 * `offers` ; extensions et thèmes : tout 200, même au corps HTML (transient vide).
	 *
	 * @param callable(string): mixed $remote Réponse du réseau, par adresse demandée.
	 * @return mixed Ce que WordPress a reçu.
	 */
	private static function proxied_check( string $url, callable $remote ): mixed {
		$transient = self::transient_of( $url );

		self::write( $transient );
		$received = HostingerProxy::request( $url, $remote );
		if ( $received instanceof \WP_Error && str_starts_with( $url, 'https://' ) ) {
			$received = HostingerProxy::request( 'http://' . substr( $url, strlen( 'https://' ) ), $remote );
		}
		if ( ! is_array( $received ) || ! is_array( $received['response'] ?? null ) || 200 !== ( $received['response']['code'] ?? null ) ) {
			return $received;
		}
		if ( 'update_core' === $transient ) {
			$decoded = json_decode( trim( (string) ( $received['body'] ?? '' ) ), true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['offers'] ) ) {
				return $received;
			}
		}
		self::write( $transient );

		return $received;
	}

	/** Réseau qui répond 200 avec ce corps, à toute adresse. */
	private static function answering( string $body ): \Closure {
		return static fn (): array => self::http_response( 200, $body );
	}

	/** Réseau injoignable (délai dépassé), à toute adresse. */
	private static function unreachable(): \Closure {
		return static fn (): \WP_Error => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}

	/**
	 * set_site_transient() (wp-includes/option.php, WordPress 6.9.4) : filtre
	 * `pre_set_site_transient_{$transient}`, puis, sans cache objet persistant,
	 * update_site_option() — action `set_site_transient_{$transient}` si la base a changé
	 * ; valeur identique : update_network_option() s'arrête juste après le filtre
	 * `pre_update_site_option_{$option}`, sans action ; écriture refusée : ni l'un ni
	 * l'autre.
	 *
	 * @param string $outcome 'changed' | 'identical' | 'failed'.
	 */
	private static function write( string $transient, string $outcome = 'changed' ): void {
		$value = WporgAnswers::before_write( (object) [ 'last_checked' => time() ], $transient );
		$old   = 'identical' === $outcome ? clone $value : (object) [ 'last_checked' => time() - 3600 ];
		$value = WporgAnswers::before_option_update( $value, $old, '_site_transient_' . $transient );
		if ( 'changed' === $outcome ) {
			WporgAnswers::after_write( $value, 0, $transient );
		}
	}

	/**
	 * Réponse telle que WP_Http::request() la passe à `http_api_debug`, puis au filtre
	 * `http_response` — inchangée, sauf code ou corps transformés par un autre filtre.
	 * Sans corps donné : celui qu'api.wordpress.org rend à la vérification visée.
	 */
	private static function response( int $code, string $url, ?string $body = null, ?int $read_code = null, ?string $read_body = null ): void {
		$received = self::http_response( $code, $body ?? self::answer_body( $url ) );
		WporgAnswers::record( $received, 'response', 'WpOrg\Requests\Requests', [], $url );

		$read = null === $read_code && null === $read_body
			? $received
			: self::http_response( $read_code ?? $code, $read_body ?? $received['body'] );
		WporgAnswers::confirm_response( $read, [], $url );
	}

	/**
	 * Réponse au format de WP_Http::request().
	 *
	 * @return array{headers: array<string, string>, body: string, response: array{code: int, message: string}, cookies: list<mixed>, filename: null}
	 */
	private static function http_response( int $code, string $body ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => '',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/** Erreur réseau (délai dépassé, connexion refusée) telle que WP_Http::request() la passe à `http_api_debug`. */
	private static function failure( string $url ): void {
		WporgAnswers::record(
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
			'response',
			'WpOrg\Requests\Requests',
			[],
			$url
		);
	}

	/** Transient écrit par la vérification visée par l'adresse. */
	private static function transient_of( string $url ): string {
		if ( str_contains( $url, '/plugins/update-check/' ) ) {
			return 'update_plugins';
		}
		if ( str_contains( $url, '/themes/update-check/' ) ) {
			return 'update_themes';
		}

		return 'update_core';
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
