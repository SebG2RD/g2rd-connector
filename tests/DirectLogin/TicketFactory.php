<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use G2RD\Connector\Security\DirectLoginTicket;

/**
 * Fabrique de tickets frais, signés comme le fait le manager (DirectLoginTicketSigner) :
 * les vecteurs communs ont une date fixe, déjà passée à l'heure réelle.
 */
final class TicketFactory {

	public const TOKEN   = 'jeton-du-site-pour-les-tests';
	public const SITE_ID = 42;
	public const NONCE   = '00112233445566778899aabbccddeeff';

	/**
	 * @param int                  $expires_at Expiration (secondes Unix).
	 * @param array<string, mixed> $overrides  Valeurs de charge à remplacer (s, u, n, a).
	 * @param string               $token      Jeton qui signe le ticket.
	 */
	public static function make( int $expires_at, array $overrides = [], string $token = self::TOKEN ): string {
		$payload = array_merge(
			[
				's' => self::SITE_ID,
				'u' => 1,
				'e' => $expires_at,
				'n' => self::NONCE,
				'a' => 7,
			],
			$overrides
		);
		$charge  = rtrim( strtr( base64_encode( (string) json_encode( $payload, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );

		return 'v1.' . $charge . '.' . DirectLoginTicket::signature( $token, $charge );
	}
}
