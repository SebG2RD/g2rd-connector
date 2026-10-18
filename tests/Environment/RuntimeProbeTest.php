<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Environment;

use Brain\Monkey\Functions;
use G2RD\Connector\Environment\RuntimeProbe;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mesures légères de l'environnement d'exécution, lues à chaque snapshot dans le PHP
 * web (D4, E2 : sodium natif ou non, limites PHP, espace disque).
 *
 * Les risques couverts : prendre sodium_compat (livré avec WordPress) pour du sodium
 * natif, ce qui ferait accepter des sauvegardes chiffrées impossibles à tenir ; faire
 * échouer le snapshot parce qu'un hébergeur a désactivé une fonction ; laisser
 * remonter un chemin du serveur ; exécuter un programme pour savoir si `exec` existe.
 *
 * Les fonctions internes de PHP ne sont pas redéfinissables dans ce banc
 * (patchwork.json) : la sonde les reçoit par fermetures, que chaque test remplace.
 * wp_convert_hr_to_bytes(), fonction de WordPress, est simulée par Brain Monkey avec
 * le calcul de WordPress.
 */
final class RuntimeProbeTest extends TestCase {

	private const GIB = 1073741824;

	protected function setUp(): void {
		parent::setUp();
		// Même calcul que wp_convert_hr_to_bytes() de WordPress (wp-includes/load.php).
		Functions\when( 'wp_convert_hr_to_bytes' )->alias(
			static function ( $value ): int {
				$value = strtolower( trim( (string) $value ) );
				$bytes = (int) $value;
				if ( str_contains( $value, 'g' ) ) {
					$bytes *= self::GIB;
				} elseif ( str_contains( $value, 'm' ) ) {
					$bytes *= 1048576;
				} elseif ( str_contains( $value, 'k' ) ) {
					$bytes *= 1024;
				}
				return min( $bytes, PHP_INT_MAX );
			}
		);
	}

	// ── Extensions natives ───────────────────────────────────────────────────────

	public function test_sodium_natif_detecte(): void {
		$asked = [];
		$probe = $this->probe(
			[
				'extension_loaded' => static function ( string $name ) use ( &$asked ): bool {
					$asked[] = $name;
					return true;
				},
			]
		);

		// Forme complète comparée à l'identique : clés du contrat, dans son ordre.
		self::assertSame(
			[
				'php_sapi'               => 'litespeed',
				'extensions'             => [
					'sodium'  => true,
					'openssl' => true,
					'zip'     => true,
					'zlib'    => true,
					'mysqli'  => true,
				],
				'sodium_library_version' => '1.0.18',
				'exec_available'         => true,
				'memory_limit_bytes'     => 268435456,
				'max_execution_seconds'  => 30,
				'disk'                   => [
					'status'      => 'ok',
					'free_bytes'  => 50 * self::GIB,
					'total_bytes' => 200 * self::GIB,
				],
			],
			$probe->describe()
		);
		self::assertContains( 'sodium', $asked, 'sodium natif se lit par extension_loaded()' );
	}

	public function test_sodium_compat_seul_n_est_pas_du_sodium_natif(): void {
		$functions_asked = [];
		$probe           = $this->probe(
			[
				// sodium_compat : extension absente, mais fonctions sodium_* ET constante
				// SODIUM_LIBRARY_VERSION définies par WordPress.
				'extension_loaded' => static fn ( string $name ): bool => 'sodium' !== $name,
				'function_exists'  => static function ( string $name ) use ( &$functions_asked ): bool {
					$functions_asked[] = $name;
					return true;
				},
			]
		);

		$description = $probe->describe();

		self::assertFalse( $description['extensions']['sodium'] );
		self::assertNull( $description['sodium_library_version'], 'la constante de sodium_compat n\'est pas une version de libsodium native' );
		self::assertSame( [], preg_grep( '/sodium/i', $functions_asked ), 'jamais function_exists() pour sodium : sodium_compat définit les fonctions' );
	}

	/**
	 * @return array<string, array{bool, bool, bool}>
	 */
	public static function zip_combinations(): array {
		return [
			'extension et classe'      => [ true, true, true ],
			'extension sans la classe' => [ true, false, false ],
			'classe sans l\'extension' => [ false, true, false ],
			'ni l\'une ni l\'autre'    => [ false, false, false ],
		];
	}

	#[DataProvider( 'zip_combinations' )]
	public function test_zip_exige_l_extension_et_la_classe( bool $extension, bool $class, bool $expected ): void {
		$classes_asked = [];
		$probe         = $this->probe(
			[
				'extension_loaded' => static fn ( string $name ): bool => 'zip' === $name ? $extension : true,
				'class_exists'     => static function ( string $class_name ) use ( $class, &$classes_asked ): bool {
					$classes_asked[] = $class_name;
					return $class;
				},
			]
		);

		self::assertSame( $expected, $probe->describe()['extensions']['zip'] );
		self::assertSame( [], array_diff( $classes_asked, [ 'ZipArchive' ] ), 'seule la classe ZipArchive est cherchée' );
	}

	// ── exec ─────────────────────────────────────────────────────────────────────

	public function test_exec_disponible_lu_sans_rien_executer(): void {
		$disponible = $this->probe();
		self::assertTrue( $disponible->describe()['exec_available'], 'fonction présente, non désactivée' );

		$absente = $this->probe( [ 'function_exists' => static fn ( string $name ): bool => 'exec' !== $name ] );
		self::assertFalse( $absente->describe()['exec_available'], 'fonction absente (désactivée en PHP 8)' );

		$desactivee = $this->probe( [ 'ini_get' => self::ini( [ 'disable_functions' => 'system, EXEC ,passthru' ] ) ] );
		self::assertFalse( $desactivee->describe()['exec_available'], 'listée dans disable_functions, casse et espaces ignorés' );

		// Rien n'est exécuté : aucune fermeture d'exécution n'est injectable…
		$parameters = array_map(
			static fn ( \ReflectionParameter $parameter ): string => $parameter->getName(),
			( new \ReflectionMethod( RuntimeProbe::class, '__construct' ) )->getParameters()
		);
		self::assertSame( [], preg_grep( '/exec|system|passthru|proc_|popen|shell/i', $parameters ) );
		// … et le code de la sonde n'appelle aucune fonction d'exécution de programme.
		$source = dirname( __DIR__, 2 ) . '/includes/Environment/RuntimeProbe.php';
		self::assertFileExists( $source );
		self::assertSame( [], self::program_execution_calls( $source ) );
	}

	// ── Limites PHP ──────────────────────────────────────────────────────────────

	/**
	 * @return array<string, array{string|false, int|null}>
	 */
	public static function memory_limits(): array {
		return [
			'256M'                 => [ '256M', 268435456 ],
			'1G'                   => [ '1G', self::GIB ],
			'illimitée'            => [ '-1', -1 ],
			'vide'                 => [ '', null ],
			'illisible'            => [ 'beaucoup', null ],
			'directive inconnue'   => [ false, null ],
			'en octets, sans unité' => [ '134217728', 134217728 ],
		];
	}

	#[DataProvider( 'memory_limits' )]
	public function test_limite_memoire_en_octets_et_illimitee( string|false $memory_limit, ?int $expected ): void {
		$probe = $this->probe( [ 'ini_get' => self::ini( [ 'memory_limit' => $memory_limit ] ) ] );

		self::assertSame( $expected, $probe->describe()['memory_limit_bytes'] );
	}

	public function test_duree_maximale_jamais_negative(): void {
		$cases = [
			'30'  => 30,
			'0'   => 0,
			'-5'  => 0,
			''    => null,
			'abc' => null,
		];
		foreach ( $cases as $raw => $expected ) {
			$probe = $this->probe( [ 'ini_get' => self::ini( [ 'max_execution_time' => (string) $raw ] ) ] );
			self::assertSame( $expected, $probe->describe()['max_execution_seconds'], 'max_execution_time = « ' . $raw . ' »' );
		}

		$unreadable = $this->probe( [ 'ini_get' => self::ini( [ 'max_execution_time' => false ] ) ] );
		self::assertNull( $unreadable->describe()['max_execution_seconds'], 'directive illisible' );
	}

	// ── Espace disque ────────────────────────────────────────────────────────────

	public function test_disque_mesure(): void {
		$directories = [];
		$probe       = $this->probe(
			[
				'disk_free_space'  => static function ( string $directory ) use ( &$directories ): float {
					$directories[] = $directory;
					return 1234.6;
				},
				'disk_total_space' => static function ( string $directory ) use ( &$directories ): float {
					$directories[] = $directory;
					return 200.0 * self::GIB;
				},
			]
		);

		self::assertSame(
			[
				'status'      => 'ok',
				'free_bytes'  => 1235,
				'total_bytes' => 200 * self::GIB,
			],
			$probe->describe()['disk']
		);
		self::assertSame( [ WP_CONTENT_DIR, WP_CONTENT_DIR ], $directories, 'volume de WP_CONTENT_DIR' );
	}

	public function test_disque_valeur_absurde_lue_indisponible(): void {
		foreach ( [ -1.0, NAN, INF, '12' ] as $absurd ) {
			$probe = $this->probe( [ 'disk_free_space' => static fn ( string $directory ): mixed => $absurd ] );
			self::assertSame( self::unavailable_disk(), $probe->describe()['disk'], 'jamais une taille négative ou inventée' );
		}
	}

	public function test_disque_fonction_desactivee(): void {
		foreach ( [ 'disk_free_space', 'disk_total_space' ] as $disabled ) {
			$probe = $this->probe(
				[
					'function_exists'  => static fn ( string $name ): bool => $disabled !== $name,
					'disk_free_space'  => static fn ( string $directory ): float => 'disk_free_space' === $disabled ? self::fail( 'fonction désactivée appelée' ) : 1.0,
					'disk_total_space' => static fn ( string $directory ): float => 'disk_total_space' === $disabled ? self::fail( 'fonction désactivée appelée' ) : 1.0,
				]
			);

			self::assertSame( self::unavailable_disk(), $probe->describe()['disk'], $disabled . ' désactivée' );
		}
	}

	public function test_disque_avertissement_tu_et_lu_indisponible(): void {
		$calls = 0;
		$probe = $this->probe(
			[
				'disk_free_space' => static function ( string $directory ) use ( &$calls ): bool {
					++$calls;
					// Ce que fait PHP sous open_basedir : un avertissement, puis false.
					trigger_error( 'disk_free_space(): open_basedir restriction in effect', E_USER_WARNING );
					return false;
				},
			]
		);

		// PHPUnit abaisse error_reporting() pendant les tests (niveaux non interceptables
		// seulement) : remis à E_ALL le temps de la mesure, pour que le contrôle standard
		// de PHP distingue un avertissement tu par « @ » (absent d'error_reporting()) d'un
		// avertissement remonté.
		$raised         = [];
		$previous_level = error_reporting( E_ALL );
		set_error_handler(
			static function ( int $level, string $message ) use ( &$raised ): bool {
				if ( 0 !== ( error_reporting() & $level ) ) {
					$raised[] = $message;
				}
				return true;
			}
		);
		try {
			$disk = $probe->describe()['disk'];
		} finally {
			restore_error_handler();
			error_reporting( $previous_level );
		}

		$this->expectOutputString( '' );
		self::assertSame( 1, $calls, 'la mesure a bien été tentée' );
		self::assertSame( [], $raised, 'aucun avertissement remonté' );
		self::assertSame( self::unavailable_disk(), $disk );
	}

	// ── Textes rendus, robustesse ────────────────────────────────────────────────

	public function test_sapi_et_version_reduites_et_bornees(): void {
		$probe = $this->probe(
			[
				'constant' => static fn ( string $name ): ?string => [
					'PHP_SAPI'               => 'LiteSpeed <b>/home/u123/' . str_repeat( 'x', 40 ),
					'SODIUM_LIBRARY_VERSION' => "1.0.18 \\ build\n" . str_repeat( '9', 40 ),
				][ $name ] ?? null,
			]
		);

		$description = $probe->describe();

		self::assertSame( substr( 'litespeedbhomeu123' . str_repeat( 'x', 40 ), 0, 32 ), $description['php_sapi'] );
		self::assertSame( substr( '1.0.18build' . str_repeat( '9', 40 ), 0, 32 ), $description['sodium_library_version'] );
		self::assertSame( 32, strlen( $description['php_sapi'] ) );
	}

	public function test_une_fonction_indisponible_ne_fait_jamais_echouer_la_description(): void {
		// Fonction désactivée par l'hébergeur : en PHP 8, elle n'est plus définie et
		// l'appeler lève une Error.
		$undefined = static fn (): mixed => throw new \Error( 'Call to undefined function' );
		$probe     = new RuntimeProbe( $undefined, $undefined, $undefined, $undefined, $undefined, $undefined, $undefined );

		self::assertSame(
			[
				'php_sapi'               => '',
				'extensions'             => [
					'sodium'  => false,
					'openssl' => false,
					'zip'     => false,
					'zlib'    => false,
					'mysqli'  => false,
				],
				'sodium_library_version' => null,
				'exec_available'         => false,
				'memory_limit_bytes'     => null,
				'max_execution_seconds'  => null,
				'disk'                   => self::unavailable_disk(),
			],
			$probe->describe()
		);
	}

	public function test_la_sonde_par_defaut_lit_le_php_qui_tourne(): void {
		$description = ( new RuntimeProbe() )->describe();

		self::assertSame( 'cli', $description['php_sapi'] );
		foreach ( [ 'sodium', 'openssl', 'zlib', 'mysqli' ] as $extension ) {
			self::assertSame( extension_loaded( $extension ), $description['extensions'][ $extension ], $extension );
		}
		self::assertSame( 0, $description['max_execution_seconds'], 'max_execution_time vaut toujours 0 en ligne de commande' );
	}

	public function test_aucun_chemin_dans_la_sortie(): void {
		$hostile = $this->probe(
			[
				'constant' => static fn ( string $name ): string => 'C:\\hébergeur/home/u123/public_html',
			]
		);

		// La vraie sonde de la machine qui joue le test, et une sonde aux textes piégés.
		foreach ( [ 'sonde réelle' => new RuntimeProbe(), 'textes piégés' => $hostile ] as $label => $probe ) {
			$description = $probe->describe();
			$paths       = [];
			array_walk_recursive(
				$description,
				static function ( $value ) use ( &$paths ): void {
					if ( is_string( $value ) && ( str_contains( $value, '/' ) || str_contains( $value, '\\' ) ) ) {
						$paths[] = $value;
					}
				}
			);
			self::assertSame( [], $paths, $label . ' : aucun chemin du serveur' );
		}
	}

	// ── Banc ─────────────────────────────────────────────────────────────────────

	/**
	 * Sonde d'un serveur ordinaire (tout présent, 256M, 30 s, 50 Gio libres sur 200),
	 * dont chaque fermeture peut être remplacée par son nom.
	 *
	 * @param array<string, \Closure> $overrides Fermetures à remplacer, par nom de paramètre.
	 */
	private function probe( array $overrides = [] ): RuntimeProbe {
		$fakes = [
			'extension_loaded' => static fn ( string $name ): bool => true,
			'ini_get'          => self::ini( [] ),
			'disk_free_space'  => static fn ( string $directory ): float => 50.0 * self::GIB,
			'disk_total_space' => static fn ( string $directory ): float => 200.0 * self::GIB,
			'function_exists'  => static fn ( string $name ): bool => true,
			'class_exists'     => static fn ( string $class_name ): bool => true,
			'constant'         => static fn ( string $name ): ?string => [
				'PHP_SAPI'               => 'litespeed',
				'SODIUM_LIBRARY_VERSION' => '1.0.18',
			][ $name ] ?? null,
		];

		return new RuntimeProbe( ...array_merge( $fakes, $overrides ) );
	}

	/**
	 * ini_get() d'un serveur ordinaire, avec des directives remplacées.
	 *
	 * @param array<string, string|false> $overrides Directive => valeur brute (false = directive inconnue).
	 */
	private static function ini( array $overrides ): \Closure {
		$values = array_merge(
			[
				'memory_limit'       => '256M',
				'max_execution_time' => '30',
				'disable_functions'  => '',
			],
			$overrides
		);
		return static fn ( string $option ): string|false => $values[ $option ] ?? false;
	}

	/**
	 * @return array{status: string, free_bytes: null, total_bytes: null}
	 */
	private static function unavailable_disk(): array {
		return [
			'status'      => 'unavailable',
			'free_bytes'  => null,
			'total_bytes' => null,
		];
	}

	/**
	 * Appels de fonctions qui exécutent un programme, et opérateur « ` », dans un fichier PHP.
	 *
	 * @return list<string>
	 */
	private static function program_execution_calls( string $file ): array {
		$forbidden = [ 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' ];
		$tokens    = array_values(
			array_filter(
				token_get_all( (string) file_get_contents( $file ) ),
				static fn ( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
			)
		);

		$calls = [];
		foreach ( $tokens as $index => $token ) {
			if ( '`' === $token ) {
				$calls[] = '`';
				continue;
			}
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) {
				continue;
			}
			if ( in_array( strtolower( ltrim( $token[1], '\\' ) ), $forbidden, true ) && '(' === ( $tokens[ $index + 1 ] ?? null ) ) {
				$calls[] = $token[1];
			}
		}
		return $calls;
	}
}
