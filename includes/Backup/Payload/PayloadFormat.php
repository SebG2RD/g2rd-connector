<?php
/**
 * Charge utile des segments G2BK v1, payload_format 1 (« G2P1 », docs/backup-payload.md, dépôt de la
 * plateforme) : constantes, en-tête d'un enregistrement, règle des chemins. Classe pure.
 *
 * PHP 64 bits seulement : les champs de 8 octets passent par pack( 'J' ) et unpack( 'J' ), absents
 * d'un PHP 32 bits, où l'autotest du module échoue déjà (Sodium, self_test_failed).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Payload;

final class PayloadFormat {

	public const MAGIC = 'G2P1';

	public const HEADER_BYTES = 40;

	public const MAX_RAW_BYTES = 1048576;

	public const MAX_STORED_BYTES = 1049600;

	public const MAX_PATH_BYTES = 4096;

	/** Bits de droits d'un mode (07777) : le lecteur refuse toute valeur au-delà. */
	public const MAX_MODE = 07777;

	public const T_FILE = 0x01;

	public const T_DIR = 0x02;

	public const T_LINK = 0x03;

	public const T_DELETED = 0x04;

	public const T_SQL = 0x10;

	public const T_MANIFEST = 0x20;

	public const T_END = 0xFF;

	public const C_STORED = 0;

	public const C_DEFLATE = 1;

	/** Noms des types, ceux des vecteurs. */
	public const NAMES = [
		self::T_FILE     => 'file',
		self::T_DIR      => 'dir',
		self::T_LINK     => 'link',
		self::T_DELETED  => 'deleted',
		self::T_SQL      => 'sql',
		self::T_MANIFEST => 'manifest',
	];

	/** Types qui portent un chemin. */
	public const WITH_PATH = [ self::T_FILE, self::T_DIR, self::T_LINK, self::T_DELETED ];

	/**
	 * En-tête d'un enregistrement : 40 octets, entiers non signés gros-boutistes. Le mode est réduit à
	 * ses bits de droits (un st_mode entier y est admis) et une date négative vaut 0 (inconnue).
	 */
	public static function header( int $type, int $compression, int $path_length, int $stored_length, int $raw_length, int $offset, int $total_size, int $mtime, int $mode ): string {
		return pack( 'CCnNNJJJN', $type, $compression, $path_length, $stored_length, $raw_length, $offset, $total_size, max( 0, $mtime ), $mode & self::MAX_MODE );
	}

	/** Chemin relatif valide : non vide, sans `/` initial ni final, ni composant vide, `.`, `..`, ni octet nul. */
	public static function valid_path( string $path ): bool {
		if ( '' === $path || strlen( $path ) > self::MAX_PATH_BYTES || str_contains( $path, "\0" ) ) {
			return false;
		}
		if ( str_starts_with( $path, '/' ) || str_ends_with( $path, '/' ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}
}
