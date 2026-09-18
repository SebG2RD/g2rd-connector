<?php
/**
 * Cible de la sonde d'administration du contrôle de santé :
 * `admin-ajax.php?action=g2rd_health&token=…`.
 *
 * Pourquoi admin-ajax et pas /wp-admin/ : sans cookie, /wp-admin/ redirige vers
 * wp-login.php, qui ne charge pas le contexte d'administration — une erreur fatale
 * propre à l'admin passerait inaperçue. admin-ajax.php charge ce contexte.
 *
 * Le jeton à usage unique (transient posé par HealthChecker juste avant la sonde)
 * prouve que la réponse vient du site, et non d'un cache ou d'une page de garde.
 * Sans jeton valide, la réponse est un 404 muet : rien n'est révélé.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class HealthEndpoint {

	public function register(): void {
		add_action( 'wp_ajax_nopriv_g2rd_health', [ $this, 'respond' ] );
		add_action( 'wp_ajax_g2rd_health', [ $this, 'respond' ] );
	}

	public function respond(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jeton à usage unique dédié, comparé en temps constant ci-dessous.
		$given    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
		$expected = (string) get_transient( HealthChecker::TOKEN_TRANSIENT );

		if ( '' === $expected || '' === $given || ! hash_equals( $expected, $given ) ) {
			wp_send_json( [ 'ok' => false ], 404 );
		}

		nocache_headers();
		wp_send_json(
			[
				'ok'    => true,
				'token' => $given,
			]
		);
	}
}
