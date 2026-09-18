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

	/** Échec pendant la restauration ; les fichiers d'origine ont été remis en place. */
	public const FAILED = 'rollback_failed';

	private function __construct( private readonly string $error_code, string $message ) {
		parent::__construct( $message );
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
}
