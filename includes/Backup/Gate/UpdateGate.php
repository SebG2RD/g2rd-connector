<?php
/**
 * Porte des mises à jour (décision n° 16 : la mise à jour passe, la sauvegarde cède). Toute commande
 * qui change le code ou le schéma (COMMANDS) y passe avant de toucher à quoi que ce soit, quelle que
 * soit sa route : /command et la file (CommandExecutor::run()), la route en arrière-plan
 * (CommandRunsController::accept(), passage gardé jusqu'à AfterResponseRunner::clean_up()). Elle se
 * place après les contrôles existants (jeton, signature, nonce), dont les refus ne changent pas.
 *
 * Règles (vue d'ensemble, lot 4-A, point 4 ; aucune mise à jour n'est jamais refusée à cause d'une
 * autre mise à jour) :
 *  (a) transparente quand les sauvegardes sont éteintes (case décochée ou constante d'arrêt) ET
 *      l'état est idle, absent ou illisible : une lecture, puis la commande, exactement comme avant
 *      (ni verrou, ni témoin, ni écriture) ;
 *  (b) verrou impossible : la mise à jour passe sans verrou ; une copie en cours passe à abort
 *      (interrupted_by_update) ; entrée « sans verrou » au témoin ;
 *  (c) verrou tenu et témoin présent (autre mise à jour, ou requête rejouée par le CDN) : passe sans
 *      verrou, entrée « sans verrou » ;
 *  (d) verrou tenu sans témoin (une tranche) : refus réessayable backup_slice_running, retry_after
 *      = reste de la tranche, rien de modifié — le SEUL cas de refus ;
 *  verrou pris : entrée « tient le verrou », copie en cours interrompue, commande exécutée verrou
 *  tenu, entrée retirée et verrou rendu dans un finally.
 * Une copie (db, files) seulement est interrompue : en upload et finalize, tout est déjà copié.
 *
 * Risque écrit (tour 6) : une mise à jour partie porte transparente tourne sans verrou ; si les
 * sauvegardes sont allumées et qu'un backup_start arrive pendant elle, il démarre en pleine mise à
 * jour ; parade : le second filet upgrader_process_complete (UpdateWatcher), essai E17 du banc.
 * Risque écrit : une reconnexion de $wpdb au milieu d'une mise à jour perdrait le verrou sans bruit ;
 * même parade.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Gate;

use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Support\MysqlLock;

final class UpdateGate {

	/** Commandes qui changent le code ou le schéma (liste fermée, § 11.2 point 1). */
	public const COMMANDS = [ 'update_plugin', 'update_theme', 'update_core', 'update_translations', 'rollback_plugin' ];

	private const REASON = 'interrupted_by_update';

	/**
	 * Passage en cours dans ce processus : verrou tenu (null si passé sans verrou) et entrée du
	 * témoin. Null : aucun passage.
	 *
	 * @var array{lock: string|null, entry: string|null}|null
	 */
	private static ?array $current = null;

	/** Vrai entre une porte transparente (règle a) et sa fermeture, dans ce processus. */
	private static bool $transparent = false;

	public static function applies_to( string $command ): bool {
		return in_array( $command, self::COMMANDS, true );
	}

	public static function holding(): bool {
		return null !== self::$current;
	}

	/**
	 * Vrai pendant une commande passée porte transparente : le début de la mise à jour qu'elle lance
	 * (UpdateWatcher::on_upgrade_start) ne relit pas l'état (rappel transverse n° 2).
	 */
	public static function transparent(): bool {
		return self::$transparent;
	}

	/**
	 * Ouvre le passage de $command. Null : la commande peut s'exécuter (passage ouvert, ou porte
	 * transparente) ; sinon, l'issue du refus (règle d).
	 *
	 * @return array{status: string, error: string, error_code: string, retry_after?: int}|null
	 */
	public static function enter( string $command, int $now ): ?array {
		if ( null !== self::$current ) {
			return null;
		}

		$state = BackupState::peek();
		if ( ! self::switch_on() && ( null === $state || ! BackupState::is_active( $state ) ) ) {
			self::$transparent = true;
			return null;
		}

		$lock = MysqlLock::acquire( BackupLock::PURPOSE, 0, MysqlLock::SCOPE_NETWORK );
		if ( MysqlLock::ACQUIRED === $lock['state'] && null !== $lock['name'] ) {
			self::$current = [
				'lock'  => $lock['name'],
				'entry' => UpdateWitness::add( $command, true, $now ),
			];
			BackupState::abort_if_capturing( self::REASON, $now );
			return null;
		}

		if ( MysqlLock::BUSY === $lock['state'] && ! UpdateWitness::has_entries() ) {
			return ( new BackupRefused( BackupRefused::SLICE_RUNNING, BackupLock::retry_after( $state, $now ) ) )->outcome();
		}

		BackupState::abort_if_capturing( self::REASON, $now );
		self::$current = [
			'lock'  => null,
			'entry' => UpdateWitness::add( $command, false, $now ),
		];
		return null;
	}

	/**
	 * Ferme le passage : retire l'entrée du témoin, rend le verrou. Idempotent, ne lève jamais.
	 */
	public static function leave(): void {
		self::$transparent = false;
		$current           = self::$current;
		self::$current     = null;
		if ( null === $current ) {
			return;
		}
		try {
			UpdateWitness::remove( $current['entry'] );
		} catch ( \Throwable ) {
			// Entrée laissée : la prochaine tranche l'efface (verrou libre ou échéance).
			unset( $current['entry'] );
		}
		if ( null !== $current['lock'] ) {
			MysqlLock::release( $current['lock'] );
		}
	}

	/**
	 * Exécute $run à travers la porte. Un passage déjà ouvert par la route en arrière-plan (verrou
	 * tenu, sans verrou, ou porte transparente) n'est ni repris ni fermé ici : la requête qui l'a
	 * ouvert le ferme (AfterResponseRunner::clean_up()). Sans quoi une porte transparente relirait
	 * l'état et s'oublierait avant la fin de la mise à jour : trois lectures au lieu de deux au plus
	 * (P4-2, rappel transverse n° 2).
	 *
	 * Types écrits en entier (forme de l'issue de CommandExecutor::run(), refus compris), sans
	 * gabarit : PHPStan 1.12 ramène la forme du refus à array<string, int|string> dès qu'elle est
	 * unie à un gabarit, et l'issue de run() perdait sa forme (relevé de niveau 8, tâche 10).
	 *
	 * @param \Closure(): array{status:string,result?:array<string,mixed>,error?:string,error_code?:string,retry_after?:int,stray_output?:string} $run
	 * @return array{status:string,result?:array<string,mixed>,error?:string,error_code?:string,retry_after?:int,stray_output?:string}
	 */
	public static function around( string $command, \Closure $run ): array {
		if ( null !== self::$current || self::$transparent ) {
			return $run();
		}
		$refusal = self::enter( $command, time() );
		if ( null !== $refusal ) {
			return $refusal;
		}
		try {
			return $run();
		} finally {
			self::leave();
		}
	}

	/**
	 * Interrupteur lu sans jamais lever (réglages illisibles, fonction WordPress absente d'un test
	 * existant) : faux, donc porte transparente si l'état est idle ou illisible (choix n° 5).
	 */
	private static function switch_on(): bool {
		try {
			return BackupSwitch::enabled();
		} catch ( \Throwable ) {
			return false;
		}
	}
}
