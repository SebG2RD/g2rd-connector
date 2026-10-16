<?php
/**
 * Créneau d'exécution, un seul par site (§ 3.4 de la spécification).
 *
 * Une seule commande en arrière-plan tourne à la fois sur un site. L'option
 * `g2rd_cmdrun_slot` dit laquelle, depuis quand, et son dernier signe de vie : JSON
 * `{command_id, command, since, alive_at}` (secondes Unix), jamais autochargé.
 *
 * Elle s'ajoute à UpdateTransaction sans le modifier. La transaction reste le verrou des
 * fichiers d'une extension (mises à jour protégées, retour arrière, reprise du cron) ; le
 * créneau couvre le reste : cœur, thèmes, traductions, optimisation, mises à jour non
 * protégées. Il reprend son modèle (identité du détenteur, signe de vie, mort déclarée au
 * bout de 600 s de silence), avec en plus les écritures atomiques de RunStore :
 *   - prise d'un créneau libre : « INSERT IGNORE », la clé unique sur option_name ne laisse
 *     passer qu'un processus ;
 *   - reprise d'un créneau mort, signe de vie : « UPDATE … AND option_value = %s » sur la
 *     valeur relue ;
 *   - rendu : « DELETE … AND option_value = %s » sur la valeur relue.
 * Une écriture perdue (aucune ligne touchée) veut dire qu'un autre processus a écrit
 * entre la lecture et l'écriture : jamais deux détenteurs.
 *
 * Toujours relu en base, jamais par get_option() : c'est un verrou, et un cache d'objets
 * persistant (LiteSpeed Object Cache) pourrait le servir libre alors qu'il vient d'être
 * pris. Une lecture que la base refuse n'est jamais prise pour un créneau libre.
 *
 * Le numéro d'exécution n'est pas contrôlé ici : il n'entre pas dans le nom de l'option,
 * seulement dans sa valeur, et la route d'acceptation l'a déjà contrôlé
 * (RunStore::ID_PATTERN). Le créneau n'est pas réentrant : une exécution qui le tient le
 * trouve occupé par elle-même.
 *
 * Limite connue : une exécution finie dont le rendu échoue (base indisponible à cet
 * instant) laisse le créneau pris jusqu'à 600 s après son dernier signe de vie, puis il
 * est repris par la prise suivante. Le ramassage (RunReaper) ne rend, lui, que le
 * créneau d'une exécution qu'il clôt (morte en route).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

use G2RD\Connector\Rollback\UpdateTransaction;

final class ExecutionSlot {

	public const OPTION = 'g2rd_cmdrun_slot';

	/**
	 * Au-delà de ce silence (secondes sans signe de vie), le détenteur est tenu pour mort
	 * et le créneau peut être repris. Même hypothèse que la transaction d'une mise à jour
	 * protégée : aucune étape vivante ne dure davantage sans signe de vie.
	 */
	public const STALE_AFTER_SECONDS = UpdateTransaction::STALE_AFTER_SECONDS;

	/** Refus : une autre exécution en arrière-plan, vivante, tient le créneau. */
	public const REASON_RUN_IN_PROGRESS = 'run_in_progress';

	/**
	 * Refus : une mise à jour protégée, ou sa reprise par le cron du site, est en cours, et
	 * la commande remplace les fichiers d'une extension (AsyncCommands::TOUCHES_PLUGIN_FILES).
	 */
	public const REASON_UPDATE_TRANSACTION = 'update_transaction';

	/**
	 * Pas un refus « occupé » : la base a refusé la lecture ou l'écriture du créneau. La
	 * route répond 500, que la plateforme lit comme ambigu, puis rattrape par le numéro.
	 */
	public const REASON_STORE_UNAVAILABLE = 'store_unavailable';

	/** Valeur « non autochargée » comprise par toutes les versions de WordPress prises en charge. */
	private const AUTOLOAD_OFF = 'no';

	/**
	 * Lectures et écritures au plus par prise. Une écriture perdue se règle en relisant :
	 * d'ordinaire, le gagnant est vivant et la prise répond « occupé » dès la deuxième
	 * lecture. La troisième couvre un détenteur mort qui rend le créneau pendant qu'on le
	 * reprend (le créneau est alors libre). Au-delà, rien n'est pris : « INSERT IGNORE »
	 * peut aussi ne toucher aucune ligne sans rien écrire (erreur changée en
	 * avertissement), et c'est alors une écriture refusée.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Prend le créneau pour l'exécution `$command_id`, si le site est libre.
	 *
	 * Occupé (rien n'est écrit) :
	 *   - créneau tenu par une exécution vivante (signe de vie depuis 600 s au plus) :
	 *     `busy_with` (son numéro) et `busy_since` (sa prise) ;
	 *   - sinon, commande qui remplace les fichiers d'une extension et mise à jour
	 *     protégée en cours (UpdateTransaction::in_progress()) : `busy_since` (ouverture de
	 *     la transaction), sans numéro (ce n'est pas une exécution en arrière-plan).
	 * Libre, ou tenu par une exécution morte ou illisible : pris, par création ou par
	 * écriture conditionnelle sur la valeur relue.
	 *
	 * @param string $command_id Numéro de l'exécution, contrôlé par la route.
	 * @param string $command    Commande admise (AsyncCommands::is_admitted()).
	 * @param int    $now        Heure courante (secondes Unix).
	 * @return array{acquired: bool, busy_with?: string, busy_since?: int, reason?: string}
	 *         `reason` (REASON_*) pour tout refus ; REASON_STORE_UNAVAILABLE n'est pas
	 *         « occupé ».
	 */
	public static function acquire( string $command_id, string $command, int $now ): array {
		$value = wp_json_encode(
			[
				'command_id' => $command_id,
				'command'    => $command,
				'since'      => $now,
				'alive_at'   => $now,
			]
		);
		// Numéro ou commande non UTF-8 : jamais le cas après les contrôles de la route.
		// Rien n'est écrit, comme pour une écriture refusée.
		if ( ! is_string( $value ) ) {
			return self::unavailable();
		}

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$raw = self::read_raw();
			} catch ( StoreUnavailable $e ) {
				return self::unavailable();
			}

			$holder = null === $raw ? null : self::decode( $raw );
			if ( null !== $holder && self::is_alive( $holder, $now ) ) {
				return [
					'acquired'   => false,
					'busy_with'  => $holder['command_id'],
					'busy_since' => $holder['since'],
					'reason'     => self::REASON_RUN_IN_PROGRESS,
				];
			}
			if ( in_array( $command, AsyncCommands::TOUCHES_PLUGIN_FILES, true ) && UpdateTransaction::in_progress( $now ) ) {
				return self::busy_with_update_transaction();
			}

			$written = null === $raw ? self::insert( $value ) : self::replace( $raw, $value );
			if ( 1 === $written ) {
				return [ 'acquired' => true ];
			}
			if ( false === $written ) {
				return self::unavailable();
			}
			// Aucune ligne touchée : un autre processus a écrit entre-temps. On relit.
		}

		return self::unavailable();
	}

	/**
	 * Signe de vie du détenteur : `alive_at` passe à `$now`, la prise (`since`) reste.
	 * Sans effet si le créneau n'est pas (ou plus) tenu par `$command_id` : rendu, ou
	 * repris par une autre exécution après 600 s de silence. Un signe de vie ne reprend
	 * jamais un créneau.
	 *
	 * Appelé au milieu d'une mise à jour : ne lève jamais. Un signe de vie perdu (base
	 * indisponible, écriture concurrente) n'est pas rattrapé : le suivant le remplace, et
	 * la mort n'est déclarée qu'après 600 s.
	 */
	public static function touch( string $command_id, int $now ): void {
		try {
			$raw = self::read_raw();
		} catch ( StoreUnavailable $e ) {
			return;
		}
		$holder = null === $raw ? null : self::decode( $raw );
		if ( null === $raw || null === $holder || $holder['command_id'] !== $command_id ) {
			return;
		}

		$holder['alive_at'] = $now;
		$value              = wp_json_encode( $holder );
		if ( is_string( $value ) ) {
			self::replace( $raw, $value );
		}
	}

	/**
	 * Rend le créneau, seulement s'il est encore tenu par `$command_id` : suppression
	 * conditionnelle sur la valeur relue. Un créneau repris entre la lecture et la
	 * suppression reste à son nouveau détenteur.
	 *
	 * Ne lève jamais (fin d'une exécution, ramassage).
	 *
	 * @return bool Vrai si le créneau de `$command_id` a été supprimé. Faux s'il n'était
	 *              pas (ou plus) le sien, ou si la base a refusé : il sera alors repris
	 *              600 s après son dernier signe de vie.
	 */
	public static function release( string $command_id ): bool {
		try {
			$raw = self::read_raw();
		} catch ( StoreUnavailable $e ) {
			return false;
		}
		$holder = null === $raw ? null : self::decode( $raw );
		if ( null === $raw || null === $holder || $holder['command_id'] !== $command_id ) {
			return false;
		}

		return 1 === self::delete( $raw );
	}

	/**
	 * L'exécution qui tient le créneau, vivante ou non, relue en base. Null si aucune
	 * exécution identifiable ne le tient : créneau libre, ou valeur illisible (reprise à
	 * la prochaine prise).
	 *
	 * @return array{command_id: string, command: string, since: int, alive_at: int}|null
	 * @throws StoreUnavailable Si la base refuse la lecture : le créneau n'est pas pour
	 *                          autant libre.
	 */
	public static function holder(): ?array {
		$raw = self::read_raw();

		return null === $raw ? null : self::decode( $raw );
	}

	/**
	 * Le détenteur a-t-il donné signe de vie depuis 600 s au plus ?
	 *
	 * @param array{command_id: string, command: string, since: int, alive_at: int} $holder
	 */
	private static function is_alive( array $holder, int $now ): bool {
		return $now - $holder['alive_at'] <= self::STALE_AFTER_SECONDS;
	}

	/**
	 * Refus « mise à jour protégée en cours », daté de l'ouverture de la transaction
	 * quand elle est connue (lue par UpdateTransaction::in_progress(), juste avant).
	 *
	 * @return array{acquired: bool, busy_since?: int, reason: string}
	 */
	private static function busy_with_update_transaction(): array {
		$refusal = [ 'acquired' => false ];
		$txn     = UpdateTransaction::current();
		if ( null !== $txn && is_int( $txn['started_at'] ?? null ) ) {
			$refusal['busy_since'] = $txn['started_at'];
		}
		$refusal['reason'] = self::REASON_UPDATE_TRANSACTION;

		return $refusal;
	}

	/**
	 * @return array{acquired: bool, reason: string}
	 */
	private static function unavailable(): array {
		return [
			'acquired' => false,
			'reason'   => self::REASON_STORE_UNAVAILABLE,
		];
	}

	/**
	 * Décode la valeur du créneau. Null si elle est illisible : pas un objet JSON (une
	 * sérialisation PHP n'est jamais décodée), ou un champ absent ou d'un autre type.
	 *
	 * @return array{command_id: string, command: string, since: int, alive_at: int}|null
	 */
	private static function decode( string $raw ): ?array {
		$value = json_decode( $raw, true );
		if ( ! is_array( $value ) ) {
			return null;
		}
		$command_id = $value['command_id'] ?? null;
		$command    = $value['command'] ?? null;
		$since      = $value['since'] ?? null;
		$alive_at   = $value['alive_at'] ?? null;
		if ( ! is_string( $command_id ) || ! is_string( $command ) || ! is_int( $since ) || ! is_int( $alive_at ) ) {
			return null;
		}

		return [
			'command_id' => $command_id,
			'command'    => $command,
			'since'      => $since,
			'alive_at'   => $alive_at,
		];
	}

	/**
	 * Valeur brute du créneau, lue en base. Null s'il est libre.
	 *
	 * @throws StoreUnavailable Si la base renvoie une erreur (cf. RunStore::read_raw()).
	 */
	private static function read_raw(): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture sans cache voulue : un cache d'objets persistant servirait un verrou périmé.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);

		// get_var() rend null aussi quand la requête échoue : seule `last_error`, remise
		// à vide par wpdb avant chaque requête, distingue l'échec de l'absence.
		$db_error = (string) $wpdb->last_error;
		if ( '' !== $db_error ) {
			throw new StoreUnavailable( $db_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- l'argument n'est pas le message (texte fixe, StoreUnavailable::MESSAGE) : l'erreur de la base, gardée pour le journal, jamais affichée.
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Crée le créneau s'il n'existe pas.
	 *
	 * @return int|false 1 s'il est créé ; 0 s'il existe déjà ; false si la base refuse.
	 */
	private static function insert( string $value ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- création atomique voulue (add_option lit puis écrit) ; option jamais lue par get_option(), cache vidé juste après.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				self::OPTION,
				$value,
				self::AUTOLOAD_OFF
			)
		);
		wp_cache_delete( self::OPTION, 'options' );

		return false === $inserted ? false : (int) $inserted;
	}

	/**
	 * Remplace le créneau, seulement s'il vaut encore `$expected` (la valeur relue).
	 *
	 * @return int|false 1 s'il est remplacé ; 0 s'il a changé ou disparu entre-temps ;
	 *                   false si la base refuse.
	 */
	private static function replace( string $expected, string $value ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- écriture conditionnelle atomique voulue (update_option lit puis écrit) ; cache vidé juste après.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$expected
			)
		);
		wp_cache_delete( self::OPTION, 'options' );

		return false === $updated ? false : (int) $updated;
	}

	/**
	 * Supprime le créneau, seulement s'il vaut encore `$expected` (la valeur relue).
	 *
	 * @return int|false 1 s'il est supprimé ; 0 s'il a changé ou disparu entre-temps ;
	 *                   false si la base refuse.
	 */
	private static function delete( string $expected ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- suppression conditionnelle voulue (delete_option ne compare pas la valeur) : un créneau repris entre la lecture et la suppression est gardé ; cache vidé juste après.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$expected
			)
		);
		wp_cache_delete( self::OPTION, 'options' );

		return false === $deleted ? false : (int) $deleted;
	}
}
