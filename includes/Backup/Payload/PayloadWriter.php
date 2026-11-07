<?php
/**
 * Écrivain de la charge utile d'un segment : magie, enregistrements, fin. Chaque enregistrement est
 * remis à $sink dès qu'il est formé (mémoire : un morceau de 1 Mio au plus et sa forme compressée).
 * Compression DEFLATE brute niveau 1, gardée seulement si elle réduit le morceau. Classe pure.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Payload;

final class PayloadWriter {

	/** @var \Closure(string): void */
	private \Closure $sink;

	private int $level;

	private int $written = 0;

	private bool $ended = false;

	/**
	 * @param \Closure(string): void $sink  Reçoit les octets, dans l'ordre.
	 * @param int                    $level Niveau DEFLATE, de 1 à 9.
	 */
	public function __construct( \Closure $sink, int $level = 1 ) {
		$this->sink  = $sink;
		$this->level = max( 1, min( 9, $level ) );
		$this->emit( PayloadFormat::MAGIC );
	}

	/** Octets remis à $sink depuis la magie comprise. */
	public function written(): int {
		return $this->written;
	}

	public function file_chunk( string $path, int $offset, int $total_size, int $mtime, int $mode, string $data, bool $compress ): int {
		if ( $offset < 0 || $offset + strlen( $data ) > $total_size ) {
			throw new \InvalidArgumentException( 'Charge utile G2P1 : morceau de fichier hors de sa taille déclarée. Cause probable : erreur de programmation (décalage négatif, ou taille lue au début du fichier plus petite que ses morceaux). Action : écrire chaque morceau dans la taille déclarée ; un fichier qui grandit pendant sa copie se relit.' );
		}
		return $this->record( PayloadFormat::T_FILE, $path, $offset, $total_size, $mtime, $mode, $data, $compress );
	}

	public function directory( string $path, int $mtime, int $mode ): int {
		return $this->record( PayloadFormat::T_DIR, $path, 0, 0, $mtime, $mode, '', false );
	}

	public function link( string $path, string $target, int $mtime, int $mode = 0777 ): int {
		return $this->record( PayloadFormat::T_LINK, $path, 0, 0, $mtime, $mode, $target, false );
	}

	public function deleted( string $path ): int {
		return $this->record( PayloadFormat::T_DELETED, $path, 0, 0, 0, 0, '', false );
	}

	public function sql( int $offset, string $data, bool $compress = true ): int {
		return $this->record( PayloadFormat::T_SQL, '', $offset, 0, 0, 0, $data, $compress );
	}

	public function manifest( int $offset, string $data, bool $compress = true ): int {
		return $this->record( PayloadFormat::T_MANIFEST, '', $offset, 0, 0, 0, $data, $compress );
	}

	public function end(): int {
		if ( $this->ended ) {
			throw new \LogicException( 'Charge utile G2P1 déjà terminée. Cause probable : erreur de programmation (end() appelé deux fois). Action : créer un nouvel écrivain pour chaque segment.' );
		}
		$this->ended = true;
		return $this->emit( PayloadFormat::header( PayloadFormat::T_END, 0, 0, 0, 0, 0, 0, 0, 0 ) );
	}

	private function record( int $type, string $path, int $offset, int $total, int $mtime, int $mode, string $raw, bool $compress ): int {
		if ( $this->ended ) {
			throw new \LogicException( 'Charge utile G2P1 : enregistrement après la fin. Cause probable : erreur de programmation (écriture après end()). Action : créer un nouvel écrivain pour chaque segment.' );
		}
		if ( strlen( $raw ) > PayloadFormat::MAX_RAW_BYTES ) {
			throw new \InvalidArgumentException( 'Charge utile G2P1 : enregistrement de plus de 1 Mio. Cause probable : erreur de programmation (morceau non découpé). Action : découper les données en morceaux de 1 Mio au plus.' );
		}
		// 'J' écrirait un entier négatif en 2^64 + valeur : le lecteur le refuserait (au-delà de 2^63).
		if ( $offset < 0 || $total < 0 ) {
			throw new \InvalidArgumentException( 'Charge utile G2P1 : décalage ou taille négatifs. Cause probable : erreur de programmation (position du dump ou du manifeste mal comptée). Action : passer une position et une taille positives ou nulles.' );
		}
		$with_path = in_array( $type, PayloadFormat::WITH_PATH, true );
		$has_path  = '' !== $path;
		if ( $has_path !== $with_path || ( $with_path && ! PayloadFormat::valid_path( $path ) ) ) {
			throw new \InvalidArgumentException( 'Charge utile G2P1 : chemin d\'enregistrement refusé par le format. Cause probable : erreur de programmation (chemin absolu, vide, avec « . », « .. », composant vide ou octet nul, ou chemin sur un enregistrement SQL ou de manifeste). Action : passer le chemin relatif à la racine copiée, séparé par « / ».' );
		}
		$compression = PayloadFormat::C_STORED;
		$stored      = $raw;
		if ( $compress && '' !== $raw ) {
			$deflated = gzdeflate( $raw, $this->level );
			if ( is_string( $deflated ) && strlen( $deflated ) < strlen( $raw ) ) {
				$compression = PayloadFormat::C_DEFLATE;
				$stored      = $deflated;
			}
		}

		return $this->emit( PayloadFormat::header( $type, $compression, strlen( $path ), strlen( $stored ), strlen( $raw ), $offset, $total, $mtime, $mode ) . $path . $stored );
	}

	private function emit( string $bytes ): int {
		( $this->sink )( $bytes );
		$this->written += strlen( $bytes );
		return strlen( $bytes );
	}
}
