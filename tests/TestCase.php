<?php
/**
 * Base des tests unitaires : Brain Monkey + une table d'options en mémoire.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Options WordPress simulées (get_option / update_option / delete_option).
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->options = [];

		Functions\when( 'get_option' )->alias(
			fn ( string $key, $fallback = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
