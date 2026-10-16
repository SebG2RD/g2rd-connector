<?php
/**
 * Passages d'état d'une exécution en arrière-plan (§ 3.5 de la spécification), écrits
 * par le processus qui l'exécute :
 *
 *   start()     accepted → running        (démarrage, juste après la réponse)
 *   alive()     running → running         (signe de vie, étape d'une mise à jour protégée)
 *   finish()    running, interrupted → done, failed (résultat, tardif compris)
 *   interrupt() running → interrupted     (dernier filet, à l'arrêt de PHP)
 *
 * Le ramassage (RunReaper) écrit, lui, les clôtures d'une exécution morte en route
 * (abandoned, interrupted), depuis un autre processus.
 *
 * Chaque passage est une écriture conditionnelle sur la valeur relue
 * (RunStore::swap()) : deux processus ne peuvent pas changer le même état ensemble. Une
 * exécution est donc soit démarrée, soit abandonnée, jamais les deux : si le ramassage
 * l'a abandonnée entre la lecture et l'écriture du démarrage, celui-ci est perdu, et rien
 * ne s'exécute.
 *
 * Écriture perdue (un autre processus a écrit entre la lecture et l'écriture) : relue,
 * puis réessayée sur la valeur relue si le passage vaut encore, trois fois au plus. Le
 * démarrage renonce si l'exécution n'est plus acceptée ; un résultat s'écrit encore sur
 * une exécution déclarée interrompue entre-temps. Une écriture refusée par la base (la
 * valeur relue n'a pas changé) n'est pas réessayée à l'identique : RunStore::swap() le
 * dit, cela ne servirait à rien.
 *
 * Aucune méthode ne lève : le signe de vie et le résultat s'écrivent au milieu ou à la fin
 * d'une mise à jour, le dernier filet à l'arrêt de PHP. Une lecture refusée par la base
 * (StoreUnavailable) ne conclut jamais rien : rien n'est écrit. Pour le démarrage, c'est
 * un refus : rien ne s'exécute, et le ramassage dira l'exécution abandonnée, ce qui est
 * vrai. Pour un résultat, il est perdu : le ramassage déclarera l'exécution interrompue
 * 600 s après son dernier signe de vie, et la plateforme vérifiera l'état réel du site.
 *
 * Le site ne relance jamais rien de lui-même : seule la plateforme relance, sous un
 * nouveau numéro.
 *
 * Horloge : toujours passée en paramètre (`$now`, secondes Unix), comme HeartbeatJob.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class RunLifecycle {

	/**
	 * Écritures au plus par passage (démarrage, résultat, interruption). Le seul autre
	 * processus qui écrit une exécution en cours est le ramassage, une fois : trois essais
	 * couvrent largement la course, sans jamais boucler sans fin.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Démarre l'exécution : accepted → running, `started_at` et `alive_at` à `$now`.
	 *
	 * @return bool Vrai si ce processus l'a démarrée : il peut exécuter la commande. Faux
	 *              sinon, et rien ne doit alors s'exécuter : exécution inconnue, déjà
	 *              démarrée, ou ramassée entre-temps (abandonnée), ou base qui refuse.
	 */
	public static function start( string $command_id, int $now ): bool {
		return self::transition(
			$command_id,
			static function ( array $record ) use ( $now ): ?array {
				if ( RunState::ACCEPTED !== $record['state'] ) {
					return null;
				}
				$record['state']      = RunState::RUNNING;
				$record['started_at'] = $now;
				$record['alive_at']   = $now;

				return $record;
			},
			self::MAX_ATTEMPTS
		);
	}

	/**
	 * Signe de vie d'une exécution en cours : `alive_at` passe à `$now`, et `step` à
	 * `$step` s'il est donné (étape d'une mise à jour protégée ; null garde la précédente).
	 *
	 * Sans effet si l'exécution n'est pas en cours : un signe de vie ne démarre rien, ne
	 * rouvre rien. Une écriture perdue n'est pas réessayée : le signe de vie suivant la
	 * remplace, et la mort n'est déclarée qu'après 600 s de silence.
	 */
	public static function alive( string $command_id, int $now, ?string $step ): void {
		self::transition(
			$command_id,
			static function ( array $record ) use ( $now, $step ): ?array {
				if ( RunState::RUNNING !== $record['state'] ) {
					return null;
				}
				$record['alive_at'] = $now;
				if ( null !== $step ) {
					$record['step'] = $step;
				}

				return $record;
			},
			1
		);
	}

	/**
	 * Écrit le résultat de la commande : running ou interrupted → done si
	 * `$outcome['status']` vaut « done », failed sinon (CommandExecutor ne rend que « done »
	 * ou « failed »). `outcome` est gardé tel quel ; `finished_at` et `alive_at` passent à
	 * `$now`.
	 *
	 * Résultat tardif : une exécution déclarée interrompue par le ramassage (étape de plus
	 * de 10 minutes sans signe de vie) était en réalité vivante. Son résultat est écrit
	 * quand même, et `interruption` reste pour l'historique : un résultat réel vaut mieux
	 * qu'une présomption.
	 *
	 * Sans effet sur une exécution qui n'a pas démarré (acceptée, abandonnée) ou déjà
	 * conclue par un résultat (done, failed).
	 *
	 * @param array<string, mixed> $outcome Résultat, de la forme de la réponse de `/command`
	 *                                      ({status, result, error, stray_output}).
	 */
	public static function finish( string $command_id, array $outcome, int $now ): void {
		$state = 'done' === ( $outcome['status'] ?? null ) ? RunState::DONE : RunState::FAILED;

		self::transition(
			$command_id,
			static function ( array $record ) use ( $state, $outcome, $now ): ?array {
				if ( ! RunState::accepts_late_result( $record['state'] ) ) {
					return null;
				}
				$record['state']       = $state;
				$record['outcome']     = $outcome;
				$record['alive_at']    = $now;
				$record['finished_at'] = $now;

				return $record;
			},
			self::MAX_ATTEMPTS
		);
	}

	/**
	 * Déclare interrompue une exécution en cours (running → interrupted) : le processus
	 * s'arrête avant d'avoir rendu son résultat, le travail est peut-être partiel.
	 * `interruption` = {reason, error, recovery} ; `finished_at` passe à `$now`.
	 *
	 * @param string                    $reason   Cause, code stable (par exemple « fatal_error »).
	 * @param string                    $error    Message de l'erreur, coupé à 2 Ko à l'écriture.
	 * @param array<string, mixed>|null $recovery Résultat de reprise de l'extension consigné
	 *                                            depuis le démarrage, s'il existe.
	 * @return bool Vrai si ce processus l'a déclarée interrompue. Faux si elle n'était pas
	 *              en cours (déjà conclue, interrompue par le ramassage…), ou si la base refuse.
	 */
	public static function interrupt( string $command_id, string $reason, string $error, ?array $recovery, int $now ): bool {
		return self::transition(
			$command_id,
			static function ( array $record ) use ( $reason, $error, $recovery, $now ): ?array {
				if ( RunState::RUNNING !== $record['state'] ) {
					return null;
				}
				$record['state']        = RunState::INTERRUPTED;
				$record['finished_at']  = $now;
				$record['interruption'] = [
					'reason'   => $reason,
					'error'    => $error,
					'recovery' => $recovery,
				];

				return $record;
			},
			self::MAX_ATTEMPTS
		);
	}

	/**
	 * Lit l'exécution, calcule son nouvel état (`$change`), et l'écrit si elle n'a pas
	 * changé entre-temps. Écriture perdue : relue, et `$change` est rappelé sur la valeur
	 * relue, `$max_attempts` écritures au plus.
	 *
	 * @param \Closure(array<string, mixed>): (array<string, mixed>|null) $change Nouvel
	 *        enregistrement, ou null si le passage ne vaut pas pour cet état.
	 * @return bool Vrai si le nouvel enregistrement est écrit.
	 */
	private static function transition( string $command_id, \Closure $change, int $max_attempts ): bool {
		try {
			$raw = RunStore::read_raw( $command_id );
		} catch ( StoreUnavailable $e ) {
			return false;
		}

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			// Absente (supprimée entre-temps) ou illisible : rien à faire.
			if ( null === $raw ) {
				return false;
			}
			$record = RunStore::decode( $raw );
			$next   = null === $record ? null : $change( $record );
			if ( null === $next ) {
				return false;
			}
			if ( RunStore::swap( $command_id, $raw, $next ) ) {
				return true;
			}
			if ( $attempt === $max_attempts ) {
				break;
			}

			try {
				$reread = RunStore::read_raw( $command_id );
			} catch ( StoreUnavailable $e ) {
				return false;
			}
			// Même valeur : personne n'a écrit, la base a refusé l'écriture.
			if ( $reread === $raw ) {
				return false;
			}
			$raw = $reread;
		}

		return false;
	}
}
