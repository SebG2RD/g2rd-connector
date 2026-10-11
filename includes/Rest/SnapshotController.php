<?php
/**
 * GET /wp-json/g2rd/v1/snapshot — inventaire complet du site WP.
 *
 * Consommé par le SyncService du manager. Remplace le mock côté Symfony.
 * Renvoie : WP core (version + update?), liste plugins (installed/latest/active),
 * liste thèmes, version PHP, info serveur.
 *
 * Léger depuis la 1.14 (serveur mutualisé partagé par tout le parc) : invalidation
 * ciblée des caches relus au lieu de wp_cache_flush(), appels à wordpress.org
 * seulement pour un transient de mise à jour périmé (plus de 3 h) ou absent, santé
 * de la base calculée au plus une fois par jour, OPcache invalidé pour le seul
 * connecteur. Les données renvoyées restent celles que WordPress tient pour vraies.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\Cron\UpdatesDiscoveryJob;
use G2RD\Connector\DirectLogin\SnapshotFields;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Rollback\RestorePointInventory;
use G2RD\Connector\Rollback\Services;
use G2RD\Connector\Settings;
use G2RD\Connector\Updates\PremiumUpdatesBridge;
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

		$now = time();

		// OPcache : seulement les fichiers du connecteur (cf. opcache_files()).
		if ( function_exists( 'opcache_invalidate' ) ) {
			foreach ( self::opcache_files() as $file ) {
				opcache_invalidate( $file, true );
			}
		}

		// Ce que l'inventaire relit, et seulement cela (plus de wp_cache_flush()).
		self::forget_stale_caches();

		// Transients de mise à jour : rafraîchis seulement s'ils sont périmés.
		self::refresh_update_checks( $now );

		// Les updaters tiers ne s'enregistrent pas ici : seul le contexte WP-Cron
		// sait les faire parler. Si sa dernière capture est périmée (plus de 13 h :
		// la découverte biquotidienne est en retard), on le réveille — WP-Cron ne
		// partant que sur une requête HTTP, un site client sans trafic resterait
		// muet. Non bloquant : la réponse ci-dessous n'attend pas.
		$discovery_stale = UpdatesDiscoveryJob::request_if_stale();

		return new WP_REST_Response(
			[
				'site'              => $this->site_info(),
				'wp_core'           => $this->wp_core(),
				'plugins'           => $this->plugins(),
				'themes'            => $this->themes(),
				'translations'      => $this->translations(),
				'server'            => $this->server_info(),
				// Calculé au plus une fois par jour ; `collected_at` dit de quand.
				'db_health'         => DbHealth::get( $now ),
				'updates_discovery' => $this->updates_discovery( $discovery_stale ),
				// Rollback des plugins : ce que le site sait faire, les points qu'il
				// détient, et ce qui s'est passé hors d'une réponse à la plateforme.
				'capabilities'      => $this->capabilities(),
				'restore_points'    => RestorePointInventory::describe(),
				'pending_outcomes'  => PendingOutcomes::all(),
			],
			200
		);
	}

	/**
	 * Fichiers dont l'OPcache est invalidé à chaque inventaire : ceux du connecteur, et
	 * eux seuls.
	 *
	 * Les versions des extensions et des thèmes n'en dépendent pas : elles sont lues
	 * par get_file_data() (file_get_contents() des 8 premiers Ko, wp-includes/functions.php),
	 * que l'OPcache ne sert jamais — il ne met en cache que le code compilé des
	 * fichiers inclus. Un `style.css` n'est d'ailleurs jamais compilé. Invalider les
	 * fichiers principaux de toutes les extensions et le functions.php de tous les
	 * thèmes (comme jusqu'à la 1.13) n'apportait rien à l'exactitude, et forçait leur
	 * recompilation à la requête suivante. Une mise à jour faite par WordPress invalide
	 * d'ailleurs elle-même chaque fichier copié (copy_dir() et move_dir(),
	 * wp-admin/includes/file.php).
	 *
	 * Reste le connecteur : sa version (`connector_version`) est une constante du
	 * fichier principal, donc du code compilé. Mis à jour hors de WordPress (WP-CLI,
	 * panneau de l'hébergeur, FTP) sur un serveur à `opcache.validate_timestamps=0`, il
	 * continuerait d'annoncer l'ancienne version. Tous ses fichiers ensemble : n'en
	 * invalider qu'un mêlerait du code neuf et ancien.
	 *
	 * Publique pour les tests.
	 *
	 * @return list<string>
	 */
	public static function opcache_files(): array {
		$files = [];
		if ( defined( 'G2RD_CONNECTOR_FILE' ) ) {
			$files[] = (string) G2RD_CONNECTOR_FILE;
		}
		if ( defined( 'G2RD_CONNECTOR_DIR' ) && is_dir( G2RD_CONNECTOR_DIR . 'includes' ) ) {
			$sources = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( G2RD_CONNECTOR_DIR . 'includes', \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $sources as $entry ) {
				if ( $entry instanceof \SplFileInfo && 'php' === $entry->getExtension() ) {
					$files[] = $entry->getPathname();
				}
			}
		}
		return $files;
	}

	/**
	 * Invalidation CIBLÉE de ce que l'inventaire relit, à la place de wp_cache_flush(),
	 * qui vidait TOUT le cache objet du site (chaque page suivante reconstruisait tout,
	 * sur un serveur partagé). Clés lues dans le cœur (WordPress 6.9.4) :
	 *
	 *  - `plugins` / `plugins` : cache de get_plugins() (wp_cache_get( 'plugins',
	 *    'plugins' ), wp-admin/includes/plugin.php), le seul que vide
	 *    wp_clean_plugins_cache( false ). Appelé directement : le cœur ne charge pas ce
	 *    fichier à ce stade d'une requête REST, et wp_clean_plugins_cache(), protégée
	 *    par function_exists(), n'était appelée que si une autre extension l'avait déjà
	 *    chargé. Groupe non persistant pour WordPress (wp_start_object_cache(),
	 *    wp-includes/load.php), mais pas pour tous les caches objet externes.
	 *  - cache des thèmes : wp_clean_themes_cache( false ) (wp-includes/theme.php,
	 *    toujours chargé) relance search_theme_directories( true ) et vide le cache de
	 *    chaque WP_Theme (groupe `themes`). `false` IMPÉRATIF : sans argument, elle
	 *    détruit `update_themes`, avant que le pont ait pu en mémoriser les entrées
	 *    tierces (et le jugerait absent, donc à rafraîchir).
	 *  - `alloptions` / `options` : options autochargées, dont `active_plugins`
	 *    (is_active des extensions) et `stylesheet` (thème actif, get_stylesheet()).
	 *    get_option() les lit dans ce tableau (wp_load_alloptions(), wp-includes/option.php),
	 *    rechargé en une requête à la lecture suivante. Seule entrée persistante du lot :
	 *    c'est elle qui peut diverger de la base (écritures concurrentes).
	 *
	 * Rien d'autre n'est relu depuis un cache : les versions installées viennent du
	 * disque (PremiumUpdatesBridge::installed_*_version()), les options du connecteur
	 * sont écrites par lui-même (update_option() tient le cache à jour), et les
	 * transients de mise à jour font référence là où ils sont rangés (base, ou cache
	 * objet persistant, groupe `site-transient`) : refresh_update_checks() les
	 * renouvelle s'ils sont périmés.
	 */
	private static function forget_stale_caches(): void {
		wp_cache_delete( 'plugins', 'plugins' );
		wp_cache_delete( 'alloptions', 'options' );
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}
	}

	/**
	 * Transients de mise à jour : rafraîchis seulement s'ils sont périmés ou absents
	 * (PremiumUpdatesBridge::UPDATE_CHECK_MAX_AGE, 3 h). Sinon leurs données sont
	 * vraies et suffisent : ni appel à wordpress.org, ni destruction.
	 *
	 * Le rafraîchissement forcé reste celui que la plateforme demande déjà : la commande
	 * check_updates (« Vérifier les MAJ »), toujours forcée, suivie d'une
	 * synchronisation qui lit alors des transients frais. Pas de paramètre de plus pour
	 * l'inventaire.
	 */
	private static function refresh_update_checks( int $now ): void {
		$core = get_site_transient( 'update_core' );
		if ( function_exists( 'wp_version_check' ) && ! self::core_check_is_current( $core, $now ) ) {
			// Le cœur vient toujours de wordpress.org. Pas de destruction préalable :
			// forcée, la vérification passe outre la fenêtre d'une minute de
			// wp_version_check(), et un échec réseau laisse la dernière réponse connue au
			// lieu d'un transient vide (« aucune mise à jour », faux).
			wp_version_check( [], true );
		}
		// Extensions et thèmes : chacun rafraîchi s'il est périmé, puis rejeu des
		// entrées des updaters tiers absents de ce contexte (cf. PremiumUpdatesBridge).
		PremiumUpdatesBridge::refresh_stale_update_transients( $now );
	}

	/**
	 * Vérification du cœur fraîche, faite pour la version installée et porteuse d'une
	 * réponse de wordpress.org (wp-includes/update.php) :
	 *  - après une mise à jour du cœur, wp_version_check() tient lui-même le transient
	 *    pour invalide (`version_checked` différent de la version courante) ;
	 *  - wordpress.org rend toujours au moins une offre (« latest » quand le site est à
	 *    jour) : une liste vide est une vérification restée sans réponse.
	 *
	 * @param mixed $core Transient `update_core`.
	 */
	private static function core_check_is_current( $core, int $now ): bool {
		if ( ! PremiumUpdatesBridge::checked_recently( $core, $now ) || ! is_object( $core ) ) {
			return false;
		}
		/** @var \stdClass $core */
		return isset( $core->version_checked, $core->updates )
			&& (string) get_bloginfo( 'version' ) === (string) $core->version_checked
			&& is_array( $core->updates )
			&& [] !== $core->updates;
	}

	/**
	 * Capacités du connecteur, pour que la plateforme n'ait pas à comparer des
	 * numéros de version. `restore_points` n'est annoncée que si le module peut
	 * réellement fonctionner ici (système de fichiers direct + bibliothèque zip).
	 * `direct_login` (1.13) : le site accepte les tickets de connexion directe ; la
	 * case du réglage est remontée à part (`site.direct_login_enabled`), pour que le
	 * manager distingue « connecteur trop ancien » de « désactivée sur ce site ».
	 *
	 * Publique pour les tests.
	 *
	 * @return list<string>
	 */
	public function capabilities(): array {
		$capabilities = [ 'signed_commands', SnapshotFields::CAPABILITY ];
		if ( Services::supported() ) {
			$capabilities[] = 'restore_points';
		}
		return $capabilities;
	}

	/**
	 * Fraîcheur de la découverte des MAJ tierces.
	 *
	 * Sans ce bloc, un site dont le cron est coupé continuerait d'annoncer « tout
	 * est à jour » : le manager doit pouvoir distinguer « rien à mettre à jour »
	 * de « je n'ai pas pu regarder ».
	 *
	 * @param bool $stale Résultat déjà calculé, pour ne pas relire le cache.
	 * @return array{captured_at: string|null, stale: bool, cron_disabled: bool, next_run: string|null}
	 */
	private function updates_discovery( bool $stale ): array {
		$last = PremiumUpdatesBridge::last_capture();

		return [
			'captured_at'   => null !== $last ? $last['captured_at'] : null,
			'stale'         => $stale,
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_run'      => UpdatesDiscoveryJob::next_run(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function site_info(): array {
		return array_merge(
			[
				'name'              => (string) get_bloginfo( 'name' ),
				'url'               => (string) home_url( '/' ),
				'admin_email'       => (string) get_bloginfo( 'admin_email' ),
				'language'          => (string) get_bloginfo( 'language' ),
				'timezone'          => (string) wp_timezone_string(),
				'multisite'         => is_multisite(),
				// Vrai favicon WP (Site Icon réglé dans l'admin), '' si non défini.
				// Le manager l'affiche dans ses pages de gestion (repli sur l'icône).
				'site_icon_url'     => (string) get_site_icon_url( 192 ),
				'connector_version' => G2RD_CONNECTOR_VERSION,
			],
			// Connexion directe (1.13) : page de connexion effective, case du réglage,
			// administrateurs du site (cf. DirectLogin\SnapshotFields).
			SnapshotFields::site_fields()
		);
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

		// Purge le cache realpath/stat de ce worker FPM AVANT toute lecture.
		// Quand un plugin est mis a jour, son dossier est remplace (nouvel
		// inode). Un worker lsphp longue-duree garde l'ancien inode en cache
		// realpath -> get_plugin_data()/fopen relit le vieux header `Version:`
		// (faux "MAJ dispo" qui survit au resync). clearstatcache( true )
		// vide AUSSI le cache realpath (la forme sans argument ne le fait pas).
		clearstatcache( true );

		$all              = get_plugins();
		$active           = (array) get_option( 'active_plugins', [] );
		$updates          = get_site_transient( 'update_plugins' );
		$updates_response = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

		$out = [];
		foreach ( $all as $file => $data ) {
			// Version installee lue FRAICHEMENT sur le fichier (cf fresh_plugin_version) :
			// get_plugins() ci-dessus peut renvoyer un header `Version:` perime servi par
			// un object cache persistant (LiteSpeed / Redis sur Hostinger) qui survit a
			// wp_cache_flush() / wp_clean_plugins_cache() dans le contexte REST. D'ou des
			// MAJ deja faites qui restent proposees (ex: manager 2.5.0 alors que wp-admin
			// affiche 2.6.0). get_plugin_data() relit le fichier directement (get_file_data,
			// sans cache d'objet) -> version reelle, comme wp-admin.
			$installed  = $this->fresh_plugin_version( $file, (string) ( $data['Version'] ?? '' ) );
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
				'version_installed' => $installed,
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
	 * Version installee d'un plugin lue FRAICHEMENT depuis son fichier (header
	 * `Version:`), sans passer par le cache d'objet 'plugins' de get_plugins().
	 *
	 * Sur les hebergeurs a object cache persistant, ce cache peut survivre a
	 * wp_cache_flush() / wp_clean_plugins_cache() entre deux requetes REST et
	 * renvoyer le header d'AVANT une MAJ -> le manager continue de proposer une
	 * MAJ deja faite. get_plugin_data() s'appuie sur get_file_data() (lecture
	 * directe du fichier, sans cache), exactement comme la page wp-admin Extensions.
	 *
	 * Fallback sur la valeur (potentiellement perimee) de get_plugins() si le
	 * fichier est introuvable / illisible.
	 */
	private function fresh_plugin_version( string $file, string $fallback ): string {
		// Implementation dans PremiumUpdatesBridge : la passerelle a besoin de la
		// meme lecture pour son garde version_compare, on evite d'en avoir deux copies.
		return PremiumUpdatesBridge::installed_plugin_version( $file, $fallback );
	}

	/**
	 * Pendant theme de fresh_plugin_version() : version lue directement depuis le
	 * `style.css` du theme (get_file_data, sans cache), pour contourner le cache
	 * d'objet de WP_Theme qui peut rester perime apres une MAJ de theme.
	 */
	private function fresh_theme_version( \WP_Theme $theme, string $fallback ): string {
		// Cf. fresh_plugin_version() : implementation unique dans PremiumUpdatesBridge.
		return PremiumUpdatesBridge::installed_theme_version( $theme->get_stylesheet(), $fallback );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function themes(): array {
		// Cf. plugins() : purge realpath/stat cache avant lecture des versions.
		clearstatcache( true );

		$all     = wp_get_themes();
		$current = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$resp    = is_object( $updates ) && isset( $updates->response ) ? (array) $updates->response : [];

		$out = [];
		foreach ( $all as $stylesheet => $theme ) {
			// Version installee lue FRAICHEMENT depuis style.css (cf fresh_theme_version),
			// pour contourner le cache d'objet de WP_Theme, comme pour les plugins.
			$installed  = $this->fresh_theme_version( $theme, (string) $theme->get( 'Version' ) );
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
