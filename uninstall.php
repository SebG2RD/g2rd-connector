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

// Dé-planification du cron heartbeat si encore présent.
$timestamp = wp_next_scheduled( 'g2rd_connector_heartbeat' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'g2rd_connector_heartbeat' );
}
wp_clear_scheduled_hook( 'g2rd_connector_heartbeat' );
