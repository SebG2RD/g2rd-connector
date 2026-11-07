<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Gate\BackupLock;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class BackupLockTest extends BackupTestCase {

	private const NOW = 1800000000;

	public function test_la_tranche_s_execute_verrou_tenu_puis_le_rend(): void {
		$vu = BackupLock::for_slice( fn (): array => $this->wpdb->held_here, self::NOW );

		self::assertSame( [ $this->lock_name() => 1 ], $vu );
		self::assertSame( [], $this->wpdb->held_here, 'rendu' );
	}

	public function test_le_verrou_est_rendu_meme_si_la_tranche_leve(): void {
		try {
			BackupLock::for_slice( static fn (): never => throw new \RuntimeException( 'panne' ), self::NOW );
		} catch ( \RuntimeException ) {
			// Attendu.
		}

		self::assertSame( [], $this->wpdb->held_here );
	}

	public function test_verrou_tenu_sans_temoin_une_autre_tranche_tourne(): void {
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;

		$refus = $this->refus( static fn (): bool => true );

		self::assertSame( BackupRefused::SLICE_RUNNING, $refus->reason_code() );
	}

	public function test_verrou_tenu_avec_temoin_une_mise_a_jour_tourne(): void {
		UpdateWitness::add( 'update_core', true, self::NOW );
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;

		$refus = $this->refus( static fn (): bool => true );

		self::assertSame( BackupRefused::UPDATE_IN_PROGRESS, $refus->reason_code() );
		self::assertSame( 60, $refus->retry_after() );
	}

	public function test_verrou_libre_mais_mise_a_jour_sans_verrou_vivante(): void {
		UpdateWitness::add( 'update_plugin', false, self::NOW - 10 );

		$refus = $this->refus( static fn (): bool => true );

		self::assertSame( BackupRefused::UPDATE_IN_PROGRESS, $refus->reason_code() );
		self::assertSame( [], $this->wpdb->held_here, 'verrou rendu avant de refuser' );
	}

	public function test_verrou_libre_les_temoins_morts_sont_effaces(): void {
		UpdateWitness::add( 'update_core', true, self::NOW - 100 );

		self::assertTrue( BackupLock::for_slice( static fn (): bool => true, self::NOW ) );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_verrou_impossible_refus_reessayable(): void {
		$this->wpdb->locks_unavailable = true;

		$refus = $this->refus( static fn (): bool => true );

		self::assertSame( BackupRefused::LOCK_UNAVAILABLE, $refus->reason_code() );
		self::assertSame( 30, $refus->retry_after() );
	}

	private function refus( \Closure $operation ): BackupRefused {
		try {
			BackupLock::for_slice( $operation, self::NOW );
		} catch ( BackupRefused $refus ) {
			return $refus;
		}
		self::fail( 'Refus attendu.' );
	}
}
