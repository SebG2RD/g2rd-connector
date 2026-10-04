<?php
/**
 * Usage unique des tickets de connexion directe (spec connexion WordPress §5.5,
 * contrôle 4).
 *
 * Un nonce consommé devient l'option `g2rd_login_used_<nonce>` (valeur : heure
 * d'usage, jamais autochargée). La ligne est écrite par un INSERT simple : la clé
 * unique de la table des options (option_name) garantit qu'une seule requête
 * l'obtient, même si deux requêtes portant le même ticket arrivent en même temps.
 * add_option() ne le garantit pas : il lit, puis écrit avec « ON DUPLICATE KEY
 * UPDATE », et rend vrai aux deux requêtes quand elles tombent sur deux secondes
 * différentes.
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

	/** Garde-fou de purge_all() : 200 lots de 500, très au-delà de ce qu'un site peut accumuler. */
	private const PURGE_ALL_MAX_BATCHES = 200;

	/** Valeur « non autochargée » comprise par toutes les versions de WordPress prises en charge. */
	private const AUTOLOAD_OFF = 'no';

	/**
	 * Consomme le nonce. Faux s'il a déjà servi — ou si la base refuse l'écriture :
	 * dans le doute, le ticket n'ouvre pas de session.
	 *
	 * L'écriture est atomique : « INSERT IGNORE » n'ajoute la ligne que si le nom
	 * d'option est libre (clé unique) et annonce alors une ligne ajoutée ; sinon
	 * aucune, sans erreur SQL dans les journaux du site.
	 *
	 * @param string $nonce Nonce du ticket (hexadécimal de 32 caractères, déjà contrôlé).
	 * @param int    $now   Heure d'usage (secondes Unix).
	 */
	public static function claim( string $nonce, int $now ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- écriture atomique voulue (add_option lit puis écrit) ; option jamais lue avant, aucun cache à tenir.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::OPTION_PREFIX . $nonce,
				(string) $now,
				self::AUTOLOAD_OFF
			)
		);

		return 1 === $inserted;
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
	 * Supprime TOUS les tickets consommés du site courant, lot après lot
	 * (désinstallation). S'arrête dès qu'un lot est incomplet : plus rien à retirer,
	 * ou une suppression refusée par la base (pas de boucle sans fin).
	 *
	 * @return int Nombre d'entrées supprimées.
	 */
	public static function purge_all(): int {
		$total = 0;
		for ( $batch = 0; $batch < self::PURGE_ALL_MAX_BATCHES; $batch++ ) {
			$purged = self::purge( PHP_INT_MAX );
			$total += $purged;
			if ( $purged < self::PURGE_BATCH ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Cible du cron horaire (RestorePointPurgeJob::HOOK).
	 */
	public static function purge_now(): void {
		self::purge( time() );
	}
}
