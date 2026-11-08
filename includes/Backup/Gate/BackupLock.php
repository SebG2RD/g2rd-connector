<?php
/**
 * Verrou des tranches de sauvegarde : MysqlLock « backup », portée réseau, sans attente. Une tranche,
 * un backup_start, un backup_abort ou un backup_set_key ne s'exécutent que verrou tenu ; le verrou
 * est rendu dans un finally (et par MySQL si le processus meurt).
 *
 *   verrou impossible (UNAVAILABLE)            → backup_lock_unavailable (30 s) ;
 *   verrou tenu ailleurs, témoin non vide      → update_in_progress (60 s) ;
 *   verrou tenu ailleurs, témoin vide          → backup_slice_running (reste de l'autre tranche) ;
 *   verrou pris, mise à jour sans verrou vivante → verrou rendu, update_in_progress ;
 *   verrou pris                                → témoins morts effacés, opération exécutée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Gate;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Support\MysqlLock;

final class BackupLock {

	public const PURPOSE = 'backup';

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 * @throws BackupRefused Verrou impossible, tenu, ou mise à jour en cours.
	 */
	public static function for_slice( callable $operation, int $now ): mixed {
		$lock    = MysqlLock::acquire( self::PURPOSE, 0, MysqlLock::SCOPE_NETWORK );
		$refusal = null;
		if ( MysqlLock::BUSY === $lock['state'] ) {
			$refusal = UpdateWitness::has_entries()
				? new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS )
				: new BackupRefused( BackupRefused::SLICE_RUNNING, self::retry_after( BackupState::peek(), $now ) );
		} elseif ( MysqlLock::ACQUIRED !== $lock['state'] || null === $lock['name'] ) {
			$refusal = new BackupRefused( BackupRefused::LOCK_UNAVAILABLE );
		}
		if ( null !== $refusal ) {
			throw $refusal;
		}
		$name = (string) $lock['name'];

		try {
			if ( UpdateWitness::clear_for_slice( $now ) ) {
				throw new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
			return $operation();
		} finally {
			MysqlLock::release( $name );
		}
	}

	/**
	 * Reste de la tranche en cours, de 1 à 60 s : début et durée lus dans l'état, plus 5 s de marge.
	 *
	 * @param array<string, mixed>|null $state
	 */
	public static function retry_after( ?array $state, int $now ): int {
		$slice   = is_array( $state['slice'] ?? null ) ? $state['slice'] : [];
		$started = is_int( $slice['started_at'] ?? null ) ? $slice['started_at'] : $now;
		$budget  = is_int( $slice['budget'] ?? null ) ? $slice['budget'] : 20;

		return max( 1, min( BackupRefused::MAX_RETRY_AFTER, $started + $budget + 5 - $now ) );
	}
}
