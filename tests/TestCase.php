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
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\UpdateTransaction;
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
		// Pas de cache d'options simulé par défaut : le vider ne change rien
		// (cf. Rollback\UpdateTransactionTest pour un cache simulé).
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// Jamais de vrai filet de shutdown pendant les tests : il s'exécuterait à la fin
		// de PHPUnit, hors de Brain Monkey (cf. ProtectedUpdateTest pour l'observer).
		Functions\when( 'register_shutdown_function' )->justReturn( null );

		// Aucune transaction tenue par « ce processus » au début du test, et aucun
		// filet de shutdown armé : statiques (propres à chaque processus PHP), un test
		// précédent a pu les laisser — quel que soit l'ordre des tests.
		( new \ReflectionProperty( UpdateTransaction::class, 'held' ) )->setValue( null, null );
		( new \ReflectionProperty( ProtectedUpdate::class, 'shutdown_net_armed' ) )->setValue( null, false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
