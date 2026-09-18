<?php
/**
 * Job WP-Cron horaire : entretien local des points de restauration.
 *
 * Déclenché par le site lui-même, jamais par la plateforme : la purge doit
 * fonctionner même si le site n'est plus joignable ou plus enrôlé. À chaque
 * passage :
 *   1. reprise d'une transaction de mise à jour restée ouverte (requête morte) ;
 *   2. suppression des points dont le délai de grâce est écoulé ;
 *   3. suppression des points retenus (`hold`) au-delà de leur plafond ;
 *   4. plafond absolu par plugin ;
 *   5. zips orphelins et entrées sans zip ;
 *   6. résultats en attente trop anciens.
 *
 * Ne supprime que ce que le module a créé (cf RestorePointStore::remove).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Cron;

use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\RestorePointStore;

final class RestorePointPurgeJob {

	public const HOOK = 'g2rd_connector_restore_points_purge';

	/** Plafond par défaut d'un point retenu (`hold`) sans autre indication : 7 jours. */
	public const DEFAULT_HOLD_MAX_SECONDS = 7 * 86400;

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		self::purge( new RestorePointStore(), time() );
	}

	/**
	 * @return array{expired:int, held:int, capped:int, orphans:array{files:int,records:int}, outcomes:int}
	 */
	public static function purge( RestorePointStore $store, int $now ): array {
		ProtectedUpdate::recover( $now );

		$expired = 0;
		$held    = 0;
		foreach ( $store->all() as $id => $record ) {
			if ( ! empty( $record['hold'] ) ) {
				$until = isset( $record['hold_until'] ) ? (int) $record['hold_until'] : (int) $record['created_at'] + self::DEFAULT_HOLD_MAX_SECONDS;
				if ( $until <= $now && $store->remove( $id ) ) {
					++$held;
				}
				continue;
			}
			$expires_at = $record['expires_at'] ?? null;
			if ( null !== $expires_at && (int) $expires_at <= $now && $store->remove( $id ) ) {
				++$expired;
			}
		}

		$capped = 0;
		foreach ( array_unique( array_column( $store->all(), 'plugin_file' ) ) as $plugin_file ) {
			$capped += count( $store->enforce_per_plugin_cap( (string) $plugin_file ) );
		}

		return [
			'expired'  => $expired,
			'held'     => $held,
			'capped'   => $capped,
			'orphans'  => $store->prune_orphans(),
			'outcomes' => PendingOutcomes::prune( $now ),
		];
	}

	/**
	 * Planification réparée à chaque démarrage (comme UpdatesDiscoveryJob) : les
	 * sites déjà installés ne rejouent jamais le hook d'activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
