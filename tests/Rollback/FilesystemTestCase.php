<?php
/**
 * Base des tests qui manipulent de vrais fichiers : un dossier temporaire par
 * test, nettoyé après coup, et les fonctions WordPress de fichiers simulées.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Tests\TestCase;

abstract class FilesystemTestCase extends TestCase {

	protected string $root      = '';
	protected string $snapshots = '';
	protected string $plugins   = '';

	protected function setUp(): void {
		parent::setUp();

		$this->root      = sys_get_temp_dir() . '/g2rd-rp-' . bin2hex( random_bytes( 6 ) );
		$this->snapshots = $this->root . '/snapshots';
		$this->plugins   = $this->root . '/plugins';
		mkdir( $this->plugins, 0777, true );

		Functions\when( 'apply_filters' )->alias( fn ( string $hook, $value ) => 'g2rd_connector_snapshots_dir' === $hook ? $this->snapshots : $value );
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $dir ): bool => is_dir( $dir ) || mkdir( $dir, 0777, true ) );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	/**
	 * Crée un plugin factice : dossier + fichier principal + fichiers annexes.
	 *
	 * @param array<string, string> $extra_files chemin relatif => contenu
	 */
	protected function make_plugin( string $slug, string $version = '1.0.0', array $extra_files = [] ): string {
		$dir = $this->plugins . '/' . $slug;
		mkdir( $dir . '/inc', 0777, true );
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: $slug\n * Version: $version\n */\n" );
		file_put_contents( $dir . '/inc/helper.php', "<?php\n// helper\n" );
		foreach ( $extra_files as $relative => $content ) {
			$path = $dir . '/' . $relative;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, $content );
		}
		return $slug . '/' . $slug . '.php';
	}

	/**
	 * @return list<string> Entrées du zip, triées.
	 */
	protected function zip_entries( string $path ): array {
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path ) );
		$entries = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();
		sort( $entries );
		return $entries;
	}

	protected function dir_size( string $dir ): int {
		$total = 0;
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$total += (int) $item->getSize();
		}
		return $total;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $dir );
	}
}
