<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Brain\Monkey\Functions;
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
	 * @return iterable<string, array{\Closure(self): void, string, bool}>
	 */
	public static function refusals(): iterable {
		yield 'plus d\'information gardée' => [
			static function ( self $test ): void {
				unset( $test->options[ ProtectedCoreUpdate::RECORD_OPTION ] );
			},
			'core_rollback_expired',
			false,
		];
		yield 'échéance passée' => [
			static function ( self $test ): void {
				$test->options[ ProtectedCoreUpdate::RECORD_OPTION ]['until'] = time() - 1;
			},
			'core_rollback_expired',
			false,
		];
		yield 'informations d\'une autre mise à jour' => [
			static function ( self $test ): void {
				$test->options[ ProtectedCoreUpdate::RECORD_OPTION ]['version_before'] = '7.1.3';
			},
			'core_rollback_expired',
			false,
		];
		yield 'WordPress mis à jour entre-temps' => [
			static function ( self $test ): void {
				$test->loaded_version = '7.1.6';
				file_put_contents( ABSPATH . 'wp-includes/version.php', "<?php \$wp_version = '7.1.6';\n" );
			},
			'core_rollback_version_changed',
			false,
		];
		yield 'base modifiée' => [
			static function ( self $test ): void {
				$test->table->rows['db_version']['value'] = '61900';
			},
			'core_rollback_db_changed',
			false,
		];
		yield 'base illisible' => [
			static function ( self $test ): void {
				$test->table->read_fails = true;
			},
			'core_rollback_db_changed',
			false,
		];
		yield 'autre mise à jour protégée en cours' => [
			static function (): void {
				UpdateTransaction::open( [ 'plugin_file' => 'akismet/akismet.php' ], time() );
				( new \ReflectionProperty( UpdateTransaction::class, 'held' ) )->setValue( null, null );
			},
			'core_rollback_busy',
			false,
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
			false,
		];
		yield 'verrou de WordPress tenu' => [
			static function (): void {
				Core_Upgrader::$on_upgrade  = null;
				Core_Upgrader::$next_result = new WP_Error( 'locked', 'Another update is currently in progress.' );
			},
			'core_rollback_busy',
			true,
		];
		yield 'wordpress.org injoignable' => [
			static function ( self $test ): void {
				$test->wporg = [];
			},
			'core_rollback_wporg_unreachable',
			true,
		];
		yield 'fichiers non conformes' => [
			static function (): void {
				Core_Upgrader::$on_upgrade = static function (): void {
					file_put_contents( ABSPATH . 'wp-settings.php', "<?php // abîmé\n" );
				};
			},
			'core_rollback_integrity',
			true,
		];
	}

	#[DataProvider( 'refusals' )]
	public function test_refus_a_code_stable( \Closure $setup, string $code, bool $blocked ): void {
		$setup( $this );
		$kept    = $this->options[ ProtectedCoreUpdate::RECORD_OPTION ] ?? null;
		$on_disk = $this->version_on_disk();

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( $code, $result['outcome'] );
		self::assertSame( $code, $result['error_code'] );
		self::assertStringStartsWith( 'WordPress ', $result['error'] );
		self::assertSame( $this->loaded_version, $result['version_before'] );
		self::assertSame( $this->loaded_version, $result['version_after'] );
		if ( 'core_rollback_integrity' !== $code ) {
			self::assertSame( $on_disk, $this->version_on_disk(), 'rien n\'a été modifié' );
		}
		self::assertSame( $kept, $this->options[ ProtectedCoreUpdate::RECORD_OPTION ] ?? null, 'informations gardées intactes : retour redemandable' );
		self::assertSame( $blocked ? [ AutoUpdateGuard::CORE => '7.1.5' ] : [], AutoUpdateGuard::all() );
		$txn = $this->options[ UpdateTransaction::OPTION_KEY ] ?? null;
		self::assertTrue( null === $txn || isset( $txn['recovery_token'] ) || 'akismet/akismet.php' === ( $txn['plugin_file'] ?? null ), 'transaction de la commande rendue, celle d\'un autre processus laissée' );
	}

	/**
	 * Chaque moitié de la condition de version : la version installée doit être celle qu'attend le
	 * manager ET celle que la mise à jour protégée a installée.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function versions_qui_ne_correspondent_pas(): iterable {
		yield 'le manager attend une autre version' => [ '7.1.5', '7.1.6' ];
		yield 'version installée par une autre mise à jour' => [ '7.1.6', '7.1.6' ];
	}

	#[DataProvider( 'versions_qui_ne_correspondent_pas' )]
	public function test_version_installee_differente( string $installed, string $expected ): void {
		$this->loaded_version = $installed;

		$result = ( new CoreRollbackCommand( $this->health ) )->run(
			[
				'expected_current' => $expected,
				'target'           => '7.1.4',
			]
		);

		self::assertSame( 'core_rollback_version_changed', $result['outcome'] );
		self::assertSame( [], $this->requested, 'refusé avant tout téléchargement' );
	}

	/**
	 * Le téléchargement peut durer six minutes : un autre processus a pu mettre WordPress ou sa base
	 * à jour entre-temps. Contrôles refaits juste avant la copie : refus à code stable, paquet
	 * supprimé, rien de copié, informations gardées intactes. `version_after` dit la version lue sur
	 * le disque (celle que l'autre processus a installée), pas celle chargée au début de la requête.
	 *
	 * @return iterable<string, array{\Closure(self): void, string, string}>
	 */
	public static function changes_during_download(): iterable {
		yield 'WordPress mis à jour pendant le téléchargement' => [
			static function ( self $test ): void {
				file_put_contents( ABSPATH . 'wp-includes/version.php', "<?php \$wp_version = '7.1.6';\n" );
			},
			'core_rollback_version_changed',
			'7.1.6',
		];
		yield 'base mise à jour pendant le téléchargement' => [
			static function ( self $test ): void {
				$test->table->rows['db_version']['value'] = '61900';
			},
			'core_rollback_db_changed',
			'7.1.5',
		];
	}

	#[DataProvider( 'changes_during_download' )]
	public function test_controles_refaits_apres_le_telechargement( \Closure $change, string $code, string $version_after ): void {
		$this->on_download = fn () => $change( $this );
		$kept              = $this->options[ ProtectedCoreUpdate::RECORD_OPTION ];

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( $code, $result['outcome'] );
		self::assertSame( $version_after, $result['version_after'] );
		self::assertSame( [], Core_Upgrader::$calls, 'rien de copié' );
		self::assertCount( 1, $this->downloads );
		self::assertFileDoesNotExist( $this->downloads[0] );
		self::assertSame( $kept, $this->options[ ProtectedCoreUpdate::RECORD_OPTION ], 'retour redemandable' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	/**
	 * Une extension lève sur `upgrader_process_complete` après la copie de 7.1.4 : le retour a eu
	 * lieu, il est dit réussi (santé mesurée, informations gardées effacées, verrou de WordPress rendu).
	 */
	public function test_exception_apres_la_copie_retour_reussi(): void {
		$this->upgrade_throws( true, '7.1.4' );

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( 'rollback_success', $result['outcome'] );
		self::assertSame( '7.1.4', $result['version_after'] );
		self::assertSame( 'healthy', $result['health']['verdict'] );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertArrayNotHasKey( ProtectedCoreUpdate::RECORD_OPTION, $this->options );
		self::assertArrayNotHasKey( 'core_updater.lock', $this->options, 'verrou de WordPress rendu' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	/**
	 * Exception imprévue, pendant la réinstallation (avant la copie) ou ailleurs (lecture des
	 * informations gardées) : résultat `core_rollback_failed`, jamais le texte de l'exception (il peut
	 * porter un chemin du serveur), transaction rendue, informations gardées intactes.
	 *
	 * @return iterable<string, array{\Closure(self): void}>
	 */
	public static function unexpected_exceptions(): iterable {
		yield 'pendant la réinstallation, avant la copie' => [
			static function ( self $test ): void {
				$test->upgrade_throws( false, '7.1.4' );
			},
		];
		yield 'hors de la réinstallation' => [
			static function ( self $test ): void {
				$get_option = static fn ( string $key, $fallback = false ) => array_key_exists( $key, $test->options ) ? $test->options[ $key ] : $fallback;
				Functions\when( 'get_option' )->alias(
					static function ( string $key, $fallback = false ) use ( $get_option ) {
						if ( ProtectedCoreUpdate::RECORD_OPTION === $key ) {
							throw new \ErrorException( 'unserialize(): Error at offset 0 of 12 bytes in /home/site/public_html/wp-includes/functions.php' );
						}
						return $get_option( $key, $fallback );
					}
				);
			},
		];
	}

	#[DataProvider( 'unexpected_exceptions' )]
	public function test_exception_imprevue( \Closure $setup ): void {
		$setup( $this );
		$kept = $this->options[ ProtectedCoreUpdate::RECORD_OPTION ];

		$result = ( new CoreRollbackCommand( $this->health ) )->run( self::PAYLOAD );

		self::assertSame( 'core_rollback_failed', $result['outcome'] );
		self::assertSame( 'core_rollback_failed', $result['error_code'] );
		self::assertStringNotContainsString( '/home/', $result['error'] );
		self::assertStringNotContainsString( 'Fatal in upgrader_process_complete', $result['error'] );
		self::assertMatchesRegularExpression( '/\((RuntimeException|ErrorException)\)$/', $result['error'] );
		self::assertSame( '7.1.5', $result['version_after'] );
		self::assertSame( $kept, $this->options[ ProtectedCoreUpdate::RECORD_OPTION ], 'retour redemandable' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
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
