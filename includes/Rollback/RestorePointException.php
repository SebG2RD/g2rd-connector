<?php
/**
 * Échec d'une opération sur un point de restauration, avec un code stable
 * remonté tel quel au manager (`snapshot_failed_disk_space`, `snapshot_failed`…).
 *
 * Le message est échappé (esc_html) au point de lancement quand il contient une
 * variable : règle WordPress.Security.EscapeOutput appliquée par Plugin Check.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class RestorePointException extends \RuntimeException {

	public const DISK_SPACE = 'snapshot_failed_disk_space';
	public const FAILED     = 'snapshot_failed';

	private function __construct( private readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}

	/** Espace disque mesuré ou budget insuffisant : la mise à jour est refusée. */
	public static function disk_space( string $message ): self {
		return new self( self::DISK_SPACE, $message );
	}

	/** Toute autre cause (dossier introuvable, écriture impossible, zip invalide). */
	public static function failed( string $message ): self {
		return new self( self::FAILED, $message );
	}

	public function error_code(): string {
		return $this->error_code;
	}
}
