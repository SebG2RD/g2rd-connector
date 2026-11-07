<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Settings;
use G2RD\Connector\Support\MysqlLock;
use G2RD\Connector\Tests\Backup\Support\FakeBackupWpdb;
use G2RD\Connector\Tests\TestCase;

/**
 * Base des tests du module de sauvegarde : faux $wpdb (options et verrous), wp_json_encode réel,
 * aucun multisite sauf dans les tests qui le simulent.
 */
abstract class BackupTestCase extends TestCase {

	protected FakeBackupWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeBackupWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value, int $flags = 0, int $depth = 512 ) => json_encode( $value, $flags, $depth ) );
		// Site simple par défaut : une fois simulée par un test, une fonction reste définie pour
		// Brain Monkey, et un appel sans simulation lèverait.
		Functions\when( 'is_multisite' )->justReturn( false );
		// Résultat de signature d'une requête d'un test précédent : état statique du processus.
		( new \ReflectionProperty( \G2RD\Connector\Rest\Auth::class, 'last_signature_check' ) )->setValue( null, null );
		// Passage de la porte des mises à jour laissé ouvert par un test précédent : état statique.
		( new \ReflectionProperty( \G2RD\Connector\Backup\Gate\UpdateGate::class, 'current' ) )->setValue( null, null );
		( new \ReflectionProperty( \G2RD\Connector\Backup\Gate\UpdateGate::class, 'transparent' ) )->setValue( null, false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		( new \ReflectionProperty( \G2RD\Connector\Backup\Gate\UpdateGate::class, 'current' ) )->setValue( null, null );
		( new \ReflectionProperty( \G2RD\Connector\Backup\Gate\UpdateGate::class, 'transparent' ) )->setValue( null, false );
		parent::tearDown();
	}

	/**
	 * Réglages du connecteur (fusionnés aux valeurs par défaut par Settings::all()).
	 *
	 * @param array<string, mixed> $values
	 */
	protected function settings( array $values ): void {
		$this->options[ Settings::OPTION_KEY ] = $values;
	}

	/**
	 * État de sauvegarde posé dans la table du site principal.
	 *
	 * @param array<string, mixed> $state
	 */
	protected function put_state( array $state ): void {
		$this->wpdb->tables['wp_options'][ BackupState::OPTION ] = (string) json_encode( $state + [ 'v' => 1, 'rev' => 1 ] );
	}

	protected function lock_name(): string {
		return (string) MysqlLock::name( 'backup', MysqlLock::SCOPE_NETWORK );
	}
}
