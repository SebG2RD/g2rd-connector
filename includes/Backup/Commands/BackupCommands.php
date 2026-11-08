<?php
/**
 * Les quatre commandes de sauvegarde (contrat « Commandes backup_* » du plan de la phase 4), toutes
 * dans CommandExecutor::ALLOWED et SIGNED_ONLY, admises en arrière-plan : chacune s'exécute sous le
 * verrou des tranches (Gate\BackupLock), donc jamais pendant une mise à jour ni pendant une autre
 * tranche. backup_set_key exige les sauvegardes allumées et aucune sauvegarde en cours (une clé
 * changée au milieu d'une sauvegarde mêlerait deux versions de clé) ; un état illisible ne passe
 * jamais pour « aucune sauvegarde en cours » (backup_store_unavailable, réessayable). backup_abort
 * reste permis sauvegardes éteintes, pour nettoyer. La file des commandes ne les exécute pas avant
 * E8 (lot 5-D) : refus `backup_queue_unsupported` (Cron\HeartbeatJob).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Commands;

use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Gate\BackupLock;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\Run\BackupJob;
use G2RD\Connector\Backup\State\BackupState;

final class BackupCommands {

	public const NAMES = [ 'backup_set_key', 'backup_start', 'backup_step', 'backup_abort' ];

	public static function is_backup_command( string $command ): bool {
		return in_array( $command, self::NAMES, true );
	}

	/**
	 * @param array<mixed> $payload
	 * @return array<string, mixed>
	 * @throws BackupRefused Refus à code stable (CommandExecutor le rend avec error_code et retry_after).
	 */
	public static function run( string $command, array $payload ): array {
		$now = time();

		return BackupLock::for_slice(
			static function () use ( $command, $payload, $now ): array {
				$sodium = new Sodium();
				if ( 'backup_set_key' === $command ) {
					if ( ! BackupSwitch::enabled() ) {
						throw new BackupRefused( BackupRefused::DISABLED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
					}
					$state = self::state();
					if ( BackupState::is_active( $state ) && BackupState::ABORT !== $state['phase'] ) {
						throw new BackupRefused( BackupRefused::ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
					}
					return ( new SiteKeyStore( $sodium ) )->receive( $payload, $now );
				}
				$job = new BackupJob( $sodium );

				return match ( $command ) {
					'backup_start' => $job->start( $payload ),
					'backup_step'  => $job->step( $payload ),
					default        => $job->abort( $payload ),
				};
			},
			$now
		);
	}

	/**
	 * État de la sauvegarde, relu sous le verrou ; illisible : refus réessayable, jamais « au repos ».
	 *
	 * @return array<string, mixed>
	 * @throws BackupRefused STORE_UNAVAILABLE.
	 */
	private static function state(): array {
		try {
			return BackupState::load()['state'];
		} catch ( \Throwable ) {
			throw new BackupRefused( BackupRefused::STORE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}
}
