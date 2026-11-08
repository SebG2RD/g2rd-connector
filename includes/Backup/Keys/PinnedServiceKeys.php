<?php
/**
 * Clés publiques Ed25519 du service de clés G2RD, épinglées dans le code du connecteur (décision
 * n° 15) : distribuées par GitHub avec le connecteur, jamais reçues de la plateforme. Vides jusqu'au
 * lot F : sans elles, backup_set_key refuse toute clé de site.
 *
 * Banc seulement (décision P4-5) : la constante G2RD_CONNECTOR_BACKUP_DEV_SERVICE_KEY (64 hex) ajoute
 * une clé, et seulement si wp_get_environment_type() vaut `local`.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Keys;

final class PinnedServiceKeys {

	/**
	 * Clés du service, en hexadécimal (64 caractères chacune). Une rotation garde l'ancienne listée.
	 *
	 * @var list<string>
	 */
	public const KEYS = [];

	public const DEV_CONSTANT = 'G2RD_CONNECTOR_BACKUP_DEV_SERVICE_KEY';

	/**
	 * Clés épinglées, 32 octets bruts chacune.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		$keys = array_map( static fn ( string $hex ): string => (string) hex2bin( $hex ), self::KEYS );
		if ( defined( self::DEV_CONSTANT ) && function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			$hex = constant( self::DEV_CONSTANT );
			if ( is_string( $hex ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $hex ) ) {
				$keys[] = (string) hex2bin( $hex );
			}
		}

		return $keys;
	}
}
