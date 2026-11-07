<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_la_constante_d_arret_l_emporte_sur_la_case(): void {
		define( 'G2RD_CONNECTOR_DISABLE_BACKUP', true );
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertTrue( BackupSwitch::disabled_by_constant() );
		self::assertFalse( BackupSwitch::enabled() );
	}
}
