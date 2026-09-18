<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use G2RD\Connector\Rollback\RestorePointException;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\Snapshotter;

final class SnapshotterTest extends FilesystemTestCase {

	private const NOW = 1789000000;

	private RestorePointStore $store;
	private Snapshotter $snapshotter;

	protected function setUp(): void {
		parent::setUp();
		$this->store       = new RestorePointStore();
		$this->snapshotter = new Snapshotter( $this->store, $this->plugins );
	}

	public function test_creates_a_verified_zip_and_indexes_it(): void {
		$file = $this->make_plugin( 'akismet', '5.7', [ 'assets/style.css' => 'body{}' ] );

		$record = $this->snapshotter->create( $file, [ 'version' => '5.7', 'kind' => 'wporg', 'expires_at' => self::NOW + 3600 ], self::NOW );

		$path = $this->store->path_for( $record );
		self::assertNotNull( $path );
		self::assertFileExists( $path );
		self::assertSame( hash_file( 'sha256', $path ), $record['sha256'] );
		self::assertSame( filesize( $path ), $record['size'] );
		self::assertMatchesRegularExpression( '/^akismet-5\.7-[a-f0-9]{32}\.zip$/', $record['file'] );
		self::assertSame( 'akismet/akismet.php', $record['plugin_file'] );
		self::assertSame( self::NOW + 3600, $record['expires_at'] );
		self::assertFalse( $record['hold'] );

		// Le zip contient le dossier du plugin comme racine : extrait dans wp-content/plugins, il restaure en place.
		self::assertSame(
			[ 'akismet/', 'akismet/akismet.php', 'akismet/assets/', 'akismet/assets/style.css', 'akismet/inc/', 'akismet/inc/helper.php' ],
			$this->zip_entries( $path )
		);

		self::assertSame( $record, $this->store->get( $record['id'] ) );
		self::assertFileDoesNotExist( $path . '.part' );
	}

	public function test_protects_the_directory(): void {
		$file = $this->make_plugin( 'akismet' );
		$this->snapshotter->create( $file, [ 'version' => '1' ], self::NOW );

		self::assertFileExists( $this->snapshots . '/index.php' );
		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->snapshots . '/.htaccess' ) );
		self::assertStringContainsString( '<deny users="*" />', (string) file_get_contents( $this->snapshots . '/web.config' ) );
	}

	public function test_single_file_plugin(): void {
		file_put_contents( $this->plugins . '/hello.php', "<?php\n// Hello Dolly\n" );

		$record = $this->snapshotter->create( 'hello.php', [ 'version' => '1.7.2', 'kind' => 'wporg' ], self::NOW );

		self::assertSame( 'hello', $record['slug'] );
		self::assertSame( [ 'hello.php' ], $this->zip_entries( (string) $this->store->path_for( $record ) ) );
	}

	public function test_unknown_plugin_is_refused_without_writing(): void {
		$this->expectException( RestorePointException::class );
		$this->expectExceptionMessageMatches( '/not found/' );

		try {
			$this->snapshotter->create( 'inexistant/inexistant.php', [], self::NOW );
		} finally {
			self::assertDirectoryDoesNotExist( $this->snapshots );
		}
	}

	public function test_path_traversal_is_refused(): void {
		$this->make_plugin( 'akismet' );

		$this->expectException( RestorePointException::class );
		$this->snapshotter->create( '../plugins/akismet/akismet.php', [], self::NOW );
	}

	/**
	 * Budget mesuré et insuffisant : refus explicite, aucun zip écrit, index intact.
	 */
	public function test_budget_exceeded_is_refused_with_the_disk_space_code(): void {
		$file = $this->make_plugin( 'akismet', '5.7', [ 'big.bin' => str_repeat( 'x', 4096 ) ] );

		try {
			$this->snapshotter->create( $file, [ 'version' => '5.7', 'kind' => 'premium', 'max_total_bytes' => 100 ], self::NOW );
			self::fail( 'exception attendue' );
		} catch ( RestorePointException $e ) {
			self::assertSame( RestorePointException::DISK_SPACE, $e->error_code() );
			self::assertStringContainsString( 'budget', $e->getMessage() );
		}

		self::assertSame( [], glob( $this->snapshots . '/*.zip' ) ?: [] );
		self::assertSame( [], $this->store->all() );
	}

	/**
	 * Le budget évince d'abord les points wordpress.org (l'ancienne version reste
	 * téléchargeable), jamais un point premium retenu tant qu'il reste autre chose.
	 */
	public function test_budget_evicts_wporg_points_first(): void {
		$wporg   = $this->make_plugin( 'akismet', '5.7', [ 'pad.bin' => str_repeat( 'a', 3000 ) ] );
		$premium = $this->make_plugin( 'acf-pro', '6.2', [ 'pad.bin' => str_repeat( 'b', 3000 ) ] );
		$third   = $this->make_plugin( 'wp-rocket', '3.15', [ 'pad.bin' => str_repeat( 'c', 3000 ) ] );

		$a = $this->snapshotter->create( $wporg, [ 'version' => '5.7', 'kind' => 'wporg' ], self::NOW );
		$b = $this->snapshotter->create( $premium, [ 'version' => '6.2', 'kind' => 'premium', 'hold' => true ], self::NOW + 1 );

		// Budget calibré : insuffisant tant que rien n'est évincé, suffisant dès que le
		// seul point wordpress.org a disparu (l'espace demandé = taille non compressée).
		$budget = $this->store->total_bytes() - $a['size'] + $this->dir_size( $this->plugins . '/wp-rocket' ) + 1;
		$c      = $this->snapshotter->create( $third, [ 'version' => '3.15', 'kind' => 'premium', 'max_total_bytes' => $budget ], self::NOW + 2 );

		self::assertNull( $this->store->get( $a['id'] ), 'le point wordpress.org est évincé en premier' );
		self::assertNotNull( $this->store->get( $b['id'] ), 'le point premium retenu est conservé' );
		self::assertNotNull( $this->store->get( $c['id'] ) );
		self::assertFileDoesNotExist( (string) $this->store->path_for( $a ) );
	}

	public function test_absolute_cap_of_three_per_plugin(): void {
		$file = $this->make_plugin( 'akismet' );
		$ids  = [];
		for ( $i = 1; $i <= 4; $i++ ) {
			$ids[] = $this->snapshotter->create( $file, [ 'version' => "5.$i", 'kind' => 'premium', 'hold' => true ], self::NOW + $i )['id'];
		}

		$remaining = array_keys( $this->store->for_plugin( $file ) );
		self::assertCount( 3, $remaining );
		self::assertNotContains( $ids[0], $remaining, 'le plus ancien sort, même retenu' );
		self::assertSame( 3, count( glob( $this->snapshots . '/*.zip' ) ?: [] ) );
	}

	public function test_a_leftover_partial_zip_is_not_indexed_and_gets_pruned(): void {
		$file = $this->make_plugin( 'akismet' );
		$this->store->ensure_dir();
		file_put_contents( $this->snapshots . '/akismet-1-deadbeef.zip.part', 'x' );
		file_put_contents( $this->snapshots . '/orphelin-1-cafe.zip', 'x' );
		file_put_contents( $this->snapshots . '/pas-a-moi.txt', 'x' );

		$record = $this->snapshotter->create( $file, [ 'version' => '1' ], self::NOW );
		$pruned = $this->store->prune_orphans();

		self::assertSame( [ 'files' => 1, 'records' => 0 ], $pruned );
		self::assertFileDoesNotExist( $this->snapshots . '/orphelin-1-cafe.zip' );
		self::assertFileExists( $this->snapshots . '/pas-a-moi.txt', 'un fichier non-zip n\'est jamais touché' );
		self::assertFileExists( (string) $this->store->path_for( $record ) );
	}
}
