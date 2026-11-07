<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\DatabaseDumper;
use G2RD\Connector\Tests\TestCase;

/**
 * Seconde moitié du dump (docs/backup-payload.md § 4) : avance reprenable par lots ordonnés par clé
 * primaire (repli LIMIT/OFFSET) et bornés en octets ; curseur gardé dans l'état JSON (jeu de
 * l'en-tête, clé en hexadécimal), refusé s'il est illisible ; valeurs de reprise en hexadécimal ;
 * reconnexion contrôlée avant d'écrire un lot ; lignes du module exclues.
 */
final class DatabaseDumperAdvanceTest extends TestCase {

	private FakeDumpWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		// wp_json_encode() comme WordPress : l'état du module passe par lui entre deux tranches.
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value, int $flags = 0, int $depth = 512 ) => self::wp_json_encode( $value, $flags, $depth ) );
		$this->wpdb = self::faux();
	}

	/**
	 * Faux $wpdb du site : options (dont une ligne du module), postmeta à clé composée de 450
	 * lignes, et une table sans clé primaire avec une colonne binaire.
	 */
	private static function faux(): FakeDumpWpdb {
		$wpdb                       = new FakeDumpWpdb();
		$wpdb->tables['wp_options'] = [
			'columns' => [
				'option_id'    => 'bigint(20) unsigned',
				'option_name'  => 'varchar(191)',
				'option_value' => 'longtext',
			],
			'pk'      => [ 'option_id' ],
			'rows'    => [
				[
					'option_id'    => '2',
					'option_name'  => 'g2rd_backup_state',
					'option_value' => '{"phase":"db"}',
				],
				[
					'option_id'    => '10',
					'option_name'  => 'blogname',
					'option_value' => "L'atelier",
				],
				[
					'option_id'    => '1',
					'option_name'  => 'siteurl',
					'option_value' => 'https://exemple.test',
				],
			],
		];
		$lignes = [];
		for ( $i = 1; $i <= 450; $i++ ) {
			$lignes[] = [
				'post_id'    => (string) $i,
				'meta_key'   => 'k' . $i,
				'meta_value' => null,
			];
		}
		$wpdb->tables['wp_postmeta'] = [
			'columns' => [
				'post_id'    => 'bigint(20)',
				'meta_key'   => 'varchar(255)',
				'meta_value' => 'longtext',
			],
			'pk'      => [ 'post_id', 'meta_key' ],
			'rows'    => $lignes,
		];
		$wpdb->tables['wp_sans_cle'] = [
			'columns' => [
				'a' => 'int(11)',
				'b' => 'blob',
			],
			'pk'      => [],
			'rows'    => [
				[
					'a' => '1',
					'b' => "\x00\xff",
				],
				[
					'a' => '2',
					'b' => '',
				],
			],
		];

		return $wpdb;
	}

	/**
	 * Équivalent de wp_json_encode() (WordPress, mbstring présent) : json_encode(), puis, s'il échoue,
	 * chaque chaîne qui n'est pas de l'UTF-8 valide réécrite par mb_convert_encoding( $s, 'UTF-8',
	 * 'UTF-8' ), ce que fait _wp_json_convert_string(), et json_encode() de nouveau.
	 *
	 * @param mixed $value
	 * @return string|false
	 */
	private static function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		$json = json_encode( $value, $flags, $depth );
		if ( false !== $json ) {
			return $json;
		}

		return json_encode( self::sanity( $value ), $flags, $depth );
	}

	private static function sanity( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return mb_check_encoding( $value, 'UTF-8' ) ? $value : mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$clean = [];
		foreach ( $value as $key => $item ) {
			$clean[ is_string( $key ) ? (string) self::sanity( $key ) : $key ] = self::sanity( $item );
		}

		return $clean;
	}

	private function dumper(): DatabaseDumper {
		return new DatabaseDumper( $this->wpdb, [ 'wp_options' => [ 'g2rd_backup_state' => true ] ] );
	}

	/**
	 * Curseur rendu par une avance, relu comme le relit la tranche suivante (l'état passe par
	 * wp_json_encode() puis json_decode()).
	 *
	 * @param array<string, mixed>|null $cursor
	 * @return array<string, mixed>|null
	 */
	private static function par_l_etat( ?array $cursor ): ?array {
		if ( null === $cursor ) {
			return null;
		}
		$relu = json_decode( (string) wp_json_encode( $cursor ), true );
		self::assertIsArray( $relu );

		return $relu;
	}

	/**
	 * Dump de $liste, une avance après l'autre ; entre deux avances, le curseur passe par l'état
	 * comme en production, puis par $reecrit s'il est donné.
	 *
	 * @param list<string> $liste
	 * @return array{0: string, 1: list<array{name: string, rows: int, create_sha256: string}>, 2: int, 3: list<array<string, mixed>>}
	 */
	private function dump( array $liste, \Closure $stop, ?\Closure $reecrit = null ): array {
		$sql      = '';
		$tables   = [];
		$appels   = 0;
		$curseurs = [];
		$curseur  = null;
		do {
			++$appels;
			$etape   = $this->dumper()->advance(
				$liste,
				$curseur,
				static function ( string $morceau ) use ( &$sql ): void {
					$sql .= $morceau;
				},
				$stop
			);
			$tables  = array_merge( $tables, $etape['tables_done'] );
			$curseur = self::par_l_etat( $etape['cursor'] );
			if ( null !== $curseur ) {
				$curseurs[] = $curseur;
				if ( null !== $reecrit ) {
					$curseur = $reecrit( $curseur );
				}
			}
		} while ( null !== $curseur && $appels < 100 );

		return [ $sql, $tables, $appels, $curseurs ];
	}

	/**
	 * @return array{0: string, 1: list<array{name: string, rows: int, create_sha256: string}>, 2: int}
	 */
	private function tout( \Closure $stop ): array {
		[ $sql, $tables, $appels ] = $this->dump( [ 'wp_options', 'wp_postmeta', 'wp_sans_cle' ], $stop );

		return [ $sql, $tables, $appels ];
	}

	private static function refus( \Closure $action ): BackupRefused {
		try {
			$action();
		} catch ( BackupRefused $refus ) {
			return $refus;
		}
		self::fail( 'Refus attendu.' );
	}

	/**
	 * @param list<string> $requetes
	 */
	private static function compte( array $requetes, string $texte ): int {
		return count( array_filter( $requetes, static fn ( string $q ): bool => $texte === $q ) );
	}

	public function test_dump_complet_en_une_fois(): void {
		[ $sql, $tables, $appels ] = $this->tout( static fn (): bool => false );

		self::assertSame( 1, $appels );
		self::assertStringStartsWith( DatabaseDumper::header( 'utf8mb4' ), $sql );
		self::assertStringEndsWith( DatabaseDumper::FOOTER, $sql );
		self::assertStringContainsString( "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options`", $sql );
		self::assertStringNotContainsString( 'g2rd_backup_state', $sql, 'ligne du module exclue' );
		self::assertStringContainsString( "('1','siteurl','https://exemple.test'),\n('10','blogname','L\\'atelier');", $sql, 'ordre de la clé primaire, valeurs échappées' );
		self::assertStringContainsString( '(1,0x00ff)', str_replace( "'1'", '1', $sql ), 'binaire en hexadécimal' );
		self::assertSame( [ 'wp_options', 'wp_postmeta', 'wp_sans_cle' ], array_column( $tables, 'name' ) );
		self::assertSame( [ 2, 450, 2 ], array_column( $tables, 'rows' ) );
		self::assertSame( 64, strlen( $tables[0]['create_sha256'] ) );
	}

	public function test_colonnes_binaires_lues_au_debut_du_type(): void {
		$this->wpdb->tables['wp_types'] = [
			'columns' => [
				'id' => 'bigint(20)',
				'e'  => "enum('bit','blob')",
				'b'  => 'bit(1)',
				'vb' => 'varbinary(8)',
				't'  => 'tinyblob',
				'lb' => 'LONGBLOB',
				'bn' => 'binary(2)',
			],
			'pk'      => [ 'id' ],
			'rows'    => [
				[
					'id' => '1',
					'e'  => 'bit',
					'b'  => "\x01",
					'vb' => "\x00a",
					't'  => "\xff",
					'lb' => 'z',
					'bn' => "\x10\x20",
				],
			],
		];

		[ $sql ] = $this->dump( [ 'wp_types' ], static fn (): bool => false );

		self::assertStringContainsString( "('1','bit',0x01,0x0061,0xff,0x7a,0x1020);", $sql, 'blob, binary, varbinary, bit : en hexadécimal ; une énumération qui cite « bit » ou « blob », non' );
	}

	public function test_reprise_par_lots_donne_exactement_le_meme_dump(): void {
		[ $entier ]            = $this->tout( static fn (): bool => false );
		[ $repris, , $appels ] = $this->tout( static fn (): bool => true );

		self::assertSame( $entier, $repris );
		self::assertGreaterThan( 3, $appels, 'une avance par lot au moins' );
	}

	public function test_en_tete_dans_le_jeu_de_la_connexion(): void {
		$this->wpdb->charset = 'latin1';
		$this->wpdb->session = [ 'latin1', 'latin1', 'latin1' ];

		[ $sql ] = $this->tout( static fn (): bool => false );

		self::assertStringStartsWith( "-- G2RD backup dump v1\n/*!40101 SET NAMES latin1 */;\n", $sql );
	}

	public function test_le_jeu_de_l_en_tete_reste_celui_du_curseur(): void {
		$liste   = [ 'wp_options', 'wp_postmeta', 'wp_sans_cle' ];
		$premier = $this->dumper()->advance(
			$liste,
			null,
			static function ( string $morceau ): void {
				unset( $morceau );
			},
			static fn (): bool => true
		);
		$curseur = self::par_l_etat( $premier['cursor'] );
		self::assertIsArray( $curseur );
		self::assertSame( 'utf8mb4', $curseur['charset'] );

		// DB_CHARSET changé entre deux tranches : la suite du dump reste lue dans le jeu de l'en-tête.
		$this->wpdb->charset = 'latin1';
		$this->wpdb->session = [ 'latin1', 'latin1', 'latin1' ];
		$emis                = '';
		// Fermeture créée hors de la fonction fléchée, qui capturerait $emis par valeur.
		$emit  = static function ( string $morceau ) use ( &$emis ): void {
			$emis .= $morceau;
		};
		$refus = self::refus( fn () => $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => false ) );

		self::assertSame( BackupRefused::DB_CHARSET_MISMATCH, $refus->reason_code() );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertStringContainsString( 'utf8mb4', $refus->getMessage() );
		self::assertStringContainsString( 'latin1', $refus->getMessage() );
		self::assertSame( '', $emis, 'rien émis par la tranche refusée' );
	}

	public function test_grosses_lignes_lots_bornes_par_la_taille_moyenne(): void {
		$lignes = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$lignes[] = [
				'id' => (string) $i,
				'v'  => str_repeat( chr( 96 + $i ), 2 * 1048576 - 1024 ),
			];
		}
		$this->wpdb->tables['wp_grosse'] = [
			'columns' => [
				'id' => 'bigint(20)',
				'v'  => 'longtext',
			],
			'pk'      => [ 'id' ],
			'rows'    => $lignes,
		];
		unset( $lignes );

		$limites = $this->limites_lues( 'wp_grosse', $sql );

		self::assertSame( 4, $limites[0], 'huit Mio de lignes de deux Mio' );
		self::assertLessThanOrEqual( 4, max( $limites ) );
		// Comptes plutôt qu'assertStringContainsString() : PHPUnit 10.5 exporte chaque valeur vérifiée,
		// même en cas de succès, et le dump fait 24 Mio.
		for ( $i = 1; $i <= 12; $i++ ) {
			self::assertSame( 1, substr_count( (string) $sql, str_repeat( chr( 96 + $i ), 2 * 1048576 - 1024 ) ), 'ligne ' . $i . ' copiée une fois' );
		}
	}

	public function test_lots_reduits_de_moitie_quand_la_moyenne_trompe(): void {
		$lignes = [];
		$valeur = str_repeat( 'x', 102400 );
		for ( $i = 1; $i <= 300; $i++ ) {
			// Une seule chaîne partagée par les 300 lignes du faux : 100 Kio en mémoire au lieu de 30 Mio.
			$lignes[] = [
				'id' => (string) $i,
				'v'  => $valeur,
			];
		}
		$this->wpdb->tables['wp_grosse'] = [
			'columns'        => [
				'id' => 'bigint(20)',
				'v'  => 'longtext',
			],
			'pk'             => [ 'id' ],
			'rows'           => $lignes,
			'avg_row_length' => 100,
		];
		unset( $lignes );

		$limites = $this->limites_lues( 'wp_grosse', $sql );

		self::assertSame( [ 200, 100, 50 ], $limites, '20 Mio puis 10 Mio : au-delà de 8 Mio, lot suivant réduit de moitié' );
		self::assertSame( 300, substr_count( (string) $sql, $valeur ) );
	}

	/**
	 * Dump d'une seule table, une avance par lot ; rend les LIMIT lus, dans l'ordre.
	 *
	 * @return list<int>
	 */
	private function limites_lues( string $table, ?string &$sql ): array {
		$sql     = '';
		$curseur = null;
		$appels  = 0;
		do {
			$etape   = $this->dumper()->advance(
				[ $table ],
				$curseur,
				static function ( string $morceau ) use ( &$sql ): void {
					$sql .= $morceau;
				},
				static fn (): bool => true
			);
			$curseur = self::par_l_etat( $etape['cursor'] );
		} while ( null !== $curseur && ++$appels < 1000 );
		preg_match_all( '/^SELECT `.+` FROM `' . preg_quote( $table, '/' ) . '` .*LIMIT (\d+)$/m', implode( "\n", $this->wpdb->queries ), $lues );

		return array_map( 'intval', $lues[1] );
	}

	public function test_une_table_illisible_arrete_avec_son_nom(): void {
		$this->wpdb->fails_on = 'wp_postmeta';

		try {
			$this->tout( static fn (): bool => false );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
			self::assertStringContainsString( '« wp_postmeta »', $refus->getMessage() );
		}
	}

	public function test_cp932_bout_en_bout(): void {
		$this->wpdb->charset          = 'cp932';
		$this->wpdb->session          = [ 'cp932', 'cp932', 'cp932' ];
		$table                        = "wp_\x83\x60";
		$colonne                      = "\x83\x60\x60";
		$valeur                       = "\x95\x5c'),(9,0x41);-- ";
		$this->wpdb->tables[ $table ] = [
			'columns' => [
				'id'     => 'bigint(20)',
				$colonne => 'varchar(191)',
			],
			'pk'      => [ 'id' ],
			'rows'    => [
				[
					'id'     => '2',
					$colonne => 'b',
				],
				[
					'id'     => '1',
					$colonne => $valeur,
				],
				[
					'id'     => '3',
					$colonne => '',
				],
			],
		];
		$liste = [ 'wp_options', $table ];

		[ $entier ]            = $this->dump( $liste, static fn (): bool => false );
		[ $repris, , $appels ] = $this->dump( $liste, static fn (): bool => true );
		$this->wpdb->queries   = [];
		[ $ligne_a_ligne ]     = $this->dump( $liste, static fn (): bool => true, static fn ( array $c ): array => array_replace( $c, [ 'limit' => 1 ] ) );

		self::assertStringStartsWith( DatabaseDumper::header( 'cp932' ), $entier );
		self::assertStringContainsString( "DROP TABLE IF EXISTS \x60\x77\x70\x5f\x83\x60\x60;\n", $entier, 'nom non doublé : 83 60 est un caractère (チ)' );
		self::assertStringContainsString( "INSERT INTO \x60wp_\x83\x60\x60 (\x60id\x60,\x60\x83\x60\x60\x60\x60) VALUES\n", $entier, 'seul l\'accent grave qui est un caractère à lui seul est doublé' );
		self::assertStringContainsString( '_cp932 0x' . bin2hex( $valeur ), $entier, 'valeur en hexadécimal avec introducteur' );
		self::assertStringNotContainsString( $valeur, $entier, 'jamais entre apostrophes en cp932' );
		self::assertSame( $entier, $repris, 'reprise par lots identique au dump d\'une traite' );
		self::assertGreaterThan( 2, $appels );
		self::assertSame( 1, substr_count( $ligne_a_ligne, '_cp932 0x' . bin2hex( $valeur ) ), 'reprise ligne à ligne : chaque ligne une fois' );
		$reprises = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_contains( $q, ' WHERE (' ) && str_contains( $q, $table ) ) );
		self::assertCount( 3, $reprises, 'une reprise après chacune des trois lignes' );
		foreach ( [ '1', '2', '3' ] as $i => $cle ) {
			self::assertStringContainsString( ') > (_cp932 0x' . bin2hex( $cle ) . ') ', $reprises[ $i ] );
			self::assertStringNotContainsString( "'", $reprises[ $i ] );
		}
	}

	public function test_reprise_sans_apostrophe_dans_where(): void {
		$ordre                              = [
			[
				'k' => "') UNION SELECT 1 -- ",
				'v' => '3',
			],
			[
				'k' => 'a',
				'v' => '1',
			],
			[
				'k' => "d'e\\",
				'v' => '2',
			],
			[
				'k' => 'z',
				'v' => '4',
			],
		];
		$this->wpdb->tables['wp_sessions'] = [
			'columns' => [
				'k' => 'varchar(191)',
				'v' => 'longtext',
			],
			'pk'      => [ 'k' ],
			'rows'    => [ $ordre[1], $ordre[2], $ordre[0], $ordre[3] ],
		];
		$liste                              = [ 'wp_sessions' ];
		$debut                              = DatabaseDumper::header( 'utf8mb4' ) . "DROP TABLE IF EXISTS `wp_sessions`;\nCREATE TABLE `wp_sessions` (`k`,`v`);\n";

		[ $entier ]          = $this->dump( $liste, static fn (): bool => false );
		$this->wpdb->queries = [];
		[ $repris ]          = $this->dump( $liste, static fn (): bool => true, static fn ( array $c ): array => array_replace( $c, [ 'limit' => 1 ] ) );

		self::assertSame( $debut . DatabaseDumper::insert( 'wp_sessions', [ 'k', 'v' ], [], $ordre, 'utf8mb4' ) . DatabaseDumper::FOOTER, $entier, 'les quatre lignes, une fois chacune, dans l\'ordre de la clé' );
		$lignes = implode( '', array_map( static fn ( array $l ): string => DatabaseDumper::insert( 'wp_sessions', [ 'k', 'v' ], [], [ $l ], 'utf8mb4' ), $ordre ) );
		self::assertSame( $debut . $lignes . DatabaseDumper::FOOTER, $repris, 'reprise lot par lot : les quatre lignes, une fois chacune' );
		$reprises = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_contains( $q, ' WHERE (' ) ) );
		self::assertCount( 4, $reprises, 'chaque clé sert de curseur' );
		foreach ( $reprises as $i => $requete ) {
			self::assertStringContainsString( '> (_utf8mb4 0x' . bin2hex( $ordre[ $i ]['k'] ) . ')', $requete );
			self::assertStringNotContainsString( "'", $requete, 'aucune apostrophe dans la reprise' );
			self::assertStringNotContainsString( '\\', $requete, 'aucune barre oblique inverse dans la reprise' );
		}
	}

	public function test_cle_binaire_reprise_apres_wp_json_encode(): void {
		$brute = (string) hex2bin( 'fa2d5e67ff88a1b2c3d4e5f60718293a' );
		self::assertSame( '3f2d5e673f3f3f3f3f3f3f3f0718293a', bin2hex( (string) json_decode( (string) wp_json_encode( [ $brute ] ), true )[0] ), 'wp_json_encode() réécrit une clé brute (essai du 07/11)' );
		$cles = array_map(
			static fn ( string $h ): string => (string) hex2bin( str_pad( $h, 32, '0' ) ),
			[ 'ff', '80', '10', 'fa2d5e67ff89a1b2c3d4e5f60718293a', 'c3a9', '7f', 'fa2d5e67ff88a1b2c3d4e5f60718293a', '41a1' ]
		);

		$curseurs = $this->reprise_apres_json( 'wp_ip', 'binary(16)', $cles, static fn ( string $cle ): string => '0x' . bin2hex( $cle ) );

		self::assertContains( [ 'fa2d5e67ff88a1b2c3d4e5f60718293a' ], $curseurs, 'reprise sur la clé que wp_json_encode() réécrirait' );
	}

	public function test_cle_latin1_reprise_apres_wp_json_encode(): void {
		$this->wpdb->charset = 'latin1';
		$this->wpdb->session = [ 'latin1', 'latin1', 'latin1' ];

		$curseurs = $this->reprise_apres_json( 'wp_mots', 'varchar(50)', [ 'd', "caf\xe9s", "\xfcber", 'abc', "\xe9t\xe9", "caf\xe9" ], static fn ( string $cle ): string => "'" . $cle . "'" );

		self::assertContains( [ '636166e9' ], $curseurs, 'reprise sur café en latin1, que wp_json_encode() réécrirait' );
	}

	/**
	 * Table à clé k et colonne v ; lots de deux lignes au départ (taille moyenne annoncée), la tranche
	 * arrêtée après chaque lot, le curseur passant par wp_json_encode() puis json_decode().
	 *
	 * @param list<string>              $cles   Clés, dans le désordre.
	 * @param \Closure(string): string $ecrite Écriture de la clé dans le dump.
	 * @return list<list<string>|null> after_hex de chaque curseur relu.
	 */
	private function reprise_apres_json( string $table, string $type, array $cles, \Closure $ecrite ): array {
		$lignes = [];
		foreach ( $cles as $i => $cle ) {
			$lignes[] = [
				'k' => $cle,
				'v' => 'r' . $i,
			];
		}
		$this->wpdb->tables[ $table ] = [
			'columns'        => [
				'k' => $type,
				'v' => 'varchar(20)',
			],
			'pk'             => [ 'k' ],
			'rows'           => $lignes,
			'avg_row_length' => intdiv( DatabaseDumper::BATCH_BYTES, 2 ),
		];

		[ $entier ]                       = $this->dump( [ $table ], static fn (): bool => false );
		[ $repris, , $appels, $curseurs ] = $this->dump( [ $table ], static fn (): bool => true );

		self::assertSame( $entier, $repris, 'dump repris identique au dump d\'une traite' );
		self::assertGreaterThan( 3, $appels, 'tranche arrêtée au milieu de la table' );
		foreach ( $cles as $cle ) {
			self::assertSame( 1, substr_count( $repris, $ecrite( $cle ) ), 'chaque ligne une seule fois : ' . bin2hex( $cle ) );
		}
		$apres = [];
		foreach ( $curseurs as $curseur ) {
			$apres[] = $curseur['after_hex'];
			foreach ( (array) $curseur['after_hex'] as $hex ) {
				self::assertIsString( $hex );
				self::assertMatchesRegularExpression( '/\A(?:[0-9a-f]{2})+\z/', $hex, 'hexadécimal minuscule' );
			}
		}

		return $apres;
	}

	public function test_curseur_illisible_refuse_sans_rien_emettre(): void {
		$liste  = [ 'wp_options', 'wp_postmeta', 'wp_sans_cle' ];
		$valide = self::par_l_etat(
			$this->dumper()->advance(
				$liste,
				null,
				static function ( string $morceau ): void {
					unset( $morceau );
				},
				static fn (): bool => true
			)['cursor']
		);
		self::assertIsArray( $valide );
		self::assertTrue( $valide['started'] );
		self::assertNull( $valide['after_hex'] );
		$sans = static fn ( string $cle ): array => array_diff_key( $valide, [ $cle => true ] );
		$cas  = [
			'after_hex en majuscules'           => array_replace( $valide, [ 'after_hex' => [ 'FA' ] ] ),
			'after_hex de longueur impaire'     => array_replace( $valide, [ 'after_hex' => [ 'abc' ] ] ),
			'after_hex avec saut de ligne'      => array_replace( $valide, [ 'after_hex' => [ "ab\n" ] ] ),
			'after_hex entier'                  => array_replace( $valide, [ 'after_hex' => [ 12 ] ] ),
			'after_hex vide'                    => array_replace( $valide, [ 'after_hex' => [] ] ),
			'after_hex chaîne'                  => array_replace( $valide, [ 'after_hex' => 'ab' ] ),
			'after_hex associatif'              => array_replace( $valide, [ 'after_hex' => [ 'a' => 'ab' ] ] ),
			'after au lieu de after_hex'        => $sans( 'after_hex' ) + [ 'after' => null ],
			'table négative'                    => array_replace( $valide, [ 'table' => -1 ] ),
			'table hors de la liste'            => array_replace( $valide, [ 'table' => 3 ] ),
			'limit nul'                         => array_replace( $valide, [ 'limit' => 0 ] ),
			'limit au-delà de BATCH_ROWS'       => array_replace( $valide, [ 'limit' => DatabaseDumper::BATCH_ROWS + 1 ] ),
			'header vrai sans charset'          => $sans( 'charset' ),
			'charset hors de son motif'         => array_replace( $valide, [ 'charset' => 'latin1;--' ] ),
			'create_sha256 de 63 caractères'    => array_replace( $valide, [ 'create_sha256' => str_repeat( 'a', 63 ) ] ),
			'table commencée sans create'       => array_replace( $valide, [ 'create_sha256' => null ] ),
			'table commencée avant l\'en-tête'  => array_replace(
				$valide,
				[
					'header'  => false,
					'charset' => '',
				]
			),
			'started absent'                    => $sans( 'started' ),
			'offset sans table commencée'       => array_replace(
				$valide,
				[
					'started'       => false,
					'create_sha256' => null,
					'offset'        => 5,
				]
			),
		];
		$emis = 0;
		// Fermeture créée hors des fonctions fléchées, qui captureraient $emis par valeur.
		$emit = static function ( string $morceau ) use ( &$emis ): void {
			unset( $morceau );
			++$emis;
		};
		foreach ( $cas as $nom => $curseur ) {
			$this->wpdb->queries = [];
			$emis                = 0;
			$refus               = self::refus( fn () => $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => false ) );
			self::assertSame( BackupRefused::DB_CURSOR_INVALID, $refus->reason_code(), $nom );
			self::assertNull( $refus->retry_after(), $nom . ' : non réessayable' );
			self::assertSame( 0, $emis, $nom . ' : rien émis' );
			self::assertSame( [], $this->wpdb->queries, $nom . ' : refusé avant toute requête' );
		}

		// Curseur bien formé, mais dont le nombre de valeurs n'est pas celui de la clé de la table.
		foreach ( [ 1 => 'wp_postmeta (clé de deux colonnes)', 2 => 'wp_sans_cle (sans clé primaire)' ] as $rang => $nom ) {
			$this->wpdb->queries = [];
			$emis                = 0;
			$refus               = self::refus(
				fn () => $this->dumper()->advance(
					$liste,
					array_replace(
						$valide,
						[
							'table'     => $rang,
							'after_hex' => [ '31' ],
						]
					),
					$emit,
					static fn (): bool => false
				)
			);
			self::assertSame( BackupRefused::DB_CURSOR_INVALID, $refus->reason_code(), $nom );
			self::assertNull( $refus->retry_after(), $nom );
			self::assertSame( 0, $emis, $nom . ' : rien émis' );
			self::assertSame( [], array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ), $nom . ' : refus au lot, avant sa requête' );
		}
	}

	public function test_reconnexion_en_latin1_refusee_sans_rien_emettre(): void {
		$liste         = [ 'wp_options' ];
		$deux_tranches = function ( ?array $session ) use ( $liste ): array {
			$this->wpdb = self::faux();
			if ( null !== $session ) {
				$this->wpdb->reconnect_on( 'WHERE (', $session );
			}
			$sql     = '';
			$emit    = static function ( string $morceau ) use ( &$sql ): void {
				$sql .= $morceau;
			};
			$curseur = self::par_l_etat( $this->dumper()->advance( $liste, null, $emit, static fn (): bool => true )['cursor'] );
			self::assertIsArray( $curseur );
			// Lot d'une ligne : le deuxième lot, celui qui reprend après la première ligne, reconnecte.
			$curseur['limit'] = 1;
			$refus            = null;
			try {
				self::assertNull( $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => false )['cursor'] );
			} catch ( BackupRefused $e ) {
				$refus = $e;
			}

			return [ $sql, $refus, self::compte( $this->wpdb->queries, FakeDumpWpdb::SESSION_QUERY ) ];
		};
		$colonnes      = [ 'option_id', 'option_name', 'option_value' ];
		$debut         = DatabaseDumper::header( 'utf8mb4' )
			. "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (`option_id`,`option_name`,`option_value`);\n"
			. DatabaseDumper::insert(
				'wp_options',
				$colonnes,
				[],
				[
					[
						'option_id'    => '1',
						'option_name'  => 'siteurl',
						'option_value' => 'https://exemple.test',
					],
				],
				'utf8mb4'
			);

		[ $reference, $sans_refus, $sessions ] = $deux_tranches( null );
		self::assertNull( $sans_refus );
		self::assertStringStartsWith( $debut, $reference );

		[ $emis, $refus ] = $deux_tranches( [ 'latin1', 'latin1', 'latin1' ] );
		self::assertInstanceOf( BackupRefused::class, $refus );
		self::assertSame( BackupRefused::DB_CHARSET_MISMATCH, $refus->reason_code() );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertSame( $debut, $emis, 'rien du lot lu dans la session latin1' );

		[ $reconnecte, $aucun_refus, $sessions_apres ] = $deux_tranches( [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ] );
		self::assertNull( $aucun_refus );
		self::assertSame( $reference, $reconnecte, 'session conforme : le lot rejoué sert' );
		self::assertSame( $sessions + 1, $sessions_apres, 'session contrôlée de nouveau après la reconnexion' );
	}

	public function test_liste_des_tables_relue_de_l_etat(): void {
		foreach ( [ null, [], [ 'FA' ], [ "77\n" ], [ 'abc' ], [ '' ], [ 77 ], 'wp_options', [ 'a' => '77' ] ] as $illisible ) {
			$refus = self::refus( static fn () => DatabaseDumper::tables_from_hex( $illisible ) );
			self::assertSame( BackupRefused::DB_CURSOR_INVALID, $refus->reason_code(), (string) wp_json_encode( $illisible ) );
			self::assertNull( $refus->retry_after() );
		}

		self::assertSame( [ "wp_caf\xe9" ], DatabaseDumper::tables_from_hex( [ '77705f636166e9' ] ) );
		self::assertSame( [ 'wp_options', 'wp_posts' ], DatabaseDumper::tables_from_hex( [ bin2hex( 'wp_options' ), bin2hex( 'wp_posts' ) ] ) );
		self::assertTrue( DatabaseDumper::hex_list( [ '', 'ab' ] ), 'valeur de clé vide : hexadécimal vide' );
		self::assertFalse( DatabaseDumper::hex_list( [ "ab\n" ] ) );
	}

	/**
	 * Table du faux de $n lignes (clé $cle de 1 à $n, valeur ligne-<clé>), rangées à l'envers, lue par
	 * lots de deux au départ (taille moyenne annoncée) ; $options remplace ou complète la table.
	 *
	 * @param list<string>         $pk
	 * @param array<string, mixed> $options
	 */
	private function lignes( string $table, string $cle, array $pk, int $n, array $options = [] ): void {
		$lignes = [];
		for ( $i = $n; $i >= 1; $i-- ) {
			$lignes[] = [
				$cle => (string) $i,
				'v'  => 'ligne-' . $i,
			];
		}
		$this->wpdb->tables[ $table ] = $options + [
			'columns'        => [
				$cle => 'bigint(20) unsigned',
				'v'  => 'varchar(20)',
			],
			'pk'             => $pk,
			'rows'           => $lignes,
			'avg_row_length' => intdiv( DatabaseDumper::BATCH_BYTES, 2 ),
		];
	}

	public function test_cle_invisible_lue_par_son_nom_et_reprise_sans_doublon(): void {
		// my_row_id : la clé INVISIBLE que MySQL 8.0.30+ ajoute (sql_generate_invisible_primary_key), que SELECT * ne rend pas.
		$this->lignes( 'wp_gipk', 'my_row_id', [ 'my_row_id' ], 9, [ 'invisible' => [ 'my_row_id' ] ] );
		$liste = [ 'wp_gipk' ];

		[ $entier ]                       = $this->dump( $liste, static fn (): bool => false );
		$this->wpdb->queries              = [];
		[ $repris, , $appels, $curseurs ] = $this->dump( $liste, static fn (): bool => true );

		self::assertSame( $entier, $repris, 'reprise par lots identique au dump d\'une traite' );
		self::assertSame( 4, $appels, 'en-tête, puis lots de 2, 4 et 8 lignes' );
		self::assertStringContainsString( "INSERT INTO `wp_gipk` (`my_row_id`,`v`) VALUES\n('1','ligne-1'),\n('2','ligne-2');\n", $repris );
		for ( $i = 1; $i <= 9; $i++ ) {
			self::assertSame( 1, substr_count( $repris, "('" . $i . "','ligne-" . $i . "')" ), 'ligne ' . $i . ' une fois, avec sa clé' );
		}
		self::assertStringNotContainsString( 'NULL', $repris, 'aucune colonne écrite NULL faute d\'avoir été lue' );
		self::assertSame( [ null, [ bin2hex( '2' ) ], [ bin2hex( '6' ) ] ], array_column( $curseurs, 'after_hex' ), 'la reprise avance sur la clé invisible' );
		$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
		self::assertCount( 3, $lectures );
		foreach ( $lectures as $lecture ) {
			self::assertStringStartsWith( 'SELECT `my_row_id`,`v` FROM `wp_gipk` ', $lecture, 'colonnes nommées, la clé invisible comprise' );
		}
	}

	public function test_colonne_de_la_cle_absente_de_la_ligne_refusee_sans_rien_emettre(): void {
		$anomalies = [
			'clé non rendue dans la ligne' => [ 'omitted' => [ 'my_row_id' ] ],
			'clé nulle dans la ligne'      => [
				'rows' => [
					[
						'my_row_id' => null,
						'v'         => 'ligne-1',
					],
				],
			],
		];
		$emis = '';
		// Fermeture créée hors des fonctions fléchées, qui captureraient $emis par valeur.
		$emit = static function ( string $morceau ) use ( &$emis ): void {
			$emis .= $morceau;
		};
		foreach ( $anomalies as $nom => $anomalie ) {
			$this->wpdb = self::faux();
			$this->lignes( 'wp_gipk', 'my_row_id', [ 'my_row_id' ], 5, $anomalie );
			$emis  = '';
			$refus = self::refus( fn () => $this->dumper()->advance( [ 'wp_gipk' ], null, $emit, static fn (): bool => false ) );
			self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code(), $nom );
			self::assertNotNull( $refus->retry_after(), $nom . ' : réessayable, rien n\'est corrompu' );
			self::assertStringContainsString( '« wp_gipk »', $refus->getMessage(), $nom . ' : la table est nommée' );
			self::assertSame( DatabaseDumper::header( 'utf8mb4' ) . "DROP TABLE IF EXISTS `wp_gipk`;\nCREATE TABLE `wp_gipk` (`my_row_id`,`v`);\n", $emis, $nom . ' : rien émis du lot' );
		}

		// Aucune colonne lue : refus avant le premier octet de la table et avant toute lecture de ses lignes.
		$this->wpdb                    = self::faux();
		$this->wpdb->tables['wp_vide'] = [
			'columns' => [],
			'pk'      => [],
			'rows'    => [],
		];
		$emis                          = '';
		$refus                         = self::refus( fn () => $this->dumper()->advance( [ 'wp_vide' ], null, $emit, static fn (): bool => false ) );
		self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		self::assertStringContainsString( '« wp_vide »', $refus->getMessage() );
		self::assertSame( DatabaseDumper::header( 'utf8mb4' ), $emis, 'en-tête seul : ni DROP ni CREATE' );
		self::assertSame( [], array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) || str_starts_with( $q, 'SHOW CREATE TABLE' ) ) );
	}

	public function test_reprise_qui_n_avance_pas_refusee_sans_rien_emettre(): void {
		// WHERE (clé) > (…) relu depuis le début (comme `my_row_id` > '' qui compare à 0) ; le lot grossit (2 puis 4 lignes),
		// sa dernière clé n'est donc pas celle du curseur : seule la ligne du curseur, relue, trahit la reprise.
		$this->lignes( 'wp_t', 'id', [ 'id' ], 5, [ 'where_ignored' => true ] );
		$sql     = '';
		$emit    = static function ( string $morceau ) use ( &$sql ): void {
			$sql .= $morceau;
		};
		$curseur = null;
		$refus   = null;
		$avant   = '';
		for ( $appels = 0; $appels < 10 && null === $refus; $appels++ ) {
			$avant = $sql;
			try {
				$curseur = self::par_l_etat( $this->dumper()->advance( [ 'wp_t' ], $curseur, $emit, static fn (): bool => true )['cursor'] );
			} catch ( BackupRefused $e ) {
				$refus = $e;
			}
		}

		self::assertInstanceOf( BackupRefused::class, $refus, 'jamais un dump fini sur une reprise qui n\'avance pas' );
		self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		self::assertStringContainsString( '« wp_t »', $refus->getMessage() );
		self::assertSame( 3, $appels, 'refus à la première reprise' );
		self::assertSame( $avant, $sql, 'rien émis par la tranche refusée' );
		for ( $i = 1; $i <= 5; $i++ ) {
			self::assertLessThanOrEqual( 1, substr_count( $sql, "'ligne-" . $i . "'" ), 'ligne ' . $i . ' jamais copiée deux fois' );
		}
	}

	public function test_cle_ajoutee_entre_deux_tranches_refusee_sans_rien_emettre(): void {
		$this->lignes( 'wp_t', 'id', [], 5 );
		$liste   = [ 'wp_t' ];
		$sql     = '';
		$emit    = static function ( string $morceau ) use ( &$sql ): void {
			$sql .= $morceau;
		};
		$curseur = null;
		// Deux tranches : l'en-tête et le CREATE, puis un lot LIMIT 2 OFFSET 0.
		for ( $i = 0; $i < 2; $i++ ) {
			$curseur = self::par_l_etat( $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => true )['cursor'] );
		}
		self::assertIsArray( $curseur );
		self::assertSame( 2, $curseur['offset'] );
		self::assertNull( $curseur['after_hex'] );

		// Une extension ajoute une clé primaire à la table (migration) avant la tranche suivante.
		$this->wpdb->tables['wp_t']['pk'] = [ 'id' ];
		$this->wpdb->queries              = [];
		$avant                            = $sql;
		$refus                            = self::refus( fn () => $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => false ) );

		self::assertSame( BackupRefused::DB_CURSOR_INVALID, $refus->reason_code() );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertSame( $avant, $sql, 'rien émis par la tranche refusée' );
		self::assertSame( [], array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ), 'refus avant la lecture du lot' );
		for ( $i = 1; $i <= 5; $i++ ) {
			self::assertLessThanOrEqual( 1, substr_count( $sql, "'ligne-" . $i . "'" ), 'ligne ' . $i . ' jamais copiée deux fois' );
		}
	}
}
