<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use Brain\Monkey\Functions;
use G2RD\Connector\ReleaseNotes\ReleaseNotesCommand;
use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use G2RD\Connector\Tests\TestCase;

/**
 * Banc des tests de la commande `get_release_notes` : un site avec trois extensions (ACF PRO, mise
 * à jour par son éditeur ; Akismet, connu de wordpress.org ; Hello Dolly, un seul fichier, sans
 * entrée de mise à jour) et deux thèmes (Premium Pro, mis à jour par son éditeur ; Twenty
 * Twenty-Five, connu de wordpress.org). Aucune capture des mises à jour tierces par défaut.
 *
 * plugins_api() et themes_api() sont simulées comme WordPress les déroule : réponse des filtres des
 * éditeurs (fixée par le test), puis le garde du connecteur s'il est posé (Brain Monkey note les
 * filtres sans les appeler), puis wordpress.org si personne n'a répondu.
 */
abstract class ReleaseNotesTestCase extends TestCase {

	protected const ACF = 'advanced-custom-fields-pro/acf.php';

	/** @var array<string, mixed> Transients de site, par nom. */
	protected array $transients = [];

	/** Capture des mises à jour tierces (option de réseau `g2rd_updates_snapshot`), false si absente. */
	protected mixed $capture = false;

	/** Réponse des filtres des éditeurs (false : aucun n'a répondu ; une exception est levée). */
	protected mixed $vendor_answer = false;

	/** Réponse de wordpress.org, quand l'appel lui parvient. */
	protected mixed $wporg_answer = false;

	/** @var list<array{0: string, 1: string, 2: array<mixed>}> Appels : fonction, action, arguments. */
	protected array $api_calls = [];

	/** Appels qui seraient partis vers wordpress.org. */
	protected int $wporg_calls = 0;

	/** @var array{guard: bool, limits: bool}|null Crochets posés au moment du dernier appel. */
	protected ?array $during = null;

	protected function setUp(): void {
		parent::setUp();
		$this->transients    = [];
		$this->capture       = false;
		$this->vendor_answer = false;
		$this->wporg_answer  = false;
		$this->api_calls     = [];
		$this->wporg_calls   = 0;
		$this->during        = null;

		Functions\when( 'get_plugins' )->justReturn(
			[
				self::ACF             => [
					'Name'    => 'Advanced Custom Fields PRO',
					'Version' => '6.8.10',
				],
				'akismet/akismet.php' => [
					'Name'    => 'Akismet',
					'Version' => '5.3',
				],
				'hello.php'           => [
					'Name'    => 'Hello Dolly',
					'Version' => '1.7.2',
				],
			]
		);
		Functions\when( 'wp_get_themes' )->justReturn(
			[
				'premium-pro'      => new \WP_Theme(
					'premium-pro',
					[
						'Name'    => 'Premium Pro',
						'Version' => '2.0',
					]
				),
				'twentytwentyfive' => new \WP_Theme(
					'twentytwentyfive',
					[
						'Name'    => 'Twenty Twenty-Five',
						'Version' => '1.3',
					]
				),
			]
		);
		Functions\when( 'get_site_transient' )->alias( fn ( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'get_site_option' )->alias( fn ( string $name, $fallback = false ) => 'g2rd_updates_snapshot' === $name ? $this->capture : $fallback );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'plugins_api' )->alias( fn ( string $action, $args = [] ) => $this->api( 'plugins_api', $action, $args ) );
		Functions\when( 'themes_api' )->alias( fn ( string $action, $args = [] ) => $this->api( 'themes_api', $action, $args ) );
	}

	protected function command(): ReleaseNotesCommand {
		return new ReleaseNotesCommand();
	}

	/**
	 * Code du refus de la commande pour cette charge ; échoue si elle n'est pas refusée.
	 *
	 * @param array<mixed> $payload
	 */
	protected function refusal_of( array $payload ): string {
		try {
			$this->command()->run( $payload );
		} catch ( ReleaseNotesRefused $e ) {
			return $e->reason_code();
		}
		self::fail( 'La commande devait être refusée pour ' . (string) json_encode( $payload ) );
	}

	protected function assert_nothing_left(): void {
		foreach ( [ 'plugins_api', 'themes_api', 'http_request_args', 'pre_http_request' ] as $hook ) {
			self::assertFalse( has_filter( $hook ), $hook . ' : filtre resté posé après la commande.' );
		}
		self::assertFalse( has_action( 'requests-requests.before_redirect' ), 'Action des redirections restée posée après la commande.' );
	}

	/** Réponse d'ACF PRO à plugins_api(), comme son updater la rend. */
	protected static function acf_answer(): \stdClass {
		return (object) [
			'name'     => 'Advanced Custom Fields PRO',
			'slug'     => 'advanced-custom-fields-pro',
			'version'  => '6.8.11',
			'homepage' => 'https://www.advancedcustomfields.com/',
			'sections' => [
				'description' => '<p>Advanced Custom Fields PRO.</p>',
				'changelog'   => '<h4>6.8.11</h4><ul><li>Fix: field groups.</li></ul>',
			],
		];
	}

	/** Entrée posée par l'updater d'ACF PRO. */
	protected static function acf_entry(): \stdClass {
		return (object) [
			'slug'        => 'advanced-custom-fields-pro',
			'plugin'      => self::ACF,
			'new_version' => '6.8.11',
			'url'         => 'https://www.advancedcustomfields.com/',
			'package'     => 'https://connect.advancedcustomfields.com/v2/plugins/download?p=pro',
		];
	}

	/** `update_plugins` : ACF PRO annoncé par son éditeur, Akismet connu de wordpress.org, rien pour Hello Dolly. */
	protected static function update_plugins(): \stdClass {
		return (object) [
			'last_checked' => 1789000000,
			'response'     => [ self::ACF => self::acf_entry() ],
			'no_update'    => [
				'akismet/akismet.php' => (object) [
					'id'          => 'w.org/plugins/akismet',
					'slug'        => 'akismet',
					'plugin'      => 'akismet/akismet.php',
					'new_version' => '5.3',
					'url'         => 'https://wordpress.org/plugins/akismet/',
					'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
				],
			],
		];
	}

	/** `update_themes` : Premium Pro annoncé par son éditeur, Twenty Twenty-Five connu de wordpress.org. */
	protected static function update_themes(): \stdClass {
		return (object) [
			'last_checked' => 1789000000,
			'response'     => [
				'premium-pro' => [
					'theme'       => 'premium-pro',
					'new_version' => '2.1',
					'url'         => 'https://premium.example/changelog/',
					'package'     => 'https://premium.example/download/premium-pro.zip',
				],
			],
			'no_update'    => [
				'twentytwentyfive' => [
					'theme'       => 'twentytwentyfive',
					'new_version' => '1.3',
					'url'         => 'https://wordpress.org/themes/twentytwentyfive/',
					'package'     => 'https://downloads.wordpress.org/theme/twentytwentyfive.1.3.zip',
				],
			],
		];
	}

	/**
	 * plugins_api() / themes_api() simulées (cf. le docblock de la classe).
	 *
	 * @param mixed $args
	 * @return mixed
	 */
	private function api( string $function, string $action, $args ) {
		$this->api_calls[] = [ $function, $action, (array) $args ];
		$this->during      = [
			'guard'  => false !== has_filter( $function, [ ReleaseNotesCommand::class, 'no_vendor_answer' ] ),
			'limits' => has_filter( 'http_request_args' ) && has_filter( 'pre_http_request' ) && has_action( 'requests-requests.before_redirect' ),
		];
		$result = $this->vendor_answer;
		if ( $result instanceof \Throwable ) {
			throw $result;
		}
		if ( $this->during['guard'] ) {
			$result = ReleaseNotesCommand::no_vendor_answer( $result );
		}
		if ( ! $result ) {
			++$this->wporg_calls;
			$result = $this->wporg_answer;
		}

		return $result;
	}
}
