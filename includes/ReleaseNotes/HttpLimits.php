<?php
/**
 * Bornes des appels réseau faits pendant la commande `get_release_notes` (1.18.0) : 15 s pour
 * l'ensemble des appels, redirections comprises (spécification « Notes de version », § 5.3), 3
 * redirections au plus par appel, 8 Mio gardés par réponse, adresses sûres seulement, quel que
 * soit le code qui appelle (WordPress vers api.wordpress.org, ou l'updater d'un éditeur vers son
 * serveur, tant qu'il passe par l'API HTTP de WordPress). Le budget est mesuré sur une horloge
 * monotone (hrtime()) et ne couvre que ces appels : le travail hors réseau de la commande n'y est
 * pas compté. Les 8 Mio (`limit_response_size`, `max_bytes` de Requests) bornent ce que le
 * transport garde du corps, pas ce qu'il lit : la suite est lue et jetée jusqu'à la fin de la
 * réponse ou jusqu'au délai. Avec cURL, ce sont les octets décompressés (cURL décompresse
 * lui-même) ; avec fsockopen, les octets reçus, décompressés ensuite par Requests : un corps
 * compressé peut alors dépasser 8 Mio une fois décompressé. Trois crochets, posés au début de la
 * commande et retirés à la fin, même sur une exception :
 *  - filtre `http_request_args`, en dernier (PHP_INT_MAX) : délai de chaque appel ramené au temps
 *    qui reste (une seconde au moins), taille gardée de la réponse bornée, redirections plafonnées,
 *    `reject_unsafe_urls` (WordPress refuse alors les adresses non publiques au sens de sa version,
 *    les ports autres que 80, 443 et 8080, les hôtes non résolus par gethostbyname(), y compris
 *    après une redirection) ;
 *  - filtre `pre_http_request`, en dernier : budget épuisé (moins d'une seconde), plus aucun appel ;
 *  - action `requests-requests.before_redirect` (relais de WP_HTTP_Requests_Hooks : Requests suit
 *    les redirections lui-même, sans repasser par les deux filtres) : chaque saut reçoit le temps
 *    qui reste ; budget épuisé, la redirection est détournée vers une adresse non HTTP
 *    (BUDGET_SPENT_LOCATION), que Requests::request() refuse lui-même (`nonhttp`) après la fin du
 *    crochet, et que WordPress rend en WP_Error. Jamais d'exception dans l'action :
 *    do_action_ref_array() ne retirerait pas son nom de $wp_current_filter.
 *
 * Limites connues : un updater qui appelle cURL lui-même, ou un site dont le transport HTTP est
 * fsockopen (délai par lecture, pas de délai total), échappe à ces bornes ; la résolution DNS de
 * wp_http_validate_url() n'est pas bornée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\ReleaseNotes;

final class HttpLimits {

	/** Budget de la commande, en secondes. */
	public const BUDGET_SECONDS = 15;

	/** Taille gardée d'une réponse, en octets (8 Mio) : cf. docblock de la classe pour ce qui est compté. */
	public const RESPONSE_MAX_BYTES = 8388608;

	/** Redirections suivies au plus par appel (5 par défaut dans WordPress). */
	public const MAX_REDIRECTS = 3;

	/** Adresse non HTTP donnée à une redirection quand le budget est épuisé : Requests la refuse (`nonhttp`). */
	public const BUDGET_SPENT_LOCATION = 'g2rd-time-budget-spent:';

	/** Délai minimal laissé à un appel, en secondes ; en dessous, le budget est épuisé. */
	private const MIN_SECONDS = 1.0;

	/** @var \Closure(): float */
	private \Closure $clock;

	private float $deadline = 0.0;

	/**
	 * @param (\Closure(): float)|null $clock Horloge en secondes (tests) ; hrtime() sinon, monotone : un
	 *                                       recalage de l'heure du système (NTP) ne rend ni ne retire
	 *                                       de temps au budget.
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): float => hrtime( true ) / 1e9;
	}

	/**
	 * Exécute $work entre la pose et le retrait des trois crochets.
	 *
	 * @param \Closure(): mixed $work
	 * @return mixed Ce que rend $work.
	 */
	public function around( \Closure $work ) {
		$this->deadline = ( $this->clock )() + self::BUDGET_SECONDS;
		add_filter( 'http_request_args', [ $this, 'bound' ], PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', [ $this, 'stop_when_spent' ], PHP_INT_MAX, 3 );
		add_action( 'requests-requests.before_redirect', [ $this, 'before_redirect' ], PHP_INT_MAX, 5 );
		try {
			return $work();
		} finally {
			remove_filter( 'http_request_args', [ $this, 'bound' ], PHP_INT_MAX );
			remove_filter( 'pre_http_request', [ $this, 'stop_when_spent' ], PHP_INT_MAX );
			remove_action( 'requests-requests.before_redirect', [ $this, 'before_redirect' ], PHP_INT_MAX );
		}
	}

	/**
	 * Filtre `http_request_args`.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $args Arguments de la requête.
	 * @param mixed $url  Adresse (non utilisée).
	 * @return mixed
	 */
	public function bound( $args, $url = '' ) {
		unset( $url );
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$left    = max( self::MIN_SECONDS, $this->left() );
		$timeout = isset( $args['timeout'] ) && is_numeric( $args['timeout'] ) ? (float) $args['timeout'] : 0.0;
		// 0 ou moins : sans limite pour cURL ; le temps qui reste s'applique.
		$args['timeout'] = $timeout > 0 ? min( $timeout, $left ) : $left;

		$limit                       = isset( $args['limit_response_size'] ) && is_numeric( $args['limit_response_size'] ) ? (int) $args['limit_response_size'] : 0;
		$args['limit_response_size'] = $limit > 0 ? min( $limit, self::RESPONSE_MAX_BYTES ) : self::RESPONSE_MAX_BYTES;

		$redirects           = isset( $args['redirection'] ) && is_numeric( $args['redirection'] ) ? (int) $args['redirection'] : self::MAX_REDIRECTS;
		$args['redirection'] = max( 0, min( $redirects, self::MAX_REDIRECTS ) );

		// Défense en profondeur : adresses non publiques, ports autres que 80, 443 et 8080 et
		// redirections vers eux refusés par WordPress (wp_http_validate_url()) pendant la commande.
		$args['reject_unsafe_urls'] = true;

		return $args;
	}

	/**
	 * Filtre `pre_http_request`.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $preempt Réponse déjà fournie par un filtre précédent (false : aucune).
	 * @param mixed $args    Arguments (non utilisés).
	 * @param mixed $url     Adresse (non utilisée).
	 * @return mixed
	 */
	public function stop_when_spent( $preempt, $args = [], $url = '' ) {
		unset( $args, $url );
		if ( false !== $preempt || $this->left() >= self::MIN_SECONDS ) {
			return $preempt;
		}

		return new \WP_Error( 'g2rd_release_notes_time_budget', 'Release notes: time budget spent.' );
	}

	/**
	 * Action `requests-requests.before_redirect` : chaque saut reçoit le temps qui reste. Budget
	 * épuisé : la redirection est détournée vers BUDGET_SPENT_LOCATION, adresse non HTTP que
	 * Requests::request() refuse lui-même (`nonhttp`, set_defaults()) juste après la fin du crochet ;
	 * WordPress le rend en WP_Error (WP_Http::request()). Jamais d'exception ici :
	 * do_action_ref_array() ne retirerait pas le nom de l'action de $wp_current_filter.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $location Adresse de la redirection, par référence : détournée quand le budget est épuisé.
	 * @param mixed $headers  En-têtes (non utilisés).
	 * @param mixed $data     Corps (non utilisé).
	 * @param mixed $options  Options de Requests, par référence : `timeout` ramené au temps qui reste.
	 * @param mixed $response Réponse qui redirige (non utilisée).
	 */
	public function before_redirect( &$location, &$headers, &$data, &$options, $response = null ): void {
		unset( $response );
		$left = $this->left();
		if ( $left < self::MIN_SECONDS ) {
			$location = self::BUDGET_SPENT_LOCATION;
			return;
		}
		if ( ! is_array( $options ) ) {
			return;
		}
		$timeout            = isset( $options['timeout'] ) && is_numeric( $options['timeout'] ) ? (float) $options['timeout'] : 0.0;
		$options['timeout'] = $timeout > 0 ? min( $timeout, $left ) : $left;
	}

	private function left(): float {
		return $this->deadline - ( $this->clock )();
	}
}
