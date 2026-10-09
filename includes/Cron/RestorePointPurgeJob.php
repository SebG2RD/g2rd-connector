<?php
/**
 * Job WP-Cron biquotidien : entretien local des points de restauration.
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
 * Le même hook purge aussi les tickets de connexion directe consommés
 * (DirectLogin\UsedTickets, branché dans Plugin::boot()).
 *
 * Rythme : deux fois par jour (toutes les heures jusqu'à la 1.13.0-rc.1, soit un
 * démarrage de WordPress par heure et par site pour un ménage qui peut attendre).
 * Ce qui dépendait du rythme horaire est couvert autrement :
 *   - la validation d'un ticket ne dépend pas de la purge (expiration contrôlée
 *     avant l'usage unique ; cf. tests DirectLogin\GateTest) ;
 *   - la reprise d'une mise à jour protégée morte sans passer par le filet de
 *     shutdown (processus tué) : un contrôle ponctuel est programmé à l'ouverture
 *     de la transaction (schedule_recovery_check()), puis suivi tant qu'elle reste
 *     ouverte — au plus RECOVERY_CHECK_DELAY après, au lieu d'une heure avant.
 * Les points expirés restent jusqu'à 12 h de plus sur le disque, toujours bornés
 * par le plafond par plugin et le budget disque du Snapshotter.
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
use G2RD\Connector\Rollback\UpdateTransaction;

final class RestorePointPurgeJob {

	public const HOOK = 'g2rd_connector_restore_points_purge';

	/** Plafond par défaut d'un point retenu (`hold`) sans autre indication : 7 jours. */
	public const DEFAULT_HOLD_MAX_SECONDS = 7 * 86400;

	/** Rythme de la purge (planification WordPress). */
	public const RECURRENCE = 'twicedaily';

	/** Rythme d'avant la 1.13, migré au démarrage et à l'activation (cf. migrate_legacy_schedule()). */
	private const LEGACY_RECURRENCE = 'hourly';

	/**
	 * Délai du contrôle de reprise d'une mise à jour protégée, compté depuis la
	 * dernière étape de sa transaction : juste après le délai au-delà duquel elle est
	 * déclarée morte (UpdateTransaction::STALE_AFTER_SECONDS, 10 min), plus une minute.
	 */
	public const RECOVERY_CHECK_DELAY = UpdateTransaction::STALE_AFTER_SECONDS + 60;

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		self::purge( new RestorePointStore(), time() );

		// Transaction encore ouverte mais pas encore déclarée morte (mise à jour en
		// cours, ou interrompue depuis peu) : on repasse dès qu'elle pourra l'être.
		$txn = UpdateTransaction::current();
		if ( null !== $txn ) {
			self::schedule_recovery_check( (int) ( $txn['updated_at'] ?? time() ) );
		}
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
	 * sites déjà installés ne rejouent jamais le hook d'activation. Une ancienne
	 * planification horaire y est migrée (une seule fois : ensuite, l'événement est
	 * biquotidien et l'appel ne fait plus rien).
	 *
	 * Coût à chaque démarrage : une lecture du tableau des tâches planifiées, déjà
	 * chargé en mémoire par WordPress (option `cron`, autochargée).
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, self::RECURRENCE, self::HOOK );
			return;
		}
		self::migrate_legacy_schedule();
	}

	/**
	 * Remplace l'événement horaire d'avant la 1.13 par un événement biquotidien, à la
	 * même date de prochain passage. Idempotent : sans effet si l'événement suivant
	 * du hook n'est pas horaire. Si WordPress refuse de retirer l'ancien, rien n'est
	 * ajouté (pas de doublon) : nouvel essai au démarrage suivant.
	 *
	 * Ne regarde que l'événement SUIVANT du hook : si un contrôle de reprise ponctuel
	 * passe avant l'événement horaire, la migration attend le démarrage qui suit ce
	 * contrôle. Jamais wp_clear_scheduled_hook() ici : il retirerait aussi ce contrôle.
	 *
	 * Appelée au démarrage (schedule()) et à l'activation (Plugin::activate()).
	 *
	 * @return bool Vrai si une migration a eu lieu.
	 */
	public static function migrate_legacy_schedule(): bool {
		$event = wp_get_scheduled_event( self::HOOK );
		if ( ! is_object( $event ) || self::LEGACY_RECURRENCE !== $event->schedule ) {
			return false;
		}

		$timestamp = (int) $event->timestamp;
		if ( true !== wp_unschedule_event( $timestamp, self::HOOK ) ) {
			return false;
		}

		return true === wp_schedule_event( $timestamp, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Programme un passage ponctuel de ce job juste après qu'une transaction de mise
	 * à jour protégée ouverte (ou avancée) à `$from` pourra être déclarée morte.
	 *
	 * Filet du filet de shutdown : un processus tué par le serveur ne passe pas par
	 * `register_shutdown_function()`, et la purge biquotidienne pourrait sinon
	 * laisser une extension à moitié mise à jour pendant des heures. WordPress refuse
	 * de lui-même un second événement identique à moins de dix minutes d'un premier :
	 * des mises à jour enchaînées ne multiplient pas les passages.
	 */
	public static function schedule_recovery_check( int $from ): void {
		wp_schedule_single_event( $from + self::RECOVERY_CHECK_DELAY, self::HOOK );
	}

	/**
	 * Désactivation : retire tous les événements du hook, y compris un contrôle de
	 * reprise ponctuel en attente.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
