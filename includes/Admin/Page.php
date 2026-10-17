<?php
/**
 * Page d'administration du plugin G2RD Connector.
 *
 * Comportement adaptatif :
 *  - Si le thème parent est un thème G2RD qui expose le filtre
 *    `g2rd_options_external_tabs` (≥ v1.19, thème enfant compris) → on s'enregistre
 *    comme tab dans la console d'options du thème (Apparence → Options G2RD
 *    jusqu'à la 1.40, menu de premier niveau « G2RD » à partir de la 1.41, cf.
 *    is_theme_console_page()). La page autonome reste alors enregistrée
 *    HORS du menu, comme page de secours (admin.php?page=g2rd-connector) si la
 *    console du thème ne charge pas l'onglet.
 *  - Sinon → menu top-level "G2RD Connector" avec dashicon dédié.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Admin;

use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;
use G2RD\Connector\Updates\PremiumUpdatesBridge;

final class Page {

	public const MENU_SLUG   = 'g2rd-connector';
	private const CAPABILITY = 'manage_options';

	/**
	 * Parent de la page de secours. WordPress n'affiche que les sous-menus des
	 * menus de premier niveau : « options.php » (écran « Toutes les options ») n'en
	 * est pas un, la page reste donc hors du menu, mais WordPress la sert, titre
	 * compris, par admin.php?page=g2rd-connector. Un parent null ou vide la
	 * cacherait aussi, mais avec des avertissements de dépréciation sous PHP 8.1+
	 * (sources de WordPress 6.9) : null passe par plugin_basename() puis
	 * wp_normalize_path() (strpos() et str_replace() sur null) ; avec null comme
	 * avec '', get_admin_page_parent() rend '' et get_admin_page_title() ne trouve
	 * pas le titre, que admin-header.php passe alors à strip_tags( null ).
	 */
	private const FALLBACK_PARENT = 'options.php';

	/**
	 * Suffixe de hook de la page de secours, tel que rendu par add_submenu_page()
	 * (vide tant qu'elle n'est pas enregistrée).
	 */
	private string $fallback_hook = '';

	/**
	 * Dépendances du script du panneau toujours demandées, auxquelles s'ajoutent
	 * celles que wp-scripts écrit dans assets/admin/build/index.asset.php.
	 */
	private const SCRIPT_DEPENDENCIES = [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'react', 'react-dom' ];

	/**
	 * Scripts de WordPress dont le panneau a besoin mais qui ne sont pas
	 * enregistrés sur ce site (vide si rien ne manque) : le panneau n'est alors
	 * pas chargé (cf. enqueue_build).
	 *
	 * @var list<string>
	 */
	private array $missing_scripts = [];

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// K4 : jeton de connexion illisible (altéré en base ou sels de wp-config.php
		// changés). Le site est alors coupé du manager ; on le dit à l'administrateur.
		add_action( 'admin_notices', [ $this, 'render_token_notice' ] );

		if ( $this->theme_supports_external_tabs() ) {
			add_filter( 'g2rd_options_external_tabs', [ $this, 'register_as_theme_tab' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_react_tab' ] );
			// Page de secours hors du menu : si la console du thème ne charge pas
			// l'onglet (thème modifié, erreur JavaScript), les réglages restent
			// joignables par admin.php?page=g2rd-connector.
			add_action( 'admin_menu', [ $this, 'register_fallback_page' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_fallback_assets' ] );
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_top_level_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_standalone_assets' ] );
	}

	/**
	 * Détecte si le thème parent est g2rd-theme ET en version ≥ 1.19 (filtre dispo).
	 *
	 * Thème parent (template) : celui du thème enfant actif, ou le thème actif
	 * lui-même s'il n'a pas de parent. C'est lui qui fournit la console d'options ;
	 * un thème enfant (g2rd-child 1.0.0 sur g2rd.fr) porte sa propre version, sans
	 * rapport avec elle. Parent absent : aucune version, donc la page autonome.
	 */
	private function theme_supports_external_tabs(): bool {
		$template = (string) get_template();
		if ( ! in_array( $template, [ 'g2rd-theme', 'g2rd' ], true ) ) {
			return false;
		}
		$version = (string) wp_get_theme( $template )->get( 'Version' );
		// Le filtre est introduit par le patch theme à partir de 1.19.0.
		return version_compare( $version, '1.19.0', '>=' );
	}

	/**
	 * Avis affiché sur tout l'administration, aux seuls administrateurs, quand le
	 * jeton de connexion au manager est stocké mais inutilisable : illisible, ou
	 * refusé par le mode strict (ancien format non authentifié).
	 */
	public function render_token_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$message = self::token_problem( Settings::token_state() );
		if ( null === $message ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'G2RD Connector : connexion au manager interrompue.', 'g2rd-connector' ); ?></strong>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Explication (cause et action) d'un jeton stocké mais inutilisable, ou null si
	 * le jeton est utilisable ou absent.
	 */
	private static function token_problem( string $state ): ?string {
		$action = __( 'Sur la page du connecteur G2RD, cliquez sur « Déconnecter du manager », puis enrôlez le site avec une nouvelle invitation générée depuis sa fiche dans le manager.', 'g2rd-connector' );
		if ( 'unreadable' === $state ) {
			return __( 'Le jeton de connexion au manager G2RD ne peut pas être lu : il a été modifié dans la base de données, ou les clés de sécurité de wp-config.php (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY) ont changé. Le site ne communique plus avec le manager. Si les clés ont changé, restaurez celles d\'origine. Sinon :', 'g2rd-connector' ) . ' ' . $action;
		}
		if ( 'refused' === $state ) {
			return __( 'Le jeton de connexion au manager G2RD est stocké dans un ancien format non protégé contre la modification (ou en clair), que ce site refuse : la constante G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN est activée dans wp-config.php. Cause probable : la valeur a été remplacée dans la base de données, ou le jeton n\'avait pas encore été converti au nouveau format. Le site ne communique plus avec le manager.', 'g2rd-connector' ) . ' ' . $action;
		}
		return null;
	}

	public function register_settings(): void {
		register_setting(
			'g2rd_connector',
			Settings::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Hook thème : enregistre un tab dans la page Options G2RD.
	 *
	 * La console lit ici le libellé, la description et l'icône de l'onglet ; le
	 * script (assets/admin/src/index.tsx) n'en fournit que le rendu (mount).
	 * `icon` est un nom du jeu d'icônes de la console, pas une classe dashicons :
	 * le thème 1.39 en fait « dashicons-cloud », le thème 1.41 la prend dans son
	 * jeu interne (toute autre valeur y devient l'icône « plug »).
	 *
	 * @param array<int, array<string, mixed>> $tabs
	 * @return array<int, array<string, mixed>>
	 */
	public function register_as_theme_tab( array $tabs ): array {
		$tabs[] = [
			'key'         => 'connector',
			'label'       => __( 'Manager G2RD', 'g2rd-connector' ),
			'description' => __( 'Connexion au tableau de bord centralisé G2RD WP Manager.', 'g2rd-connector' ),
			'icon'        => 'cloud',
			'mount_id'    => 'g2rd-connector-tab-root',
			'data'        => $this->initial_data(),
		];
		return $tabs;
	}

	public function register_top_level_menu(): void {
		add_menu_page(
			__( 'G2RD Connector', 'g2rd-connector' ),
			__( 'G2RD Connector', 'g2rd-connector' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_standalone_page' ],
			'dashicons-cloud',
			81
		);
	}

	/**
	 * Page de secours du mode onglet : la page autonome, hors du menu (cf.
	 * FALLBACK_PARENT), même formulaire et même correctif des cases cochées.
	 */
	public function register_fallback_page(): void {
		$hook = add_submenu_page(
			self::FALLBACK_PARENT,
			__( 'G2RD Connector', 'g2rd-connector' ),
			__( 'G2RD Connector', 'g2rd-connector' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_standalone_page' ]
		);
		// false : l'utilisateur n'a pas la capacité requise, WordPress refuse la page.
		$this->fallback_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Classes du conteneur de la page autonome.
	 *
	 * « --wp7 » sous WordPress 7 et plus : WordPress y dessine la coche en blanc sur
	 * la couleur d'accent, et un thème qui donne un fond blanc à toutes les cases de
	 * l'administration la rend invisible ; la feuille du connecteur redonne ce fond
	 * sous cette classe seulement (assets/admin/src/style.css). Sous WordPress 6, la
	 * coche est colorée sur fond blanc : peindre le fond la cacherait.
	 */
	private static function wrap_class(): string {
		$classes = 'wrap g2rd-connector-wrap';
		if ( version_compare( (string) get_bloginfo( 'version' ), '7.0-alpha', '>=' ) ) {
			$classes .= ' g2rd-connector-wrap--wp7';
		}
		return $classes;
	}

	/**
	 * Page autonome (sans thème G2RD) et page de secours du mode onglet :
	 * formulaire PHP. Libellés et descriptions identiques à ceux du panneau React
	 * (assets/admin/src/ConnectorPanel.tsx), pour un seul vocabulaire des mêmes
	 * réglages ; les noms des champs envoyés restent inchangés.
	 */
	public function render_standalone_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$this->maybe_handle_form();
		$settings = Settings::all();
		$enrolled = Settings::is_enrolled();
		// Jeton stocké mais inutilisable : le site reste « enrôlé » (d'où le bouton
		// Déconnecter), mais ne parle plus au manager. On montre l'état réel.
		$problem = $enrolled ? self::token_problem( Settings::token_state() ) : null;
		?>
		<div class="<?php echo esc_attr( self::wrap_class() ); ?>">
			<h1><?php echo esc_html__( 'G2RD Connector', 'g2rd-connector' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Lie ce site WordPress au tableau de bord centralisé G2RD WP Manager.', 'g2rd-connector' ); ?>
			</p>

			<?php if ( null !== $problem ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php echo esc_html__( 'Site enrôlé, mais connexion au manager interrompue.', 'g2rd-connector' ); ?></strong>
						<?php echo esc_html( $problem ); ?>
					</p>
				</div>
			<?php elseif ( $enrolled ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php echo esc_html__( 'Site enrôlé.', 'g2rd-connector' ); ?></strong>
						<?php
						printf(
							/* translators: %1$s adresse du manager, %2$d numéro du site dans le manager */
							esc_html__( 'Manager : %1$s — site n° %2$d.', 'g2rd-connector' ),
							esc_html( (string) $settings['manager_url'] ),
							(int) $settings['site_id']
						);
						?>
					</p>
					<p>
						<?php
						// Diagnostic : prouve que la copie des MAJ tierces tourne. Ces
						// MAJ ne sont visibles du manager que grâce à elle, cf.
						// PremiumUpdatesBridge.
						$capture = PremiumUpdatesBridge::last_capture();
						if ( null === $capture ) {
							echo esc_html__( 'Dernière capture des mises à jour tierces : jamais. Ouvrez Extensions ou Tableau de bord → Mises à jour pour l’amorcer.', 'g2rd-connector' );
						} else {
							$captured_ts = strtotime( $capture['captured_at'] );
							printf(
								/* translators: 1: date locale de la capture, 2: nombre de plugins, 3: nombre de thèmes */
								esc_html__( 'Dernière capture des mises à jour tierces : %1$s — %2$d extension(s), %3$d thème(s).', 'g2rd-connector' ),
								esc_html( (string) wp_date( 'j M Y H:i', false === $captured_ts ? null : $captured_ts ) ),
								(int) $capture['plugins'],
								(int) $capture['themes']
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'g2rd_connector_save', 'g2rd_connector_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="g2rd_manager_url"><?php echo esc_html__( 'Adresse du manager', 'g2rd-connector' ); ?></label></th>
						<td>
							<input type="url" name="g2rd_manager_url" id="g2rd_manager_url"
								value="<?php echo esc_attr( (string) $settings['manager_url'] ); ?>"
								class="regular-text" required>
							<p class="description"><?php echo esc_html__( 'Adresse de votre tableau de bord G2RD WP Manager, par exemple https://wp-manager.g2rd.fr.', 'g2rd-connector' ); ?></p>
						</td>
					</tr>
					<?php if ( ! $enrolled ) : ?>
					<tr>
						<th scope="row"><label for="g2rd_invitation_token"><?php echo esc_html__( 'Jeton d’invitation', 'g2rd-connector' ); ?></label></th>
						<td>
							<input type="text" name="g2rd_invitation_token" id="g2rd_invitation_token"
								class="regular-text" autocomplete="off">
							<p class="description"><?php echo esc_html__( 'Généré dans le manager : fiche du site, « Inviter ce site à se connecter ». Valable 15 minutes.', 'g2rd-connector' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Signe de vie horaire', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_heartbeat_enabled" value="1"
									<?php checked( (bool) $settings['heartbeat_enabled'] ); ?>>
								<?php echo esc_html__( 'Toutes les heures, le site envoie au manager ses versions (WordPress, PHP, connecteur), l’espace disque libre, le nombre d’extensions actives et d’utilisateurs, et relève les commandes en attente si les commandes à distance sont autorisées.', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Événements en temps réel', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_events_enabled" value="1"
									<?php checked( (bool) $settings['events_enabled'] ); ?>>
								<?php echo esc_html__( 'Prévient le manager dès qu’ils se produisent : connexions réussies ou échouées, extensions activées ou désactivées, mises à jour installées.', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Commandes à distance', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_remote_commands_enabled" value="1"
									<?php checked( (bool) $settings['remote_commands_enabled'] ); ?>>
								<?php echo esc_html__( 'Autorise le manager à lancer des opérations sur ce site : mises à jour, restaurations d’extensions, vidage du cache, nettoyage de la base de données. Celles qu’il met en file sont relevées avec le signe de vie horaire.', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'IPv4 vers le manager', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_force_ipv4_to_manager" value="1"
									<?php checked( (bool) ( $settings['force_ipv4_to_manager'] ?? false ) ); ?>>
								<?php echo esc_html__( 'À activer si l’hébergeur bloque l’IPv6 sortante du serveur : signes de vie, événements et enrôlement passent alors par l’IPv4. Ne concerne que les appels vers le manager.', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Connexion directe depuis G2RD', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_allow_direct_login" value="1"
									<?php checked( (bool) ( $settings['allow_direct_login'] ?? true ) ); ?>>
								<?php echo esc_html__( 'Autoriser la connexion directe depuis G2RD', 'g2rd-connector' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'G2RD WP Manager peut ouvrir l\'administration de ce site sans mot de passe, avec le compte administrateur choisi dans la fiche du site (lien à usage unique valable une minute, réservé aux comptes protégés par double authentification). Décochée : le manager ouvre la page de connexion habituelle.', 'g2rd-connector' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="g2rd_connector_action" value="save" class="button button-primary">
						<?php echo esc_html__( 'Enregistrer', 'g2rd-connector' ); ?>
					</button>
					<?php if ( ! $enrolled ) : ?>
					<button type="submit" name="g2rd_connector_action" value="enroll" class="button button-secondary">
						<?php echo esc_html__( 'Enrôler le site', 'g2rd-connector' ); ?>
					</button>
					<?php else : ?>
					<button type="submit" name="g2rd_connector_action" value="unenroll" class="button button-link-delete">
						<?php echo esc_html__( 'Déconnecter du manager', 'g2rd-connector' ); ?>
					</button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private function maybe_handle_form(): void {
		if ( empty( $_POST['g2rd_connector_action'] ) ) {
			return;
		}
		check_admin_referer( 'g2rd_connector_save', 'g2rd_connector_nonce' );

		$action = sanitize_key( wp_unslash( (string) $_POST['g2rd_connector_action'] ) );
		$url    = isset( $_POST['g2rd_manager_url'] )
			? esc_url_raw( wp_unslash( (string) $_POST['g2rd_manager_url'] ) )
			: '';

		Settings::update(
			[
				'manager_url'             => $url,
				'heartbeat_enabled'       => ! empty( $_POST['g2rd_heartbeat_enabled'] ),
				'events_enabled'          => ! empty( $_POST['g2rd_events_enabled'] ),
				'remote_commands_enabled' => ! empty( $_POST['g2rd_remote_commands_enabled'] ),
				'force_ipv4_to_manager'   => ! empty( $_POST['g2rd_force_ipv4_to_manager'] ),
				'allow_direct_login'      => ! empty( $_POST['g2rd_allow_direct_login'] ),
			]
		);

		if ( 'enroll' === $action ) {
			$token  = isset( $_POST['g2rd_invitation_token'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['g2rd_invitation_token'] ) )
				: '';
			$result = ( new ManagerClient() )->enroll( $token, $url );
			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'g2rd_connector',
					'enroll_failed',
					esc_html( $result->get_error_message() ),
					'error'
				);
			} else {
				// Enrollment réussi → on planifie le cron heartbeat (opt-in consenti).
				\G2RD\Connector\Cron\HeartbeatJob::schedule();
				add_settings_error(
					'g2rd_connector',
					'enrolled',
					esc_html__( 'Site enrôlé avec succès.', 'g2rd-connector' ),
					'success'
				);
			}
		}

		if ( 'unenroll' === $action ) {
			Settings::update(
				[
					'site_id'     => null,
					'site_token'  => '',
					'enrolled_at' => null,
				]
			);
			\G2RD\Connector\Cron\HeartbeatJob::unschedule();
			add_settings_error(
				'g2rd_connector',
				'unenrolled',
				esc_html__( 'Site déconnecté du manager.', 'g2rd-connector' ),
				'success'
			);
		}

		settings_errors( 'g2rd_connector' );
	}

	/**
	 * Payload boot injecté à l'app React. Délègue à BootData pour partager la
	 * source de vérité avec Rest\AdminController (réponses save/enroll/unenroll).
	 *
	 * @return array<string, mixed>
	 */
	private function initial_data(): array {
		return \G2RD\Connector\BootData::build();
	}

	/**
	 * Script de l'onglet, sur la seule page de la console du thème G2RD (cf.
	 * is_theme_console_page) : c'est lui qui appelle window.G2RDOptions.registerTab.
	 */
	public function enqueue_react_tab( string $hook ): void {
		if ( ! self::is_theme_console_page( $hook ) ) {
			return;
		}
		$this->do_enqueue( true );
	}

	/**
	 * La page d'administration courante (suffixe de hook) est-elle la console
	 * d'options du thème G2RD, celle qui affiche les onglets d'extension ?
	 *
	 *  - Thèmes 1.19 à 1.40 : Apparence → Options G2RD, « appearance_page_g2rd-options ».
	 *  - Thèmes 1.41 et plus : menu de premier niveau « G2RD », slug
	 *    \G2RD\AdminConsole::MENU_SLUG (« g2rd »), donc « toplevel_page_g2rd ».
	 *    L'ancienne adresse (?page=g2rd-options) y est redirigée en 301 : le
	 *    premier hook n'y est jamais atteint.
	 *
	 * Les sous-pages du menu « G2RD » (« g2rd_page_… ») n'affichent pas les
	 * onglets d'extension : le script n'y est pas chargé.
	 */
	public static function is_theme_console_page( string $hook ): bool {
		// Slug lu dans le thème quand il le publie (1.41 et plus), sans dépendre
		// de sa classe au chargement ; « g2rd » sinon (sa valeur depuis la 1.41).
		$slug = defined( 'G2RD\\AdminConsole::MENU_SLUG' )
			? (string) constant( 'G2RD\\AdminConsole::MENU_SLUG' )
			: 'g2rd';
		return in_array( $hook, [ 'appearance_page_g2rd-options', 'toplevel_page_' . $slug ], true );
	}

	public function enqueue_standalone_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		$this->do_enqueue();
	}

	/**
	 * Assets de la page de secours, sur le hook que WordPress lui a donné
	 * (« admin_page_g2rd-connector ») : sa feuille porte le correctif des cases
	 * cochées du formulaire.
	 */
	public function enqueue_fallback_assets( string $hook ): void {
		if ( '' === $this->fallback_hook || $this->fallback_hook !== $hook ) {
			return;
		}
		$this->do_enqueue();
	}

	/**
	 * Assets du connecteur, depuis assets/admin/build.
	 *
	 * @param bool $for_tab Onglet de la console du thème : seul le panneau React y
	 *                      affiche les réglages, son absence y est donc signalée.
	 */
	private function do_enqueue( bool $for_tab = false ): void {
		$this->enqueue_build(
			G2RD_CONNECTOR_DIR . 'assets/admin/build/',
			G2RD_CONNECTOR_URL . 'assets/admin/build/',
			$for_tab
		);
	}

	/**
	 * Charge le panneau React et sa feuille.
	 *
	 * Le panneau compilé lit des scripts de WordPress (index.asset.php), dont
	 * react-jsx-runtime, enregistré seulement à partir de WordPress 6.6 alors que
	 * le connecteur accepte la 6.4 : s'il en manque un, le script n'est pas chargé
	 * (il planterait, ou WordPress l'écarterait sans rien dire). La feuille, elle,
	 * reste chargée : elle porte le correctif des cases cochées du formulaire PHP
	 * (page autonome et page de secours, qui n'ont pas besoin du script). Dans
	 * l'onglet de la console du thème, un avis dit pourquoi l'onglet reste vide.
	 *
	 * Publique pour les tests (dossier de build fictif).
	 *
	 * @param string $dir     Dossier du build, terminé par « / ».
	 * @param string $url     Adresse du même dossier, terminée par « / ».
	 * @param bool   $for_tab Onglet de la console du thème.
	 */
	public function enqueue_build( string $dir, string $url, bool $for_tab ): void {
		$js_path  = $dir . 'index.js';
		$css_path = $dir . 'style-index.css';

		if ( file_exists( $js_path ) ) {
			$dependencies          = self::script_dependencies( $dir . 'index.asset.php' );
			$this->missing_scripts = self::missing_scripts( $dependencies );
			if ( [] === $this->missing_scripts ) {
				wp_enqueue_script(
					'g2rd-connector-admin',
					$url . 'index.js',
					$dependencies,
					(string) filemtime( $js_path ),
					true
				);
				wp_localize_script( 'g2rd-connector-admin', 'G2RDConnectorData', $this->initial_data() );
			} elseif ( $for_tab ) {
				add_action( 'admin_notices', [ $this, 'render_missing_scripts_notice' ] );
			}
		}
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'g2rd-connector-admin',
				$url . 'style-index.css',
				[ 'wp-components' ],
				(string) filemtime( $css_path )
			);
			// wp-scripts emet aussi style-index-rtl.css : WordPress le
			// substitue automatiquement dans une locale de droite a gauche.
			wp_style_add_data( 'g2rd-connector-admin', 'rtl', 'replace' );
		}
	}

	/**
	 * Dépendances du script : la liste fixe, plus celles du fichier écrit par
	 * wp-scripts au build (react-jsx-runtime, notamment), sans doublon. Fichier
	 * absent ou illisible : la liste fixe seule.
	 *
	 * @param string $asset_file Chemin de index.asset.php.
	 * @return list<string>
	 */
	public static function script_dependencies( string $asset_file ): array {
		$dependencies = self::SCRIPT_DEPENDENCIES;
		$asset        = is_readable( $asset_file ) ? require $asset_file : null;
		if ( is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
			foreach ( $asset['dependencies'] as $handle ) {
				if ( is_string( $handle ) && '' !== $handle ) {
					$dependencies[] = $handle;
				}
			}
		}
		return array_values( array_unique( $dependencies ) );
	}

	/**
	 * Dépendances qui ne sont pas enregistrées sur ce site.
	 *
	 * @param list<string> $dependencies Noms des scripts.
	 * @return list<string>
	 */
	public static function missing_scripts( array $dependencies ): array {
		return array_values(
			array_filter(
				$dependencies,
				static fn ( string $handle ): bool => ! wp_script_is( $handle, 'registered' )
			)
		);
	}

	/**
	 * Avis de la console du thème quand le panneau n'a pas pu être chargé (cf.
	 * enqueue_build) : quoi, cause, action, et la page de réglages PHP en attendant.
	 */
	public function render_missing_scripts_notice(): void {
		if ( [] === $this->missing_scripts || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$version = (string) get_bloginfo( 'version' );
		if ( version_compare( $version, '6.6', '<' ) ) {
			$cause = sprintf(
				/* translators: %s: version de WordPress installée. */
				__( 'Ce panneau demande WordPress 6.6 ou plus récent (version installée : %s) : mettez WordPress à jour.', 'g2rd-connector' ),
				$version
			);
		} else {
			$cause = sprintf(
				/* translators: %s: noms des scripts de WordPress absents. */
				__( 'Des scripts de WordPress dont il a besoin ne sont pas enregistrés (%s). Cause probable : une extension ou le thème les a retirés. Désactivez une à une les extensions récemment ajoutées pour trouver la cause ; si rien ne change, contactez le support G2RD.', 'g2rd-connector' ),
				implode( ', ', $this->missing_scripts )
			);
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'L’onglet « Manager G2RD » ne peut pas s’afficher.', 'g2rd-connector' ); ?></strong>
				<?php echo esc_html( $cause ); ?>
				<?php if ( '' !== $this->fallback_hook ) : ?>
					<?php echo esc_html__( 'En attendant, réglez le connecteur depuis', 'g2rd-connector' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'sa page de réglages', 'g2rd-connector' ); ?></a>.
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
