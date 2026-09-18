<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use G2RD\Connector\Rollback\PluginRestorer;
use G2RD\Connector\Rollback\RestoreException;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Snapshotter;

final class PluginRestorerTest extends FilesystemTestCase {

	private const NOW = 1789000000;

	private RestorePointStore $store;
	private Snapshotter $snapshotter;

	protected function setUp(): void {
		parent::setUp();
		$this->store       = new RestorePointStore();
		$this->snapshotter = new Snapshotter( $this->store, $this->plugins );
	}

	/**
	 * Scénario nominal : point pris en 1.0, plugin passé en 2.0 (fichier ajouté,
	 * fichier modifié, fichier supprimé), rollback → arborescence 1.0 à l'identique.
	 */
	public function test_nominal_rollback_restores_the_exact_previous_tree(): void {
		$file   = $this->make_plugin( 'akismet', '1.0', [ 'assets/old.css' => 'old' ] );
		$before = $this->tree( $this->plugins . '/akismet' );
		$record = $this->snapshotter->create( $file, [ 'version' => '1.0', 'kind' => 'wporg' ], self::NOW );

		$this->upgrade_plugin( 'akismet', '2.0' );
		self::assertNotSame( $before, $this->tree( $this->plugins . '/akismet' ) );

		$result = ( new PluginRestorer( $this->plugins ) )->restore_from_zip(
			(string) $this->store->path_for( $record ),
			$file,
			$record['sha256'],
			'1.0',
			'2.0'
		);

		self::assertSame( [ 'version_before' => '2.0', 'version_after' => '1.0' ], $result );
		self::assertSame( $before, $this->tree( $this->plugins . '/akismet' ) );
		self::assertSame( [], glob( $this->plugins . '/akismet.g2rd-old-*' ) ?: [], 'le dossier mis de côté est supprimé' );
	}

	public function test_single_file_plugin_rollback(): void {
		file_put_contents( $this->plugins . '/hello.php', "<?php\n/**\n * Plugin Name: Hello\n * Version: 1.7.2\n */\n" );
		$record = $this->snapshotter->create( 'hello.php', [ 'version' => '1.7.2', 'kind' => 'wporg' ], self::NOW );
		file_put_contents( $this->plugins . '/hello.php', "<?php\n/**\n * Plugin Name: Hello\n * Version: 1.8\n */\nnew_code();\n" );

		( new PluginRestorer( $this->plugins ) )->restore_from_zip( (string) $this->store->path_for( $record ), 'hello.php', $record['sha256'], '1.7.2', '1.8' );

		self::assertStringContainsString( 'Version: 1.7.2', (string) file_get_contents( $this->plugins . '/hello.php' ) );
		self::assertStringNotContainsString( 'new_code', (string) file_get_contents( $this->plugins . '/hello.php' ) );
	}

	/**
	 * Hash invalide : statut d'intégrité, et RIEN n'a été touché.
	 */
	public function test_wrong_hash_touches_nothing(): void {
		$file   = $this->make_plugin( 'akismet', '1.0' );
		$record = $this->snapshotter->create( $file, [ 'version' => '1.0' ], self::NOW );
		$this->upgrade_plugin( 'akismet', '2.0' );
		$tree = $this->tree( $this->plugins . '/akismet' );

		try {
			( new PluginRestorer( $this->plugins ) )->restore_from_zip( (string) $this->store->path_for( $record ), $file, str_repeat( 'ab', 32 ), '1.0', '2.0' );
			self::fail( 'exception attendue' );
		} catch ( RestoreException $e ) {
			self::assertSame( RestoreException::INTEGRITY, $e->error_code() );
		}

		self::assertSame( $tree, $this->tree( $this->plugins . '/akismet' ), 'arborescence intacte' );
		self::assertSame( [], glob( $this->plugins . '/akismet.g2rd-old-*' ) ?: [] );
	}

	/**
	 * Zip au hash correct mais dont la version ne correspond pas à ce que le manager
	 * attend (index falsifié, mauvais point) : intégrité, rien n'est touché.
	 */
	public function test_zip_with_unexpected_version_touches_nothing(): void {
		$file   = $this->make_plugin( 'akismet', '1.0' );
		$record = $this->snapshotter->create( $file, [ 'version' => '1.0' ], self::NOW );
		$this->upgrade_plugin( 'akismet', '2.0' );
		$tree = $this->tree( $this->plugins . '/akismet' );

		try {
			( new PluginRestorer( $this->plugins ) )->restore_from_zip( (string) $this->store->path_for( $record ), $file, $record['sha256'], '0.9', '2.0' );
			self::fail( 'exception attendue' );
		} catch ( RestoreException $e ) {
			self::assertSame( RestoreException::INTEGRITY, $e->error_code() );
		}
		self::assertSame( $tree, $this->tree( $this->plugins . '/akismet' ) );
	}

	/**
	 * Le plugin a été mis à jour à la main pendant le délai de grâce : refus, rien n'est touché.
	 */
	public function test_version_drift_touches_nothing(): void {
		$file   = $this->make_plugin( 'akismet', '1.0' );
		$record = $this->snapshotter->create( $file, [ 'version' => '1.0' ], self::NOW );
		$this->upgrade_plugin( 'akismet', '2.1' ); // le manager croit 2.0
		$tree = $this->tree( $this->plugins . '/akismet' );

		try {
			( new PluginRestorer( $this->plugins ) )->restore_from_zip( (string) $this->store->path_for( $record ), $file, $record['sha256'], '1.0', '2.0' );
			self::fail( 'exception attendue' );
		} catch ( RestoreException $e ) {
			self::assertSame( RestoreException::VERSION_DRIFT, $e->error_code() );
		}
		self::assertSame( $tree, $this->tree( $this->plugins . '/akismet' ) );
	}

	/**
	 * Extraction en échec (disque plein, droits) : le dossier mis de côté reprend sa
	 * place, le plugin courant est intact.
	 */
	public function test_failed_extraction_puts_the_current_plugin_back(): void {
		$file   = $this->make_plugin( 'akismet', '1.0' );
		$record = $this->snapshotter->create( $file, [ 'version' => '1.0' ], self::NOW );
		$this->upgrade_plugin( 'akismet', '2.0' );
		$tree = $this->tree( $this->plugins . '/akismet' );

		$failing = static function ( string $zip, string $destination ): void {
			// Extraction à moitié faite, puis panne.
			mkdir( $destination . '/akismet' );
			file_put_contents( $destination . '/akismet/partial.php', 'x' );
			throw new \RuntimeException( 'disk full' );
		};

		try {
			( new PluginRestorer( $this->plugins, $failing ) )->restore_from_zip( (string) $this->store->path_for( $record ), $file, $record['sha256'], '1.0', '2.0' );
			self::fail( 'exception attendue' );
		} catch ( RestoreException $e ) {
			self::assertSame( RestoreException::FAILED, $e->error_code() );
			self::assertStringContainsString( 'disk full', $e->getMessage() );
		}

		self::assertSame( $tree, $this->tree( $this->plugins . '/akismet' ), 'le plugin 2.0 est de retour, intact' );
		self::assertFileDoesNotExist( $this->plugins . '/akismet/partial.php' );
		self::assertSame( [], glob( $this->plugins . '/akismet.g2rd-old-*' ) ?: [] );
	}

	/**
	 * Zip wordpress.org (pas de hash) dont une entrée sort du dossier : refusé avant toute écriture.
	 */
	public function test_zip_entry_outside_the_plugin_directory_is_refused(): void {
		$file = $this->make_plugin( 'akismet', '2.0' );
		$tree = $this->tree( $this->plugins . '/akismet' );

		$evil = $this->root . '/evil.zip';
		$zip  = new \ZipArchive();
		$zip->open( $evil, \ZipArchive::CREATE );
		$zip->addFromString( 'akismet/akismet.php', "<?php\n/**\n * Version: 1.0\n */\n" );
		$zip->addFromString( 'g2rd-connector/g2rd-connector.php', '<?php // pwned' );
		$zip->close();

		try {
			( new PluginRestorer( $this->plugins ) )->restore_from_zip( $evil, $file, '', '1.0', '2.0' );
			self::fail( 'exception attendue' );
		} catch ( RestoreException $e ) {
			self::assertSame( RestoreException::INTEGRITY, $e->error_code() );
		}
		self::assertSame( $tree, $this->tree( $this->plugins . '/akismet' ) );
		self::assertDirectoryDoesNotExist( $this->plugins . '/g2rd-connector' );
	}

	public function test_missing_archive_is_an_integrity_error(): void {
		$file = $this->make_plugin( 'akismet', '2.0' );
		$this->expectException( RestoreException::class );
		( new PluginRestorer( $this->plugins ) )->restore_from_zip( $this->snapshots . '/absent.zip', $file, '', '1.0', null );
	}

	/**
	 * @return iterable<string, array{string, string|null}>
	 */
	public static function headers(): iterable {
		yield 'docblock' => [ "<?php\n/**\n * Plugin Name: X\n * Version: 1.2.3\n */", '1.2.3' ];
		yield 'commentaire //' => [ "<?php\n// Version: 4.5\n", '4.5' ];
		yield 'CRLF' => [ "<?php\r\n/*\r\nVersion: 2.0-beta\r\n*/", '2.0-beta' ];
		yield 'fin de commentaire sur la ligne' => [ "<?php /* Version: 3.1 */ echo 1;", '3.1' ];
		yield 'absent' => [ "<?php\n// rien\n", null ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'headers' )]
	public function test_version_from_header( string $content, ?string $expected ): void {
		self::assertSame( $expected, PluginRestorer::version_from_header( $content ) );
	}

	/** Passe le plugin factice en `$version` : en-tête modifié, fichier ajouté, fichier retiré. */
	private function upgrade_plugin( string $slug, string $version ): void {
		$dir = $this->plugins . '/' . $slug;
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/**\n * Plugin Name: $slug\n * Version: $version\n */\nnew_feature();\n" );
		file_put_contents( $dir . '/inc/new-module.php', "<?php\n// nouveau\n" );
		if ( file_exists( $dir . '/assets/old.css' ) ) {
			unlink( $dir . '/assets/old.css' );
		}
	}

	/**
	 * Empreinte d'une arborescence : chemin relatif => sha256 du contenu (dossiers inclus).
	 *
	 * @return array<string, string>
	 */
	private function tree( string $dir ): array {
		$out   = [];
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$rel         = str_replace( '\\', '/', substr( (string) $item, strlen( $dir ) + 1 ) );
			$out[ $rel ] = $item->isDir() ? 'dir' : hash_file( 'sha256', (string) $item );
		}
		ksort( $out );
		return $out;
	}
}
