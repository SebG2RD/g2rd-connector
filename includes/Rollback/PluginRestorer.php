<?php
/**
 * Restaure les FICHIERS d'un plugin depuis un zip (point de restauration local
 * ou archive téléchargée depuis wordpress.org).
 *
 * Ordre des opérations, pensé pour ne jamais laisser le site sans le plugin :
 *   1. vérifier le SHA-256 (quand le manager en fournit un) et la version lue
 *      DANS le zip — avant de toucher à quoi que ce soit ;
 *   2. vérifier que la version installée est bien celle que le manager croit
 *      (sinon `version_drift`, rien n'est touché) ;
 *   3. renommer le dossier actuel pour le mettre de côté (pas le supprimer) ;
 *   4. extraire le zip ;
 *   5. vérifier la version extraite ;
 *   6. supprimer le dossier mis de côté.
 * Si une étape échoue après le 3, l'extraction partielle est retirée et le dossier
 * mis de côté reprend sa place.
 *
 * La désactivation/réactivation du plugin, l'invalidation de l'OPcache, le
 * `.maintenance` et le contrôle de santé sont orchestrés par l'appelant
 * (CommandExecutor) : cette classe ne connaît que les fichiers.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

// phpcs:disable WordPress.WP.AlternativeFunctions -- système de fichiers direct, voir RestorePointStore.

final class PluginRestorer {

	/** @var callable(string, string): void */
	private $extractor;

	/**
	 * @param string        $plugins_root Racine des plugins (WP_PLUGIN_DIR).
	 * @param callable|null $extractor    fn( string $zip, string $destination ): void — injectable pour les tests.
	 */
	public function __construct( private readonly string $plugins_root, ?callable $extractor = null ) {
		$this->extractor = $extractor ?? [ $this, 'extract' ];
	}

	/**
	 * Restaure `$plugin_file` depuis `$zip_path`.
	 *
	 * @param string      $expected_sha256          Hash attendu par le manager (chaîne vide = non vérifié, cas du zip wordpress.org).
	 * @param string      $expected_version         Version que le zip doit contenir.
	 * @param string|null $expected_current_version Version censée être installée (null = non vérifié).
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	public function restore_from_zip( string $zip_path, string $plugin_file, string $expected_sha256, string $expected_version, ?string $expected_current_version ): array {
		// ── 1. Intégrité du zip, avant toute écriture ────────────────────────────
		if ( ! is_file( $zip_path ) ) {
			throw RestoreException::integrity( 'restore point archive is missing' );
		}
		if ( '' !== $expected_sha256 && ! hash_equals( strtolower( $expected_sha256 ), (string) hash_file( 'sha256', $zip_path ) ) ) {
			throw RestoreException::integrity( 'archive hash does not match the expected SHA-256' );
		}

		$is_dir       = '.' !== dirname( $plugin_file );
		$main_in_zip  = $is_dir ? $plugin_file : basename( $plugin_file );
		$zip_version  = $this->version_in_zip( $zip_path, $main_in_zip );
		$version_norm = static fn ( string $v ): string => strtolower( trim( $v ) );
		if ( null === $zip_version ) {
			throw RestoreException::integrity( esc_html( 'plugin header not found in archive: ' . $main_in_zip ) );
		}
		if ( $version_norm( $zip_version ) !== $version_norm( $expected_version ) ) {
			throw RestoreException::integrity( esc_html( sprintf( 'archive contains version %s, expected %s', $zip_version, $expected_version ) ) );
		}
		$this->assert_entries_are_confined( $zip_path, $is_dir ? dirname( $plugin_file ) : basename( $plugin_file ) );

		// ── 2. État installé ─────────────────────────────────────────────────────
		$target  = $this->target_path( $plugin_file );
		$current = $this->installed_version( $target, $plugin_file );
		if ( null !== $expected_current_version && null !== $current && $version_norm( $current ) !== $version_norm( $expected_current_version ) ) {
			throw RestoreException::version_drift( esc_html( sprintf( 'installed version is %s, expected %s', $current, $expected_current_version ) ) );
		}

		// ── 3. Mettre de côté, extraire, vérifier, nettoyer ─────────────────────
		$aside = $target . '.g2rd-old-' . bin2hex( random_bytes( 4 ) );
		if ( file_exists( $target ) && ! rename( $target, $aside ) ) {
			throw RestoreException::failed( 'could not set the current plugin aside' );
		}

		$after = null;
		try {
			( $this->extractor )( $zip_path, $this->plugins_root );

			$after = $this->installed_version( $target, $plugin_file );
			if ( null === $after || $version_norm( $after ) !== $version_norm( $expected_version ) ) {
				throw RestoreException::failed( esc_html( sprintf( 'extracted version is %s, expected %s', $after ?? 'unknown', $expected_version ) ) );
			}
		} catch ( \Throwable $e ) {
			// Retour arrière : on retire ce qui a été extrait, le dossier mis de côté reprend sa place.
			$this->delete_path( $target );
			if ( file_exists( $aside ) ) {
				rename( $aside, $target );
			}
			if ( $e instanceof RestoreException ) {
				throw $e;
			}
			throw RestoreException::failed( esc_html( 'extraction failed: ' . $e->getMessage() ) );
		}

		if ( file_exists( $aside ) ) {
			$this->delete_path( $aside );
		}
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( $is_dir ? $target : $this->plugins_root );
		}

		return [
			'version_before' => (string) $current,
			'version_after'  => (string) $after,
		];
	}

	/**
	 * Lit la version déclarée dans l'en-tête d'un fichier de plugin (même règle que
	 * get_file_data() : « Version: x » en début de ligne, commentaires compris).
	 */
	public static function version_from_header( string $content ): ?string {
		$head = substr( $content, 0, 8192 );
		$head = str_replace( "\r", "\n", $head );
		if ( 1 === preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', $head, $m ) ) {
			$version = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			return '' === $version ? null : $version;
		}
		return null;
	}

	private function target_path( string $plugin_file ): string {
		$relative = '.' !== dirname( $plugin_file ) ? dirname( $plugin_file ) : $plugin_file;
		return rtrim( $this->plugins_root, '/\\' ) . '/' . $relative;
	}

	private function installed_version( string $target, string $plugin_file ): ?string {
		$main = '.' !== dirname( $plugin_file ) ? $target . '/' . basename( $plugin_file ) : $target;
		if ( ! is_file( $main ) ) {
			return null;
		}
		return self::version_from_header( (string) file_get_contents( $main ) );
	}

	private function version_in_zip( string $zip_path, string $main_in_zip ): ?string {
		$content = $this->read_zip_entry( $zip_path, $main_in_zip );
		return null === $content ? null : self::version_from_header( $content );
	}

	/**
	 * Refuse un zip dont une entrée sortirait du dossier du plugin (`..`, chemin
	 * absolu, autre racine). Notre propre zip est déjà vérifié par hash ; celui de
	 * wordpress.org ne l'est pas, et un zip ne doit jamais pouvoir écrire ailleurs.
	 */
	private function assert_entries_are_confined( string $zip_path, string $root ): void {
		foreach ( $this->list_zip_entries( $zip_path ) as $entry ) {
			$normalized = str_replace( '\\', '/', $entry );
			$confined   = $normalized === $root || str_starts_with( $normalized, $root . '/' );
			if ( ! $confined || str_contains( $normalized, '..' ) || str_starts_with( $normalized, '/' ) ) {
				throw RestoreException::integrity( esc_html( 'archive entry outside the plugin directory: ' . $entry ) );
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function list_zip_entries( string $zip_path ): array {
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				throw RestoreException::integrity( 'archive cannot be opened' );
			}
			$entries = [];
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété native de ZipArchive.
				$entries[] = (string) $zip->getNameIndex( $i );
			}
			$zip->close();
			return $entries;
		}

		$this->require_pclzip();
		$list = ( new \PclZip( $zip_path ) )->listContent();
		if ( ! is_array( $list ) ) {
			throw RestoreException::integrity( 'archive cannot be opened' );
		}
		return array_map( static fn ( array $item ): string => (string) $item['filename'], $list );
	}

	private function read_zip_entry( string $zip_path, string $entry ): ?string {
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) ) {
				return null;
			}
			$content = $zip->getFromName( $entry );
			$zip->close();
			return false === $content ? null : $content;
		}

		$this->require_pclzip();
		$extracted = ( new \PclZip( $zip_path ) )->extract( PCLZIP_OPT_BY_NAME, $entry, PCLZIP_OPT_EXTRACT_AS_STRING ); // @phpstan-ignore-line
		if ( ! is_array( $extracted ) || ! isset( $extracted[0]['content'] ) ) {
			return null;
		}
		return (string) $extracted[0]['content'];
	}

	/**
	 * Extraction par défaut (ZipArchive, repli PclZip).
	 */
	public function extract( string $zip_path, string $destination ): void {
		if ( class_exists( \ZipArchive::class ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path ) || ! $zip->extractTo( $destination ) ) {
				throw new \RuntimeException( 'ZipArchive extraction failed' );
			}
			$zip->close();
			return;
		}

		$this->require_pclzip();
		$result = ( new \PclZip( $zip_path ) )->extract( PCLZIP_OPT_PATH, $destination ); // @phpstan-ignore-line
		if ( 0 === $result || ! is_array( $result ) ) {
			throw new \RuntimeException( 'PclZip extraction failed' );
		}
	}

	private function require_pclzip(): void {
		if ( ! class_exists( \PclZip::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
	}

	/**
	 * Supprime un fichier ou un dossier — uniquement sous la racine des plugins
	 * (dossier du plugin restauré, ou dossier mis de côté par cette classe).
	 */
	private function delete_path( string $path ): void {
		$root = rtrim( str_replace( '\\', '/', (string) realpath( $this->plugins_root ) ), '/' );
		$real = realpath( $path );
		if ( false === $real ) {
			return;
		}
		$real = str_replace( '\\', '/', $real );
		if ( '' === $root || ! str_starts_with( $real, $root . '/' ) ) {
			return;
		}

		if ( is_file( $real ) ) {
			unlink( $real );
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $real );
	}
}
