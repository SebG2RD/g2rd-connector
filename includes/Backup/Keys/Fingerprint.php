<?php
/**
 * Empreinte de comparaison d'une clé publique (docs/backup-format.md § 7.4, décision n° 20) : les
 * 16 premiers octets de SHA-256, 32 caractères hexadécimaux, affichés en huit groupes de quatre.
 * Même calcul que la page du service de clés (`comparisonFingerprint`) : c'est ce que le propriétaire
 * compare à l'œil avant d'approuver la clé de signature du site.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Keys;

final class Fingerprint {

	public static function comparison_fingerprint( string $public_key ): string {
		return substr( hash( 'sha256', $public_key ), 0, 32 );
	}

	public static function display( string $public_key ): string {
		return implode( ' ', str_split( self::comparison_fingerprint( $public_key ), 4 ) );
	}
}
