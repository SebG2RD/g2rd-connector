<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Rollback\UpdateTransaction;

final class RestorePointPurgeJobTest extends FilesystemTestCase {

	private const NOW = 1789000000;

	private RestorePointStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RestorePointStore();
		$this->store->ensure_dir();
	}

	public function test_purges_expired_points_and_keeps_the_others(): void {
		$this->add( 'expired', expires: self::NOW - 1 );
		$this->add( 'in-grace', expires: self::NOW + 3600 );
		$this->add( 'no-expiry', expires: null );

		$report = RestorePointPurgeJob::purge( $this->store, self::NOW );

		self::assertSame( 1, $report['expired'] );
		self::assertNull( $this->store->get( 'expired' ) );
		self::assertFileDoesNotExist( $this->snapshots . '/expired.zip' );
		self::assertNotNull( $this->store->get( 'in-grace' ) );
		self::assertNotNull( $this->store->get( 'no-expiry' ), 'sans expiration ni hold : conservé (cas legacy), le plafond par plugin le bornera' );
	}

	/** Un point retenu ne suit pas le délai de grâce, mais tombe à son plafond. */
	public function test_held_points_fall_at_their_ceiling_only(): void {
		$this->add( 'held-fresh', expires: self::NOW - 999, hold: true, hold_until: self::NOW + 10 );
		$this->add( 'held-old', expires: null, hold: true, hold_until: self::NOW - 1 );
		$this->add( 'held-legacy', expires: null, hold: true, created: self::NOW - RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS - 1 );

		$report = RestorePointPurgeJob::purge( $this->store, self::NOW );

		self::assertSame( 0, $report['expired'], 'un point retenu n\'est jamais compté comme expiré' );
		self::assertSame( 2, $report['held'] );
		self::assertNotNull( $this->store->get( 'held-fresh' ) );
		self::assertNull( $this->store->get( 'held-old' ) );
		self::assertNull( $this->store->get( 'held-legacy' ), 'sans hold_until : plafond par défaut depuis la création' );
	}

	public function test_enforces_the_absolute_cap_per_plugin(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->add( 'p' . $i, plugin: 'x/x.php', expires: self::NOW + 9999, created: self::NOW - 100 + $i );
		}

		$report = RestorePointPurgeJob::purge( $this->store, self::NOW );

		self::assertSame( 2, $report['capped'] );
		self::assertSame( [ 'p3', 'p4', 'p5' ], array_keys( $this->store->for_plugin( 'x/x.php' ) ) );
	}

	public function test_prunes_orphans_and_old_outcomes_and_recovers_stale_transactions(): void {
		file_put_contents( $this->snapshots . '/orphelin-1-abcd.zip', 'x' );
		file_put_contents( $this->snapshots . '/etranger.txt', 'x' );
		PendingOutcomes::add( [ 'plugin_file' => 'x/x.php', 'outcome' => 'old' ], self::NOW - PendingOutcomes::TTL - 1 );
		PendingOutcomes::add( [ 'plugin_file' => 'y/y.php', 'outcome' => 'recent' ], self::NOW - 10 );
		// Transaction morte sans point : consignée, pas de fichiers à toucher.
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], self::NOW - 5000 );

		$report = RestorePointPurgeJob::purge( $this->store, self::NOW );

		self::assertSame( [ 'files' => 1, 'records' => 0 ], $report['orphans'] );
		self::assertFileExists( $this->snapshots . '/etranger.txt', 'jamais un fichier qui n\'est pas un zip' );
		self::assertSame( 1, $report['outcomes'] );
		self::assertNull( UpdateTransaction::current() );
		$outcomes = array_column( PendingOutcomes::all(), 'outcome' );
		self::assertContains( 'recent', $outcomes );
		self::assertContains( 'interrupted_unverified', $outcomes );
		self::assertNotContains( 'old', $outcomes );
	}

	public function test_empty_store_is_a_no_op(): void {
		$report = RestorePointPurgeJob::purge( $this->store, self::NOW );
		self::assertSame( [ 'expired' => 0, 'held' => 0, 'capped' => 0, 'orphans' => [ 'files' => 0, 'records' => 0 ], 'outcomes' => 0 ], $report );
	}

	private function add( string $id, string $plugin = 'a/a.php', ?int $expires = null, bool $hold = false, ?int $hold_until = null, int $created = self::NOW ): void {
		file_put_contents( $this->snapshots . '/' . $id . '.zip', 'z' );
		$record = [
			'id'          => $id,
			'plugin_file' => $plugin,
			'slug'        => dirname( $plugin ),
			'version'     => '1.0',
			'kind'        => 'premium',
			'file'        => $id . '.zip',
			'sha256'      => str_repeat( '0', 64 ),
			'size'        => 1,
			'created_at'  => $created,
			'expires_at'  => $expires,
			'hold'        => $hold,
		];
		if ( null !== $hold_until ) {
			$record['hold_until'] = $hold_until;
		}
		$this->store->add( $record );
	}
}
