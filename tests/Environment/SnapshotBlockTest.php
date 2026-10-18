<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Environment;

use Brain\Monkey\Functions;
use G2RD\Connector\Environment\RuntimeProbe;
use G2RD\Connector\Environment\SizeReport;
use G2RD\Connector\Environment\SnapshotBlock;
use G2RD\Connector\Tests\TestCase;

/**
 * Bloc `environment` du snapshot (version 1 de la forme) : mesures légères de
 * RuntimeProbe et volumes du cache quotidien de SizeReport, sous une clé `version`.
 *
 * Les risques couverts : une forme qui s'écarte de celle que lit la plateforme (le
 * fichier d'exemple tests/fixtures/snapshot-environment-v1.json est partagé, à
 * l'octet, avec backend/tests/fixtures/snapshot-environment-v1.json du manager) ; un
 * snapshot qui échoue parce que la lecture de l'environnement a levé.
 *
 * Chaque exemple du fichier est reproduit par une sonde aux fermetures injectées et
 * par un cache (option de réseau) écrit comme SizeReport l'écrit.
 */
final class SnapshotBlockTest extends TestCase {

	/** Exemples partagés avec le manager. */
	private const EXAMPLES_FILE = __DIR__ . '/../fixtures/snapshot-environment-v1.json';

	/** @var array<string, mixed> Options de réseau (get_site_option), par nom. */
	private array $site_options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->site_options = [];
		Functions\when( 'get_site_option' )->alias(
			fn ( string $name, $fallback = false ) => array_key_exists( $name, $this->site_options ) ? $this->site_options[ $name ] : $fallback
		);
		// La sonde reçoit ici la limite de mémoire en octets, écrite en chiffres seuls :
		// c'est ce que wp_convert_hr_to_bytes() de WordPress en fait.
		Functions\when( 'wp_convert_hr_to_bytes' )->alias( static fn ( $value ): int => (int) $value );
	}

	public function test_forme_conforme_au_fichier_d_exemple(): void {
		$examples = self::examples();
		self::assertSame( [ 'complet', 'jamais_mesure', 'disque_indisponible' ], array_keys( $examples ) );

		foreach ( $examples as $name => $example ) {
			$this->site_options = [];
			$now                = $this->cache( $example['sizes'] );

			$block = SnapshotBlock::build( $now, self::probe( $example ) );

			self::assertSame( self::shape( $example ), self::shape( $block ), $name . ' : mêmes clés, mêmes types, même ordre' );

			// Et les mêmes valeurs. La date de la mesure est rendue en UTC (gmdate( 'c' )) :
			// même instant que celle de l'exemple, qui peut être écrite dans un autre fuseau.
			$expected = $example;
			if ( null !== $example['sizes']['measured_at'] ) {
				$expected['sizes']['measured_at'] = gmdate( 'c', (int) strtotime( $example['sizes']['measured_at'] ) );
			}
			self::assertSame( $expected, $block, $name );
		}
	}

	public function test_une_exception_rend_un_bloc_minimal(): void {
		$failures = [
			// Base injoignable, mysqli en mode exceptions.
			'exception' => new \RuntimeException( 'Lost connection to MySQL server during query' ),
			// Fonction de WordPress absente ou refusée.
			'erreur'    => new \Error( 'Call to undefined function get_site_option()' ),
		];

		foreach ( $failures as $name => $failure ) {
			Functions\when( 'get_site_option' )->alias(
				static function () use ( $failure ): never {
					throw $failure;
				}
			);

			self::assertSame( [ 'version' => 1 ], SnapshotBlock::build( time(), self::probe( self::examples()['complet'] ) ), $name );
		}
		self::assertSame( 1, SnapshotBlock::VERSION );
	}

	// ── Outils ───────────────────────────────────────────────────────────────────

	/**
	 * Exemples du fichier partagé, par nom.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function examples(): array {
		$file = json_decode( (string) file_get_contents( self::EXAMPLES_FILE ), true, 512, JSON_THROW_ON_ERROR );
		return $file['examples'];
	}

	/**
	 * Sonde dont les fermetures rendent ce que l'exemple décrit.
	 *
	 * @param array<string, mixed> $example Bloc d'exemple.
	 */
	private static function probe( array $example ): RuntimeProbe {
		$disk = $example['disk'];

		return new RuntimeProbe(
			extension_loaded: static fn ( string $name ): bool => $example['extensions'][ $name ] ?? false,
			ini_get: static fn ( string $option ): string|false => [
				'memory_limit'       => (string) $example['memory_limit_bytes'],
				'max_execution_time' => (string) $example['max_execution_seconds'],
				'disable_functions'  => '',
			][ $option ] ?? false,
			disk_free_space: static fn ( string $directory ): float|false => 'ok' === $disk['status'] ? (float) $disk['free_bytes'] : false,
			disk_total_space: static fn ( string $directory ): float|false => 'ok' === $disk['status'] ? (float) $disk['total_bytes'] : false,
			function_exists: static fn ( string $name ): bool => 'exec' !== $name || $example['exec_available'],
			class_exists: static fn ( string $class_name ): bool => true,
			constant: static fn ( string $name ): ?string => [
				'PHP_SAPI'               => $example['php_sapi'],
				// Sans sodium natif, sodium_compat définit aussi la constante : jamais lue alors.
				'SODIUM_LIBRARY_VERSION' => $example['sodium_library_version'] ?? '1.0.8',
			][ $name ] ?? null
		);
	}

	/**
	 * Écrit le cache de SizeReport qui donne ce bloc `sizes` (rien si jamais mesuré), et
	 * rend l'instant de la lecture : une heure après la mesure.
	 *
	 * @param array<string, mixed> $sizes Bloc `sizes` de l'exemple.
	 */
	private function cache( array $sizes ): int {
		if ( 'pending' === $sizes['status'] ) {
			return 1789000000;
		}
		$at = (int) strtotime( $sizes['measured_at'] );
		unset( $sizes['measured_at'] );

		$this->site_options[ SizeReport::OPTION ] = [
			'at'           => $at,
			'attempted_at' => $at,
			'sizes'        => $sizes,
		];
		return $at + 3600;
	}

	/**
	 * Forme d'une valeur : clés et types, récursivement, dans l'ordre.
	 */
	private static function shape( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return get_debug_type( $value );
		}
		return array_map( static fn ( mixed $child ): mixed => self::shape( $child ), $value );
	}
}
