<?php
/**
 * Phase des tâches WP-Cron récurrentes du connecteur, tirée au hasard.
 *
 * Pourquoi
 * --------
 * Mesuré en production le 11/10/2026 sur le serveur mutualisé (une trentaine de
 * sites) : la purge des points de restauration passait à :33-:34 sur 18 sites, la
 * découverte des mises à jour vers 02:05 et 14:05 UTC sur 20 sites. Chaque tâche
 * avait été planifiée au premier démarrage qui suivait la même mise à jour du
 * connecteur, à un décalage fixe (`time() + 300`, `time() + 120`), et WordPress
 * garde ensuite la phase d'un événement récurrent : wp_reschedule_event() calcule
 * le passage suivant à partir de la date prévue, pas de l'heure réelle du passage.
 *
 * Désormais :
 *   - un premier passage est tiré au hasard dans l'intervalle de la tâche
 *     (first_run()), pour la découverte des mises à jour et la purge ;
 *   - les tâches déjà planifiées sont re-phasées une seule fois (maybe_rephase()).
 *
 * Non concernés par le re-phasage : le heartbeat horaire, que maybe_rephase() ne
 * reçoit pas (un battement existant garde sa phase, qui suit l'heure d'enrôlement de
 * chaque site, déjà répartie). Planifié à l'enrôlement (`time() + 60`, premier signe
 * de vie rapide), il est en revanche réparé au démarrage s'il a disparu
 * (HeartbeatJob::ensure_scheduled()), à un premier passage tiré par first_run().
 * Non concernés non plus : les passages ponctuels, qui ont besoin de leur heure
 * (contrôle de reprise d'une mise à jour protégée, découverte immédiate demandée par
 * le snapshot).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Cron;

final class Phase {

	/**
	 * Option : version de la répartition déjà appliquée aux tâches existantes.
	 * Autochargée (un entier) : lue à chaque démarrage, elle ne coûte alors aucune
	 * requête SQL.
	 */
	public const OPTION = 'g2rd_connector_cron_phase';

	/** Version courante de la répartition. L'augmenter re-phase une fois de plus. */
	public const VERSION = 1;

	/**
	 * Délai minimal d'un premier passage, en secondes : jamais dans le passé, ni dans
	 * la rafale de requêtes qui suit une mise à jour du connecteur.
	 */
	public const MIN_DELAY = 60;

	/**
	 * Date d'un premier passage : maintenant plus un délai tiré au hasard dans
	 * [MIN_DELAY, intervalle], bornes incluses.
	 *
	 * @param int                          $now      Heure courante (secondes Unix).
	 * @param int                          $interval Intervalle de la tâche (secondes).
	 * @param (\Closure(int, int): int)|null $random Tirage (bornes incluses), injectable
	 *                                               pour les tests ; random_int() sinon.
	 */
	public static function first_run( int $now, int $interval, ?\Closure $random = null ): int {
		$max   = max( self::MIN_DELAY, $interval );
		$delay = ( $random ?? self::default_random() )( self::MIN_DELAY, $max );

		// Borne défensive : un tirage hors bornes ne place jamais l'événement ailleurs.
		return $now + min( $max, max( self::MIN_DELAY, $delay ) );
	}

	/**
	 * Migration unique : re-phase au hasard, dans leur intervalle, les tâches
	 * récurrentes déjà planifiées des hooks donnés. Appelée à chaque démarrage, elle
	 * se résume ensuite à la lecture d'une option autochargée.
	 *
	 * Le marqueur est écrit AVANT de toucher aux tâches : si l'écriture échoue (ou
	 * qu'une requête concurrente vient de l'écrire : update_option() — add_option()
	 * quand l'option manque — rend alors faux), rien n'est re-phasé. Sans cela, un
	 * marqueur impossible à écrire repousserait la tâche à chaque démarrage, et elle
	 * ne passerait plus jamais. Une tâche dont le
	 * retrait est refusé garde sa date (aucun doublon) : la migration n'est pas
	 * rejouée pour autant.
	 *
	 * Un passage manqué n'est pas grave : la tâche passera au tour suivant.
	 *
	 * @param list<string>                 $hooks  Hooks des tâches à re-phaser.
	 * @param (\Closure(int, int): int)|null $random Tirage, cf. first_run().
	 * @return bool Vrai si la migration a eu lieu pendant cet appel.
	 */
	public static function maybe_rephase( array $hooks, ?\Closure $random = null ): bool {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return false;
		}
		if ( true !== update_option( self::OPTION, self::VERSION, true ) ) {
			return false;
		}

		$now = time();
		foreach ( $hooks as $hook ) {
			self::rephase( $hook, $now, $random );
		}

		return true;
	}

	/**
	 * Déplace la tâche récurrente d'un hook à une date tirée dans son intervalle, à
	 * rythme et arguments inchangés. Retrait d'abord, de cet événement-là seulement
	 * (jamais wp_clear_scheduled_hook()) ; rien n'est ajouté si WordPress le refuse.
	 *
	 * @param (\Closure(int, int): int)|null $random
	 */
	private static function rephase( string $hook, int $now, ?\Closure $random ): void {
		$event = self::recurring_event( $hook );
		if ( null === $event ) {
			return;
		}
		if ( true !== wp_unschedule_event( $event['timestamp'], $hook, $event['args'] ) ) {
			return;
		}
		wp_schedule_event( self::first_run( $now, $event['interval'], $random ), $event['schedule'], $hook, $event['args'] );
	}

	/**
	 * Événement RÉCURRENT d'un hook. wp_get_scheduled_event() ne suffit pas : il rend
	 * le prochain événement, qui peut être un passage ponctuel du même hook (la
	 * découverte immédiate demandée par le snapshot, cf. UpdatesDiscoveryJob::request_now()).
	 *
	 * @return array{timestamp: int, schedule: string, interval: int, args: array<mixed>}|null
	 */
	private static function recurring_event( string $hook ): ?array {
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( $hooks[ $hook ] as $event ) {
				if ( is_array( $event ) && ! empty( $event['schedule'] ) && ! empty( $event['interval'] ) ) {
					return [
						'timestamp' => (int) $timestamp,
						'schedule'  => (string) $event['schedule'],
						'interval'  => (int) $event['interval'],
						'args'      => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : [],
					];
				}
			}
		}
		return null;
	}

	/**
	 * random_int(), qui ne lève qu'en l'absence de toute source d'aléa : repli sur le
	 * milieu de l'intervalle plutôt qu'une erreur au démarrage du site.
	 *
	 * @return \Closure(int, int): int
	 */
	private static function default_random(): \Closure {
		return static function ( int $min, int $max ): int {
			try {
				return random_int( $min, $max );
			} catch ( \Throwable ) {
				return intdiv( $min + $max, 2 );
			}
		};
	}
}
