<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Updates;

use Brain\Monkey\Functions;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\PremiumUpdatesBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Rejeu de la capture des mises à jour tierces en LECTURE SEULE (1.18.0) : l'entrée que le rejeu de
 * l'inventaire ajouterait à `response`, pour que la commande get_release_notes voie la même
 * provenance que l'inventaire, sans rafraîchir, brancher ni écrire quoi que ce soit.
 *
 * Processus séparés : simuler get_file_data() la définit pour toute la suite, et
 * SnapshotController::opcache_disk_version() comme PhpErrors\Normalizer testent function_exists().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PremiumUpdatesBridgeReplayEntryTest extends TestCase {

	private const FILE = 'banc-premium/banc-premium.php';

	private const THEME = 'banc-theme-premium';

	/** Capture (option de réseau `g2rd_updates_snapshot`), false si absente. */
	private mixed $capture = false;

	/** @var list<string> Écritures et branchements tentés. */
	private array $ecritures = [];

	private string $plugin_path = '';

	private string $theme_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->capture   = false;
		$this->ecritures = [];
		Functions\when( 'get_site_option' )->alias( fn ( string $name, $fallback = false ) => 'g2rd_updates_snapshot' === $name ? $this->capture : $fallback );
		foreach ( [ 'update_site_option', 'update_option', 'set_site_transient', 'delete_site_transient', 'add_filter' ] as $fonction ) {
			Functions\when( $fonction )->alias(
				function () use ( $fonction ): bool {
					$this->ecritures[] = $fonction;
					return true;
				}
			);
		}
		Functions\when( 'get_plugin_data' )->justReturn( [ 'Version' => '8.0' ] );
		Functions\when( 'get_file_data' )->justReturn( [ 'Version' => '2.0' ] );

		$this->plugin_path = WP_PLUGIN_DIR . '/' . self::FILE;
		if ( ! is_dir( dirname( $this->plugin_path ) ) ) {
			mkdir( dirname( $this->plugin_path ), 0777, true );
		}
		file_put_contents( $this->plugin_path, "<?php\n/* Plugin Name: test */\n" );

		$this->theme_dir = WP_CONTENT_DIR . '/themes/' . self::THEME;
		if ( ! is_dir( $this->theme_dir ) ) {
			mkdir( $this->theme_dir, 0777, true );
		}
		file_put_contents( $this->theme_dir . '/style.css', "/* Theme Name: test */\n" );
		$dir = $this->theme_dir;
		Functions\when( 'wp_get_theme' )->alias( static fn ( string $stylesheet ): \WP_Theme => new \WP_Theme( $stylesheet, [], self::THEME === $stylesheet, $dir ) );
	}

	protected function tearDown(): void {
		foreach ( [ $this->plugin_path, $this->theme_dir . '/style.css' ] as $fichier ) {
			if ( is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}
		foreach ( [ dirname( $this->plugin_path ), $this->theme_dir ] as $dossier ) {
			if ( is_dir( $dossier ) ) {
				rmdir( $dossier );
			}
		}
		parent::tearDown();
	}

	public function test_une_entree_d_extension_plus_recente_est_rendue(): void {
		$this->capture = self::capture( [ self::FILE => self::entree_extension( '8.1' ) ], [] );

		self::assertSame(
			[
				'slug'        => 'banc-premium',
				'plugin'      => self::FILE,
				'new_version' => '8.1',
				'package'     => 'https://premium.example/download/banc-premium.zip',
				'url'         => 'https://premium.example/',
			],
			PremiumUpdatesBridge::replay_entry( 'plugins', self::FILE )
		);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function versions_pas_plus_recentes(): iterable {
		yield 'même version' => [ '8.0' ];
		yield 'plus ancienne' => [ '7.9' ];
	}

	#[DataProvider( 'versions_pas_plus_recentes' )]
	public function test_une_entree_qui_n_est_pas_plus_recente_n_est_pas_rejouee( string $version ): void {
		$this->capture = self::capture( [ self::FILE => self::entree_extension( $version ) ], [] );

		self::assertNull( PremiumUpdatesBridge::replay_entry( 'plugins', self::FILE ) );
	}

	public function test_sans_capture_ou_capture_perimee_rien(): void {
		self::assertNull( PremiumUpdatesBridge::replay_entry( 'plugins', self::FILE ) );

		$this->capture = self::capture( [ self::FILE => self::entree_extension( '8.1' ) ], [], 8 * DAY_IN_SECONDS );

		self::assertNull( PremiumUpdatesBridge::replay_entry( 'plugins', self::FILE ) );
	}

	public function test_une_autre_extension_ou_une_version_installee_illisible_rien(): void {
		$this->capture = self::capture(
			[
				self::FILE            => self::entree_extension( '8.1' ),
				'absente/absente.php' => self::entree_extension( '8.1' ),
			],
			[]
		);

		self::assertNull( PremiumUpdatesBridge::replay_entry( 'plugins', 'hello.php' ) );
		self::assertNull( PremiumUpdatesBridge::replay_entry( 'plugins', 'absente/absente.php' ) );
	}

	public function test_une_entree_de_theme_plus_recente_est_rendue(): void {
		$this->capture = self::capture( [], [ self::THEME => self::entree_theme() ] );

		self::assertSame(
			[
				'theme'       => self::THEME,
				'new_version' => '2.1',
				'package'     => 'https://premium.example/download/theme.zip',
				'url'         => 'https://premium.example/changelog/',
			],
			PremiumUpdatesBridge::replay_entry( 'themes', self::THEME )
		);
	}

	#[DataProvider( 'versions_pas_plus_recentes_du_theme' )]
	public function test_une_entree_de_theme_qui_n_est_pas_plus_recente_n_est_pas_rejouee( string $version ): void {
		$this->capture = self::capture( [], [ self::THEME => [ 'new_version' => $version ] + self::entree_theme() ] );

		self::assertNull( PremiumUpdatesBridge::replay_entry( 'themes', self::THEME ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function versions_pas_plus_recentes_du_theme(): iterable {
		yield 'même version' => [ '2.0' ];
		yield 'plus ancienne' => [ '1.9' ];
	}

	public function test_un_theme_absent_rien(): void {
		$this->capture = self::capture( [], [ 'theme-absent' => [ 'theme' => 'theme-absent' ] + self::entree_theme() ] );

		self::assertNull( PremiumUpdatesBridge::replay_entry( 'themes', 'theme-absent' ) );
	}

	public function test_rien_n_est_ecrit_ni_branche(): void {
		$this->capture = self::capture( [ self::FILE => self::entree_extension( '8.1' ) ], [ self::THEME => self::entree_theme() ] );

		PremiumUpdatesBridge::replay_entry( 'plugins', self::FILE );
		PremiumUpdatesBridge::replay_entry( 'themes', self::THEME );
		PremiumUpdatesBridge::replay_entry( 'plugins', 'hello.php' );

		self::assertSame( [], $this->ecritures );
	}

	private static function entree_extension( string $version ): \stdClass {
		return (object) [
			'slug'        => 'banc-premium',
			'plugin'      => self::FILE,
			'new_version' => $version,
			'url'         => 'https://premium.example/',
			'package'     => 'https://premium.example/download/banc-premium.zip',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function entree_theme(): array {
		return [
			'theme'       => self::THEME,
			'new_version' => '2.1',
			'url'         => 'https://premium.example/changelog/',
			'package'     => 'https://premium.example/download/theme.zip',
		];
	}

	/**
	 * @param array<string, mixed> $plugins
	 * @param array<string, mixed> $themes
	 * @return array<string, mixed>
	 */
	private static function capture( array $plugins, array $themes, int $age = 3600 ): array {
		return [
			'plugins'     => $plugins,
			'themes'      => $themes,
			'captured_at' => gmdate( 'c', time() - $age ),
		];
	}
}
