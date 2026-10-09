<?php
/**
 * Client HTTP pour appeler le manager Symfony (https://wp-manager.g2rd.fr).
 *
 * Usages :
 *   - enrollment one-shot (POST /api/sites/enroll)
 *   - heartbeat horaire (POST /api/sites/{id}/heartbeat)
 *   - push event (POST /api/sites/{id}/events)
 *
 * Authentification : Bearer SiteToken stocké dans Settings (sauf enrollment
 * qui présente un invitation_token signé reçu hors-bande par l'utilisateur).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Outbound;

use G2RD\Connector\Settings;
use WP_Error;

final class ManagerClient {

	private const TIMEOUT = 15;

	/**
	 * Délai (secondes) d'un envoi non bloquant (`'blocking' => false`).
	 *
	 * Avec le transport cURL de WordPress, un envoi non bloquant attend tout de même
	 * que la requête soit partie, jusqu'à ce délai ; il ne lit ni n'interprète la
	 * réponse. Le délai doit donc couvrir DNS, connexion et TLS jusqu'à la plateforme
	 * (via le CDN de l'hébergeur), sans quoi l'événement serait perdu : 2 s, en
	 * secondes entières (un délai inférieur à la seconde est mal tenu par certains
	 * résolveurs DNS de cURL), contre 15 s pour un envoi bloquant.
	 */
	public const NON_BLOCKING_TIMEOUT = 2;

	/**
	 * Enregistre ce site auprès du manager en présentant un invitation_token
	 * collé par l'utilisateur dans la page admin du plugin.
	 *
	 * Le manager répond avec { site_id, site_token } qu'on persiste.
	 *
	 * @param string $invitation_token Token reçu depuis le manager (UI invitation site).
	 * @param string $manager_url      URL base du manager (sans trailing slash).
	 *
	 * @return array{site_id:int,site_token:string}|WP_Error
	 */
	public function enroll( string $invitation_token, string $manager_url ): array|WP_Error {
		$payload = [
			'invitation_token'  => $invitation_token,
			'site_url'          => home_url( '/' ),
			'site_name'         => (string) get_bloginfo( 'name' ),
			'admin_email'       => (string) get_bloginfo( 'admin_email' ),
			'wp_version'        => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'connector_version' => G2RD_CONNECTOR_VERSION,
		];

		$resp = wp_remote_post(
			rtrim( $manager_url, '/' ) . '/api/sites/enroll',
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( 200 !== $code && 201 !== $code ) {
			return new WP_Error(
				'g2rd_connector_enroll_failed',
				sprintf( 'Enrollment refusé (HTTP %d) : %s', $code, is_array( $body ) ? ( $body['message'] ?? '' ) : '' ),
				[ 'status' => $code ]
			);
		}

		if ( ! is_array( $body ) || empty( $body['site_id'] ) || empty( $body['site_token'] ) ) {
			return new WP_Error( 'g2rd_connector_enroll_invalid_response', 'Réponse manager invalide.' );
		}

		Settings::update(
			[
				'manager_url' => rtrim( $manager_url, '/' ),
				'site_id'     => (int) $body['site_id'],
				'site_token'  => (string) $body['site_token'],
				'enrolled_at' => gmdate( 'c' ),
			]
		);

		return [
			'site_id'    => (int) $body['site_id'],
			'site_token' => (string) $body['site_token'],
		];
	}

	/**
	 * Heartbeat horaire : signale au manager que le site est vivant + envoie métriques légères.
	 *
	 * Type natif `bool` et non `true` : le type `true` n'existe qu'en PHP 8.2, et le
	 * plugin tourne dès PHP 8.1 (le charger y serait une erreur fatale).
	 *
	 * @return true|WP_Error
	 */
	public function heartbeat(): bool|WP_Error {
		if ( ! Settings::is_enrolled() ) {
			return new WP_Error( 'g2rd_connector_not_enrolled', 'Site non enrôlé.' );
		}

		$disk_free = function_exists( 'disk_free_space' ) ? disk_free_space( ABSPATH ) : false;

		$payload = [
			'wp_version'        => (string) get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'connector_version' => G2RD_CONNECTOR_VERSION,
			'disk_free_bytes'   => false !== $disk_free ? (int) $disk_free : null,
			'active_plugins'    => count( (array) get_option( 'active_plugins', [] ) ),
			'users_count'       => (int) count_users()['total_users'],
			'at'                => gmdate( 'c' ),
		];

		$resp = $this->post( '/heartbeat', $payload );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		Settings::update( [ 'last_heartbeat_at' => gmdate( 'c' ) ] );
		return true;
	}

	/**
	 * Push d'un event temps réel (login, plugin install, update fail, etc.).
	 *
	 * `$blocking = false` : la requête part avec un délai court
	 * (NON_BLOCKING_TIMEOUT) et sa réponse n'est pas lue — un refus de la plateforme
	 * n'est alors pas remonté. Même adresse, mêmes en-têtes, même corps qu'un envoi
	 * bloquant. Réservé aux événements qu'on ne veut jamais voir retenir une page
	 * WordPress (connexions échouées, cf. Events\Listener).
	 *
	 * @param array<string, mixed> $context
	 * @param bool                 $blocking Attendre et contrôler la réponse (par défaut, comme avant).
	 * @return true|WP_Error Type natif `bool` : `true` n'existe qu'en PHP 8.2.
	 */
	public function send_event( string $type, array $context = [], bool $blocking = true ): bool|WP_Error {
		if ( ! Settings::is_enrolled() ) {
			return new WP_Error( 'g2rd_connector_not_enrolled', 'Site non enrôlé.' );
		}
		if ( ! Settings::get( 'events_enabled' ) ) {
			return true;
		}

		$payload = [
			'type'    => $type,
			'context' => $context,
			'at'      => gmdate( 'c' ),
		];

		$resp = $this->post( '/events', $payload, $blocking );
		return is_wp_error( $resp ) ? $resp : true;
	}

	/**
	 * Polling des commandes en attente côté manager.
	 * Le manager claim atomiquement (PENDING → RUNNING) les commandes retournées.
	 *
	 * Les entrées proviennent de json_decode (données externes non fiables) :
	 * chaque commande est validée par l'appelant (présence id/kind).
	 *
	 * @return list<array<string, mixed>>|WP_Error
	 */
	public function poll_commands(): array|WP_Error {
		if ( ! Settings::is_enrolled() ) {
			return new WP_Error( 'g2rd_connector_not_enrolled', 'Site non enrôlé.' );
		}
		if ( ! Settings::get( 'remote_commands_enabled' ) ) {
			return [];
		}

		$base    = (string) Settings::get( 'manager_url' );
		$site_id = (int) Settings::get( 'site_id' );
		$token   = Settings::site_token();
		$url     = sprintf( '%s/api/agent/sites/%d/commands', rtrim( $base, '/' ), $site_id );

		$resp = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
			]
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 ) {
			return new WP_Error( 'g2rd_connector_manager_error', sprintf( 'HTTP %d', $code ) );
		}

		$body     = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$commands = is_array( $body ) && isset( $body['commands'] ) ? $body['commands'] : [];
		return is_array( $commands ) ? $commands : [];
	}

	/**
	 * Notifie le manager du résultat d'une commande exécutée.
	 *
	 * @param array<string, mixed>|null $result_payload
	 */
	public function send_command_result( int $command_id, string $status, ?array $result_payload = null, ?string $error_message = null ): bool|WP_Error {
		if ( ! Settings::is_enrolled() ) {
			return new WP_Error( 'g2rd_connector_not_enrolled', 'Site non enrôlé.' );
		}

		$body = [ 'status' => $status ];
		if ( null !== $result_payload ) {
			$body['result'] = $result_payload;
		}
		if ( null !== $error_message ) {
			$body['error'] = $error_message;
		}

		$resp = $this->post( '/commands/' . $command_id . '/result', $body );
		return is_wp_error( $resp ) ? $resp : true;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param bool                 $blocking Faux : délai court, réponse ni attendue ni lue (cf. send_event).
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( string $relative_path, array $payload, bool $blocking = true ): array|WP_Error {
		$base    = (string) Settings::get( 'manager_url' );
		$site_id = (int) Settings::get( 'site_id' );
		$token   = Settings::site_token();
		$url     = sprintf( '%s/api/agent/sites/%d%s', rtrim( $base, '/' ), $site_id, $relative_path );

		$args = [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			],
			'body'    => wp_json_encode( $payload ),
		];
		if ( ! $blocking ) {
			$args['timeout']  = self::NON_BLOCKING_TIMEOUT;
			$args['blocking'] = false;
		}

		$resp = wp_remote_post( $url, $args );

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		if ( ! $blocking ) {
			// Pas de réponse à lire : WordPress renvoie un code vide.
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 ) {
			return new WP_Error(
				'g2rd_connector_manager_error',
				sprintf( 'Manager a renvoyé HTTP %d', $code ),
				[
					'status' => $code,
					'body'   => (string) wp_remote_retrieve_body( $resp ),
				]
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $body ) ? $body : [];
	}
}
