<?php
/**
 * Ticket de connexion directe émis par G2RD WP Manager (spec connexion WordPress §5.3).
 *
 *   ticket    = "v1." + base64url(charge) + "." + base64url(signature)
 *   charge    = JSON compact {"s": site, "u": compte WordPress, "e": expiration Unix,
 *               "n": nonce hexadécimal de 128 bits, "a": utilisateur du manager}
 *   signature = HMAC-SHA256(clé, "v1\n" + charge en base64url)
 *   clé       = HMAC-SHA256(SiteToken, "g2rd-login-v1"), en binaire
 *
 * Même principe que RequestSignature, avec un CONTEXTE DISTINCT (`g2rd-login-v1`
 * contre `g2rd-sign-v1`) : une signature de commande ne vaut jamais ticket, et
 * inversement. Aucun nouveau secret : renouveler le jeton du site invalide les
 * tickets en vol.
 *
 * Ordre des contrôles, identique au vérificateur de référence du manager : forme,
 * signature (temps constant), charge, site, expiration (+30 s), avance (90 s au plus).
 * L'usage unique, la case du réglage et le compte sont contrôlés ensuite par
 * DirectLogin\Gate, qui a besoin de WordPress.
 *
 * Cette classe est PURE (aucun appel WordPress) : les vecteurs du manager
 * (tests/fixtures/direct-login-ticket-vectors.json) y sont rejoués à l'identique.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

final class DirectLoginTicket {

	public const VERSION     = 'v1';
	public const KEY_CONTEXT = 'g2rd-login-v1';

	/** Tolérance d'horloge après l'expiration, et avance maximale acceptée (spec §5.5). */
	public const CLOCK_TOLERANCE_SECONDS = 30;
	public const MAX_FUTURE_SECONDS      = 90;

	/** Un ticket fait environ 200 caractères : au-delà, inutile de calculer quoi que ce soit. */
	public const MAX_LENGTH = 1024;

	public const STATUS_OK            = 'ok';
	public const STATUS_MALFORMED     = 'malformed';
	public const STATUS_BAD_SIGNATURE = 'bad_signature';
	public const STATUS_WRONG_SITE    = 'wrong_site';
	public const STATUS_EXPIRED       = 'expired';
	public const STATUS_FUTURE        = 'future';

	/**
	 * Clé de signature (32 octets bruts) dérivée du SiteToken.
	 */
	public static function derive_key( string $site_token ): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, $site_token, true );
	}

	/**
	 * Signature base64url d'une charge déjà encodée en base64url.
	 */
	public static function signature( string $site_token, string $charge ): string {
		return self::base64url( hash_hmac( 'sha256', self::VERSION . "\n" . $charge, self::derive_key( $site_token ), true ) );
	}

	/**
	 * Vérifie un ticket. La charge n'est rendue que pour un ticket accepté.
	 *
	 * @param string $ticket     Ticket brut reçu dans l'adresse.
	 * @param string $site_token SiteToken en clair ('' si le site n'est pas enrôlé).
	 * @param int    $site_id    Identifiant reçu à l'enrôlement.
	 * @param int    $now        Heure courante du serveur (secondes Unix).
	 * @return array{status: string, payload?: array{s: int, u: int, e: int, n: string, a: int}}
	 */
	public static function verify( string $ticket, string $site_token, int $site_id, int $now ): array {
		if ( strlen( $ticket ) > self::MAX_LENGTH || 1 !== preg_match( '/^v1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/D', $ticket, $parts ) ) {
			return [ 'status' => self::STATUS_MALFORMED ];
		}

		// Sans jeton, la clé serait calculable par n'importe qui : refus, avant tout calcul.
		if ( '' === $site_token || ! hash_equals( self::signature( $site_token, $parts[1] ), $parts[2] ) ) {
			return [ 'status' => self::STATUS_BAD_SIGNATURE ];
		}

		$payload = self::decode_payload( $parts[1] );
		if ( null === $payload ) {
			return [ 'status' => self::STATUS_MALFORMED ];
		}
		if ( $payload['s'] !== $site_id ) {
			return [ 'status' => self::STATUS_WRONG_SITE ];
		}
		if ( $now > $payload['e'] + self::CLOCK_TOLERANCE_SECONDS ) {
			return [ 'status' => self::STATUS_EXPIRED ];
		}
		if ( $payload['e'] > $now + self::MAX_FUTURE_SECONDS ) {
			return [ 'status' => self::STATUS_FUTURE ];
		}

		return [
			'status'  => self::STATUS_OK,
			'payload' => $payload,
		];
	}

	/**
	 * Charge complète et bien typée, ou null : s, u, e, a entiers positifs ; n en
	 * hexadécimal minuscule de 32 caractères (il sert aussi de nom d'option).
	 *
	 * @return array{s: int, u: int, e: int, n: string, a: int}|null
	 */
	private static function decode_payload( string $charge ): ?array {
		$json    = base64_decode( strtr( $charge, '-_', '+/' ), true );
		$payload = is_string( $json ) ? json_decode( $json, true, 4 ) : null;
		if ( ! is_array( $payload ) ) {
			return null;
		}
		foreach ( [ 's', 'u', 'e', 'a' ] as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_int( $payload[ $key ] ) || $payload[ $key ] <= 0 ) {
				return null;
			}
		}
		if ( ! isset( $payload['n'] ) || ! is_string( $payload['n'] ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $payload['n'] ) ) {
			return null;
		}

		return [
			's' => (int) $payload['s'],
			'u' => (int) $payload['u'],
			'e' => (int) $payload['e'],
			'n' => (string) $payload['n'],
			'a' => (int) $payload['a'],
		];
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
