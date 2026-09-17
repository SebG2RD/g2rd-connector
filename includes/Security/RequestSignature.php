<?php
/**
 * Signature HMAC des requêtes du manager (en plus du Bearer SiteToken).
 *
 * Le Bearer authentifie ; la signature ajoute la protection contre le rejeu
 * (horodatage + nonce) et lie la preuve à UNE requête précise (méthode, route,
 * corps). La clé est dérivée du SiteToken : aucun ré-enrôlement, aucun nouveau
 * secret à stocker.
 *
 * Chaîne signée (v1), champs séparés par "\n" :
 *   v1, MÉTHODE, route REST, timestamp, nonce, sha256(corps)
 *
 * On signe la ROUTE REST (`/g2rd/v1/command`) et non le chemin de l'URL : une
 * installation en sous-dossier ou en permaliens simples (`?rest_route=`) réécrit
 * le chemin, ce qui ferait échouer la vérification sur des sites pourtant sains.
 *
 * Cette classe est PURE (aucun appel WordPress) : elle se teste sans WordPress,
 * et les mêmes vecteurs (tests/fixtures/signature-vectors.json) sont rejoués
 * côté manager pour garantir que les deux implémentations restent identiques.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

final class RequestSignature {

	public const VERSION        = 'v1';
	public const KEY_CONTEXT    = 'g2rd-sign-v1';
	public const WINDOW_SECONDS = 300;

	public const HEADER_TIMESTAMP = 'x-g2rd-timestamp';
	public const HEADER_NONCE     = 'x-g2rd-nonce';
	public const HEADER_SIGNATURE = 'x-g2rd-signature';

	public const STATUS_OK     = 'ok';
	public const STATUS_ABSENT = 'absent';
	public const STATUS_FAILED = 'failed';

	public const CODE_INVALID  = 'signature_invalid';
	public const CODE_REPLAYED = 'signature_replayed';
	public const CODE_SKEW     = 'clock_skew';

	/**
	 * Clé de signature (32 octets bruts) dérivée du SiteToken.
	 */
	public static function derive_key( string $site_token ): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, $site_token, true );
	}

	/**
	 * Chaîne canonique signée.
	 */
	public static function canonical( string $method, string $route, string $timestamp, string $nonce, string $body ): string {
		return implode(
			"\n",
			[
				self::VERSION,
				strtoupper( $method ),
				$route,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			]
		);
	}

	/**
	 * Signature hexadécimale (sans le préfixe `v1=`).
	 */
	public static function sign( string $site_token, string $method, string $route, string $timestamp, string $nonce, string $body ): string {
		return hash_hmac(
			'sha256',
			self::canonical( $method, $route, $timestamp, $nonce, $body ),
			self::derive_key( $site_token )
		);
	}

	/**
	 * Vérifie les en-têtes de signature d'une requête.
	 *
	 * Ne consulte PAS le registre des nonces : l'appelant le fait seulement quand
	 * le statut est `ok`, pour qu'une signature invalide ne puisse pas remplir le
	 * registre.
	 *
	 * @param string $timestamp Valeur brute de X-G2RD-Timestamp ('' si absent).
	 * @param string $nonce     Valeur brute de X-G2RD-Nonce ('' si absent).
	 * @param string $signature Valeur brute de X-G2RD-Signature ('' si absent).
	 * @param int    $now       Heure courante du serveur (secondes Unix).
	 * @return array{status:string,code?:string,server_time?:int}
	 */
	public static function verify( string $site_token, string $method, string $route, string $body, string $timestamp, string $nonce, string $signature, int $now ): array {
		if ( '' === $timestamp && '' === $nonce && '' === $signature ) {
			return [ 'status' => self::STATUS_ABSENT ];
		}

		// Signature partielle ou mal formée : invalide, sans autre détail.
		if (
			1 !== preg_match( '/^[0-9]{1,12}$/', $timestamp )
			|| 1 !== preg_match( '/^[a-f0-9]{32}$/', $nonce )
			|| 1 !== preg_match( '/^v1=([a-f0-9]{64})$/', $signature, $matches )
		) {
			return self::failed( self::CODE_INVALID );
		}

		$expected = self::sign( $site_token, $method, $route, $timestamp, $nonce, $body );
		if ( ! hash_equals( $expected, $matches[1] ) ) {
			return self::failed( self::CODE_INVALID );
		}

		// Fenêtre vérifiée APRÈS la signature : l'heure du serveur n'est révélée
		// qu'à un appelant qui détient la clé. Elle transforme un site à l'horloge
		// déréglée en diagnostic lisible côté manager.
		if ( abs( $now - (int) $timestamp ) > self::WINDOW_SECONDS ) {
			return [
				'status'      => self::STATUS_FAILED,
				'code'        => self::CODE_SKEW,
				'server_time' => $now,
			];
		}

		return [ 'status' => self::STATUS_OK ];
	}

	/**
	 * @return array{status:string,code:string}
	 */
	public static function failed( string $code ): array {
		return [
			'status' => self::STATUS_FAILED,
			'code'   => $code,
		];
	}
}
