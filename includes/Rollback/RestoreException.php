<?php
/**
 * Échec d'une restauration, avec un code stable remonté au manager.
 * Le message est échappé (esc_html) au point de lancement quand il contient une
 * variable (règle WordPress.Security.EscapeOutput, Plugin Check).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class RestoreException extends \RuntimeException {

	/** Hash ou version du zip différents de ce qu'attend le manager : rien n'a été touché. */
	public const INTEGRITY = 'rollback_failed_integrity';

	/** La version installée n'est plus celle attendue (mise à jour manuelle entre-temps) : rien n'a été touché. */
	public const VERSION_DRIFT = 'version_drift';

	/**
	 * Échec pendant la restauration ; les fichiers d'origine ont été remis en place quand
	 * c'était possible (cf. previous_folder_restored()).
	 */
	public const FAILED = 'rollback_failed';

	/**
	 * @param bool $previous_folder_restored Échec survenu une fois le dossier de l'extension mis de
	 *                                       côté : le restaurateur a remis ce dossier en place tel
	 *                                       quel (cf. PluginRestorer, previous_folder_restored()).
	 */
	private function __construct( private readonly string $error_code, string $message, ?\Throwable $previous = null, private readonly bool $previous_folder_restored = false ) {
		parent::__construct( $message, 0, $previous );
	}

	public static function integrity( string $message ): self {
		return new self( self::INTEGRITY, $message );
	}

	public static function version_drift( string $message ): self {
		return new self( self::VERSION_DRIFT, $message );
	}

	public static function failed( string $message ): self {
		return new self( self::FAILED, $message );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * L'échec est survenu après la mise de côté du dossier de l'extension, et le
	 * restaurateur a remis ce dossier en place tel quel : les fichiers sont exactement
	 * ceux d'avant la restauration. Faux pour un refus d'avant la mise de côté (rien n'a
	 * bougé), ou quand la remise en place a échoué (dossier mis de côté perdu, dossier
	 * partiellement extrait impossible à retirer).
	 */
	public function previous_folder_restored(): bool {
		return $this->previous_folder_restored;
	}

	/**
	 * Le même échec, survenu une fois le dossier mis de côté : `$restored` dit si le
	 * restaurateur l'a remis en place tel quel (cf. PluginRestorer::put_back()).
	 */
	public function after_put_back( bool $restored ): self {
		return new self( $this->error_code, $this->getMessage(), $this, $restored );
	}

	/**
	 * Le même échec (même code pour la plateforme), message complété de ce qui a été
	 * fait ensuite de l'extension (cf. ProtectedUpdate::restore()).
	 *
	 * @param string $detail Texte fixe, ajouté tel quel à la fin du message.
	 */
	public function with_detail( string $detail ): self {
		return new self( $this->error_code, $this->getMessage() . $detail, $this, $this->previous_folder_restored );
	}
}
