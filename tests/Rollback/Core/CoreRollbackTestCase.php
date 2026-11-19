<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Brain\Monkey\Functions;
use Core_Upgrader;
use G2RD\Connector\Rollback\Core\CorePackage;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;
use G2RD\Connector\Tests\TestCase;
use WP_Error;

/**
 * Banc commun du retour arrière du cœur (connecteur 1.18.0) : wordpress.org simulé (SHA-1 publié,
 * paquet, empreintes des fichiers), Core_Upgrader simulé qui écrit sous ABSPATH les fichiers de la
 * version de l'offre, contrôle de santé réglable (et observable sonde par sonde), `db_version` lue en
 * base (FakeOptionsTable), transaction reprise par un autre processus (take_over()).
 * Aucune requête réseau ; les fichiers écrits sont retirés après chaque essai.
 */
abstract class CoreRollbackTestCase extends TestCase {

	/** Fichiers du cœur simulés : chemin relatif ⇒ contenu par version. */
	protected const FILES = [
		'wp-settings.php'         => [
			'7.1.4' => "<?php // 7.1.4\n",
			'7.1.5' => "<?php // 7.1.5\n",
		],
		'wp-includes/version.php' => [
			'7.1.4' => "<?php \$wp_version = '7.1.4';\n",
			'7.1.5' => "<?php \$wp_version = '7.1.5';\n",
		],
	];

	protected FakeOptionsTable $table;

	protected HealthChecker $health;

	/** Version que WordPress a chargée au début de la requête (`get_bloginfo( 'version' )`). */
	protected string $loaded_version = '7.1.5';

	/** Le site répond-il normalement aux sondes du contrôle de santé ? */
	protected bool $site_ok = true;

	/** Version qui casse le site une fois installée (null : aucune). */
	protected ?string $breaks_on = null;

	/** Sondes du contrôle de santé faites depuis le début de l'essai (deux par mesure : accueil, administration). */
	protected int $probes = 0;

	/** @var (\Closure(int): void)|null Appelée avant chaque sonde, avec son numéro (1 et 2 : première mesure). */
	protected ?\Closure $on_probe = null;

	/** Contenu servi pour chaque paquet téléchargé. */
	protected string $zip = 'paquet-wordpress';

	/**
	 * Réponses de wordpress.org par adresse ; une adresse absente = erreur réseau.
	 *
	 * @var array<string, array{code: int, body: string}>
	 */
	protected array $wporg = [];

	/** @var list<string> Adresses demandées (petites requêtes et téléchargements), dans l'ordre. */
	protected array $requested = [];

	/** @var list<string> Fichiers temporaires rendus par download_url(). */
	protected array $downloads = [];

	protected function setUp(): void {
		parent::setUp();
		Core_Upgrader::reset();
		unset( $GLOBALS['wp_local_package'] );
		$this->table                     = new FakeOptionsTable();
		$this->table->rows['db_version'] = [
			'value'    => '61833',
			'autoload' => 'yes',
		];
		$GLOBALS['wpdb']      = $this->table;
		$this->loaded_version = '7.1.5';
		$this->site_ok        = true;
		$this->breaks_on      = null;
		$this->probes         = 0;
		$this->on_probe       = null;
		$this->zip            = 'paquet-wordpress';
		$this->wporg          = [];
		$this->requested      = [];
		$this->downloads      = [];
		$this->publish( '7.1.4', 'en_US' );
		$this->install( '7.1.5' );

		Functions\when( 'get_bloginfo' )->alias( fn ( string $show = '' ): string => 'version' === $show ? $this->loaded_version : '' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $value ): bool => $value instanceof WP_Error );
		Functions\when( 'get_filesystem_method' )->justReturn( 'direct' );
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url ) {
				$this->requested[] = $url;
				if ( ! isset( $this->wporg[ $url ] ) ) {
					return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
				}
				return [
					'response' => [ 'code' => $this->wporg[ $url ]['code'] ],
					'body'     => $this->wporg[ $url ]['body'],
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $response ) => is_array( $response ) ? $response['response']['code'] : '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $response ) => is_array( $response ) ? $response['body'] : '' );
		Functions\when( 'download_url' )->alias(
			function ( string $url ) {
				$this->requested[] = $url;
				if ( 200 !== ( $this->wporg[ $url ]['code'] ?? 0 ) ) {
					return new WP_Error( 'http_404', 'Not Found' );
				}
				$file = (string) tempnam( sys_get_temp_dir(), 'g2rd-core-' );
				file_put_contents( $file, $this->zip );
				$this->downloads[] = $file;
				return $file;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		// Contrôle de santé (HealthChecker::measure()) : adresses et jeton du site.
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test' . $path );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value = null, $url = null ): string => is_array( $key ) ? $value . '?' . http_build_query( $key ) : $url . '?' . $key . '=' . $value );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->health = new HealthChecker(
			function ( string $url ): array {
				++$this->probes;
				if ( null !== $this->on_probe ) {
					( $this->on_probe )( $this->probes );
				}
				if ( ! $this->site_ok ) {
					return [
						'code'  => 500,
						'body'  => 'There has been a critical error on this website.',
						'error' => null,
					];
				}
				parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
				$body = str_contains( $url, 'admin-ajax' ) ? (string) json_encode( [ 'ok' => true, 'token' => $query['token'] ?? '' ] ) : 'ok';
				return [
					'code'  => 200,
					'body'  => $body,
					'error' => null,
				];
			}
		);
		// Ce que update_core() écrit : les fichiers de la version de l'offre.
		Core_Upgrader::$on_upgrade = function ( object $offer ): void {
			$this->install( (string) $offer->version );
		};
	}

	protected function tearDown(): void {
		foreach ( array_keys( self::FILES ) as $file ) {
			if ( is_file( ABSPATH . $file ) ) {
				unlink( ABSPATH . $file );
			}
		}
		foreach ( $this->downloads as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_local_package'] );
		parent::tearDown();
	}

	/** Écrit sous ABSPATH les fichiers de `$version` ; le site casse si c'est `breaks_on`. */
	protected function install( string $version ): void {
		foreach ( self::FILES as $file => $contents ) {
			if ( ! is_dir( dirname( ABSPATH . $file ) ) ) {
				mkdir( dirname( ABSPATH . $file ), 0777, true );
			}
			file_put_contents( ABSPATH . $file, $contents[ $version ] );
		}
		$this->site_ok = $version !== $this->breaks_on;
	}

	/** wordpress.org publie le paquet de `$version` dans `$locale` : SHA-1, paquet, empreintes. */
	protected function publish( string $version, string $locale ): void {
		$url                           = CorePackage::url( $version, $locale );
		$this->wporg[ $url . '.sha1' ] = [
			'code' => 200,
			'body' => sha1( $this->zip ) . "\n",
		];
		$this->wporg[ $url ]           = [
			'code' => 200,
			'body' => '',
		];
		$checksums                     = [ 'wp-content/themes/twentytwentyfive/style.css' => md5( 'thème' ) ];
		foreach ( self::FILES as $file => $contents ) {
			$checksums[ $file ] = md5( $contents[ $version ] );
		}
		$this->wporg[ 'https://api.wordpress.org/core/checksums/1.0/?version=' . $version . '&locale=' . $locale ] = [
			'code' => 200,
			'body' => (string) json_encode( [ 'checksums' => $checksums ] ),
		];
	}

	/** Version du cœur lue sur le disque. */
	protected function version_on_disk(): string {
		$contents = (string) file_get_contents( ABSPATH . 'wp-includes/version.php' );
		return 1 === preg_match( "/'([0-9.]+)'/", $contents, $found ) ? $found[1] : '';
	}

	/**
	 * Une reprise prend la transaction de ce processus, comme UpdateTransaction::reserve() quand une
	 * étape a duré plus de 10 minutes sans signe de vie (contrôle du cron, ou mise à jour suivante) :
	 * step() rend faux, touch() et close() n'y touchent plus. Pas d'assertion ici : appelée depuis
	 * une sonde, son échec serait avalé par HealthChecker::probe() (sonde « invérifiable »).
	 */
	protected function take_over(): void {
		$txn = (array) ( $this->options[ UpdateTransaction::OPTION_KEY ] ?? [] );
		$this->options[ UpdateTransaction::OPTION_KEY ] = array_merge(
			$txn,
			[
				'step'            => UpdateTransaction::STEP_RECOVERING,
				'recovering_from' => $txn['step'] ?? null,
				'recovery_token'  => 'jeton-du-cron',
			]
		);
	}

	/**
	 * Core_Upgrader simulé qui lève pendant l'installation des versions `$versions`, comme une
	 * extension sur `upgrader_process_complete` (même crochet, type `core`, pour la mise à jour et pour
	 * la réinstallation) : une fois la copie faite (`_core_updated_successfully` émise) si `$after_copy`,
	 * sinon avant ; verrou `core_updater` pris comme WP_Upgrader::create_lock(). Les autres versions
	 * s'installent normalement.
	 */
	protected function upgrade_throws( bool $after_copy, string ...$versions ): void {
		$marker = null;
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$marker ): bool {
				if ( '_core_updated_successfully' === $hook ) {
					$marker = $callback;
				}
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			static function ( string $hook ) use ( &$marker ): bool {
				if ( '_core_updated_successfully' === $hook ) {
					$marker = null;
				}
				return true;
			}
		);
		Core_Upgrader::$on_upgrade = function ( object $offer ) use ( $after_copy, $versions, &$marker ): void {
			if ( ! in_array( $offer->version, $versions, true ) ) {
				$this->install( (string) $offer->version );
				return;
			}
			$this->options['core_updater.lock'] = time();
			if ( $after_copy ) {
				$this->install( (string) $offer->version );
				( $marker )( $offer->version );
			}
			throw new \RuntimeException( 'Fatal in upgrader_process_complete' );
		};
	}
}
