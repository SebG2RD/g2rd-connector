<?php
/**
 * Tampon des erreurs PHP relevées sur le site (évolution stratégique, § 6) : option
 * `g2rd_php_errors`, NON autochargée (lue seulement par le snapshot et à l'écriture), au plus
 * 50 groupes et 32 Ko sérialisés, 7 jours.
 *
 * Une erreur fatale n'est écrite qu'une fois par minute et par empreinte au plus : un site en
 * erreur à chaque requête ne doit pas recevoir une écriture en base à chaque requête (§ 3).
 * `count` compte donc les occurrences ENREGISTRÉES : c'est une borne basse (contrat). La lecture
 * du journal écrit, elle, en une fois, chaque ligne comptée, sauf les lignes `fatal`/`error`
 * d'une erreur que le relevé a déjà enregistrée (record_many() : jamais deux fois la même).
 *
 * Écritures sous le verrou MySQL `phperrors` (portée blog), sans attendre : tenu ailleurs, rien
 * n'est écrit ; indisponible, écrit sans verrou. Avant d'écrire, l'option est relue en base, ni
 * sa valeur ni son absence gardées par la requête (forget_cached()). Ne lève jamais vers son
 * appelant au-delà de ce que lèvent get_option() et update_option() (l'appelant rattrape).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

use G2RD\Connector\Support\MysqlLock;

/**
 * @phpstan-import-type Occurrence from Normalizer
 * @phpstan-type Group array{level: string, type: string, slug: string|null, version: string|null, file: string, line: int, message: string, first_seen: int, last_seen: int, count: int, source: string, written_at: int}
 * @phpstan-type Store array{format: int, groups: array<string, Group>, truncated_at: int|null}
 * @phpstan-type Entry array{fp: string, level: string, type: string, slug: string|null, file: string, line: int, message: string, source: string, version?: string|null, first_seen?: int, last_seen?: int, count?: int}
 */
final class Buffer {

	public const OPTION                 = 'g2rd_php_errors';
	public const MAX_GROUPS             = 50;
	public const MAX_BYTES              = 32768;
	public const RETENTION_SECONDS      = 604800;
	public const WRITE_INTERVAL_SECONDS = 60;
	public const MAX_COUNT              = 1000000000;
	private const LOCK_PURPOSE          = 'phperrors';
	private const FORMAT                = 1;

	/**
	 * Enregistre une erreur fatale, au plus une fois par minute et par empreinte.
	 *
	 * La règle passe d'abord, sur le tampon relu en base : un site en erreur à chaque requête ne
	 * paie, dans la minute, que cette lecture ; ni la version (get_plugins() parcourt le dossier
	 * de l'extension), ni GET_LOCK et RELEASE_LOCK. Quand le groupe est à écrire, la version est
	 * calculée hors du verrou, puis la règle est revue sous le verrou, sur le tampon relu : un
	 * autre processus a pu écrire la même erreur entre-temps. Ce qui est écrit ne change pas.
	 *
	 * @param Entry                            $entry   Occurrence (et sa version, si elle est connue).
	 * @param (\Closure(): (string|null))|null $version Version du composant, appelée seulement si le
	 *                                                  groupe est à écrire ; elle remplace celle de $entry.
	 * @return bool Vrai si le tampon a été écrit.
	 */
	public static function record( array $entry, int $now, ?\Closure $version = null ): bool {
		if ( self::written_recently( self::load( true ), $entry['fp'], $now ) ) {
			return false;
		}
		if ( null !== $version ) {
			$entry['version'] = $version();
		}
		$write = static function () use ( $entry, $now ): bool {
			$store = self::load( true );
			if ( self::written_recently( $store, $entry['fp'], $now ) ) {
				return false;
			}

			return self::save( self::bound( self::merge( $store, $entry, $now ), $now ) );
		};

		return MysqlLock::run( self::LOCK_PURPOSE, 0, $write, static fn (): bool => false, $write );
	}

	/**
	 * Groupe de cette empreinte écrit il y a moins d'une minute.
	 *
	 * @param Store $store
	 */
	private static function written_recently( array $store, string $fp, int $now ): bool {
		$existing = $store['groups'][ $fp ] ?? null;

		return null !== $existing && $now - $existing['written_at'] < self::WRITE_INTERVAL_SECONDS;
	}

	/**
	 * Enregistre en une écriture des occurrences regroupées (lecture du journal).
	 *
	 * Une ligne `fatal` ou `error` du journal dont l'empreinte a déjà un groupe de source
	 * `fatal_catcher` n'est pas recomptée : le relevé à l'arrêt a vu cette erreur, et le journal
	 * en garde aussi chaque occurrence ; l'additionner compterait deux fois la même erreur
	 * (statut faux). Le groupe garde le compte du relevé, borne basse vraie (contrat). Le
	 * contrôle se fait ici, sous le verrou et sur le tampon relu, pour qu'un relevé écrit pendant
	 * la lecture du journal soit vu. Un groupe touché par le relevé garde la source
	 * `fatal_catcher` : ses lignes de journal ne le fusionnent plus.
	 *
	 * Tampon inchangé (par exemple, chaque ligne est celle d'une erreur déjà relevée) : rien n'est
	 * écrit, et c'est un succès. update_option() rendrait faux pour une valeur identique, et la
	 * lecture du journal, qui n'avance son curseur que sur un succès, relirait les mêmes lignes à
	 * chaque passage.
	 *
	 * @param list<Entry> $entries Occurrences, chacune avec first_seen, last_seen et count.
	 * @param bool        $partial Vrai si la lecture n'a pas tout lu (journal coupé ou trop long).
	 * @return bool Vrai si rien n'était à écrire ou si l'écriture a eu lieu ; faux si le verrou
	 *              est tenu ailleurs ou si l'écriture a échoué.
	 */
	public static function record_many( array $entries, int $now, bool $partial ): bool {
		if ( [] === $entries && ! $partial ) {
			return true;
		}
		$write = static function () use ( $entries, $now, $partial ): bool {
			$loaded = self::load( true );
			$store  = $loaded;
			foreach ( $entries as $entry ) {
				if ( self::already_caught( $store, $entry ) ) {
					continue;
				}
				$store = self::merge( $store, $entry, $now );
			}
			if ( $partial ) {
				$store['truncated_at'] = $now;
			}
			$store = self::bound( $store, $now );

			return $store === $loaded || self::save( $store );
		};

		return MysqlLock::run( self::LOCK_PURPOSE, 1, $write, static fn (): bool => false, $write );
	}

	/**
	 * Groupes des 7 derniers jours, du plus récent au plus ancien, et tampon coupé ou non.
	 *
	 * @return array{groups: array<string, Group>, truncated: bool}
	 */
	public static function read( int $now ): array {
		$store = self::bound( self::load( false ), $now );

		return [
			'groups'    => $store['groups'],
			'truncated' => null !== $store['truncated_at'],
		];
	}

	/**
	 * Rappel de la tâche biquotidienne, sans paramètre : `do_action( HOOK )` sans argument passe ''.
	 */
	public static function purge_now(): void {
		self::purge( time() );
	}

	/** Retire les groupes de plus de 7 jours ; l'option si plus rien n'y reste. */
	public static function purge( int $now ): void {
		$purge = static function () use ( $now ): void {
			self::forget_cached();
			if ( null === get_option( self::OPTION, null ) ) {
				return;
			}
			$store = self::bound( self::load( false ), $now );
			if ( [] === $store['groups'] && null === $store['truncated_at'] ) {
				delete_option( self::OPTION );
				return;
			}
			self::save( $store );
		};

		MysqlLock::run( self::LOCK_PURPOSE, 0, $purge, static function (): void {}, $purge );
	}

	/**
	 * @return Store
	 */
	private static function load( bool $fresh ): array {
		if ( $fresh ) {
			// Un autre processus a pu écrire : relire la base, ni la valeur gardée par la requête
			// ni une absence notée plus tôt (cf. forget_cached()).
			self::forget_cached();
		}
		$stored = get_option( self::OPTION, null );
		$store  = [
			'format'       => self::FORMAT,
			'groups'       => [],
			'truncated_at' => null,
		];
		if ( ! is_array( $stored ) || self::FORMAT !== ( $stored['format'] ?? null ) ) {
			return $store;
		}
		$truncated_at          = $stored['truncated_at'] ?? null;
		$store['truncated_at'] = is_int( $truncated_at ) ? $truncated_at : null;
		$groups                = $stored['groups'] ?? null;
		if ( ! is_array( $groups ) ) {
			return $store;
		}
		foreach ( $groups as $fp => $group ) {
			$valid = self::valid_group( (string) $fp, $group );
			if ( null !== $valid ) {
				$store['groups'][ (string) $fp ] = $valid;
			}
		}

		return $store;
	}

	/**
	 * Oublie ce que la requête sait de l'option, avant de la relire sous le verrou.
	 *
	 * Non autochargée : get_option() la garde dans le groupe `options` du cache, sous son nom.
	 * Lue absente plus tôt dans la requête (purge sans tampon, snapshot d'un site sain), elle est
	 * notée dans `notoptions`, et get_option() la rendrait absente sans relire la base, même
	 * écrite depuis par un autre processus. update_option() passerait alors par add_option(),
	 * qui ne vérifie pas la base pour une option notée absente et écrase la ligne (INSERT … ON
	 * DUPLICATE KEY UPDATE) : le relevé écrit entre-temps serait perdu. On l'en retire, comme
	 * UpdateTransaction::fresh() ; rien n'est écrit si elle n'y est pas, et le reste de
	 * `notoptions` est gardé (autres options lues absentes).
	 */
	private static function forget_cached(): void {
		wp_cache_delete( self::OPTION, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
			unset( $notoptions[ self::OPTION ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * @param Store $store
	 */
	private static function save( array $store ): bool {
		return update_option( self::OPTION, $store, false );
	}

	/**
	 * Ligne du journal (`fatal` ou `error`) d'une erreur déjà enregistrée par le relevé à l'arrêt.
	 *
	 * @param Store $store
	 * @param Entry $entry
	 */
	private static function already_caught( array $store, array $entry ): bool {
		if ( Normalizer::SOURCE_LOG !== $entry['source'] || ! in_array( $entry['level'], [ Normalizer::LEVEL_FATAL, Normalizer::LEVEL_ERROR ], true ) ) {
			return false;
		}
		$group = $store['groups'][ $entry['fp'] ] ?? null;

		return null !== $group && Normalizer::SOURCE_FATAL === $group['source'];
	}

	/**
	 * @param Store $store
	 * @param Entry $entry
	 * @return Store
	 */
	private static function merge( array $store, array $entry, int $now ): array {
		$fp    = $entry['fp'];
		$last  = $entry['last_seen'] ?? $now;
		$first = min( $entry['first_seen'] ?? $now, $last );
		$count = max( 1, $entry['count'] ?? 1 );
		$group = $store['groups'][ $fp ] ?? null;

		if ( null === $group ) {
			$store['groups'][ $fp ] = [
				'level'      => $entry['level'],
				'type'       => $entry['type'],
				'slug'       => $entry['slug'],
				'version'    => $entry['version'] ?? null,
				'file'       => $entry['file'],
				'line'       => $entry['line'],
				'message'    => $entry['message'],
				'first_seen' => $first,
				'last_seen'  => $last,
				'count'      => min( self::MAX_COUNT, $count ),
				'source'     => $entry['source'],
				'written_at' => $now,
			];

			return $store;
		}

		if ( $last >= $group['last_seen'] ) {
			// L'occurrence la plus récente donne la ligne, le message, la version et la source.
			$group['line']      = $entry['line'];
			$group['message']   = $entry['message'];
			$group['version']   = $entry['version'] ?? $group['version'];
			$group['source']    = $entry['source'];
			$group['last_seen'] = $last;
		}
		$group['first_seen']    = min( $group['first_seen'], $first );
		$group['count']         = min( self::MAX_COUNT, $group['count'] + $count );
		$group['written_at']    = $now;
		$store['groups'][ $fp ] = $group;

		return $store;
	}

	/**
	 * Bornes : 7 jours, du plus récent au plus ancien, 50 groupes, 32 Ko sérialisés. Un groupe
	 * écarté marque le tampon comme coupé.
	 *
	 * @param Store $store
	 * @return Store
	 */
	private static function bound( array $store, int $now ): array {
		$limit           = $now - self::RETENTION_SECONDS;
		$store['groups'] = array_filter( $store['groups'], static fn ( array $group ): bool => $group['last_seen'] >= $limit );
		if ( null !== $store['truncated_at'] && $store['truncated_at'] < $limit ) {
			$store['truncated_at'] = null;
		}
		uasort( $store['groups'], static fn ( array $a, array $b ): int => $b['last_seen'] <=> $a['last_seen'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- mesure seulement : la taille que WordPress donne à l'option (maybe_serialize() d'un tableau) ; rien n'est désérialisé.
		while ( [] !== $store['groups'] && ( count( $store['groups'] ) > self::MAX_GROUPS || strlen( serialize( $store ) ) > self::MAX_BYTES ) ) {
			array_pop( $store['groups'] );
			$store['truncated_at'] = $now;
		}

		return $store;
	}

	/**
	 * Groupe relu de la base, chaque champ contrôlé ; null s'il est mal formé.
	 *
	 * @return Group|null
	 */
	private static function valid_group( string $fp, mixed $group ): ?array {
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $fp ) || ! is_array( $group ) ) {
			return null;
		}
		$level   = self::text( $group, 'level' );
		$type    = self::text( $group, 'type' );
		$file    = self::text( $group, 'file' );
		$message = self::text( $group, 'message' );
		$source  = self::text( $group, 'source' );
		$line    = self::number( $group, 'line' );
		$first   = self::number( $group, 'first_seen' );
		$last    = self::number( $group, 'last_seen' );
		$count   = self::number( $group, 'count' );
		$written = self::number( $group, 'written_at' );
		$slug    = $group['slug'] ?? null;
		$version = $group['version'] ?? null;
		if ( null === $level || null === $type || null === $file || null === $message || null === $source
			|| null === $line || null === $first || null === $last || null === $count || null === $written ) {
			return null;
		}
		if ( ! in_array( $level, Normalizer::LEVELS, true ) || ! in_array( $type, Normalizer::TYPES, true ) || ! in_array( $source, Normalizer::SOURCES, true ) ) {
			return null;
		}
		if ( ( null !== $slug && ! is_string( $slug ) ) || ( null !== $version && ! is_string( $version ) ) || $count < 1 || $first > $last ) {
			return null;
		}

		return [
			'level'      => $level,
			'type'       => $type,
			'slug'       => $slug,
			'version'    => $version,
			'file'       => $file,
			'line'       => $line,
			'message'    => $message,
			'first_seen' => $first,
			'last_seen'  => $last,
			'count'      => $count,
			'source'     => $source,
			'written_at' => $written,
		];
	}

	/**
	 * @param array<mixed> $group
	 */
	private static function text( array $group, string $key ): ?string {
		$value = $group[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * @param array<mixed> $group
	 */
	private static function number( array $group, string $key ): ?int {
		$value = $group[ $key ] ?? null;

		return is_int( $value ) ? $value : null;
	}
}
