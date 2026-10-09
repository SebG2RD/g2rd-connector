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
		// Une reprise qui réserve une transaction programme un contrôle (filet si elle
		// mourait en route, cf. ProtectedUpdate::recover()) : rien en attente par défaut.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
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

	/**
	 * Transaction morte : reprise d'abord, puis purge normale. Seul contrôle programmé :
	 * celui de la réservation, rien de reprogrammé ensuite.
	 */
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
		$this->assert_only_the_reservation_check( $scheduled, $now );
	}

	public function test_run_schedules_nothing_without_an_open_transaction(): void {
		Functions\expect( 'wp_schedule_single_event' )->never();

		( new RestorePointPurgeJob() )->run();

		self::assertNull( UpdateTransaction::current() );
	}

	/**
	 * Le contrôle ponctuel ne fait que reprendre une transaction morte : ni purge des
	 * points (expirés, orphelins), ni reprogrammation une fois la transaction fermée
	 * (seul reste le contrôle posé à la réservation).
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
		$this->assert_only_the_reservation_check( $scheduled, $now );
		self::assertArrayNotHasKey( UpdateTransaction::RECOVERY_TRACE_KEY, $this->options, 'Fermeture réussie : aucune trace écrite.' );
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
	 * Transaction fraîche à la limite (presque 10 min depuis sa dernière étape) : le
	 * contrôle est reprogrammé dans le futur, au moins une minute plus tard.
	 */
	public function test_recovery_check_reschedules_a_fresh_transaction_in_the_future(): void {
		$scheduled = $this->record_single_events();
		$before    = time();
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $before - UpdateTransaction::STALE_AFTER_SECONDS + 5 );

		( new RestorePointPurgeJob() )->run_recovery_check();

		self::assertNotNull( UpdateTransaction::current(), 'Pas encore morte : pas reprise.' );
		self::assertSame( [], PendingOutcomes::all() );
		self::assertCount( 1, $scheduled );
		[ $timestamp, $hook ] = $scheduled[0];
		self::assertSame( RestorePointPurgeJob::RECOVERY_HOOK, $hook );
		self::assertGreaterThanOrEqual( $before + 60, $timestamp );
		self::assertGreaterThan( time(), $timestamp, 'Jamais dans le passé : WP-Cron le relancerait aussitôt.' );
	}

	/**
	 * Transaction morte que la reprise n'arrive pas à retirer (delete_option() en
	 * échec) : le contrôle ne se reprogramme pas — à `updated_at + délai`, date déjà
	 * passée, WP-Cron le relancerait à chaque passage, environ une fois par minute —
	 * et la reprise n'est pas rejouée sur la même transaction. Seul reste le contrôle
	 * posé à la réservation, dans le futur.
	 */
	public function test_recovery_check_does_not_loop_on_a_dead_transaction_it_cannot_close(): void {
		$scheduled = $this->record_single_events();
		$before    = time();
		$this->make_transaction_undeletable();
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], time() - 5000 );

		( new RestorePointPurgeJob() )->run_recovery_check();
		( new RestorePointPurgeJob() )->run_recovery_check();

		$txn = UpdateTransaction::current();
		self::assertNotNull( $txn, 'Toujours là : delete_option() échoue.' );
		$this->assert_only_the_reservation_check( $scheduled, $before );
		self::assertSame( [ 'interrupted_unverified' ], array_column( PendingOutcomes::all(), 'outcome' ), 'Une seule reprise pour cette transaction.' );
		$trace = $this->options[ UpdateTransaction::RECOVERY_TRACE_KEY ] ?? null;
		self::assertIsArray( $trace, 'Reprise tracée.' );
		self::assertSame( UpdateTransaction::identity( $txn ), $trace['txn'] );
		self::assertSame( 'interrupted_unverified', $trace['outcome'] );
	}

	/**
	 * Même transaction morte et impossible à retirer, au passage biquotidien : elle ne
	 * protège plus aucune mise à jour vivante, la purge n'est donc pas reportée
	 * indéfiniment, et rien n'est reprogrammé.
	 */
	public function test_purge_is_not_deferred_forever_by_a_dead_transaction_it_cannot_close(): void {
		$scheduled = $this->record_single_events();
		$this->make_transaction_undeletable();
		$now = time();
		$this->add( 'expire', expires: $now - 1 );
		UpdateTransaction::open( [ 'plugin_file' => 'z/z.php' ], $now - 5000 );

		( new RestorePointPurgeJob() )->run();
		( new RestorePointPurgeJob() )->run();

		self::assertNotNull( UpdateTransaction::current() );
		self::assertNull( $this->store->get( 'expire' ), 'Purge faite.' );
		$this->assert_only_the_reservation_check( $scheduled, $now );
		self::assertSame( [ 'interrupted_unverified' ], array_column( PendingOutcomes::all(), 'outcome' ) );
	}

	/**
	 * delete_option() échoue sur la transaction (base en erreur, ligne verrouillée…) ;
	 * les autres options se suppriment normalement.
	 */
	private function make_transaction_undeletable(): void {
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				if ( UpdateTransaction::OPTION_KEY === $key ) {
					return false;
				}
				unset( $this->options[ $key ] );
				return true;
			}
		);
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
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( string $hook ) use ( $scheduled ) {
				foreach ( $scheduled as [ $timestamp, $event_hook ] ) {
					if ( $event_hook === $hook ) {
						return $timestamp;
					}
				}
				return false;
			}
		);
		return $scheduled;
	}

	/**
	 * Seul contrôle programmé : celui posé quand la reprise a réservé la transaction
	 * (filet si elle mourait en route), dans le futur. Rien de reprogrammé ensuite,
	 * ni dans le passé.
	 *
	 * @param \ArrayObject<int, array{0:int, 1:string}> $scheduled
	 */
	private function assert_only_the_reservation_check( \ArrayObject $scheduled, int $before ): void {
		self::assertCount( 1, $scheduled, 'Un seul contrôle : celui de la réservation.' );
		[ $timestamp, $hook ] = $scheduled[0];
		self::assertSame( RestorePointPurgeJob::RECOVERY_HOOK, $hook );
		self::assertGreaterThanOrEqual( $before + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
		self::assertLessThanOrEqual( time() + RestorePointPurgeJob::RECOVERY_CHECK_DELAY, $timestamp );
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
