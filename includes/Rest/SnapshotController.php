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

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';

		// Force un rafraîchissement TOTAL des caches WP avant lecture du snapshot.
		//
		// Deux niveaux de cache sont en jeu :
		//
		// 1. Cache d'objet `wp_get_themes()` / `get_plugins()` — lié au filesystem.
		//    Nettoyé par wp_clean_themes_cache / wp_clean_plugins_cache.
		//
		// 2. Transients `update_themes`, `update_plugins`, `update_core` — lectures
		//    des updates dispo. Crucial : wp_update_themes() / wp_update_plugins()
		//    / wp_version_check() utilisent un "minimum_period" (12h) qui les fait
		//    SKIP silencieusement si le transient a été touché récemment.
		//
		//    Conséquence : si un GitHub Updater tiers (ex : g2rd-theme via
		//    class-github-updater.php) a injecté son entrée puis qu'une nouvelle
		//    release est publiée, le transient reste figé jusqu'au prochain cron
		//    WP. Le manager reçoit alors un snapshot incorrect ("tout est à jour"
		//    alors qu'une release plus récente existe, ou inversement).
		//
		//    On supprime explicitement les transients pour forcer WordPress à
		//    re-dispatcher les hooks `pre_set_site_transient_update_*`, ce qui
		//    permet aux updaters custom de ré-évaluer leur état contre l'API
		//    distante (wp.org / GitHub). Coût : ~1-3s par sync, acceptable.
		// Invalide OPcache sur tous les fichiers `.php` racine des plugins
		// et thèmes installés AVANT wp_clean_plugins_cache(), pour garantir
		// que `get_plugins()` re-lise les headers `Version:` depuis le disque
		// et non depuis le bytecode mis en cache par OPcache.
		//
		// Constat : sur certains hébergeurs (Hostinger Cloud Pro, LiteSpeed,
		// PHP-FPM avec `opcache.validate_timestamps=0`), après une MAJ de
		// plugin via UPDATE NOW du WP-admin, le fichier sur disque est bien
		// remplacé, MAIS dans le contexte REST API qui sert /snapshot,
		// OPcache retourne encore le bytecode de l'ancienne version. Du coup
		// `get_plugins()['monplugin/monplugin.php']['Version']` renvoie
		// l'ancien header (ex: "1.5.1" alors que le site WP-admin affiche
		// déjà "1.6.1"). Le manager persiste donc des versions périmées et
		// continue à proposer une MAJ déjà faite.
		//
		// `opcache_invalidate($file, true)` force le rechargement du fichier
		// au prochain accès. Le second argument `true` invalide même si le
		// timestamp du fichier ne semble pas avoir changé (defensive).
		if ( function_exists( 'opcache_invalidate' ) ) {
			$plugins_dir = WP_PLUGIN_DIR;
			if ( is_dir( $plugins_dir ) ) {
				foreach ( (array) glob( $plugins_dir . '/*/*.php' ) as $php_file ) {
					opcache_invalidate( (string) $php_file, true );
				}
				// Single-file plugins dans wp-content/plugins/foo.php.
				foreach ( (array) glob( $plugins_dir . '/*.php' ) as $php_file ) {
					opcache_invalidate( (string) $php_file, true );
				}
			}
			$themes_dir = get_theme_root();
			if ( is_dir( $themes_dir ) ) {
				foreach ( (array) glob( $themes_dir . '/*/style.css' ) as $style_file ) {
					opcache_invalidate( (string) $style_file, true );
				}
				foreach ( (array) glob( $themes_dir . '/*/functions.php' ) as $fn_file ) {
					opcache_invalidate( (string) $fn_file, true );
				}
			}
		}

		// Purge agressive de l'object cache WP (Redis / Memcached / LiteSpeed
		// Object Cache / etc.) AVANT wp_clean_plugins_cache. Sur les
		// hebergeurs avec object cache persistent (typiquement Hostinger Cloud
		// Pro avec LiteSpeed Cache + Hostinger AI Assistant qui wrappent
		// wp_cache_*), les valeurs cachees survivent entre requetes REST. Du
		// coup `get_plugins()` peut renvoyer un cache stale contenant l'ancien
		// header `Version:` d'un plugin meme apres MAJ.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache();
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_version_check' ) ) {
			wp_version_check( [], true );
		}
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		return new WP_REST_Response(
			[
				'site'         => $this->site_info(),
				'wp_core'      => $this->wp_core(),
				'plugins'      => $this->plugins(),
				'themes'       => $this->themes(),
				'translations' => $this->translations(),
				'server'       => $this->server_info(),
			],
			200
		);
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

		$current    = get_bloginfo( 'version' );
		$updates    = function_exists( 'get_core_updates' ) ? get_core_updates() : [];
		$latest     = $current;
		$has_update = false;

		if ( is_array( $updates ) && isset( $updates[0]->current ) ) {
			$candidate          = (string) $updates[0]->current;
			$transient_says_yes = isset( $updates[0]->response ) && 'upgrade' === $updates[0]->response;
			// Sanity check : on ne signale une MAJ que si elle est strictement
			// superieure a la version installee. Defend contre les transients
			// obsoletes apres une MAJ manuelle (cf docs/plugin-wordpress.md).
			if ( $transient_says_yes && version_compare( $candidate, $current, '>' ) ) {
				$latest     = $candidate;
				$has_update = true;
			} else {
				$latest = $current;
			}
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

		$all              = get_plugins();
		$active           = (array) get_option( 'active_plugins', [] );
		$updates          = get_site_transient( 'update_plugins' );
		$updates_response = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

		$out = [];
		foreach ( $all as $file => $data ) {
			$installed  = (string) ( $data['Version'] ?? '' );
			$latest     = $installed;
			$has_update = false;
			if ( isset( $updates_response[ $file ]->new_version ) ) {
				$candidate = (string) $updates_response[ $file ]->new_version;
				// Sanity check version_compare : protege contre les transients
				// update_plugins obsoletes apres une MAJ manuelle.
				if ( '' !== $installed && version_compare( $candidate, $installed, '>' ) ) {
					$latest     = $candidate;
					$has_update = true;
				}
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
		$all     = wp_get_themes();
		$current = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$resp    = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

		$out = [];
		foreach ( $all as $stylesheet => $theme ) {
			$installed  = (string) $theme->get( 'Version' );
			$latest     = $installed;
			$has_update = false;
			if ( isset( $resp[ $stylesheet ]['new_version'] ) ) {
				$candidate = (string) $resp[ $stylesheet ]['new_version'];
				// Sanity check : on ne marque la MAJ comme dispo que si elle
				// est strictement plus recente que l'installee. Protege contre
				// les transients update_themes stale apres MAJ via updater custom.
				if ( '' !== $installed && version_compare( $candidate, $installed, '>' ) ) {
					$latest     = $candidate;
					$has_update = true;
				}
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
	 * Inventaire des traductions installées + MAJ disponibles.
	 *
	 * Lecture du transient `update_core` qui contient les translation updates
	 * détectés par wp_version_check([], true). Pour chaque entrée, on retourne
	 * le locale, le type (core/plugin/theme), l'identifiant (slug) et la version.
	 *
	 * @return array<string, mixed>
	 */
	private function translations(): array {
		$available_updates = function_exists( 'wp_get_translation_updates' ) ? wp_get_translation_updates() : [];

		$updates = [];
		foreach ( (array) $available_updates as $update ) {
			if ( ! is_object( $update ) ) {
				continue;
			}
			$updates[] = [
				'type'     => (string) ( $update->type ?? '' ),       // core | plugin | theme
				'slug'     => (string) ( $update->slug ?? '' ),       // ex: g2rd-theme, akismet, default (core)
				'language' => (string) ( $update->language ?? '' ),    // ex: fr_FR
				'version'  => (string) ( $update->version ?? '' ),     // version cible
				'updated'  => (string) ( $update->updated ?? '' ),     // ISO8601
			];
		}

		$installed_languages = function_exists( 'get_available_languages' ) ? get_available_languages() : [];

		return [
			'installed_languages' => array_values( (array) $installed_languages ),
			'site_language'       => (string) get_locale(),
			'updates_available'   => $updates,
			'updates_count'       => count( $updates ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function server_info(): array {
		global $wp_version;

		// SERVER_SOFTWARE est une entree superglobale : deslash + assainissement.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) )
			: '';

		return [
			'php_version'     => PHP_VERSION,
			'wp_version'      => (string) $wp_version,
			'server_software' => $server_software,
			'mysql_version'   => $this->mysql_version(),
			'memory_limit'    => (string) ini_get( 'memory_limit' ),
			'max_execution'   => (int) ini_get( 'max_execution_time' ),
			'upload_max_size' => (string) ini_get( 'upload_max_filesize' ),
		];
	}

	private function mysql_version(): string {
		global $wpdb;
		if ( ! $wpdb ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lecture triviale de la version MySQL pour le snapshot, sans cache pertinent.
		$version = $wpdb->get_var( 'SELECT VERSION()' );
		return is_string( $version ) ? $version : '';
	}
}
