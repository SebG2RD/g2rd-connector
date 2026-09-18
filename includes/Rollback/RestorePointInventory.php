<?php
/**
 * Vue des points de restauration exposée dans l'inventaire (GET /snapshot) : la
 * plateforme y lit ce que le site détient réellement et réconcilie ses items
 * (point purgé par le budget, expiré, retenu…) sans canal supplémentaire.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class RestorePointInventory {

	/**
	 * @return array{items: list<array<string, mixed>>, count: int, total_bytes: int, dir_writable: bool}
	 */
	public static function describe(): array {
		$store = new RestorePointStore();
		$items = [];
		foreach ( $store->all() as $record ) {
			$items[] = [
				'id'          => (string) $record['id'],
				'plugin_file' => (string) $record['plugin_file'],
				'version'     => (string) ( $record['version'] ?? '' ),
				'kind'        => (string) ( $record['kind'] ?? '' ),
				'sha256'      => (string) ( $record['sha256'] ?? '' ),
				'size'        => (int) ( $record['size'] ?? 0 ),
				'created_at'  => (int) ( $record['created_at'] ?? 0 ),
				'expires_at'  => isset( $record['expires_at'] ) ? (int) $record['expires_at'] : null,
				'hold'        => ! empty( $record['hold'] ),
			];
		}

		return [
			'items'        => $items,
			'count'        => count( $items ),
			'total_bytes'  => $store->total_bytes(),
			'dir_writable' => is_dir( $store->dir() ) ? is_writable( $store->dir() ) : true,
		];
	}
}
