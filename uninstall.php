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

// Suppression de l'option principale (URL manager, token site, toggles), et du
// compteur des connexions échouées de la minute (cf. Events\LoginFailedThrottle :
// il expire seul en 120 s, mais sans cache objet sa ligne resterait jusqu'au
// ménage quotidien de WordPress). Puis le marqueur de la répartition des tâches
// WP-Cron (cf. Cron\Phase), propre à chaque blog comme les tâches elles-mêmes, et la
// santé de la base gardée 24 h par l'inventaire (cf. Rest\DbHealth), propre à chaque
// blog comme son inventaire.
delete_option( 'g2rd_connector_settings' );
delete_transient( 'g2rd_connector_login_failed_window' );
delete_option( 'g2rd_connector_cron_phase' );
delete_option( 'g2rd_connector_db_health' );

// Si multisite, suppression sur chaque blog. 'number' => 0 : tous les blogs
// (get_sites() n'en rend que 100 par défaut ; au-delà, les réglages, dont le jeton
// chiffré du site, resteraient sur les blogs suivants).
if ( is_multisite() ) {
	$blog_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		delete_option( 'g2rd_connector_settings' );
		delete_transient( 'g2rd_connector_login_failed_window' );
		delete_option( 'g2rd_connector_cron_phase' );
		delete_option( 'g2rd_connector_db_health' );
		restore_current_blog();
	}
}

// Capture des MAJ tierces (option, pas transient : elle doit survivre au
// wp_cache_flush() de la commande clear_cache sur les sites à cache objet persistant).
delete_site_option( 'g2rd_updates_snapshot' );

// Heure des dernières réponses de wordpress.org aux vérifications des mises à jour
// (cf. Updates\WporgAnswers) : option de réseau, comme les transients de mise à jour.
delete_site_option( 'g2rd_connector_wporg_answers' );

// Volumes mesurés chaque jour (cf. Environment\SizeReport) : option de réseau, une
// seule ligne même en multisite (wp-content et la base sont communs au réseau).
delete_site_option( 'g2rd_connector_environment' );

// Registre des nonces de signature + compteur d'échecs (cf. Security\SignatureState).
delete_option( 'g2rd_connector_signature_state' );

// Trace des restaurations wordpress.org non vérifiées (cf. Rollback\UnverifiedDownloads).
delete_option( 'g2rd_connector_unverified_downloads' );

// Copie de secours du jeton au format v1, prise à sa migration au format v2 (cf. Settings).
delete_option( 'g2rd_connector_site_token_v1' );

// Points de restauration (cf. Rollback\RestorePointStore) : on supprime UNIQUEMENT
// les zips indexés par le plugin et ses fichiers de protection, puis le dossier s'il
// est vide. Un fichier étranger déposé dans ce dossier n'est jamais touché.
// WordPress inclut uninstall.php SANS charger le plugin : les constantes du fichier
// principal n'existent pas encore, l'autoloader en a besoin.
if ( ! defined( 'G2RD_CONNECTOR_DIR' ) ) {
	define( 'G2RD_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
}
require_once __DIR__ . '/includes/autoload.php';
if ( class_exists( \G2RD\Connector\Rollback\RestorePointStore::class ) ) {
	$g2rd_store = new \G2RD\Connector\Rollback\RestorePointStore();
	foreach ( array_keys( $g2rd_store->all() ) as $g2rd_point_id ) {
		$g2rd_store->remove( (string) $g2rd_point_id );
	}
	$g2rd_dir = $g2rd_store->dir();
	if ( is_dir( $g2rd_dir ) ) {
		foreach ( [ 'index.php', '.htaccess', 'web.config' ] as $g2rd_guard ) {
			if ( file_exists( $g2rd_dir . '/' . $g2rd_guard ) ) {
				wp_delete_file( $g2rd_dir . '/' . $g2rd_guard );
			}
		}
		$g2rd_left = array_diff( (array) scandir( $g2rd_dir ), [ '.', '..' ] );
		if ( [] === $g2rd_left ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par le plugin, vide.
			rmdir( $g2rd_dir );
		}
	}
	unset( $g2rd_store, $g2rd_point_id, $g2rd_dir, $g2rd_guard, $g2rd_left );
}
// `g2rd_update_txn_recovery` : trace d'une reprise sur une transaction impossible à
// retirer (cf. Rollback\UpdateTransaction::RECOVERY_TRACE_KEY).
foreach ( [ 'g2rd_restore_points', 'g2rd_update_txn', 'g2rd_update_txn_recovery', 'g2rd_pending_outcomes', 'g2rd_blocked_versions' ] as $g2rd_option ) {
	delete_option( $g2rd_option );
}
unset( $g2rd_option );

// Tickets de connexion directe consommés (cf. DirectLogin\UsedTickets) : options
// éphémères créées par le plugin, toutes retirées — lot après lot, et sur chaque
// blog en multisite (chaque blog a sa propre table d'options).
if ( class_exists( \G2RD\Connector\DirectLogin\UsedTickets::class ) ) {
	\G2RD\Connector\DirectLogin\UsedTickets::purge_all();
	if ( is_multisite() ) {
		// 'number' => 0 : tous les blogs (get_sites() n'en rend que 100 par défaut).
		$g2rd_blog_ids = get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);
		foreach ( $g2rd_blog_ids as $g2rd_blog_id ) {
			switch_to_blog( (int) $g2rd_blog_id );
			\G2RD\Connector\DirectLogin\UsedTickets::purge_all();
			restore_current_blog();
		}
		unset( $g2rd_blog_ids, $g2rd_blog_id );
	}
}

// Dé-planification des crons si encore présents (dont un contrôle de reprise
// ponctuel d'une mise à jour protégée, cf. Cron\RestorePointPurgeJob::RECOVERY_HOOK).
foreach ( [ 'g2rd_connector_heartbeat', 'g2rd_connector_refresh_updates', 'g2rd_connector_restore_points_purge', 'g2rd_connector_update_recovery_check' ] as $g2rd_hook ) {
    $timestamp = wp_next_scheduled( $g2rd_hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $g2rd_hook );
    }
    wp_clear_scheduled_hook( $g2rd_hook );
}
