<?php
/**
 * GET /wp-json/g2rd/v1/health — endpoint léger pour ping/probe.
 *
 * Non authentifié : sert juste à vérifier que le plugin est chargé
 * (utile pour les tests de connectivité depuis l'admin du manager).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use WP_REST_Response;
use WP_REST_Server;

final class HealthController {

	public function register(): void {
		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			'/health',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle(): WP_REST_Response {
		// Réponse volontairement minimale : cet endpoint est NON authentifié
		// (__return_true). On ne divulgue donc PAS wp_version / php_version /
		// statut d'enrollment à un appelant anonyme (évite le fingerprinting de
		// versions vulnérables et la reconnaissance des sites managés). Le manager
		// n'a besoin que de confirmer que le connecteur répond + sa version.
		return new WP_REST_Response(
			[
				'ok'                => true,
				'connector_version' => G2RD_CONNECTOR_VERSION,
			],
			200
		);
	}
}
