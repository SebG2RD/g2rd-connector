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
 * close()) et le contrôle de reprise du cron, qui ferme une transaction morte après
 * avoir restauré l'extension. La requête ne réécrit donc jamais la copie lue dans son
 * cache d'options : elle relit la base, et ne réécrit rien si la transaction a été
 * fermée ou remplacée entre-temps (cf. still_open()).
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
	 * Identité de la transaction ouverte par ce processus (open()), oubliée à close().
	 * step() et touch() ne réécrivent qu'elle : après une relecture, le cache
	 * d'options contient ce que la base contient, et une transaction ouverte entre-temps
	 * par une autre mise à jour ne doit pas passer pour celle de cette requête.
	 */
	private static ?string $opened = null;

	/**
	 * Ouvre une transaction. Échoue si une autre est encore fraîche.
	 *
	 * @param array<string, mixed> $context plugin_file, was_active, network_active, version_before…
	 */
	public static function open( array $context, int $now ): bool {
		$current = self::current();
		if ( null !== $current && ! self::is_stale( $current, $now ) ) {
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
		self::$opened = self::identity( $txn );
		return true;
	}

	/**
	 * Fait avancer la transaction ouverte par cette requête. Sans effet si elle a été
	 * fermée ou remplacée par un autre processus (cf. still_open()).
	 *
	 * @param array<string, mixed> $changes
	 */
	public static function step( string $step, array $changes = [], ?int $now = null ): void {
		$current = self::still_open();
		if ( null === $current ) {
			return;
		}
		$current['step']       = $step;
		$current['updated_at'] = $now ?? time();
		self::write( array_merge( $current, $changes ) );
	}

	/**
	 * Signe de vie : rafraîchit `updated_at` sans changer d'étape, après une
	 * opération qui peut être longue (mesure de santé, restauration). Sans effet si
	 * aucune transaction n'est ouverte, ou si elle a été fermée ou remplacée par un
	 * autre processus (cf. still_open()).
	 */
	public static function touch( ?int $now = null ): void {
		$current = self::still_open();
		if ( null === $current ) {
			return;
		}
		$current['updated_at'] = $now ?? time();
		self::write( $current );
	}

	public static function close(): void {
		self::$opened = null;
		delete_option( self::OPTION_KEY );
		// Cache objet désynchronisé (ligne déjà absente en base mais encore servie par
		// le cache) : delete_option() renvoie alors faux sans vider le cache, et la
		// transaction resterait vue comme ouverte. On le vide dans tous les cas.
		wp_cache_delete( self::OPTION_KEY, 'options' );
	}

	/**
	 * Identité d'une transaction : son identifiant, son extension et sa date
	 * d'ouverture. Une transaction ouverte par une version d'avant l'identifiant
	 * reste reconnue par les deux autres.
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function identity( array $txn ): string {
		return (string) ( $txn['id'] ?? '' ) . '|' . (string) ( $txn['plugin_file'] ?? '' ) . '|' . (int) ( $txn['started_at'] ?? 0 );
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
	 * Les fichiers du plugin peuvent être dans un état intermédiaire à cette étape ?
	 *
	 * @param array<string, mixed> $txn
	 */
	public static function files_may_be_dirty( array $txn ): bool {
		return in_array( $txn['step'] ?? '', [ self::STEP_UPGRADING, self::STEP_ROLLING_BACK ], true );
	}

	/**
	 * La transaction de cette requête, relue en base, ou null si elle a disparu ou
	 * changé d'identité entre-temps.
	 *
	 * Le contrôle de reprise (autre processus) peut fermer la transaction — après
	 * avoir restauré l'extension — pendant que la requête, encore vivante, en garde
	 * la copie dans son cache d'options. Réécrire cette copie recréerait une
	 * transaction fermée, avec une date fraîche, sur des fichiers déjà restaurés.
	 * D'où la relecture sans cache (une requête SQL, quelques fois par mise à jour
	 * protégée). Reste la fenêtre entre cette relecture et l'écriture qui suit
	 * (quelques millisecondes).
	 *
	 * L'identité attendue est celle retenue à open() ; à défaut (transaction que ce
	 * processus n'a pas ouverte), celle de la copie en cache.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function still_open(): ?array {
		$expected = self::$opened;
		if ( null === $expected ) {
			$cached = self::current();
			if ( null === $cached ) {
				return null;
			}
			$expected = self::identity( $cached );
		}
		// Non autochargée (cf. write()) : get_option() la garde dans le groupe
		// `options` du cache, sous son nom.
		wp_cache_delete( self::OPTION_KEY, 'options' );
		$stored = self::current();
		if ( null === $stored || self::identity( $stored ) !== $expected ) {
			return null;
		}
		return $stored;
	}

	/**
	 * @param array<string, mixed> $txn
	 */
	private static function write( array $txn ): void {
		update_option( self::OPTION_KEY, $txn, false );
	}
}
