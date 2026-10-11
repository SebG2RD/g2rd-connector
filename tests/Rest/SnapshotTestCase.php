<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\PremiumUpdatesBridge;

/**
 * Banc d'essai de l'inventaire (GET /snapshot) : un WordPress simulé autour de
 * SnapshotController::handle(), dont on note ce qui coûte sur un serveur partagé —
 * les trois appels à wordpress.org (wp_version_check, wp_update_plugins,
 * wp_update_themes), les vidages de cache et les requêtes de santé de la base.
 *
 * Deux extensions (Akismet, active ; SEOPress PRO, dont l'updater ne s'enregistre
 * pas en REST), trois thèmes (Twenty Twenty-Five, connu de wordpress.org ; un thème
 * enfant actif ; un thème premium).
 */
abstract class SnapshotTestCase extends TestCase {

	protected const WP_VERSION = '6.9.4';

	/** Versions des thèmes installés, comme WordPress les envoie à wordpress.org (`checked`). */
	protected const THEME_VERSIONS = [
		'twentytwentyfive' => '1.3',
		'g2rd-child'       => '1.0.0',
		'premium-pro'      => '2.0',
	];

	/** @var array<string, mixed> Transients de site, par nom. */
	protected array $transients = [];

	/** @var array<string, list<array<mixed>>> Appels notés, par fonction : leurs arguments. */
	protected array $calls = [];

	protected SnapshotWpdb $wpdb;

	/** Capture des mises à jour tierces (option `g2rd_updates_snapshot`), false si absente. */
	protected mixed $capture = false;

	/** @var array<string, string> En-tête `Version:` sur le disque, par fichier d'extension. */
	protected array $plugin_versions = [
		'akismet/akismet.php'              => '5.3',
		'wp-seopress-pro/seopress-pro.php' => '8.0',
	];

	/**
	 * Ce que wordpress.org répond à wp_update_themes() (entrées `response` et
	 * `no_update`, au format du transient `update_themes`).
	 *
	 * @var array{response: array<string, array<string, string>>, no_update: array<string, array<string, string>>}
	 */
	protected array $wporg_themes = [
		'response'  => [],
		'no_update' => [],
	];

	/** @var list<string> Fichiers d'extensions créés par le test, retirés ensuite. */
	private array $created = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transients   = [];
		$this->calls        = [];
		$this->wpdb         = new SnapshotWpdb();
		$this->wporg_themes = [
			'response'  => [],
			'no_update' => [
				'twentytwentyfive' => self::wporg_theme( 'twentytwentyfive', '1.3' ),
			],
		];
		// Capture tierce d'il y a une heure : la découverte n'est pas en retard.
		$this->capture = [
			'plugins'     => [],
			'themes'      => [],
			'captured_at' => gmdate( 'c', time() - 3600 ),
		];

		$GLOBALS['wpdb']       = $this->wpdb;
		$GLOBALS['wp_version'] = self::WP_VERSION;
		$this->create_plugin_files();

		$this->stub_site();
		$this->stub_extensions_and_themes();
		$this->stub_caches_and_transients();
	}

	protected function tearDown(): void {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			if ( is_dir( dirname( $file ) ) && [] === array_diff( (array) scandir( dirname( $file ) ), [ '.', '..' ] ) ) {
				rmdir( dirname( $file ) );
			}
		}
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_version'] );
		parent::tearDown();
	}

	/**
	 * Réponse de GET /snapshot.
	 *
	 * @return array<string, mixed>
	 */
	protected function snapshot(): array {
		return (array) ( new SnapshotController() )->handle()->get_data();
	}

	/** Nombre d'appels notés d'une fonction. */
	protected function calls_to( string $function ): int {
		return count( $this->calls[ $function ] ?? [] );
	}

	/** Les trois transients de mise à jour, vérifiés il y a `$age` secondes. */
	protected function update_transients_checked( int $age ): void {
		$at = time() - $age;

		$this->transients['update_core']    = (object) [
			'last_checked'    => $at,
			'version_checked' => self::WP_VERSION,
			'updates'         => [ self::latest_core_offer() ],
			'translations'    => [],
		];
		$this->transients['update_plugins'] = (object) [
			'last_checked' => $at,
			'response'     => [],
			'no_update'    => [],
			'checked'      => $this->plugin_versions,
		];
		$this->transients['update_themes']  = (object) ( [
			'last_checked' => $at,
			'checked'      => self::THEME_VERSIONS,
		] + $this->wporg_themes );
	}

	/** Offre « à jour » que wordpress.org rend toujours (api.wordpress.org/core/version-check/1.7/). */
	protected static function latest_core_offer(): object {
		return (object) [
			'response' => 'latest',
			'current'  => self::WP_VERSION,
			'version'  => self::WP_VERSION,
			'locale'   => 'fr_FR',
		];
	}

	/**
	 * Entrée de thème telle que wordpress.org la rend (api.wordpress.org/themes/update-check/1.1/).
	 *
	 * @return array<string, string>
	 */
	protected static function wporg_theme( string $slug, string $version ): array {
		return [
			'theme'        => $slug,
			'new_version'  => $version,
			'url'          => 'https://wordpress.org/themes/' . $slug . '/',
			'package'      => 'https://downloads.wordpress.org/theme/' . $slug . '.' . $version . '.zip',
			'requires'     => '6.7',
			'requires_php' => '7.2',
		];
	}

	/**
	 * Forme d'une valeur : clés et types, récursivement (une liste est décrite par
	 * son premier élément). Deux réponses de même forme donnent le même JSON au
	 * détail des valeurs près.
	 */
	protected static function shape( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return get_debug_type( $value );
		}
		if ( array_is_list( $value ) ) {
			return [] === $value ? 'list' : [ 'list' => self::shape( $value[0] ) ];
		}
		$out = [];
		foreach ( $value as $key => $child ) {
			$out[ $key ] = self::shape( $child );
		}
		return $out;
	}

	private function stub_site(): void {
		Functions\when( 'get_bloginfo' )->alias(
			static fn ( string $show = '' ): string => [
				'version'     => self::WP_VERSION,
				'name'        => 'Site test',
				'admin_email' => 'admin@site.test',
				'language'    => 'fr-FR',
			][ $show ] ?? ''
		);
		Functions\when( 'home_url' )->justReturn( 'https://site.test/' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Europe/Paris' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'wp_login_url' )->justReturn( 'https://site.test/wp-login.php' );
		Functions\when( 'get_users' )->justReturn( [] );
		Functions\when( 'get_core_updates' )->alias(
			function () {
				$core = $this->transients['update_core'] ?? false;
				return is_object( $core ) && isset( $core->updates ) ? $core->updates : false;
			}
		);
		Functions\when( 'wp_get_translation_updates' )->justReturn( [] );
		Functions\when( 'get_available_languages' )->justReturn( [ 'fr_FR' ] );
		Functions\when( 'get_locale' )->justReturn( 'fr_FR' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_count_comments' )->justReturn(
			(object) [
				'spam'     => 3,
				'approved' => 10,
			]
		);
		Functions\when( 'get_filesystem_method' )->justReturn( 'ftpext' );
		Functions\when( 'get_site_option' )->alias( fn () => $this->capture );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
	}

	private function stub_extensions_and_themes(): void {
		$this->options['active_plugins'] = [ 'akismet/akismet.php' ];
		Functions\when( 'get_plugins' )->justReturn(
			[
				'akismet/akismet.php'              => [
					'Name'      => 'Akismet',
					'Version'   => '5.3',
					'PluginURI' => 'https://akismet.com/',
					'Author'    => 'Automattic',
				],
				'wp-seopress-pro/seopress-pro.php' => [
					'Name'      => 'SEOPress PRO',
					'Version'   => '8.0',
					'PluginURI' => 'https://www.seopress.org/',
					'Author'    => 'The SEO Guys',
				],
			]
		);
		Functions\when( 'get_plugin_data' )->alias(
			fn ( string $path ): array => [ 'Version' => $this->plugin_versions[ substr( $path, strlen( WP_PLUGIN_DIR ) + 1 ) ] ?? '' ]
		);

		$themes = [
			'twentytwentyfive' => new \WP_Theme(
				'twentytwentyfive',
				[
					'Name'     => 'Twenty Twenty-Five',
					'Version'  => '1.3',
					'ThemeURI' => 'https://wordpress.org/themes/twentytwentyfive/',
					'Author'   => 'the WordPress team',
				]
			),
			'g2rd-child'       => new \WP_Theme(
				'g2rd-child',
				[
					'Name'     => 'G2RD enfant',
					'Version'  => '1.0.0',
					'ThemeURI' => 'https://g2rd.fr/',
					'Author'   => 'G2RD',
				]
			),
			'premium-pro'      => new \WP_Theme(
				'premium-pro',
				[
					'Name'     => 'Premium Pro',
					'Version'  => '2.0',
					'ThemeURI' => 'https://premium.example/',
					'Author'   => 'Premium Inc.',
				]
			),
		];
		Functions\when( 'wp_get_themes' )->justReturn( $themes );
		Functions\when( 'wp_get_theme' )->alias(
			static fn ( ?string $stylesheet = null ): \WP_Theme => $themes[ $stylesheet ?? 'g2rd-child' ] ?? new \WP_Theme( (string) $stylesheet, [], false )
		);
		Functions\when( 'get_theme_root' )->justReturn( WP_CONTENT_DIR . '/themes' );
	}

	private function stub_caches_and_transients(): void {
		foreach ( [ 'wp_cache_flush', 'wp_clean_plugins_cache', 'wp_clean_themes_cache' ] as $function ) {
			$this->record( $function, null );
		}
		$this->record( 'wp_cache_delete', true );

		Functions\when( 'get_site_transient' )->alias( fn ( string $name ) => $this->read_transient( $name ) );
		Functions\when( 'set_site_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_site_transient' )->alias(
			function ( string $name ): bool {
				$this->calls['delete_site_transient'][] = [ $name ];
				unset( $this->transients[ $name ] );
				return true;
			}
		);

		// Les trois appels à wordpress.org : notés, et le transient qu'ils écrivent.
		Functions\when( 'wp_version_check' )->alias(
			function ( ...$args ): void {
				$this->calls['wp_version_check'][] = $args;
				$this->transients['update_core']   = (object) [
					'last_checked'    => time(),
					'version_checked' => self::WP_VERSION,
					'updates'         => [ self::latest_core_offer() ],
					'translations'    => [],
				];
			}
		);
		Functions\when( 'wp_update_plugins' )->alias(
			function ( ...$args ): void {
				$this->calls['wp_update_plugins'][] = $args;
				$this->transients['update_plugins'] = (object) [
					'last_checked' => time(),
					'response'     => [],
					'no_update'    => [],
					'checked'      => $this->plugin_versions,
				];
			}
		);
		Functions\when( 'wp_update_themes' )->alias(
			function ( ...$args ): void {
				$this->calls['wp_update_themes'][] = $args;
				$this->transients['update_themes']  = (object) ( [
					'last_checked' => time(),
					'checked'      => self::THEME_VERSIONS,
				] + $this->wporg_themes );
			}
		);
	}

	/**
	 * Lecture d'un transient comme get_site_transient() : une copie, passée par les
	 * filtres de rejeu du pont s'ils sont branchés (Brain Monkey note add_filter()
	 * sans jamais appeler les fonctions branchées).
	 */
	private function read_transient( string $name ): mixed {
		$value = $this->transients[ $name ] ?? false;
		$value = is_object( $value ) ? clone $value : $value;
		if ( 'update_plugins' === $name && false !== has_filter( 'site_transient_update_plugins', [ PremiumUpdatesBridge::class, 'replay_plugins' ] ) ) {
			$value = PremiumUpdatesBridge::replay_plugins( $value );
		}
		if ( 'update_themes' === $name && false !== has_filter( 'site_transient_update_themes', [ PremiumUpdatesBridge::class, 'replay_themes' ] ) ) {
			$value = PremiumUpdatesBridge::replay_themes( $value );
		}
		return $value;
	}

	private function record( string $function, mixed $return ): void {
		Functions\when( $function )->alias(
			function ( ...$args ) use ( $function, $return ) {
				$this->calls[ $function ][] = $args;
				return $return;
			}
		);
	}

	/** Fichiers principaux des extensions, lisibles (la version est relue sur le disque). */
	private function create_plugin_files(): void {
		$this->created = [];
		foreach ( array_keys( $this->plugin_versions ) as $file ) {
			$path = WP_PLUGIN_DIR . '/' . $file;
			if ( is_file( $path ) ) {
				continue;
			}
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, "<?php\n/* Plugin Name: test */\n" );
			$this->created[] = $path;
		}
	}
}
