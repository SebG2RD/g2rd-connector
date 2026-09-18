<?php
/**
 * Stockage des points de restauration (zips pris avant chaque mise à jour de
 * plugin) : dossier protégé, index, budget disque, suppression.
 *
 * Le dossier est `wp-content/g2rd-snapshots` (filtre `g2rd_connector_snapshots_dir`).
 * Les zips portent un nom impossible à deviner : la protection par `.htaccess`
 * ne vaut rien sous nginx, le nom aléatoire vaut partout.
 *
 * L'index vit dans une option sans autoload. Le hash de référence, lui, est
 * conservé par le manager et renvoyé dans la commande de rollback : un zip
 * modifié sur le disque du site est détecté même si l'index l'a été aussi.
 *
 * Règle absolue : ce stockage ne supprime JAMAIS un fichier qu'il n'a pas créé —
 * un chemin hors du dossier ou absent de l'index n'est pas touché (cf remove()).
 *
 * Accès direct au système de fichiers : ces opérations (zip, rename, unlink) n'ont
 * pas d'équivalent WP_Filesystem, et la capacité `restore_points` n'est annoncée
 * au manager que si get_filesystem_method() vaut `direct` (cf CommandExecutor).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

// phpcs:disable WordPress.WP.AlternativeFunctions -- système de fichiers direct, voir en-tête.

final class RestorePointStore {

	public const OPTION_KEY = 'g2rd_restore_points';

	/** Filet de sécurité absolu, quel que soit l'âge des points. */
	public const MAX_PER_PLUGIN = 3;

	public const KIND_WPORG   = 'wporg';
	public const KIND_PREMIUM = 'premium';
	public const KIND_CUSTOM  = 'custom';

	/**
	 * Chemin du dossier de stockage (sans slash final).
	 */
	public function dir(): string {
		$default = rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/g2rd-snapshots';
		return rtrim( (string) apply_filters( 'g2rd_connector_snapshots_dir', $default ), '/\\' );
	}

	/**
	 * Crée le dossier et ses protections si besoin. Idempotent.
	 */
	public function ensure_dir(): bool {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$guards = [
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# G2RD Connector : points de restauration, jamais servis par HTTP.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		];
		foreach ( $guards as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) && false === file_put_contents( $path, $content ) ) {
				return false;
			}
		}

		return is_writable( $dir );
	}

	/**
	 * Tous les points, indexés par identifiant, du plus ancien au plus récent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$points = [];
		foreach ( $stored as $id => $record ) {
			if ( is_array( $record ) && isset( $record['file'], $record['plugin_file'] ) ) {
				$points[ (string) $id ] = $record;
			}
		}
		uasort( $points, static fn ( array $a, array $b ): int => ( (int) $a['created_at'] ) <=> ( (int) $b['created_at'] ) );

		return $points;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Points d'un plugin donné, du plus ancien au plus récent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function for_plugin( string $plugin_file ): array {
		return array_filter(
			$this->all(),
			static fn ( array $record ): bool => $record['plugin_file'] === $plugin_file
		);
	}

	/**
	 * Enregistre un point dont le zip est déjà écrit dans le dossier.
	 *
	 * @param array<string, mixed> $record
	 */
	public function add( array $record ): void {
		$points                           = $this->all();
		$points[ (string) $record['id'] ] = $record;
		$this->write( $points );
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function update( string $id, array $changes ): void {
		$points = $this->all();
		if ( ! isset( $points[ $id ] ) ) {
			return;
		}
		$points[ $id ] = array_merge( $points[ $id ], $changes );
		$this->write( $points );
	}

	/**
	 * Retient un point (mise à jour échouée ou annulée) : il n'expire plus, mais
	 * le cron le supprimera au-delà de `$hold_until` — le disque du client est compté.
	 */
	public function hold( string $id, int $hold_until ): void {
		$this->update(
			$id,
			[
				'hold'       => true,
				'expires_at' => null,
				'hold_until' => $hold_until,
			]
		);
	}

	/**
	 * Supprime un point : son zip, puis son entrée d'index. Ne touche qu'à un
	 * fichier présent dans l'index ET situé dans le dossier de stockage.
	 */
	public function remove( string $id ): bool {
		$points = $this->all();
		if ( ! isset( $points[ $id ] ) ) {
			return false;
		}

		$path = $this->path_for( $points[ $id ] );
		if ( null !== $path && file_exists( $path ) && ! unlink( $path ) ) {
			return false;
		}

		unset( $points[ $id ] );
		$this->write( $points );
		return true;
	}

	/**
	 * Chemin absolu du zip d'un point, ou null s'il sortirait du dossier.
	 *
	 * @param array<string, mixed> $record
	 */
	public function path_for( array $record ): ?string {
		$name = (string) ( $record['file'] ?? '' );
		if ( '' === $name || basename( $name ) !== $name || 1 !== preg_match( '/^[A-Za-z0-9._-]+\.zip$/', $name ) ) {
			return null;
		}
		return $this->dir() . '/' . $name;
	}

	/**
	 * Octets occupés par les zips indexés (tailles de l'index, pas du disque :
	 * un zip disparu ne compte pas).
	 */
	public function total_bytes(): int {
		$total = 0;
		foreach ( $this->all() as $record ) {
			$total += (int) ( $record['size'] ?? 0 );
		}
		return $total;
	}

	/**
	 * Libère au moins `$needed` octets sous le budget `$max_total` en supprimant
	 * des points, dans l'ordre le moins coûteux pour l'utilisateur :
	 *   1. plugins wordpress.org (l'ancienne version reste téléchargeable) ;
	 *   2. plugins premium / custom en fin de délai de grâce ;
	 *   3. points retenus (`hold`), en tout dernier.
	 * Dans chaque groupe, du plus ancien au plus récent.
	 *
	 * @return int Octets encore manquants (0 si le budget est respecté).
	 */
	public function make_room( int $needed, int $max_total, int $now ): int {
		$missing = $this->missing_bytes( $needed, $max_total );
		if ( 0 === $missing ) {
			return 0;
		}

		foreach ( $this->eviction_order( $now ) as $id ) {
			$this->remove( $id );
			$missing = $this->missing_bytes( $needed, $max_total );
			if ( 0 === $missing ) {
				return 0;
			}
		}

		return $missing;
	}

	private function missing_bytes( int $needed, int $max_total ): int {
		return max( 0, $this->total_bytes() + $needed - $max_total );
	}

	/**
	 * Applique le plafond absolu par plugin : au-delà de MAX_PER_PLUGIN, les plus
	 * anciens sont supprimés, quel que soit leur état.
	 *
	 * @return list<string> Identifiants supprimés.
	 */
	public function enforce_per_plugin_cap( string $plugin_file ): array {
		$points  = array_keys( $this->for_plugin( $plugin_file ) );
		$excess  = count( $points ) - self::MAX_PER_PLUGIN;
		$removed = [];
		for ( $i = 0; $i < $excess; $i++ ) {
			if ( $this->remove( $points[ $i ] ) ) {
				$removed[] = $points[ $i ];
			}
		}
		return $removed;
	}

	/**
	 * Supprime les zips du dossier qui ne figurent pas dans l'index (points
	 * orphelins d'une transaction interrompue), et les entrées d'index dont le zip
	 * a disparu. Les fichiers de protection et tout fichier non-zip sont ignorés.
	 *
	 * @return array{files:int,records:int}
	 */
	public function prune_orphans(): array {
		$dir     = $this->dir();
		$points  = $this->all();
		$indexed = [];
		foreach ( $points as $record ) {
			$indexed[ (string) $record['file'] ] = true;
		}

		$files = 0;
		if ( is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $name ) {
				$name = (string) $name;
				if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.zip$/', $name ) || isset( $indexed[ $name ] ) ) {
					continue;
				}
				if ( unlink( $dir . '/' . $name ) ) {
					++$files;
				}
			}
		}

		$records = 0;
		foreach ( $points as $id => $record ) {
			$path = $this->path_for( $record );
			if ( null === $path || ! file_exists( $path ) ) {
				unset( $points[ $id ] );
				++$records;
			}
		}
		if ( $records > 0 ) {
			$this->write( $points );
		}

		return [
			'files'   => $files,
			'records' => $records,
		];
	}

	/**
	 * Identifiant aléatoire d'un point (16 octets hex).
	 */
	public static function new_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * @return list<string>
	 */
	private function eviction_order( int $now ): array {
		$wporg    = [];
		$expired  = [];
		$in_grace = [];
		$held     = [];
		foreach ( $this->all() as $id => $record ) {
			$expires_at = $record['expires_at'] ?? null;
			if ( self::KIND_WPORG === ( $record['kind'] ?? '' ) ) {
				$wporg[] = $id;
			} elseif ( ! empty( $record['hold'] ) ) {
				$held[] = $id;
			} elseif ( null === $expires_at || (int) $expires_at <= $now ) {
				$expired[] = $id;
			} else {
				// Premium/custom encore en délai de grâce : après les expirés, avant les retenus.
				$in_grace[] = $id;
			}
		}
		return array_merge( $wporg, $expired, $in_grace, $held );
	}

	/**
	 * @param array<string, array<string, mixed>> $points
	 */
	private function write( array $points ): void {
		update_option( self::OPTION_KEY, $points, false );
	}
}
