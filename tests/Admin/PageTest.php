<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Admin;

use Brain\Monkey\Functions;
use G2RD\Connector\Admin\Page;
use G2RD\Connector\Backup\Keys\Fingerprint;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\BootData;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'checked' )->alias(
			static function ( $checked, $current = true, bool $display = true ): string {
				$result = (string) $checked === (string) $current ? " checked='checked'" : '';
				if ( $display ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doublure de checked().
				}
				return $result;
			}
		);
		Functions\when( 'disabled' )->alias(
			static function ( $disabled, $current = true, bool $display = true ): string {
				$result = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
				if ( $display ) {
					echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doublure de disabled().
				}
				return $result;
			}
		);
	}

	/**
	 * Dossiers de build fictifs créés par build_dir().
	 *
	 * @var list<string>
	 */
	private array $build_dirs = [];

	protected function tearDown(): void {
		$_POST = [];
		// Les dossiers de build fictifs ne servent qu'au test qui les a créés : on
		// les retire du dossier temporaire pour ne pas l'encombrer à chaque passage.
		foreach ( $this->build_dirs as $dir ) {
			foreach ( (array) glob( $dir . '*' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					unlink( $file );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->build_dirs = [];
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
	 * Réseau multisite : le formulaire PHP suit la même règle que le panneau. Processus séparé,
	 * comme les autres tests qui simulent is_multisite() (une fonction simulée reste définie).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_secondaire_le_formulaire_ignore_les_cases_et_les_affiche_desactivees(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'backup_enabled' => true,
			'force_ipv4_to_storage' => true,
		];
		$_POST                                 = $this->saved_form() + [ 'g2rd_backup_enabled' => '1' ];

		$html = $this->render();

		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['backup_enabled'], 'valeur enregistrée gardée' );
		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['force_ipv4_to_storage'], 'valeur enregistrée gardée' );
		self::assertMatchesRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+disabled=.disabled.>/', $html, 'case décochée et désactivée' );
		self::assertMatchesRegularExpression( '/name=.g2rd_force_ipv4_to_storage. value=.1.\s+disabled=.disabled.>/', $html );
		self::assertStringContainsString( 'les sauvegardes se règlent sur le site principal', $html );
		self::assertStringContainsString( 'ouvrez les réglages du connecteur du site principal', $html );
		// Comme le panneau : la phrase du verrou tient lieu d'aide, reliée à la case ; ni aide qui dit
		// de décocher une case grisée, ni empreinte.
		self::assertStringContainsString( 'aria-describedby="g2rd_backup_enabled_verrou" name="g2rd_backup_enabled"', $html );
		self::assertStringContainsString( '<p class="description" id="g2rd_backup_enabled_verrou">Sur un réseau multisite, ', $html );
		self::assertStringNotContainsString( 'id="g2rd_backup_enabled_aide"', $html );
		self::assertStringNotContainsString( 'Empreinte de la clé de signature du site', $html );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_administrateur_non_super_administrateur_le_formulaire_garde_la_valeur_enregistree(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => false ];
		$_POST                                 = $this->saved_form() + [
			'g2rd_backup_enabled' => '1',
			'g2rd_force_ipv4_to_storage' => '1',
		];

		$html = $this->render();

		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['backup_enabled'] );
		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['force_ipv4_to_storage'] ?? false );
		self::assertMatchesRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+disabled=.disabled.>/', $html );
		self::assertStringContainsString( 'seul un super-administrateur peut régler les sauvegardes', $html );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_super_administrateur_du_site_principal_le_formulaire_enregistre_les_cases(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( true );
		$_POST = $this->saved_form() + [ 'g2rd_backup_enabled' => '1' ];

		$html = $this->render();

		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['backup_enabled'] );
		self::assertDoesNotMatchRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+checked=.checked. disabled/', $html );
		self::assertStringNotContainsString( 'seul un super-administrateur', $html );
	}

	/** Clé de signature du site enregistrée : son empreinte est connue des deux écrans. */
	private function cle_de_signature(): string {
		$publique                            = str_repeat( 'ab', 32 );
		$this->options[ SigningKey::OPTION ] = [
			'seed'       => 'graine-chiffree',
			'public_key' => $publique,
			'created_at' => 1800000000,
		];

		return Fingerprint::display( (string) hex2bin( $publique ) );
	}

	/**
	 * Décision n° 5 du point de contrôle n° 2 de la phase 4 : constante d'arrêt posée, case grisée
	 * ET décochée (valeur enregistrée gardée), phrase courte qui dit quoi, la cause probable et quoi
	 * faire, reliée à la case ; aucune empreinte, comme le panneau. Aucun texte de la ligne ne dit
	 * plus que le manager « peut sauvegarder ce site » : la phrase de la constante tient lieu d'aide.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_constante_d_arret_case_grisee_et_decochee_avec_son_explication(): void {
		define( 'G2RD_CONNECTOR_DISABLE_BACKUP', true );
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => true ];
		$empreinte                             = $this->cle_de_signature();

		$html = $this->render();

		self::assertMatchesRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+disabled=.disabled.>/', $html, 'case décochée et désactivée' );
		self::assertStringContainsString( 'aria-describedby="g2rd_backup_enabled_constante" name="g2rd_backup_enabled"', $html, 'explication reliée à la case' );
		self::assertMatchesRegularExpression( '/<p class="description" id="g2rd_backup_enabled_constante">Aucune sauvegarde ne part de ce site[^<]* Cause probable : [^<]*G2RD_CONNECTOR_DISABLE_BACKUP[^<]* Action : [^<]*wp-config\.php[^<]*<\/p>/', $html, 'quoi, cause probable, action' );
		self::assertStringContainsString( 'les sauvegardes reprendront si la case était cochée.', $html, 'valeur enregistrée dite' );
		self::assertStringNotContainsString( 'peut sauvegarder ce site', $html, 'constante posée : rien ne dit que le manager peut sauvegarder' );
		self::assertStringNotContainsString( 'id="g2rd_backup_enabled_aide"', $html, 'la phrase de la constante tient lieu d’aide' );
		self::assertStringNotContainsString( $empreinte, $html, 'aucune empreinte, comme le panneau' );
		self::assertStringNotContainsString( 'Empreinte de la clé de signature du site', $html );
		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['backup_enabled'], 'valeur enregistrée gardée' );
	}

	/**
	 * Clé de signature pas encore créée (sa création a échoué à cette lecture), case cochée et
	 * réglable : même règle que le panneau, la ligne de l'empreinte dit « créée à l'enregistrement ».
	 */
	public function test_case_des_sauvegardes_cochee_sans_cle_l_empreinte_est_dite_creee_a_l_enregistrement(): void {
		Functions\when( 'add_option' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => true ];

		$html = $this->render();

		self::assertArrayNotHasKey( SigningKey::OPTION, $this->options, 'aucune clé enregistrée' );
		self::assertMatchesRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+checked=.checked.\s*>/', $html );
		self::assertStringContainsString( '<p class="description">Empreinte de la clé de signature du site, à comparer à celle de la page d’approbation du service de clés G2RD : créée à l’enregistrement.</p>', $html );
	}

	public function test_case_des_sauvegardes_decochee_aucune_empreinte_comme_le_panneau(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => false ];
		$empreinte                             = $this->cle_de_signature();

		$html = $this->render();

		self::assertDoesNotMatchRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+checked/', $html );
		self::assertStringNotContainsString( 'Empreinte de la clé de signature du site', $html );
		self::assertStringNotContainsString( $empreinte, $html );
		self::assertStringNotContainsString( 'créée à l’enregistrement', $html );
		self::assertStringNotContainsString( 'g2rd_backup_enabled_constante', $html, 'sans constante : aucune phrase de constante' );
	}

	public function test_case_des_sauvegardes_cochee_l_empreinte_est_montree(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'backup_enabled' => true ];
		$empreinte                             = $this->cle_de_signature();

		$html = $this->render();

		self::assertMatchesRegularExpression( '/name=.g2rd_backup_enabled. value=.1.\s+checked=.checked.\s*>/', $html );
		self::assertStringContainsString( 'aria-describedby="g2rd_backup_enabled_aide" name="g2rd_backup_enabled"', $html );
		self::assertStringContainsString( 'Empreinte de la clé de signature du site, à comparer à celle de la page d’approbation du service de clés G2RD : <code>' . $empreinte . '</code>', $html );
	}

	public function test_la_case_du_releve_est_affichee_decochee_par_defaut(): void {
		$html = $this->render();

		self::assertStringContainsString( 'Relevé des erreurs PHP', $html );
		self::assertStringContainsString( 'name="g2rd_php_errors_enabled"', $html );
		self::assertDoesNotMatchRegularExpression( '/name="g2rd_php_errors_enabled" value="1"\s+checked/', $html );
	}

	public function test_enregistrer_avec_la_case_du_releve_la_coche(): void {
		$_POST = $this->saved_form() + [ 'g2rd_php_errors_enabled' => '1' ];

		$this->render();

		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['php_errors_enabled'] );
	}

	public function test_enregistrer_sans_la_case_du_releve_coupe_aussi_le_journal(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled'  => true,
			'php_errors_log_tail' => true,
		];
		$_POST                                 = $this->saved_form();

		$this->render();

		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['php_errors_enabled'] );
		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['php_errors_log_tail'] );
	}

	/**
	 * La lecture du journal ne se règle que par le manager (commande signée) : la
	 * page n'a pas de case pour elle, et l'enregistrer avec le relevé coché la
	 * laisse telle que le manager l'a mise.
	 */
	public function test_enregistrer_avec_la_case_du_releve_garde_la_lecture_du_journal(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled'  => true,
			'php_errors_log_tail' => true,
		];
		$_POST                                 = $this->saved_form() + [ 'g2rd_php_errors_enabled' => '1' ];

		$this->render();

		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['php_errors_enabled'] );
		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['php_errors_log_tail'] );
	}

	/**
	 * Cas de g2rd.fr : le thème actif est l'enfant « g2rd-child » (1.0.0) ; c'est la
	 * version du thème parent, celui qui fournit la console, qui compte.
	 */
	public function test_un_theme_enfant_d_un_theme_g2rd_1_19_ou_plus_donne_l_onglet(): void {
		$this->active_theme(
			'g2rd-child',
			'g2rd-theme',
			[
				'g2rd-child' => '1.0.0',
				'g2rd-theme' => '1.39.0',
			]
		);

		self::assertSame( 'onglet', $this->display_mode() );
	}

	public function test_un_theme_enfant_d_un_theme_g2rd_plus_ancien_que_1_19_donne_la_page(): void {
		$this->active_theme(
			'g2rd-child',
			'g2rd-theme',
			[
				'g2rd-child' => '1.0.0',
				'g2rd-theme' => '1.18.9',
			]
		);

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
		$this->active_theme(
			'g2rd-child',
			'g2rd-theme',
			[
				'g2rd-child' => '1.0.0',
				'g2rd-theme' => '1.39.0',
			]
		);
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
	 * La page de secours ne charge ses assets que sur son propre hook : avant son
	 * enregistrement (hook encore inconnu), et sur toute autre page de
	 * l'administration, rien n'est chargé. Sur son hook, seule la feuille l'est
	 * (correctif des cases cochées du formulaire PHP) : sans conteneur React, le
	 * script, React et les données du panneau n'y servent à rien.
	 */
	public function test_la_page_de_secours_ne_charge_que_sa_feuille_et_seulement_sur_son_hook(): void {
		Functions\when( 'add_submenu_page' )->justReturn( 'admin_page_g2rd-connector' );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		$dir     = $this->build_dir( true );
		$charges = [];
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle ) use ( &$charges ): void {
				$charges[] = $handle;
			}
		);
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		$page = new Page( $dir, 'https://site.test/build/' );
		$page->enqueue_fallback_assets( 'admin_page_g2rd-connector' );
		self::assertSame( [], $charges, 'Page de secours pas encore enregistrée : rien ne doit être chargé.' );

		$page->register_fallback_page();
		foreach ( [ 'index.php', 'plugins.php', 'toplevel_page_g2rd', 'appearance_page_g2rd-options', '' ] as $hook ) {
			$page->enqueue_fallback_assets( $hook );
		}
		self::assertSame( [], $charges, 'Hors de la page de secours, rien ne doit être chargé.' );

		$page->enqueue_fallback_assets( 'admin_page_g2rd-connector' );
		self::assertSame( [ 'g2rd-connector-admin' ], $charges, 'Sur la page de secours, la feuille seule doit être chargée.' );
	}

	/**
	 * Page autonome (menu de premier niveau) : même formulaire PHP, même chargement
	 * de la feuille seule, sur son seul hook.
	 */
	public function test_la_page_autonome_ne_charge_que_sa_feuille(): void {
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		Functions\when( 'wp_script_is' )->justReturn( true );
		$dir     = $this->build_dir( true );
		$charges = [];
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle, string $src ) use ( &$charges ): void {
				$charges[] = [ $handle, $src ];
			}
		);
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		$page = new Page( $dir, 'https://site.test/build/' );
		$page->enqueue_standalone_assets( 'index.php' );
		self::assertSame( [], $charges, 'Hors de la page autonome, rien ne doit être chargé.' );

		$page->enqueue_standalone_assets( 'toplevel_page_g2rd-connector' );
		self::assertSame( [ [ 'g2rd-connector-admin', 'https://site.test/build/style-index.css' ] ], $charges );
	}

	/**
	 * Formulaire PHP : chaque case a pour nom le titre de sa ligne (pas sa longue
	 * description, qui devient son aide) ; site enrôlé signalé par un avis neutre,
	 * pas vert, car la page ne vérifie pas les échanges avec le manager.
	 */
	public function test_les_cases_du_formulaire_ont_le_titre_pour_nom_et_la_description_pour_aide(): void {
		Functions\when( 'get_site_option' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'manager_url' => 'https://wp-manager.g2rd.fr',
			'site_id'     => 7,
			'site_token'  => 'jeton-historique',
		];
		$html = $this->render();

		foreach ( [ 'g2rd_heartbeat_enabled', 'g2rd_events_enabled', 'g2rd_remote_commands_enabled', 'g2rd_force_ipv4_to_manager' ] as $case ) {
			self::assertMatchesRegularExpression( '/<th scope="row" id="' . $case . '_titre">/', $html, "Titre de « {$case} » sans identifiant." );
			self::assertMatchesRegularExpression(
				'/<input type="checkbox" aria-labelledby="' . $case . '_titre" aria-describedby="' . $case . '_aide"\s+name="' . $case . '"/',
				$html,
				"La case « {$case} » doit être nommée par le titre de sa ligne et décrite par sa description."
			);
			self::assertStringContainsString( '<span id="' . $case . '_aide">', $html );
		}
		self::assertStringContainsString( 'aria-describedby="g2rd_allow_direct_login_aide" name="g2rd_allow_direct_login"', $html );
		self::assertStringContainsString( '<p class="description" id="g2rd_allow_direct_login_aide">', $html );
		// Relevé des erreurs PHP : libellé court, explication en paragraphe d'aide gris, comme la connexion directe.
		self::assertStringContainsString( 'aria-describedby="g2rd_php_errors_enabled_aide"', $html );
		self::assertStringContainsString( '<p class="description" id="g2rd_php_errors_enabled_aide">', $html );
		self::assertStringContainsString( 'Pour l’arrêter, décochez la case puis enregistrez', $html );
		// Sauvegardes chiffrées et IPv4 vers le stockage : même modèle (libellé court dans le label,
		// explication en aide grise reliée par aria-describedby, qui dit comment l'arrêter).
		foreach (
			[
				'g2rd_backup_enabled'        => [ 'Autoriser les sauvegardes chiffrées de ce site', 'Pour les arrêter, décochez la case puis enregistrez' ],
				'g2rd_force_ipv4_to_storage' => [ 'Envoyer les sauvegardes par l’IPv4', 'Pour l’arrêter, décochez la case puis enregistrez' ],
			] as $case => [ $libelle, $arret ]
		) {
			self::assertStringNotContainsString( 'id="' . $case . '_titre"', $html, "« {$case} » : titre sans identifiant, comme le relevé des erreurs PHP." );
			self::assertStringNotContainsString( 'aria-labelledby="' . $case . '_titre"', $html );
			self::assertMatchesRegularExpression( '/<input type="checkbox" aria-describedby="' . $case . '_aide" name="' . $case . '" value="1"[^>]*>\s*' . preg_quote( $libelle, '/' ) . '\s*<\/label>/', $html, "« {$case} » : libellé court dans le label." );
			self::assertMatchesRegularExpression( '/<p class="description" id="' . $case . '_aide">[^<]*' . preg_quote( $arret, '/' ) . '[^<]*<\/p>/', $html, "« {$case} » : aide grise qui dit comment l'arrêter." );
		}

		self::assertStringContainsString( '<div class="notice notice-info inline">', $html );
		self::assertStringNotContainsString( 'notice-success', $html );
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
	 * Thèmes 1.19 à 1.40 : la console est la page Apparence → Options G2RD.
	 */
	public function test_le_script_de_l_onglet_se_charge_sur_la_console_des_themes_1_19_a_1_40(): void {
		self::assertTrue( Page::is_theme_console_page( 'appearance_page_g2rd-options' ) );
	}

	/**
	 * Thèmes 1.41 et plus : la console est la page du menu de premier niveau
	 * « G2RD » (slug \G2RD\AdminConsole::MENU_SLUG, « g2rd ») ; l'ancienne adresse
	 * y est redirigée, le hook de la 1.40 n'y est jamais atteint. Sans ce hook,
	 * registerTab n'était jamais appelé et l'onglet « Manager G2RD » restait vide.
	 */
	public function test_le_script_de_l_onglet_se_charge_sur_la_console_des_themes_1_41_et_plus(): void {
		self::assertTrue( Page::is_theme_console_page( 'toplevel_page_g2rd' ) );
	}

	/**
	 * Ailleurs, rien n'est chargé : ni sur les autres pages de l'administration, ni
	 * sur les sous-pages du menu « G2RD » (« g2rd_page_… », sans onglets
	 * d'extension), ni sur la page du connecteur (ses propres assets ont leur hook).
	 */
	public function test_rien_n_est_charge_hors_de_la_console_du_theme(): void {
		foreach ( [ 'dashboard', 'index.php', 'plugins.php', 'g2rd_page_g2rd-facturation', 'toplevel_page_g2rd-connector', 'admin_page_g2rd-connector', 'appearance_page_g2rd', 'toplevel_page_g2rd-options', '' ] as $hook ) {
			self::assertFalse( Page::is_theme_console_page( $hook ), "Hook « {$hook} »." );
		}

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_localize_script' )->never();
		foreach ( [ 'dashboard', 'g2rd_page_g2rd-facturation', 'toplevel_page_g2rd-connector' ] as $hook ) {
			( new Page() )->enqueue_react_tab( $hook );
		}
	}

	/**
	 * Dépendances du script : la liste fixe, plus celles que wp-scripts écrit dans
	 * index.asset.php (react-jsx-runtime, lu par le panneau compilé), sans doublon ;
	 * la liste fixe seule si le fichier manque ou ne rend pas de tableau.
	 */
	public function test_les_dependances_du_script_viennent_du_build(): void {
		$base = [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'react', 'react-dom' ];
		$dir  = $this->build_dir( true );

		self::assertSame( array_merge( $base, [ 'react-jsx-runtime' ] ), Page::script_dependencies( $dir . 'index.asset.php' ) );
		self::assertSame( $base, Page::script_dependencies( $dir . 'absent.asset.php' ) );

		file_put_contents( $dir . 'illisible.asset.php', "<?php return 'pas un tableau';\n" );
		self::assertSame( $base, Page::script_dependencies( $dir . 'illisible.asset.php' ) );
	}

	/**
	 * WordPress 6.6 et plus : toutes les dépendances sont enregistrées, le panneau
	 * est chargé avec celles du build, et aucun avis n'est ajouté.
	 */
	public function test_avec_toutes_les_dependances_le_panneau_est_charge(): void {
		$this->stub_boot_data();
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		$dir = $this->build_dir( true );

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'g2rd-connector-admin', 'https://site.test/build/index.js', \Mockery::on( static fn ( array $deps ): bool => in_array( 'react-jsx-runtime', $deps, true ) ), \Mockery::type( 'string' ), true );
		Functions\expect( 'wp_localize_script' )->once();
		Functions\expect( 'wp_enqueue_style' )->once();

		$page = new Page();
		$page->enqueue_build( $dir, 'https://site.test/build/', true );

		self::assertFalse( has_action( 'admin_notices', [ $page, 'render_missing_scripts_notice' ] ) );
	}

	/**
	 * Sous WordPress 6.4 et 6.5, react-jsx-runtime n'est pas enregistré : le panneau
	 * planterait (window.ReactJSXRuntime absent). Dans l'onglet de la console, il
	 * n'est pas chargé et un avis dit quoi faire, avec le lien vers la page de
	 * réglages PHP ; la feuille reste chargée.
	 */
	public function test_sans_react_jsx_runtime_l_onglet_affiche_un_avis_au_lieu_du_panneau(): void {
		Functions\when( 'wp_script_is' )->alias( static fn ( string $handle ): bool => 'react-jsx-runtime' !== $handle );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5.5' );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		Functions\when( 'add_submenu_page' )->justReturn( 'admin_page_g2rd-connector' );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-admin/' . $path );
		$dir = $this->build_dir( true );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_localize_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->once();

		$page = new Page();
		$page->register_fallback_page();
		$page->enqueue_build( $dir, 'https://site.test/build/', true );

		self::assertNotFalse( has_action( 'admin_notices', [ $page, 'render_missing_scripts_notice' ] ) );
		ob_start();
		$page->render_missing_scripts_notice();
		$avis = (string) ob_get_clean();
		self::assertStringContainsString( 'L’onglet « Manager G2RD » ne peut pas s’afficher.', $avis );
		self::assertStringContainsString( 'Ce panneau demande WordPress 6.6 ou plus récent (version installée : 6.5.5) : mettez WordPress à jour.', $avis );
		self::assertStringContainsString( 'href="https://site.test/wp-admin/admin.php?page=g2rd-connector"', $avis );
	}

	/**
	 * WordPress récent dont un script a été retiré (extension, thème) : l'avis ne
	 * parle pas de version de WordPress, il nomme le script absent et la cause
	 * probable.
	 */
	public function test_un_script_retire_sous_un_wordpress_recent_est_nomme(): void {
		Functions\when( 'wp_script_is' )->alias( static fn ( string $handle ): bool => 'wp-components' !== $handle );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		$dir = $this->build_dir( true );

		$page = new Page();
		$page->enqueue_build( $dir, 'https://site.test/build/', true );
		ob_start();
		$page->render_missing_scripts_notice();
		$avis = (string) ob_get_clean();

		self::assertStringContainsString( 'ne sont pas enregistrés (wp-components)', $avis );
		self::assertStringContainsString( 'extension ou le thème', $avis );
		self::assertStringNotContainsString( '6.6', $avis );
		// Pas de page de secours enregistrée (mode page autonome) : pas de lien.
		self::assertStringNotContainsString( 'href=', $avis );
	}

	/**
	 * Page autonome et page de secours : le formulaire PHP n'a pas besoin du
	 * script ; sans ses dépendances, il n'est pas chargé, sans avis, et la feuille
	 * (correctif des cases cochées) reste chargée.
	 */
	public function test_hors_de_l_onglet_un_script_manquant_ne_donne_pas_d_avis(): void {
		Functions\when( 'wp_script_is' )->alias( static fn ( string $handle ): bool => 'react-jsx-runtime' !== $handle );
		Functions\when( 'wp_style_add_data' )->justReturn( true );
		$dir = $this->build_dir( true );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->once();

		$page = new Page();
		$page->enqueue_build( $dir, 'https://site.test/build/', false );

		self::assertFalse( has_action( 'admin_notices', [ $page, 'render_missing_scripts_notice' ] ) );
	}

	/**
	 * Le formulaire PHP (page autonome, page de secours) parle comme le panneau
	 * React : mêmes libellés pour les mêmes réglages, plus aucun ancien libellé, et
	 * pas de « Connecté » que rien ne prouve ici. Les noms des champs envoyés, eux,
	 * ne changent pas.
	 */
	public function test_le_formulaire_reprend_les_libelles_du_panneau(): void {
		Functions\when( 'get_site_option' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'manager_url' => 'https://wp-manager.g2rd.fr',
			'site_id'     => 7,
			'site_token'  => 'jeton-historique',
		];
		$enrole                                = $this->render();
		$this->options[ Settings::OPTION_KEY ] = [ 'manager_url' => 'https://wp-manager.g2rd.fr' ];
		$non_enrole                            = $this->render();

		foreach ( [
			'Adresse du manager',
			'Signe de vie horaire',
			'Événements en temps réel',
			'Commandes à distance',
			'IPv4 vers le manager',
			'Connexion directe depuis G2RD',
			'Relevé des erreurs PHP',
			'Site enrôlé.',
			'Manager : https://wp-manager.g2rd.fr — site n° 7.',
			'Dernière capture des mises à jour tierces',
			'Déconnecter du manager',
		] as $texte ) {
			self::assertStringContainsString( $texte, $enrole, "Texte « {$texte} » absent de la page." );
		}
		self::assertStringContainsString( 'Jeton d’invitation', $non_enrole );
		self::assertStringContainsString( 'Enrôler le site', $non_enrole );

		foreach ( [ 'URL du manager', 'Token d', 'Heartbeat horaire', 'Webhook events', 'Commandes distantes', 'Connecté à', 'site ID', 'MAJ tierces' ] as $ancien ) {
			self::assertStringNotContainsString( $ancien, $enrole . $non_enrole, "Ancien libellé « {$ancien} » encore présent dans la page." );
		}
		foreach ( [ 'g2rd_manager_url', 'g2rd_invitation_token', 'g2rd_heartbeat_enabled', 'g2rd_events_enabled', 'g2rd_remote_commands_enabled', 'g2rd_force_ipv4_to_manager', 'g2rd_allow_direct_login', 'g2rd_php_errors_enabled' ] as $champ ) {
			self::assertStringContainsString( 'name="' . $champ . '"', $enrole . $non_enrole, "Champ « {$champ} » absent du formulaire." );
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

	/**
	 * Dossier de build fictif (index.js, style-index.css et, au choix,
	 * index.asset.php comme l'écrit wp-scripts), propre à chaque test.
	 */
	private function build_dir( bool $with_asset ): string {
		$dir = sys_get_temp_dir() . '/g2rd-connector-tests-build-' . uniqid( '', true ) . '/';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . 'index.js', "// build fictif\n" );
		file_put_contents( $dir . 'style-index.css', "/* build fictif */\n" );
		if ( $with_asset ) {
			file_put_contents(
				$dir . 'index.asset.php',
				"<?php return array( 'dependencies' => array( 'react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-element' ), 'version' => 'test' );\n"
			);
		}
		$this->build_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Fonctions lues par BootData::build(), passé au script par wp_localize_script.
	 */
	private function stub_boot_data(): void {
		Functions\when( 'get_site_option' )->justReturn( false );
		Functions\when( 'rest_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
	}

	private function render(): string {
		ob_start();
		( new Page() )->render_standalone_page();
		return (string) ob_get_clean();
	}
}
