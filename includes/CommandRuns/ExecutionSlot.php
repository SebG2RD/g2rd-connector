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
 * entre la lecture et l'écriture : jamais deux détenteurs. Ces requêtes sont celles
 * d'OptionRow, partagées avec RunStore.
 *
 * Toujours relu en base, jamais par get_option() : c'est un verrou, et un cache d'objets
 * persistant (LiteSpeed Object Cache) pourrait le servir libre alors qu'il vient d'être
 * pris. Une lecture que la base refuse n'est jamais prise pour un créneau libre.
 *
 * Le numéro d'exécution (RunStore::ID_PATTERN) est contrôlé à la prise, avant toute
 * écriture, et à chaque lecture : une valeur dont le numéro est mal formé est illisible,
 * donc reprise. La plateforme ne reçoit ainsi dans `busy_with` qu'un numéro qu'elle peut
 * rapprocher d'une de ses exécutions. Le créneau n'est pas réentrant : une exécution qui
 * le tient le trouve occupé par elle-même.
 *
 * Limite connue (rendu perdu) : une exécution finie dont le rendu échoue (base
 * indisponible à cet instant) laisse le créneau pris jusqu'à 600 s après son dernier signe
 * de vie, puis il est repris par la prise suivante. Le ramassage (RunReaper) ne rend, lui,
 * que le créneau d'une exécution qu'il clôt (morte en route).
 *
 * Limite connue (multisite) : le créneau est rangé dans la table des options du blog
 * courant ($wpdb->options), donc un créneau par blog, comme UpdateTransaction (option
 * `g2rd_update_txn`, elle aussi par blog). Or le cœur, les extensions, les thèmes et les
 * traductions sont des fichiers partagés par tout le réseau : deux blogs d'un même réseau
 * suivis comme deux sites pourraient lancer update_core ou update_theme au même moment.
 * Ce n'est pas une régression (la transaction a la même portée), mais un créneau valable
 * pour tout le réseau demanderait de le ranger dans la table des métadonnées du réseau
 * ($wpdb->sitemeta). État livré avec la route d'acceptation : un créneau par blog, limite
 * écrite telle quelle dans le README (« Une exécution à la fois par site ») ; le choix d'un
 * créneau de réseau n'est pas tranché, il est remonté au propriétaire à la relecture de la
 * documentation (tâche 25).
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

	/**
	 * Lectures et écritures au plus par prise. Une écriture perdue se règle en relisant :
	 * d'ordinaire, le gagnant est vivant et la prise répond « occupé » dès la deuxième
	 * lecture. Si le détenteur mort rend le créneau pendant qu'on le reprend, la deuxième
	 * lecture le trouve libre et la prise le crée ; la troisième lecture couvre une autre
	 * prise qui le crée au même instant. Au-delà, rien n'est pris : « INSERT IGNORE » peut
	 * aussi ne toucher aucune ligne sans rien écrire (erreur changée en avertissement), et
	 * c'est alors une écriture refusée.
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
	 * Numéro mal formé (RunStore::ID_PATTERN), ou commande impossible à encoder : jamais le
	 * cas après les contrôles de la route (appelant fautif). Rien n'est écrit, comme pour
	 * une écriture refusée : un créneau pris sous un numéro mal formé serait illisible, donc
	 * repris par la prise suivante, et le site aurait deux détenteurs.
	 *
	 * @param string $command_id Numéro de l'exécution, contrôlé par la route.
	 * @param string $command    Commande admise (AsyncCommands::is_admitted()).
	 * @param int    $now        Heure courante (secondes Unix).
	 * @return array{acquired: bool, busy_with?: string, busy_since?: int, reason?: string}
	 *         `reason` (REASON_*) pour tout refus ; REASON_STORE_UNAVAILABLE n'est pas
	 *         « occupé ».
	 */
	public static function acquire( string $command_id, string $command, int $now ): array {
		if ( ! self::is_valid_id( $command_id ) ) {
			return self::unavailable();
		}
		$value = wp_json_encode(
			[
				'command_id' => $command_id,
				'command'    => $command,
				'since'      => $now,
				'alive_at'   => $now,
			]
		);
		// Commande non UTF-8 : jamais le cas après les contrôles de la route. Rien n'est
		// écrit, comme pour une écriture refusée.
		if ( ! is_string( $value ) ) {
			return self::unavailable();
		}

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$raw = OptionRow::read_raw( self::OPTION );
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

			$written = null === $raw
				? OptionRow::insert_ignore( self::OPTION, $value )
				: OptionRow::replace( self::OPTION, $raw, $value );
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
			$raw = OptionRow::read_raw( self::OPTION );
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
			OptionRow::replace( self::OPTION, $raw, $value );
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
			$raw = OptionRow::read_raw( self::OPTION );
		} catch ( StoreUnavailable $e ) {
			return false;
		}
		$holder = null === $raw ? null : self::decode( $raw );
		if ( null === $raw || null === $holder || $holder['command_id'] !== $command_id ) {
			return false;
		}

		return 1 === OptionRow::delete_if_unchanged( self::OPTION, $raw );
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
		$raw = OptionRow::read_raw( self::OPTION );

		return null === $raw ? null : self::decode( $raw );
	}

	/**
	 * Le détenteur a-t-il donné signe de vie depuis 600 s au plus ?
	 *
	 * Une prise (`since`) ou un signe de vie (`alive_at`) daté de plus de 600 s dans le
	 * futur est tenu pour illisible, donc repris comme une valeur abîmée. Sans cette
	 * borne, une horloge qui recule (resynchronisation NTP, migration de machine) ou un
	 * entier démesuré laisserait le site « occupé » bien au-delà de 600 s, sans limite, et
	 * la plateforme finirait par abandonner (site_busy_timeout) sans cause réelle. La marge
	 * de 600 s couvre une heure courante lue un peu plus tôt par l'appelant ; une horloge
	 * qui recule de moins de 600 s retarde la reprise d'autant, 1 200 s au plus.
	 *
	 * @param array{command_id: string, command: string, since: int, alive_at: int} $holder
	 */
	private static function is_alive( array $holder, int $now ): bool {
		$horizon = $now + self::STALE_AFTER_SECONDS;
		if ( $holder['alive_at'] > $horizon || $holder['since'] > $horizon ) {
			return false;
		}

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
	 * sérialisation PHP n'est jamais décodée), un champ absent ou d'un autre type, ou un
	 * numéro mal formé (RunStore::ID_PATTERN), que la plateforme ne saurait rapprocher
	 * d'aucune de ses exécutions.
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
		if ( ! self::is_valid_id( $command_id ) ) {
			return null;
		}

		return [
			'command_id' => $command_id,
			'command'    => $command,
			'since'      => $since,
			'alive_at'   => $alive_at,
		];
	}

	private static function is_valid_id( string $command_id ): bool {
		return 1 === preg_match( RunStore::ID_PATTERN, $command_id );
	}
}
