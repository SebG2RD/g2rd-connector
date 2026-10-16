<?php
/**
 * Ramassage des exécutions mortes en route (§ 3.5 de la spécification, cas 2 et 3).
 *
 * Un processus tué sans fonction d'arrêt (CloudLinux, `kill`), ou mort entre la réponse
 * et le démarrage, ne clôt pas lui-même son exécution. Le ramassage la clôt depuis un
 * autre processus :
 *   - acceptée depuis plus de 120 s sans démarrer → abandoned (rien n'a été modifié : le
 *     démarrage suit pourtant la réponse de quelques millisecondes) ;
 *   - en cours sans signe de vie depuis plus de 600 s → interrupted, cause
 *     « no_sign_of_life » (le travail est peut-être partiel). Même hypothèse que la
 *     reprise d'une mise à jour protégée : aucune étape vivante ne dure davantage sans
 *     signe de vie. Si elle était en réalité vivante, son résultat sera écrit quand même
 *     (RunLifecycle::finish(), résultat tardif).
 *
 * Chaque clôture est une écriture conditionnelle sur la valeur lue et jugée
 * (RunStore::swap()) : si un autre processus a écrit entre-temps (démarrage, signe de vie,
 * résultat, autre ramassage), elle est perdue, et c'est juste : l'exécution vit, ou elle
 * est déjà close. Le passage suivant la jugera de nouveau. Une exécution est ainsi soit
 * démarrée, soit abandonnée, jamais les deux. Une fois close, son créneau est rendu s'il
 * était tenu par elle : le site est libre aussitôt, sans attendre les 600 s du créneau.
 *
 * Le ramassage ne fait que clore. Il ne relance jamais rien : seule la plateforme relance,
 * sous un nouveau numéro. Il ne touche jamais une exécution conclue.
 *
 * Ménage seulement : il ne lève jamais. Une lecture refusée par la base ne conclut rien,
 * une écriture refusée non plus ; le passage suivant reprend. Il passe à l'acceptation
 * (sweep(), complet et borné) et à la lecture par la plateforme (sweep_one(), sur le
 * numéro lu et sur le détenteur du créneau).
 *
 * Horloge : toujours passée en paramètre (`$now`, secondes Unix), comme HeartbeatJob.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class RunReaper {

	/** Acceptée depuis plus de 120 s sans démarrer : abandonnée. */
	public const ACCEPTED_TIMEOUT_SECONDS = 120;

	/**
	 * En cours sans signe de vie depuis plus de 600 s : interrompue. Même mort que le
	 * créneau (repris au même silence) et que la transaction d'une mise à jour protégée.
	 */
	public const SILENCE_TIMEOUT_SECONDS = ExecutionSlot::STALE_AFTER_SECONDS;

	/** Cause d'une interruption déclarée par le ramassage (`interruption.reason`). */
	public const REASON_NO_SIGN_OF_LIFE = 'no_sign_of_life';

	/**
	 * Clôt l'exécution `$command_id` si elle est morte en route. Sans effet si elle est
	 * vivante, conclue, inconnue ou illisible, ou si le numéro est mal formé.
	 */
	public static function sweep_one( string $command_id, int $now ): void {
		try {
			$raw = RunStore::read_raw( $command_id );
		} catch ( StoreUnavailable $e ) {
			return;
		}
		if ( null !== $raw ) {
			self::reap( $command_id, $raw, $now );
		}
	}

	/**
	 * Clôt les exécutions acceptées ou en cours mortes en route, parmi `$limit` au plus
	 * (RunStore::unfinished()). Les exécutions conclues ne sont pas lues : elles
	 * n'entament pas la borne. Au-delà de la borne, le passage suivant traite la suite.
	 *
	 * @return int Nombre d'exécutions closes.
	 */
	public static function sweep( int $now, int $limit ): int {
		$closed = 0;
		foreach ( RunStore::unfinished( $limit ) as [ $command_id, $raw ] ) {
			if ( self::reap( $command_id, $raw, $now ) ) {
				++$closed;
			}
		}

		return $closed;
	}

	/**
	 * Juge l'exécution sur sa valeur brute `$raw`, et la clôt par écriture conditionnelle
	 * sur cette même valeur si elle est morte en route ; puis rend son créneau.
	 *
	 * @return bool Vrai si elle est close par ce passage.
	 */
	private static function reap( string $command_id, string $raw, int $now ): bool {
		$record = RunStore::decode( $raw );
		$closed = null === $record ? null : self::closed( $record, $now );
		if ( null === $closed || ! RunStore::swap( $command_id, $raw, $closed ) ) {
			return false;
		}
		ExecutionSlot::release( $command_id );

		return true;
	}

	/**
	 * L'exécution close, si elle est morte en route ; null si elle vit ou si elle est
	 * déjà conclue (le ramassage ne touche qu'aux états accepted et running).
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|null
	 */
	private static function closed( array $record, int $now ): ?array {
		if ( RunState::ACCEPTED === $record['state'] && self::timed_out( $record['accepted_at'] ?? null, self::ACCEPTED_TIMEOUT_SECONDS, $now ) ) {
			$record['state']       = RunState::ABANDONED;
			$record['finished_at'] = $now;

			return $record;
		}

		if ( RunState::RUNNING === $record['state'] && self::timed_out( $record['alive_at'] ?? null, self::SILENCE_TIMEOUT_SECONDS, $now ) ) {
			$record['state']        = RunState::INTERRUPTED;
			$record['finished_at']  = $now;
			$record['interruption'] = [
				'reason'   => self::REASON_NO_SIGN_OF_LIFE,
				'error'    => self::no_sign_of_life_message( $record ),
				'recovery' => null,
			];

			return $record;
		}

		return null;
	}

	/**
	 * Plus de `$timeout` secondes écoulées depuis `$since` (acceptation, dernier signe de
	 * vie) ?
	 *
	 * Une date absente, d'un autre type, ou de plus de 600 s dans le futur est tenue pour
	 * illisible, donc pour un délai dépassé : comme pour le créneau (ExecutionSlot), une
	 * horloge qui recule ou un entier démesuré ne garde pas une exécution ouverte sans
	 * limite. La marge de 600 s couvre une heure courante lue un peu plus tôt par l'appelant.
	 */
	private static function timed_out( mixed $since, int $timeout, int $now ): bool {
		if ( ! is_int( $since ) || $since > $now + ExecutionSlot::STALE_AFTER_SECONDS ) {
			return true;
		}

		return $now - $since > $timeout;
	}

	/**
	 * Message gardé dans `interruption.error`, au format des messages du site (quoi, cause
	 * probable, action). La plateforme affiche sa propre phrase, à partir de l'état.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function no_sign_of_life_message( array $record ): string {
		$command = is_string( $record['command'] ?? null ) && '' !== $record['command'] ? $record['command'] : '?';

		return sprintf(
			/* translators: %s: nom technique de la commande, par exemple update_plugin. */
			__( 'La commande « %s » ne donne plus signe de vie sur le site depuis plus de 10 minutes : elle est déclarée interrompue, et son travail est peut-être partiel. Cause probable : processus arrêté par l’hébergeur (limite de temps ou de mémoire), ou étape anormalement longue. Action : aucune ; la plateforme vérifie l’état réel du site, et un résultat qui arriverait encore serait enregistré.', 'g2rd-connector' ),
			$command
		);
	}
}
