<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Admin;

use Brain\Monkey\Functions;
use G2RD\Connector\Admin\Page;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Page de réglages autonome (formulaire PHP, sans le thème G2RD) : la case
 * « Autoriser la connexion directe depuis G2RD » s'affiche, et le formulaire
 * l'enregistre cochée comme décochée. Un navigateur n'envoie pas une case
 * décochée : son absence dans $_POST vaut « non ».
 */
final class PageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( string $key ): string => strtolower( $key ) );
		Functions\when( 'settings_errors' )->justReturn( null );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, bool $display = true ): string {
				$result = (string) $checked === (string) $current ? " checked='checked'" : '';
				if ( $display ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doublure de checked().
				}
				return $result;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_la_case_est_affichee_et_cochee_par_defaut(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Autoriser la connexion directe depuis G2RD', $html );
		self::assertMatchesRegularExpression( '/name="g2rd_allow_direct_login" value="1"\s+checked=\'checked\'/', $html );
	}

	public function test_une_case_decochee_s_affiche_decochee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];

		$html = $this->render();

		self::assertStringContainsString( 'name="g2rd_allow_direct_login"', $html );
		self::assertDoesNotMatchRegularExpression( '/name="g2rd_allow_direct_login" value="1"\s+checked/', $html );
	}

	public function test_enregistrer_sans_la_case_la_decoche(): void {
		$_POST = $this->saved_form();

		$this->render();

		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['allow_direct_login'] );
	}

	public function test_enregistrer_avec_la_case_la_coche(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];
		$_POST                                 = $this->saved_form() + [ 'g2rd_allow_direct_login' => '1' ];

		$this->render();

		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['allow_direct_login'] );
	}

	/**
	 * @return array<string, string>
	 */
	private function saved_form(): array {
		return [
			'g2rd_connector_action' => 'save',
			'g2rd_connector_nonce'  => 'nonce',
			'g2rd_manager_url'      => 'https://wp-manager.g2rd.fr',
		];
	}

	private function render(): string {
		ob_start();
		( new Page() )->render_standalone_page();
		return (string) ob_get_clean();
	}
}
