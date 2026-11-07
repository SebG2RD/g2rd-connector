<?php
/**
 * Second filet de la décision n° 16 (la mise à jour passe, la sauvegarde cède), pour toute mise à
 * jour, d'où qu'elle vienne (plateforme, administration, mises à jour automatiques, wp-cli) :
 *
 *  - début (filtre upgrader_pre_download, WordPress 3.7, appelé au début de chaque paquet par
 *    WP_Upgrader::download_package(), avant le contrôle d'un paquet local) : sauvegardes éteintes et
 *    état idle → une lecture, rien d'autre (règle a) ; sinon copie en cours interrompue et entrée
 *    « sans verrou » au témoin (échéance UpdateWitness::UNLOCKED_TTL_SECONDS), qui fait refuser
 *    update_in_progress toute tranche et tout backup_start pendant la mise à jour. Une commande de la
 *    plateforme déjà passée par la porte (verrou tenu ou porte transparente) n'ajoute rien ;
 *  - fin (upgrader_process_complete) : entrées de ce processus retirées ; copie en cours
 *    interrompue ; sauvegarde commencée pendant la mise à jour interrompue même après sa copie.
 *
 * Il ne passe pas après une erreur fatale : d'où le verrou de la porte pour les commandes de la
 * plateforme, et l'échéance des entrées « sans verrou ». Au plus deux lectures d'état par mise à
 * jour sauvegardes éteintes ; ne lève jamais.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Gate;

use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\State\BackupState;

final class UpdateWatcher {

	private const REASON = 'interrupted_by_update';

	/** Début de la première mise à jour vue dans ce processus (secondes Unix), ou null. */
	private static ?int $started_at = null;

	/** @var list<string> Entrées « sans verrou » ajoutées au témoin par ce processus. */
	private static array $entries = [];

	/**
	 * @param mixed $reply Valeur du filtre upgrader_pre_download (false : télécharger), rendue telle quelle.
	 * @return mixed
	 */
	public static function on_upgrade_start( mixed $reply = false ): mixed {
		$now                = time();
		self::$started_at ??= $now;
		if ( UpdateGate::holding() || UpdateGate::transparent() ) {
			return $reply;
		}
		try {
			$state = BackupState::peek();
			if ( ! self::enabled() && ( null === $state || ! BackupState::is_active( $state ) ) ) {
				return $reply;
			}
			BackupState::abort_if_capturing( self::REASON, $now );
			$id = UpdateWitness::add( 'upgrader', false, $now );
			if ( null !== $id ) {
				self::$entries[] = $id;
			}
		} catch ( \Throwable ) {
			return $reply;
		}

		return $reply;
	}

	/**
	 * @param mixed $upgrader Instance de WP_Upgrader (non utilisée).
	 * @param mixed $options  Description de la mise à jour (non utilisée).
	 */
	public static function on_upgrade_complete( mixed $upgrader = null, mixed $options = null ): void {
		unset( $upgrader, $options );
		$since            = self::$started_at;
		$entries          = self::$entries;
		self::$started_at = null;
		self::$entries    = [];
		try {
			foreach ( $entries as $id ) {
				UpdateWitness::remove( $id );
			}
			$state = BackupState::peek();
			if ( null === $state || ! BackupState::is_active( $state ) ) {
				return;
			}
			$now = time();
			if ( BackupState::is_capturing( $state ) ) {
				BackupState::abort_if_capturing( self::REASON, $now );
				return;
			}
			if ( null !== $since ) {
				BackupState::abort_if_started_since( $since, self::REASON, $now );
			}
		} catch ( \Throwable ) {
			return;
		}
	}

	private static function enabled(): bool {
		try {
			return BackupSwitch::enabled();
		} catch ( \Throwable ) {
			return false;
		}
	}
}
