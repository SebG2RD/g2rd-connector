<?php
/**
 * POST /wp-json/g2rd/v1/command — exécute une commande envoyée par le manager.
 *
 * Auth Bearer SiteToken obligatoire. Commandes supportées :
 *   - clear_cache : purge wp_cache + transients d'update
 *   - check_updates : force la vérif des updates core/plugins/themes
 *   - update_core : déclenche update WP core (best effort, peut échouer)
 *
 * Logique d'exécution déléguée à CommandExecutor (partagée avec Cron\HeartbeatJob
 * qui drain la queue manager via GET /api/agent/sites/{id}/commands).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\Commands\CommandExecutor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CommandController {

	public function register(): void {
		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			'/command',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ Auth::class, 'require_site_token' ],
				'args'                => [
					'command' => [
						'required'          => true,
						'type'              => 'string',
						'enum'              => CommandExecutor::ALLOWED,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && in_array( $value, CommandExecutor::ALLOWED, true );
						},
					],
				],
			]
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$command = (string) $request->get_param( 'command' );

		$outcome = CommandExecutor::run( $command );
		if ( null === $outcome ) {
			return new WP_Error(
				'g2rd_connector_unknown_command',
				/* translators: %s: nom de la commande rejetée. */
				sprintf( __( 'Commande inconnue : %s', 'g2rd-connector' ), $command ),
				[ 'status' => 400 ]
			);
		}

		return new WP_REST_Response(
			[
				'command' => $command,
				'status'  => $outcome['status'],
				'result'  => $outcome['result'] ?? null,
				'error'   => $outcome['error'] ?? null,
				'at'      => gmdate( 'c' ),
			],
			'done' === $outcome['status'] ? 200 : 500
		);
	}
}
