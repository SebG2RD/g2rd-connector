<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Tests\TestCase;

final class SignatureStateTest extends TestCase {

	private const NOW = 1789000000;

	/** $wpdb global d'origine, rétabli après chaque test. */
	private bool $had_wpdb = false;

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

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

	// ── K2 : le registre est borné par le TEMPS, jamais par le volume ───────────

	/**
	 * K2 — avant le correctif, le registre gardait au plus 500 nonces et évinçait
	 * les plus anciens SANS regarder s'ils étaient encore dans leur fenêtre de
	 * rejeu : 500 requêtes signées suffisaient à rendre rejouable un nonce de 20 s.
	 */
	public function test_a_nonce_still_in_the_window_is_never_evicted_by_volume(): void {
		self::assertTrue( SignatureState::remember_nonce( 'cible', self::NOW ) );
		for ( $i = 1; $i <= 1000; $i++ ) {
			SignatureState::remember_nonce( 'n' . $i, self::NOW + 10 );
		}

		self::assertFalse( SignatureState::remember_nonce( 'cible', self::NOW + 20 ), 'un nonce de 20 s doit rester un rejeu' );
	}

	public function test_registry_is_bounded_by_time_not_by_count(): void {
		for ( $i = 1; $i <= 1000; $i++ ) {
			SignatureState::remember_nonce( 'n' . $i, self::NOW );
		}
		self::assertCount( 1000, $this->options[ SignatureState::OPTION_KEY ]['nonces'] );

		SignatureState::remember_nonce( 'apres', self::NOW + SignatureState::NONCE_TTL_SECONDS + 1 );
		self::assertSame( [ 'apres' => self::NOW + SignatureState::NONCE_TTL_SECONDS + 1 ], $this->options[ SignatureState::OPTION_KEY ]['nonces'] );
	}

	/**
	 * Remplace l'ancien test « le registre est borné et évince le plus ancien », qui
	 * figeait précisément le défaut K2. Au plafond mémoire, on REFUSE le nouveau
	 * nonce au lieu d'évincer une entrée encore vivante.
	 */
	public function test_saturated_registry_refuses_instead_of_evicting(): void {
		$this->fill_registry( SignatureState::MAX_LIVE_NONCES, self::NOW );

		self::assertSame( SignatureState::NONCE_STORE_FULL, SignatureState::register_nonce( 'nouveau', self::NOW + 1 ) );
		self::assertFalse( SignatureState::remember_nonce( 'nouveau', self::NOW + 1 ) );
		self::assertSame( SignatureState::NONCE_REPLAYED, SignatureState::register_nonce( 'n1', self::NOW + 1 ), 'une entrée vivante reste un rejeu' );

		$stored = $this->options[ SignatureState::OPTION_KEY ]['nonces'];
		self::assertCount( SignatureState::MAX_LIVE_NONCES, $stored );
		self::assertArrayNotHasKey( 'nouveau', $stored );
	}

	public function test_saturation_clears_once_entries_expire(): void {
		$this->fill_registry( SignatureState::MAX_LIVE_NONCES, self::NOW );

		self::assertSame(
			SignatureState::NONCE_ACCEPTED,
			SignatureState::register_nonce( 'nouveau', self::NOW + SignatureState::NONCE_TTL_SECONDS + 1 )
		);
		self::assertSame( [ 'nouveau' ], array_keys( $this->options[ SignatureState::OPTION_KEY ]['nonces'] ) );
	}

	public function test_register_nonce_reports_accepted_then_replayed(): void {
		self::assertSame( SignatureState::NONCE_ACCEPTED, SignatureState::register_nonce( 'aaaa', self::NOW ) );
		self::assertSame( SignatureState::NONCE_REPLAYED, SignatureState::register_nonce( 'aaaa', self::NOW + 1 ) );
	}

	/** La rétention couvre toute la durée pendant laquelle un nonce reste rejouable. */
	public function test_ttl_covers_the_whole_replay_window(): void {
		self::assertGreaterThanOrEqual( 2 * RequestSignature::WINDOW_SECONDS, SignatureState::NONCE_TTL_SECONDS );
	}

	/**
	 * Caractérisation : un site qui passe de rc.4 au correctif garde son registre
	 * et ses compteurs tels quels — même option, même structure, aucune migration.
	 */
	public function test_legacy_rc4_option_is_read_unchanged(): void {
		$legacy = [];
		for ( $i = 1; $i <= 500; $i++ ) {
			$legacy[ 'n' . $i ] = self::NOW;
		}
		$this->options[ SignatureState::OPTION_KEY ] = [
			'nonces' => $legacy,
			'stats'  => [
				'failed_count'   => 3,
				'last_failed_at' => self::NOW - 50,
				'last_code'      => 'clock_skew',
			],
		];

		self::assertFalse( SignatureState::remember_nonce( 'n250', self::NOW + 5 ) );
		self::assertTrue( SignatureState::remember_nonce( 'neuf', self::NOW + 5 ) );

		$stored = $this->options[ SignatureState::OPTION_KEY ];
		self::assertSame( [ 'nonces', 'stats' ], array_keys( $stored ) );
		self::assertCount( 501, $stored['nonces'] );
		self::assertSame(
			[
				'failed_count'   => 3,
				'last_failed_at' => self::NOW - 50,
				'last_code'      => 'clock_skew',
			],
			SignatureState::stats()
		);
	}

	public function test_record_failure_prunes_expired_and_keeps_live_nonces(): void {
		SignatureState::remember_nonce( 'vieux', self::NOW );
		SignatureState::remember_nonce( 'vivant', self::NOW + SignatureState::NONCE_TTL_SECONDS );

		SignatureState::record_failure( 'signature_invalid', self::NOW + SignatureState::NONCE_TTL_SECONDS + 1 );

		self::assertSame( [ 'vivant' ], array_keys( $this->options[ SignatureState::OPTION_KEY ]['nonces'] ) );
	}

	// ── K2 : lecture-modification-écriture sous verrou MySQL, repli sans verrou ──

	public function test_lock_is_taken_and_released_around_the_write(): void {
		$log = $this->fake_wpdb( '1' );
		\Brain\Monkey\Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $key, string $group ) use ( $log ): bool {
				$log->entries[] = 'cache_delete:' . $group . ':' . $key;
				return true;
			}
		);
		$this->log_writes( $log );

		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );

		$kinds = array_map( static fn ( string $e ): string => strtok( $e, ':' ), $log->entries );
		self::assertSame( [ 'GET_LOCK', 'cache_delete', 'write', 'RELEASE_LOCK' ], $kinds );
		self::assertSame( 'cache_delete:options:' . SignatureState::OPTION_KEY, $log->entries[1] );

		$name = $log->lock_names[0];
		self::assertStringStartsWith( 'g2rd_sig_', $name );
		self::assertLessThanOrEqual( 64, strlen( $name ) );
		self::assertSame( $name, $log->lock_names[1], 'le verrou libéré est celui qui a été pris' );
	}

	/** Le nom du verrou dépend de la table des options : pas de collision entre sites voisins. */
	public function test_lock_name_differs_per_options_table(): void {
		$log = $this->fake_wpdb( '1', 'wp_options' );
		SignatureState::remember_nonce( 'aaaa', self::NOW );
		$first = $log->lock_names[0];

		$log = $this->fake_wpdb( '1', 'autre_options' );
		SignatureState::remember_nonce( 'bbbb', self::NOW );

		self::assertNotSame( $first, $log->lock_names[0] );
	}

	/**
	 * GET_LOCK désactivé, en délai dépassé (NULL / '0') ou $wpdb absent : on
	 * retombe exactement sur le comportement d'avant, sans refus ni exception.
	 *
	 * @return iterable<string, array{string|null}>
	 */
	public static function unavailable_lock_results(): iterable {
		yield 'délai dépassé' => [ '0' ];
		yield 'fonction indisponible' => [ null ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'unavailable_lock_results' )]
	public function test_unavailable_lock_falls_back_to_current_behaviour( ?string $result ): void {
		$log = $this->fake_wpdb( $result );

		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );
		self::assertFalse( SignatureState::remember_nonce( 'aaaa', self::NOW + 1 ) );
		self::assertNotContains( 'RELEASE_LOCK', array_map( static fn ( string $e ): string => strtok( $e, ':' ), $log->entries ), 'un verrou non obtenu ne se libère pas' );
	}

	public function test_without_wpdb_the_registry_still_works(): void {
		unset( $GLOBALS['wpdb'] );
		self::assertTrue( SignatureState::remember_nonce( 'aaaa', self::NOW ) );
		self::assertFalse( SignatureState::remember_nonce( 'aaaa', self::NOW + 1 ) );
	}

	public function test_lock_is_released_even_if_the_write_throws(): void {
		$log = $this->fake_wpdb( '1' );
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'base de données indisponible' );
			}
		);

		try {
			SignatureState::remember_nonce( 'aaaa', self::NOW );
			self::fail( 'l’exception d’écriture doit remonter (Auth la rattrape)' );
		} catch ( \RuntimeException ) {
			// Attendu.
		}

		self::assertSame( 'RELEASE_LOCK', strtok( (string) end( $log->entries ), ':' ) );
	}

	/** Les erreurs SQL d'un GET_LOCK refusé ne doivent jamais s'afficher dans une réponse REST. */
	public function test_lock_queries_do_not_print_database_errors(): void {
		$log = $this->fake_wpdb( null );
		SignatureState::remember_nonce( 'aaaa', self::NOW );

		self::assertSame( [ true, false ], $log->suppress_calls, 'erreurs masquées le temps du verrou, puis état d’origine rétabli' );
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

	/**
	 * Préremplit le registre directement (n'appelle pas register_nonce N fois).
	 */
	private function fill_registry( int $count, int $seen_at ): void {
		$nonces = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$nonces[ 'n' . $i ] = $seen_at;
		}
		$this->options[ SignatureState::OPTION_KEY ] = [
			'nonces' => $nonces,
			'stats'  => [
				'failed_count'   => 0,
				'last_failed_at' => null,
				'last_code'      => null,
			],
		];
	}

	/**
	 * Faux $wpdb : journalise GET_LOCK / RELEASE_LOCK et répond `$lock_result`.
	 *
	 * Le journal renvoyé porte `entries` (list<string>), `lock_names` (list<string>)
	 * et `suppress_calls` (list<bool>).
	 */
	private function fake_wpdb( ?string $lock_result, string $options_table = 'wp_options' ): \stdClass {
		$log                 = new \stdClass();
		$log->entries        = [];
		$log->lock_names     = [];
		$log->suppress_calls = [];

		// Appelée sous verrou ; un test qui veut la tracer la redéfinit ensuite.
		\Brain\Monkey\Functions\when( 'wp_cache_delete' )->justReturn( true );

		$GLOBALS['wpdb'] = new class( $log, $lock_result, $options_table ) {
			public string $options;

			private bool $suppress = false;

			public function __construct( private \stdClass $log, private ?string $lock_result, string $options_table ) {
				$this->options = $options_table;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = (string) preg_replace( '/%[sd]/', "'" . (string) $arg . "'", $query, 1 );
				}
				return $query;
			}

			public function get_var( string $query ): ?string {
				if ( 1 === preg_match( "/^SELECT (GET_LOCK|RELEASE_LOCK)\('([^']*)'/", $query, $m ) ) {
					$this->log->entries[]    = $m[1] . ':' . $m[2];
					$this->log->lock_names[] = $m[2];
					return 'GET_LOCK' === $m[1] ? $this->lock_result : '1';
				}
				return null;
			}

			public function suppress_errors( bool $suppress = true ): bool {
				$this->log->suppress_calls[] = $suppress;
				$previous                    = $this->suppress;
				$this->suppress              = $suppress;
				return $previous;
			}
		};

		return $log;
	}

	private function log_writes( \stdClass $log ): void {
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( $log ): bool {
				$log->entries[]        = 'write:' . $key;
				$this->options[ $key ] = $value;
				return true;
			}
		);
	}
}
