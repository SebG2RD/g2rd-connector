<?php
/**
 * Lecteur en flux de l'état local d'une incrémentale, pour la comparaison par fusion avec le
 * parcours : une ligne à la fois, reprise au décalage gardé dans l'état de la sauvegarde. Une ligne
 * illisible est sautée (elle compte alors comme un fichier nouveau ou supprimé : jamais comme
 * inchangé). Il reçoit le fichier déjà ouvert et placé au décalage de reprise : c'est l'appelant
 * qui l'ouvre sans lien et vérifie qu'il est bien là, de la bonne taille (Run\BackupJob) ; un état
 * local absent ne donne jamais un lecteur vide, qui ferait oublier les suppressions.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

final class BaselineReader {

	/** @var resource|null */
	private $handle;

	/** @var array{path: string, type: string, size: int, mtime: int, sha256: string|null}|null */
	private ?array $current = null;

	private int $offset = 0;

	/**
	 * @param resource $handle État local ouvert en lecture, placé au décalage de reprise.
	 */
	public function __construct( $handle ) {
		$this->handle = $handle;
		$position     = ftell( $handle );
		$this->offset = false === $position ? 0 : $position;
		$this->advance();
	}

	/**
	 * @return array{path: string, type: string, size: int, mtime: int, sha256: string|null}|null
	 */
	public function peek(): ?array {
		return $this->current;
	}

	public function next(): void {
		$this->advance();
	}

	/** Décalage de la ligne courante (celle que rend peek()) : là où reprendre. */
	public function offset(): int {
		return $this->offset;
	}

	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du flux ouvert par le constructeur.
			$this->handle = null;
		}
	}

	private function advance(): void {
		$this->current = null;
		while ( null !== $this->handle ) {
			$start = ftell( $this->handle );
			$line  = fgets( $this->handle );
			if ( false === $line ) {
				$this->offset = false === $start ? $this->offset : $start;
				return;
			}
			$entry = IncrementalBaseline::decode_line( $line );
			if ( null !== $entry ) {
				$this->offset  = false === $start ? 0 : $start;
				$this->current = $entry;
				return;
			}
		}
	}
}
