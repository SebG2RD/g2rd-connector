<?php
/**
 * Espace libre d'un dossier, extrait de Rollback\Snapshotter (phase 4, tâche 5) sans changement :
 * null quand la mesure est impossible (fonction désactivée par l'hébergeur, dossier absent), jamais
 * 0, qui voudrait dire « disque plein ».
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Support;

final class DiskSpace {

	/**
	 * Espace libre en octets, ou null si la mesure est impossible.
	 */
	public static function free_bytes( string $dir ): ?int {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- désactivée chez certains hébergeurs : « inconnu », pas « insuffisant ».
		return false === $free ? null : (int) $free;
	}
}
