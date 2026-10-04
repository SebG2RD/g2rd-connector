<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Functions;
use G2RD\Connector\BootData;
use G2RD\Connector\Settings;

/**
 * Données de démarrage du panneau React : l'état de la case de connexion directe
 * y figure, pour que l'interrupteur s'affiche dans le bon état.
 */
final class BootDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
	}

	public function test_la_case_est_transmise_cochee_par_defaut(): void {
		self::assertTrue( BootData::build()['allowDirectLogin'] );
	}

	public function test_la_case_decochee_est_transmise_decochee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];

		self::assertFalse( BootData::build()['allowDirectLogin'] );
	}
}
