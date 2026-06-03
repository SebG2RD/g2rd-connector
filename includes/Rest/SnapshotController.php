<?php
/**
 * GET /wp-json/g2rd/v1/snapshot — inventaire complet du site WP.
 *
 * Consommé par le SyncService du manager. Remplace le mock côté Symfony.
 * Renvoie : WP core (version + update?), liste plugins (installed/latest/active),
 * liste thèmes, version PHP, info serveur.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\Settings;
use WP_REST_Response;
use WP_REST_Server;

final class SnapshotController {

    public function register(): void {
        register_rest_route(
            G2RD_CONNECTOR_REST_NS,
            '/snapshot',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'handle' ],
                'permission_callback' => [ Auth::class, 'require_site_token' ],
            ]
        );
    }

    public function handle(): WP_REST_Response {
        Settings::update( [ 'last_heartbeat_at' => gmdate( 'c' ) ] );

        return new WP_REST_Response( [
            'site'    => $this->site_info(),
            'wp_core' => $this->wp_core(),
            'plugins' => $this->plugins(),
            'themes'  => $this->themes(),
            'server'  => $this->server_info(),
        ], 200 );
    }

    /**
     * @return array<string, mixed>
     */
    private function site_info(): array {
        return [
            'name'              => (string) get_bloginfo( 'name' ),
            'url'               => (string) home_url( '/' ),
            'admin_email'       => (string) get_bloginfo( 'admin_email' ),
            'language'          => (string) get_bloginfo( 'language' ),
            'timezone'          => (string) wp_timezone_string(),
            'multisite'         => is_multisite(),
            'connector_version' => G2RD_CONNECTOR_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wp_core(): array {
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $current = get_bloginfo( 'version' );
        $updates = function_exists( 'get_core_updates' ) ? get_core_updates() : [];
        $latest  = $current;
        $has_update = false;

        if ( is_array( $updates ) && isset( $updates[0]->current ) ) {
            $latest = (string) $updates[0]->current;
            $has_update = isset( $updates[0]->response ) && 'upgrade' === $updates[0]->response;
        }

        return [
            'version_installed' => $current,
            'version_latest'    => $latest,
            'has_update'        => $has_update,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plugins(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        $all     = get_plugins();
        $active  = (array) get_option( 'active_plugins', [] );
        $updates = get_site_transient( 'update_plugins' );
        $updates_response = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

        $out = [];
        foreach ( $all as $file => $data ) {
            $latest = $data['Version'] ?? '';
            $has_update = false;
            if ( isset( $updates_response[ $file ]->new_version ) ) {
                $latest     = (string) $updates_response[ $file ]->new_version;
                $has_update = true;
            }
            $slug = isset( $updates_response[ $file ]->slug )
                ? (string) $updates_response[ $file ]->slug
                : ( strpos( $file, '/' ) !== false ? dirname( $file ) : null );

            $out[] = [
                'file'              => $file,
                'slug'              => $slug,
                'name'              => (string) ( $data['Name'] ?? $file ),
                'version_installed' => (string) ( $data['Version'] ?? '' ),
                'version_latest'    => $latest,
                'is_active'         => in_array( $file, $active, true ),
                'has_update'        => $has_update,
                'plugin_uri'        => (string) ( $data['PluginURI'] ?? '' ),
                'author'            => (string) ( $data['Author'] ?? '' ),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function themes(): array {
        $all       = wp_get_themes();
        $current   = wp_get_theme();
        $updates   = get_site_transient( 'update_themes' );
        $resp      = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

        $out = [];
        foreach ( $all as $stylesheet => $theme ) {
            $installed  = (string) $theme->get( 'Version' );
            $latest     = $installed;
            $has_update = false;
            if ( isset( $resp[ $stylesheet ]['new_version'] ) ) {
                $latest     = (string) $resp[ $stylesheet ]['new_version'];
                $has_update = true;
            }

            $out[] = [
                'stylesheet'        => (string) $stylesheet,
                'name'              => (string) $theme->get( 'Name' ),
                'version_installed' => $installed,
                'version_latest'    => $latest,
                'is_active'         => $stylesheet === $current->get_stylesheet(),
                'has_update'        => $has_update,
                'theme_uri'         => (string) $theme->get( 'ThemeURI' ),
                'author'            => (string) $theme->get( 'Author' ),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function server_info(): array {
        global $wp_version;
        return [
            'php_version'      => PHP_VERSION,
            'wp_version'       => (string) $wp_version,
            'server_software'  => (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ),
            'mysql_version'    => $this->mysql_version(),
            'memory_limit'     => (string) ini_get( 'memory_limit' ),
            'max_execution'    => (int) ini_get( 'max_execution_time' ),
            'upload_max_size'  => (string) ini_get( 'upload_max_filesize' ),
        ];
    }

    private function mysql_version(): string {
        global $wpdb;
        if ( ! $wpdb ) {
            return '';
        }
        $version = $wpdb->get_var( 'SELECT VERSION()' );
        return is_string( $version ) ? $version : '';
    }
}
