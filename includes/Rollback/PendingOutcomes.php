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
	 * @param array<string, mixed> $outcome plugin_file, restore_point_id, outcome, detail
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
