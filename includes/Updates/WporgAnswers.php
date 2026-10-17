<?php
/**
 * Heure de la dernière réponse REÇUE de wordpress.org à chacune des trois vérifications
 * des mises à jour (cœur, extensions, thèmes), liée à l'écriture du transient qui la
 * porte : ce que les transients de mise à jour ne disent pas.
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
 * WordPress reçoit la réponse par l'un de deux chemins (WP_Http::request(),
 * wp-includes/class-wp-http.php, WordPress 6.9.4, identique en 7.1.3) :
 *  - requête faite : l'action `http_api_debug` reçoit la réponse, puis le filtre
 *    `http_response` la rend à WordPress ;
 *  - requête court-circuitée : un filtre `pre_http_request` rend autre chose que false,
 *    et WP_Http::request() rend cette valeur telle quelle (`if ( false !== $pre ) {
 *    return $pre; }`), sans `http_api_debug` ni `http_response` pour cette adresse
 *    (cf. « Requête court-circuitée » plus bas).
 * Une réponse à l'une des trois adresses de vérification de api.wordpress.org compte si
 * c'est un tableau au code 200 exactement — un entier : update.php compare strictement
 * (`200 !== wp_remote_retrieve_response_code()`) — dont le corps est du JSON qui porte
 * les données de cette vérification. Pour le cœur, il faut des offres (`offers`) : sans elles,
 * wp_version_check() s'arrête sans rien écrire. Pour les extensions et les thèmes, il faut
 * `plugins` (resp. `themes`) : wp_update_plugins() et wp_update_themes() écrivent un
 * transient neuf après tout 200, mais vide quand le corps n'est pas la réponse attendue
 * (page de défi HTML d'un CDN, par exemple) — noté, il passerait pour frais pendant 3 h et
 * cacherait une mise à jour publiée.
 *
 * Une réponse qui compte n'est pas notée à sa réception : elle reste EN ATTENTE, en
 * mémoire, et n'est écrite en base que si WordPress écrit ensuite, dans la même requête
 * PHP, le transient qui la porte (`update_core`, `update_plugins`, `update_themes`). Sans
 * ce lien, une réponse que WordPress n'écrit pas — filtre `http_response` qui la
 * transforme, appel d'update-check fait par une autre extension — ferait passer pour
 * fraîches des données de plus de 3 h. L'ordre suivi est celui de wp-includes/update.php
 * et de set_site_transient() (wp-includes/option.php), WordPress 6.9.4, identique en 7.1.3 :
 *
 *  1. écriture d'AVANT l'appel (`last_checked`, update.php l. 76-77, 424-425, 711-712) :
 *     aucune réponse en attente, rien n'est noté ; la vérification est « amorcée » ;
 *  2. réponse (`http_api_debug`, ou valeur finale de `pre_http_request` pour une requête
 *     court-circuitée) : mise en attente seulement si la vérification est
 *     amorcée. Une réponse sans écriture du transient avant elle dans cette requête n'est
 *     pas celle d'une vérification de WordPress (autre extension) : ignorée. Une erreur
 *     réseau (WP_Error) en https:// garde l'amorce (WordPress retente en http://) ; en
 *     http://, dernier essai de WordPress (repli, ou essai unique sans SSL), elle la
 *     retire, comme un code autre que 200, un 200 sans les données ou toute autre valeur
 *     rendue par un filtre (WordPress s'arrête, ou écrit un transient vide). Juste après, le
 *     filtre `http_response`, branché en
 *     dernier, voit la réponse que WordPress lira : transformée par un autre filtre en
 *     autre chose qu'un 200 porteur des données, elle n'est plus en attente — WordPress
 *     n'écrira pas le transient, et elle ne doit pas être emportée par l'écriture d'avant
 *     l'appel d'une vérification suivante dans la même requête (cron qui enchaîne
 *     wp_update_plugins et la découverte du connecteur). Pour une requête court-circuitée,
 *     la valeur finale de `pre_http_request` est déjà celle que WordPress lit : la
 *     confirmation se fait au même moment ;
 *  3. écriture de la réponse : le filtre `pre_set_site_transient_{$transient}`, branché
 *     en dernier, lie la réponse en attente à CETTE écriture et à elle seule — elle ne
 *     survit jamais jusqu'à une écriture suivante. Elle est notée quand l'écriture
 *     aboutit : action `set_site_transient_{$transient}` (déclenchée seulement si
 *     l'écriture a réussi), ou valeur identique à celle en base — update_network_option()
 *     s'arrête alors sans écrire ni déclencher l'action, juste après le filtre
 *     `pre_update_site_option_{$option}` (réponse inchangée, écrite dans la même seconde
 *     que l'écriture d'avant l'appel) : la base porte exactement la réponse. Écriture
 *     refusée par la base : rien n'est noté.
 *
 * Une écriture en base par vérification réussie, aucune pour le reste du trafic HTTP du
 * site ni pour les autres écritures des transients.
 *
 * Requête court-circuitée (`pre_http_request`)
 * ---------------------------------------------
 * Sur les sites hébergés chez Hostinger, le mu-plugin `hostinger-auto-updates.php`
 * branche sur `pre_http_request` (priorité 10) un filtre qui refait chaque requête vers
 * api.wordpress.org vers son proxy (`HOSTINGER_API_PROXY_HOST`, wpapi.hostinger.io par
 * défaut), par une requête imbriquée, et rend la réponse du proxy à WordPress (relevé sur
 * g2rd.fr le 16/10/2026, WordPress 7.1.3). Seule la requête imbriquée déclenche
 * `http_api_debug` et `http_response`, pour l'adresse du proxy, que check_of() ignore
 * (autre hôte) : aucun double comptage. Sans l'observation qui suit, aucune réponse
 * n'était notée sur ces sites et l'inventaire rafraîchissait à chaque synchronisation.
 *
 * Le filtre `pre_http_request` est donc observé en dernier (PHP_INT_MAX) : il voit la
 * valeur finale, celle que WordPress utilise comme réponse. Quand l'adresse d'ORIGINE est
 * exactement l'une des trois vérifications (même règle stricte, check_of()), cette valeur
 * compte comme la réponse reçue, quel que soit l'hôte qui l'a servie — l'hôte du proxy
 * n'est jamais supposé : mêmes règles qu'à l'étape 2, confirmation au même moment, puis
 * étape 3 inchangée (notée seulement si WordPress écrit ensuite le transient qui la
 * porte). Un filtre qui refait la requête vers la MÊME adresse : la réponse imbriquée est
 * mise en attente par `http_api_debug`, la valeur finale ne fait que la confirmer (ou la
 * retirer si elle n'est plus un 200 porteur des données) — une seule écriture. La valeur
 * du filtre est toujours rendue telle quelle, jamais modifiée.
 *
 * L'inventaire (PremiumUpdatesBridge::is_fresh(), SnapshotController) ne tient un
 * transient pour frais que si `last_checked` ET cette heure ont moins de
 * PremiumUpdatesBridge::UPDATE_CHECK_MAX_AGE. Ce qui n'est pas noté (connecteur tout
 * juste mis à jour, wordpress.org ou son proxy injoignable, réponse que WordPress n'écrit
 * pas) laisse l'inventaire rafraîchir à chaque synchronisation, comme avant la 1.14 :
 * plus lourd, jamais faux.
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
	 * Chemins EXACTS des trois vérifications (wp-includes/update.php, WordPress 6.9.4), par
	 * vérification. Une autre version de l'API ne compte pas : sa réponse n'est pas notée
	 * et l'inventaire rafraîchit à chaque synchronisation (plus lourd, jamais faux) jusqu'à
	 * une version du connecteur qui la connaît.
	 */
	private const CHECK_PATHS = [
		'core'    => '/core/version-check/1.7/',
		'plugins' => '/plugins/update-check/1.1/',
		'themes'  => '/themes/update-check/1.1/',
	];

	/** Schémas des trois adresses : https://, et http:// pour le repli de WordPress. */
	private const SCHEMES = [ 'https', 'http' ];

	/**
	 * Clé que le corps JSON doit porter pour que la réponse compte, par vérification : les
	 * données que WordPress range dans le transient (wp-includes/update.php).
	 */
	private const ANSWER_KEYS = [
		'core'    => 'offers',
		'plugins' => 'plugins',
		'themes'  => 'themes',
	];

	/** Transient de site écrit par chaque vérification (wp-includes/update.php). */
	private const TRANSIENTS = [
		'update_core'    => 'core',
		'update_plugins' => 'plugins',
		'update_themes'  => 'themes',
	];

	/**
	 * Préfixe de l'option qui range un transient de site sans cache objet persistant
	 * (set_site_transient(), wp-includes/option.php).
	 */
	private const TRANSIENT_OPTION_PREFIX = '_site_transient_';

	/**
	 * Vérifications amorcées : le transient a été écrit sans réponse en attente
	 * (l'écriture d'avant l'appel). État de la requête PHP, jamais persisté.
	 *
	 * @var array<string, true>
	 */
	private static array $primed = [];

	/**
	 * Réponses reçues après l'amorce, en attente de l'écriture du transient : heure de
	 * réception, par vérification.
	 *
	 * @var array<string, int>
	 */
	private static array $pending = [];

	/**
	 * Réponses liées à l'écriture du transient en cours (entre le filtre
	 * `pre_set_site_transient_{$transient}` et son aboutissement) : heure de réception.
	 *
	 * @var array<string, int>
	 */
	private static array $writing = [];

	public static function register(): void {
		add_action( 'http_api_debug', [ self::class, 'record' ], 10, 5 );
		add_filter( 'http_response', [ self::class, 'confirm_response' ], PHP_INT_MAX, 3 );
		// En dernier : la valeur vue est celle que WordPress utilise, après le proxy
		// éventuel (mu-plugin Hostinger, priorité 10) et tout autre filtre.
		add_filter( 'pre_http_request', [ self::class, 'observe_preempted' ], PHP_INT_MAX, 3 );
		foreach ( array_keys( self::TRANSIENTS ) as $transient ) {
			// En dernier : les updaters tiers branchés sur ces filtres (et leurs propres
			// requêtes HTTP) passent avant.
			add_filter( 'pre_set_site_transient_' . $transient, [ self::class, 'before_write' ], PHP_INT_MAX, 2 );
			add_filter( 'pre_update_site_option_' . self::TRANSIENT_OPTION_PREFIX . $transient, [ self::class, 'before_option_update' ], PHP_INT_MAX, 3 );
			add_action( 'set_site_transient_' . $transient, [ self::class, 'after_write' ], 10, 3 );
		}
	}

	/**
	 * Met en attente une réponse de wordpress.org à l'une des trois vérifications
	 * (cf. en-tête de la classe, étape 2). Rien n'est écrit ici.
	 *
	 * Appelée après CHAQUE requête HTTP du site : tout ce qui n'est pas une vérification
	 * de wordpress.org sort à la première comparaison de chaîne.
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
		if ( 'response' !== $context || ! is_string( $url ) ) {
			return;
		}
		$check = self::check_of( $url );
		if ( null !== $check ) {
			self::receive( $check, $url, $response );
		}
	}

	/**
	 * Filtre `pre_http_request`, en dernier : requête court-circuitée (cf. en-tête de la
	 * classe, « Requête court-circuitée »). La valeur reçue est la valeur finale du filtre,
	 * que WP_Http::request() rend telle quelle à WordPress, sans `http_api_debug` ni
	 * `http_response` pour cette adresse. Si l'adresse d'ORIGINE est l'une des trois
	 * vérifications, elle compte comme la réponse reçue, quel que soit l'hôte qui l'a
	 * servie (proxy Hostinger, par exemple) : mise en attente comme à `http_api_debug`, et
	 * confirmée au même moment, puisqu'aucun `http_response` ne suit. Rien n'est écrit ici.
	 *
	 * Appelé avant CHAQUE requête HTTP du site : sans court-circuit (false, le cas courant),
	 * il sort à la première comparaison. Valeur rendue telle quelle, jamais modifiée.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $response Valeur finale du filtre : false (pas de court-circuit), tableau
	 *                        de réponse, WP_Error ou autre.
	 * @param mixed $args     Arguments de la requête (non utilisés).
	 * @param mixed $url      Adresse d'origine de la requête.
	 * @return mixed
	 */
	public static function observe_preempted( $response, $args = [], $url = '' ) {
		unset( $args );
		if ( false === $response || ! is_string( $url ) ) {
			return $response;
		}
		$check = self::check_of( $url );
		if ( null !== $check ) {
			self::receive( $check, $url, $response );
			self::confirm( $check, $response );
		}

		return $response;
	}

	/**
	 * Filtre `http_response`, en dernier : la réponse telle que WordPress la lira
	 * (WP_Http::request() l'applique juste après `http_api_debug`, à toute réponse sans
	 * erreur). Transformée par un autre filtre en autre chose qu'un 200 porteur des
	 * données, WordPress n'écrira pas le transient : la réponse n'est plus en attente
	 * (cf. en-tête de la classe, étape 2). Valeur rendue telle quelle.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $response Réponse lue par WordPress.
	 * @param mixed $args     Arguments de la requête (non utilisés).
	 * @param mixed $url      Adresse appelée.
	 * @return mixed
	 */
	public static function confirm_response( $response, $args = [], $url = '' ) {
		unset( $args );
		if ( [] === self::$pending || ! is_string( $url ) ) {
			return $response;
		}
		$check = self::check_of( $url );
		if ( null !== $check ) {
			self::confirm( $check, $response );
		}

		return $response;
	}

	/**
	 * Filtre `pre_set_site_transient_{$transient}`, en dernier : WordPress va écrire le
	 * transient. La réponse en attente est liée à cette écriture et à elle seule ; sans
	 * réponse en attente, c'est l'écriture d'avant l'appel, qui amorce la vérification.
	 * Valeur rendue telle quelle.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $value     Valeur du transient.
	 * @param mixed $transient Nom du transient.
	 * @return mixed
	 */
	public static function before_write( $value, $transient = '' ) {
		$check = self::check_of_transient( $transient );
		if ( null === $check ) {
			return $value;
		}
		unset( self::$writing[ $check ], self::$primed[ $check ] );
		if ( isset( self::$pending[ $check ] ) ) {
			self::$writing[ $check ] = self::$pending[ $check ];
			unset( self::$pending[ $check ] );
		} else {
			self::$primed[ $check ] = true;
		}

		return $value;
	}

	/**
	 * Filtre `pre_update_site_option__site_transient_{$transient}`, en dernier : valeur
	 * identique à celle en base, update_network_option() s'arrête juste après, sans écrire
	 * ni déclencher `set_site_transient_{$transient}`. La base porte alors exactement la
	 * réponse liée : notée. Valeur rendue telle quelle.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $value     Nouvelle valeur.
	 * @param mixed $old_value Valeur en base.
	 * @param mixed $option    Nom de l'option (`_site_transient_…`).
	 * @return mixed
	 */
	public static function before_option_update( $value, $old_value = false, $option = '' ) {
		$check = is_string( $option ) && str_starts_with( $option, self::TRANSIENT_OPTION_PREFIX )
			? self::check_of_transient( substr( $option, strlen( self::TRANSIENT_OPTION_PREFIX ) ) )
			: null;
		if ( null === $check || ! isset( self::$writing[ $check ] ) ) {
			return $value;
		}
		// Même comparaison que update_network_option() (wp-includes/option.php).
		if ( $value === $old_value || maybe_serialize( $value ) === maybe_serialize( $old_value ) ) {
			self::store( $check );
		}

		return $value;
	}

	/**
	 * Action `set_site_transient_{$transient}` : le transient est écrit, la réponse liée à
	 * cette écriture est notée.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $value      Valeur écrite (non utilisée).
	 * @param mixed $expiration Durée (non utilisée).
	 * @param mixed $transient  Nom du transient.
	 */
	public static function after_write( $value, $expiration = 0, $transient = '' ): void {
		unset( $value, $expiration );
		$check = self::check_of_transient( $transient );
		if ( null !== $check && isset( self::$writing[ $check ] ) ) {
			self::store( $check );
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
	 * Réponse reçue à une vérification, par `http_api_debug` ou comme valeur finale de
	 * `pre_http_request` (cf. en-tête de la classe, étape 2) : mise en attente seulement
	 * si elle compte et que la vérification est amorcée. Rien n'est écrit ici.
	 *
	 * @param string $check    'core' | 'plugins' | 'themes'.
	 * @param string $url      Adresse d'origine (le schéma dit si c'était le dernier essai).
	 * @param mixed  $response Réponse (tableau), WP_Error ou autre valeur d'un filtre.
	 */
	private static function receive( string $check, string $url, $response ): void {
		if ( $response instanceof \WP_Error ) {
			// Erreur réseau. En https://, l'amorce reste : WordPress retente en http://
			// (`$ssl && is_wp_error()`, wp-includes/update.php). En http://, c'était son
			// dernier essai (repli, ou essai unique sans SSL) : il s'arrête sans rien
			// écrire, l'amorce tombe.
			if ( 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
				unset( self::$primed[ $check ] );
			}
			return;
		}
		if ( ! self::is_answer( $check, $response ) ) {
			// WordPress s'arrête (code autre que 200, valeur d'un filtre qui n'est ni une
			// réponse ni une erreur), ou écrit un transient vide : rien à lier.
			unset( self::$primed[ $check ] );
			return;
		}
		if ( ! isset( self::$primed[ $check ] ) ) {
			// Aucune écriture du transient avant cet appel : pas une vérification de
			// WordPress (autre extension) — ou réponse déjà mise en attente par une
			// requête imbriquée vers la même adresse.
			return;
		}
		unset( self::$primed[ $check ] );
		self::$pending[ $check ] = time();
	}

	/**
	 * Réponse telle que WordPress la lit : autre chose qu'un 200 porteur des données,
	 * WordPress n'écrira pas le transient, la réponse en attente tombe (cf. en-tête de la
	 * classe, étape 2).
	 *
	 * @param string $check    'core' | 'plugins' | 'themes'.
	 * @param mixed  $response Réponse lue par WordPress.
	 */
	private static function confirm( string $check, $response ): void {
		if ( isset( self::$pending[ $check ] ) && ! self::is_answer( $check, $response ) ) {
			unset( self::$pending[ $check ] );
		}
	}

	/**
	 * La réponse compte pour cette vérification : un tableau de réponse au code 200, dont
	 * le corps JSON porte la clé de la vérification (ANSWER_KEYS).
	 *
	 * @param string $check    'core' | 'plugins' | 'themes'.
	 * @param mixed  $response Réponse.
	 */
	private static function is_answer( string $check, $response ): bool {
		return is_array( $response ) && self::is_ok( $response ) && self::carries( $response, self::ANSWER_KEYS[ $check ] );
	}

	/**
	 * Écrit en base l'heure de la réponse liée à l'écriture du transient. Best-effort : une
	 * écriture impossible ne gêne jamais WordPress (l'inventaire rafraîchira alors).
	 *
	 * @param string $check 'core' | 'plugins' | 'themes'.
	 */
	private static function store( string $check ): void {
		$at = self::$writing[ $check ];
		unset( self::$writing[ $check ] );
		try {
			$answers           = self::all();
			$answers[ $check ] = $at;
			update_site_option( self::OPTION, $answers );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Vérification dont c'est le transient, ou null.
	 *
	 * @param mixed $transient Nom du transient.
	 */
	private static function check_of_transient( $transient ): ?string {
		return is_string( $transient ) && isset( self::TRANSIENTS[ $transient ] ) ? self::TRANSIENTS[ $transient ] : null;
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

	/**
	 * Vérification visée par l'adresse, ou null. Seules comptent les adresses que
	 * WordPress appelle : schéma http ou https, hôte api.wordpress.org, ni port ni
	 * identifiants, chemin exactement celui d'une vérification (CHECK_PATHS). La requête
	 * (`?version=…` du cœur) n'entre pas en compte. L'adresse d'un proxy (autre hôte) ne
	 * compte jamais.
	 *
	 * Appelée pour chaque requête HTTP du site : une adresse qui ne contient pas
	 * api.wordpress.org sort à la première comparaison de chaîne.
	 */
	private static function check_of( string $url ): ?string {
		if ( false === stripos( $url, self::HOST ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ?? '' ), self::SCHEMES, true ) || self::HOST !== strtolower( $parts['host'] ?? '' ) ) {
			return null;
		}
		$check = array_search( $parts['path'] ?? '', self::CHECK_PATHS, true );

		return is_string( $check ) ? $check : null;
	}

	/**
	 * Code 200 exactement, comme wp-includes/update.php : `200 !==
	 * wp_remote_retrieve_response_code( … )`, qui rend le code tel quel
	 * (wp-includes/http.php). Un « 200 » en chaîne, qu'un filtre peut rendre, arrête
	 * WordPress sans écrire le transient. Les réponses de WP_Http::request() portent un
	 * entier.
	 *
	 * @param array<mixed> $response Réponse.
	 */
	private static function is_ok( array $response ): bool {
		$status = $response['response'] ?? null;

		return is_array( $status ) && 200 === ( $status['code'] ?? null );
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
