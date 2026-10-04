<?php
/**
 * Usage unique des tickets de connexion directe (spec connexion WordPress §5.5,
 * contrôle 4).
 *
 * Un nonce consommé devient l'option `g2rd_login_used_<nonce>` (valeur : heure
 * d'usage, jamais autochargée). add_option() refuse une option qui existe déjà :
 * un ticket rejoué est refusé.
 *
 * Les entrées de plus de 10 minutes — bien au-delà de la vie d'un ticket (60 s,
 * plus 30 s de tolérance d'horloge) — sont purgées par le cron horaire local
 * existant (RestorePointPurgeJob::HOOK), qui fonctionne hors connexion au manager.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\DirectLogin;

final class UsedTickets {

	public const OPTION_PREFIX     = 'g2rd_login_used_';
	public const RETENTION_SECONDS = 600;

	/** Au plus 10 tickets par minute et par utilisateur côté manager : 500 par passage suffisent. */
	private const PURGE_BATCH = 500;

	/**
	 * Consomme le nonce. Faux s'il a déjà servi.
	 *
	 * @param string $nonce Nonce du ticket (hexadécimal de 32 caractères, déjà contrôlé).
	 * @param int    $now   Heure d'usage (secondes Unix).
	 */
	public static function claim( string $nonce, int $now ): bool {
		return add_option( self::OPTION_PREFIX . $nonce, $now, '', false );
	}

	/**
	 * Supprime les tickets consommés depuis plus de 10 minutes.
	 *
	 * @return int Nombre d'entrées supprimées.
	 */
	public static function purge( int $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recherche par préfixe des tickets consommés (options non autochargées), aucun cache pertinent.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				self::PURGE_BATCH
			)
		);

		$purged    = 0;
		$threshold = $now - self::RETENTION_SECONDS;
		foreach ( (array) $names as $name ) {
			$used_at = (int) get_option( (string) $name, 0 );
			if ( $used_at < $threshold && delete_option( (string) $name ) ) {
				++$purged;
			}
		}

		return $purged;
	}

	/**
	 * Cible du cron horaire (RestorePointPurgeJob::HOOK).
	 */
	public static function purge_now(): void {
		self::purge( time() );
	}
}
