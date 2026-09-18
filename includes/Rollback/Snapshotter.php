<?php
/**
 * Crée un point de restauration : zip du dossier d'un plugin, SHA-256, index.
 *
 * Refuse AVANT d'écrire quoi que ce soit si l'espace disque mesuré ou le budget
 * alloué aux points ne suffisent pas (`snapshot_failed_disk_space`). Un espace
 * disque INCONNU (disk_free_space désactivé par l'hébergeur) n'est pas un espace
 * insuffisant : on tente, et un échec d'écriture donne `snapshot_failed`.
 *
 * Le zip est écrit sous un nom temporaire puis renommé : l'index ne référence
 * jamais un zip à moitié écrit, et la purge des orphelins ramasse les restes
 * d'une requête interrompue.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

// phpcs:disable WordPress.WP.AlternativeFunctions -- système de fichiers direct, voir RestorePointStore.

final class Snapshotter {

	/** Marge de sécurité par défaut au-dessus de 2 × la taille du plugin (octets). */
	public const DEFAULT_DISK_MARGIN_BYTES = 52428800;

	/** Budget disque par défaut pour l'ensemble des points d'un site (octets). */
	public const DEFAULT_MAX_TOTAL_BYTES = 314572800;

	public function __construct(
		private readonly RestorePointStore $store,
		private readonly string $plugins_root,
	) {
	}

	/**
	 * Crée le point de restauration d'un plugin installé.
	 *
	 * @param string               $plugin_file Identifiant WordPress du plugin (`akismet/akismet.php`, `hello.php`).
	 * @param array<string, mixed> $meta        version (string), kind (wporg|premium|custom), expires_at (int|null),
	 *                                          hold (bool), max_total_bytes (int), disk_margin_bytes (int).
	 * @return array<string, mixed> Enregistrement ajouté à l'index.
	 * @throws RestorePointException
	 */
	public function create( string $plugin_file, array $meta, int $now ): array {
		$source = $this->resolve_source( $plugin_file );
		if ( null === $source ) {
			throw RestorePointException::failed( esc_html( 'plugin directory not found: ' . $plugin_file ) );
		}
		[ $source_path, $is_dir ] = $source;

		if ( ! $this->store->ensure_dir() ) {
			throw RestorePointException::failed( 'snapshot directory is not writable' );
		}

		$size   = $is_dir ? $this->dir_size( $source_path ) : (int) filesize( $source_path );
		$margin = (int) ( $meta['disk_margin_bytes'] ?? self::DEFAULT_DISK_MARGIN_BYTES );
		$budget = (int) ( $meta['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES );

		// 1) Espace disque MESURÉ insuffisant : refus, rien n'est écrit.
		$free = $this->free_space( $this->store->dir() );
		if ( null !== $free && $free < 2 * $size + $margin ) {
			throw RestorePointException::disk_space( esc_html( sprintf( 'insufficient disk space: %d bytes free, %d required', $free, 2 * $size + $margin ) ) );
		}

		// 2) Budget des points : on évince d'abord ce qui coûte le moins, puis on refuse.
		$missing = $this->store->make_room( $size, $budget, $now );
		if ( $missing > 0 ) {
			throw RestorePointException::disk_space( esc_html( sprintf( 'restore points budget exceeded: %d bytes over the %d bytes allowed', $missing, $budget ) ) );
		}

		$id   = RestorePointStore::new_id();
		$slug = $is_dir ? basename( $source_path ) : pathinfo( $plugin_file, PATHINFO_FILENAME );
		$name = sprintf( '%s-%s-%s.zip', $this->safe( $slug ), $this->safe( (string) ( $meta['version'] ?? '0' ) ), $id );
		$path = $this->store->dir() . '/' . $name;
		$tmp  = $path . '.part';

		try {
			$expected = $this->zip( $source_path, $is_dir, $tmp );
			if ( ! $this->zip_is_sound( $tmp, $expected ) ) {
				throw RestorePointException::failed( 'zip verification failed' );
			}
			if ( ! rename( $tmp, $path ) ) {
				throw RestorePointException::failed( 'could not finalize zip' );
			}
		} catch ( \Throwable $e ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			if ( $e instanceof RestorePointException ) {
				throw $e;
			}
			throw RestorePointException::failed( esc_html( 'zip failed: ' . $e->getMessage() ) );
		}

		$record = [
			'id'          => $id,
			'plugin_file' => $plugin_file,
			'slug'        => $slug,
			'version'     => (string) ( $meta['version'] ?? '' ),
			'kind'        => (string) ( $meta['kind'] ?? RestorePointStore::KIND_CUSTOM ),
			'file'        => $name,
			'sha256'      => (string) hash_file( 'sha256', $path ),
			'size'        => (int) filesize( $path ),
			'created_at'  => $now,
			'expires_at'  => isset( $meta['expires_at'] ) ? (int) $meta['expires_at'] : null,
			'hold'        => ! empty( $meta['hold'] ),
		];
		$this->store->add( $record );
		$this->store->enforce_per_plugin_cap( $plugin_file );

		return $record;
	}

	/**
	 * Une bibliothèque zip est-elle disponible ?
	 */
	public static function is_supported(): bool {
		return class_exists( \ZipArchive::class ) || is_readable( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
	}

	/**
	 * Résout le dossier (ou le fichier unique) d'un plugin, en s'assurant qu'il
	 * reste sous la racine des plugins : l'identifiant vient du manager, pas de
	 * l'utilisateur, mais on ne construit jamais un chemin sans le vérifier.
	 *
	 * @return array{0:string,1:bool}|null [chemin, est un dossier]
	 */
	private function resolve_source( string $plugin_file ): ?array {
		$root = realpath( $this->plugins_root );
		if ( false === $root || '' === $plugin_file || str_contains( $plugin_file, '..' ) ) {
			return null;
		}
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		$relative = '.' === dirname( $plugin_file ) ? $plugin_file : dirname( $plugin_file );
		$path     = realpath( $this->plugins_root . '/' . $relative );
		if ( false === $path ) {
			return null;
		}
		$path = str_replace( '\\', '/', $path );
		if ( ! str_starts_with( $path . '/', $root . '/' ) || $path === $root ) {
			return null;
		}

		return [ $path, is_dir( $path ) ];
	}

	/**
	 * @return int Nombre d'entrées attendues dans le zip.
	 */
	private function zip( string $source_path, bool $is_dir, string $target ): int {
		if ( class_exists( \ZipArchive::class ) ) {
			return $this->zip_with_ziparchive( $source_path, $is_dir, $target );
		}
		return $this->zip_with_pclzip( $source_path, $is_dir, $target );
	}

	private function zip_with_ziparchive( string $source_path, bool $is_dir, string $target ): int {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( esc_html( 'cannot open zip for writing' ) );
		}

		$count = 0;
		if ( ! $is_dir ) {
			$zip->addFile( $source_path, basename( $source_path ) );
			$count = 1;
		} else {
			$prefix = basename( $source_path );
			$zip->addEmptyDir( $prefix );
			++$count;
			foreach ( $this->walk( $source_path ) as $file => $relative ) {
				if ( is_dir( $file ) ) {
					$zip->addEmptyDir( $prefix . '/' . $relative );
				} else {
					$zip->addFile( $file, $prefix . '/' . $relative );
				}
				++$count;
			}
		}

		if ( ! $zip->close() ) {
			throw new \RuntimeException( esc_html( 'cannot write zip' ) );
		}
		return $count;
	}

	private function zip_with_pclzip( string $source_path, bool $is_dir, string $target ): int {
		if ( ! class_exists( \PclZip::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
		$archive = new \PclZip( $target );
		$parent  = dirname( $source_path );
		// PclZip lit ses options via func_get_args() : le stub ne déclare que le 1er paramètre.
		$result = $archive->create( $source_path, PCLZIP_OPT_REMOVE_PATH, $parent ); // @phpstan-ignore-line
		if ( 0 === $result || ! is_array( $result ) ) {
			throw new \RuntimeException( esc_html( 'PclZip: ' . (string) $archive->errorInfo( true ) ) );
		}
		return count( $result );
	}

	/**
	 * Rouvre le zip et compare son nombre d'entrées à ce qui a été ajouté.
	 */
	private function zip_is_sound( string $path, int $expected ): bool {
		if ( ! file_exists( $path ) || (int) filesize( $path ) <= 0 ) {
			return false;
		}
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
				return false;
			}
			$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété native de ZipArchive.
			$zip->close();
			return $count === $expected;
		}
		$archive = new \PclZip( $path );
		$list    = $archive->listContent();
		return is_array( $list ) && count( $list ) === $expected;
	}

	/**
	 * Parcours récursif d'un dossier : chemin absolu => chemin relatif (séparateur `/`).
	 *
	 * @return \Generator<string, string>
	 */
	private function walk( string $dir ): \Generator {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$base = strlen( rtrim( $dir, '/\\' ) ) + 1;
		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			yield $path => substr( $path, $base );
		}
	}

	private function dir_size( string $dir ): int {
		$total = 0;
		foreach ( $this->walk( $dir ) as $file => $relative ) {
			unset( $relative );
			if ( is_file( $file ) ) {
				$total += (int) filesize( $file );
			}
		}
		return $total;
	}

	/**
	 * Espace libre en octets, ou null si la mesure est impossible.
	 */
	private function free_space( string $dir ): ?int {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- désactivée chez certains hébergeurs : « inconnu », pas « insuffisant ».
		return false === $free ? null : (int) $free;
	}

	private function safe( string $value ): string {
		$clean = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $value );
		$clean = trim( (string) $clean, '-.' );
		return '' === $clean ? 'x' : $clean;
	}
}
