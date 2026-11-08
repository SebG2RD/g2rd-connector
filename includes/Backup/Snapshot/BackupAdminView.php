<?php
/**
 * Ce que les écrans du connecteur montrent des sauvegardes (panneau React par BootData, page PHP) :
 * case, constante d'arrêt, empreinte de comparaison de la clé de signature en huit groupes de quatre
 * (à comparer à la page d'approbation du service de clés), version de la clé de site, dernière issue.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Snapshot;

use G2RD\Connector\Backup\Keys\Fingerprint;

final class BackupAdminView {

	/**
	 * @return array{enabled: bool, disabledByConstant: bool, fingerprint: string|null, siteKeyVersion: int|null, lastOutcome: string|null, lastAt: string|null}
	 */
	public static function data( int $now ): array {
		$block  = BackupBlock::build( $now );
		$public = is_string( $block['signing_public_key'] ) ? hex2bin( $block['signing_public_key'] ) : false;
		$key    = is_array( $block['site_key'] ) ? $block['site_key'] : null;
		$last   = is_array( $block['last'] ) ? $block['last'] : null;

		return [
			'enabled'            => (bool) $block['enabled'],
			'disabledByConstant' => (bool) $block['disabled_by_constant'],
			'fingerprint'        => false === $public ? null : Fingerprint::display( $public ),
			'siteKeyVersion'     => is_int( $key['version'] ?? null ) ? $key['version'] : null,
			'lastOutcome'        => is_string( $last['outcome'] ?? null ) ? $last['outcome'] : null,
			'lastAt'             => is_string( $last['at'] ?? null ) ? $last['at'] : null,
		];
	}
}
