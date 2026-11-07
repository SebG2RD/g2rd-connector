<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use G2RD\Connector\Rest\AdminController;
use G2RD\Connector\Tests\TestCase;
use WP_REST_Request;

/**
 * Le panneau React enregistre ses réglages par POST /g2rd/v1/admin/save : la case
 * de connexion directe doit y être retenue, sinon elle reviendrait cochée au
 * rechargement (même famille de défaut que la rc.3 sur l'option IPv4).
 */
final class AdminControllerSettingsTest extends TestCase {

	public function test_la_case_envoyee_par_le_panneau_est_retenue(): void {
		$request = $this->request();
		$request->set_param( 'allow_direct_login', false );

		self::assertFalse( ( new AdminController() )->collect_settings( $request )['allow_direct_login'] );
	}

	public function test_la_case_cochee_est_retenue(): void {
		$request = $this->request();
		$request->set_param( 'allow_direct_login', true );

		self::assertTrue( ( new AdminController() )->collect_settings( $request )['allow_direct_login'] );
	}

	public function test_absente_de_la_requete_elle_n_est_pas_touchee(): void {
		self::assertArrayNotHasKey( 'allow_direct_login', ( new AdminController() )->collect_settings( $this->request() ) );
	}

	public function test_la_case_du_releve_envoyee_par_le_panneau_est_retenue(): void {
		$request = $this->request();
		$request->set_param( 'php_errors_enabled', true );

		self::assertTrue( ( new AdminController() )->collect_settings( $request )['php_errors_enabled'] );
	}

	public function test_releve_decoche_dans_le_panneau_coupe_aussi_le_journal(): void {
		$request = $this->request();
		$request->set_param( 'php_errors_enabled', false );

		$settings = ( new AdminController() )->collect_settings( $request );

		self::assertFalse( $settings['php_errors_enabled'] );
		self::assertFalse( $settings['php_errors_log_tail'] );
	}

	/**
	 * La lecture du journal ne se règle que par le manager : relevé coché (ou
	 * absent de la requête), le panneau n'y touche pas.
	 */
	public function test_releve_coche_ou_absent_laisse_la_lecture_du_journal_au_manager(): void {
		$request = $this->request();
		$request->set_param( 'php_errors_enabled', true );

		self::assertArrayNotHasKey( 'php_errors_log_tail', ( new AdminController() )->collect_settings( $request ) );
		self::assertArrayNotHasKey( 'php_errors_log_tail', ( new AdminController() )->collect_settings( $this->request() ) );
		self::assertArrayNotHasKey( 'php_errors_enabled', ( new AdminController() )->collect_settings( $this->request() ) );
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/g2rd/v1/admin/save' );
		$request->set_param( 'manager_url', 'https://wp-manager.g2rd.fr' );
		return $request;
	}
}
