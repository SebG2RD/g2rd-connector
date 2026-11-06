<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use Brain\Monkey\Functions;
use G2RD\Connector\PhpErrors\Buffer;
use G2RD\Connector\Tests\Support\FakeLockWpdb;
use G2RD\Connector\Tests\TestCase;

/**
 * Tampon des erreurs PHP (option non autochargée) : une écriture par empreinte et par minute au
 * plus, 50 groupes, 32 Ko, 7 jours, purge, données illisibles traitées comme vides, verrou tenu
 * ailleurs.
 *
 * Comme WordPress, update_option() n'écrit rien et rend faux pour une valeur identique. Les
 * tests des écritures concurrentes simulent en plus le cache d'options de la requête
 * (simulate_options_cache()) : $this->options est « la base », qu'un autre processus écrit
 * sans passer par ce cache.
 */
final class BufferTest extends TestCase {

	/** 01/11/2026 00:00:00 UTC. */
	private const T = 1793491200;

	/** @var list<array{string, mixed, mixed}> Écritures : nom, valeur, autochargement. */
	private array $writes = [];

	/** @var list<string> */
	private array $deleted = [];

	/** @var array<string, mixed> Cache d'options de la requête (groupe `options`), `notoptions` compris. */
	private array $cache = [];

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wpdb'] );
		$this->writes  = [];
		$this->deleted = [];
		$this->cache   = [];
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload = null ): bool {
				// Comme WordPress : une valeur identique n'est pas écrite, et update_option() rend faux.
				if ( array_key_exists( $key, $this->options ) && $value === $this->options[ $key ] ) {
					return false;
				}
				$this->writes[]        = [ $key, $value, $autoload ];
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				$this->deleted[] = $key;
				unset( $this->options[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_une_premiere_erreur_cree_un_groupe_non_autocharge(): void {
		self::assertTrue( Buffer::record( self::entry( 'a' ), self::T ) );

		self::assertCount( 1, $this->writes );
		self::assertSame( Buffer::OPTION, $this->writes[0][0] );
		self::assertFalse( $this->writes[0][2], 'Option non autochargée.' );
		$group = Buffer::read( self::T )['groups'][ hash( 'sha256', 'a' ) ];
		self::assertSame( 1, $group['count'] );
		self::assertSame( self::T, $group['first_seen'] );
		self::assertSame( '2.4.1', $group['version'] );
	}

	public function test_la_meme_erreur_dans_la_minute_n_ecrit_rien(): void {
		Buffer::record( self::entry( 'a' ), self::T );

		self::assertFalse( Buffer::record( self::entry( 'a' ), self::T + 59 ) );

		self::assertCount( 1, $this->writes );
		self::assertSame( 1, Buffer::read( self::T + 59 )['groups'][ hash( 'sha256', 'a' ) ]['count'] );
	}

	public function test_la_meme_erreur_une_minute_plus_tard_compte_deux(): void {
		Buffer::record( self::entry( 'a', 'Premier' ), self::T );

		self::assertTrue( Buffer::record( self::entry( 'a', 'Second' ), self::T + 60 ) );

		$group = Buffer::read( self::T + 60 )['groups'][ hash( 'sha256', 'a' ) ];
		self::assertSame( 2, $group['count'] );
		self::assertSame( self::T, $group['first_seen'] );
		self::assertSame( self::T + 60, $group['last_seen'] );
		self::assertSame( 'Second', $group['message'], 'Le message le plus récent.' );
	}

	public function test_cinquante_groupes_au_plus_le_plus_ancien_part(): void {
		$entries = [];
		for ( $i = 0; $i < 51; $i++ ) {
			$entries[] = self::entry( 'g' . $i ) + [
				'first_seen' => self::T + $i,
				'last_seen'  => self::T + $i,
				'count'      => 1,
			];
		}

		Buffer::record_many( $entries, self::T + 100, false );

		$read = Buffer::read( self::T + 100 );
		self::assertCount( Buffer::MAX_GROUPS, $read['groups'] );
		self::assertArrayNotHasKey( hash( 'sha256', 'g0' ), $read['groups'], 'Le plus ancien est écarté.' );
		self::assertTrue( $read['truncated'] );
	}

	public function test_32_ko_au_plus(): void {
		$entries = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$entries[] = self::entry( 'g' . $i, str_repeat( 'é', 500 ) ) + [
				'first_seen' => self::T + $i,
				'last_seen'  => self::T + $i,
				'count'      => 1,
			];
		}

		Buffer::record_many( $entries, self::T + 100, false );

		self::assertLessThanOrEqual( Buffer::MAX_BYTES, strlen( serialize( $this->options[ Buffer::OPTION ] ) ) );
		self::assertLessThan( 50, count( Buffer::read( self::T + 100 )['groups'] ) );
		self::assertTrue( Buffer::read( self::T + 100 )['truncated'] );
	}

	public function test_un_groupe_de_plus_de_sept_jours_n_est_plus_lu(): void {
		Buffer::record( self::entry( 'a' ), self::T );

		self::assertCount( 1, Buffer::read( self::T + Buffer::RETENTION_SECONDS )['groups'] );
		self::assertSame( [], Buffer::read( self::T + Buffer::RETENTION_SECONDS + 1 )['groups'] );
	}

	public function test_une_option_illisible_vaut_un_tampon_vide(): void {
		$this->options[ Buffer::OPTION ] = 'pas un tableau';

		self::assertSame(
			[
				'groups'    => [],
				'truncated' => false,
			],
			Buffer::read( self::T )
		);
		self::assertTrue( Buffer::record( self::entry( 'a' ), self::T ) );
	}

	public function test_un_groupe_mal_forme_est_ignore(): void {
		Buffer::record( self::entry( 'a' ), self::T );
		$this->options[ Buffer::OPTION ]['groups']['pas-une-empreinte'] = [ 'level' => 'fatal' ];
		$this->options[ Buffer::OPTION ]['groups'][ hash( 'sha256', 'b' ) ] = [ 'level' => 'critique' ];

		self::assertSame( [ hash( 'sha256', 'a' ) ], array_keys( Buffer::read( self::T )['groups'] ) );
	}

	public function test_verrou_tenu_ailleurs_rien_n_est_ecrit(): void {
		$GLOBALS['wpdb'] = new FakeLockWpdb( '0' );

		self::assertFalse( Buffer::record( self::entry( 'a' ), self::T ) );

		self::assertSame( [], $this->writes );
	}

	public function test_ecriture_groupee_du_journal(): void {
		$entry = self::entry( 'w' ) + [
			'first_seen' => self::T - 600,
			'last_seen'  => self::T - 10,
			'count'      => 4310,
		];

		self::assertTrue( Buffer::record_many( [ $entry ], self::T, true ) );

		self::assertCount( 1, $this->writes );
		$read = Buffer::read( self::T );
		self::assertSame( 4310, $read['groups'][ hash( 'sha256', 'w' ) ]['count'] );
		self::assertTrue( $read['truncated'], 'Journal lu en partie.' );
	}

	/**
	 * Même erreur fatale vue par le relevé puis par le journal : un seul groupe, compte du relevé
	 * (jamais 1 + 3). Une ligne d'avertissement du journal, elle, compte toujours.
	 */
	public function test_une_erreur_deja_relevee_n_est_pas_recomptee_par_le_journal(): void {
		Buffer::record( self::entry( 'f' ), self::T );
		$fatal   = [ 'source' => 'log_tail' ] + self::entry( 'f' ) + [
			'first_seen' => self::T - 30,
			'last_seen'  => self::T + 5,
			'count'      => 3,
		];
		$warning = [
			'source' => 'log_tail',
			'level'  => 'warning',
			'fp'     => hash( 'sha256', 'w2' ),
		] + self::entry( 'w2' ) + [
			'first_seen' => self::T - 30,
			'last_seen'  => self::T,
			'count'      => 2,
		];

		self::assertTrue( Buffer::record_many( [ $fatal, $warning ], self::T + 600, false ) );

		$groups = Buffer::read( self::T + 600 )['groups'];
		self::assertCount( 2, $groups );
		self::assertSame( 1, $groups[ hash( 'sha256', 'f' ) ]['count'], 'Compte du relevé, borne basse vraie.' );
		self::assertSame( 'fatal_catcher', $groups[ hash( 'sha256', 'f' ) ]['source'] );
		self::assertSame( 2, $groups[ hash( 'sha256', 'w2' ) ]['count'] );
	}

	/** Groupe venu du journal d'abord, puis vu par le relevé : ensuite, le journal ne le recompte plus. */
	public function test_apres_le_releve_le_journal_ne_recompte_plus_le_groupe(): void {
		$log = [ 'source' => 'log_tail' ] + self::entry( 'g' ) + [
			'first_seen' => self::T - 60,
			'last_seen'  => self::T - 60,
			'count'      => 2,
		];
		Buffer::record_many( [ $log ], self::T, false );
		Buffer::record( self::entry( 'g' ), self::T + 120 );

		Buffer::record_many( [ [ 'last_seen' => self::T + 120 ] + $log ], self::T + 600, false );

		$group = Buffer::read( self::T + 600 )['groups'][ hash( 'sha256', 'g' ) ];
		self::assertSame( 3, $group['count'], '2 lignes lues avant le relevé + 1 relevé ; rien de plus.' );
		self::assertSame( 'fatal_catcher', $group['source'] );
	}

	/**
	 * Erreur fatale répétée à chaque requête : le relevé à l'arrêt l'a enregistrée, le journal en
	 * garde aussi la ligne. La lecture du journal n'a rien à ajouter : rien n'est écrit (WordPress
	 * rendrait faux pour une valeur identique), et record_many() rend vrai, pour que la lecture du
	 * journal avance son curseur au lieu de relire les mêmes lignes à chaque passage.
	 */
	public function test_une_ligne_deja_relevee_rend_vrai_sans_ecrire(): void {
		Buffer::record( self::entry( 'f' ), self::T );
		$fatal = [ 'source' => 'log_tail' ] + self::entry( 'f' ) + [
			'first_seen' => self::T,
			'last_seen'  => self::T,
			'count'      => 1,
		];

		self::assertTrue( Buffer::record_many( [ $fatal ], self::T + 600, false ) );

		self::assertCount( 1, $this->writes, 'Seule l\'écriture du relevé : le tampon est inchangé.' );
		self::assertFalse( Buffer::read( self::T + 600 )['truncated'] );
	}

	/** Une écriture que la base refuse rend faux : la lecture du journal relira ces lignes. */
	public function test_une_ecriture_refusee_rend_faux(): void {
		Functions\when( 'update_option' )->justReturn( false );
		$entry = self::entry( 'w' ) + [
			'first_seen' => self::T,
			'last_seen'  => self::T,
			'count'      => 1,
		];

		self::assertFalse( Buffer::record_many( [ $entry ], self::T, false ) );
	}

	public function test_la_lecture_va_du_plus_recent_au_plus_ancien(): void {
		Buffer::record( self::entry( 'ancien' ), self::T );
		Buffer::record( self::entry( 'recent' ), self::T + 120 );

		self::assertSame( [ hash( 'sha256', 'recent' ), hash( 'sha256', 'ancien' ) ], array_keys( Buffer::read( self::T + 120 )['groups'] ) );
	}

	public function test_la_purge_retire_les_groupes_expires_et_l_option_vide(): void {
		Buffer::record( self::entry( 'a' ), self::T );

		Buffer::purge( self::T + Buffer::RETENTION_SECONDS + 1 );

		self::assertSame( [ Buffer::OPTION ], $this->deleted );
		self::assertArrayNotHasKey( Buffer::OPTION, $this->options );
	}

	public function test_la_purge_sans_tampon_ne_fait_rien(): void {
		Buffer::purge( self::T );

		self::assertSame( [], $this->writes );
		self::assertSame( [], $this->deleted );
	}

	/** `do_action( HOOK )` passe '' en premier argument : le rappel n'en prend aucun. */
	public function test_le_rappel_de_purge_ne_prend_aucun_argument(): void {
		self::assertSame( 0, ( new \ReflectionMethod( Buffer::class, 'purge_now' ) )->getNumberOfParameters() );
	}

	/**
	 * Tâche biquotidienne : la purge passe avant la lecture du journal, dans la même requête, et
	 * trouve le tampon absent (WordPress le note dans `notoptions`). Un autre processus relève une
	 * erreur fatale pendant la lecture du journal. record_many() relit la base sous le verrou, pas
	 * cette absence notée : le relevé concurrent est gardé, pas écrasé.
	 */
	public function test_un_releve_ecrit_apres_une_purge_sans_tampon_est_garde(): void {
		$this->simulate_options_cache();
		$this->cache['notoptions']['g2rd_autre_option'] = true;
		Buffer::purge( self::T );
		$this->in_other_process( static fn (): bool => Buffer::record( self::entry( 'f' ), self::T + 10 ) );
		$warning = [
			'source' => 'log_tail',
			'level'  => 'warning',
		] + self::entry( 'w' ) + [
			'first_seen' => self::T + 5,
			'last_seen'  => self::T + 15,
			'count'      => 2,
		];

		self::assertTrue( Buffer::record_many( [ $warning ], self::T + 20, false ) );

		$groups = $this->options[ Buffer::OPTION ]['groups'];
		self::assertSame( [ hash( 'sha256', 'w' ), hash( 'sha256', 'f' ) ], array_keys( $groups ), 'Le relevé concurrent est gardé.' );
		self::assertSame( 'fatal_catcher', $groups[ hash( 'sha256', 'f' ) ]['source'] );
		self::assertTrue( $this->cache['notoptions']['g2rd_autre_option'] ?? false, 'Les autres options notées absentes le restent.' );
	}

	/**
	 * Un snapshot lit le tampon absent ; un autre processus relève une erreur ; puis une erreur
	 * fatale survient à l'arrêt de la même requête que le snapshot : les deux groupes restent.
	 */
	public function test_un_releve_ecrit_apres_une_lecture_sans_tampon_est_garde(): void {
		$this->simulate_options_cache();
		self::assertSame( [], Buffer::read( self::T )['groups'] );
		$this->in_other_process( static fn (): bool => Buffer::record( self::entry( 'autre' ), self::T + 10 ) );

		self::assertTrue( Buffer::record( self::entry( 'f' ), self::T + 20 ) );

		self::assertSame( [ hash( 'sha256', 'f' ), hash( 'sha256', 'autre' ) ], array_keys( $this->options[ Buffer::OPTION ]['groups'] ) );
	}

	/** La purge relit la base elle aussi : un tampon écrit après une lecture sans tampon est purgé. */
	public function test_la_purge_voit_un_tampon_ecrit_apres_une_lecture_sans_tampon(): void {
		$this->simulate_options_cache();
		Buffer::read( self::T );
		$this->in_other_process( static fn (): bool => Buffer::record( self::entry( 'a' ), self::T ) );

		Buffer::purge( self::T + Buffer::RETENTION_SECONDS + 1 );

		self::assertSame( [ Buffer::OPTION ], $this->deleted );
		self::assertArrayNotHasKey( Buffer::OPTION, $this->options );
	}

	/**
	 * Cache d'options de la requête, tenu comme WordPress pour une option non autochargée : une
	 * option lue est gardée sous son nom ; une option lue absente est notée dans `notoptions`, et
	 * get_option() la rend absente sans relire la base tant qu'elle y figure. update_option()
	 * compare à ce que get_option() rend : identique, rien n'est écrit ; absente pour le cache,
	 * add_option() écrit sans regarder la base (INSERT … ON DUPLICATE KEY UPDATE) et écrase la
	 * ligne. delete_option() note l'option absente ; sans ligne en base, il rend faux.
	 */
	private function simulate_options_cache(): void {
		Functions\when( 'get_option' )->alias(
			fn ( string $key, $fallback = false ) => $this->cached_option( $key, $fallback )
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload = null ): bool {
				if ( $value === $this->cached_option( $key, false ) ) {
					return false;
				}
				$this->writes[]        = [ $key, $value, $autoload ];
				$this->options[ $key ] = $value;
				$this->cache[ $key ]   = $value;
				unset( $this->cache['notoptions'][ $key ] );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				if ( ! array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->deleted[] = $key;
				unset( $this->options[ $key ], $this->cache[ $key ] );
				$this->cache['notoptions'][ $key ] = true;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key, string $group = '' ): bool {
				if ( 'options' !== $group || ! array_key_exists( $key, $this->cache ) ) {
					return false;
				}
				unset( $this->cache[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			fn ( string $key, string $group = '' ) => 'options' === $group && array_key_exists( $key, $this->cache ) ? $this->cache[ $key ] : false
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( string $key, $value, string $group = '' ): bool {
				if ( 'options' === $group ) {
					$this->cache[ $key ] = $value;
				}
				return true;
			}
		);
	}

	/** get_option() de WordPress, vu du cache de la requête (cf. simulate_options_cache()). */
	private function cached_option( string $key, mixed $fallback ): mixed {
		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}
		if ( isset( $this->cache['notoptions'][ $key ] ) ) {
			return $fallback;
		}
		if ( ! array_key_exists( $key, $this->options ) ) {
			$this->cache['notoptions'][ $key ] = true;
			return $fallback;
		}
		$this->cache[ $key ] = $this->options[ $key ];

		return $this->cache[ $key ];
	}

	/** Un autre processus : son propre cache d'options, vide au départ, et la même base. */
	private function in_other_process( callable $operation ): void {
		$mine        = $this->cache;
		$this->cache = [];
		try {
			$operation();
		} finally {
			$this->cache = $mine;
		}
	}

	/**
	 * @return array{fp: string, level: string, type: string, slug: string|null, version: string|null, file: string, line: int, message: string, source: string}
	 */
	private static function entry( string $seed, string $message = 'Call to undefined function x()' ): array {
		return [
			'fp'      => hash( 'sha256', $seed ),
			'level'   => 'fatal',
			'type'    => 'plugin',
			'slug'    => 'exemple',
			'version' => '2.4.1',
			'file'    => 'wp-content/plugins/exemple/x.php',
			'line'    => 87,
			'message' => $message,
			'source'  => 'fatal_catcher',
		];
	}
}
