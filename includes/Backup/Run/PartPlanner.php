<?php
/**
 * Découpage d'un segment en parties d'un envoi S3 en plusieurs parties. Taille tirée de la mémoire
 * libre (relevé de la phase 3 : pic PHP = 1,00 fois la partie, processus ≈ 2 fois) : un quart de la
 * marge (`memory_limit` moins la mémoire occupée), arrondi au Mio inférieur, de 5 Mio (minimum S3
 * sauf la dernière) à 64 Mio (plafond de l'envoi d'une partie, phase 3) ; 32 Mio si `memory_limit` est illimité.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

final class PartPlanner {

	public const MIN_PART_BYTES = 5242880;

	public const MAX_PART_BYTES = 67108864;

	private const UNLIMITED_PART_BYTES = 33554432;

	private const MIB = 1048576;

	public static function part_size( ?int $memory_limit, int $usage ): int {
		if ( null === $memory_limit || $memory_limit <= 0 ) {
			return self::UNLIMITED_PART_BYTES;
		}
		$quarter = intdiv( max( 0, $memory_limit - $usage ), 4 );
		$rounded = intdiv( $quarter, self::MIB ) * self::MIB;

		return max( self::MIN_PART_BYTES, min( self::MAX_PART_BYTES, $rounded ) );
	}

	/**
	 * @return list<array{part_number: int, offset: int, length: int}>
	 */
	public static function plan( int $bytes, int $part_size ): array {
		$parts  = [];
		$offset = 0;
		$number = 1;
		while ( $offset < $bytes ) {
			$length  = min( $part_size, $bytes - $offset );
			$parts[] = [
				'part_number' => $number,
				'offset'      => $offset,
				'length'      => $length,
			];
			$offset += $length;
			++$number;
		}

		return $parts;
	}
}
