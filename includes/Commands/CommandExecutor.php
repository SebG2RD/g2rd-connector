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
			throw new \RuntimeException( 'plugin not installed: ' . $file );
		}

		$version_before = isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

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
			throw new \RuntimeException( $result->get_error_message() );
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
		];
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
			throw new \RuntimeException( 'theme not installed: ' . $stylesheet );
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
			throw new \RuntimeException( $result->get_error_message() );
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
