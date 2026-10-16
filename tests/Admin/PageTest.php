<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Admin;

use Brain\Monkey\Functions;
use G2RD\Connector\Admin\Page;
use G2RD\Connector\BootData;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Page de réglages autonome (formulaire PHP, sans le thème G2RD) : la case
 * « Autoriser la connexion directe depuis G2RD » s'affiche, et le formulaire
 * l'enregistre cochée comme décochée. Un navigateur n'envoie pas une case
 * décochée : son absence dans $_POST vaut « non ».
 *
 * Choix de l'affichage : onglet de la console d'options du thème G2RD quand le
 * thème parent est un thème G2RD 1.19.0 ou plus (thème enfant compris), page
 * autonome sinon ; en mode onglet, la page reste joignable hors du menu.
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
		Functions\when( 'get_bloginfo' )->justReturn( '7.1.3' );
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

	/**
	 * WordPress 7 dessine la coche en blanc sur la couleur d'accent : la feuille du
	 * connecteur ne redonne ce fond aux cases cochées que sous la classe « --wp7 ».
	 * Sous WordPress 6, la coche est colorée sur fond blanc : peindre le fond la
	 * cacherait, d'où l'absence de la classe.
	 */
	public function test_la_page_porte_la_classe_wp7_sous_wordpress_7(): void {
		foreach ( [ '7.0-alpha-61234', '7.0-beta1', '7.0', '7.1.3' ] as $version ) {
			Functions\when( 'get_bloginfo' )->justReturn( $version );

			self::assertStringContainsString( 'class="wrap g2rd-connector-wrap g2rd-connector-wrap--wp7"', $this->render(), $version );
		}
	}

	public function test_la_page_ne_porte_pas_la_classe_wp7_sous_wordpress_6(): void {
		foreach ( [ '6.4', '6.9.4', '6.9.9' ] as $version ) {
			Functions\when( 'get_bloginfo' )->justReturn( $version );

			$html = $this->render();

			self::assertStringContainsString( 'class="wrap g2rd-connector-wrap"', $html, $version );
			self::assertStringNotContainsString( 'g2rd-connector-wrap--wp7', $html, $version );
		}
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
	 * Cas de g2rd.fr : le thème actif est l'enfant « g2rd-child » (1.0.0) ; c'est la
	 * version du thème parent, celui qui fournit la console, qui compte.
	 */
	public function test_un_theme_enfant_d_un_theme_g2rd_1_19_ou_plus_donne_l_onglet(): void {
		$this->active_theme( 'g2rd-child', 'g2rd-theme', [ 'g2rd-child' => '1.0.0', 'g2rd-theme' => '1.39.0' ] );

		self::assertSame( 'onglet', $this->display_mode() );
	}

	public function test_un_theme_enfant_d_un_theme_g2rd_plus_ancien_que_1_19_donne_la_page(): void {
		$this->active_theme( 'g2rd-child', 'g2rd-theme', [ 'g2rd-child' => '1.0.0', 'g2rd-theme' => '1.18.9' ] );

		self::assertSame( 'page', $this->display_mode() );
	}

	public function test_le_theme_g2rd_actif_sans_enfant_donne_l_onglet(): void {
		$this->active_theme( 'g2rd-theme', 'g2rd-theme', [ 'g2rd-theme' => '1.40.1' ] );

		self::assertSame( 'onglet', $this->display_mode() );
	}

	public function test_l_ancien_dossier_g2rd_du_theme_donne_aussi_l_onglet(): void {
		$this->active_theme( 'g2rd', 'g2rd', [ 'g2rd' => '1.19.0' ] );

		self::assertSame( 'onglet', $this->display_mode() );
	}

	public function test_un_autre_theme_donne_la_page(): void {
		$this->active_theme( 'twentytwentyfive', 'twentytwentyfive', [ 'twentytwentyfive' => '1.3' ] );

		self::assertSame( 'page', $this->display_mode() );
	}

	/**
	 * Un thème enfant dont le parent G2RD manque (supprimé, renommé) : pas de
	 * version lisible, donc pas de console à laquelle se fier.
	 */
	public function test_un_theme_parent_g2rd_absent_donne_la_page(): void {
		$this->active_theme( 'g2rd-child', 'g2rd-theme', [ 'g2rd-child' => '1.0.0' ] );

		self::assertSame( 'page', $this->display_mode() );
	}

	/**
	 * En mode onglet, la page autonome reste enregistrée, hors du menu, comme page
	 * de secours (admin.php?page=g2rd-connector) si la console du thème ne charge
	 * pas l'onglet. WordPress n'affiche que les sous-menus des menus de premier
	 * niveau : rattachée à « options.php », qui n'en est pas un, la page n'a pas
	 * d'entrée de menu. Elle sert le formulaire PHP, avec ses assets sur son hook.
	 */
	public function test_en_mode_onglet_la_page_de_secours_est_enregistree_sans_entree_de_menu(): void {
		$this->active_theme( 'g2rd-child', 'g2rd-theme', [ 'g2rd-child' => '1.0.0', 'g2rd-theme' => '1.39.0' ] );
		$submenu_pages = [];
		Functions\when( 'add_submenu_page' )->alias(
			static function ( ...$args ) use ( &$submenu_pages ): string {
				$submenu_pages[] = $args;
				return 'admin_page_' . $args[4];
			}
		);
		$page = new Page();
		$page->register();

		self::assertFalse( has_action( 'admin_menu', [ $page, 'register_top_level_menu' ] ) );
		self::assertNotFalse( has_action( 'admin_menu', [ $page, 'register_fallback_page' ] ) );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', [ $page, 'enqueue_fallback_assets' ] ) );
		$page->register_fallback_page();

		self::assertSame(
			[ [ 'options.php', 'G2RD Connector', 'G2RD Connector', 'manage_options', Page::MENU_SLUG, [ $page, 'render_standalone_page' ] ] ],
			$submenu_pages
		);
	}

	/**
	 * Métadonnées de l'onglet lues par la console du thème dans le filtre. L'icône
	 * est un nom du jeu de la console, « cloud » : le thème 1.39 en fait la classe
	 * « dashicons-cloud », le thème 1.41 la prend dans son jeu interne (toute autre
	 * valeur y devient « plug »). Les onglets d'autres extensions sont conservés.
	 */
	public function test_l_onglet_de_la_console_porte_ses_metadonnees(): void {
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		$autre = [
			'key'   => 'facturation',
			'label' => 'Facturation',
		];

		$tabs = ( new Page() )->register_as_theme_tab( [ $autre ] );

		self::assertCount( 2, $tabs );
		self::assertSame( $autre, $tabs[0] );
		self::assertSame(
			[
				'key'         => 'connector',
				'label'       => 'Manager G2RD',
				'description' => 'Connexion au tableau de bord centralisé G2RD WP Manager.',
				'icon'        => 'cloud',
				'mount_id'    => 'g2rd-connector-tab-root',
			],
			array_diff_key( $tabs[1], [ 'data' => true ] )
		);
		self::assertSame( BootData::build(), $tabs[1]['data'] );
	}

	/**
	 * Le script de l'onglet (assets/admin/src/index.tsx) repasse clé, libellé,
	 * description et icône à registerTab. Les consoles 1.39 et 1.41 n'y prennent que
	 * `mount` et lisent ces valeurs dans le filtre : les deux copies doivent rester
	 * identiques, sans quoi une console qui lirait un jour celles du script
	 * afficherait autre chose.
	 */
	public function test_les_metadonnees_du_script_restent_celles_du_filtre(): void {
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		$onglet = ( new Page() )->register_as_theme_tab( [] )[0];
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/src/index.tsx' );
		self::assertIsString( $source, 'assets/admin/src/index.tsx est illisible.' );

		self::assertSame(
			1,
			preg_match( '/registerTab\(\s*\{(.*?)\}\s*\)/s', $source, $appel ),
			'Appel à window.G2RDOptions.registerTab( { … } ) introuvable dans assets/admin/src/index.tsx.'
		);
		foreach ( [ 'key', 'label', 'description', 'icon' ] as $champ ) {
			self::assertSame(
				1,
				preg_match( '/\b' . $champ . ":\s*'((?:[^'\\\\]|\\\\.)*)'/", $appel[1], $valeur ),
				"Champ « {$champ} » introuvable dans l'appel à registerTab de index.tsx."
			);
			self::assertSame(
				$onglet[ $champ ],
				stripslashes( $valeur[1] ),
				"« {$champ} » diffère entre index.tsx et Page::register_as_theme_tab : à garder identique."
			);
		}
	}

	/**
	 * Thème actif simulé : $active est le dossier du thème actif, $template celui de
	 * son thème parent (le même s'il n'en a pas), $versions l'en-tête « Version » de
	 * chaque thème installé. wp_get_theme() sans argument rend le thème actif, comme
	 * WordPress ; un thème absent n'a aucun en-tête.
	 *
	 * @param array<string, string> $versions
	 */
	private function active_theme( string $active, string $template, array $versions ): void {
		$theme = static fn ( string $slug ): \WP_Theme => new \WP_Theme(
			$slug,
			isset( $versions[ $slug ] ) ? [ 'Version' => $versions[ $slug ] ] : [],
			isset( $versions[ $slug ] ),
			'',
			$slug === $active ? $template : ''
		);
		Functions\when( 'get_template' )->justReturn( $template );
		Functions\when( 'wp_get_theme' )->alias(
			static fn ( ?string $stylesheet = null ): \WP_Theme => $theme( $stylesheet ?? $active )
		);
	}

	/**
	 * Affichage retenu par register() : « onglet » (filtre de la console du thème,
	 * pas de menu de premier niveau) ou « page » (menu de premier niveau, pas
	 * d'onglet).
	 */
	private function display_mode(): string {
		$page = new Page();
		$page->register();

		$tab  = false !== has_filter( 'g2rd_options_external_tabs', [ $page, 'register_as_theme_tab' ] );
		$menu = false !== has_action( 'admin_menu', [ $page, 'register_top_level_menu' ] );
		if ( $tab === $menu ) {
			self::fail( 'register() doit choisir l\'onglet OU la page autonome, pas les deux ni aucun.' );
		}
		return $tab ? 'onglet' : 'page';
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
