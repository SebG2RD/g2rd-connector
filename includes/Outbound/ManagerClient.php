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

        $resp = wp_remote_post( rtrim( $manager_url, '/' ) . '/api/sites/enroll', [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

        if ( $code !== 200 && $code !== 201 ) {
            return new WP_Error(
                'g2rd_connector_enroll_failed',
                sprintf( 'Enrollment refusé (HTTP %d) : %s', $code, is_array( $body ) ? ( $body['message'] ?? '' ) : '' ),
                [ 'status' => $code ]
            );
        }

        if ( ! is_array( $body ) || empty( $body['site_id'] ) || empty( $body['site_token'] ) ) {
            return new WP_Error( 'g2rd_connector_enroll_invalid_response', 'Réponse manager invalide.' );
        }

        Settings::update( [
            'manager_url' => rtrim( $manager_url, '/' ),
            'site_id'     => (int) $body['site_id'],
            'site_token'  => (string) $body['site_token'],
            'enrolled_at' => gmdate( 'c' ),
        ] );

        return [
            'site_id'    => (int) $body['site_id'],
            'site_token' => (string) $body['site_token'],
        ];
    }

    /**
     * Heartbeat horaire : signale au manager que le site est vivant + envoie métriques légères.
     */
    public function heartbeat(): true|WP_Error {
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
     * @param array<string, mixed> $context
     */
    public function send_event( string $type, array $context = [] ): true|WP_Error {
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

        $resp = $this->post( '/events', $payload );
        return is_wp_error( $resp ) ? $resp : true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|WP_Error
     */
    private function post( string $relative_path, array $payload ): array|WP_Error {
        $base   = (string) Settings::get( 'manager_url' );
        $siteId = (int) Settings::get( 'site_id' );
        $token  = (string) Settings::get( 'site_token' );
        $url    = sprintf( '%s/api/sites/%d%s', rtrim( $base, '/' ), $siteId, $relative_path );

        $resp = wp_remote_post( $url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code >= 400 ) {
            return new WP_Error(
                'g2rd_connector_manager_error',
                sprintf( 'Manager a renvoyé HTTP %d', $code ),
                [ 'status' => $code, 'body' => (string) wp_remote_retrieve_body( $resp ) ]
            );
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        return is_array( $body ) ? $body : [];
    }
}
