<?php
/**
 * Mesure quotidienne des volumes (wp-content, médias, base) en tâche de fond, gardée
 * datée ; le snapshot ne fait que lire ce cache.
 *
 * Rythme
 * ------
 * refresh_now() est branché sur la tâche biquotidienne de la purge locale
 * (RestorePointPurgeJob::HOOK, phase tirée au hasard, cf. Cron\Phase). Aucune
 * tentative moins de RETRY_GUARD_SECONDS (20 h) après la précédente, réussie ou non :
 * un passage sur deux mesure, et le cache a 24 h en régime normal (davantage
 * seulement si la tâche planifiée ne passe pas). Pourquoi 20 h et non 24 : la tâche
 * est déclenchée par WP-Cron, donc rarement à la seconde près ; avec une garde de
 * 24 h, le passage du lendemain parti quelques minutes plus tôt que la veille serait
 * refusé, et la mesure attendrait 12 h de plus. Avec 20 h, le passage de la
 * mi-journée est toujours refusé, celui du lendemain toujours accepté.
 *
 * Une tentative est notée (`attempted_at`) AVANT de mesurer : une mesure tuée par le
 * serveur (délai, mémoire : aucun `finally` ne s'exécute) n'est pas relancée avant
 * 20 h, et read() la dit ratée passé KILLED_AFTER_SECONDS.
 *
 * Passages concurrents
 * --------------------
 * Verrou `GET_LOCK` non bloquant, commun au réseau (Support\MysqlLock) : tenu ailleurs
 * → rien (l'autre processus mesure). Indisponible (fonction refusée, cluster Galera)
 * → mesure sans verrou, sous la même garde de 20 h : sinon ce serveur ne mesurerait
 * jamais. Au pire, deux processus partis à la même seconde mesurent chacun une fois.
 * Sous verrou, l'option est relue APRÈS purge du cache d'objets (sa valeur, et
 * `notoptions` si elle était absente) : sans cela, ce processus relirait la valeur
 * lue avant le verrou et ne verrait pas une mesure écrite entre-temps.
 *
 * Option de RÉSEAU, non autochargée (OPTION, `{at, attempted_at, sizes}`) : en
 * multisite, wp-content et la base sont communs, une mesure vaut pour tous les blogs.
 * En site simple, update_site_option() écrit une option non autochargée. Les médias
 * sont ceux du site principal, qui contiennent `uploads/sites/N` de tous les blogs.
 * Limite : sur un réseau antérieur à WordPress 3.5 (`ms-files`, médias dans
 * `wp-content/blogs.dir`), les médias des blogs secondaires sont comptés dans
 * `wp_content_bytes`, pas dans `uploads_bytes`.
 *
 * Risque connu : un site sans WP-Cron (DISABLE_WP_CRON sans vrai cron) ne mesure
 * jamais ; il reste `pending`, dit comme tel par la plateforme.
 *
 * Aucun chemin ni message d'erreur gardé : des totaux, un statut et des dates. Aucun
 * effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Environment;

use G2RD\Connector\Support\MysqlLock;

final class SizeReport {

	/** Option de réseau : `{at, attempted_at, sizes}`. */
	public const OPTION = 'g2rd_connector_environment';

	/**
	 * Garde : aucune tentative moins de 20 h après la précédente (cf. en-tête).
	 *
	 * Écrit en secondes littérales : `HOUR_IN_SECONDS` n'est défini qu'à l'exécution
	 * par WordPress.
	 */
	public const RETRY_GUARD_SECONDS = 72000;

	/** Budget de temps par défaut du parcours des fichiers (filtre `g2rd_connector_environment_scan_seconds`). */
	public const DEFAULT_SECONDS = 5;

	/** Budget d'entrées par défaut du parcours (filtre `g2rd_connector_environment_scan_entries`). */
	public const DEFAULT_ENTRIES = 100000;

	/**
	 * Au-delà, une tentative restée sans résultat est tenue pour tuée par le serveur
	 * (délai, mémoire) : le parcours des fichiers est borné à 20 s au plus (MAX_SECONDS).
	 */
	public const KILLED_AFTER_SECONDS = 600;

	/** Mesure complète. */
	public const STATUS_OK = 'ok';

	/** Borne atteinte : les valeurs sont un minimum. */
	public const STATUS_PARTIAL = 'partial';

	/** Jamais mesuré. */
	public const STATUS_PENDING = 'pending';

	/** Dernière tentative ratée : valeurs de la dernière mesure réussie, ou null. */
	public const STATUS_FAILED = 'failed';

	/** Bornes acceptées pour les filtres du budget. */
	private const MIN_SECONDS = 1;
	private const MAX_SECONDS = 20;
	private const MIN_ENTRIES = 1000;
	private const MAX_ENTRIES = 500000;

	/** But du verrou (cf. Support\MysqlLock). */
	private const LOCK_PURPOSE = 'env';

	/** Statuts qu'une mesure écrit. */
	private const STORED_STATUSES = [ self::STATUS_OK, self::STATUS_PARTIAL, self::STATUS_FAILED ];

	/**
	 * Rappel de la tâche biquotidienne, sans paramètre : `do_action( HOOK )` sans
	 * argument passe '' en premier argument, qu'un paramètre entier refuserait.
	 */
	public static function refresh_now(): void {
		self::refresh_if_due( time() );
	}

	/**
	 * Mesure si la dernière tentative a 20 h ou plus, sous verrou (cf. en-tête).
	 * Jamais branchée directement sur un hook (paramètres injectables pour les tests).
	 *
	 * @param int            $now   Horodatage Unix du passage.
	 * @param SizeMeter|null $meter Mesureur ; par défaut, bornes lues par les filtres.
	 */
	public static function refresh_if_due( int $now, ?SizeMeter $meter = null ): void {
		if ( self::recently_attempted( self::stored(), $now ) ) {
			return;
		}

		$measure = static function () use ( $now, $meter ): void {
			// Un autre processus vient peut-être de mesurer : relire la base, pas le cache.
			self::forget_cached_option();
			$stored = self::stored();
			if ( self::recently_attempted( $stored, $now ) ) {
				return;
			}
			self::measure( $stored, $now, $meter );
		};

		MysqlLock::run(
			self::LOCK_PURPOSE,
			0,
			$measure,
			// Tenu ailleurs : l'autre processus mesure.
			static function (): void {},
			// Indisponible : mesure sans verrou, sous la même garde de 20 h.
			$measure,
			MysqlLock::SCOPE_NETWORK
		);
	}

	/**
	 * Bloc `sizes` du snapshot, lu dans le cache : jamais de mesure ici.
	 *
	 * @param int $now Horodatage Unix de la lecture.
	 * @return array{status: string, measured_at: string|null, wp_content_bytes: int|null, uploads_bytes: int|null, files_counted: int|null, db_bytes: int|null, db_status: string|null, scan_ms: int|null}
	 */
	public static function read( int $now ): array {
		$stored = self::stored();
		$sizes  = $stored['sizes'];

		$status = null === $sizes ? self::STATUS_PENDING : (string) $sizes['status'];
		// Tentative restée sans résultat au-delà de 10 min : tuée par le serveur.
		$killed = null !== $stored['attempted_at']
			&& ( null === $stored['at'] || $stored['attempted_at'] > $stored['at'] )
			&& $now - $stored['attempted_at'] > self::KILLED_AFTER_SECONDS;
		if ( $killed ) {
			$status = self::STATUS_FAILED;
		}

		return [
			'status'      => $status,
			'measured_at' => null !== $sizes && null !== $stored['at'] ? gmdate( 'c', $stored['at'] ) : null,
		] + self::values( $sizes );
	}

	/**
	 * Note la tentative, mesure, puis garde le résultat daté. Une exception (en
	 * pratique : wp-content impossible à ouvrir) donne `failed` avec les dernières
	 * valeurs réussies et leur date ; son message, qui peut porter un chemin, n'est ni
	 * gardé ni journalisé.
	 *
	 * @param array{at: int|null, attempted_at: int|null, sizes: array<mixed>|null} $stored Option relue sous verrou.
	 * @param int                                                                    $now    Début de la tentative.
	 * @param SizeMeter|null                                                         $meter  Mesureur injecté, ou null.
	 */
	private static function measure( array $stored, int $now, ?SizeMeter $meter ): void {
		$stored['attempted_at'] = $now;
		update_site_option( self::OPTION, $stored );

		try {
			$meter ??= new SizeMeter( ...self::scan_bounds() );
			$files   = $meter->measure_files( WP_CONTENT_DIR, self::uploads_dir() );
			$db      = $meter->measure_database();
			update_site_option(
				self::OPTION,
				[
					// Fin de la mesure des fichiers, à la seconde, sur l'horloge du passage.
					'at'           => $now + intdiv( $files['scan_ms'], 1000 ),
					'attempted_at' => $now,
					'sizes'        => [
						'status'           => $files['partial'] ? self::STATUS_PARTIAL : self::STATUS_OK,
						'wp_content_bytes' => $files['wp_content_bytes'],
						'uploads_bytes'    => $files['uploads_bytes'],
						'files_counted'    => $files['files_counted'],
						'db_bytes'         => $db['db_bytes'],
						'db_status'        => $db['db_status'],
						'scan_ms'          => $files['scan_ms'],
					],
				]
			);
		} catch ( \Throwable ) {
			$stored['sizes'] = [ 'status' => self::STATUS_FAILED ] + self::values( $stored['sizes'] );
			update_site_option( self::OPTION, $stored );
		}
	}

	/**
	 * Dernière tentative à moins de 20 h de $now. Une date future compte aussi (processus
	 * parti juste après celui-ci), sauf au-delà de 20 h : une horloge reculée ou une
	 * valeur abîmée ne bloque jamais la mesure indéfiniment.
	 *
	 * @param array{at: int|null, attempted_at: int|null, sizes: array<mixed>|null} $stored Option lue.
	 */
	private static function recently_attempted( array $stored, int $now ): bool {
		return null !== $stored['attempted_at'] && abs( $now - $stored['attempted_at'] ) < self::RETRY_GUARD_SECONDS;
	}

	/**
	 * Option lue et vérifiée : une valeur abîmée compte comme absente.
	 *
	 * @return array{at: int|null, attempted_at: int|null, sizes: array<mixed>|null}
	 */
	private static function stored(): array {
		$stored = get_site_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$sizes  = $stored['sizes'] ?? null;
		$valid  = is_array( $sizes ) && in_array( $sizes['status'] ?? null, self::STORED_STATUSES, true );

		return [
			'at'           => self::int_or_null( $stored['at'] ?? null ),
			'attempted_at' => self::int_or_null( $stored['attempted_at'] ?? null ),
			'sizes'        => $valid ? $sizes : null,
		];
	}

	/**
	 * Purge l'option du cache d'objets de ce processus, et la liste des options notées
	 * absentes (`notoptions`), comme SignatureState avant d'écrire sous verrou. Clés de
	 * get_option() en site simple, de get_network_option() en multisite.
	 */
	private static function forget_cached_option(): void {
		if ( is_multisite() ) {
			$network = get_current_network_id();
			wp_cache_delete( $network . ':' . self::OPTION, 'site-options' );
			wp_cache_delete( $network . ':notoptions', 'site-options' );
			return;
		}
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Dossier des médias du site principal (cf. en-tête), lu sans le créer
	 * (wp_get_upload_dir(), pas wp_upload_dir()). Le blog courant est toujours rétabli.
	 */
	private static function uploads_dir(): string {
		if ( ! is_multisite() ) {
			return self::upload_basedir();
		}
		switch_to_blog( get_main_site_id() );
		try {
			return self::upload_basedir();
		} finally {
			restore_current_blog();
		}
	}

	/** `basedir` du blog courant, ou '' (médias non mesurés). */
	private static function upload_basedir(): string {
		$dirs = wp_get_upload_dir();
		return isset( $dirs['basedir'] ) && is_string( $dirs['basedir'] ) ? $dirs['basedir'] : '';
	}

	/**
	 * Budget du parcours : temps, entrées. Filtres ramenés dans [1, 20] s et
	 * [1 000, 500 000] entrées ; une valeur non numérique garde le défaut.
	 *
	 * @return array{int, int}
	 */
	private static function scan_bounds(): array {
		return [
			self::bounded( apply_filters( 'g2rd_connector_environment_scan_seconds', self::DEFAULT_SECONDS ), self::DEFAULT_SECONDS, self::MIN_SECONDS, self::MAX_SECONDS ),
			self::bounded( apply_filters( 'g2rd_connector_environment_scan_entries', self::DEFAULT_ENTRIES ), self::DEFAULT_ENTRIES, self::MIN_ENTRIES, self::MAX_ENTRIES ),
		];
	}

	/**
	 * @param mixed $value    Valeur rendue par le filtre.
	 * @param int   $fallback Défaut si elle n'est pas numérique.
	 * @param int   $min      Borne basse.
	 * @param int   $max      Borne haute.
	 */
	private static function bounded( mixed $value, int $fallback, int $min, int $max ): int {
		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $fallback ) );
	}

	/**
	 * Valeurs mesurées d'un bloc stocké, vérifiées, dans l'ordre du bloc `sizes` du
	 * snapshot ; toutes null sans bloc.
	 *
	 * @param array<mixed>|null $sizes Bloc stocké.
	 * @return array{wp_content_bytes: int|null, uploads_bytes: int|null, files_counted: int|null, db_bytes: int|null, db_status: string|null, scan_ms: int|null}
	 */
	private static function values( ?array $sizes ): array {
		$db_status = $sizes['db_status'] ?? null;
		return [
			'wp_content_bytes' => self::int_or_null( $sizes['wp_content_bytes'] ?? null ),
			'uploads_bytes'    => self::int_or_null( $sizes['uploads_bytes'] ?? null ),
			'files_counted'    => self::int_or_null( $sizes['files_counted'] ?? null ),
			'db_bytes'         => self::int_or_null( $sizes['db_bytes'] ?? null ),
			'db_status'        => in_array( $db_status, [ SizeMeter::DB_OK, SizeMeter::DB_UNAVAILABLE ], true ) ? $db_status : null,
			'scan_ms'          => self::int_or_null( $sizes['scan_ms'] ?? null ),
		];
	}

	/**
	 * @param mixed $value Valeur stockée.
	 */
	private static function int_or_null( mixed $value ): ?int {
		return is_int( $value ) ? $value : null;
	}
}
