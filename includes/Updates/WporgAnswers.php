<?php
/**
 * Heure de la dernière réponse REÇUE de wordpress.org à chacune des trois vérifications
 * des mises à jour (cœur, extensions, thèmes) : ce que les transients de mise à jour ne
 * disent pas.
 *
 * Problème
 * --------
 * wp_version_check(), wp_update_plugins() et wp_update_themes() (wp-includes/update.php,
 * WordPress 6.9.4) écrivent `last_checked = time()` dans le transient EXISTANT avant
 * d'appeler wordpress.org (« to prevent multiple blocking requests if request hangs »),
 * puis s'arrêtent sans rien écrire d'autre sur une erreur réseau ou une réponse autre
 * que 200. Le transient garde alors ses anciennes données (`checked`, `response`,
 * `no_update`, `updates`) sous une date toute neuve : `last_checked` dit quand WordPress
 * a ESSAYÉ, pas quand wordpress.org a RÉPONDU. Exemple : dernière réponse à 2 h, cron
 * de 14 h sans réponse (lenteur, 403 du CDN devant api.wordpress.org) : à 15 h, le
 * transient paraît vérifié il y a une heure avec les données de 2 h.
 *
 * Solution
 * --------
 * L'action `http_api_debug` (WP_Http::request(), après chaque requête HTTP) reçoit la
 * réponse. Une réponse 200 de api.wordpress.org à l'une des trois adresses de
 * vérification est notée, par vérification, si son corps est du JSON qui porte les
 * données de cette vérification : une écriture par vérification réussie, aucune pour le
 * reste du trafic HTTP du site. Pour le cœur, il faut des offres (`offers`) : sans elles,
 * wp_version_check() s'arrête sans rien écrire. Pour les extensions et les thèmes, il faut
 * `plugins` (resp. `themes`) : wp_update_plugins() et wp_update_themes() écrivent un
 * transient neuf après tout 200, mais vide quand le corps n'est pas la réponse attendue
 * (page de défi HTML d'un CDN, par exemple) — noté, il passerait pour frais pendant 3 h et
 * cacherait une mise à jour publiée.
 *
 * L'inventaire (PremiumUpdatesBridge::is_fresh(), SnapshotController) ne tient un
 * transient pour frais que si `last_checked` ET cette heure ont moins de
 * PremiumUpdatesBridge::UPDATE_CHECK_MAX_AGE. Ce qui n'est pas noté (connecteur tout
 * juste mis à jour, requête court-circuitée par `pre_http_request` avec une erreur ou un
 * code autre que 200 (un 200 fourni par le filtre est noté, comme WordPress le tient pour
 * une réponse), wordpress.org bloqué) laisse l'inventaire rafraîchir à chaque
 * synchronisation, comme avant la 1.14 : plus lourd, jamais faux.
 *
 * Option de RÉSEAU, comme les transients de mise à jour (transients de site) : en
 * multisite, une vérification vaut pour tous les blogs. En site simple,
 * update_site_option() écrit une option non autochargée (update_network_option(),
 * wp-includes/option.php). Retirée à la désinstallation.
 *
 * Écoute branchée après la barrière d'enrôlement (Plugin::boot()) : aucune écriture
 * avant l'accord explicite de l'administrateur.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Updates;

final class WporgAnswers {

	/** Heure (horodatage Unix) de la dernière réponse reçue, par vérification. */
	public const OPTION = 'g2rd_connector_wporg_answers';

	private const HOST = 'api.wordpress.org';

	/**
	 * Adresses des trois vérifications (wp-includes/update.php), par vérification :
	 * core/version-check/1.7/, plugins/update-check/1.1/, themes/update-check/1.1/. Le
	 * numéro de version de l'API n'est pas retenu : il change sans que le sens change.
	 */
	private const CHECK_PATHS = [
		'core'    => '/core/version-check/',
		'plugins' => '/plugins/update-check/',
		'themes'  => '/themes/update-check/',
	];

	/**
	 * Clé que le corps JSON doit porter pour que la réponse compte, par vérification : les
	 * données que WordPress range dans le transient (wp-includes/update.php).
	 */
	private const ANSWER_KEYS = [
		'core'    => 'offers',
		'plugins' => 'plugins',
		'themes'  => 'themes',
	];

	public static function register(): void {
		add_action( 'http_api_debug', [ self::class, 'record' ], 10, 5 );
	}

	/**
	 * Note l'heure d'une réponse de wordpress.org à l'une des trois vérifications.
	 *
	 * Appelée après CHAQUE requête HTTP du site : tout ce qui n'est pas une vérification
	 * de wordpress.org sort à la première comparaison de chaîne. Best-effort : une
	 * écriture impossible ne gêne jamais la requête (l'inventaire rafraîchira alors).
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $response  Réponse (tableau) ou WP_Error.
	 * @param mixed $context   Toujours 'response' dans WP_Http::request().
	 * @param mixed $transport Classe du transport (non utilisée).
	 * @param mixed $args      Arguments de la requête (non utilisés).
	 * @param mixed $url       Adresse appelée.
	 */
	public static function record( $response, $context = 'response', $transport = '', $args = [], $url = '' ): void {
		unset( $transport, $args );
		if ( 'response' !== $context || ! is_array( $response ) || ! is_string( $url ) || false === stripos( $url, self::HOST ) ) {
			return;
		}
		$check = self::check_of( $url );
		if ( null === $check || 200 !== self::status_of( $response ) ) {
			return;
		}
		if ( ! self::carries( $response, self::ANSWER_KEYS[ $check ] ) ) {
			return;
		}

		try {
			$answers           = self::all();
			$answers[ $check ] = time();
			update_site_option( self::OPTION, $answers );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Vrai si wordpress.org a répondu à cette vérification il y a moins de `$max_age`
	 * secondes. Jamais notée, ou notée dans le futur (horloge revenue en arrière) : faux.
	 *
	 * @param string $check 'core' | 'plugins' | 'themes'.
	 */
	public static function answered_within( string $check, int $now, int $max_age ): bool {
		$answers = self::all();
		if ( ! isset( $answers[ $check ] ) ) {
			return false;
		}
		$age = $now - $answers[ $check ];

		return $age >= 0 && $age < $max_age;
	}

	/**
	 * Heures notées, lisibles seulement.
	 *
	 * @return array<string, int>
	 */
	private static function all(): array {
		$stored = get_site_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$out = [];
		foreach ( array_keys( self::CHECK_PATHS ) as $check ) {
			if ( isset( $stored[ $check ] ) && is_int( $stored[ $check ] ) ) {
				$out[ $check ] = $stored[ $check ];
			}
		}

		return $out;
	}

	/** Vérification visée par l'adresse, ou null (autre hôte, autre service). */
	private static function check_of( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || self::HOST !== strtolower( $host ) ) {
			return null;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return null;
		}
		foreach ( self::CHECK_PATHS as $check => $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * Code HTTP de la réponse (forme de WP_Http::request()), 0 s'il manque.
	 *
	 * @param array<mixed> $response Réponse.
	 */
	private static function status_of( array $response ): int {
		$status = $response['response'] ?? null;

		return is_array( $status ) && isset( $status['code'] ) && is_numeric( $status['code'] ) ? (int) $status['code'] : 0;
	}

	/**
	 * Le corps est du JSON qui porte `$key` (ANSWER_KEYS) : pour le cœur, la condition de
	 * wp_version_check() pour écrire le transient `update_core` ; pour les extensions et les
	 * thèmes, ce qui distingue une vraie réponse d'un 200 au corps HTML ou vide, après lequel
	 * WordPress écrit un transient vide (cf. en-tête de la classe).
	 *
	 * @param array<mixed> $response Réponse.
	 * @param string       $key      Clé attendue.
	 */
	private static function carries( array $response, string $key ): bool {
		$body    = isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
		$decoded = json_decode( trim( $body ), true );

		return is_array( $decoded ) && isset( $decoded[ $key ] );
	}
}
