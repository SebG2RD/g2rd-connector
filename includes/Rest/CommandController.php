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
					// `payload` est optionnel et propre à chaque commande :
					//   - update_plugin : { file: 'akismet/akismet.php' }
					//   - update_theme  : { stylesheet: 'twentytwentyfour' }
					//   - autres        : ignoré
					// Validation détaillée déléguée au CommandExecutor (whitelist
					// défensive via get_plugins() / wp_get_themes()).
					'payload' => [
						'required' => false,
						'type'     => 'object',
					],
				],
			]
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$command = (string) $request->get_param( 'command' );
		$payload = $request->get_param( 'payload' );
		$payload = is_array( $payload ) ? $payload : null;

		$outcome = CommandExecutor::run( $command, $payload );
		if ( null === $outcome ) {
			return new WP_Error(
				'g2rd_connector_unknown_command',
				/* translators: %s: nom de la commande rejetée. */
				sprintf( __( 'Commande inconnue : %s', 'g2rd-connector' ), $command ),
				[ 'status' => 400 ]
			);
		}

		$body = [
			'command' => $command,
			'status'  => $outcome['status'],
			'result'  => $outcome['result'] ?? null,
			'error'   => $outcome['error'] ?? null,
			'at'      => gmdate( 'c' ),
		];

		// Refus à code stable (porte des mises à jour, sauvegarde) : code, délai et étape, en plus de
		// la phrase (contrat backup-refusal-v1.json). Absents pour tout autre échec : un appelant qui
		// les ignore garde exactement son comportement.
		$body += self::refusal_fields( $outcome );

		// Diagnostic : ce qu'un plugin tiers ou une notice PHP a écrit pendant la
		// commande. Capturé par CommandExecutor plutôt que laissé corrompre ce JSON.
		if ( isset( $outcome['stray_output'] ) && '' !== $outcome['stray_output'] ) {
			$body['stray_output'] = $outcome['stray_output'];
		}

		// Résultat de la vérification de signature (cf. Auth) : en politique `report`
		// un échec n'empêche pas la commande, il doit donc être visible du manager.
		$signature = Auth::last_signature_check();
		if ( null !== $signature ) {
			$body['signature_check'] = $signature;
		}

		return new WP_REST_Response(
			$body,
			'done' === $outcome['status'] ? 200 : 500
		);
	}

	/**
	 * Champs d'un refus à code stable recopiés dans la réponse de /command : `error_code`, puis
	 * `retry_after` (1 à 3 600) et `stage` (étape d'un autotest en échec) s'ils sont présents et bien
	 * formés ; rien pour tout autre échec. Publique pour les tests.
	 *
	 * @param array<string, mixed> $outcome Issue de CommandExecutor::run().
	 * @return array<string, int|string>
	 */
	public static function refusal_fields( array $outcome ): array {
		$code = $outcome['error_code'] ?? null;
		if ( ! is_string( $code ) || 1 !== preg_match( '/^[a-z_]{1,64}$/D', $code ) ) {
			return [];
		}
		$fields = [ 'error_code' => $code ];
		$retry  = $outcome['retry_after'] ?? null;
		if ( is_int( $retry ) && $retry >= 1 && $retry <= 3600 ) {
			$fields['retry_after'] = $retry;
		}
		$stage = $outcome['stage'] ?? null;
		if ( is_string( $stage ) && 1 === preg_match( '/^[a-z_]{1,32}$/D', $stage ) ) {
			$fields['stage'] = $stage;
		}

		return $fields;
	}
}
