<?php
/**
 * Échec d'une opération sur un point de restauration, avec un code stable
 * remonté tel quel au manager (`snapshot_failed_disk_space`, `snapshot_failed`…).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class RestorePointException extends \RuntimeException {

	public const DISK_SPACE = 'snapshot_failed_disk_space';
	public const FAILED     = 'snapshot_failed';

	public function __construct( private readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}

	public function error_code(): string {
		return $this->error_code;
	}
}
