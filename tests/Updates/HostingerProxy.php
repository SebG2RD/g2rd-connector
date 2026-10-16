<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Updates;

use G2RD\Connector\Updates\WporgAnswers;

/**
 * Le mu-plugin Hostinger `wp-content/mu-plugins/hostinger-auto-updates.php`, présent sur les
 * 30 sites WordPress du compte d'hébergement (relevé sur g2rd.fr le 16/10/2026, WordPress
 * 7.1.3), et WP_Http::request() autour de lui.
 *
 * Le mu-plugin branche hostinger_use_proxy_services() sur le filtre `pre_http_request`
 * (priorité 10) : une requête vers api.wordpress.org est refaite vers son proxy
 * (`HOSTINGER_API_PROXY_HOST`, wpapi.hostinger.io par défaut) par wp_remote_request()
 * (hostinger_fetch_api()), et la réponse du proxy devient la valeur du filtre. WP_Http::request()
 * (wp-includes/class-wp-http.php, WordPress 6.9.4 et 7.1.3) la rend alors telle quelle
 * (`if ( false !== $pre ) { return $pre; }`), sans `http_api_debug` ni `http_response` pour
 * l'adresse d'origine : seule la requête imbriquée vers le proxy les déclenche.
 *
 * Seul hostinger_fetch_api() est reproduit : hostinger_fetch_package() (downloads.wordpress.org)
 * ne sert pas aux vérifications des mises à jour.
 */
final class HostingerProxy {

	/** Hôte du proxy, comme `HOSTINGER_API_PROXY_HOST` (wpapi.hostinger.io par défaut). */
	public static string $proxy_host = 'wpapi.hostinger.io';

	/** @var list<string> Adresses réellement demandées au réseau, dans l'ordre. */
	public static array $network = [];

	/** `static $in_flight` de hostinger_use_proxy_services(). */
	private static bool $in_flight = false;

	/** État de départ : proxy par défaut, aucune requête faite. */
	public static function reset(): void {
		self::$proxy_host = 'wpapi.hostinger.io';
		self::$network    = [];
		self::$in_flight  = false;
	}

	/**
	 * WP_Http::request() sur un site qui a le mu-plugin : filtre `pre_http_request` (le
	 * mu-plugin à 10, WporgAnswers en dernier) ; sans court-circuit, requête réseau, puis
	 * `http_api_debug` et `http_response`.
	 *
	 * @param callable(string): mixed $remote Réponse du réseau (tableau ou WP_Error), par adresse demandée.
	 * @return mixed Ce que WordPress reçoit.
	 */
	public static function request( string $url, callable $remote ): mixed {
		$pre = self::use_proxy_services( false, $url, $remote );
		$pre = WporgAnswers::observe_preempted( $pre, [], $url );
		if ( false !== $pre ) {
			return $pre;
		}

		self::$network[] = $url;
		$response        = $remote( $url );
		WporgAnswers::record( $response, 'response', 'WpOrg\Requests\Requests', [], $url );

		return WporgAnswers::confirm_response( $response, [], $url );
	}

	/**
	 * hostinger_use_proxy_services() et hostinger_fetch_api() : une requête vers
	 * api.wordpress.org est refaite vers le proxy, dans une requête imbriquée.
	 *
	 * @param callable(string): mixed $remote Réponse du réseau, par adresse demandée.
	 */
	private static function use_proxy_services( mixed $response_override, string $url, callable $remote ): mixed {
		if ( self::$in_flight ) {
			return $response_override;
		}
		if ( 'api.wordpress.org' !== strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) ) {
			return $response_override;
		}
		self::$in_flight = true;
		try {
			return self::request( str_replace( 'api.wordpress.org', self::$proxy_host, $url ), $remote );
		} finally {
			self::$in_flight = false;
		}
	}
}
