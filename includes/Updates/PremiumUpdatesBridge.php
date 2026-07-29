<?php
/**
 * Passerelle de détection des mises à jour annoncées par des updaters TIERS.
 *
 * Problème résolu
 * ---------------
 * Certains updaters tiers ne s'enregistrent pas dans le contexte REST. Constaté
 * et mesuré sur g2rd.fr avec SEOPress PRO :
 *
 *  - `SEOPRESS_Updater::check_update` est branché sur le filtre d'ÉCRITURE
 *    `pre_set_site_transient_update_plugins`, et il est bien actif en WP-CLI et
 *    en administration ;
 *  - en WP-CLI, `delete_site_transient( 'update_plugins' ) + wp_update_plugins()`
 *    régénère donc l'entrée `wp-seopress-pro/seopress-pro.php` ;
 *  - la MÊME séquence exécutée par /snapshot (requête REST authentifiée par
 *    Bearer SiteToken) écrit un transient dont `response` est VIDE, et le
 *    snapshot renvoie `has_update: false`.
 *
 * Le connecteur détruisait ainsi une entrée qu'il ne savait pas reconstruire.
 * Comme la synchronisation de fond tourne toutes les heures, la fenêtre pendant
 * laquelle l'entrée existe (entre une visite de wp-admin et la sync suivante)
 * est très étroite : la MAJ restait invisible côté manager en permanence, et
 * `Plugin_Upgrader` n'avait pas non plus d'URL de `package` pour l'appliquer.
 *
 * Tous les updaters ne sont pas concernés : Elementor Pro, Astra Pro ou les
 * plugins Fluent* s'enregistrent sans condition de contexte et remontent
 * normalement. Le discriminant n'est pas « premium vs wp.org » mais le contexte
 * dans lequel l'updater accepte de s'enregistrer.
 *
 * Stratégie
 * ---------
 * 1. CAPTURE — depuis un écran d'administration, où les updaters tiers sont
 *    actifs, on copie les entrées de MAJ dans un cache persistant à nous.
 *
 * 2. REJEU — hors administration, on réinjecte ces entrées via le filtre de
 *    LECTURE `site_transient_update_*`, sans jamais réécrire le transient.
 *    Trois raisons de préférer le filtre à une réécriture :
 *      - une réécriture re-déclencherait `pre_set_site_transient_update_*`,
 *        donc un second passage des updaters présents (AddonUpdatePusher,
 *        Fluent*…) et leurs appels réseau, à chaque synchronisation ;
 *      - nos entrées reconstituées ne sont jamais persistées en base ;
 *      - `Plugin_Upgrader::upgrade()` et `Theme_Upgrader::upgrade()` lisent
 *        eux aussi via `get_site_transient()` : le `package` leur parvient sans
 *        code supplémentaire.
 *
 * Ce qui a été mémorisé juste avant le rafraîchissement alimente le rejeu au
 * même titre que le cache : si une visite admin a eu lieu depuis la dernière
 * sync, l'entrée est encore en base et on la récupère sans dépendre du cache.
 *
 * Aucun appel réseau n'est ajouté, aucune élévation de privilège n'est faite :
 * ni `wp_set_current_user()`, ni `do_action( 'admin_init' )` — ce dernier
 * déclencherait chez les plugins tiers des redirections (`wp_redirect(); exit;`
 * tuerait la réponse REST), des migrations de base et des appels licence
 * bloquants, impossibles à borner sur un parc client.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Updates;

use stdClass;
use WP_Screen;

final class PremiumUpdatesBridge {

	/** Cache persistant alimenté depuis l'administration. */
	private const CACHE_KEY = 'g2rd_updates_snapshot';

	/**
	 * Écrans d'administration où le transient de MAJ est frais ET les updaters
	 * tiers actifs. `plugins` et `update-core` forcent un `wp_update_*()` via
	 * `load-plugins.php` / `load-update-core.php` ; `dashboard` repasse par
	 * `_maybe_update_plugins()`, dont même le chemin « rien à refaire » ré-écrit
	 * le transient et redéclenche donc les filtres des updaters tiers.
	 */
	private const CAPTURE_SCREENS = [ 'plugins', 'update-core', 'dashboard' ];

	/**
	 * Clés conservées d'une entrée plugin (objet). Liste blanche : borne la
	 * taille du cache et évite de sérialiser des structures imprévues (icônes,
	 * bannières, objets de compatibilité…). `package` est indispensable : c'est
	 * l'URL que Plugin_Upgrader utilise pour appliquer la mise à jour.
	 */
	private const PLUGIN_KEYS = [ 'id', 'slug', 'plugin', 'new_version', 'package', 'url', 'tested', 'requires', 'requires_php' ];

	/** Pendant thème : les entrées de `update_themes` sont des tableaux, pas des objets. */
	private const THEME_KEYS = [ 'theme', 'new_version', 'package', 'url', 'requires', 'requires_php' ];

	/**
	 * Entrées à réinjecter à la lecture, indexées par fichier de plugin.
	 * Vidées à chaque requête (état de requête, pas état persistant).
	 *
	 * @var array<string, stdClass>
	 */
	private static array $replay_plugins = [];

	/** @var array<string, array<string, mixed>> */
	private static array $replay_themes = [];

	private static bool $filters_installed = false;

	// ─────────────────────────────────────────────────────────────────────────
	// Capture (contexte administration)
	// ─────────────────────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'current_screen', [ $this, 'maybe_schedule_capture' ] );
	}

	/**
	 * Programme la capture si l'écran courant est l'un de ceux où les MAJ tierces
	 * sont résolues. On attend `admin_footer` : la page est alors entièrement
	 * rendue, donc tous les updaters ont eu l'occasion de s'enregistrer et
	 * d'injecter leur entrée.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $screen Écran courant (WP_Screen attendu).
	 */
	public function maybe_schedule_capture( $screen ): void {
		if ( ! $screen instanceof WP_Screen ) {
			return;
		}
		if ( ! in_array( $screen->id, self::CAPTURE_SCREENS, true ) ) {
			return;
		}
		add_action( 'admin_footer', [ $this, 'capture' ], 99 );
	}

	/**
	 * Copie les entrées de MAJ actuellement connues de WordPress dans le cache
	 * persistant.
	 *
	 * On capture toutes les entrées, pas seulement celles d'updaters tiers :
	 * inutile de maintenir une heuristique wp.org, puisque le rejeu n'injecte
	 * que ce que la lecture courante ignore déjà.
	 *
	 * Best-effort strict : une capture qui échoue ne doit jamais casser le rendu
	 * d'une page d'administration.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 */
	public function capture(): void {
		try {
			$plugins = [];
			foreach ( self::response_of( get_site_transient( 'update_plugins' ) ) as $file => $entry ) {
				$kept = self::whitelist_plugin_entry( $entry );
				if ( null !== $kept ) {
					$plugins[ (string) $file ] = $kept;
				}
			}

			$themes = [];
			foreach ( self::response_of( get_site_transient( 'update_themes' ) ) as $stylesheet => $entry ) {
				$kept = self::whitelist_theme_entry( $entry );
				if ( null !== $kept ) {
					$themes[ (string) $stylesheet ] = $kept;
				}
			}

			// On écrit même si tout est à jour : le cache reflète alors fidèlement
			// « rien en attente », ce qui évacue les entrées déjà appliquées et
			// permet à la ligne de diagnostic de prouver que la capture tourne.
			set_site_transient(
				self::CACHE_KEY,
				[
					'plugins'     => $plugins,
					'themes'      => $themes,
					'captured_at' => gmdate( 'c' ),
				],
				self::cache_ttl()
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Métadonnées de la dernière capture, pour la ligne de diagnostic de la page
	 * d'options. `null` si aucune capture n'a encore eu lieu.
	 *
	 * @return array{captured_at: string, plugins: int, themes: int}|null
	 */
	public static function last_capture(): ?array {
		$payload     = self::cached_payload();
		$captured_at = isset( $payload['captured_at'] ) ? (string) $payload['captured_at'] : '';
		if ( '' === $captured_at ) {
			return null;
		}

		return [
			'captured_at' => $captured_at,
			'plugins'     => count( self::cached_section( $payload, 'plugins' ) ),
			'themes'      => count( self::cached_section( $payload, 'themes' ) ),
		];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rafraîchissement + rejeu (contexte REST / cron)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Rafraîchit `update_plugins` / `update_themes`, puis installe le rejeu des
	 * entrées que ce contexte ne sait pas régénérer.
	 *
	 * Remplace tout couple `delete_site_transient() + wp_update_*()` du
	 * connecteur. Une fois appelée, toute lecture ultérieure de ces transients
	 * dans la requête courante voit la vue complétée — y compris celles faites
	 * par `Plugin_Upgrader` / `Theme_Upgrader`.
	 *
	 * Best-effort : en cas d'échec, le comportement dégradé est exactement le
	 * comportement historique.
	 */
	public static function refresh_update_transients(): void {
		try {
			if ( ! function_exists( 'wp_update_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}

			// Mémorisation AVANT destruction : si une visite de wp-admin a eu lieu
			// depuis la dernière synchronisation, l'entrée tierce est encore en base
			// et on la récupère sans dépendre du cache.
			$preserved_plugins = self::response_of( get_site_transient( 'update_plugins' ) );
			$preserved_themes  = self::response_of( get_site_transient( 'update_themes' ) );

			// La destruction est nécessaire : sans elle, `wp_update_plugins()` sort
			// en avance (fenêtre de 12 h) et les updaters GitHub ne sont pas
			// ré-évalués. Le rejeu ci-dessous compense ce qu'elle fait perdre.
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'update_themes' );
			wp_update_plugins();
			wp_update_themes();

			self::$replay_plugins = self::plugin_extras( $preserved_plugins );
			self::$replay_themes  = self::theme_extras( $preserved_themes );

			self::install_replay_filters();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Filtre `site_transient_update_plugins` : complète la vue lue avec les
	 * entrées que ce contexte ne sait pas produire.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $value Transient tel que lu (objet, ou false si absent).
	 * @return mixed
	 */
	public static function replay_plugins( $value ) {
		if ( [] === self::$replay_plugins || ! is_object( $value ) ) {
			return $value;
		}
		/** @var stdClass $value */
		$response = self::response_of( $value );
		foreach ( self::$replay_plugins as $file => $entry ) {
			// La lecture courante fait autorité : on ne complète que ses manques.
			if ( ! isset( $response[ $file ] ) ) {
				$response[ $file ] = $entry;
			}
		}
		$value->response = $response;

		return $value;
	}

	/**
	 * Pendant thème de replay_plugins().
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $value Transient tel que lu.
	 * @return mixed
	 */
	public static function replay_themes( $value ) {
		if ( [] === self::$replay_themes || ! is_object( $value ) ) {
			return $value;
		}
		/** @var stdClass $value */
		$response = self::response_of( $value );
		foreach ( self::$replay_themes as $stylesheet => $entry ) {
			if ( ! isset( $response[ $stylesheet ] ) ) {
				$response[ $stylesheet ] = $entry;
			}
		}
		$value->response = $response;

		return $value;
	}

	private static function install_replay_filters(): void {
		if ( self::$filters_installed ) {
			return;
		}
		if ( [] === self::$replay_plugins && [] === self::$replay_themes ) {
			return;
		}
		add_filter( 'site_transient_update_plugins', [ self::class, 'replay_plugins' ] );
		add_filter( 'site_transient_update_themes', [ self::class, 'replay_themes' ] );
		self::$filters_installed = true;
	}

	/**
	 * Entrées plugin candidates au rejeu : ce qui était en base juste avant le
	 * rafraîchissement, complété par le cache de capture.
	 *
	 * Le garde `version_compare` est appliqué ICI (une fois), et non dans le
	 * filtre de lecture qui peut être appelé de nombreuses fois par requête : il
	 * implique une lecture disque par entrée. C'est aussi lui qui empêche une
	 * entrée périmée de survivre à l'application de la mise à jour.
	 *
	 * @param array<string, mixed> $preserved Entrées lues avant destruction.
	 * @return array<string, stdClass>
	 */
	private static function plugin_extras( array $preserved ): array {
		$out = [];
		foreach ( self::merge_candidates( $preserved, 'plugins' ) as $file => $entry ) {
			$file = (string) $file;
			$kept = self::whitelist_plugin_entry( $entry );
			if ( null === $kept ) {
				continue;
			}
			$installed = self::installed_plugin_version( $file );
			// Version installée illisible => on n'annonce rien (même règle
			// conservatrice que SyncService::isStrictlyNewer() côté manager).
			if ( '' === $installed || ! version_compare( (string) $kept->new_version, $installed, '>' ) ) {
				continue;
			}
			$out[ $file ] = $kept;
		}

		return $out;
	}

	/**
	 * Pendant thème de plugin_extras().
	 *
	 * @param array<string, mixed> $preserved Entrées lues avant destruction.
	 * @return array<string, array<string, mixed>>
	 */
	private static function theme_extras( array $preserved ): array {
		$out = [];
		foreach ( self::merge_candidates( $preserved, 'themes' ) as $stylesheet => $entry ) {
			$stylesheet = (string) $stylesheet;
			$kept       = self::whitelist_theme_entry( $entry );
			if ( null === $kept ) {
				continue;
			}
			$installed = self::installed_theme_version( $stylesheet );
			if ( '' === $installed || ! version_compare( (string) $kept['new_version'], $installed, '>' ) ) {
				continue;
			}
			$out[ $stylesheet ] = $kept;
		}

		return $out;
	}

	/**
	 * Candidats par priorité décroissante : entrées mémorisées avant destruction,
	 * puis cache de capture pour ce qu'elles ne couvrent pas.
	 *
	 * @param array<string, mixed> $preserved Entrées lues avant destruction.
	 * @param string               $section   'plugins' | 'themes'.
	 * @return array<string, mixed>
	 */
	private static function merge_candidates( array $preserved, string $section ): array {
		$candidates = $preserved;
		foreach ( self::cached_section( self::cached_payload(), $section ) as $key => $entry ) {
			if ( ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = $entry;
			}
		}

		return $candidates;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Lecture de version sur disque (source unique — SnapshotController délègue ici)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Version installée d'un plugin, lue FRAÎCHEMENT depuis son header `Version:`.
	 *
	 * `get_plugins()` peut renvoyer un header périmé servi par un object cache
	 * persistant (LiteSpeed / Redis sur Hostinger) qui survit à `wp_cache_flush()`
	 * dans le contexte REST. `get_plugin_data()` s'appuie sur `get_file_data()`
	 * (lecture directe, sans cache d'objet), exactement comme la page wp-admin
	 * Extensions. Le `clearstatcache()` couvre le remplacement d'inode après une
	 * mise à jour.
	 */
	public static function installed_plugin_version( string $file, string $fallback = '' ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = WP_PLUGIN_DIR . '/' . $file;
		clearstatcache( true, $path );
		if ( ! is_readable( $path ) ) {
			return $fallback;
		}

		// $markup = false, $translate = false : lecture brute du header, sans i18n ni HTML.
		$data    = get_plugin_data( $path, false, false );
		$version = (string) $data['Version'];

		return '' !== $version ? $version : $fallback;
	}

	/**
	 * Pendant thème de installed_plugin_version() : version lue directement depuis
	 * le `style.css`, pour contourner le cache d'objet de WP_Theme.
	 */
	public static function installed_theme_version( string $stylesheet, string $fallback = '' ): string {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return $fallback;
		}

		$style = $theme->get_stylesheet_directory() . '/style.css';
		clearstatcache( true, $style );
		if ( ! is_readable( $style ) ) {
			return $fallback;
		}

		$data    = get_file_data( $style, [ 'Version' => 'Version' ] );
		$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';

		return '' !== $version ? $version : $fallback;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers internes
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Extrait `response` d'un transient de MAJ, quelle que soit sa forme.
	 * WordPress y stocke un stdClass à structure dynamique : d'où les gardes.
	 *
	 * @param mixed $transient Transient brut (objet, false, ou n'importe quoi).
	 * @return array<string, mixed>
	 */
	private static function response_of( $transient ): array {
		if ( ! is_object( $transient ) ) {
			return [];
		}
		/** @var stdClass $transient */
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return [];
		}

		return $transient->response;
	}

	/**
	 * Réduit une entrée plugin à la liste blanche de clés. Retourne null si
	 * l'entrée n'annonce pas de version cible exploitable.
	 *
	 * @param mixed $entry Entrée brute du transient.
	 */
	private static function whitelist_plugin_entry( $entry ): ?stdClass {
		if ( ! is_object( $entry ) ) {
			return null;
		}

		$data = get_object_vars( $entry );
		if ( ! isset( $data['new_version'] ) || '' === (string) $data['new_version'] ) {
			return null;
		}

		$kept = new stdClass();
		foreach ( self::PLUGIN_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) && ( is_scalar( $data[ $key ] ) || null === $data[ $key ] ) ) {
				$kept->{$key} = $data[ $key ];
			}
		}

		return $kept;
	}

	/**
	 * Pendant thème de whitelist_plugin_entry() : les entrées de `update_themes`
	 * sont des tableaux associatifs.
	 *
	 * @param mixed $entry Entrée brute du transient.
	 * @return array<string, mixed>|null
	 */
	private static function whitelist_theme_entry( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		if ( ! isset( $entry['new_version'] ) || '' === (string) $entry['new_version'] ) {
			return null;
		}

		$kept = [];
		foreach ( self::THEME_KEYS as $key ) {
			if ( array_key_exists( $key, $entry ) && ( is_scalar( $entry[ $key ] ) || null === $entry[ $key ] ) ) {
				$kept[ $key ] = $entry[ $key ];
			}
		}

		return $kept;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function cached_payload(): array {
		$payload = get_site_transient( self::CACHE_KEY );

		return is_array( $payload ) ? $payload : [];
	}

	/**
	 * @param array<string, mixed> $payload Cache complet.
	 * @param string               $section 'plugins' | 'themes'.
	 * @return array<string, mixed>
	 */
	private static function cached_section( array $payload, string $section ): array {
		return isset( $payload[ $section ] ) && is_array( $payload[ $section ] ) ? $payload[ $section ] : [];
	}

	/**
	 * TTL du cache. Méthode plutôt que constante de classe : `DAY_IN_SECONDS` est
	 * défini à l'exécution par WordPress, on évite toute dépendance à l'ordre de
	 * définition des constantes.
	 */
	private static function cache_ttl(): int {
		return 7 * DAY_IN_SECONDS;
	}
}
