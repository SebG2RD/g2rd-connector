<?php
/**
 * Les six contrôles de la connexion directe, dans l'ordre de la spec (§5.5) :
 *   1. forme et signature du ticket (comparaison en temps constant) ;
 *   2. identifiant de site égal à celui reçu à l'enrôlement ;
 *   3. expiration (+30 s de tolérance d'horloge, 90 s d'avance au plus) ;
 *   4. usage unique (UsedTickets) ;
 *   5. case « Autoriser la connexion directe depuis G2RD » cochée (absente = cochée) ;
 *   6. le compte existe et a toujours `manage_options` (compte rétrogradé entre-temps).
 * Les trois premiers sont faits par la classe pure Security\DirectLoginTicket.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\DirectLogin;

use G2RD\Connector\Security\DirectLoginTicket;
use G2RD\Connector\Settings;

final class Gate {

	/** Capacité exigée du compte ouvert, vérifiée au moment de l'usage. */
	public const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * `status` vaut `ok` (avec `user` et `payload`) ou `refused` (avec `code`, voir Refusal).
	 *
	 * @param string $ticket Ticket brut reçu dans l'adresse.
	 * @param int    $now    Heure courante du serveur (secondes Unix).
	 * @return array{status: string, code?: string, user?: \WP_User, payload?: array{s: int, u: int, e: int, n: string, a: int}}
	 */
	public function check( string $ticket, int $now ): array {
		$verdict = DirectLoginTicket::verify( $ticket, Settings::site_token(), (int) Settings::get( 'site_id' ), $now );
		if ( ! isset( $verdict['payload'] ) ) {
			return self::refused( Refusal::from_ticket_status( $verdict['status'] ) );
		}
		$payload = $verdict['payload'];

		if ( ! UsedTickets::claim( $payload['n'], $now ) ) {
			return self::refused( Refusal::REPLAYED );
		}
		if ( ! Settings::get( 'allow_direct_login' ) ) {
			return self::refused( Refusal::DISABLED );
		}

		$user = get_userdata( $payload['u'] );
		if ( ! $user instanceof \WP_User || ! user_can( $user, self::REQUIRED_CAPABILITY ) ) {
			return self::refused( Refusal::NOT_ADMIN );
		}

		return [
			'status'  => 'ok',
			'user'    => $user,
			'payload' => $payload,
		];
	}

	/**
	 * @return array{status: string, code: string}
	 */
	private static function refused( string $code ): array {
		return [
			'status' => 'refused',
			'code'   => $code,
		];
	}
}
