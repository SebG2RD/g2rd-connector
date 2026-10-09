<?php
/**
 * Journal de transaction d'une mise à jour protégée : ce qui est en cours, pour
 * qu'une requête morte en route (délai PHP dépassé, mémoire) puisse être rejouée
 * — par le filet de shutdown d'abord, par le cron ensuite (cf ProtectedUpdate::recover).
 *
 * Une seule transaction à la fois par site : la plateforme sérialise déjà ses
 * commandes, le verrou protège contre un rejeu concurrent.
 *
 * Deux processus y écrivent : la requête qui mène la mise à jour (step(), touch(),
 * close()) et celui qui reprend une transaction morte (contrôle du cron, ou mise à
 * jour suivante) : il la réserve (reserve()) avant de restaurer l'extension, puis la
 * ferme. Chaque processus n'agit que sur la transaction qu'il tient (ouverte, ou
 * réservée), relue en base sans passer par son cache d'options : jamais sur celle
 * d'une autre mise à jour, ni sur une transaction fermée ou réservée entre-temps
 * (cf. still_open(), close()).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class UpdateTransaction {

	public const OPTION_KEY = 'g2rd_update_txn';

	/**
	 * Trace d'une reprise faite sur une transaction que close() n'a pas pu retirer
	 * (identité de la transaction, date, résultat). Elle empêche de restaurer
	 * l'extension une seconde fois à chaque passage (cf. ProtectedUpdate::recover()).
	 * Non autochargée, écrite seulement dans ce cas d'échec.
	 */
	public const RECOVERY_TRACE_KEY = 'g2rd_update_txn_recovery';

	/**
	 * Au-delà, une transaction encore ouverte est tenue pour morte (cron). Suppose
	 * qu'aucune étape d'une mise à jour vivante ne dure davantage entre deux
	 * rafraîchissements (step(), touch() ; cf. RestorePointPurgeJob::RECOVERY_CHECK_DELAY).
	 */
	public const STALE_AFTER_SECONDS = 600;

	public const STEP_SNAPSHOT     = 'snapshot';
	public const STEP_UPGRADING    = 'upgrading';
	public const STEP_HEALTH       = 'health';
	public const STEP_ROLLING_BACK = 'rolling_back';

	/**
	 * Reprise en cours (reserve()) : la transaction morte est réservée par le processus
	 * qui la reprend, AVANT toute restauration. Fraîche, aucun autre processus ne la
	 * reprend et aucune mise à jour ne s'ouvre par-dessus (open()). L'étape de la mise
	 * à jour morte est gardée dans `recovering_from`, le jeton du processus qui la
	 * reprend dans `recovery_token`.
	 */
	public const STEP_RECOVERING = 'recovering';

	/**
	 * Reprises commencées (réservations, cf. reserve() et `recovery_attempts`) au-delà
	 * desquelles la reprise ne restaure plus l'extension (cf. ProtectedUpdate::recover()).
	 * Une reprise qui meurt en route (erreur fatale pendant la restauration) ne
	 * consigne rien et laisse l'extension désactivée : la même cause la ferait mourir
	 * de nouveau à chaque passage du contrôle, toutes les 11 minutes environ. Trois
	 * tentatives : celle du contrôle, celle du filet de shutdown qui la suit aussitôt,
	 * puis celle du contrôle suivant (cause passagère : délai dépassé, serveur chargé).
	 */
	public const MAX_RECOVERY_ATTEMPTS = 3;

	/**
	 * Refus d'une opération sur les fichiers d'une extension pendant une mise à jour
	 * protégée ou une reprise (ProtectedUpdate::run(), RestoreCommands::rollback_plugin()).
	 * Déjà connu de la plateforme, qui l'affiche tel quel : rien n'a changé sur le site.
	 */
	public const BUSY_MESSAGE = 'another protected update is still running on this site (or an interrupted one is being recovered); nothing was changed, retry in a few minutes';

	/**
	 * Transaction que tient ce processus : [identité, jeton de reprise]. Jeton nul pour
	 * celle qu'il a ouverte (open()), le sien pour celle qu'il reprend (reserve()).
	 * Oubliée à close().
	 *
	 * step(), touch() et close() n'agissent que sur elle : après une relecture, le
	 * cache d'options contient ce que la base contient, et une transaction ouverte
	 * entre-temps par une autre mise à jour, ou réservée par une reprise, ne doit pas
	 * passer pour celle de cette requête.
	 *
	 * @var array{0: string, 1: string|null}|null
	 */
	private static ?array $held = null;

	/**
	 * Ouvre une transaction. Échoue si une autre mise à jour, ou une reprise, est
	 * encore en cours (cf. is_live()).
	 *
	 * @param array<string, mixed> $context plugin_file, was_active, network_active, version_before…
	 */
	public static function open( array $context, int $now ): bool {
		// Relue sans cache : une reprise réservée par un autre processus doit être vue.
		$current = self::fresh();
		if ( null !== $current && self::is_live( $current, $now ) ) {
			return false;
		}
		$txn = array_merge(
			$context,
			[
				// Distingue deux transactions de la même extension ouvertes à la même
				// seconde (cf. identity()).
				'id'         => bin2hex( random_bytes( 8 ) ),
				'step'       => self::STEP_SNAPSHOT,
				'started_at' => $now,
				'updated_at' => $now,
			]
		);
		self::write( $txn );
		self::$held = [ self::identity( $txn ), null ];
		return true;
	}

	/**
	 * Fait avancer la transaction ouverte par cette requête. Sans effet si elle a été
	 * fermée, remplacée ou réservée par un autre processus (cf. still_open()).
	 *
	 * @param array<string, mixed> $changes
	 * @return bool Faux si la transaction n'est plus celle de cette requête : la
	 *              mise à jour ne doit pas continuer sans elle (cf. ProtectedUpdate::run()).
	 */
	public static function step( string $step, array $changes = [], ?int $now = null ): bool {
		$current = self::still_open();
		if ( null === $current ) {
			return false;
		}
		$current['step']       = $step;
		$current['updated_at'] = $now ?? time();
		self::write( array_merge( $current, $changes ) );
		return true;
	}

	/**
	 * Signe de vie : rafraîchit `updated_at` sans changer d'étape, après une
	 * opération qui peut être longue (mesure de santé, restauration). Sans effet si
	 * aucune transaction n'est ouverte, ou si elle a été fermée, remplacée ou
	 * réservée par un autre processus (cf. still_open()).
	 */
	public static function touch( ?int $now = null ): void {
		$current = self::still_open();
		if ( null === $current ) {
			return;
		}
		$current['updated_at'] = $now ?? time();
		self::write( $current );
	}

	/**
	 * Ferme la transaction que tient ce processus, et elle seulement : relue en base
	 * sans cache, elle n'est retirée que si elle porte encore son identité (et, pour
	 * une reprise, son jeton). Une transaction ouverte entre-temps par une autre mise
	 * à jour, ou réservée par une reprise, reste en place.
	 */
	public static function close(): void {
		$held       = self::$held;
		self::$held = null;
		// La relecture vide aussi la copie du cache : un cache objet désynchronisé
		// (ligne déjà absente en base mais encore servie par le cache) ne fait plus
		// voir la transaction comme ouverte.
		$stored = self::fresh();
		if ( null === $held || null === $stored || ! self::is( $stored, $held ) ) {
			return;
		}
		self::delete();
	}

	/**
	 * Réserve une transaction pour la reprendre (ProtectedUpdate::recover()) : étape
	 * `recovering`, date fraîche, jeton de ce processus — AVANT toute restauration.
	 * Tant qu'elle est fraîche, aucun autre processus ne la reprend et aucune mise à
	 * jour ne s'ouvre par-dessus.
	 *
	 * Refusée (null) si la transaction a disparu ou changé depuis que l'appelant l'a
	 * lue (réservée par une autre reprise, remplacée), si elle n'est pas morte, ou —
	 * reprise forcée du filet de shutdown (`$own`) — si ce n'est pas celle que tient
	 * ce processus.
	 *
	 * Deux reprises parties au même instant (contrôle du cron et mise à jour) écrivent
	 * chacune leur jeton : la relecture qui suit l'écriture ne laisse continuer que
	 * celle dont le jeton est resté en base. Reste la fenêtre où chacune relit avant
	 * l'écriture de l'autre (quelques millisecondes) : la réservation n'est pas
	 * atomique. add_option() ne la rendrait pas atomique : WordPress l'écrit en
	 * `INSERT … ON DUPLICATE KEY UPDATE`, le second appel concurrent réussit aussi.
	 * Une réservation atomique demanderait une écriture conditionnelle en SQL direct
	 * (`UPDATE … WHERE option_value = <valeur lue>`, une seule ligne modifiée pour
	 * le gagnant) : limite connue, suivie à part.
	 *
	 * @param array<string, mixed> $txn Telle que lue par l'appelant.
	 * @return array<string, mixed>|null La transaction réservée.
	 */
	public static function reserve( array $txn, int $now, bool $own = false ): ?array {
		$stored = self::fresh();
		if ( null === $stored || ! self::is( $stored, self::holder_of( $txn ) ) ) {
			return null;
		}
		$allowed = $own
			? null !== self::$held && self::is( $stored, self::$held )
			: self::is_stale( $stored, $now );
		if ( ! $allowed ) {
			return null;
		}

		$token    = bin2hex( random_bytes( 8 ) );
		$reserved = array_merge(
			$stored,
			[
				'step'            => self::STEP_RECOVERING,
				// Reprise d'une reprise interrompue : l'étape d'origine reste celle de
				// la mise à jour morte (inconnue : null, cf. files_may_be_dirty()).
				'recovering_from'   => self::STEP_RECOVERING === $stored['step'] ? ( $stored['recovering_from'] ?? null ) : $stored['step'],
				'recovery_token'    => $token,
				// Celle-ci comprise, jamais remis à zéro : une reprise morte en route compte.
				'recovery_attempts' => self::recovery_attempts( $stored ) + 1,
				'updated_at'        => $now,
			]
		);
		self::write( $reserved );

		$check  = self::fresh();
		$holder = [ self::identity( $reserved ), $token ];
		if ( null === $check || ! self::is( $check, $holder ) ) {
			return null;
		}
		self::$held = $holder;
		return $check;
	}

	/**
	 * Retire une transaction déjà reprise (trace de reprise) que close() n'avait pas
	 * pu retirer — si c'est toujours elle en base (relue sans cache) —, puis sa trace,
	 * qui ne sert qu'à elle. La trace reste tant que la transaction n'a pas pu être
	 * retirée : sans elle, chaque passage restaurerait l'extension de nouveau.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function discard( array $txn ): void {
		$stored = self::fresh();
		if ( null === $stored || ! self::is( $stored, self::holder_of( $txn ) ) ) {
			return;
		}
		if ( self::delete() && self::recovery_already_attempted( $txn ) ) {
			delete_option( self::RECOVERY_TRACE_KEY );
		}
	}

	/**
	 * La transaction que tient ce processus, relue en base sans cache, ou null : il
	 * n'en tient aucune, ou elle a été fermée, remplacée ou réservée par un autre
	 * processus entre-temps (cf. ProtectedUpdate::recover_on_shutdown()).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function held(): ?array {
		if ( null === self::$held ) {
			return null;
		}
		$stored = self::fresh();
		return null !== $stored && self::is( $stored, self::$held ) ? $stored : null;
	}

	/**
	 * Une mise à jour protégée, ou une reprise, est-elle en cours sur ce site ? Relue
	 * en base sans cache (cf. fresh()). Pour refuser, avant tout changement, une
	 * opération sur les fichiers d'une extension qui pourrait croiser une restauration
	 * (rollback manuel, cf. RestoreCommands::rollback_plugin()).
	 */
	public static function in_progress( int $now ): bool {
		$current = self::fresh();
		return null !== $current && self::is_live( $current, $now );
	}

	/**
	 * Même transaction et même reprise : même identité, même jeton de reprise (aucun
	 * pour une transaction qui n'est pas réservée). Une reprise qui a dépassé 10 min a
	 * pu être réservée de nouveau par une autre (même identité, autre jeton) : sa
	 * réservation n'est plus celle de la première (cf. ProtectedUpdate::recover()).
	 *
	 * @param array<string, mixed> $txn
	 * @param array<string, mixed> $other
	 */
	public static function same_reservation( array $txn, array $other ): bool {
		return self::is( $txn, self::holder_of( $other ) );
	}

	/**
	 * Identité d'une transaction : son identifiant, son extension et sa date
	 * d'ouverture. Une transaction ouverte par une version d'avant l'identifiant
	 * reste reconnue par les deux autres. Une réservation (reserve()) ne la change pas.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function identity( array $txn ): string {
		return (string) ( $txn['id'] ?? '' ) . '|' . (string) ( $txn['plugin_file'] ?? '' ) . '|' . (int) ( $txn['started_at'] ?? 0 );
	}

	/**
	 * Reprises commencées sur cette transaction (réservations, celle en cours
	 * comprise) ; 0 si elle n'a jamais été reprise, ou si elle a été écrite par une
	 * version d'avant ce compte.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function recovery_attempts( array $txn ): int {
		return max( 0, (int) ( $txn['recovery_attempts'] ?? 0 ) );
	}

	/**
	 * Une reprise a-t-elle déjà été faite sur cette transaction, sans que close()
	 * parvienne à la retirer ?
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function recovery_already_attempted( array $txn ): bool {
		$trace = get_option( self::RECOVERY_TRACE_KEY, null );
		return is_array( $trace ) && self::identity( $txn ) === ( $trace['txn'] ?? null );
	}

	/**
	 * Consigne qu'une reprise a été faite sur cette transaction, toujours là après
	 * close().
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function record_recovery_attempt( array $txn, string $outcome, int $now ): void {
		update_option(
			self::RECOVERY_TRACE_KEY,
			[
				'txn'         => self::identity( $txn ),
				'plugin_file' => (string) ( $txn['plugin_file'] ?? '' ),
				'outcome'     => $outcome,
				'at'          => $now,
			],
			false
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$stored = get_option( self::OPTION_KEY, null );
		return is_array( $stored ) && isset( $stored['step'], $stored['plugin_file'] ) ? $stored : null;
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	public static function is_stale( array $txn, int $now ): bool {
		return ( $now - (int) ( $txn['updated_at'] ?? 0 ) ) > self::STALE_AFTER_SECONDS;
	}

	/**
	 * Une mise à jour, ou une reprise, est-elle encore en cours sous cette
	 * transaction ? Fraîche, et pas déjà reprise : une transaction dont la reprise est
	 * tracée (close() n'a pas pu la retirer) ne protège plus rien, même réservée il y
	 * a moins de 10 minutes.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function is_live( array $txn, int $now ): bool {
		return ! self::is_stale( $txn, $now ) && ! self::recovery_already_attempted( $txn );
	}

	/**
	 * Les fichiers du plugin peuvent être dans un état intermédiaire à cette étape ?
	 *
	 * Une reprise interrompue (`recovering`, processus tué en route) a pu commencer à
	 * restaurer si la mise à jour morte avait laissé des fichiers à moitié remplacés :
	 * on regarde l'étape d'origine, et on suppose le pire si elle est inconnue.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function files_may_be_dirty( array $txn ): bool {
		$step = $txn['step'] ?? '';
		if ( self::STEP_RECOVERING === $step ) {
			$step = $txn['recovering_from'] ?? null;
			if ( ! is_string( $step ) ) {
				return true;
			}
		}
		return in_array( $step, [ self::STEP_UPGRADING, self::STEP_ROLLING_BACK ], true );
	}

	/**
	 * La transaction de cette requête, relue en base, ou null si elle a disparu, changé
	 * d'identité ou été réservée par une reprise entre-temps.
	 *
	 * Le contrôle de reprise (autre processus) peut fermer la transaction — après
	 * avoir restauré l'extension — pendant que la requête, encore vivante, en garde
	 * la copie dans son cache d'options. Réécrire cette copie recréerait une
	 * transaction fermée, avec une date fraîche, sur des fichiers déjà restaurés.
	 * D'où la relecture sans cache (une requête SQL, quelques fois par mise à jour
	 * protégée). Reste la fenêtre entre cette relecture et l'écriture qui suit
	 * (quelques millisecondes).
	 *
	 * L'identité attendue est celle que tient ce processus ; à défaut (transaction
	 * qu'il n'a pas ouverte), celle de la copie en cache — jamais une reprise en cours.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function still_open(): ?array {
		$holder = self::$held;
		if ( null === $holder ) {
			$cached = self::current();
			if ( null === $cached ) {
				return null;
			}
			$holder = [ self::identity( $cached ), null ];
		}
		$stored = self::fresh();
		return null !== $stored && self::is( $stored, $holder ) ? $stored : null;
	}

	/**
	 * La transaction en base, relue sans le cache d'options — y compris quand elle a
	 * été lue absente plus tôt dans la requête.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function fresh(): ?array {
		// Non autochargée (cf. write()) : get_option() la garde dans le groupe
		// `options` du cache, sous son nom.
		wp_cache_delete( self::OPTION_KEY, 'options' );
		// Lue absente plus tôt (par exemple par la reprise qui précède l'ouverture),
		// elle est mémorisée dans `notoptions`, et get_option() la rendrait absente
		// sans relire la base, même créée depuis par un autre processus. On l'en
		// retire, comme WordPress quand il l'ajoute ; rien n'est écrit si elle n'y est
		// pas. Le reste de `notoptions` est gardé (autres options lues absentes).
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION_KEY ] ) ) {
			unset( $notoptions[ self::OPTION_KEY ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
		return self::current();
	}

	/**
	 * Cette transaction est-elle celle de ce détenteur (même identité, même jeton de
	 * reprise) ?
	 *
	 * @param array<string, mixed>              $txn
	 * @param array{0: string, 1: string|null} $holder
	 */
	private static function is( array $txn, array $holder ): bool {
		return self::identity( $txn ) === $holder[0] && self::token( $txn ) === $holder[1];
	}

	/**
	 * @param array<string, mixed> $txn
	 * @return array{0: string, 1: string|null}
	 */
	private static function holder_of( array $txn ): array {
		return [ self::identity( $txn ), self::token( $txn ) ];
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	private static function token( array $txn ): ?string {
		$token = $txn['recovery_token'] ?? null;
		return is_string( $token ) ? $token : null;
	}

	/**
	 * @return bool Vrai si la ligne a été retirée de la base (retour de delete_option()).
	 */
	private static function delete(): bool {
		$deleted = delete_option( self::OPTION_KEY );
		// Cache objet désynchronisé : delete_option() peut renvoyer faux sans vider le
		// cache, et la transaction resterait vue comme ouverte. On le vide dans tous les cas.
		wp_cache_delete( self::OPTION_KEY, 'options' );
		return $deleted;
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	private static function write( array $txn ): void {
		update_option( self::OPTION_KEY, $txn, false );
	}
}
