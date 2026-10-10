<?php
/**
 * Résultats survenus HORS d'une réponse à la plateforme : reprise d'une
 * transaction interrompue, rollback de rattrapage par le cron. Exposés dans
 * l'inventaire, la plateforme les lit à la synchronisation suivante et répare
 * l'item concerné.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class PendingOutcomes {

	public const OPTION_KEY = 'g2rd_pending_outcomes';
	public const MAX        = 20;
	public const TTL        = 7 * 86400;

	/**
	 * @param array<string, mixed> $outcome plugin_file, restore_point_id, step, outcome, detail,
	 *                                      reactivation (ce qui a été fait de l'extension après
	 *                                      un `recovery_failed`, cf. ProtectedUpdate::reactivation()).
	 */
	public static function add( array $outcome, int $now ): void {
		$all   = self::all();
		$all[] = array_merge( $outcome, [ 'at' => $now ] );
		if ( count( $all ) > self::MAX ) {
			$all = array_slice( $all, -self::MAX );
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Complète un résultat déjà consigné (add()) : le plus récent qui est exactement
	 * `$outcome` consigné à `$now`. Sert quand le résultat doit être écrit AVANT une
	 * étape qui peut faire mourir le processus (cf. ProtectedUpdate::recover(), essai de
	 * réactivation), puis précisé une fois l'étape passée.
	 *
	 * @param array<string, mixed> $outcome Tel que passé à add().
	 * @param array<string, mixed> $changes Champs ajoutés ou remplacés.
	 * @return bool Faux si ce résultat n'est plus là (retiré ou poussé dehors entre-temps).
	 */
	public static function complete( array $outcome, int $now, array $changes ): bool {
		$consigned = array_merge( $outcome, [ 'at' => $now ] );
		$all       = self::all();
		for ( $i = count( $all ) - 1; $i >= 0; $i-- ) {
			if ( $all[ $i ] === $consigned ) {
				$all[ $i ] = array_merge( $all[ $i ], $changes );
				update_option( self::OPTION_KEY, $all, false );
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		return array_values( array_filter( $stored, static fn ( $o ): bool => is_array( $o ) && isset( $o['outcome'] ) ) );
	}

	/**
	 * Retire les résultats plus vieux que TTL.
	 */
	public static function prune( int $now ): int {
		$all  = self::all();
		$kept = array_values( array_filter( $all, static fn ( array $o ): bool => ( $now - (int) ( $o['at'] ?? 0 ) ) <= self::TTL ) );
		if ( count( $kept ) !== count( $all ) ) {
			update_option( self::OPTION_KEY, $kept, false );
		}
		return count( $all ) - count( $kept );
	}
}
