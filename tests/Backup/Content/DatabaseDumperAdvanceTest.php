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
	 * lignes, et une table sans clé primaire avec une colonne binaire. Les valeurs sont de type TEXT :
	 * un LONGTEXT commence par un lot d'une ligne (LARGE_TYPE), cas des tests « statistiques périmées ».
	 */
	private static function faux(): FakeDumpWpdb {
		$wpdb                       = new FakeDumpWpdb();
		$wpdb->tables['wp_options'] = [
			'columns' => [
				'option_id'    => 'bigint(20) unsigned',
				'option_name'  => 'varchar(191)',
				'option_value' => 'text',
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
				'meta_value' => 'text',
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
		$ligne                          = [
			'id' => '1',
			'e'  => 'bit',
			'st' => 'blob,bit',
			'b'  => "\x01",
			'vb' => "\x00a",
			't'  => "\xff",
			'lb' => 'z',
			'bn' => "\x10\x20",
		];
		$this->wpdb->tables['wp_types'] = [
			'columns' => [
				'id' => 'bigint(20)',
				'e'  => "enum('bit','blob')",
				'st' => "set('blob','bit')",
				'b'  => 'bit(1)',
				'vb' => 'varbinary(8)',
				't'  => 'tinyblob',
				'lb' => 'LONGBLOB',
				'bn' => 'binary(2)',
			],
			'pk'      => [ 'id' ],
			'rows'    => [ $ligne ],
		];

		[ $sql ] = $this->dump( [ 'wp_types' ], static fn (): bool => false );

		self::assertStringContainsString( "('1','bit','blob,bit',0x01,0x0061,0xff,0x7a,0x1020);", $sql, 'blob, binary, varbinary, bit : en hexadécimal ; une énumération ou un ensemble qui cite « bit » ou « blob », non' );

		// Un ENUM et un SET hors de la clé ne changent pas la lecture : seule une colonne de la clé l'envoie vers l'offset.
		$this->wpdb->tables['wp_types']['rows'][]        = [
			'id' => '3',
			'e'  => 'blob',
			'st' => '',
		] + $ligne;
		$this->wpdb->tables['wp_types']['rows'][]        = [
			'id' => '2',
			'st' => 'bit',
		] + $ligne;
		$this->wpdb->tables['wp_types']['avg_row_length'] = intdiv( DatabaseDumper::BATCH_BYTES, 2 );
		// BLOB (64 Kio) et non LONGBLOB : un LONGBLOB commence par un lot d'une ligne (LARGE_TYPE), ce que ce test ne vérifie pas.
		$this->wpdb->tables['wp_types']['columns']['lb'] = 'blob';
		$this->wpdb->queries                              = [];
		[ $repris, , $appels, $curseurs ]                 = $this->dump( [ 'wp_types' ], static fn (): bool => true );

		self::assertSame( 3, $appels, 'en-tête, puis lots de 2 et 4 lignes' );
		self::assertSame( [ null, [ bin2hex( '2' ) ] ], array_column( $curseurs, 'after_hex' ), 'reprise par la clé' );
		self::assertSame( [ 0, 0 ], array_column( $curseurs, 'offset' ), 'jamais par offset' );
		$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
		self::assertCount( 2, $lectures );
		self::assertStringEndsWith( ' FROM `wp_types` ORDER BY `id` LIMIT 2', $lectures[0], 'premier lot par la clé, sans OFFSET' );
		self::assertStringEndsWith( ' FROM `wp_types` WHERE (`id`) > (_utf8mb4 0x' . bin2hex( '2' ) . ') ORDER BY `id` LIMIT 4', $lectures[1], 'reprise WHERE (`id`) > (…), sans OFFSET' );
		foreach ( [ "('1','bit','blob,bit',", "('2','bit','bit',", "('3','blob',''," ] as $debut ) {
			self::assertSame( 1, substr_count( $repris, $debut ), $debut . ' copiée une fois' );
		}
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
				'v'  => 'text',
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

	/**
	 * Table d'un LONGTEXT dont la taille moyenne annoncée est périmée (relevé du 08/11/2026 : 54 octets
	 * annoncés, 300 lignes de 2 Mio, premier lot de 200 lignes, 400 Mio, erreur fatale sous 128 Mo).
	 *
	 * @return list<int> LIMIT lus, dans l'ordre.
	 */
	private function limites_d_une_table_large( string $type, int $moyenne ): array {
		$valeur = str_repeat( 'x', 2 * 1048576 - 1024 );
		$lignes = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			// Une seule chaîne partagée par les lignes du faux : 2 Mio en mémoire au lieu de 80 Mio.
			$lignes[] = [
				'id' => (string) $i,
				'v'  => $valeur,
			];
		}
		$this->wpdb->tables['wp_large'] = [
			'columns'        => [
				'id' => 'bigint(20)',
				'v'  => $type,
			],
			'pk'             => [ 'id' ],
			'rows'           => $lignes,
			'avg_row_length' => $moyenne,
		];
		unset( $lignes );

		return $this->limites_lues( 'wp_large', $sql );
	}

	public function test_une_colonne_de_plusieurs_mio_commence_par_un_lot_d_une_ligne_meme_si_la_moyenne_est_petite(): void {
		foreach ( [ 'longtext', 'MEDIUMTEXT', 'longblob', 'mediumblob', 'json' ] as $type ) {
			$this->wpdb->queries = [];
			$limites             = $this->limites_d_une_table_large( $type, 54 );

			self::assertSame( 1, $limites[0], $type . ' : une ligne pour commencer, jamais BATCH_ROWS lignes sur la foi de 54 octets' );
			self::assertLessThanOrEqual( 4, max( $limites ), $type . ' : le lot suivant se règle sur la taille lue (2 Mio la ligne)' );
		}
	}

	public function test_une_colonne_de_64_kio_garde_le_premier_lot_de_la_taille_moyenne(): void {
		foreach ( [ 'text', 'blob', 'tinytext', 'varchar(255)' ] as $type ) {
			$this->wpdb->queries = [];
			$limites             = $this->limites_d_une_table_large( $type, 1048576 );

			self::assertSame( 8, $limites[0], $type . ' : BATCH_BYTES / 1 Mio' );
		}
	}

	/**
	 * Revue du 08/11/2026 : 300 lignes d'un octet puis 400 de 256 Kio. Le LIMIT doublait après les petites
	 * lignes (jusqu'à 200) et le lot suivant lisait 38 Mio d'un coup. Les longueurs sont lues d'abord.
	 */
	public function test_aucun_lot_d_une_table_large_ne_depasse_batch_bytes_apres_de_petites_lignes(): void {
		$grosse = str_repeat( 'g', 262144 );
		$lignes = [];
		for ( $i = 1; $i <= 350; $i++ ) {
			// Une seule chaîne partagée par les lignes volumineuses du faux.
			$lignes[] = [
				'id' => (string) $i,
				'v'  => $i <= 300 ? 'p' : $grosse,
			];
		}
		$this->wpdb->tables['wp_mixte'] = [
			'columns'        => [
				'id' => 'bigint(20)',
				'v'  => 'longtext',
			],
			'pk'             => [ 'id' ],
			'rows'           => $lignes,
			'avg_row_length' => 54,
		];
		unset( $lignes );

		$limites = $this->limites_lues( 'wp_mixte', $sql );

		self::assertLessThan( 200, max( $limites ), 'le lot de 200 lignes (jusqu\'à 50 Mio de lignes de 256 Kio) est réduit à ce que BATCH_BYTES permet' );
		self::assertGreaterThan( 1, max( $limites ), 'les petites lignes passent par lots, pas une à une' );
		self::assertSame( 50, substr_count( (string) $sql, $grosse ), 'chaque ligne copiée une fois' );
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
				'v'  => 'text',
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
				'v' => 'text',
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
		$deux_tranches = function ( ?array $session, string $fuseau_du_serveur = DatabaseDumper::TIME_ZONE ) use ( $liste ): array {
			$this->wpdb                    = self::faux();
			$this->wpdb->default_time_zone = $fuseau_du_serveur;
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

		// Serveur réglé en UTC : la session rouverte l'est aussi.
		[ $reconnecte, $aucun_refus, $sessions_apres ] = $deux_tranches( [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ] );
		self::assertNull( $aucun_refus );
		self::assertSame( $reference, $reconnecte, 'session conforme et en UTC : le lot rejoué sert' );
		self::assertSame( $sessions + 1, $sessions_apres, 'session contrôlée de nouveau après la reconnexion' );

		// Serveur réglé sur Paris (SYSTEM) : wpdb::db_connect() ne règle pas le fuseau, le lot rejoué a lu ses dates TIMESTAMP
		// dans celui du serveur.
		[ $hors_utc, $refus_fuseau ] = $deux_tranches( [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ], 'SYSTEM' );
		self::assertInstanceOf( BackupRefused::class, $refus_fuseau );
		self::assertSame( BackupRefused::DB_READ_FAILED, $refus_fuseau->reason_code() );
		self::assertNotNull( $refus_fuseau->retry_after(), 'réessayable : la tranche suivante repart d\'une session mise en UTC' );
		self::assertStringContainsString( 'wp_options', $refus_fuseau->getMessage(), 'message qui nomme la table' );
		self::assertSame( $debut, $hors_utc, 'rien du lot rejoué hors d\'UTC' );
		self::assertSame( 'SYSTEM', $this->wpdb->time_zone, 'la session rouverte reprend son fuseau à la fin de l\'avance refusée' );
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

	/**
	 * Table du faux dont la clé contient un ENUM ou un SET, lue par lots d'une ligne au départ (taille
	 * moyenne annoncée) : chaque ligne a sa valeur v, ligne-<clé>.
	 *
	 * @param array<string, string>       $columns
	 * @param list<string>                $pk
	 * @param list<array<string, string>> $rows
	 */
	private function table_a_cle_ordinale( string $table, array $columns, array $pk, array $rows, int $avg_row ): void {
		$this->wpdb->tables[ $table ] = [
			'columns'        => $columns + [ 'v' => 'varchar(20)' ],
			'pk'             => $pk,
			'rows'           => array_map( static fn ( array $row ): array => $row + [ 'v' => 'ligne-' . implode( '-', $row ) ], $rows ),
			'avg_row_length' => $avg_row,
		];
	}

	public function test_cle_enum_ou_set_lue_par_offset_chaque_ligne_une_fois(): void {
		// ORDER BY trie un ENUM par son rang dans la définition (z, a, m) et un SET par sa valeur numérique (z = 1,
		// a = 2, z,a = 3), WHERE (clé) > (…) les compare comme du texte : une reprise par la clé perdrait ou doublerait des lignes.
		// Un FLOAT lu tel quel est arrondi (0.6999999 et 0.7 : « 0.7 »), que WHERE relit en un double qui n'est pas la valeur
		// rangée : après « 0.1 », la ligne du curseur revient ; après « 0.7 », la ligne 0.7 (0.699999988) n'est pas rendue. Lu
		// désormais par son texte exact (test_float_lu_par_son_texte_exact()), la clé FLOAT reste lue par offset.
		$cas = [
			'clé FLOAT (valeur lue arrondie)' => [
				[ 'k' => 'float' ],
				[ 'k' ],
				[ [ 'k' => '0.8' ], [ 'k' => '0.7' ], [ 'k' => '0.6999999' ], [ 'k' => '0.1' ] ],
				[ 'ligne-0.1', 'ligne-0.6999999', 'ligne-0.7', 'ligne-0.8' ],
			],
			'clé composée (x, DOUBLE)'        => [
				[
					'x' => 'int(11)',
					'k' => 'double unsigned',
				],
				[ 'x', 'k' ],
				[
					[
						'x' => '2',
						'k' => '0.5',
					],
					[
						'x' => '1',
						'k' => '0.25',
					],
					[
						'x' => '1',
						'k' => '1e-7',
					],
				],
				[ 'ligne-1-1e-7', 'ligne-1-0.25', 'ligne-2-0.5' ],
			],
			'clé REAL (type en capitales)'    => [
				[ 'k' => 'REAL' ],
				[ 'k' ],
				[ [ 'k' => '10' ], [ 'k' => '-1' ], [ 'k' => '2.5' ] ],
				[ 'ligne--1', 'ligne-2.5', 'ligne-10' ],
			],
			'clé ENUM simple'                 => [
				[ 'k' => "enum('z','a','m')" ],
				[ 'k' ],
				[ [ 'k' => 'a' ], [ 'k' => 'm' ], [ 'k' => 'z' ] ],
				[ 'ligne-z', 'ligne-a', 'ligne-m' ],
			],
			'clé composée (x, ENUM)'          => [
				[
					'x' => 'int(11)',
					'k' => "enum('z','a','m')",
				],
				[ 'x', 'k' ],
				[
					[
						'x' => '2',
						'k' => 'm',
					],
					[
						'x' => '1',
						'k' => 'a',
					],
					[
						'x' => '2',
						'k' => 'z',
					],
					[
						'x' => '1',
						'k' => 'm',
					],
					[
						'x' => '2',
						'k' => 'a',
					],
					[
						'x' => '1',
						'k' => 'z',
					],
				],
				[ 'ligne-1-z', 'ligne-1-a', 'ligne-1-m', 'ligne-2-z', 'ligne-2-a', 'ligne-2-m' ],
			],
			'clé SET (type en capitales)'     => [
				[ 'k' => "SET('z','a')" ],
				[ 'k' ],
				[ [ 'k' => 'z,a' ], [ 'k' => 'a' ], [ 'k' => 'z' ] ],
				[ 'ligne-z', 'ligne-a', 'ligne-z,a' ],
			],
		];
		foreach ( $cas as $nom => [ $colonnes, $pk, $lignes, $ordre ] ) {
			$this->wpdb = self::faux();
			$this->table_a_cle_ordinale( 'wp_ord', $colonnes, $pk, $lignes, DatabaseDumper::BATCH_BYTES );
			$liste = [ 'wp_ord' ];

			[ $entier, $faites ]              = $this->dump( $liste, static fn (): bool => false );
			$this->wpdb->queries              = [];
			[ $repris, , $appels, $curseurs ] = $this->dump( $liste, static fn (): bool => true );

			self::assertSame( $entier, $repris, $nom . ' : reprise par lots identique au dump d\'une traite' );
			self::assertGreaterThan( 2, $appels, $nom . ' : plusieurs lots' );
			self::assertSame( count( $lignes ), $faites[0]['rows'], $nom . ' : toutes les lignes annoncées' );
			$position = -1;
			foreach ( $ordre as $valeur ) {
				self::assertSame( 1, substr_count( $repris, "'" . $valeur . "'" ), $nom . ' : ' . $valeur . ' copiée une fois et une seule' );
				$ici = (int) strpos( $repris, "'" . $valeur . "'" );
				self::assertGreaterThan( $position, $ici, $nom . ' : ' . $valeur . ' dans l\'ordre de ORDER BY la clé' );
				$position = $ici;
			}
			self::assertSame( array_fill( 0, count( $curseurs ), null ), array_column( $curseurs, 'after_hex' ), $nom . ' : jamais de reprise par la clé' );
			self::assertGreaterThan( 0, max( array_column( $curseurs, 'offset' ) ), $nom . ' : la reprise avance par offset' );
			$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
			self::assertNotSame( [], $lectures );
			// Colonnes de la clé qualifiées par la table : ORDER BY `k` prendrait l'alias d'un FLOAT lu `k`*1e0 AS `k`.
			$cle = implode( ',', array_map( static fn ( string $c ): string => '`wp_ord`.`' . $c . '`', $pk ) );
			foreach ( $lectures as $lecture ) {
				self::assertStringNotContainsString( ' WHERE ', $lecture, $nom );
				self::assertMatchesRegularExpression( '/ FROM `wp_ord` ORDER BY ' . preg_quote( $cle, '/' ) . ' LIMIT [1-9]\d* OFFSET \d+\z/', $lecture, $nom . ' : ordre déterministe (la clé est unique)' );
			}
		}
	}

	public function test_cle_changee_de_sorte_entre_deux_tranches_refusee_sans_rien_emettre(): void {
		$cas = [
			// Reprise par la clé commencée (after_hex), clé devenue ENUM : l'offset, resté à 0, recopierait la table depuis le début.
			'clé devenue ENUM'         => [ 'bigint(20) unsigned', "enum('1','2','3','4','5')", 'after_hex' ],
			// Lecture par offset commencée, clé qui n'est plus un ENUM : la reprise par la clé repartirait du début.
			'clé qui n\'est plus ENUM' => [ "enum('1','2','3','4','5')", 'varchar(1)', 'offset' ],
		];
		foreach ( $cas as $nom => [ $avant_type, $apres_type, $avance ] ) {
			$this->wpdb = self::faux();
			$this->lignes( 'wp_t', 'id', [ 'id' ], 5 );
			$this->wpdb->tables['wp_t']['columns']['id'] = $avant_type;

			$liste   = [ 'wp_t' ];
			$sql     = '';
			$emit    = static function ( string $morceau ) use ( &$sql ): void {
				$sql .= $morceau;
			};
			$curseur = null;
			// Deux tranches : l'en-tête et le CREATE, puis un lot de deux lignes.
			for ( $i = 0; $i < 2; $i++ ) {
				$curseur = self::par_l_etat( $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => true )['cursor'] );
			}
			self::assertIsArray( $curseur );
			self::assertSame( 'after_hex' === $avance ? [ bin2hex( '2' ) ] : null, $curseur['after_hex'], $nom );
			self::assertSame( 'offset' === $avance ? 2 : 0, $curseur['offset'], $nom );

			// Une extension change le type de la colonne de la clé (migration) avant la tranche suivante.
			$this->wpdb->tables['wp_t']['columns']['id'] = $apres_type;
			$this->wpdb->queries                         = [];
			$avant                                       = $sql;
			$refus                                       = self::refus( fn () => $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => false ) );

			self::assertSame( BackupRefused::DB_CURSOR_INVALID, $refus->reason_code(), $nom );
			self::assertNull( $refus->retry_after(), $nom . ' : non réessayable' );
			self::assertSame( $avant, $sql, $nom . ' : rien émis par la tranche refusée' );
			self::assertSame( [], array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ), $nom . ' : refus avant la lecture du lot' );
		}
	}

	public function test_colonnes_generees_absentes_de_l_insert_reprise_par_la_cle(): void {
		// Relevé du 08/11/2026 : re, ROW END explicite d'une table à versions système de MariaDB 10.11.19, est STORED GENERATED
		// et MariaDB l'ajoute à la clé (PRIMARY (id, re)) ; g et s sont générées hors de la clé. ts (DEFAULT CURRENT_TIMESTAMP)
		// a DEFAULT_GENERATED dans Extra sous MySQL 8.4.0 : colonne ordinaire, copiée. Une valeur donnée à une colonne générée
		// fait échouer la restauration en mode strict (erreur 1906 sous MariaDB, 3105 sous MySQL 8).
		$lignes = [];
		for ( $i = 5; $i >= 1; $i-- ) {
			$lignes[] = [
				'id' => (string) $i,
				'g'  => (string) ( 2 * $i ),
				'v'  => 'ligne-' . $i,
				'ts' => '2001-01-0' . $i . ' 00:00:00',
				's'  => 's-' . $i,
				're' => '2038-01-19 03:14:07.999999',
			];
		}
		$this->wpdb->tables['wp_gen'] = [
			'columns'        => [
				'id' => 'int(11)',
				'g'  => 'int(11)',
				'v'  => 'varchar(20)',
				'ts' => 'timestamp',
				's'  => 'varchar(30)',
				're' => 'timestamp(6)',
			],
			'pk'             => [ 'id', 're' ],
			'rows'           => $lignes,
			'avg_row_length' => intdiv( DatabaseDumper::BATCH_BYTES, 2 ),
			'extra'          => [
				'g'  => 'VIRTUAL GENERATED INVISIBLE',
				'ts' => 'DEFAULT_GENERATED',
				's'  => 'STORED GENERATED',
				're' => 'STORED GENERATED',
			],
		];
		$liste = [ 'wp_gen' ];

		[ $entier ]                       = $this->dump( $liste, static fn (): bool => false );
		$this->wpdb->queries              = [];
		[ $repris, , $appels, $curseurs ] = $this->dump( $liste, static fn (): bool => true );

		self::assertSame( $entier, $repris, 'reprise par lots identique au dump d\'une traite' );
		self::assertSame( 3, $appels, 'en-tête, puis lots de 2 et 4 lignes' );
		self::assertStringContainsString( "INSERT INTO `wp_gen` (`id`,`v`,`ts`) VALUES\n('1','ligne-1','2001-01-01 00:00:00'),\n('2','ligne-2','2001-01-02 00:00:00');\n", $repris, 'colonnes générées absentes de l\'INSERT, celle de la clé comprise ; DEFAULT_GENERATED copiée' );
		self::assertSame( 2, substr_count( $repris, 'INSERT INTO ' ) );
		self::assertSame( 2, substr_count( $repris, "INSERT INTO `wp_gen` (`id`,`v`,`ts`) VALUES\n" ), 'aucun INSERT ne nomme une colonne générée' );
		for ( $i = 1; $i <= 5; $i++ ) {
			self::assertSame( 1, substr_count( $repris, "('" . $i . "','ligne-" . $i . "','2001-01-0" . $i . " 00:00:00')" ), 'ligne ' . $i . ' une fois' );
		}
		self::assertSame( [ null, [ bin2hex( '2' ), bin2hex( '2038-01-19 03:14:07.999999' ) ] ], array_column( $curseurs, 'after_hex' ), 'la reprise avance sur la clé, sa colonne générée comprise' );
		$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
		self::assertCount( 2, $lectures );
		foreach ( $lectures as $lecture ) {
			self::assertStringStartsWith( 'SELECT `id`,`v`,`ts`,`re` FROM `wp_gen` ', $lecture, 'colonne générée de la clé lue (key_hex()), les autres non' );
		}
		self::assertStringContainsString( ' WHERE (`id`,`re`) > (_utf8mb4 0x' . bin2hex( '2' ) . ',_utf8mb4 0x' . bin2hex( '2038-01-19 03:14:07.999999' ) . ') ', $lectures[1] );

		// Table dont toutes les colonnes sont générées (admise par les deux serveurs) : ses colonnes sont lues pour compter les
		// lignes (SELECT sans colonne : erreur 1064), l'INSERT n'en nomme aucune ; INSERT INTO t () VALUES (),() passe en
		// mode strict (relevé du 08/11/2026, MariaDB 10.11.19 et MySQL 8.4.0).
		$this->wpdb->tables['wp_toutgen'] = [
			'columns' => [ 'g' => 'int(11)' ],
			'pk'      => [],
			'rows'    => array_fill( 0, 3, [ 'g' => '1' ] ),
			'extra'   => [ 'g' => 'VIRTUAL GENERATED' ],
		];
		[ $sql, $faites ] = $this->dump( [ 'wp_toutgen' ], static fn (): bool => false );
		self::assertStringContainsString( "INSERT INTO `wp_toutgen` () VALUES\n(),\n(),\n();\n", $sql );
		self::assertSame( 3, $faites[0]['rows'] );
	}

	/**
	 * Valeurs des lignes des INSERT de $table, dans l'ordre, sans leurs apostrophes (les valeurs de
	 * ces tests n'ont ni virgule, ni apostrophe, ni \).
	 *
	 * @return list<list<string>>
	 */
	private static function tuples( string $sql, string $table ): array {
		$lignes = [];
		preg_match_all( '/^INSERT INTO `' . preg_quote( $table, '/' ) . '` \([^)]*\) VALUES\n(.*?);\n/ms', $sql, $inserts );
		foreach ( $inserts[1] as $valeurs ) {
			foreach ( explode( ",\n", $valeurs ) as $tuple ) {
				$lignes[] = array_map( static fn ( string $v ): string => trim( $v, "'" ), explode( ',', substr( $tuple, 1, -1 ) ) );
			}
		}
		return $lignes;
	}

	/** Float de simple précision que range une colonne FLOAT pour ce texte (INSERT de la restauration). */
	private static function simple( string $texte ): float {
		$valeur = unpack( 'g', pack( 'g', (float) $texte ) );
		self::assertIsArray( $valeur );
		return (float) $valeur[1];
	}

	public function test_float_lu_par_son_texte_exact(): void {
		// Relevé du 08/11/2026 (MariaDB 10.11.19, MySQL 8.0.16 et 8.4.0) : lu tel quel, un FLOAT est le texte arrondi à
		// 6 chiffres significatifs : 0.6999999 et 0.7 s'écrivaient toutes deux '0.7' (restauration de la clé en erreur 1062),
		// 48.856613 devenait 48.8566 sans bruit. `c`*1e0 AS `c` rend le double exact du float rangé, que l'INSERT redonne.
		$this->wpdb->tables['wp_float'] = [
			'columns'        => [
				'k' => 'float',
				'a' => 'float',
				'b' => 'float(7,4)',
				'd' => 'double',
				'v' => 'varchar(20)',
			],
			'pk'             => [ 'k' ],
			'rows'           => [
				[
					'k' => '0.8',
					'a' => '48.856613',
					'b' => '48.8566',
					'd' => '0.30000000000000004',
					'v' => 'ligne-0.8',
				],
				[
					'k' => '0.7',
					'a' => '2.3522219',
					'b' => '999.9999',
					'd' => '1e-7',
					'v' => 'ligne-0.7',
				],
				[
					'k' => '0.6999999',
					'a' => '0.6999999',
					'b' => '-12.3456',
					'd' => '1.7976931348623157e308',
					'v' => 'ligne-0.6999999',
				],
				[
					'k' => '0.1',
					'a' => '3.4028234e38',
					'b' => '0.0001',
					'd' => '5e-324',
					'v' => 'ligne-0.1',
				],
			],
			'avg_row_length' => DatabaseDumper::BATCH_BYTES,
		];
		$sources = array_column( $this->wpdb->tables['wp_float']['rows'], null, 'v' );
		$liste   = [ 'wp_float' ];

		[ $entier ]                          = $this->dump( $liste, static fn (): bool => false );
		$this->wpdb->queries                 = [];
		[ $repris, $faites, $appels, $etats ] = $this->dump( $liste, static fn (): bool => true );

		self::assertSame( $entier, $repris, 'reprise par lots identique au dump d\'une traite' );
		self::assertGreaterThan( 2, $appels, 'plusieurs lots' );
		self::assertSame( 4, $faites[0]['rows'] );
		self::assertSame( array_fill( 0, count( $etats ), null ), array_column( $etats, 'after_hex' ), 'clé FLOAT : toujours lue par offset' );
		$lignes = self::tuples( $repris, 'wp_float' );
		self::assertCount( 4, $lignes );
		$cles = array_column( $lignes, 0 );
		self::assertSame( $cles, array_values( array_unique( $cles ) ), 'valeurs de la clé distinctes dans l\'INSERT (sinon : restauration en erreur 1062)' );
		self::assertSame( [ 'ligne-0.1', 'ligne-0.6999999', 'ligne-0.7', 'ligne-0.8' ], array_column( $lignes, 4 ), 'ordre de la clé, chaque ligne une fois' );
		foreach ( $lignes as [ $k, $a, $b, $d, $v ] ) {
			self::assertSame( self::simple( $sources[ $v ]['k'] ), self::simple( $k ), $v . ' : la clé écrite (' . $k . ') redonne le float rangé' );
			self::assertSame( self::simple( $sources[ $v ]['a'] ), self::simple( $a ), $v . ' : FLOAT hors de la clé (' . $a . ') redonne le float rangé' );
			self::assertSame( self::simple( $sources[ $v ]['b'] ), self::simple( $b ), $v . ' : FLOAT(7,4) (' . $b . ') redonne le float rangé' );
			self::assertSame( $sources[ $v ]['d'], $d, $v . ' : DOUBLE lu tel quel (texte exact)' );
		}
		self::assertStringNotContainsString( "'48.8566'", $repris, '48.856613 n\'est pas arrondi' );
		$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
		self::assertNotSame( [], $lectures );
		foreach ( $lectures as $lecture ) {
			self::assertMatchesRegularExpression( '/\ASELECT `k`\*1e0 AS `k`,`a`\*1e0 AS `a`,`b`\*1e0 AS `b`,`d`,`v` FROM `wp_float` ORDER BY `wp_float`.`k` LIMIT [1-9]\d* OFFSET \d+\z/', $lecture, 'FLOAT sous son nom, DOUBLE tel quel, ORDER BY la colonne qualifiée' );
		}

		// FLOAT hors d'une clé qui sert à la reprise (type en capitales) : la table se lit par la clé, ORDER BY et WHERE nomment
		// la colonne de la clé elle-même.
		$this->wpdb->tables['wp_lieux'] = [
			'columns'        => [
				'id'  => 'bigint(20)',
				'lat' => 'FLOAT',
				'lng' => 'float unsigned',
			],
			'pk'             => [ 'id' ],
			'rows'           => [
				[
					'id'  => '3',
					'lat' => '43.604652',
					'lng' => '1.444209',
				],
				[
					'id'  => '1',
					'lat' => '48.856613',
					'lng' => '2.3522219',
				],
				[
					'id'  => '2',
					'lat' => '45.764042',
					'lng' => '4.835659',
				],
			],
			'avg_row_length' => DatabaseDumper::BATCH_BYTES,
		];
		$sources             = array_column( $this->wpdb->tables['wp_lieux']['rows'], null, 'id' );
		$this->wpdb->queries = [];
		[ $lieux, , , $etats ] = $this->dump( [ 'wp_lieux' ], static fn (): bool => true );

		self::assertSame( [ bin2hex( '1' ) ], $etats[1]['after_hex'] ?? null, 'reprise par la clé' );
		$lignes = self::tuples( $lieux, 'wp_lieux' );
		self::assertSame( [ '1', '2', '3' ], array_column( $lignes, 0 ) );
		foreach ( $lignes as [ $id, $lat, $lng ] ) {
			self::assertSame( self::simple( $sources[ $id ]['lat'] ), self::simple( $lat ), $id . ' : latitude exacte (' . $lat . ')' );
			self::assertSame( self::simple( $sources[ $id ]['lng'] ), self::simple( $lng ), $id . ' : longitude exacte (' . $lng . ')' );
		}
		$lectures = array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, FakeDumpWpdb::ROWS_START ) ) );
		self::assertGreaterThan( 1, count( $lectures ) );
		self::assertSame( 'SELECT `id`,`lat`*1e0 AS `lat`,`lng`*1e0 AS `lng` FROM `wp_lieux` ORDER BY `id` LIMIT 1', $lectures[0] );
		self::assertStringStartsWith( 'SELECT `id`,`lat`*1e0 AS `lat`,`lng`*1e0 AS `lng` FROM `wp_lieux` WHERE (`id`) > (_utf8mb4 0x31) ORDER BY `id` LIMIT ', $lectures[1] );
	}

	/**
	 * Table du faux à clé TIMESTAMP : quatre instants (UTC) autour du passage à l'heure d'hiver à
	 * Paris, le 25/10/2026 (03:00 CEST = 01:00 UTC devient 02:00 CET) ; 00:30 et 01:30 UTC se
	 * lisent toutes deux « 02:30:00 » dans le fuseau SYSTEM d'un serveur réglé sur Paris. m :
	 * TIMESTAMP hors de la clé, à l'heure ambiguë pour deux lignes.
	 */
	private function table_timestamp(): void {
		$this->wpdb->tables['wp_ts'] = [
			'columns'        => [
				'k' => 'timestamp',
				'v' => 'varchar(10)',
				'm' => 'timestamp',
			],
			'pk'             => [ 'k' ],
			'rows'           => [
				[
					'k' => '2026-10-25 02:00:00',
					'v' => 'd',
					'm' => '2026-03-29 01:30:00',
				],
				[
					'k' => '2026-10-25 00:30:00',
					'v' => 'b',
					'm' => '2026-10-25 00:30:00',
				],
				[
					'k' => '2026-10-25 01:30:00',
					'v' => 'c',
					'm' => null,
				],
				[
					'k' => '2026-10-25 00:00:00',
					'v' => 'a',
					'm' => '2026-10-25 01:30:00',
				],
			],
			'avg_row_length' => DatabaseDumper::BATCH_BYTES,
		];
	}

	public function test_session_en_utc_pendant_chaque_avance_cle_timestamp_au_changement_d_heure(): void {
		// Essai du 08/11/2026 (MariaDB 10.11.19 et MySQL 8.4.0, fuseau SYSTEM réglé sur Paris) : dans le fuseau du serveur, la
		// reprise après « 02:30:00 » perd la ligne de 01:30 UTC, et la restauration échoue (erreur 1062). En UTC, aucune heure
		// n'est ambiguë ; l'en-tête l'annonce à la restauration.
		$this->table_timestamp();
		$liste    = [ 'wp_ts' ];
		$sql      = '';
		$emit     = static function ( string $morceau ) use ( &$sql ): void {
			$sql .= $morceau;
		};
		$curseur  = null;
		$annonces = 0;
		$avances  = 0;
		do {
			$this->wpdb->queries = [];
			$etape               = $this->dumper()->advance( $liste, $curseur, $emit, static fn (): bool => true );
			$annonces           += array_sum( array_column( $etape['tables_done'], 'rows' ) );
			$curseur             = self::par_l_etat( $etape['cursor'] );
			++$avances;

			$requetes = $this->wpdb->queries;
			$utc      = array_search( FakeDumpWpdb::UTC_QUERY, $requetes, true );
			self::assertIsInt( $utc, 'avance ' . $avances . ' : session mise en UTC' );
			foreach ( $requetes as $rang => $requete ) {
				if ( str_starts_with( $requete, 'SHOW ' ) || str_starts_with( $requete, FakeDumpWpdb::ROWS_START ) ) {
					self::assertGreaterThan( $utc, $rang, 'avance ' . $avances . ' : aucune lecture des tables avant le passage en UTC : ' . $requete );
				}
			}
			self::assertSame( "SET time_zone = 'SYSTEM'", end( $requetes ), 'avance ' . $avances . ' : fuseau rendu en dernier' );
			self::assertSame( 'SYSTEM', $this->wpdb->time_zone, 'avance ' . $avances . ' : la session reprend son fuseau' );
		} while ( null !== $curseur && $avances < 30 );

		self::assertNull( $curseur, 'dump fini' );
		self::assertGreaterThan( 3, $avances, 'lots d\'une ligne au départ' );
		self::assertStringStartsWith( "-- G2RD backup dump v1\n/*!40101 SET NAMES utf8mb4 */;\n/*!40103 SET TIME_ZONE='+00:00' */;\nSET FOREIGN_KEY_CHECKS=0;\n", $sql, 'fuseau des valeurs annoncé à la restauration' );
		self::assertSame( 4, $annonces, 'toutes les lignes annoncées' );
		$lignes = self::tuples( $sql, 'wp_ts' );
		self::assertSame(
			[
				[ '2026-10-25 00:00:00', 'a', '2026-10-25 01:30:00' ],
				[ '2026-10-25 00:30:00', 'b', '2026-10-25 00:30:00' ],
				[ '2026-10-25 01:30:00', 'c', 'NULL' ],
				[ '2026-10-25 02:00:00', 'd', '2026-03-29 01:30:00' ],
			],
			$lignes,
			'chaque instant une fois, écrit en UTC, clé comme hors de la clé'
		);
	}

	public function test_fuseau_rendu_apres_un_refus_et_jamais_change_s_il_est_deja_utc(): void {
		$this->table_timestamp();
		$this->wpdb->fails_on = 'wp_ts';
		$refus                = self::refus(
			fn () => $this->dumper()->advance(
				[ 'wp_ts' ],
				null,
				static function ( string $morceau ): void {
					unset( $morceau );
				},
				static fn (): bool => false
			)
		);

		self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		self::assertSame( 'SYSTEM', $this->wpdb->time_zone, 'fuseau rendu après le refus' );
		self::assertSame( "SET time_zone = 'SYSTEM'", end( $this->wpdb->queries ) );

		// Session déjà en UTC : ni SET ni retour.
		$this->wpdb->fails_on  = null;
		$this->wpdb->time_zone = DatabaseDumper::TIME_ZONE;
		$this->wpdb->queries   = [];
		[ $sql ]               = $this->dump( [ 'wp_ts' ], static fn (): bool => false );

		self::assertStringContainsString( "('2026-10-25 01:30:00','c',NULL)", $sql );
		self::assertSame( [], array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SET ' ) ) ), 'aucun SET' );
		self::assertSame( DatabaseDumper::TIME_ZONE, $this->wpdb->time_zone );

		// Même instance pour deux avances : le fuseau rendu par la première ne resert pas à la seconde, commencée en UTC.
		$dumper                = $this->dumper();
		$rien                  = static function ( string $morceau ): void {
			unset( $morceau );
		};
		$this->wpdb->time_zone = 'SYSTEM';
		$curseur               = self::par_l_etat( $dumper->advance( [ 'wp_ts' ], null, $rien, static fn (): bool => true )['cursor'] );
		self::assertSame( 'SYSTEM', $this->wpdb->time_zone );
		$this->wpdb->time_zone = DatabaseDumper::TIME_ZONE;
		$this->wpdb->queries   = [];
		$dumper->advance( [ 'wp_ts' ], $curseur, $rien, static fn (): bool => true );
		self::assertSame( [], array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SET ' ) ) ), 'seconde avance : aucun SET' );
		self::assertSame( DatabaseDumper::TIME_ZONE, $this->wpdb->time_zone, 'seconde avance : session laissée en UTC' );
	}

	public function test_fuseau_hors_du_motif_jamais_reecrit(): void {
		// Valeur de @@session.time_zone qui contiendrait une apostrophe : la réécrire entre apostrophes ferait exécuter la suite.
		$this->table_timestamp();
		$this->wpdb->time_zone = "Europe/Paris'; DROP TABLE wp_ts; --";
		[ $sql ]               = $this->dump( [ 'wp_ts' ], static fn (): bool => false );

		self::assertStringContainsString( "('2026-10-25 01:30:00','c',NULL)", $sql, 'dump lu en UTC' );
		self::assertSame( [ FakeDumpWpdb::UTC_QUERY ], array_values( array_filter( $this->wpdb->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SET ' ) ) ), 'seul le passage en UTC, jamais la valeur lue' );
		self::assertSame( DatabaseDumper::TIME_ZONE, $this->wpdb->time_zone, 'session laissée en UTC' );
	}

	public function test_session_restee_hors_d_utc_refusee_avant_toute_lecture(): void {
		$this->table_timestamp();
		$this->wpdb->zone_locked = true;
		$emis                    = '';
		$refus                   = self::refus(
			fn () => $this->dumper()->advance(
				[ 'wp_ts' ],
				null,
				static function ( string $morceau ) use ( &$emis ): void {
					$emis .= $morceau;
				},
				static fn (): bool => false
			)
		);

		self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		self::assertNotNull( $refus->retry_after(), 'réessayable' );
		self::assertSame( '', $emis, 'rien émis, en-tête compris' );
		foreach ( $this->wpdb->queries as $requete ) {
			self::assertFalse( str_starts_with( $requete, 'SHOW ' ) || str_starts_with( $requete, FakeDumpWpdb::ROWS_START ), 'aucune lecture des tables : ' . $requete );
		}
	}
}
