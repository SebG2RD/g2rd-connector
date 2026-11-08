<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\BackupSwitch;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class BackupSwitchTest extends BackupTestCase {

	public function test_eteint_par_defaut(): void {
		self::assertFalse( BackupSwitch::enabled() );
	}

	public function test_case_cochee_allume(): void {
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertTrue( BackupSwitch::enabled() );
	}

	/**
	 * Une sauvegarde couvre tout le réseau : l'administrateur d'un site secondaire, qui n'est pas
	 * super-administrateur, ne l'allume pas en cochant la case de son blog.
	 *
	 * Processus séparé, comme chaque test qui simule is_main_site() : une fois définie par Brain
	 * Monkey, la fonction le resterait pour toute la suite, et PhpErrors\LoggingProbe (qui teste
	 * function_exists( 'is_main_site' )) appellerait alors is_multisite() sans simulation.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_secondaire_d_un_reseau_eteint_malgre_la_case(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( false );
		$this->settings(
			[
				'backup_enabled'          => true,
				'remote_commands_enabled' => true,
			]
		);

		self::assertTrue( BackupSwitch::secondary_site() );
		self::assertFalse( BackupSwitch::enabled() );
		self::assertFalse( BackupSwitch::capability_available() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_principal_d_un_reseau_allume_par_la_case(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertFalse( BackupSwitch::secondary_site() );
		self::assertTrue( BackupSwitch::enabled() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_la_constante_d_arret_l_emporte_sur_la_case(): void {
		define( 'G2RD_CONNECTOR_DISABLE_BACKUP', true );
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertTrue( BackupSwitch::disabled_by_constant() );
		self::assertFalse( BackupSwitch::enabled() );
	}
}
