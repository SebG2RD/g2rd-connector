<?php
/**
 * Lecteur strict de la charge utile d'un segment (règles de docs/backup-payload.md § 2). Il travaille
 * sur un flux en mémoire : vérification des vecteurs, banc, et restauration de la phase 8 (qui le
 * bornera à un segment). Ne rend jamais une donnée d'un flux refusé.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Payload;

/**
 * @phpstan-type Record array{type: string, path: string, offset: int, total_size: int, mtime: int, mode: int, data: string, compressed: bool}
 */
final class PayloadReader {

	/**
	 * @return list<Record>
	 * @throws PayloadError Flux refusé.
	 */
	public static function parse( string $stream ): array {
		if ( PayloadFormat::MAGIC !== substr( $stream, 0, 4 ) ) {
			throw new PayloadError();
		}
		$length   = strlen( $stream );
		$position = 4;
		$records  = [];
		while ( true ) {
			if ( $length - $position < PayloadFormat::HEADER_BYTES ) {
				throw new PayloadError();
			}
			$fields = unpack( 'Ctype/Ccomp/npath/Nstored/Nraw/Joffset/Jtotal/Jmtime/Nmode', $stream, $position );
			if ( false === $fields ) {
				throw new PayloadError();
			}
			$h = array_map( 'intval', $fields );
			// 'J' rend un entier signé : une valeur ≥ 2^63 devient négative (règle du lecteur). Le mode
			// ne porte que les bits de droits, jamais le st_mode entier (type de fichier compris).
			if ( $h['offset'] < 0 || $h['total'] < 0 || $h['mtime'] < 0 || $h['mode'] > PayloadFormat::MAX_MODE ) {
				throw new PayloadError();
			}
			$position += PayloadFormat::HEADER_BYTES;
			if ( PayloadFormat::T_END === $h['type'] ) {
				if ( 0 !== $h['comp'] + $h['path'] + $h['stored'] + $h['raw'] + $h['offset'] + $h['total'] + $h['mtime'] + $h['mode'] || $position !== $length ) {
					throw new PayloadError();
				}
				return $records;
			}
			if ( ! isset( PayloadFormat::NAMES[ $h['type'] ] ) || ! in_array( $h['comp'], [ PayloadFormat::C_STORED, PayloadFormat::C_DEFLATE ], true ) ) {
				throw new PayloadError();
			}
			if ( $h['raw'] > PayloadFormat::MAX_RAW_BYTES || $h['stored'] > PayloadFormat::MAX_STORED_BYTES || $h['path'] > PayloadFormat::MAX_PATH_BYTES || $length - $position < $h['path'] + $h['stored'] ) {
				throw new PayloadError();
			}
			$path      = (string) substr( $stream, $position, $h['path'] );
			$position += $h['path'];
			$stored    = (string) substr( $stream, $position, $h['stored'] );
			$position += $h['stored'];
			$with_path = in_array( $h['type'], PayloadFormat::WITH_PATH, true );
			if ( $with_path ? ! PayloadFormat::valid_path( $path ) : '' !== $path ) {
				throw new PayloadError();
			}
			$data = self::data( $h['comp'], $stored, $h['raw'] );
			// offset + raw_length ≤ total_size, sans addition : près de 2^63, la somme deviendrait un
			// flottant arrondi comme total_size. offset et total sont dans [0, 2^63 − 1] : pas de débordement.
			if ( PayloadFormat::T_FILE === $h['type'] && $h['raw'] > $h['total'] - $h['offset'] ) {
				throw new PayloadError();
			}
			$records[] = [
				'type'       => PayloadFormat::NAMES[ $h['type'] ],
				'path'       => $path,
				'offset'     => $h['offset'],
				'total_size' => $h['total'],
				'mtime'      => $h['mtime'],
				'mode'       => $h['mode'],
				'data'       => $data,
				'compressed' => PayloadFormat::C_DEFLATE === $h['comp'],
			];
		}
	}

	/** Octets stockés donnés à la fois à inflate_add() : sortie bornée à ≈ 4 Mio par pas. */
	private const INFLATE_STEP = 4096;

	/**
	 * Données d'un enregistrement. DEFLATE : décompressé par pas (mémoire bornée même pour un flux
	 * hostile), longueur exacte, fin du flux atteinte et aucun octet stocké après elle (gzinflate()
	 * ignorerait ces octets : les deux lecteurs divergeraient).
	 *
	 * @throws PayloadError Longueur fausse, DEFLATE illisible, incomplet ou suivi d'octets.
	 */
	private static function data( int $compression, string $stored, int $raw ): string {
		if ( PayloadFormat::C_STORED === $compression ) {
			if ( strlen( $stored ) !== $raw ) {
				throw new PayloadError();
			}
			return $stored;
		}
		if ( 0 === $raw ) {
			throw new PayloadError();
		}
		$context = inflate_init( ZLIB_ENCODING_RAW );
		if ( false === $context ) {
			throw new PayloadError();
		}
		$length = strlen( $stored );
		$data   = '';
		for ( $at = 0; $at < $length; $at += self::INFLATE_STEP ) {
			$last = $at + self::INFLATE_STEP >= $length;
			$out  = @inflate_add( $context, (string) substr( $stored, $at, self::INFLATE_STEP ), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DEFLATE illisible : refus, sans avertissement.
			if ( false === $out ) {
				throw new PayloadError();
			}
			$data .= $out;
			if ( strlen( $data ) > $raw ) {
				throw new PayloadError();
			}
			if ( ZLIB_STREAM_END === inflate_get_status( $context ) ) {
				break;
			}
		}
		if ( strlen( $data ) !== $raw || ZLIB_STREAM_END !== inflate_get_status( $context ) || inflate_get_read_len( $context ) !== $length ) {
			throw new PayloadError();
		}
		return $data;
	}
}
