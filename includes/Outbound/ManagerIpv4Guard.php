<?php
/**
 * Force l'IPv4 pour les appels HTTP sortants VERS LE MANAGER, et seulement eux.
 *
 * Le 2026-09-25, le CDN de l'hébergeur (hcdn) a renvoyé 403 à l'adresse IPv6
 * sortante du serveur pour tous les domaines : battements de cœur, événements et
 * enrôlement mouraient en route, alors que le site répondait parfaitement à ses
 * visiteurs et que la même requête en IPv4 passait. Aucun réenrôlement ne pouvait
 * y changer quoi que ce soit — la requête n'atteignait jamais le manager.
 *
 * Le remède est celui appliqué côté plateforme (`HTTP_CLIENT_BINDTO`) : choisir la
 * famille d'adresses. Ici via le hook prévu par WordPress pour ajuster cURL, en
 * ne touchant qu'aux requêtes dont l'hôte est celui du manager : rien ne change
 * pour wordpress.org, les webhooks tiers ou les loopbacks du site.
 *
 * Opt-in (`force_ipv4_to_manager`, faux par défaut). Remplace le mu-plugin posé à
 * la main sur g2rd.fr le 25/09, qui ne suivait pas les mises à jour du connecteur.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Outbound;

use G2RD\Connector\Settings;

final class ManagerIpv4Guard {

	public function register(): void {
		add_action( 'http_api_curl', [ $this, 'apply' ], 10, 3 );
	}

	/**
	 * Callback de `http_api_curl`. `$handle` est le CurlHandle de la requête en
	 * cours ; WordPress le passe par référence, la modification est donc en place.
	 *
	 * @param mixed                $handle      CurlHandle (PHP 8) de la requête.
	 * @param array<string, mixed> $parsed_args Arguments WP_Http (non utilisés).
	 * @param mixed                $url         URL de la requête.
	 */
	public function apply( $handle, $parsed_args, $url ): void {
		unset( $parsed_args );

		if ( ! is_string( $url ) || ! defined( 'CURL_IPRESOLVE_V4' ) || ! ( $handle instanceof \CurlHandle ) ) {
			return;
		}
		if ( ! self::applies_to( $url, (string) Settings::get( 'manager_url' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- le hook http_api_curl existe précisément pour ajuster le handle cURL de WP_Http.
		curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
	}

	/**
	 * La requête vise-t-elle le manager ? Comparaison d'hôtes, insensible à la
	 * casse, indépendante du schéma, du port et du chemin. Un manager non configuré
	 * (hôte vide) ne fait rien : la garde ne peut pas s'appliquer « à tout ».
	 */
	public static function applies_to( string $url, string $manager_url ): bool {
		$target  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$manager = strtolower( (string) wp_parse_url( $manager_url, PHP_URL_HOST ) );

		return '' !== $manager && $target === $manager;
	}
}
