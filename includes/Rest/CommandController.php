<?php
/**
 * POST /wp-json/g2rd/v1/command — exécute une commande envoyée par le manager.
 *
 * Auth Bearer SiteToken obligatoire. Commandes supportées :
 *   - clear_cache : purge wp_cache + transients d'update
 *   - check_updates : force la vérif des updates core/plugins/themes
 *   - update_core : déclenche update WP core (best effort, peut échouer)
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CommandController {

    private const ALLOWED_COMMANDS = [ 'clear_cache', 'check_updates', 'update_core' ];

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
                        'enum'              => self::ALLOWED_COMMANDS,
                        'sanitize_callback' => 'sanitize_key',
                        'validate_callback' => static function ( $value ): bool {
                            return is_string( $value ) && in_array( $value, self::ALLOWED_COMMANDS, true );
                        },
                    ],
                ],
            ]
        );
    }

    public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $command = (string) $request->get_param( 'command' );

        if ( ! in_array( $command, self::ALLOWED_COMMANDS, true ) ) {
            return new WP_Error(
                'g2rd_connector_unknown_command',
                sprintf( __( 'Commande inconnue : %s', 'g2rd-connector' ), $command ),
                [ 'status' => 400 ]
            );
        }

        $result = match ( $command ) {
            'clear_cache'   => $this->clear_cache(),
            'check_updates' => $this->check_updates(),
            'update_core'   => $this->update_core(),
        };

        return new WP_REST_Response( [
            'command' => $command,
            'result'  => $result,
            'at'      => gmdate( 'c' ),
        ], 200 );
    }

    /**
     * @return array<string, mixed>
     */
    private function clear_cache(): array {
        wp_cache_flush();
        delete_site_transient( 'update_core' );
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'update_themes' );
        return [ 'cleared' => [ 'object_cache', 'update_transients' ] ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check_updates(): array {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
        return [ 'refreshed' => true ];
    }

    /**
     * @return array<string, mixed>
     */
    private function update_core(): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $updates = get_core_updates();
        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return [ 'updated' => false, 'reason' => 'no_updates_available' ];
        }

        $upgrader = new \Core_Upgrader();
        $result   = $upgrader->upgrade( $updates[0] );

        return [
            'updated' => ! is_wp_error( $result ),
            'detail'  => is_wp_error( $result ) ? $result->get_error_message() : (string) $result,
        ];
    }
}
