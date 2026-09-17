<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Tests\TestCase;

final class SignatureStateTest extends TestCase {

	private const NOW = 1789000000;

	public function test_first_sight_is_accepted_and_second_is_a_replay(): void {
		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );
		self::assertFalse( SignatureState::remember_nonce( 'aaaa', self::NOW + 5 ) );
	}

	public function test_distinct_nonces_are_accepted(): void {
		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );
		self::assertTrue( SignatureState::remember_nonce( 'bbbb', self::NOW ) );
	}

	public function test_expired_nonces_are_pruned(): void {
		SignatureState::remember_nonce( 'vieux', self::NOW );
		SignatureState::remember_nonce( 'recent', self::NOW + SignatureState::NONCE_TTL_SECONDS + 1 );

		$stored = $this->options[ SignatureState::OPTION_KEY ]['nonces'];
		self::assertArrayNotHasKey( 'vieux', $stored );
		self::assertArrayHasKey( 'recent', $stored );
	}

	public function test_a_nonce_is_kept_for_the_whole_ttl(): void {
		SignatureState::remember_nonce( 'aaaa', self::NOW );
		self::assertFalse( SignatureState::remember_nonce( 'aaaa', self::NOW + SignatureState::NONCE_TTL_SECONDS ) );
	}

	public function test_store_is_bounded_and_drops_the_oldest(): void {
		SignatureState::remember_nonce( 'le-plus-ancien', self::NOW );
		for ( $i = 1; $i <= SignatureState::MAX_NONCES; $i++ ) {
			SignatureState::remember_nonce( 'n' . $i, self::NOW + 1 );
		}

		$stored = $this->options[ SignatureState::OPTION_KEY ]['nonces'];
		self::assertCount( SignatureState::MAX_NONCES, $stored );
		self::assertArrayNotHasKey( 'le-plus-ancien', $stored );
		self::assertArrayHasKey( 'n' . SignatureState::MAX_NONCES, $stored );
	}

	public function test_option_is_written_without_autoload(): void {
		$autoload = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $flag = null ) use ( &$autoload ): bool {
				$autoload              = $flag;
				$this->options[ $key ] = $value;
				return true;
			}
		);

		SignatureState::remember_nonce( 'aaaa', self::NOW );
		self::assertFalse( $autoload );
	}

	public function test_failures_are_counted(): void {
		self::assertSame( 0, SignatureState::stats()['failed_count'] );

		SignatureState::record_failure( 'signature_invalid', self::NOW );
		SignatureState::record_failure( 'clock_skew', self::NOW + 10 );

		self::assertSame(
			[
				'failed_count'   => 2,
				'last_failed_at' => self::NOW + 10,
				'last_code'      => 'clock_skew',
			],
			SignatureState::stats()
		);
	}

	public function test_recording_a_failure_keeps_known_nonces(): void {
		SignatureState::remember_nonce( 'aaaa', self::NOW );
		SignatureState::record_failure( 'signature_invalid', self::NOW );
		self::assertFalse( SignatureState::remember_nonce( 'aaaa', self::NOW + 1 ) );
	}

	public function test_corrupted_option_is_tolerated(): void {
		$this->options[ SignatureState::OPTION_KEY ] = 'pas un tableau';
		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );
		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}
}
