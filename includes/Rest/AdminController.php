<?php
/**
 * Endpoints REST d'administration LOCALE du connecteur (namespace g2rd/v1/admin).
 *
 * Consommés par l'app React d'admin (onglet « Manager G2RD ») pour :
 *   - POST /admin/save     : persister URL du manager + toggles ;
 *   - POST /admin/enroll   : enrôler le site (URL + token d'invitation) ;
 *   - POST /admin/unenroll : déconnecter le site du manager.
 *
 * Auth : capability `manage_options` + nonce REST WordPress (X-WP-Nonce). Cette
 * garde est DISTINCTE du Bearer SiteToken (Rest\Auth) réservé aux appels entrants
 * du manager : ici c'est l'administrateur local déjà connecté qui agit. Même
 * posture que le formulaire PHP (check_admin_referer + current_user_can).
 *
 * Enregistré AVANT le gate d'enrôlement (cf. Plugin::boot) : il faut pouvoir
 * enrôler un site qui ne l'est pas encore. Aucune requête sortante n'est émise
 * tant que l'admin ne clique pas explicitement (conforme guideline « no phoning
 * home without consent »).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\BootData;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AdminController {

	private const CAPABILITY = 'manage_options';

	public function register(): void {
		$toggle_args = [
			'heartbeat_enabled'       => [
				'required' => false,
				'type'     => 'boolean',
			],
			'events_enabled'          => [
				'required' => false,
				'type'     => 'boolean',
			],
			'remote_commands_enabled' => [
				'required' => false,
				'type'     => 'boolean',
			],
		];

		$url_arg = [
			'manager_url' => [
				'required'          => true,
				'type'              => 'string',
				'format'            => 'uri',
				'sanitize_callback' => 'esc_url_raw',
			],
		];

		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			'/admin/save',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_save' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => array_merge( $url_arg, $toggle_args ),
			]
		);

		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			'/admin/enroll',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_enroll' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => array_merge(
					$url_arg,
					$toggle_args,
					[
						'invitation_token' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => static function ( $value ): bool {
								return is_string( $value ) && '' !== trim( $value );
							},
						],
					]
				),
			]
		);

		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			'/admin/unenroll',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_unenroll' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	/**
	 * Garde capability : `current_user_can` échoue aussi si le nonce REST est
	 * absent/invalide (l'utilisateur courant est alors anonyme) → 401/403.
	 */
	public function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	public function handle_save( WP_REST_Request $request ): WP_REST_Response {
		Settings::update( $this->collect_settings( $request ) );
		return new WP_REST_Response( BootData::build(), 200 );
	}

	public function handle_enroll( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$url = (string) $request->get_param( 'manager_url' );

		// Miroir du formulaire PHP : on persiste d'abord URL + toggles (même en
		// cas d'échec d'enrôlement ensuite), puis on tente l'enrôlement.
		Settings::update( $this->collect_settings( $request ) );

		$token  = (string) $request->get_param( 'invitation_token' );
		$result = ( new ManagerClient() )->enroll( $token, $url );

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			if ( $status < 400 ) {
				// Erreur réseau / réponse invalide : le manager n'a pas répondu
				// correctement → 502 Bad Gateway plutôt que 500 générique.
				$status = 502;
			}
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => $status ]
			);
		}

		// Enrôlement réussi → planification du cron heartbeat (opt-in consenti).
		HeartbeatJob::schedule();

		return new WP_REST_Response( BootData::build(), 200 );
	}

	public function handle_unenroll(): WP_REST_Response {
		Settings::update(
			[
				'site_id'     => null,
				'site_token'  => '',
				'enrolled_at' => null,
			]
		);
		HeartbeatJob::unschedule();

		return new WP_REST_Response( BootData::build(), 200 );
	}

	/**
	 * Extrait URL + toggles présents dans la requête. Les toggles absents ne sont
	 * PAS forcés à false : seules les clés réellement soumises sont mises à jour
	 * (l'app React les envoie toujours, mais on reste défensif).
	 *
	 * @return array<string, mixed>
	 */
	private function collect_settings( WP_REST_Request $request ): array {
		$settings = [ 'manager_url' => (string) $request->get_param( 'manager_url' ) ];

		foreach ( [ 'heartbeat_enabled', 'events_enabled', 'remote_commands_enabled' ] as $flag ) {
			$value = $request->get_param( $flag );
			if ( null !== $value ) {
				$settings[ $flag ] = (bool) $value;
			}
		}

		return $settings;
	}
}
