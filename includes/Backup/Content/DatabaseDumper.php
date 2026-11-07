<?php
/**
 * Dump de la base du site pour les sauvegardes (docs/backup-payload.md § 4) : tables du préfixe
 * exact, sans celles d'une installation voisine ni les vues ; `SHOW CREATE TABLE` ; lignes par lots
 * ordonnés par clé primaire (repli LIMIT/OFFSET pour une table sans clé : une ligne ajoutée ou
 * retirée pendant le dump peut alors être vue deux fois ou pas du tout, limite écrite) ; colonnes
 * binaires en hexadécimal ; lignes des options du module exclues. Reprenable par curseur, borné par
 * l'appelant ($stop). Aucune écriture dans la base.
 *
 * Avant toute lecture, le jeu de caractères de la session est vérifié, et de nouveau si la connexion
 * a été rouverte ; les noms s'écrivent entre accents graves selon ce jeu (ident()), jamais par
 * $wpdb->prepare() ; aucune requête exécutée sur le site ne dépend de \ ni du sql_mode (motifs
 * LIKE … ESCAPE '!', valeurs de la clé de reprise en hexadécimal).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

use G2RD\Connector\Backup\Commands\BackupRefused;

/**
 * @phpstan-type Cursor array{table: int, after_hex: list<string>|null, offset: int, started: bool, header: bool, rows: int, create_sha256: string|null, limit: int, charset: string}
 * @phpstan-type Meta array{columns: list<string>, binary: array<string, bool>, pk: list<string>, avg_row: int}
 */
final class DatabaseDumper {

	public const FOOTER = "SET FOREIGN_KEY_CHECKS=1;\n";

	/** Lignes d'un lot, au plus ; réduites par la taille (BATCH_BYTES). */
	public const BATCH_ROWS = 200;

	/** Taille visée d'un lot lu en une requête (octets des valeurs) : mémoire bornée sous 128 Mo. */
	public const BATCH_BYTES = 8388608;

	private const DEFAULT_CHARSET = 'utf8mb4';

	private const ESCAPES = [
		'\\'   => '\\\\',
		"\0"   => '\\0',
		"\n"   => '\\n',
		"\r"   => '\\r',
		"'"    => "\\'",
		'"'    => '\\"',
		"\x1a" => '\\Z',
	];

	/** Jeux où 0x5C et 0x60 peuvent être le second octet d'un caractère (docs/backup-payload.md § 4). */
	private const HEX_CHARSETS = [ 'big5', 'cp932', 'gbk', 'sjis', 'gb18030' ];

	/**
	 * Forme des caractères de ces jeux (tableau du § 4, tables ismbchar de MySQL) :
	 * [ octets d'un caractère d'un octet, octets de tête, seconds octets, caractères de quatre octets ].
	 */
	private const SHAPES = [
		'big5'    => [ [ [ 0x00, 0x7F ] ], [ [ 0xA1, 0xF9 ] ], [ [ 0x40, 0x7E ], [ 0xA1, 0xFE ] ], false ],
		'cp932'   => [ [ [ 0x00, 0x7F ], [ 0xA1, 0xDF ] ], [ [ 0x81, 0x9F ], [ 0xE0, 0xFC ] ], [ [ 0x40, 0x7E ], [ 0x80, 0xFC ] ], false ],
		'sjis'    => [ [ [ 0x00, 0x7F ], [ 0xA1, 0xDF ] ], [ [ 0x81, 0x9F ], [ 0xE0, 0xFC ] ], [ [ 0x40, 0x7E ], [ 0x80, 0xFC ] ], false ],
		'gbk'     => [ [ [ 0x00, 0x7F ] ], [ [ 0x81, 0xFE ] ], [ [ 0x40, 0x7E ], [ 0x80, 0xFE ] ], false ],
		'gb18030' => [ [ [ 0x00, 0x7F ] ], [ [ 0x81, 0xFE ] ], [ [ 0x40, 0x7E ], [ 0x80, 0xFE ] ], true ],
	];

	/** utf8 et utf8mb3 : un même jeu (MySQL 5.7.40 rend utf8 ; MySQL 8.4.3 et MariaDB 10.6.5, relevé du 07/11 : utf8mb3). */
	private const SAME_CHARSETS = [ 'utf8' => 'utf8mb3' ];

	/** Second et quatrième octets d'un caractère de quatre octets de gb18030. */
	private const DIGITS = [ [ 0x30, 0x39 ] ];

	/** Préfixe admis avant d'écrire un motif LIKE : les seuls caractères que WordPress accepte (wpdb::set_prefix()), vide compris. */
	private const PREFIX_PATTERN = '/\A[A-Za-z0-9_]*\z/';

	/** Types copiés d'information_schema.TABLES : SYSTEM VERSIONED = table à versions système de MariaDB (BASE TABLE pour SHOW FULL TABLES). */
	private const TABLE_TYPES = "('BASE TABLE', 'SYSTEM VERSIONED')";

	/** @var \wpdb */
	private object $wpdb;

	/** Jeu contrôlé pour cette instance (une instance par requête ou par tranche), ou null. */
	private ?string $guarded = null;

	/** Jeu de la dernière session contrôlée par check_session(), ou null. */
	private ?string $session_charset = null;

	/** Identité de la connexion contrôlée (connection_identity()), ou null avant tout contrôle. */
	private ?string $identity = null;

	/** @var array{0: string, 1: string, 2: list<string>}|null Arguments du dernier contrôle complet. */
	private ?array $guard_for = null;

	/**
	 * @param \wpdb $wpdb $wpdb (un double en test).
	 */
	public function __construct( object $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * En-tête du dump : SET NAMES du jeu de caractères de la connexion (spécification § 6.3), pour que
	 * les octets lus soient ceux que le dump annonce.
	 */
	public static function header( string $charset ): string {
		return "-- G2RD backup dump v1\n/*!40101 SET NAMES " . $charset . " */;\nSET FOREIGN_KEY_CHECKS=0;\n";
	}

	/**
	 * Jeu de caractères de la connexion ($wpdb->charset, réglé par DB_CHARSET), contrôlé par
	 * `^[a-z0-9]+$` ; utf8mb4 sinon.
	 *
	 * @param \wpdb $wpdb $wpdb (un double en test).
	 */
	public static function charset_of( object $wpdb ): string {
		$charset = property_exists( $wpdb, 'charset' ) && is_string( $wpdb->charset ) ? strtolower( $wpdb->charset ) : '';

		return 1 === preg_match( '/\A[a-z0-9]{1,32}\z/', $charset ) ? $charset : self::DEFAULT_CHARSET;
	}

	/** Les trois variables de jeu de la session valent $charset (vecteurs sql_sessions). */
	public static function session_matches( string $charset, ?string $client, ?string $connection, ?string $results ): bool {
		$expected = self::SAME_CHARSETS[ $charset ] ?? $charset;
		foreach ( [ $client, $connection, $results ] as $value ) {
			if ( null === $value || ( self::SAME_CHARSETS[ $value ] ?? $value ) !== $expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Motif LIKE du préfixe, sans \ (docs/backup-payload.md § 4, « Motifs LIKE sans \ ») : avec
	 * NO_BACKSLASH_ESCAPES, que wpdb::set_sql_mode() garde, \ n'échappe rien dans LIKE.
	 *
	 * @throws BackupRefused DB_TABLES_NOT_FOUND (non réessayable) : préfixe hors de [A-Za-z0-9_].
	 */
	public static function like_prefix( string $prefix ): string {
		if ( 1 !== preg_match( self::PREFIX_PATTERN, $prefix ) ) {
			throw new BackupRefused( BackupRefused::DB_TABLES_NOT_FOUND, null, self::printable( $prefix . 'options' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, nom échappé).
		}
		$escaped = strtr(
			$prefix,
			[
				'!' => '!!',
				'_' => '!_',
				'%' => '!%',
			]
		);

		return "'" . $escaped . "%' ESCAPE '!'";
	}

	/**
	 * Tables copiées, triées octet par octet (docs/backup-payload.md § 4, « Tables copiées ») :
	 * motif du préfixe, session, liste, filtres, filet <préfixe>options, noms perdus des tables de la
	 * liste, fin du contrôle.
	 *
	 * @return list<string>
	 * @throws BackupRefused DB_TABLES_NOT_FOUND, DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	public function tables( string $prefix ): array {
		$like            = self::like_prefix( $prefix );
		$charset         = self::charset_of( $this->wpdb );
		$this->guarded   = null;
		$this->guard_for = null;
		$this->check_session( $charset );
		$names = [];
		foreach ( $this->rows( self::tables_query( $like ), $prefix . '*' ) as $row ) {
			$name = (string) ( array_values( $row )[0] ?? '' );
			// Second filet : la collation d'information_schema peut ignorer la casse.
			if ( str_starts_with( $name, $prefix ) ) {
				$names[] = $name;
			}
		}
		$foreign = self::foreign_prefixes( $prefix, $names );
		$names   = array_values(
			array_filter(
				$names,
				static function ( string $name ) use ( $foreign ): bool {
					foreach ( $foreign as $other ) {
						if ( str_starts_with( $name, $other ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
		sort( $names, SORT_STRING );
		if ( ! in_array( $prefix . 'options', $names, true ) ) {
			throw new BackupRefused( BackupRefused::DB_TABLES_NOT_FOUND, null, $prefix . 'options' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, préfixe vérifié par like_prefix()).
		}
		$this->check_names( $charset, $prefix, $names );
		$this->end_guard( $charset, $prefix, $names );

		return $names;
	}

	/**
	 * Préfixes d'une autre installation qui partage la base et le préfixe : infixe `X_` quelconque
	 * (`_` compris : `new_site_`) tel que `<préfixe>X_options` et `<préfixe>X_usermeta` existent
	 * (les blogs d'un réseau n'ont pas de `usermeta`). Chaque candidat se vérifie par la présence
	 * exacte de sa table `usermeta`, jamais par un motif.
	 *
	 * @param list<string> $names
	 * @return list<string> Triés octet par octet.
	 */
	public static function foreign_prefixes( string $prefix, array $names ): array {
		$present = array_flip( $names );
		$foreign = [];
		$suffix  = '_options';
		foreach ( $names as $name ) {
			if ( strlen( $name ) <= strlen( $prefix ) + strlen( $suffix ) || ! str_starts_with( $name, $prefix ) || ! str_ends_with( $name, $suffix ) ) {
				continue;
			}
			$infix = substr( $name, strlen( $prefix ), - ( strlen( $suffix ) - 1 ) );
			if ( isset( $present[ $prefix . $infix . 'usermeta' ] ) ) {
				$foreign[] = $prefix . $infix;
			}
		}
		$foreign = array_values( array_unique( $foreign ) );
		sort( $foreign, SORT_STRING );

		return $foreign;
	}

	/**
	 * Valeur d'un INSERT du dump (docs/backup-payload.md § 4, « Valeurs ») : NULL ; '' si vide ;
	 * 0x… si binaire ; _<jeu> 0x… dans les cinq jeux de HEX_CHARSETS ; entre apostrophes, échappée
	 * octet par octet, sinon. Ne sert qu'aux INSERT du dump, exécutés à la restauration qui fixe le
	 * sql_mode (§ 7) ; jamais dans une requête exécutée sur le site (key_literal()).
	 *
	 * @param string $charset Jeu de l'en-tête du dump (motif de charset_of()).
	 */
	public static function quote( ?string $value, bool $binary, string $charset ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( '' === $value ) {
			return "''";
		}
		if ( $binary ) {
			return '0x' . bin2hex( $value );
		}
		if ( in_array( $charset, self::HEX_CHARSETS, true ) ) {
			return '_' . $charset . ' 0x' . bin2hex( $value );
		}
		return "'" . strtr( $value, self::ESCAPES ) . "'";
	}

	/**
	 * Valeur de la clé dans WHERE (clé) > (…) de la reprise (docs/backup-payload.md § 4, « Valeurs
	 * de la clé de reprise ») : '' si vide, 0x… si binaire, _<jeu> 0x… sinon, quel que soit le jeu.
	 * Jamais entre apostrophes ni avec un \ : la requête s'exécute sur le site, dans un sql_mode que
	 * rien ne contrôle (NO_BACKSLASH_ESCAPES). Une clé primaire n'a pas de valeur nulle.
	 *
	 * @param string $charset Jeu du curseur (motif de charset_of()).
	 */
	public static function key_literal( string $value, bool $binary, string $charset ): string {
		if ( '' === $value ) {
			return "''";
		}
		if ( $binary ) {
			return '0x' . bin2hex( $value );
		}
		return '_' . $charset . ' 0x' . bin2hex( $value );
	}

	/**
	 * Identifiant entre accents graves ; $name = octets lus par la connexion dans $charset.
	 * Dans les cinq jeux de HEX_CHARSETS, seul un accent grave qui est un caractère à lui seul est doublé.
	 *
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable) : nom qui ne se découpe pas en caractères du jeu.
	 */
	public static function ident( string $name, string $charset ): string {
		if ( ! isset( self::SHAPES[ $charset ] ) ) {
			return '`' . str_replace( '`', '``', $name ) . '`';
		}
		[ $single, $lead, $trail, $four ] = self::SHAPES[ $charset ];
		$out                              = '`';
		$n                                = strlen( $name );
		for ( $i = 0; $i < $n; ) {
			$byte = ord( $name[ $i ] );
			if ( self::in_ranges( $single, $byte ) ) {
				$out .= 0x60 === $byte ? '``' : $name[ $i ];
				++$i;
			} elseif ( self::in_ranges( $lead, $byte ) && $i + 1 < $n && self::in_ranges( $trail, ord( $name[ $i + 1 ] ) ) ) {
				$out .= substr( $name, $i, 2 );
				$i   += 2;
			} elseif ( $four && self::in_ranges( $lead, $byte ) && $i + 3 < $n && self::in_ranges( self::DIGITS, ord( $name[ $i + 1 ] ) )
				&& self::in_ranges( $lead, ord( $name[ $i + 2 ] ) ) && self::in_ranges( self::DIGITS, ord( $name[ $i + 3 ] ) ) ) {
				$out .= substr( $name, $i, 4 );
				$i   += 4;
			} else {
				// Octet hors de la forme du jeu, ou caractère tronqué : un octet de tête isolé en fin de
				// nom avalerait l'accent grave fermant (0x60 est un second octet valide des cinq jeux).
				throw new BackupRefused( BackupRefused::DB_NAME_UNSUPPORTED, null, '0x' . bin2hex( $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, nom en hexadécimal).
			}
		}
		return $out . '`';
	}

	/**
	 * Une instruction INSERT de lignes de $table ; noms et valeurs = octets lus dans $charset.
	 *
	 * @param list<string>                     $columns
	 * @param array<string, bool>              $binary
	 * @param list<array<string, string|null>> $rows
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable) : nom mal formé dans $charset.
	 */
	public static function insert( string $table, array $columns, array $binary, array $rows, string $charset ): string {
		$tuples = [];
		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $columns as $column ) {
				$values[] = self::quote( $row[ $column ] ?? null, $binary[ $column ] ?? false, $charset );
			}
			$tuples[] = '(' . implode( ',', $values ) . ')';
		}

		return 'INSERT INTO ' . self::ident( $table, $charset ) . ' (' . implode( ',', array_map( static fn ( string $c ): string => self::ident( $c, $charset ), $columns ) ) . ") VALUES\n" . implode( ",\n", $tuples ) . ";\n";
	}

	/** @param list<array{0: int, 1: int}> $ranges */
	private static function in_ranges( array $ranges, int $byte ): bool {
		foreach ( $ranges as [ $low, $high ] ) {
			if ( $byte >= $low && $byte <= $high ) {
				return true;
			}
		}
		return false;
	}

	/** Tables du préfixe et taille moyenne de leurs lignes (§ 4, « Tables copiées ») ; $like vient de like_prefix(). */
	private static function tables_query( string $like ): string {
		return 'SELECT TABLE_NAME, AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
			. ' AND TABLE_TYPE IN ' . self::TABLE_TYPES . ' AND TABLE_NAME LIKE ' . $like;
	}

	/** Détection des noms perdus (§ 4) ; $charset suit le motif de charset_of(), $like vient de like_prefix(). */
	private static function lost_names_query( string $charset, string $like ): string {
		return 'SELECT HEX(CONVERT(TABLE_NAME USING utf8mb4)), HEX(CONVERT(COLUMN_NAME USING utf8mb4)), TABLE_NAME FROM information_schema.COLUMNS'
			. ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ' . $like
			. ' AND (CONVERT(CONVERT(TABLE_NAME USING ' . $charset . ') USING utf8mb4) COLLATE utf8mb4_bin <> CONVERT(TABLE_NAME USING utf8mb4) COLLATE utf8mb4_bin'
			. ' OR CONVERT(CONVERT(COLUMN_NAME USING ' . $charset . ') USING utf8mb4) COLLATE utf8mb4_bin <> CONVERT(COLUMN_NAME USING utf8mb4) COLLATE utf8mb4_bin)'
			. ' AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ' . self::TABLE_TYPES . ')'
			. ' ORDER BY 1, 2';
	}

	/**
	 * Identité de la connexion (§ 4, « Reconnexion silencieuse ») : l'objet de $wpdb->dbh (un nouveau
	 * à chaque connexion) et le numéro de connexion du serveur (un identifiant d'objet libéré se
	 * réutilise). Lue juste après une requête réussie : la connexion est alors ouverte.
	 */
	private function connection_identity(): string {
		// wpdb::$dbh est protégée : lue par wpdb::__get() ; un accès direct est refusé par PHPStan (essai du 07/11).
		$dbh = $this->wpdb->__get( 'dbh' );
		if ( ! is_object( $dbh ) ) {
			return '';
		}
		return spl_object_id( $dbh ) . ':' . ( $dbh instanceof \mysqli ? (string) $dbh->thread_id : '' );
	}

	/**
	 * Variables de jeu de la session ; retient l'identité de la connexion qu'il vient de vérifier.
	 *
	 * @throws BackupRefused DB_CHARSET_MISMATCH (non réessayable), DB_READ_FAILED.
	 */
	private function check_session( string $charset ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- requête constante : variables de jeu de la session, avant toute lecture du dump.
		$row = $this->wpdb->get_row( 'SELECT @@character_set_client, @@character_set_connection, @@character_set_results', ARRAY_N );
		$this->fail_on_error( 'information_schema' );
		$this->identity        = $this->connection_identity();
		$this->session_charset = $charset;
		$read                  = array_map(
			static fn ( $v ): ?string => null === $v ? null : (string) $v,
			array_pad( is_array( $row ) ? array_values( $row ) : [], 3, null )
		);
		if ( ! self::session_matches( $charset, $read[0], $read[1], $read[2] ) ) {
			$seen = implode( ', ', array_map( static fn ( ?string $v ): string => $v ?? 'NULL', $read ) );
			throw new BackupRefused( BackupRefused::DB_CHARSET_MISMATCH, null, $charset . '|' . $seen ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, noms de jeux).
		}
	}

	/**
	 * Noms perdus des seules tables copiées (§ 4, « Noms perdus ») : une ligne ne compte que si son
	 * troisième champ (TABLE_NAME lu dans $charset, donc les octets de la liste) est dans $tables.
	 *
	 * @param list<string> $tables Tables copiées : celles de tables(), ou db.tables_hex relue.
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable), DB_READ_FAILED.
	 */
	private function check_names( string $charset, string $prefix, array $tables ): void {
		$copied = array_flip( $tables );
		foreach ( $this->read( self::lost_names_query( $charset, self::like_prefix( $prefix ) ), 'information_schema' ) as $row ) {
			$values = array_values( $row );
			if ( isset( $copied[ (string) ( $values[2] ?? '' ) ] ) ) {
				$name = self::printable( (string) hex2bin( (string) ( $values[0] ?? '' ) ) ) . '.' . self::printable( (string) hex2bin( (string) ( $values[1] ?? '' ) ) );
				throw new BackupRefused( BackupRefused::DB_NAME_UNSUPPORTED, null, $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, nom échappé).
			}
		}
	}

	/**
	 * Fin d'un contrôle complet : la connexion ne doit pas avoir changé depuis check_session().
	 *
	 * @param list<string> $tables
	 * @throws BackupRefused DB_READ_FAILED (réessayable) : connexion rouverte pendant le contrôle.
	 */
	private function end_guard( string $charset, string $prefix, array $tables ): void {
		if ( $this->connection_identity() !== $this->identity ) {
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, 'information_schema' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$this->guarded   = $charset;
		$this->guard_for = [ $charset, $prefix, $tables ];
	}

	/**
	 * Avant toute lecture d'une tranche : jeu de la session, puis noms perdus des tables copiées
	 * (docs/backup-payload.md § 4). Refait si la connexion a changé.
	 *
	 * @param list<string> $tables Tables copiées (db.tables_hex relue).
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function guard( string $charset, string $prefix, array $tables ): void {
		if ( $this->guarded === $charset && null !== $this->identity && $this->connection_identity() === $this->identity ) {
			return;
		}
		$this->guarded   = null;
		$this->guard_for = null;
		$this->check_session( $charset );
		$this->check_names( $charset, $prefix, $tables );
		$this->end_guard( $charset, $prefix, $tables );
	}

	/**
	 * Juste après une requête du dump, avant d'en employer le résultat (§ 4, « Reconnexion
	 * silencieuse ») : la reconnexion de wpdb::query() rejoue la requête dans une nouvelle session,
	 * qui doit être contrôlée avant que le moindre octet en soit écrit.
	 *
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function recheck(): void {
		if ( null === $this->identity || $this->connection_identity() === $this->identity ) {
			return;
		}
		if ( null === $this->guard_for ) {
			// Dans tables(), avant que la liste soit connue : la session seulement.
			$this->check_session( (string) $this->session_charset );
			return;
		}
		[ $charset, $prefix, $tables ] = $this->guard_for;
		$this->guarded                 = null;
		$this->guard( $charset, $prefix, $tables );
	}

	/**
	 * Forme affichable d'un nom en UTF-8 (relu par HEX() d'information_schema) : ASCII imprimable tel
	 * quel, tout autre caractère en séquence d'échappement JSON (é s'écrit u00e9 précédé d'une barre
	 * oblique inverse, comme json_encode()) ; 0x… si le nom
	 * n'est pas de l'UTF-8 valide.
	 */
	private static function printable( string $utf8 ): string {
		$json = json_encode( $utf8, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() réécrirait un nom invalide au lieu d'échouer.
		return false === $json ? '0x' . bin2hex( $utf8 ) : substr( $json, 1, -1 );
	}

	/**
	 * Lignes d'une requête bâtie par l'appelant, sans contrôle de la connexion (requêtes du contrôle).
	 *
	 * @return list<array<string, mixed>>
	 * @throws BackupRefused DB_READ_FAILED.
	 */
	private function read( string $sql, string $table ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- requête bâtie par l'appelant : identifiants par ident() (§ 4), valeurs de la clé par key_literal() (hexadécimal, sûr quel que soit le sql_mode du site), entiers convertis, motif LIKE par like_prefix() (préfixe vérifié [A-Za-z0-9_], ESCAPE '!', aucun \), jeu au motif de charset_of() ; lecture de la base pour la sauvegarde, rien à mettre en cache.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		$this->fail_on_error( $table );

		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * Lignes d'une requête du dump : read(), puis recheck() avant que l'appelant ne les emploie.
	 *
	 * @return list<array<string, mixed>>
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function rows( string $sql, string $table ): array {
		$rows = $this->read( $sql, $table );
		$this->recheck();

		return $rows;
	}

	/**
	 * @throws BackupRefused DB_READ_FAILED si la dernière requête a échoué.
	 */
	private function fail_on_error( string $table ): void {
		if ( '' !== (string) $this->wpdb->last_error ) {
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table, jamais affiché sans échappement par la plateforme).
		}
	}
}
