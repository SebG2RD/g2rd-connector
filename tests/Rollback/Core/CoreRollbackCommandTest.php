<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Core_Upgrader;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\Core\CoreRollbackCommand;
use G2RD\Connector\Rollback\Core\ProtectedCoreUpdate;
use G2RD\Connector\Rollback\UpdateTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Commande signée `rollback_core` (§ 4.2) : réinstalle la version d'avant d'après les informations
 * gardées pour CETTE mise à jour ; refus à code stable, rendus comme résultats (`outcome`), avant
 * tout changement sauf pour l'intégrité ; rien sans la transaction ; version retirée bloquée pour
 * les mises à jour automatiques de WordPress avant la réinstallation.
 */
final class CoreRollbackCommandTest extends CoreRollbackTestCase {

	private const PAYLOAD = [
		'expected_current' => '7.1.5',
		'target'           => '7.1.4',
	];

	protected function setUp(): void {
		parent::setUp();
		$this->options[ ProtectedCoreUpdate::RECORD_OPTION ] = [
			'version_before' => '7.1.4',
			'version_after'  => '7.1.5',
			'locale'         => 'en_US',
			'db_version'     => 61833,
			'until'          => time() + 3600,
		];
	}

	public function test_retour_arriere_demande(): void {
		$blocked_during            = null;
		Core_Upgrader::$on_upgrade = function ( object $offer ) use ( &$blocked_during ): void {
			$blocked_during = AutoUpdateGuard::all();
			$this->install( (string) $offer->version );
		};

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( 'rollback_success', $result['outcome'] );
		self::assertSame( 'download', $result['via'] );
		self::assertSame( '7.1.5', $result['version_before'] );
		self::assertSame( '7.1.4', $result['version_after'] );
		self::assertSame( 'en_US', $result['locale'] );
		self::assertSame( 'healthy', $result['health']['verdict'] );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], $blocked_during, 'bloquée AVANT la réinstallation' );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all(), 'WordPress ne réinstallera pas 7.1.5 tout seul' );
		self::assertArrayNotHasKey( ProtectedCoreUpdate::RECORD_OPTION, $this->options, 'effacées après la réinstallation' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	public function test_la_langue_gardee_est_reinstallee(): void {
		$this->options[ ProtectedCoreUpdate::RECORD_OPTION ]['locale'] = 'fr_FR';
		$this->publish( '7.1.4', 'fr_FR' );

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( 'fr_FR', $result['locale'] );
		self::assertSame( 'fr_FR', Core_Upgrader::$calls[0]['offer']->locale );
	}

	/**
	 * @return iterable<string, array{\Closure(self): void, string}>
	 */
	public static function refusals(): iterable {
		yield 'plus d\'information gardée' => [
			static function ( self $test ): void {
				unset( $test->options[ ProtectedCoreUpdate::RECORD_OPTION ] );
			},
			'core_rollback_expired',
		];
		yield 'échéance passée' => [
			static function ( self $test ): void {
				$test->options[ ProtectedCoreUpdate::RECORD_OPTION ]['until'] = time() - 1;
			},
			'core_rollback_expired',
		];
		yield 'informations d\'une autre mise à jour' => [
			static function ( self $test ): void {
				$test->options[ ProtectedCoreUpdate::RECORD_OPTION ]['version_before'] = '7.1.3';
			},
			'core_rollback_expired',
		];
		yield 'WordPress mis à jour entre-temps' => [
			static function ( self $test ): void {
				$test->loaded_version = '7.1.6';
			},
			'core_rollback_version_changed',
		];
		yield 'base modifiée' => [
			static function ( self $test ): void {
				$test->table->rows['db_version']['value'] = '61900';
			},
			'core_rollback_db_changed',
		];
		yield 'base illisible' => [
			static function ( self $test ): void {
				$test->table->read_fails = true;
			},
			'core_rollback_db_changed',
		];
		yield 'autre mise à jour protégée en cours' => [
			static function (): void {
				UpdateTransaction::open( [ 'plugin_file' => 'akismet/akismet.php' ], time() );
				( new \ReflectionProperty( UpdateTransaction::class, 'held' ) )->setValue( null, null );
			},
			'core_rollback_busy',
		];
		yield 'transaction reprise par un autre processus pendant la mesure' => [
			static function ( self $test ): void {
				$test->on_probe = static function ( int $probe ) use ( $test ): void {
					if ( 1 === $probe ) {
						$test->take_over();
					}
				};
			},
			'core_rollback_busy',
		];
		yield 'verrou de WordPress tenu' => [
			static function (): void {
				Core_Upgrader::$on_upgrade  = null;
				Core_Upgrader::$next_result = new WP_Error( 'locked', 'Another update is currently in progress.' );
			},
			'core_rollback_busy',
		];
		yield 'wordpress.org injoignable' => [
			static function ( self $test ): void {
				$test->wporg = [];
			},
			'core_rollback_wporg_unreachable',
		];
		yield 'fichiers non conformes' => [
			static function (): void {
				Core_Upgrader::$on_upgrade = static function (): void {
					file_put_contents( ABSPATH . 'wp-settings.php', "<?php // abîmé\n" );
				};
			},
			'core_rollback_integrity',
		];
	}

	#[DataProvider( 'refusals' )]
	public function test_refus_a_code_stable( \Closure $setup, string $code ): void {
		$setup( $this );

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( $code, $result['outcome'] );
		self::assertSame( $code, $result['error_code'] );
		self::assertStringStartsWith( 'WordPress ', $result['error'] );
		self::assertSame( $this->loaded_version, $result['version_before'] );
		self::assertSame( $this->loaded_version, $result['version_after'] );
		if ( 'core_rollback_integrity' !== $code ) {
			self::assertSame( '7.1.5', $this->version_on_disk(), 'rien n\'a été modifié' );
		}
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformed_payloads(): iterable {
		yield 'vide' => [ [] ];
		yield 'cible des sources' => [
			[
				'expected_current' => '7.1.5',
				'target'           => '7.1.4-src',
			],
		];
		yield 'version actuelle qui remonte' => [
			[
				'expected_current' => '../7.1.5',
				'target'           => '7.1.4',
			],
		];
		yield 'pas des chaînes' => [
			[
				'expected_current' => 7.15,
				'target'           => [ '7.1.4' ],
			],
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	#[DataProvider( 'malformed_payloads' )]
	public function test_charge_mal_formee( array $payload ): void {
		$this->expectException( \RuntimeException::class );

		( new CoreRollbackCommand( $this->health ) )->run( $payload );
	}
}
