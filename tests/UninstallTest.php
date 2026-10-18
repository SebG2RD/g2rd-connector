<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Cron\Phase;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Environment\SizeReport;
use G2RD\Connector\Events\LoginFailedThrottle;
use G2RD\Connector\Rest\DbHealth;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;
use G2RD\Connector\Tests\DirectLogin\FakeWpdb;
use G2RD\Connector\Updates\WporgAnswers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Désinstallation (uninstall.php) : ce que l'allègement du connecteur a ajouté est
 * retiré comme le reste — le compteur des connexions échouées, sur chaque blog en
 * multisite, et un contrôle de reprise ponctuel encore planifié.
 *
 * WordPress inclut uninstall.php sans charger le plugin ; le test fait de même,
 * dans un multisite simulé de deux blogs.
 */
final class UninstallTest extends TestCase {

	private int $blog = 1;

	/** @var list<string> Transients retirés, « blog:nom ». */
	private array $deleted_transients = [];

	/** @var list<string> Hooks dont la planification est retirée. */
	private array $cleared_hooks = [];

	protected function setUp(): void {
		parent::setUp();
		$this->blog               = 1;
		$this->deleted_transients = [];
		$this->cleared_hooks      = [];

		$GLOBALS['wpdb'] = new FakeWpdb(
			fn (): array => array_map( 'strval', array_keys( $this->options ) ),
			static function (): void {}
		);

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( [ 1, 2 ] );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $blog_id ): bool {
				$this->blog = $blog_id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				$this->blog = 1;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$this->deleted_transients[] = $this->blog . ':' . $name;
				return true;
			}
		);
		Functions\when( 'delete_site_option' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->alias( static fn ( string $file ): string => dirname( $file ) . '/' );
		// Aucun dossier de points de restauration sur ce site.
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => 'g2rd_connector_snapshots_dir' === $hook ? sys_get_temp_dir() . '/g2rd-uninstall-absent' : $value
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_unschedule_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook ): int {
				$this->cleared_hooks[] = $hook;
				return 0;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_le_compteur_des_connexions_echouees_est_retire_sur_chaque_blog(): void {
		$this->uninstall();

		self::assertContains( '1:' . LoginFailedThrottle::TRANSIENT_KEY, $this->deleted_transients );
		self::assertContains( '2:' . LoginFailedThrottle::TRANSIENT_KEY, $this->deleted_transients );
	}

	public function test_la_purge_et_le_controle_de_reprise_sont_deplanifies(): void {
		$this->uninstall();

		self::assertContains( RestorePointPurgeJob::HOOK, $this->cleared_hooks );
		self::assertContains( RestorePointPurgeJob::RECOVERY_HOOK, $this->cleared_hooks );
	}

	public function test_la_trace_de_reprise_d_une_mise_a_jour_protegee_est_retiree(): void {
		$this->options[ UpdateTransaction::RECOVERY_TRACE_KEY ] = [
			'txn' => 'x|a/a.php|1',
			'at'  => 1,
		];

		$this->uninstall();

		self::assertArrayNotHasKey( UpdateTransaction::RECOVERY_TRACE_KEY, $this->options );
	}

	/** Marqueur de la répartition des tâches WP-Cron (cf. Cron\Phase), sur chaque blog en multisite. */
	public function test_le_marqueur_de_repartition_des_taches_est_retire(): void {
		$deleted = [];
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $this->blog . ':' . $key;
				unset( $this->options[ $key ] );
				return true;
			}
		);

		$this->uninstall();

		self::assertContains( '1:' . Phase::OPTION, $deleted );
		self::assertContains( '2:' . Phase::OPTION, $deleted );
	}

	/** Santé de la base gardée 24 h par l'inventaire (cf. Rest\DbHealth). */
	public function test_la_sante_de_la_base_gardee_par_l_inventaire_est_retiree(): void {
		$this->options[ DbHealth::OPTION ] = [
			'at'     => 1,
			'health' => [],
		];

		$this->uninstall();

		self::assertArrayNotHasKey( DbHealth::OPTION, $this->options );
	}

	/** En multisite, l'inventaire de chaque blog garde sa propre santé de la base : retirée sur chacun. */
	public function test_la_sante_de_la_base_est_retiree_sur_chaque_blog(): void {
		$deleted = [];
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $this->blog . ':' . $key;
				unset( $this->options[ $key ] );
				return true;
			}
		);

		$this->uninstall();

		self::assertContains( '1:' . DbHealth::OPTION, $deleted );
		self::assertContains( '2:' . DbHealth::OPTION, $deleted );
	}

	/**
	 * Réseau de plus de 100 blogs : get_sites() n'en rend que 100 par défaut
	 * (WP_Site_Query, `number` = 100), tous avec `'number' => 0`. Les options de chaque
	 * blog — dont `g2rd_connector_settings`, qui porte le jeton chiffré du site — sont
	 * retirées sur tous, pas seulement sur les 100 premiers.
	 */
	public function test_les_options_de_chaque_blog_sont_retirees_au_dela_de_100_blogs(): void {
		Functions\when( 'get_sites' )->alias(
			static function ( array $args = [] ): array {
				$number = $args['number'] ?? 100;
				return range( 1, 0 === $number ? 150 : min( 150, (int) $number ) );
			}
		);
		$deleted = [];
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $this->blog . ':' . $key;
				unset( $this->options[ $key ] );
				return true;
			}
		);

		$this->uninstall();

		foreach ( [ Settings::OPTION_KEY, Phase::OPTION, DbHealth::OPTION ] as $option ) {
			self::assertContains( '150:' . $option, $deleted, $option );
		}
		self::assertContains( '150:' . LoginFailedThrottle::TRANSIENT_KEY, $this->deleted_transients );
	}

	/** Heure des réponses de wordpress.org (cf. Updates\WporgAnswers) : option de réseau, comme les transients de mise à jour. */
	public function test_l_heure_des_reponses_de_wordpress_org_est_retiree(): void {
		$deleted = [];
		Functions\when( 'delete_site_option' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		$this->uninstall();

		self::assertContains( WporgAnswers::OPTION, $deleted );
	}

	/**
	 * Site simple, puis multisite de deux blogs.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function sites(): array {
		return [
			'site simple' => [ false ],
			'multisite'   => [ true ],
		];
	}

	/**
	 * Exécutions en arrière-plan (cf. CommandRuns\RunStore) : les enregistrements, terminés
	 * ou non, et le créneau sont retirés de la table d'options du site, et de celle de
	 * chaque blog en multisite (chaque blog a la sienne). Les options des autres
	 * extensions restent.
	 */
	#[DataProvider( 'sites' )]
	public function test_les_executions_et_le_creneau_sont_supprimes( bool $multisite ): void {
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		$avant = [
			RunStore::option_name( str_repeat( 'a', 32 ) ) => [
				'value'    => '{"command_id":"' . str_repeat( 'a', 32 ) . '","state":"done"}',
				'autoload' => 'no',
			],
			RunStore::option_name( str_repeat( 'b', 32 ) ) => [
				'value'    => '{"command_id":"' . str_repeat( 'b', 32 ) . '","state":"running"}',
				'autoload' => 'no',
			],
			'g2rd_cmdrun_slot'                             => [
				'value'    => '{"command_id":"' . str_repeat( 'b', 32 ) . '"}',
				'autoload' => 'no',
			],
			'option_d_une_autre_extension'                 => [
				'value'    => 'gardée',
				'autoload' => 'yes',
			],
		];
		$tables = [];
		foreach ( [ 1, 2 ] as $blog_id ) {
			$tables[ $blog_id ]       = new FakeOptionsTable();
			$tables[ $blog_id ]->rows = $avant;
		}
		// Chaque blog a sa table d'options : changer de blog, c'est changer de table.
		$GLOBALS['wpdb'] = $tables[1];
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $blog_id ) use ( $tables ): bool {
				$this->blog      = $blog_id;
				$GLOBALS['wpdb'] = $tables[ $blog_id ];
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () use ( $tables ): bool {
				$this->blog      = 1;
				$GLOBALS['wpdb'] = $tables[1];
				return true;
			}
		);

		$this->uninstall();

		self::assertSame( [ 'option_d_une_autre_extension' ], array_keys( $tables[1]->rows ), 'Blog courant.' );
		self::assertSame(
			$multisite ? [ 'option_d_une_autre_extension' ] : array_keys( $avant ),
			array_keys( $tables[2]->rows ),
			$multisite ? 'Second blog du réseau.' : 'Hors multisite, aucune autre table n\'est touchée.'
		);
	}

	/** Volumes mesurés chaque jour (cf. Environment\SizeReport) : option de réseau, une seule même en multisite. */
	public function test_la_mesure_de_l_environnement_est_supprimee(): void {
		$deleted = [];
		Functions\when( 'delete_site_option' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		$this->uninstall();

		self::assertContains( SizeReport::OPTION, $deleted );
	}

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'g2rd-connector/g2rd-connector.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';
	}
}
