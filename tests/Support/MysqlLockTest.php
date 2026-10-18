<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Support;

use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Support\MysqlLock;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verrou consultatif MySQL réutilisable, sorti de SignatureState (E6).
 *
 * Le risque principal couvert : un nom de verrou changé laisserait, pendant une mise
 * à jour du connecteur, l'ancienne et la nouvelle version écrire le registre des
 * nonces sans se voir (un nonce effacé redeviendrait rejouable).
 */
final class MysqlLockTest extends TestCase {

	/**
	 * Nom de base de test. WordPress le définit dans wp-config.php ; le banc de test
	 * ne le définit pas. Une constante ne se retire pas : elle reste définie pour la
	 * suite du processus, ce qui ne gêne aucun autre test (seul le nom du verrou en
	 * dépend, et aucun autre test n'en fige la valeur).
	 */
	private const DB_NAME = 'base_secrete_du_site';

	/** $wpdb global d'origine, rétabli après chaque test. */
	private bool $had_wpdb = false;

	private mixed $original_wpdb = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', self::DB_NAME );
		}
	}

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

	// ── run() : opération sous verrou, replis au choix de l'appelant ────────────

	public function test_verrou_pris_puis_libere_autour_de_l_operation(): void {
		$wpdb = $this->install_wpdb( '1' );

		$result = MysqlLock::run(
			'env',
			3,
			static function () use ( $wpdb ): string {
				$wpdb->events[] = 'operation';
				return 'fait';
			},
			static fn (): string => self::fail( 'repli « occupé » appelé alors que le verrou est pris' ),
			static fn (): string => self::fail( 'repli « indisponible » appelé alors que le verrou est pris' )
		);

		self::assertSame( 'fait', $result );
		self::assertSame( [ 'GET_LOCK', 'operation', 'RELEASE_LOCK' ], $wpdb->events );
		$names = $wpdb->lock_names();
		self::assertSame( MysqlLock::name( 'env' ), $names[0] );
		self::assertSame( $names[0], $names[1], 'le verrou libéré est celui qui a été pris' );
	}

	public function test_verrou_libere_meme_si_l_operation_leve(): void {
		$wpdb = $this->install_wpdb( '1' );

		try {
			MysqlLock::run(
				'env',
				3,
				static function (): never {
					throw new \RuntimeException( 'écriture impossible' );
				},
				static fn () => null,
				static fn () => null
			);
			self::fail( 'l’exception de l’opération doit remonter à l’appelant' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'écriture impossible', $e->getMessage() );
		}

		self::assertSame( [ 'GET_LOCK', 'RELEASE_LOCK' ], $wpdb->events );
	}

	public function test_verrou_occupe_appelle_le_repli_occupe_sans_liberer(): void {
		$wpdb = $this->install_wpdb( '0' );

		$result = MysqlLock::run(
			'env',
			0,
			static fn (): string => self::fail( 'opération lancée sans le verrou' ),
			static fn (): string => 'occupé',
			static fn (): string => self::fail( 'un verrou tenu ailleurs n’est pas un verrou indisponible' )
		);

		self::assertSame( 'occupé', $result );
		self::assertSame( [ 'GET_LOCK' ], $wpdb->events, 'un verrou non obtenu ne se libère pas' );
	}

	public function test_get_lock_null_appelle_le_repli_indisponible(): void {
		$wpdb = $this->install_wpdb( null );

		self::assertSame( 'sans verrou', $this->run_expecting_unavailable() );
		self::assertSame( [ 'GET_LOCK' ], $wpdb->events, 'un verrou non obtenu ne se libère pas' );
	}

	public function test_sans_wpdb_appelle_le_repli_indisponible(): void {
		self::assertNull( MysqlLock::name( 'env' ) );
		self::assertSame( 'sans verrou', $this->run_expecting_unavailable() );

		// Un objet sans prepare() n'est pas un $wpdb exploitable.
		$GLOBALS['wpdb'] = new \stdClass();
		self::assertNull( MysqlLock::name( 'env' ) );
		self::assertSame( 'sans verrou', $this->run_expecting_unavailable() );
	}

	public function test_exception_de_la_base_appelle_le_repli_indisponible(): void {
		$wpdb = $this->install_wpdb( new \RuntimeException( 'MySQL server has gone away' ) );

		self::assertSame( 'sans verrou', $this->run_expecting_unavailable() );
		self::assertSame( [ 'GET_LOCK' ], $wpdb->events );
		self::assertFalse( $wpdb->suppress, 'masquage des erreurs rétabli malgré l’exception' );
	}

	// ── acquire() : trois issues distinctes ─────────────────────────────────────

	/**
	 * @return iterable<string, array{string|null|\Throwable, string}>
	 */
	public static function get_lock_results(): iterable {
		yield 'pris' => [ '1', MysqlLock::ACQUIRED ];
		yield 'tenu ailleurs jusqu’à la fin du délai' => [ '0', MysqlLock::BUSY ];
		yield 'NULL de la base (fonction indisponible)' => [ null, MysqlLock::UNAVAILABLE ];
		yield 'exception de la base' => [ new \RuntimeException( 'Galera : GET_LOCK non pris en charge' ), MysqlLock::UNAVAILABLE ];
	}

	#[DataProvider( 'get_lock_results' )]
	public function test_acquire_distingue_occupe_et_indisponible( string|null|\Throwable $get_lock_result, string $expected_state ): void {
		$this->install_wpdb( $get_lock_result );

		$lock = MysqlLock::acquire( 'env', 3 );

		self::assertSame( $expected_state, $lock['state'] );
		self::assertSame( MysqlLock::ACQUIRED === $expected_state ? MysqlLock::name( 'env' ) : null, $lock['name'] );
	}

	// ── Nom du verrou ───────────────────────────────────────────────────────────

	/**
	 * Formule de SignatureState::lock_name(), de la 1.12.0-rc.5 à la 1.14.0-rc.2,
	 * recopiée ici et non appelée : deux versions du connecteur qui tournent ensemble
	 * pendant une mise à jour doivent se sérialiser sur le même verrou.
	 */
	public function test_le_nom_du_registre_des_nonces_est_inchange(): void {
		$wpdb    = $this->install_wpdb( '1' );
		$expected = 'g2rd_sig_' . substr( hash( 'sha256', (string) constant( 'DB_NAME' ) . '|' . 'wp_options' ), 0, 24 );

		self::assertSame( $expected, MysqlLock::name( 'sig' ) );
		self::assertSame( $expected, MysqlLock::name( 'sig', MysqlLock::SCOPE_BLOG ) );

		// Et c'est bien ce nom que le registre des nonces prend et libère.
		SignatureState::register_nonce( 'aaaa', 1789000000 );
		self::assertSame( [ $expected, $expected ], $wpdb->lock_names() );
	}

	public function test_noms_differents_par_table_des_options_et_par_portee(): void {
		$wpdb = $this->install_wpdb( '1' );

		$blog    = MysqlLock::name( 'env' );
		$network = MysqlLock::name( 'env', MysqlLock::SCOPE_NETWORK );
		self::assertNotSame( $blog, $network, 'portées blog et réseau distinctes' );
		self::assertNotSame( $blog, MysqlLock::name( 'sig' ), 'buts distincts' );

		// Autre blog du même réseau : nom « blog » différent, nom « réseau » identique
		// (wp-content et la base sont communs au réseau : une seule mesure).
		$wpdb->options = 'wp_2_options';
		self::assertNotSame( $blog, MysqlLock::name( 'env' ) );
		self::assertSame( $network, MysqlLock::name( 'env', MysqlLock::SCOPE_NETWORK ) );

		// Autre installation sur la même base (autre préfixe) : nom « réseau » différent.
		$wpdb->base_prefix = 'autre_';
		self::assertNotSame( $network, MysqlLock::name( 'env', MysqlLock::SCOPE_NETWORK ) );
	}

	public function test_nom_sous_64_caracteres_et_sans_nom_de_base(): void {
		$this->install_wpdb( '1' );

		foreach ( [ MysqlLock::SCOPE_BLOG, MysqlLock::SCOPE_NETWORK ] as $scope ) {
			$name = (string) MysqlLock::name( 'abcdefghijkl', $scope );

			self::assertMatchesRegularExpression( '/\Ag2rd_abcdefghijkl_[0-9a-f]{24}\z/', $name );
			self::assertLessThanOrEqual( 64, strlen( $name ), 'limite de longueur des noms GET_LOCK de MySQL' );
			self::assertStringNotContainsString( (string) constant( 'DB_NAME' ), $name );
			self::assertStringNotContainsString( 'wp_options', $name );
		}
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function malformed_purposes(): iterable {
		yield 'vide' => [ '' ];
		yield 'majuscule' => [ 'Sig' ];
		yield '13 caractères' => [ 'abcdefghijklm' ];
		yield 'tiret' => [ 'sig-x' ];
		yield 'souligné' => [ 'sig_x' ];
		yield 'saut de ligne final' => [ "sig\n" ];
	}

	#[DataProvider( 'malformed_purposes' )]
	public function test_but_mal_forme_refuse( string $purpose ): void {
		// Refusé avant tout accès à la base, même sans $wpdb : erreur de programmation.
		$this->expectException( \InvalidArgumentException::class );
		MysqlLock::name( $purpose );
	}

	#[DataProvider( 'malformed_purposes' )]
	public function test_but_mal_forme_refuse_aussi_par_acquire_et_run( string $purpose ): void {
		$wpdb = $this->install_wpdb( '1' );

		foreach ( [ 'acquire', 'run' ] as $method ) {
			try {
				if ( 'acquire' === $method ) {
					MysqlLock::acquire( $purpose, 3 );
				} else {
					MysqlLock::run( $purpose, 3, static fn () => null, static fn () => null, static fn () => null );
				}
				self::fail( $method . '() doit refuser un but mal formé' );
			} catch ( \InvalidArgumentException ) {
				// Attendu.
			}
		}

		self::assertSame( [], $wpdb->events, 'aucune requête pour un but refusé' );
	}

	public function test_portee_inconnue_refusee(): void {
		$this->install_wpdb( '1' );

		$this->expectException( \InvalidArgumentException::class );
		MysqlLock::name( 'env', 'site' );
	}

	// ── Délai et requêtes ───────────────────────────────────────────────────────

	public function test_delai_borne_a_10_secondes_et_jamais_negatif(): void {
		$wpdb = $this->install_wpdb( '0' );

		MysqlLock::acquire( 'env', 60 );
		MysqlLock::acquire( 'env', -5 );
		MysqlLock::acquire( 'env', 0 );
		MysqlLock::acquire( 'env', 3 );

		self::assertSame( 10, MysqlLock::MAX_TIMEOUT_SECONDS );
		self::assertSame( [ 10, 0, 0, 3 ], array_column( $wpdb->lock_calls, 'timeout' ) );
	}

	/** Une erreur SQL affichée casserait la réponse JSON d'un site en WP_DEBUG_DISPLAY. */
	public function test_les_requetes_de_verrou_n_affichent_aucune_erreur(): void {
		$wpdb = $this->install_wpdb( '1' );
		MysqlLock::run( 'env', 3, static fn () => null, static fn () => null, static fn () => null );

		self::assertSame( [ true, true ], $wpdb->suppressed_during_queries, 'erreurs masquées pendant GET_LOCK et RELEASE_LOCK' );
		self::assertSame( [ true, false, true, false ], $wpdb->suppress_calls, 'état d’origine rétabli après chaque requête' );

		// Site qui masquait déjà ses erreurs : il les masque toujours après.
		$wpdb           = $this->install_wpdb( null );
		$wpdb->suppress = true;
		MysqlLock::acquire( 'env', 3 );
		self::assertSame( [ true, true ], $wpdb->suppress_calls );
		self::assertTrue( $wpdb->suppress );
	}

	/**
	 * Lance run() en exigeant que seul le repli « indisponible » soit appelé.
	 */
	private function run_expecting_unavailable(): mixed {
		return MysqlLock::run(
			'env',
			3,
			static fn (): string => self::fail( 'opération lancée comme si le verrou était pris' ),
			static fn (): string => self::fail( 'un verrou indisponible n’est pas un verrou tenu ailleurs' ),
			static fn (): string => 'sans verrou'
		);
	}

	private function install_wpdb( string|null|\Throwable $get_lock_result ): FakeLockWpdb {
		$wpdb            = new FakeLockWpdb( $get_lock_result );
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}
}
