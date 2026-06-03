<?php
/**
 * Helper d'authentification Bearer SiteToken pour les endpoints REST.
 *
 * Le manager présente le token long-lived (généré côté Symfony) dans
 * l'en-tête Authorization. On compare en hash_equals contre l'option stockée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\Settings;
use WP_Error;
use WP_REST_Request;

final class Auth {

	/**
	 * Permission callback à brancher sur tous les endpoints sécurisés.
	 *
	 * @return true|WP_Error
	 */
	public static function require_site_token( WP_REST_Request $request ): bool|WP_Error {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header || stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error(
				'g2rd_connector_missing_token',
				__( 'Authorization Bearer token requis.', 'g2rd-connector' ),
				[ 'status' => 401 ]
			);
		}

		$token = trim( substr( $header, 7 ) );
		if ( ! Settings::token_matches( $token ) ) {
			return new WP_Error(
				'g2rd_connector_invalid_token',
				__( 'Token site invalide.', 'g2rd-connector' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}
}
