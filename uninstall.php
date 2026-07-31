<?php
/**
 * Désinstallation de G2RD Connector.
 *
 * Appelé par WordPress quand le plugin est supprimé via Plugins → Delete.
 * Supprime toutes les options + dé-planifie le cron horaire.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Suppression de l'option principale (URL manager, token site, toggles).
delete_option( 'g2rd_connector_settings' );

// Si multisite, suppression sur chaque blog.
if ( is_multisite() ) {
    $blog_ids = get_sites( [ 'fields' => 'ids' ] );
    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( (int) $blog_id );
        delete_option( 'g2rd_connector_settings' );
        restore_current_blog();
    }
}

// Capture des MAJ tierces (option, pas transient : elle doit survivre au
// wp_cache_flush() du snapshot sur les sites à cache objet persistant).
delete_site_option( 'g2rd_updates_snapshot' );

// Dé-planification des crons si encore présents.
foreach ( [ 'g2rd_connector_heartbeat', 'g2rd_connector_refresh_updates' ] as $g2rd_hook ) {
    $timestamp = wp_next_scheduled( $g2rd_hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $g2rd_hook );
    }
    wp_clear_scheduled_hook( $g2rd_hook );
}
