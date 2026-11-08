<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Functions;
use G2RD\Connector\BootData;
use G2RD\Connector\Settings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Données de démarrage du panneau React : l'état de la case de connexion directe
 * y figure, pour que l'interrupteur s'affiche dans le bon état.
 */
final class BootDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
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

	public function test_la_case_du_releve_est_transmise_decochee_par_defaut(): void {
		self::assertFalse( BootData::build()['phpErrorsEnabled'] );
	}

	public function test_hors_multisite_les_sauvegardes_ne_sont_pas_verrouillees(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => true ];

		$boot = BootData::build();

		self::assertTrue( $boot['backupEnabled'] );
		self::assertNull( $boot['backup']['lock'] );
		self::assertNull( $boot['backup']['lockMessage'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_secondaire_la_case_est_transmise_decochee_avec_la_phrase_du_verrou(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'backup_enabled' => true,
			'force_ipv4_to_storage' => true,
		];

		$boot = BootData::build();

		self::assertFalse( $boot['backupEnabled'] );
		self::assertFalse( $boot['forceIpv4ToStorage'] );
		self::assertSame( 'secondary', $boot['backup']['lock'] );
		self::assertStringContainsString( 'ouvrez les réglages du connecteur du site principal', (string) $boot['backup']['lockMessage'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_administrateur_non_super_administrateur_la_valeur_enregistree_est_montree_verrouillee(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => true ];

		$boot = BootData::build();

		self::assertTrue( $boot['backupEnabled'] );
		self::assertSame( 'super_admin', $boot['backup']['lock'] );
		self::assertStringContainsString( 'demandez au super-administrateur du réseau', (string) $boot['backup']['lockMessage'] );
	}

	public function test_la_case_du_releve_cochee_est_transmise_cochee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'php_errors_enabled' => true ];

		self::assertTrue( BootData::build()['phpErrorsEnabled'] );
	}
}
