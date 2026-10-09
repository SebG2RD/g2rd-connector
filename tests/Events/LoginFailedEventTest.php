<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Events;

use Brain\Monkey\Functions;
use G2RD\Connector\Events\Listener;
use G2RD\Connector\Events\LoginFailedThrottle;
use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Connexions échouées : chaque `wp_login_failed` faisait un POST BLOQUANT vers la
 * plateforme (jusqu'à 15 s). En force brute — et par xmlrpc `system.multicall`,
 * plusieurs essais par requête — la plateforme démarrait à chaque mot de passe
 * essayé (jusqu'à 896 événements par jour mesurés sur un site).
 *
 * Désormais : envoi non bloquant (délai court), et au plus
 * LoginFailedThrottle::MAX_PER_MINUTE événements par minute et par site. Les
 * autres événements ne changent pas.
 */
final class LoginFailedEventTest extends TestCase {

	private const NOW     = 1788999980; // 20 s après le début d'une minute (1788999960 = 29816666 × 60).
	private const TOKEN   = 'jeton-du-site-0123456789';
	private const SITE_ID = 7;

	/** Heure simulée, lue par le plafond. */
	private int $now = self::NOW;

	/** @var list<array{url: string, args: array<string, mixed>, body: array<string, mixed>}> */
	private array $posts = [];

	/** @var array<string, array{value: mixed, expiration: int}> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		$this->now        = self::NOW;
		$this->posts      = [];
		$this->transients = [];

		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = array_merge(
			Settings::defaults(),
			[
				'manager_url' => 'https://manager.invalid',
				'site_id'     => self::SITE_ID,
				'site_token'  => self::TOKEN,
			]
		);

		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $text ): string => trim( $text ) );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				$this->posts[] = [
					'url'  => $url,
					'args' => $args,
					'body' => (array) json_decode( (string) $args['body'], true ),
				];
				return [ 'response' => [ 'code' => 202 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 202 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'get_transient' )->alias(
			fn ( string $key ) => isset( $this->transients[ $key ] ) ? $this->transients[ $key ]['value'] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $expiration = 0 ): bool {
				$this->transients[ $key ] = [
					'value'      => $value,
					'expiration' => $expiration,
				];
				return true;
			}
		);

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_la_connexion_echouee_part_sans_bloquer_avec_un_delai_court(): void {
		$this->listener()->on_login_failed( 'admin' );

		self::assertCount( 1, $this->posts );
		self::assertSame( 'https://manager.invalid/api/agent/sites/7/events', $this->posts[0]['url'] );
		self::assertFalse( $this->posts[0]['args']['blocking'], 'La page WordPress n\'attend plus la réponse de la plateforme.' );
		self::assertSame( ManagerClient::NON_BLOCKING_TIMEOUT, $this->posts[0]['args']['timeout'] );
		self::assertLessThanOrEqual( 2, ManagerClient::NON_BLOCKING_TIMEOUT, 'Délai court : plus jamais 15 s par mot de passe essayé.' );
	}

	/** Même adresse, mêmes en-têtes (Bearer), même corps : la plateforme ne voit aucune différence. */
	public function test_le_format_et_l_authentification_de_la_requete_sont_inchanges(): void {
		$this->listener()->on_login_failed( 'admin' );

		$post = $this->posts[0];
		self::assertSame(
			[
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . self::TOKEN,
			],
			$post['args']['headers']
		);
		self::assertSame( [ 'type', 'context', 'at' ], array_keys( $post['body'] ) );
		self::assertSame( 'user.login_failed', $post['body']['type'] );
		self::assertSame(
			[
				'user_login' => 'admin',
				'ip'         => '203.0.113.9',
			],
			$post['body']['context']
		);
	}

	public function test_au_plus_trente_evenements_par_minute_et_par_site(): void {
		$listener = $this->listener();
		for ( $i = 0; $i < 45; $i++ ) {
			$listener->on_login_failed( 'admin' . $i );
		}

		self::assertSame( 30, LoginFailedThrottle::MAX_PER_MINUTE );
		self::assertCount( LoginFailedThrottle::MAX_PER_MINUTE, $this->posts, 'Au-delà du plafond, rien ne part.' );
		self::assertSame( 'admin29', $this->posts[29]['body']['context']['user_login'], 'Les 30 premières tentatives de la minute sont envoyées.' );
		self::assertSame( 45, $this->transients[ LoginFailedThrottle::TRANSIENT_KEY ]['value']['count'], 'Les tentatives au-delà du plafond restent comptées.' );
	}

	/**
	 * Le plafond est un compteur partagé par les requêtes du site (transient), pas
	 * un état de la requête : une nouvelle instance — une nouvelle requête — le voit.
	 */
	public function test_le_plafond_vaut_pour_toutes_les_requetes_du_site(): void {
		for ( $i = 0; $i < 35; $i++ ) {
			$this->listener()->on_login_failed( 'admin' );
		}

		self::assertCount( LoginFailedThrottle::MAX_PER_MINUTE, $this->posts );
	}

	public function test_le_compteur_repart_a_zero_a_la_minute_suivante(): void {
		$listener = $this->listener();
		for ( $i = 0; $i < 40; $i++ ) {
			$listener->on_login_failed( 'admin' );
		}
		self::assertCount( 30, $this->posts );

		$this->now = self::NOW + 60;
		$listener->on_login_failed( 'root' );

		self::assertCount( 31, $this->posts, 'Minute suivante : les envois reprennent.' );
		self::assertSame( 1, $this->transients[ LoginFailedThrottle::TRANSIENT_KEY ]['value']['count'] );
		// Aucun événement récapitulatif : le format existant n'en prévoit pas (cf.
		// LoginFailedThrottle). L'événement repart tel quel, sans champ ajouté.
		self::assertSame( 'user.login_failed', $this->posts[30]['body']['type'] );
		self::assertSame( [ 'user_login', 'ip' ], array_keys( $this->posts[30]['body']['context'] ) );
	}

	/** Le changement de minute se fait à la minute calendaire, pas 60 s après le premier essai. */
	public function test_la_fenetre_est_la_minute_calendaire(): void {
		$listener = $this->listener();
		for ( $i = 0; $i < 30; $i++ ) {
			$listener->on_login_failed( 'admin' );
		}
		$this->now = self::NOW + 39; // Même minute (40 s en tout) : toujours plafonné.
		$listener->on_login_failed( 'admin' );
		self::assertCount( 30, $this->posts );

		$this->now = self::NOW + 40; // Minute suivante.
		$listener->on_login_failed( 'admin' );
		self::assertCount( 31, $this->posts );
	}

	/** Le compteur ne s'accumule pas en base : il expire seul après sa fenêtre. */
	public function test_le_compteur_expire_seul(): void {
		$this->listener()->on_login_failed( 'admin' );

		$expiration = $this->transients[ LoginFailedThrottle::TRANSIENT_KEY ]['expiration'];
		self::assertGreaterThan( 0, $expiration );
		self::assertLessThanOrEqual( 120, $expiration );
	}

	/** Connexions réussies, extensions, mises à jour : envoi bloquant, délai et plafond inchangés. */
	public function test_les_autres_evenements_ne_changent_pas(): void {
		$listener = $this->listener();
		for ( $i = 0; $i < 40; $i++ ) {
			$listener->on_login_failed( 'admin' );
		}
		$this->posts = [];

		$user        = new \WP_User();
		$user->ID    = 3;
		$user->roles = [ 'administrator' ];
		for ( $i = 0; $i < 35; $i++ ) {
			$listener->on_login( 'editeur', $user );
		}
		$listener->on_plugin_activated( 'akismet/akismet.php' );

		self::assertCount( 36, $this->posts, 'Les autres événements ne sont jamais plafonnés.' );
		foreach ( $this->posts as $post ) {
			self::assertArrayNotHasKey( 'blocking', $post['args'], 'Envoi bloquant, comme avant.' );
			self::assertSame( 15, $post['args']['timeout'] );
		}
		self::assertSame( 'user.login', $this->posts[0]['body']['type'] );
		self::assertSame( 'plugin.activated', $this->posts[35]['body']['type'] );
	}

	private function listener(): Listener {
		return new Listener( new LoginFailedThrottle( fn (): int => $this->now ) );
	}
}
