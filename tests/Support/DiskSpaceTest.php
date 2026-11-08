<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Support;

use G2RD\Connector\Support\DiskSpace;
use PHPUnit\Framework\TestCase;

final class DiskSpaceTest extends TestCase {

	public function test_l_espace_libre_d_un_dossier_existant_est_un_entier_positif(): void {
		$libre = DiskSpace::free_bytes( sys_get_temp_dir() );

		self::assertIsInt( $libre );
		self::assertGreaterThan( 0, $libre );
	}

	public function test_un_dossier_absent_rend_inconnu_et_non_zero(): void {
		self::assertNull( DiskSpace::free_bytes( sys_get_temp_dir() . '/g2rd-absent-' . bin2hex( random_bytes( 6 ) ) . '/x' ) );
	}
}
