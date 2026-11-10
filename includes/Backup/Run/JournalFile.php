<?php
/**
 * Journal d'une sauvegarde écrit en ajout dans le dossier privé (manifeste, futur état local, liste
 * des segments) : ouvert une seule fois par WorkDir::open_private() (`ab`, contrôles au moment même
 * de l'ouverture), puis gardé ouvert pour les ajouts suivants. Un lien posé ensuite à son chemin ne
 * change plus le fichier écrit : les lignes vont dans le fichier vérifié, jamais dans une cible.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

use G2RD\Connector\Backup\Commands\BackupRefused;

final class JournalFile {

	/** @var resource|null */
	private $handle = null;

	public function __construct( private readonly string $path ) {
	}

	/**
	 * Ajoute $bytes à la fin du journal, écrits sur le disque avant le retour (la longueur du journal
	 * est relue aussitôt par WorkDir::journal_sizes()).
	 *
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE si le journal ne s'ouvre pas sans lien ou ne s'écrit pas.
	 */
	public function append( string $bytes ): void {
		if ( null === $this->handle ) {
			$this->handle = WorkDir::open_private( $this->path, 'ab' );
			if ( null === $this->handle ) {
				throw WorkDir::unavailable( $this->path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
		}
		$written = fwrite( $this->handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- journal de la sauvegarde, ouvert par WorkDir::open_private().
		if ( strlen( $bytes ) !== $written || ! fflush( $this->handle ) ) {
			throw WorkDir::unavailable( $this->path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}

	public function __destruct() {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du journal ouvert par append().
		}
	}
}
