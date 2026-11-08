<?php
/**
 * Flux de charge utile refusé par le lecteur : code stable `payload_malformed`, aucune donnée lue
 * dans le message.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Payload;

final class PayloadError extends \RuntimeException {

	public const MALFORMED = 'payload_malformed';

	public function __construct() {
		parent::__construct( self::MALFORMED );
	}

	public function reason(): string {
		return self::MALFORMED;
	}
}
