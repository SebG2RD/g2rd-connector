<?php
/**
 * Archive d'une entrée du parcours dans la charge utile d'un segment (docs/backup-payload.md § 3) :
 * fichier par morceaux de 1 Mio (lu en flux, jamais en entier), dossier, lien. Un fichier peut être
 * arrêté entre deux morceaux ($stop) et repris au décalage rendu, dans le segment suivant. SHA-256
 * calculé seulement pour un fichier lu en entier dans le même appel (choix n° 9). Un fichier
 * illisible ou disparu ne bloque jamais : `unreadable`, rien d'écrit. Un fichier changé pendant sa
 * lecture (taille ou date du descripteur différentes de celles de l'entrée, lues à son début :
 * § 5) est dit `unreadable` ; ses morceaux déjà écrits ne sont pas à restaurer. Limite : une
 * réécriture à taille égale dans la même seconde que la lecture de l'entrée ne se voit pas (date
 * à la seconde).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

use G2RD\Connector\Backup\Payload\PayloadWriter;

final class FileArchiver {

	public const CHUNK_BYTES = 1048576;

	/** Formats déjà compressés : stockés tels quels. */
	private const STORED_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mp3', 'm4a', 'ogg', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'jar', 'apk' ];

	private string $root;

	public function __construct( string $root ) {
		$this->root = rtrim( str_replace( '\\', '/', $root ), '/' );
	}

	public static function compressible( string $path ): bool {
		return ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::STORED_EXTENSIONS, true );
	}

	/**
	 * @param array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null} $entry
	 * @param int                $offset Décalage où reprendre un fichier commencé (0 sinon).
	 * @param \Closure(): bool   $stop   Vrai quand le segment est plein ou la tranche finie ; vérifié après un morceau.
	 * @return array{done: bool, offset: int, sha256: string|null, unreadable: bool}
	 */
	public function archive( array $entry, int $offset, PayloadWriter $writer, \Closure $stop ): array {
		if ( 'dir' === $entry['type'] ) {
			$writer->directory( $entry['path'], $entry['mtime'], $entry['mode'] );
			return self::outcome( true, 0, null, false );
		}
		if ( 'link' === $entry['type'] ) {
			$writer->link( $entry['path'], (string) $entry['target'], $entry['mtime'], $entry['mode'] );
			return self::outcome( true, 0, null, false );
		}

		$handle = @fopen( $this->root . '/' . $entry['path'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lecture en flux d'un fichier du site pour la sauvegarde (WP_Filesystem ne lit pas par morceaux) ; illisible = compté, sans avertissement.
		if ( false === $handle ) {
			return self::outcome( true, 0, null, true );
		}
		try {
			if ( $offset > 0 && ( 0 !== fseek( $handle, $offset ) || $offset >= $entry['size'] ) ) {
				return self::outcome( true, 0, null, true );
			}
			if ( 0 === $entry['size'] ) {
				// Vide à son début : relu avant d'écrire son morceau de 0 octet (un fichier rempli
				// depuis n'est jamais gardé vide avec l'empreinte du vide).
				if ( self::changed( $handle, $entry ) ) {
					return self::outcome( true, 0, null, true );
				}
				$writer->file_chunk( $entry['path'], 0, 0, $entry['mtime'], $entry['mode'], '', false );
				return self::outcome( true, 0, hash( 'sha256', '' ), false );
			}
			$hash     = 0 === $offset ? hash_init( 'sha256' ) : null;
			$position = $offset;
			$compress = self::compressible( $entry['path'] );
			while ( $position < $entry['size'] ) {
				if ( $position > $offset && $stop() ) {
					return self::outcome( false, $position, null, false );
				}
				$want = min( self::CHUNK_BYTES, $entry['size'] - $position );
				$data = self::read_exact( $handle, $want );
				if ( '' === $data ) {
					break;
				}
				if ( null !== $hash ) {
					hash_update( $hash, $data );
				}
				$writer->file_chunk( $entry['path'], $position, $entry['size'], $entry['mtime'], $entry['mode'], $data, $compress );
				$position += strlen( $data );
				if ( strlen( $data ) < $want ) {
					break;
				}
			}
			// Statut vrai : un fichier qui a rétréci (lecture arrêtée avant sa taille), grandi ou été
			// réécrit (taille ou date du descripteur différentes) pendant sa lecture est dit illisible ;
			// ses morceaux déjà écrits ne sont pas à restaurer (docs/backup-payload.md § 5).
			if ( $position !== $entry['size'] || self::changed( $handle, $entry ) ) {
				return self::outcome( true, 0, null, true );
			}
			return self::outcome( true, 0, null !== $hash ? hash_final( $hash ) : null, false );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du flux ouvert ci-dessus.
		}
	}

	/**
	 * Taille ou date du fichier ouvert différentes de celles de l'entrée (§ 5, « changé pendant sa
	 * lecture »). Date bornée à 0 comme le parcours (`FileWalker`). Descripteur sans état : rien à
	 * opposer à l'entrée.
	 *
	 * @param resource                       $handle
	 * @param array{size: int, mtime: int}   $entry
	 */
	private static function changed( $handle, array $entry ): bool {
		$stat = fstat( $handle );
		if ( ! is_array( $stat ) ) {
			return false;
		}
		return (int) $stat['size'] !== $entry['size'] || max( 0, (int) $stat['mtime'] ) !== $entry['mtime'];
	}

	/**
	 * @param resource $handle
	 */
	private static function read_exact( $handle, int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length && ! feof( $handle ) ) {
			$part = fread( $handle, max( 1, $length - strlen( $data ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- lecture en flux, voir archive().
			if ( false === $part || '' === $part ) {
				break;
			}
			$data .= $part;
		}
		return $data;
	}

	/**
	 * @return array{done: bool, offset: int, sha256: string|null, unreadable: bool}
	 */
	private static function outcome( bool $done, int $offset, ?string $sha256, bool $unreadable ): array {
		return [
			'done'       => $done,
			'offset'     => $offset,
			'sha256'     => $sha256,
			'unreadable' => $unreadable,
		];
	}
}
