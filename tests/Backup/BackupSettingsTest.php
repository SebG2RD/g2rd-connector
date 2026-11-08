<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Rest\AdminController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_REST_Request;

/**
 * Cases des sauvegardes enregistrées par le panneau React (POST /g2rd/v1/admin/save). Une sauvegarde
 * couvre tout le réseau : sur un site secondaire, l'administrateur du blog (manage_options sans être
 * super-administrateur) ne les enregistre pas ; les autres réglages de son blog restent enregistrés.
 */
final class BackupSettingsTest extends BackupTestCase {

	public function test_site_simple_les_cases_envoyees_par_le_panneau_sont_retenues(): void {
		$settings = ( new AdminController() )->collect_settings( $this->request() );

		self::assertTrue( $settings['backup_enabled'] );
		self::assertTrue( $settings['force_ipv4_to_storage'] );
	}

	/**
	 * Processus séparé pour les deux tests qui simulent is_main_site() (cf. BackupSwitchTest).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_secondaire_d_un_reseau_les_cases_sont_ignorees(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( false );

		$settings = ( new AdminController() )->collect_settings( $this->request() );

		self::assertArrayNotHasKey( 'backup_enabled', $settings );
		self::assertArrayNotHasKey( 'force_ipv4_to_storage', $settings );
		self::assertTrue( $settings['allow_direct_login'], 'les autres réglages du blog restent enregistrés' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_principal_d_un_reseau_les_cases_sont_retenues(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( true );

		$settings = ( new AdminController() )->collect_settings( $this->request() );

		self::assertTrue( $settings['backup_enabled'] );
		self::assertTrue( $settings['force_ipv4_to_storage'] );
	}

	/**
	 * Administrateur du site principal sans être super-administrateur : manage_options suffit pour
	 * ouvrir la page, mais pas pour ouvrir des sauvegardes de tout le réseau.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_administrateur_du_site_principal_non_super_administrateur_les_cases_sont_ignorees(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );

		$settings = ( new AdminController() )->collect_settings( $this->request() );

		self::assertArrayNotHasKey( 'backup_enabled', $settings );
		self::assertArrayNotHasKey( 'force_ipv4_to_storage', $settings );
		self::assertTrue( $settings['allow_direct_login'] );
		self::assertSame( BackupSwitch::LOCK_SUPER_ADMIN, BackupSwitch::network_lock() );
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/g2rd/v1/admin/save' );
		$request->set_param( 'manager_url', 'https://wp-manager.g2rd.fr' );
		$request->set_param( 'backup_enabled', true );
		$request->set_param( 'force_ipv4_to_storage', true );
		$request->set_param( 'allow_direct_login', true );
		return $request;
	}
}
