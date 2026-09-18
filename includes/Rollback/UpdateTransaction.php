<?php
/**
 * Journal de transaction d'une mise à jour protégée : ce qui est en cours, pour
 * qu'une requête morte en route (délai PHP dépassé, mémoire) puisse être rejouée
 * — par le filet de shutdown d'abord, par le cron ensuite (cf ProtectedUpdate::recover).
 *
 * Une seule transaction à la fois par site : la plateforme sérialise déjà ses
 * commandes, le verrou protège contre un rejeu concurrent.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class UpdateTransaction {

	public const OPTION_KEY = 'g2rd_update_txn';

	/** Au-delà, une transaction encore ouverte est tenue pour morte (cron). */
	public const STALE_AFTER_SECONDS = 600;

	public const STEP_SNAPSHOT     = 'snapshot';
	public const STEP_UPGRADING    = 'upgrading';
	public const STEP_HEALTH       = 'health';
	public const STEP_ROLLING_BACK = 'rolling_back';

	/**
	 * Ouvre une transaction. Échoue si une autre est encore fraîche.
	 *
	 * @param array<string, mixed> $context plugin_file, was_active, network_active, version_before…
	 */
	public static function open( array $context, int $now ): bool {
		$current = self::current();
		if ( null !== $current && ! self::is_stale( $current, $now ) ) {
			return false;
		}
		self::write(
			array_merge(
				$context,
				[
					'step'       => self::STEP_SNAPSHOT,
					'started_at' => $now,
					'updated_at' => $now,
				]
			)
		);
		return true;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public static function step( string $step, array $changes = [], ?int $now = null ): void {
		$current = self::current();
		if ( null === $current ) {
			return;
		}
		$current['step']       = $step;
		$current['updated_at'] = $now ?? time();
		self::write( array_merge( $current, $changes ) );
	}

	public static function close(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$stored = get_option( self::OPTION_KEY, null );
		return is_array( $stored ) && isset( $stored['step'], $stored['plugin_file'] ) ? $stored : null;
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	public static function is_stale( array $txn, int $now ): bool {
		return ( $now - (int) ( $txn['updated_at'] ?? 0 ) ) > self::STALE_AFTER_SECONDS;
	}

	/**
	 * Les fichiers du plugin peuvent être dans un état intermédiaire à cette étape ?
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function files_may_be_dirty( array $txn ): bool {
		return in_array( $txn['step'] ?? '', [ self::STEP_UPGRADING, self::STEP_ROLLING_BACK ], true );
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	private static function write( array $txn ): void {
		update_option( self::OPTION_KEY, $txn, false );
	}
}
