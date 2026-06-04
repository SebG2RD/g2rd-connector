<?php
/**
 * Page d'administration du plugin G2RD Connector.
 *
 * Comportement adaptatif :
 *  - Si le thème G2RD est actif ET expose le filtre `g2rd_options_external_tabs`
 *    (≥ v1.19) → on s'enregistre comme tab dans la page Options G2RD.
 *  - Sinon → menu top-level "G2RD Connector" avec dashicon dédié.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Admin;

use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;

final class Page {

	public const MENU_SLUG   = 'g2rd-connector';
	private const CAPABILITY = 'manage_options';

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		if ( $this->theme_supports_external_tabs() ) {
			add_filter( 'g2rd_options_external_tabs', [ $this, 'register_as_theme_tab' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_react_tab' ] );
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_top_level_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_standalone_assets' ] );
	}

	/**
	 * Détecte si le thème actif est g2rd-theme ET en version ≥ 1.19 (filtre dispo).
	 */
	private function theme_supports_external_tabs(): bool {
		$theme   = wp_get_theme();
		$is_g2rd = in_array( $theme->get_template(), [ 'g2rd-theme', 'g2rd' ], true )
			|| in_array( $theme->get_stylesheet(), [ 'g2rd-theme', 'g2rd' ], true );
		if ( ! $is_g2rd ) {
			return false;
		}
		$version = (string) $theme->get( 'Version' );
		// Le filtre est introduit par le patch theme à partir de 1.19.0.
		return version_compare( $version, '1.19.0', '>=' );
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
	 * @param array<int, array<string, mixed>> $tabs
	 * @return array<int, array<string, mixed>>
	 */
	public function register_as_theme_tab( array $tabs ): array {
		$tabs[] = [
			'key'         => 'connector',
			'label'       => __( 'Manager G2RD', 'g2rd-connector' ),
			'description' => __( 'Connexion au tableau de bord centralisé.', 'g2rd-connector' ),
			'icon'        => 'dashicons-cloud',
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

	public function render_standalone_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$this->maybe_handle_form();
		$settings = Settings::all();
		$enrolled = Settings::is_enrolled();
		?>
		<div class="wrap g2rd-connector-wrap">
			<h1><?php echo esc_html__( 'G2RD Connector', 'g2rd-connector' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Lie ce site WordPress au tableau de bord centralisé G2RD WP Manager.', 'g2rd-connector' ); ?>
			</p>

			<?php if ( $enrolled ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php echo esc_html__( 'Site enrôlé.', 'g2rd-connector' ); ?></strong>
						<?php
						printf(
							/* translators: %1$s URL du manager, %2$d ID du site */
							esc_html__( 'Connecté à %1$s — site ID #%2$d.', 'g2rd-connector' ),
							esc_html( (string) $settings['manager_url'] ),
							(int) $settings['site_id']
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'g2rd_connector_save', 'g2rd_connector_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="g2rd_manager_url"><?php echo esc_html__( 'URL du manager', 'g2rd-connector' ); ?></label></th>
						<td>
							<input type="url" name="g2rd_manager_url" id="g2rd_manager_url"
								value="<?php echo esc_attr( (string) $settings['manager_url'] ); ?>"
								class="regular-text" required>
							<p class="description"><?php echo esc_html__( 'Ex : https://wp-manager.g2rd.fr', 'g2rd-connector' ); ?></p>
						</td>
					</tr>
					<?php if ( ! $enrolled ) : ?>
					<tr>
						<th scope="row"><label for="g2rd_invitation_token"><?php echo esc_html__( 'Token d\'invitation', 'g2rd-connector' ); ?></label></th>
						<td>
							<input type="text" name="g2rd_invitation_token" id="g2rd_invitation_token"
								class="regular-text" autocomplete="off">
							<p class="description"><?php echo esc_html__( 'Généré côté manager dans la page Sites → Inviter un site.', 'g2rd-connector' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Heartbeat horaire', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_heartbeat_enabled" value="1"
									<?php checked( (bool) $settings['heartbeat_enabled'] ); ?>>
								<?php echo esc_html__( 'Envoyer les métriques au manager toutes les heures', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Webhook events', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_events_enabled" value="1"
									<?php checked( (bool) $settings['events_enabled'] ); ?>>
								<?php echo esc_html__( 'Notifier le manager en temps réel (login, plugin install, update)', 'g2rd-connector' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Commandes distantes', 'g2rd-connector' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="g2rd_remote_commands_enabled" value="1"
									<?php checked( (bool) $settings['remote_commands_enabled'] ); ?>>
								<?php echo esc_html__( 'Autoriser le manager à déclencher cache:clear / update à distance', 'g2rd-connector' ); ?>
							</label>
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
	 * @return array<string, mixed>
	 */
	private function initial_data(): array {
		$s = Settings::all();
		return [
			'managerUrl'            => $s['manager_url'],
			'enrolled'              => Settings::is_enrolled(),
			'siteId'                => $s['site_id'],
			'enrolledAt'            => $s['enrolled_at'],
			'lastHeartbeatAt'       => $s['last_heartbeat_at'],
			'heartbeatEnabled'      => (bool) $s['heartbeat_enabled'],
			'eventsEnabled'         => (bool) $s['events_enabled'],
			'remoteCommandsEnabled' => (bool) $s['remote_commands_enabled'],
			'restUrl'               => rest_url( G2RD_CONNECTOR_REST_NS . '/' ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'connectorVersion'      => G2RD_CONNECTOR_VERSION,
		];
	}

	public function enqueue_react_tab( string $hook ): void {
		// Ne s'enregistre que sur la page Options G2RD du thème (hook = "appearance_page_g2rd-options")
		if ( 'appearance_page_g2rd-options' !== $hook ) {
			return;
		}
		$this->do_enqueue();
	}

	public function enqueue_standalone_assets( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		$this->do_enqueue();
	}

	private function do_enqueue(): void {
		$js_path  = G2RD_CONNECTOR_DIR . 'assets/admin/build/index.js';
		$css_path = G2RD_CONNECTOR_DIR . 'assets/admin/build/index.css';

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'g2rd-connector-admin',
				G2RD_CONNECTOR_URL . 'assets/admin/build/index.js',
				[ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'react', 'react-dom' ],
				(string) filemtime( $js_path ),
				true
			);
			wp_localize_script( 'g2rd-connector-admin', 'G2RDConnectorData', $this->initial_data() );
		}
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'g2rd-connector-admin',
				G2RD_CONNECTOR_URL . 'assets/admin/build/index.css',
				[ 'wp-components' ],
				(string) filemtime( $css_path )
			);
		}
	}
}
