<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use Brain\Monkey\Functions;
use G2RD\Connector\PhpErrors\Buffer;
use G2RD\Connector\PhpErrors\FatalCatcher;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\Support\FakeLockWpdb;
use G2RD\Connector\Tests\TestCase;

/**
 * Relevé des erreurs fatales à l'arrêt de PHP : armé seulement si le relevé est activé et le site
 * enrôlé ; premier passage sans effet (lecture d'error_get_last() et programmation seulement) ;
 * écriture en toute fin ; ne lève jamais. L'ordre réel des fonctions d'arrêt est prouvé à la
 * tâche 8 par un vrai arrêt de PHP.
 */
final class FatalCatcherTest extends TestCase {

	private const T = 1793491200;

	/** @var list<callable> Fonctions confiées à register_shutdown_function(). */
	private array $registered = [];

	private int $option_reads = 0;

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wpdb'] );
		( new \ReflectionProperty( FatalCatcher::class, 'armed' ) )->setValue( null, null );
		$this->registered   = [];
		$this->option_reads = 0;
		Functions\when( 'register_shutdown_function' )->alias(
			function ( callable $callback ): void {
				$this->registered[] = $callback;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				++$this->option_reads;
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
			}
		);
	}

	protected function tearDown(): void {
		( new \ReflectionProperty( FatalCatcher::class, 'armed' ) )->setValue( null, null );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_arme_seulement_releve_active_et_site_enrole(): void {
		FatalCatcher::arm();
		self::assertSame( [], $this->registered, 'Réglages absents : rien.' );

		$this->options[ Settings::OPTION_KEY ] = [ 'php_errors_enabled' => true ];
		FatalCatcher::arm();
		self::assertSame( [], $this->registered, 'Site non enrôlé : rien.' );

		// Site enrôlé, relevé non activé : l'état de tous les sites à la réception de la 1.16.
		// Placés avant le cas qui arme, puisque arm() ne s'arme qu'une fois par requête.
		$enrole = [
			'site_id'    => 7,
			'site_token' => 'enc:v1:x',
		];

		$this->options[ Settings::OPTION_KEY ] = $enrole;
		FatalCatcher::arm();
		self::assertSame( [], $this->registered, 'Site enrôlé, réglages d\'avant la 1.16 (sans la clé) : rien.' );

		$this->options[ Settings::OPTION_KEY ] = $enrole + [ 'php_errors_enabled' => false ];
		FatalCatcher::arm();
		self::assertSame( [], $this->registered, 'Site enrôlé, relevé désactivé : rien.' );

		$this->options[ Settings::OPTION_KEY ] = $enrole + [ 'php_errors_enabled' => '1' ];
		FatalCatcher::arm();
		self::assertSame( [], $this->registered, 'Site enrôlé, valeur vraie mais pas true : rien (contrôle strict).' );

		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled' => true,
			'site_id'            => 7,
			'site_token'         => 'enc:v1:x',
		];
		FatalCatcher::arm();
		self::assertCount( 1, $this->registered );
	}

	public function test_arme_une_seule_fois(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled' => true,
			'site_id'            => 7,
			'site_token'         => 'enc:v1:x',
		];

		FatalCatcher::arm();
		FatalCatcher::arm();

		self::assertCount( 1, $this->registered );
	}

	public function test_sans_erreur_fatale_rien_n_est_programme(): void {
		foreach ( [ null, self::error( E_WARNING ), self::error( E_USER_DEPRECATED ) ] as $last ) {
			$this->catcher( $last )->on_shutdown();
		}

		self::assertSame( [], $this->registered );
	}

	public function test_le_premier_passage_ne_lit_ni_n_ecrit_la_base(): void {
		$catcher = $this->catcher( self::error( E_ERROR ) );

		$catcher->on_shutdown();

		self::assertSame( 0, $this->option_reads );
		self::assertArrayNotHasKey( Buffer::OPTION, $this->options );
		self::assertCount( 1, $this->registered, 'Seulement le passage suivant, programmé.' );
	}

	public function test_l_ecriture_passe_en_toute_fin_apres_deux_reports(): void {
		$catcher = $this->catcher( self::error( E_ERROR ) );

		$catcher->on_shutdown();
		( $this->registered[0] )();
		self::assertCount( 2, $this->registered, 'defer() programme flush() en fin de liste.' );
		self::assertArrayNotHasKey( Buffer::OPTION, $this->options, 'Rien d\'écrit avant flush().' );
		( $this->registered[1] )();

		$groups = Buffer::read( self::T )['groups'];
		self::assertCount( 1, $groups );
		$group = array_values( $groups )[0];
		self::assertSame( 'fatal', $group['level'] );
		self::assertSame( 'wp-content/plugins/exemple/x.php', $group['file'] );
		self::assertSame( 'plugin', $group['type'] );
		self::assertStringNotContainsString( 'contact@exemple.fr', $group['message'] );
	}

	public function test_une_seconde_ecriture_ne_se_fait_pas(): void {
		$catcher = $this->catcher( self::error( E_ERROR ) );
		$catcher->on_shutdown();
		$catcher->defer();
		$catcher->flush();
		$avant = $this->options[ Buffer::OPTION ];

		$catcher->flush();

		self::assertSame( $avant, $this->options[ Buffer::OPTION ] );
	}

	/**
	 * Erreur fatale à chaque requête : une écriture par minute et par empreinte. Dans la minute,
	 * ni version du composant (get_plugins() parcourt le dossier de l'extension) ni verrou ; ce
	 * qui est écrit, version comprise, ne change pas.
	 */
	public function test_dans_la_minute_ni_version_ni_verrou(): void {
		$wpdb            = new FakeLockWpdb( '1' );
		$GLOBALS['wpdb'] = $wpdb;
		$folders         = [];
		Functions\when( 'get_plugins' )->alias(
			static function ( string $folder = '' ) use ( &$folders ): array {
				$folders[] = $folder;
				return [ 'exemple.php' => [ 'Version' => '2.4.1' ] ];
			}
		);

		foreach ( [ 0, 1, 30, 59, 60 ] as $delay ) {
			$catcher = $this->catcher( self::error( E_ERROR ), self::T + $delay );
			$catcher->on_shutdown();
			$catcher->defer();
			$catcher->flush();
		}

		self::assertSame( [ '/exemple', '/exemple' ], $folders, 'Version calculée à T et à T+60 seulement.' );
		self::assertSame( [ 'GET_LOCK', 'RELEASE_LOCK', 'GET_LOCK', 'RELEASE_LOCK' ], $wpdb->events, 'Verrou pris à T et à T+60 seulement.' );
		$groups = Buffer::read( self::T + 60 )['groups'];
		self::assertCount( 1, $groups );
		$group = array_values( $groups )[0];
		self::assertSame( '2.4.1', $group['version'] );
		self::assertSame( 2, $group['count'] );
		self::assertSame( self::T, $group['first_seen'] );
		self::assertSame( self::T + 60, $group['written_at'] );
	}

	public function test_le_releve_ne_leve_jamais(): void {
		$lit_mal = new FatalCatcher(
			static function (): array {
				throw new \RuntimeException( 'error_get_last indisponible' );
			},
			function ( callable $callback ): void {
				$this->registered[] = $callback;
			},
			static fn (): int => self::T
		);
		$lit_mal->on_shutdown();
		self::assertSame( [], $this->registered );

		Functions\when( 'get_option' )->alias(
			static function (): never {
				throw new \RuntimeException( 'Lost connection to MySQL server during query' );
			}
		);
		$catcher = $this->catcher( self::error( E_ERROR ) );
		$catcher->on_shutdown();
		$catcher->defer();
		$catcher->flush();
		$this->addToAssertionCount( 1 );
	}

	public function test_le_fichier_principal_arme_le_releve_avant_plugins_loaded(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/g2rd-connector.php' );

		$autoload = strpos( $source, "includes/autoload.php';" );
		$arm      = strpos( $source, 'FatalCatcher::arm();' );
		$loaded   = strpos( $source, "'plugins_loaded'" );

		self::assertIsInt( $autoload );
		self::assertIsInt( $arm );
		self::assertIsInt( $loaded );
		self::assertGreaterThan( $autoload, $arm );
		self::assertLessThan( $loaded, $arm );
	}

	/**
	 * @param array<string, mixed>|null $last
	 */
	private function catcher( ?array $last, int $now = self::T ): FatalCatcher {
		return new FatalCatcher(
			static fn (): ?array => $last,
			function ( callable $callback ): void {
				$this->registered[] = $callback;
			},
			static fn (): int => $now
		);
	}

	/**
	 * @return array{type: int, message: string, file: string, line: int}
	 */
	private static function error( int $type ): array {
		return [
			'type'    => $type,
			'message' => 'Uncaught Error: envoi refusé pour contact@exemple.fr',
			'file'    => WP_CONTENT_DIR . '/plugins/exemple/x.php',
			'line'    => 87,
		];
	}
}
