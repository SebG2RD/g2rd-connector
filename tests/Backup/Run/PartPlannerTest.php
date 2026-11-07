<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use G2RD\Connector\Backup\Run\PartPlanner;
use PHPUnit\Framework\TestCase;

final class PartPlannerTest extends TestCase {

	private const MIO = 1048576;

	public function test_taille_conseillee_par_le_releve_de_memoire(): void {
		self::assertSame( 20 * self::MIO, PartPlanner::part_size( 128 * self::MIO, 48 * self::MIO ), '128 Mo, 48 Mio occupés : un quart de 80 Mio' );
		self::assertSame( 52 * self::MIO, PartPlanner::part_size( 256 * self::MIO, 48 * self::MIO ) );
		self::assertSame( 64 * self::MIO, PartPlanner::part_size( 4096 * self::MIO, 48 * self::MIO ), 'plafond' );
		self::assertSame( 5 * self::MIO, PartPlanner::part_size( 64 * self::MIO, 60 * self::MIO ), 'jamais sous 5 Mio' );
		self::assertSame( 32 * self::MIO, PartPlanner::part_size( null, 48 * self::MIO ), 'memory_limit illimité' );
	}

	public function test_parties_consecutives_la_derniere_seule_plus_petite(): void {
		self::assertSame(
			[
				[ 'part_number' => 1, 'offset' => 0, 'length' => 16 * self::MIO ],
				[ 'part_number' => 2, 'offset' => 16 * self::MIO, 'length' => 16 * self::MIO ],
				[ 'part_number' => 3, 'offset' => 32 * self::MIO, 'length' => 1000 ],
			],
			PartPlanner::plan( 32 * self::MIO + 1000, 16 * self::MIO )
		);
		self::assertSame( [ [ 'part_number' => 1, 'offset' => 0, 'length' => 164 ] ], PartPlanner::plan( 164, 16 * self::MIO ) );
	}
}
