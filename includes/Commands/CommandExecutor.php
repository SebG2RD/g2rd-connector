<?php
/**
 * Exécution locale des commandes G2RD côté WordPress.
 *
 * Utilisé par deux entry points :
 *   - Rest\CommandController : POST direct /wp-json/g2rd/v1/command (manager pousse)
 *   - Cron\HeartbeatJob : drain de la queue manager via GET /api/agent/sites/{id}/commands
 *
 * Sépare la logique d'exécution du transport REST pour pouvoir la tester et
 * la réutiliser. Liste des commandes : cf SiteCommandKind côté manager
 * (clear_cache, check_updates, update_core, delete_spam_comments,
 * delete_post_revisions, optimize_database).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Commands;

use Automatic_Upgrader_Skin;
use Core_Upgrader;
use Language_Pack_Upgrader;
use Plugin_Upgrader;
use Theme_Upgrader;

final class CommandExecutor {

	public const ALLOWED = [
		'clear_cache',
		'check_updates',
		'update_core',
		'delete_spam_comments',
		'delete_post_revisions',
		'optimize_database',
		'update_plugin',
		'update_theme',
		'update_translations',
	];

	/**
	 * Exécute une commande. Retourne :
	 *   - [ 'status' => 'done', 'result' => array<string, mixed> ]
	 *   - [ 'status' => 'failed', 'error' => string ]
	 *   - null si la commande est inconnue.
	 *
	 * Le `$payload` est optionnel pour les commandes legacy (clear_cache,
	 * update_core, etc.) ; il est requis pour update_plugin (clé `file`) et
	 * update_theme (clé `stylesheet`).
	 *
	 * @param array<string, mixed>|null $payload
	 * @return array{status:string,result?:array<string,mixed>,error?:string}|null
	 */
	public static function run( string $command, ?array $payload = null ): ?array {
		if ( ! in_array( $command, self::ALLOWED, true ) ) {
			return null;
		}

		try {
			$result = match ( $command ) {
				'clear_cache'           => self::clear_cache(),
				'check_updates'         => self::check_updates(),
				'update_core'           => self::update_core(),
				'delete_spam_comments'  => self::delete_spam_comments(),
				'delete_post_revisions' => self::delete_post_revisions(),
				'optimize_database'     => self::optimize_database(),
				'update_plugin'         => self::update_plugin( (array) ( $payload ?? [] ) ),
				'update_theme'          => self::update_theme( (array) ( $payload ?? [] ) ),
				'update_translations'   => self::update_translations(),
			};
			return [
				'status' => 'done',
				'result' => $result,
			];
		} catch ( \Throwable $e ) {
			return [
				'status' => 'failed',
				'error'  => $e->getMessage(),
			];
		}
	}

	/**
	 * Met à jour un plugin spécifique vers la dernière version disponible.
	 *
	 * Whitelist défensive via get_plugins() pour interdire toute injection de
	 * chemin arbitraire (ex: ../../wp-config.php). Seuls les plugins déjà
	 * installés peuvent être ciblés. L'install d'un plugin nouveau passe par
	 * un autre flow (hors-scope V1).
	 *
	 * @param array<string, mixed> $payload Doit contenir la clé `file` (ex: "akismet/akismet.php").
	 * @return array<string, mixed>
	 */
	private static function update_plugin( array $payload ): array {
		$file = isset( $payload['file'] ) && is_string( $payload['file'] ) ? $payload['file'] : '';
		if ( '' === $file ) {
			throw new \RuntimeException( 'payload.file required' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			throw new \RuntimeException( esc_html( 'plugin not installed: ' . $file ) );
		}

		$version_before = isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';

		// État d'activation AVANT l'upgrade : Plugin_Upgrader::upgrade() désactive
		// silencieusement un plugin actif (hook deactivate_plugin_before_upgrade du
		// coeur) et ne le RÉACTIVE PAS dans ce contexte REST/cron. On mémorise donc
		// l'état pour le rétablir après l'upgrade — un plugin actif doit le rester.
		$was_active         = is_plugin_active( $file );
		$was_network_active = is_multisite() && is_plugin_active_for_network( $file );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// Filet de sécurité « réactivation quoi qu'il arrive ». Cas critique : le
		// connecteur se met à jour LUI-MÊME. Si la requête meurt (erreur fatale ou
		// dépassement de max_execution_time) ENTRE la désactivation silencieuse du
		// coeur et la réactivation nominale plus bas — ou si l'upgrade renvoie une
		// WP_Error qui jette avant cette réactivation — le plugin resterait éteint.
		// Et, étant éteint, plus aucun cron ne tournerait pour le rétablir : le site
		// deviendrait injoignable par le manager, irrécupérable à distance.
		// register_shutdown_function s'exécute AUSSI sur arrêt fatal / timeout, ce
		// qui garantit la réactivation dans tous ces cas. force_reactivate() est
		// idempotent : si la réactivation nominale a déjà réussi, ce filet ne fait
		// rien. On ne l'arme que si le plugin était actif (on ne réactive jamais un
		// plugin que l'admin avait volontairement laissé éteint).
		if ( $was_active ) {
			register_shutdown_function( [ self::class, 'force_reactivate' ], $file, $was_network_active );
		}

		// Force le refresh du transient update_plugins avant l'upgrade pour
		// garantir que Plugin_Upgrader voit la dernière release dispo.
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		// Automatic_Upgrader_Skin = skin sans output, adapté à un contexte REST/cron.
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $file );

		if ( is_wp_error( $result ) ) {
			// Le filet shutdown armé plus haut réactivera le plugin (le coeur l'a
			// peut-être déjà désactivé via deactivate_plugin_before_upgrade avant
			// d'échouer) — on peut donc jeter sans laisser le site éteint.
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}

		// Réactivation nominale (synchrone) : si le plugin était actif, on le
		// rétablit immédiatement. force_reactivate gère le mode silencieux et un
		// repli défensif ; le filet shutdown reste armé en cas d'échec du code aval.
		$reactivated = $was_active;
		if ( $was_active ) {
			$reactivated = self::force_reactivate( $file, $was_network_active );
		}

		// Re-lire la version installée après upgrade.
		wp_clean_plugins_cache();
		$plugins_after = get_plugins();
		$version_after = isset( $plugins_after[ $file ]['Version'] ) ? (string) $plugins_after[ $file ]['Version'] : $version_before;

		return [
			'updated'        => true === $result,
			'file'           => $file,
			'version_before' => $version_before,
			'version_after'  => $version_after,
			'was_active'     => $was_active,
			'reactivated'    => $reactivated,
		];
	}

	/**
	 * Réactive un plugin de façon idempotente et défensive — garantit l'état
	 * « actif » quoi qu'il arrive après un upgrade (cf. update_plugin()).
	 *
	 * Publique + statique pour être passable à register_shutdown_function() : ce
	 * filet doit fonctionner même appelé en fin de cycle PHP (arrêt fatal /
	 * timeout), quand le contexte admin a pu être partiellement déchargé — d'où le
	 * require_once défensif de plugin.php.
	 *
	 * Stratégie en deux temps :
	 *   1. Voie standard : activate_plugin() en mode silencieux ($silent = true),
	 *      symétrique de la désactivation silencieuse du coeur — ne relance pas les
	 *      hooks d'activation, le plugin ayant déjà été activé auparavant.
	 *   2. Dernier recours : si activate_plugin() échoue (ex. validation transitoire
	 *      juste après le remplacement des fichiers du plugin lors d'un self-update),
	 *      on écrit DIRECTEMENT l'option active_plugins / active_sitewide_plugins.
	 *      Si la nouvelle version était réellement cassée, le mode recovery natif de
	 *      WordPress (>= 5.2) la re-désactivera et préviendra l'admin — on ne risque
	 *      donc pas de bloquer durablement le site.
	 *
	 * @return bool true si le plugin est actif en sortie.
	 */
	public static function force_reactivate( string $file, bool $network_wide ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $file ) ) {
			return true;
		}

		// 1) Voie standard : activate_plugin silencieux. Un retour non-WP_Error
		// signifie que le plugin a bien été (re)basculé actif dans l'option.
		$activated = activate_plugin( $file, '', $network_wide, true );
		if ( ! is_wp_error( $activated ) ) {
			return true;
		}

		// 2) Dernier recours : forcer l'option, en court-circuitant les validations
		// d'activate_plugin() qui peuvent échouer transitoirement post-swap. On lit
		// l'état directement dans l'option qu'on vient d'écrire (et non via un nouvel
		// is_plugin_active(), volontairement, pour rester self-contained).
		if ( is_multisite() && $network_wide ) {
			$active = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( ! isset( $active[ $file ] ) ) {
				$active[ $file ] = time();
				update_site_option( 'active_sitewide_plugins', $active );
			}
			return isset( $active[ $file ] );
		}

		$active = (array) get_option( 'active_plugins', [] );
		if ( ! in_array( $file, $active, true ) ) {
			$active[] = $file;
			sort( $active );
			update_option( 'active_plugins', $active );
		}
		return in_array( $file, $active, true );
	}

	/**
	 * Met à jour un thème spécifique vers la dernière version disponible.
	 *
	 * Whitelist défensive via wp_get_themes(). Idem update_plugin pour le reste
	 * du fonctionnement.
	 *
	 * @param array<string, mixed> $payload Doit contenir la clé `stylesheet` (ex: "twentytwentyfour").
	 * @return array<string, mixed>
	 */
	private static function update_theme( array $payload ): array {
		$stylesheet = isset( $payload['stylesheet'] ) && is_string( $payload['stylesheet'] ) ? $payload['stylesheet'] : '';
		if ( '' === $stylesheet ) {
			throw new \RuntimeException( 'payload.stylesheet required' );
		}

		$themes = wp_get_themes();
		if ( ! isset( $themes[ $stylesheet ] ) ) {
			throw new \RuntimeException( esc_html( 'theme not installed: ' . $stylesheet ) );
		}

		$version_before = (string) $themes[ $stylesheet ]->get( 'Version' );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// Force refresh transient update_themes pour ré-évaluer les GitHub
		// Updaters tiers (cf SnapshotController::handle()).
		delete_site_transient( 'update_themes' );
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}

		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $stylesheet );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}

		// Re-lire la version installée après upgrade.
		wp_clean_themes_cache();
		$themes_after  = wp_get_themes();
		$version_after = isset( $themes_after[ $stylesheet ] ) ? (string) $themes_after[ $stylesheet ]->get( 'Version' ) : $version_before;

		return [
			'updated'        => true === $result,
			'stylesheet'     => $stylesheet,
			'version_before' => $version_before,
			'version_after'  => $version_after,
		];
	}

	/**
	 * Met à jour les packs de traduction (cœur, plugins, thèmes) vers les
	 * dernières versions disponibles sur translate.wordpress.org.
	 *
	 * Rafraîchit d'abord les transients de MAJ (qui portent aussi les packs de
	 * langue disponibles), puis lance Language_Pack_Upgrader::bulk_upgrade() sur
	 * l'ensemble détecté par wp_get_translation_updates(). Mode silencieux
	 * (Automatic_Upgrader_Skin), adapté au contexte REST/cron.
	 *
	 * @return array<string, mixed>
	 */
	private static function update_translations(): array {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// Rafraîchit les transients : wp_get_translation_updates() lit les packs
		// de langue annoncés par update_core / update_plugins / update_themes.
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();

		$updates = wp_get_translation_updates();
		if ( empty( $updates ) ) {
			return [
				'updated'       => false,
				'total'         => 0,
				'updated_count' => 0,
				'failed_count'  => 0,
				'reason'        => 'no_updates_available',
			];
		}

		$total    = count( $updates );
		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$results  = $upgrader->bulk_upgrade( $updates );

		if ( is_wp_error( $results ) ) {
			throw new \RuntimeException( esc_html( $results->get_error_message() ) );
		}

		// false global = échec d'init du système de fichiers (droits/credentials).
		if ( false === $results ) {
			return [
				'updated'       => false,
				'total'         => $total,
				'updated_count' => 0,
				'failed_count'  => $total,
			];
		}

		// Sinon : un résultat par pack (chemin de destination si succès,
		// false/WP_Error sinon).
		$updated_count = 0;
		$failed_count  = 0;
		foreach ( (array) $results as $result ) {
			if ( $result && ! is_wp_error( $result ) ) {
				++$updated_count;
			} else {
				++$failed_count;
			}
		}

		return [
			'updated'       => $updated_count > 0,
			'total'         => $total,
			'updated_count' => $updated_count,
			'failed_count'  => $failed_count,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function clear_cache(): array {
		wp_cache_flush();
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		return [ 'cleared' => [ 'object_cache', 'update_transients' ] ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_updates(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
		return [ 'refreshed' => true ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function update_core(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$updates = get_core_updates();
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return [
				'updated' => false,
				'reason'  => 'no_updates_available',
			];
		}

		$upgrader = new Core_Upgrader();
		$result   = $upgrader->upgrade( $updates[0] );

		return [
			'updated' => ! is_wp_error( $result ),
			'detail'  => is_wp_error( $result ) ? $result->get_error_message() : (string) $result,
		];
	}

	/**
	 * Supprime tous les commentaires marqués spam.
	 *
	 * Récupère les IDs via WP_Comment_Query (status='spam'), boucle avec
	 * wp_delete_comment(force_delete=true) pour passer la corbeille et
	 * supprimer en dur. Plus rapide qu'un SQL direct car ça déclenche les
	 * hooks WP (Akismet stats, etc.) et garantit l'intégrité des relations
	 * (commentmeta, etc.).
	 *
	 * @return array<string, mixed>
	 */
	private static function delete_spam_comments(): array {
		$comment_ids = get_comments(
			[
				'status' => 'spam',
				'fields' => 'ids',
				'number' => 0,
			]
		);

		$deleted = 0;
		foreach ( (array) $comment_ids as $comment_id ) {
			if ( wp_delete_comment( (int) $comment_id, true ) ) {
				++$deleted;
			}
		}

		return [
			'deleted_count' => $deleted,
			'total_spam'    => count( (array) $comment_ids ),
		];
	}

	/**
	 * Supprime toutes les revisions de posts (DB cleanup).
	 *
	 * Récupère via WP_Query post_type='revision', boucle avec
	 * wp_delete_post_revision() qui supprime en dur (pas de corbeille pour
	 * les revisions). Ne touche PAS aux brouillons / pages / posts publiés.
	 *
	 * @return array<string, mixed>
	 */
	private static function delete_post_revisions(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lecture des revisions pour une commande de maintenance, sans cache pertinent.
		$revision_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		$deleted = 0;
		foreach ( (array) $revision_ids as $revision_id ) {
			if ( wp_delete_post_revision( (int) $revision_id ) ) {
				++$deleted;
			}
		}

		return [
			'deleted_count'   => $deleted,
			'total_revisions' => count( (array) $revision_ids ),
		];
	}

	/**
	 * Optimise la base de données :
	 *   - OPTIMIZE TABLE sur toutes les tables avec préfixe WP (réduit la
	 *     fragmentation et libère l'espace disque sur InnoDB/MyISAM)
	 *   - Suppression des transients expirés (options _transient_timeout_*
	 *     dans le passé + leurs _transient_* associés)
	 *   - Suppression des transients orphelins (timeout sans valeur ou vice-versa)
	 *
	 * @return array<string, mixed>
	 */
	private static function optimize_database(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- liste des tables a prefixe WP pour OPTIMIZE, prefixe sur.
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );

		$optimized = 0;
		foreach ( (array) $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- OPTIMIZE TABLE de maintenance, nom de table echappe via esc_sql.
			$result = $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( (string) $table ) . '`' );
			if ( false !== $result ) {
				++$optimized;
			}
		}

		// Purge des transients expirés. Les transients WP sont stockés en
		// 2 options : _transient_X (valeur) + _transient_timeout_X (expiration).
		$now              = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- nettoyage des transients expires (requete preparee), sans cache pertinent.
		$expired_timeouts = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s AND option_value < %d",
				'_transient_timeout_%',
				$now
			)
		);

		$transients_deleted = 0;
		foreach ( $expired_timeouts as $timeout_option ) {
			$transient_key = preg_replace( '/^_transient_timeout_/', '', (string) $timeout_option );
			if ( '' === $transient_key ) {
				continue;
			}
			if ( delete_transient( $transient_key ) ) {
				++$transients_deleted;
			}
			// Suppression défensive du timeout au cas où delete_transient
			// n'a pas trouvé la value associée (orphelin).
			delete_option( '_transient_timeout_' . $transient_key );
		}

		return [
			'tables_optimized'   => $optimized,
			'tables_total'       => count( (array) $tables ),
			'transients_deleted' => $transients_deleted,
		];
	}
}
