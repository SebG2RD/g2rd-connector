<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\DatabaseDumper;
use G2RD\Connector\Tests\TestCase;

/**
 * Première moitié du dump (docs/backup-payload.md § 4) : valeurs, identifiants et instructions des
 * vecteurs du générateur indépendant de la plateforme ; jeu de la session vérifié avant toute
 * lecture ; motifs LIKE sans \ ; tables copiées, filet <préfixe>options et noms perdus.
 */
final class DatabaseDumperTablesTest extends TestCase {

	/** @return array<string, mixed> */
	private static function vecteurs(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/backup-payload-vectors.json' ), true, 32, JSON_THROW_ON_ERROR );
	}

	/**
	 * Une section des vecteurs, jamais vide (une section absente ne doit pas faire passer un test).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function section( string $nom ): array {
		$cas = self::vecteurs()[ $nom ] ?? [];
		self::assertIsArray( $cas, $nom );
		self::assertNotSame( [], $cas, 'section ' . $nom . ' absente des vecteurs' );

		return array_values( $cas );
	}

	/** @return array{columns: array<string, string>, pk: list<string>, rows: list<array<string, string|null>>} */
	private static function table(): array {
		return [
			'columns' => [ 'id' => 'bigint(20)' ],
			'pk'      => [ 'id' ],
			'rows'    => [],
		];
	}

	private static function faux_du_site(): FakeDumpWpdb {
		$wpdb                       = new FakeDumpWpdb();
		$wpdb->tables['wp_options'] = self::table();
		$wpdb->tables['wp_posts']   = self::table();

		return $wpdb;
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
	private static function compte( array $requetes, string $debut ): int {
		return count( array_filter( $requetes, static fn ( string $q ): bool => str_starts_with( $q, $debut ) ) );
	}

	public function test_valeurs_sql_des_vecteurs(): void {
		foreach ( self::section( 'sql_values' ) as $cas ) {
			$valeur = null === $cas['value_hex'] ? null : (string) hex2bin( $cas['value_hex'] );
			self::assertSame( $cas['expected_hex'], bin2hex( DatabaseDumper::quote( $valeur, $cas['binary'], $cas['charset'] ) ), $cas['name'] );
		}
	}

	public function test_valeurs_de_reprise_en_hexadecimal_quel_que_soit_le_jeu(): void {
		$injection = "') UNION SELECT 1 -- ";
		foreach ( [ 'utf8mb4', 'latin1', 'utf8', 'cp932', 'big5' ] as $jeu ) {
			self::assertSame( '_' . $jeu . ' 0x' . bin2hex( $injection ), DatabaseDumper::key_literal( $injection, false, $jeu ), $jeu );
			$ecrit = DatabaseDumper::key_literal( "d'e\\", false, $jeu );
			self::assertStringNotContainsString( "'", $ecrit, $jeu . ' : aucune apostrophe' );
			self::assertStringNotContainsString( '\\', $ecrit, $jeu . ' : aucune barre oblique inverse' );
		}
		self::assertSame( "''", DatabaseDumper::key_literal( '', false, 'utf8mb4' ) );
		self::assertSame( '0x00275c', DatabaseDumper::key_literal( "\x00'\\", true, 'utf8mb4' ) );
		self::assertSame( '_latin1 0x3132', DatabaseDumper::key_literal( '12', false, 'latin1' ), 'clé entière' );
	}

	public function test_instructions_insert_des_vecteurs(): void {
		foreach ( self::section( 'sql_inserts' ) as $cas ) {
			$octets   = static fn ( string $h ): string => (string) hex2bin( $h );
			$colonnes = array_map( $octets, $cas['columns_hex'] );
			$binaire  = array_fill_keys( array_map( $octets, $cas['binary_hex'] ), true );
			$lignes   = array_map(
				static fn ( array $l ): array => array_combine( $colonnes, array_map( static fn ( ?string $v ): ?string => null === $v ? null : (string) hex2bin( $v ), $l ) ),
				$cas['rows_hex']
			);
			self::assertSame( $cas['expected_hex'], bin2hex( DatabaseDumper::insert( $octets( $cas['table_hex'] ), $colonnes, $binaire, $lignes, $cas['charset'] ) ), $cas['name'] );
		}
	}

	public function test_identifiants_des_vecteurs(): void {
		$refuses = 0;
		$ecrits  = 0;
		foreach ( self::section( 'sql_identifiers' ) as $cas ) {
			try {
				$ecrit = DatabaseDumper::ident( (string) hex2bin( $cas['name_hex'] ), $cas['charset'] );
				self::assertFalse( $cas['refused'], $cas['name'] . ' : devait être refusé' );
				self::assertSame( $cas['expected_hex'], bin2hex( $ecrit ), $cas['name'] );
				++$ecrits;
			} catch ( BackupRefused $refus ) {
				self::assertTrue( $cas['refused'], $cas['name'] . ' : refusé à tort' );
				self::assertSame( $cas['error_code'], $refus->reason_code(), $cas['name'] );
				self::assertNull( $refus->retry_after(), $cas['name'] . ' : non réessayable' );
				self::assertStringContainsString( '0x' . $cas['name_hex'], $refus->getMessage(), $cas['name'] . ' : nom affichable' );
				++$refuses;
			}
		}
		self::assertGreaterThan( 0, $ecrits, 'des noms écrits' );
		self::assertGreaterThan( 0, $refuses, 'des noms refusés' );
	}

	public function test_en_tete_du_dump_dans_le_jeu_de_la_connexion(): void {
		foreach ( self::section( 'sql_headers' ) as $cas ) {
			self::assertSame( $cas['expected_hex'], bin2hex( DatabaseDumper::header( $cas['charset'] ) ), $cas['charset'] );
		}
		$wpdb = new FakeDumpWpdb();
		self::assertSame( 'utf8mb4', DatabaseDumper::charset_of( $wpdb ) );
		$wpdb->charset = 'latin1';
		self::assertSame( 'latin1', DatabaseDumper::charset_of( $wpdb ) );
		$wpdb->charset = 'utf8mb4; DROP';
		self::assertSame( 'utf8mb4', DatabaseDumper::charset_of( $wpdb ), 'valeur hors du motif : repli' );
	}

	public function test_jeu_de_la_session_des_vecteurs(): void {
		foreach ( self::section( 'sql_sessions' ) as $cas ) {
			self::assertSame( ! $cas['refused'], DatabaseDumper::session_matches( $cas['charset'], $cas['character_set_client'], $cas['character_set_connection'], $cas['character_set_results'] ), $cas['name'] );
			if ( $cas['refused'] ) {
				self::assertSame( BackupRefused::DB_CHARSET_MISMATCH, $cas['error_code'], $cas['name'] );
			}
		}

		$wpdb          = self::faux_du_site();
		$wpdb->session = [ 'latin1', 'latin1', 'latin1' ];
		$refus         = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_CHARSET_MISMATCH, $refus->reason_code(), 'DB_CHARSET absent sous MariaDB : session latin1, en-tête utf8mb4' );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertStringContainsString( 'utf8mb4', $refus->getMessage() );
		self::assertStringContainsString( 'latin1, latin1, latin1', $refus->getMessage() );
		self::assertSame( [ FakeDumpWpdb::SESSION_QUERY ], $wpdb->queries, 'aucune lecture avant le contrôle de la session' );
	}

	public function test_nom_perdu_refuse_avant_toute_lecture(): void {
		$wpdb                       = new FakeDumpWpdb();
		$wpdb->charset              = 'cp932';
		$wpdb->session              = [ 'cp932', 'cp932', 'cp932' ];
		$wpdb->tables['wp_options'] = self::table();
		$wpdb->tables['wp_caf?']    = self::table();
		$wpdb->lost                 = [ [ bin2hex( 'wp_café' ), bin2hex( 'i' ), 'wp_caf?' ] ];

		$refus = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_NAME_UNSUPPORTED, $refus->reason_code() );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertStringContainsString( 'wp_caf' . chr( 92 ) . 'u00e9.i', $refus->getMessage(), 'nom affichable (wp_caf, barre oblique inverse, u00e9), relu en UTF-8 par HEX()' );
		self::assertCount( 3, $wpdb->queries, 'session, liste, détection : rien d\'autre' );
		self::assertSame( FakeDumpWpdb::SESSION_QUERY, $wpdb->queries[0] );
		self::assertStringStartsWith( 'SELECT TABLE_NAME, AVG_ROW_LENGTH FROM information_schema.TABLES', $wpdb->queries[1] );
		self::assertStringStartsWith( 'SELECT HEX(CONVERT(TABLE_NAME USING utf8mb4))', $wpdb->queries[2] );
		foreach ( [ 'SHOW FULL COLUMNS', 'SHOW CREATE TABLE', FakeDumpWpdb::ROWS_START ] as $lecture ) {
			self::assertSame( 0, self::compte( $wpdb->queries, $lecture ), $lecture . ' : aucune lecture du contenu' );
		}
	}

	public function test_noms_perdus_limites_aux_tables_copiees(): void {
		$wpdb          = new FakeDumpWpdb();
		$wpdb->charset = 'cp932';
		$wpdb->session = [ 'cp932', 'cp932', 'cp932' ];
		foreach ( [ 'wp_options', 'wp_x_options', 'wp_x_usermeta', 'wp_x_caf?' ] as $nom ) {
			$wpdb->tables[ $nom ] = self::table();
		}
		$wpdb->lost = [
			[ bin2hex( 'wp_x_café' ), bin2hex( 'c_é' ), 'wp_x_caf?' ],
			[ bin2hex( 'wp_x_café' ), bin2hex( 'i' ), 'wp_x_caf?' ],
		];

		self::assertSame( [ 'wp_options' ], ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ), 'wp_x_ = installation voisine (relevé du 07/11) : jamais de refus' );

		$wpdb->tables['wp_vue_?'] = [
			'columns' => [ 'id' => 'int' ],
			'pk'      => [],
			'rows'    => [],
			'view'    => true,
		];
		$wpdb->lost[]             = [ bin2hex( 'wp_vue_é' ), bin2hex( 'é' ), 'wp_vue_?' ];

		self::assertSame( [ 'wp_options' ], ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ), 'vue non copiée : jamais de refus' );
	}

	public function test_motif_like_sans_barre_oblique_inverse(): void {
		$attendus = [
			'wp_'    => "'wp!_%' ESCAPE '!'",
			''       => "'%' ESCAPE '!'",
			'Wp_2_x' => "'Wp!_2!_x%' ESCAPE '!'",
		];
		foreach ( $attendus as $prefixe => $motif ) {
			self::assertSame( $motif, DatabaseDumper::like_prefix( (string) $prefixe ), (string) $prefixe );
			self::assertStringNotContainsString( '\\', DatabaseDumper::like_prefix( (string) $prefixe ) );
		}

		foreach ( [ 'wp-', "wp'", 'wp\\', 'wp%', 'wp!', "wp\x00" ] as $prefixe ) {
			$refus = self::refus( static fn () => DatabaseDumper::like_prefix( $prefixe ) );
			self::assertSame( BackupRefused::DB_TABLES_NOT_FOUND, $refus->reason_code(), bin2hex( $prefixe ) );
			self::assertNull( $refus->retry_after(), 'non réessayable' );

			$wpdb  = self::faux_du_site();
			$refus = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( $prefixe ) );
			self::assertSame( BackupRefused::DB_TABLES_NOT_FOUND, $refus->reason_code(), bin2hex( $prefixe ) );
			self::assertSame( [], $wpdb->queries, bin2hex( $prefixe ) . ' : refus avant toute requête' );
		}
		self::assertStringContainsString( 'wp\u0000options', self::refus( static fn () => DatabaseDumper::like_prefix( "wp\x00" ) )->getMessage(), 'nom affichable' );
	}

	public function test_liste_sans_table_des_reglages_refusee(): void {
		$wpdb                        = new FakeDumpWpdb();
		$wpdb->tables['wp_posts']    = self::table();
		$wpdb->tables['wp_usermeta'] = self::table();

		$refus = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_TABLES_NOT_FOUND, $refus->reason_code() );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertStringContainsString( 'wp_options', $refus->getMessage() );
		self::assertSame( 0, self::compte( $wpdb->queries, 'SELECT HEX(' ), 'aucune détection des noms perdus' );

		$vide  = new FakeDumpWpdb();
		$refus = self::refus( static fn () => ( new DatabaseDumper( $vide ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_TABLES_NOT_FOUND, $refus->reason_code(), 'liste vide (MySQL 8.4, NO_BACKSLASH_ESCAPES et l\'ancien motif) : jamais de dump vide réussi' );
		self::assertCount( 2, $vide->queries, 'session et liste seulement' );
	}

	public function test_reconnexion_pendant_la_liste_controlee(): void {
		$wpdb = self::faux_du_site();
		$wpdb->reconnect_on( 'information_schema.TABLES', [ 'latin1', 'latin1', 'latin1' ] );

		$refus = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_CHARSET_MISMATCH, $refus->reason_code(), 'liste lue dans la nouvelle session latin1 : jamais employée' );
		self::assertNull( $refus->retry_after(), 'non réessayable' );
		self::assertSame( 2, self::compte( $wpdb->queries, FakeDumpWpdb::SESSION_QUERY ), 'session contrôlée de nouveau après la reconnexion' );
		self::assertSame( 0, self::compte( $wpdb->queries, 'SELECT HEX(' ), 'rien lu après le refus' );

		$wpdb = self::faux_du_site();
		$wpdb->reconnect_on( 'information_schema.TABLES', [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ] );

		self::assertSame( [ 'wp_options', 'wp_posts' ], ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );
		self::assertSame( 2, self::compte( $wpdb->queries, FakeDumpWpdb::SESSION_QUERY ), 'session contrôlée de nouveau après la reconnexion' );
	}

	public function test_reconnexion_pendant_la_detection_arrete_la_tranche(): void {
		$wpdb = self::faux_du_site();
		$wpdb->reconnect_on( 'information_schema.COLUMNS', [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ] );

		$refus = self::refus( static fn () => ( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ) );

		self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code(), 'connexion rouverte pendant le contrôle lui-même (§ 4, « Reconnexion silencieuse »)' );
		self::assertNotNull( $refus->retry_after(), 'réessayable' );
	}

	public function test_tables_du_prefixe_exact_sans_installation_voisine_ni_vue(): void {
		$wpdb = new FakeDumpWpdb();
		foreach ( [ 'wp_options', 'wp_posts', 'wp_usermeta', 'wp_2_options', 'wp_2_posts', 'wp_shop_orders', 'wp_xxx_options', 'wp_xxx_usermeta', 'wp_xxx_posts', 'wp_new_site_options', 'wp_new_site_usermeta', 'wp_new_site_posts', 'wpzoptions', 'autre_options' ] as $nom ) {
			$wpdb->tables[ $nom ] = [
				'columns' => [ 'id' => 'bigint(20)' ],
				'pk'      => [ 'id' ],
				'rows'    => [],
			];
		}
		$wpdb->tables['wp_vue'] = [
			'columns' => [ 'id' => 'int' ],
			'pk'      => [],
			'rows'    => [],
			'view'    => true,
		];

		self::assertSame(
			[ 'wp_2_options', 'wp_2_posts', 'wp_options', 'wp_posts', 'wp_shop_orders', 'wp_usermeta' ],
			( new DatabaseDumper( $wpdb ) )->tables( 'wp_' ),
			'wp_xxx_* et wp_new_site_* (options et usermeta, infixe avec « _ » compris) = autres installations ; wpzoptions ne passe pas par le motif échappé par ! ; vue exclue'
		);
		self::assertSame( [ 'wp_new_site_', 'wp_xxx_' ], DatabaseDumper::foreign_prefixes( 'wp_', [ 'wp_options', 'wp_2_options', 'wp_new_site_options', 'wp_new_site_usermeta', 'wp_xxx_options', 'wp_xxx_usermeta' ] ) );
	}
}
