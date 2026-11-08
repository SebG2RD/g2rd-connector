<?php
/**
 * Témoin « mise à jour en cours » (vue d'ensemble, lot 4-A, point 4, règle (c)) : une LISTE
 * d'entrées, une par mise à jour qui passe par la porte, dans l'option du site principal
 * `g2rd_backup_updates`. Chaque mise à jour ajoute la sienne et ne retire que la sienne (écritures
 * conditionnelles sur la valeur relue) : la fin de la première ne retire jamais le témoin de la
 * seconde. Une entrée « tient le verrou » (held) n'a pas d'échéance : son processus tient le verrou
 * `backup`, que MySQL rend à sa mort ; une entrée « sans verrou » (règles b et c) porte une échéance
 * de UNLOCKED_TTL_SECONDS.
 *
 * Une tranche qui OBTIENT le verrou efface sans attendre les entrées « tient le verrou » (verrou
 * libre : leur processus est mort) et les entrées « sans verrou » échues ; s'il reste une entrée
 * « sans verrou » non échue, une mise à jour tourne peut-être encore (clear_for_slice() rend vrai).
 *
 * Illisible = présent : une porte qui ne sait pas lire le témoin laisse passer la mise à jour, une
 * tranche qui ne sait pas le lire refuse (update_in_progress, réessayable).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Gate;

use G2RD\Connector\Backup\State\MainSite;
use G2RD\Connector\CommandRuns\OptionRow;
use G2RD\Connector\CommandRuns\StoreUnavailable;

/**
 * @phpstan-type Entry array{id: string, command: string, since: int, held: bool, until: int|null}
 */
final class UpdateWitness {

	public const OPTION = 'g2rd_backup_updates';

	/** Durée maximale d'une mise à jour passée sans verrou (choix du plan, § 11.2 point 1). */
	public const UNLOCKED_TTL_SECONDS = 900;

	public const MAX_ENTRIES = 20;

	private const MAX_ATTEMPTS = 3;

	/**
	 * @return array{raw: string|null, entries: list<Entry>}
	 * @throws StoreUnavailable La base a refusé la lecture.
	 */
	public static function load(): array {
		$raw = MainSite::run( static fn (): ?string => OptionRow::read_raw( self::OPTION ) );

		return [
			'raw'     => $raw,
			'entries' => self::decode( $raw ),
		];
	}

	/**
	 * Ajoute l'entrée d'une mise à jour. Rend son identifiant, ou null si l'écriture est
	 * impossible (la mise à jour passe quand même).
	 */
	public static function add( string $command, bool $held, int $now ): ?string {
		$entry = [
			'id'      => bin2hex( random_bytes( 8 ) ),
			'command' => $command,
			'since'   => $now,
			'held'    => $held,
			'until'   => $held ? null : $now + self::UNLOCKED_TTL_SECONDS,
		];

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$witness = self::load();
			} catch ( \Throwable ) {
				return null;
			}
			$entries   = array_slice( $witness['entries'], -( self::MAX_ENTRIES - 1 ) );
			$entries[] = $entry;
			if ( self::write( $witness['raw'], $entries ) ) {
				return $entry['id'];
			}
		}

		return null;
	}

	/**
	 * Retire l'entrée $id, et elle seule. Sans effet si elle a déjà disparu.
	 */
	public static function remove( ?string $id ): void {
		if ( null === $id ) {
			return;
		}
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$witness = self::load();
			} catch ( \Throwable ) {
				return;
			}
			$kept = array_values( array_filter( $witness['entries'], static fn ( array $entry ): bool => $entry['id'] !== $id ) );
			if ( count( $kept ) === count( $witness['entries'] ) || self::write( $witness['raw'], $kept ) ) {
				return;
			}
		}
	}

	/** Vrai si au moins une entrée existe ; illisible compte comme présent. */
	public static function has_entries(): bool {
		try {
			return [] !== self::load()['entries'];
		} catch ( \Throwable ) {
			return true;
		}
	}

	/**
	 * Nettoyage par une tranche qui tient le verrou ; vrai s'il reste une mise à jour « sans
	 * verrou » non échue (ou si le témoin est illisible).
	 */
	public static function clear_for_slice( int $now ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$witness = self::load();
			} catch ( \Throwable ) {
				return true;
			}
			$alive = array_values(
				array_filter(
					$witness['entries'],
					static fn ( array $entry ): bool => ! $entry['held'] && null !== $entry['until'] && $entry['until'] > $now
				)
			);
			if ( count( $alive ) === count( $witness['entries'] ) || self::write( $witness['raw'], $alive ) ) {
				return [] !== $alive;
			}
		}

		return true;
	}

	/**
	 * @param list<Entry> $entries
	 */
	private static function write( ?string $raw, array $entries ): bool {
		if ( [] === $entries ) {
			return null === $raw || 1 === MainSite::run( static fn () => OptionRow::delete_if_unchanged( self::OPTION, $raw ) );
		}
		$json = wp_json_encode(
			[
				'v'       => 1,
				'entries' => $entries,
			]
		);
		if ( ! is_string( $json ) ) {
			return false;
		}

		return 1 === MainSite::run(
			static fn () => null === $raw ? OptionRow::insert_ignore( self::OPTION, $json ) : OptionRow::replace( self::OPTION, $raw, $json )
		);
	}

	/**
	 * @return list<Entry>
	 */
	private static function decode( ?string $raw ): array {
		$data    = null === $raw ? null : json_decode( $raw, true );
		$entries = is_array( $data ) && is_array( $data['entries'] ?? null ) ? $data['entries'] : [];
		$valid   = [];
		foreach ( $entries as $entry ) {
			if ( is_array( $entry )
				&& is_string( $entry['id'] ?? null )
				&& is_string( $entry['command'] ?? null )
				&& is_int( $entry['since'] ?? null )
				&& is_bool( $entry['held'] ?? null )
				&& ( null === ( $entry['until'] ?? null ) || is_int( $entry['until'] ) ) ) {
				$valid[] = [
					'id'      => $entry['id'],
					'command' => $entry['command'],
					'since'   => $entry['since'],
					'held'    => $entry['held'],
					'until'   => $entry['until'] ?? null,
				];
			}
		}

		return $valid;
	}
}
