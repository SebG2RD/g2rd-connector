<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use G2RD\Connector\Rollback\RestorePointStore;

final class RestorePointStoreTest extends FilesystemTestCase {

	private const NOW = 1789000000;

	private RestorePointStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RestorePointStore();
		$this->store->ensure_dir();
	}

	public function test_index_is_written_without_autoload(): void {
		$autoload = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $flag = null ) use ( &$autoload ): bool {
				$autoload              = $flag;
				$this->options[ $key ] = $value;
				return true;
			}
		);

		$this->store->add( $this->record( 'a', 'x/x.php', 'a.zip' ) );
		self::assertFalse( $autoload );
	}

	public function test_remove_deletes_only_an_indexed_zip_inside_the_directory(): void {
		file_put_contents( $this->snapshots . '/a.zip', 'zip' );
		$this->store->add( $this->record( 'a', 'x/x.php', 'a.zip' ) );

		self::assertTrue( $this->store->remove( 'a' ) );
		self::assertFileDoesNotExist( $this->snapshots . '/a.zip' );
		self::assertNull( $this->store->get( 'a' ) );

		self::assertFalse( $this->store->remove( 'inconnu' ) );
	}

	/**
	 * Un index corrompu ou malveillant ne peut pas faire supprimer un fichier
	 * hors du dossier : le chemin est refusé, l'entrée est retirée, rien d'autre.
	 */
	public function test_a_record_pointing_outside_the_directory_never_deletes_anything(): void {
		$victim = $this->root . '/victime.zip';
		file_put_contents( $victim, 'précieux' );

		foreach ( [ '../victime.zip', $victim, 'sous/dossier.zip', 'sans-extension' ] as $i => $file ) {
			$this->store->add( $this->record( 'r' . $i, 'x/x.php', $file ) );
			self::assertNull( $this->store->path_for( $this->store->get( 'r' . $i ) ?? [] ), $file );
			self::assertTrue( $this->store->remove( 'r' . $i ) );
		}

		self::assertFileExists( $victim );
		self::assertSame( 'précieux', file_get_contents( $victim ) );
	}

	public function test_prune_orphans_removes_records_whose_zip_vanished(): void {
		$this->store->add( $this->record( 'a', 'x/x.php', 'a.zip' ) ); // pas de fichier
		file_put_contents( $this->snapshots . '/b.zip', 'zip' );
		$this->store->add( $this->record( 'b', 'y/y.php', 'b.zip' ) );

		self::assertSame( [ 'files' => 0, 'records' => 1 ], $this->store->prune_orphans() );
		self::assertNull( $this->store->get( 'a' ) );
		self::assertNotNull( $this->store->get( 'b' ) );
	}

	public function test_eviction_order(): void {
		$now = self::NOW;
		$this->add_with_file( 'held-old', 'premium', hold: true, created: $now - 400 );
		$this->add_with_file( 'grace', 'premium', expires: $now + 9999, created: $now - 300 );
		$this->add_with_file( 'expired', 'custom', expires: $now - 10, created: $now - 200 );
		$this->add_with_file( 'wporg-new', 'wporg', created: $now - 100 );
		$this->add_with_file( 'wporg-old', 'wporg', created: $now - 500 );

		$order = [];
		// Budget 0 avec 1 octet demandé : tout doit sortir, dans l'ordre d'éviction.
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$order ): bool {
				$gone = array_diff( array_keys( (array) ( $this->options[ $key ] ?? [] ) ), array_keys( (array) $value ) );
				foreach ( $gone as $id ) {
					$order[] = $id;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
		self::assertSame( 1, $this->store->make_room( 1, 0, $now ) );

		self::assertSame( [ 'wporg-old', 'wporg-new', 'expired', 'grace', 'held-old' ], $order );
	}

	public function test_make_room_stops_as_soon_as_the_budget_is_met(): void {
		$this->add_with_file( 'w1', 'wporg', created: self::NOW - 3, size: 100 );
		$this->add_with_file( 'w2', 'wporg', created: self::NOW - 2, size: 100 );
		$this->add_with_file( 'p', 'premium', created: self::NOW - 1, size: 100 );

		self::assertSame( 0, $this->store->make_room( 50, 250, self::NOW ) );
		self::assertNull( $this->store->get( 'w1' ) );
		self::assertNotNull( $this->store->get( 'w2' ) );
		self::assertNotNull( $this->store->get( 'p' ) );
	}

	public function test_corrupted_index_is_tolerated(): void {
		$this->options[ RestorePointStore::OPTION_KEY ] = 'nimporte';
		self::assertSame( [], $this->store->all() );
		$this->options[ RestorePointStore::OPTION_KEY ] = [ 'a' => 'pas un tableau', 'b' => [ 'sans' => 'cles' ] ];
		self::assertSame( [], $this->store->all() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record( string $id, string $plugin_file, string $file, string $kind = 'premium', ?int $expires = null, bool $hold = false, int $created = self::NOW, int $size = 10 ): array {
		return [
			'id'          => $id,
			'plugin_file' => $plugin_file,
			'slug'        => dirname( $plugin_file ),
			'version'     => '1.0',
			'kind'        => $kind,
			'file'        => $file,
			'sha256'      => str_repeat( '0', 64 ),
			'size'        => $size,
			'created_at'  => $created,
			'expires_at'  => $expires,
			'hold'        => $hold,
		];
	}

	private function add_with_file( string $id, string $kind, ?int $expires = null, bool $hold = false, int $created = self::NOW, int $size = 10 ): void {
		file_put_contents( $this->snapshots . '/' . $id . '.zip', str_repeat( 'z', $size ) );
		$this->store->add( $this->record( $id, $id . '/' . $id . '.php', $id . '.zip', $kind, $expires, $hold, $created, $size ) );
	}
}
