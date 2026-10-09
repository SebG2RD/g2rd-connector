<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
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

	/**
	 * Une mise à jour protégée en cours (transaction fraîche) : la purge ne touche à
	 * rien. Le point de la mise à jour vaut `expires_at = now` sur les plans sans délai
	 * de grâce, et son zip peut être écrit mais pas encore indexé : les supprimer
	 * pendant le contrôle de santé laisserait un rollback automatique sans archive.
	 */
	public function test_purge_is_deferred_while_a_protected_update_is_running(): void {
		$now = self::NOW;
		$this->add( 'point-en-cours', plugin: 'z/z.php', expires: $now );
		file_put_contents( $this->snapshots . '/z-2.0-pas-encore-indexe.zip', 'z' );
		PendingOutcomes::add( [ 'plugin_file' => 'x/x.php', 'outcome' => 'old' ], $now - PendingOutcomes::TTL - 1 );
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $now - 30 );

		self::assertNull( RestorePointPurgeJob::purge( $this->store, $now ), 'Purge reportée.' );

		self::assertNotNull( $this->store->get( 'point-en-cours' ) );
		self::assertFileExists( $this->snapshots . '/point-en-cours.zip' );
		self::assertFileExists( $this->snapshots . '/z-2.0-pas-encore-indexe.zip', 'Zip renommé mais pas encore indexé : pas un orphelin.' );
		self::assertSame( [ 'old' ], array_column( PendingOutcomes::all(), 'outcome' ), 'Résultats en attente non purgés.' );
		self::assertNotNull( UpdateTransaction::current(), 'Transaction fraîche : jamais reprise par le cron.' );
	}

	/**
	 * Passage biquotidien pendant une mise à jour protégée : rien n'est supprimé, et
	 * seul le contrôle de reprise (hook à lui) est reprogrammé, juste après le délai
	 * au-delà duquel la transaction pourra être déclarée morte.
	 */
	public function test_run_deletes_nothing_and_schedules_the_recovery_check_while_an_update_is_running(): void {
		$scheduled = $this->record_single_events();
		$now       = time();
		$this->add( 'point-en-cours', plugin: 'z/z.php', expires: $now );
		file_put_contents( $this->snapshots . '/z-2.0-pas-encore-indexe.zip', 'z' );
		$updated_at = $now - 30;
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $updated_at );

		( new RestorePointPurgeJob() )->run();

		self::assertNotNull( $this->store->get( 'point-en-cours' ) );
		self::assertFileExists( $this->snapshots . '/point-en-cours.zip' );
		self::assertFileExists( $this->snapshots . '/z-2.0-pas-encore-indexe.zip' );
		self::assertNotNull( UpdateTransaction::current(), 'Transaction fraîche : pas encore reprise.' );
		self::assertSame( [ [ $updated_at + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, RestorePointPurgeJob::RECOVERY_HOOK ] ], $scheduled->getArrayCopy() );
	}

	/** Transaction morte : reprise d'abord, puis purge normale, et plus rien à suivre. */
	public function test_run_recovers_a_dead_transaction_then_purges_normally(): void {
		$scheduled = $this->record_single_events();
		$now       = time();
		$this->add( 'expire', expires: $now - 1 );
		file_put_contents( $this->snapshots . '/orphelin-1-abcd.zip', 'x' );
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $now - 5000 );

		( new RestorePointPurgeJob() )->run();

		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [ 'interrupted_unverified' ], array_column( PendingOutcomes::all(), 'outcome' ) );
		self::assertNull( $this->store->get( 'expire' ) );
		self::assertFileDoesNotExist( $this->snapshots . '/orphelin-1-abcd.zip' );
		self::assertSame( [], $scheduled->getArrayCopy() );
	}

	public function test_run_schedules_nothing_without_an_open_transaction(): void {
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new RestorePointPurgeJob() )->run();

		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Le contrôle ponctuel ne fait que reprendre une transaction morte : ni purge des
	 * points (expirés, orphelins), ni reprogrammation une fois la transaction fermée.
	 */
	public function test_recovery_check_recovers_a_dead_transaction_and_purges_nothing(): void {
		$scheduled = $this->record_single_events();
		$now       = time();
		$this->add( 'expire', expires: $now - 1 );
		file_put_contents( $this->snapshots . '/orphelin-1-abcd.zip', 'x' );
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $now - 5000 );

		( new RestorePointPurgeJob() )->run_recovery_check();

		self::assertNull( UpdateTransaction::current() );
		self::assertSame( [ 'interrupted_unverified' ], array_column( PendingOutcomes::all(), 'outcome' ) );
		self::assertNotNull( $this->store->get( 'expire' ), 'La purge des points attend le passage biquotidien.' );
		self::assertFileExists( $this->snapshots . '/orphelin-1-abcd.zip' );
		self::assertSame( [], $scheduled->getArrayCopy() );
	}

	/** Transaction encore fraîche au moment du contrôle : il se reprogramme, sans rien toucher. */
	public function test_recovery_check_follows_a_transaction_still_open(): void {
		$scheduled  = $this->record_single_events();
		$updated_at = time() - 30;
		$this->add( 'point-en-cours', plugin: 'z/z.php', expires: $updated_at );
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $updated_at );

		( new RestorePointPurgeJob() )->run_recovery_check();

		self::assertNotNull( UpdateTransaction::current() );
		self::assertNotNull( $this->store->get( 'point-en-cours' ) );
		self::assertSame( [], PendingOutcomes::all() );
		self::assertSame( [ [ $updated_at + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, RestorePointPurgeJob::RECOVERY_HOOK ] ], $scheduled->getArrayCopy() );
	}

	/**
	 * Événements ponctuels programmés pendant le test (date, hook).
	 *
	 * @return \ArrayObject<int, array{0:int, 1:string}>
	 */
	private function record_single_events(): \ArrayObject {
		$scheduled = new \ArrayObject();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( int $timestamp, string $hook ) use ( $scheduled ): bool {
				$scheduled->append( [ $timestamp, $hook ] );
				return true;
			}
		);
		return $scheduled;
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
