<?php
/**
 * État persistant de la vérification de signature : nonces déjà vus (protection
 * contre le rejeu) et compteur d'échecs (diagnostic remonté au manager).
 *
 * Stocké dans une OPTION dédiée, sans autoload — pas un transient : sur un site à
 * cache objet persistant, un transient peut être évincé avant son terme, ce qui
 * rouvrirait la fenêtre de rejeu (même piège que `g2rd_updates_snapshot`).
 *
 * Limite connue : deux requêtes strictement simultanées peuvent se marcher dessus
 * (lecture-modification-écriture de l'option). La fenêtre est de quelques
 * millisecondes et le Bearer reste exigé ; on ne pose pas de verrou pour si peu.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

final class SignatureState {

	public const OPTION_KEY = 'g2rd_connector_signature_state';

	/** Durée de rétention d'un nonce : deux fois la fenêtre d'horodatage acceptée. */
	public const NONCE_TTL_SECONDS = 600;

	/** Borne la taille de l'option quoi qu'il arrive. */
	public const MAX_NONCES = 500;

	/**
	 * Mémorise un nonce. Retourne false s'il a déjà été vu (rejeu).
	 */
	public static function remember_nonce( string $nonce, int $now ): bool {
		$state  = self::read();
		$nonces = self::prune( $state['nonces'], $now );

		if ( isset( $nonces[ $nonce ] ) ) {
			return false;
		}

		$nonces[ $nonce ] = $now;
		if ( count( $nonces ) > self::MAX_NONCES ) {
			// Les plus anciens sortent d'abord.
			asort( $nonces );
			$nonces = array_slice( $nonces, -self::MAX_NONCES, null, true );
		}

		$state['nonces'] = $nonces;
		self::write( $state );
		return true;
	}

	/**
	 * Compte un échec de vérification (mode rapport : la requête est acceptée,
	 * mais l'anomalie ne doit pas rester invisible).
	 */
	public static function record_failure( string $code, int $now ): void {
		$state                            = self::read();
		$state['stats']['failed_count']   = (int) $state['stats']['failed_count'] + 1;
		$state['stats']['last_failed_at'] = $now;
		$state['stats']['last_code']      = $code;
		self::write( $state );
	}

	/**
	 * @return array{failed_count:int,last_failed_at:int|null,last_code:string|null}
	 */
	public static function stats(): array {
		return self::read()['stats'];
	}

	/**
	 * @param array<string, int> $nonces
	 * @return array<string, int>
	 */
	private static function prune( array $nonces, int $now ): array {
		$threshold = $now - self::NONCE_TTL_SECONDS;
		return array_filter(
			$nonces,
			static fn ( int $seen_at ): bool => $seen_at >= $threshold
		);
	}

	/**
	 * @return array{nonces:array<string,int>,stats:array{failed_count:int,last_failed_at:int|null,last_code:string|null}}
	 */
	private static function read(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$nonces = [];
		if ( isset( $stored['nonces'] ) && is_array( $stored['nonces'] ) ) {
			foreach ( $stored['nonces'] as $nonce => $seen_at ) {
				$nonces[ (string) $nonce ] = (int) $seen_at;
			}
		}

		$stats = isset( $stored['stats'] ) && is_array( $stored['stats'] ) ? $stored['stats'] : [];

		return [
			'nonces' => $nonces,
			'stats'  => [
				'failed_count'   => isset( $stats['failed_count'] ) ? (int) $stats['failed_count'] : 0,
				'last_failed_at' => isset( $stats['last_failed_at'] ) ? (int) $stats['last_failed_at'] : null,
				'last_code'      => isset( $stats['last_code'] ) ? (string) $stats['last_code'] : null,
			],
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function write( array $state ): void {
		update_option( self::OPTION_KEY, $state, false );
	}
}
