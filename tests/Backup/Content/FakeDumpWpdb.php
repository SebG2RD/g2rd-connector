<?php
/**
 * Faux $wpdb du dump : des tables en mémoire (colonnes et types, clé primaire, lignes), une session
 * (les trois variables de jeu), des noms perdus, une connexion qui peut être rouverte, et les seules
 * requêtes de Backup\Content\DatabaseDumper (docs/backup-payload.md § 4), reconnues à leur texte.
 * Toute autre requête lève, de même qu'un motif LIKE qui contient \ ou n'est pas celui du préfixe,
 * un identifiant passé par %i, un motif échappé par esc_like(), une valeur de reprise qui n'est
 * pas écrite comme le fait key_literal(), et SELECT * (une colonne INVISIBLE n'y est pas rendue :
 * les lignes se lisent par la liste exacte des colonnes de la table) : un test ne valide jamais une
 * requête que la vraie base lirait autrement, ni une écriture qui dépend du sql_mode du site.
 * Comme la vraie base (essai du 08/11/2026 sur MariaDB 10.11.19), ORDER BY trie une colonne ENUM
 * par le rang de sa valeur dans la définition et un SET par sa valeur numérique, alors que
 * WHERE (clé) > (valeurs) les compare comme du texte.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\DatabaseDumper;

final class FakeDumpWpdb {

	/** Requête des variables de jeu de la session, au texte exact (docs/backup-payload.md § 4). */
	public const SESSION_QUERY = 'SELECT @@character_set_client, @@character_set_connection, @@character_set_results';

	/** Début de la liste des tables et de leur taille moyenne (§ 4, « Tables copiées »). */
	private const LIST_START = 'SELECT TABLE_NAME, AVG_ROW_LENGTH FROM information_schema.TABLES';

	/** Début de la détection des noms perdus (§ 4, « Noms perdus »). */
	private const LOST_START = 'SELECT HEX(CONVERT(TABLE_NAME USING utf8mb4))';

	/** Début d'une lecture de lignes : la première colonne nommée entre accents graves (jamais SELECT *). */
	public const ROWS_START = 'SELECT `';

	public string $options = 'wp_options';

	public string $base_prefix = 'wp_';

	public string $charset = 'utf8mb4';

	public string $last_error = '';

	/**
	 * Noms et valeurs : octets tels que lus dans $charset. invisible : colonnes INVISIBLE (Extra de
	 * SHOW FULL COLUMNS, rendues seulement nommées) ; omitted : colonnes absentes des lignes rendues
	 * même nommées (anomalie simulée) ; where_ignored : la reprise WHERE (clé) > (…) relit depuis le
	 * début (comme `my_row_id` > '' qui compare à 0).
	 *
	 * @var array<string, array{columns: array<string, string>, pk: list<string>, rows: list<array<string, string|null>>, view?: bool, avg_row_length?: int, invisible?: list<string>, omitted?: list<string>, where_ignored?: bool}>
	 */
	public array $tables = [];

	/** @var list<string> Requêtes reçues, dans l'ordre. */
	public array $queries = [];

	/** Nom de table dont la lecture des lignes échoue, ou null. */
	public ?string $fails_on = null;

	/** @var list<string|null> Variables de jeu de la session : client, connexion, résultats. */
	public array $session = [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ];

	/**
	 * Lignes rendues par la détection des noms perdus : hexadécimal de la table et de la colonne en
	 * UTF-8, puis TABLE_NAME tel que lu dans $charset (les trois champs de la requête).
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	public array $lost = [];

	/** Connexion courante : un nouvel objet à chaque reconnexion, comme wpdb::db_connect(). */
	private object $dbh;

	/** @var list<object> Connexions fermées, gardées pour que leur identifiant ne soit pas repris. */
	private array $closed = [];

	/** @var array{0: string, 1: list<string|null>}|null Reconnexion attendue : texte cherché, session suivante. */
	private ?array $reconnect = null;

	public function __construct() {
		$this->dbh = new \stdClass();
	}

	/**
	 * Comme wpdb::__get() : la connexion protégée se lit par son nom.
	 */
	public function __get( string $name ): mixed {
		if ( 'dbh' === $name ) {
			return $this->dbh;
		}
		throw new \LogicException( 'Propriété non prévue par le faux $wpdb du dump : ' . $name );
	}

	/**
	 * À la première requête dont le texte contient $needle, la connexion est rouverte (nouvel objet)
	 * dans la session $session, avant que le résultat de cette requête soit rendu : la reconnexion de
	 * wpdb::query() rejoue la requête dans la nouvelle session.
	 *
	 * @param list<string|null> $session
	 */
	public function reconnect_on( string $needle, array $session ): void {
		$this->reconnect = [ $needle, $session ];
	}

	/**
	 * Le dump n'échappe aucun motif par \ : avec NO_BACKSLASH_ESCAPES, \ n'échappe rien (§ 4).
	 */
	public function esc_like( string $text ): string {
		throw new \LogicException( 'esc_like() appelé par le dump (motif échappé par \\, muet sous NO_BACKSLASH_ESCAPES) : ' . $text );
	}

	/**
	 * %s et %d comme wpdb::prepare() ; %i refusé : un identifiant s'écrit par ident() (§ 4).
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( str_contains( $query, '%i' ) ) {
			throw new \LogicException( 'Identifiant passé par %i (docs/backup-payload.md § 4 : ident() seul) : ' . $query );
		}
		$values = array_values( $args );

		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$values ): string {
				$v = array_shift( $values );
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $sql, string $output = 'OBJECT' ): array {
		unset( $output );
		$this->receive( $sql );

		if ( str_starts_with( $sql, self::LIST_START ) ) {
			$motif = $this->motif( $sql );
			$rows  = [];
			foreach ( $this->tables as $name => $table ) {
				if ( empty( $table['view'] ) && self::like( $motif, (string) $name ) ) {
					$rows[] = [
						'TABLE_NAME'     => (string) $name,
						'AVG_ROW_LENGTH' => (string) self::avg_row_length( $table ),
					];
				}
			}
			return $rows;
		}
		if ( str_starts_with( $sql, self::LOST_START ) ) {
			$this->motif( $sql );
			if ( ! str_contains( $sql, 'USING ' . DatabaseDumper::charset_of( $this ) . ')' ) ) {
				throw new \LogicException( 'Détection des noms perdus hors du jeu de la connexion : ' . $sql );
			}
			return array_map(
				static fn ( array $l ): array => [
					'HEX(CONVERT(TABLE_NAME USING utf8mb4))'  => $l[0],
					'HEX(CONVERT(COLUMN_NAME USING utf8mb4))' => $l[1],
					'TABLE_NAME'                              => $l[2],
				],
				$this->lost
			);
		}
		$name = $this->table_named( $sql, 'SHOW FULL COLUMNS FROM ', '' );
		if ( null !== $name ) {
			$rows = [];
			foreach ( $this->tables[ $name ]['columns'] as $field => $type ) {
				$rows[] = [
					'Field' => (string) $field,
					'Type'  => $type,
					'Extra' => in_array( (string) $field, $this->tables[ $name ]['invisible'] ?? [], true ) ? 'INVISIBLE' : '',
				];
			}
			return $rows;
		}
		$name = $this->table_named( $sql, 'SHOW KEYS FROM ', " WHERE Key_name = 'PRIMARY'" );
		if ( null !== $name ) {
			$rows = [];
			foreach ( $this->tables[ $name ]['pk'] as $i => $column ) {
				$rows[] = [
					'Column_name'  => $column,
					'Seq_in_index' => (string) ( $i + 1 ),
				];
			}
			return array_reverse( $rows );
		}
		if ( str_starts_with( $sql, 'SELECT *' ) ) {
			throw new \LogicException( 'SELECT * : une colonne INVISIBLE (dont my_row_id) n\'y est pas rendue, les colonnes se nomment : ' . $sql );
		}
		foreach ( $this->tables as $candidate => $table ) {
			$ident = self::ident_or_null( (string) $candidate, $this->charset );
			$list  = $this->column_list( $table['columns'] );
			$start = 'SELECT ' . $list . ' FROM ' . $ident . ' ';
			if ( null !== $ident && null !== $list && str_starts_with( $sql, $start ) ) {
				return $this->select( (string) $candidate, substr( $sql, strlen( $start ) - 1 ) );
			}
		}
		throw new \LogicException( 'Lecture non prévue par le faux $wpdb du dump (colonnes autres que celles de la table, dans leur ordre ?) : ' . $sql );
	}

	/**
	 * Colonnes de la table écrites par ident(), dans leur ordre, séparées par des virgules ; null si
	 * un nom est refusé dans le jeu du faux ou si la table n'a pas de colonne.
	 *
	 * @param array<string, string> $columns
	 */
	private function column_list( array $columns ): ?string {
		$idents = [];
		foreach ( array_keys( $columns ) as $column ) {
			$ident = self::ident_or_null( (string) $column, $this->charset );
			if ( null === $ident ) {
				return null;
			}
			$idents[] = $ident;
		}
		return [] === $idents ? null : implode( ',', $idents );
	}

	/**
	 * Lignes telles que la base les rend pour la liste des colonnes de la table : chaque colonne, dans
	 * l'ordre, sauf celles d'omitted (anomalie simulée).
	 *
	 * @param list<array<string, string|null>> $rows
	 * @return list<array<string, string|null>>
	 */
	private function projected( string $name, array $rows ): array {
		$omitted = $this->tables[ $name ]['omitted'] ?? [];
		$read    = [];
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( array_keys( $this->tables[ $name ]['columns'] ) as $column ) {
				$column = (string) $column;
				if ( in_array( $column, $omitted, true ) ) {
					continue;
				}
				if ( ! array_key_exists( $column, $row ) ) {
					throw new \LogicException( 'Ligne du faux sans la colonne ' . $column . ' de ' . $name );
				}
				$line[ $column ] = $row[ $column ];
			}
			$read[] = $line;
		}
		return $read;
	}

	/**
	 * @return array<int|string, string|null>|null
	 */
	public function get_row( string $sql, string $output = 'OBJECT' ): ?array {
		$this->receive( $sql );
		if ( self::SESSION_QUERY === $sql ) {
			if ( 'ARRAY_N' !== $output ) {
				throw new \LogicException( 'Variables de session lues autrement que par ARRAY_N : ' . $output );
			}
			return $this->session;
		}
		$name = $this->table_named( $sql, 'SHOW CREATE TABLE ', '' );
		if ( null === $name ) {
			throw new \LogicException( 'Lecture non prévue par le faux $wpdb du dump : ' . $sql );
		}
		$columns = array_map( fn ( int|string $c ): string => DatabaseDumper::ident( (string) $c, $this->charset ), array_keys( $this->tables[ $name ]['columns'] ) );

		return [
			'Table'        => $name,
			'Create Table' => 'CREATE TABLE ' . DatabaseDumper::ident( $name, $this->charset ) . ' (' . implode( ',', $columns ) . ')',
		];
	}

	/**
	 * Lignes d'un SELECT <colonnes> FROM : LIMIT … OFFSET … pour une table sans clé primaire, ORDER BY
	 * la clé puis LIMIT sinon, avec WHERE (clé) > (valeurs) pour reprendre, ou OFFSET … après LIMIT
	 * (sans WHERE) ; les valeurs se relisent par l'inverse de key_literal(), et lui seul. Le tri de
	 * ORDER BY et la comparaison de WHERE sont ceux de la vraie base (compare_keys()).
	 *
	 * @param string $rest Texte qui suit le nom de la table (commence par une espace).
	 * @return list<array<string, string|null>>
	 */
	private function select( string $name, string $rest ): array {
		$table = $this->tables[ $name ];
		$pk    = $table['pk'];
		$types = $table['columns'];
		if ( [] === $pk ) {
			if ( 1 !== preg_match( '/\A LIMIT (\d+) OFFSET (\d+)\z/', $rest, $m ) ) {
				throw new \LogicException( 'Lecture d\'une table sans clé primaire hors de LIMIT … OFFSET … : ' . $rest );
			}
			return $this->projected( $name, array_slice( $this->rows_of( $name ), (int) $m[2], (int) $m[1] ) );
		}
		$columns = implode( ',', array_map( fn ( string $c ): string => DatabaseDumper::ident( $c, $this->charset ), $pk ) );
		$order   = ' ORDER BY ' . $columns . ' LIMIT ';
		$where   = ' WHERE (' . $columns . ') > (';
		$after   = null;
		if ( str_starts_with( $rest, $where ) ) {
			$tail = substr( $rest, strlen( $where ) );
			$cut  = strpos( $tail, ')' . $order );
			if ( false === $cut ) {
				throw new \LogicException( 'Reprise sans ORDER BY de la clé : ' . $rest );
			}
			$after = array_map( [ $this, 'key_value' ], explode( ',', substr( $tail, 0, $cut ) ) );
			if ( count( $after ) !== count( $pk ) ) {
				throw new \LogicException( 'Reprise dont le nombre de valeurs n\'est pas celui de la clé : ' . $rest );
			}
			$limit = substr( $tail, $cut + strlen( ')' . $order ) );
		} elseif ( str_starts_with( $rest, $order ) ) {
			$limit = substr( $rest, strlen( $order ) );
		} else {
			throw new \LogicException( 'Lecture d\'une table à clé primaire hors de ORDER BY la clé : ' . $rest );
		}
		$offset = 0;
		if ( null === $after && 1 === preg_match( '/\A([1-9]\d*) OFFSET (\d+)\z/', $limit, $m ) ) {
			$limit  = $m[1];
			$offset = (int) $m[2];
		}
		if ( 1 !== preg_match( '/\A[1-9]\d*\z/', $limit ) ) {
			throw new \LogicException( 'LIMIT illisible : ' . $rest );
		}
		$rows = $this->rows_of( $name );
		usort( $rows, static fn ( array $a, array $b ): int => self::compare_keys( $a, $b, $pk, $types, true ) );
		if ( null !== $after && empty( $table['where_ignored'] ) ) {
			$key  = array_combine( $pk, $after );
			$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => self::compare_keys( $row, $key, $pk, $types, false ) > 0 ) );
		}

		return $this->projected( $name, array_slice( $rows, $offset, (int) $limit ) );
	}

	/**
	 * Lignes de la table, ou aucune et last_error renseignée si sa lecture échoue (fails_on).
	 *
	 * @return list<array<string, string|null>>
	 */
	private function rows_of( string $name ): array {
		if ( $name === $this->fails_on ) {
			$this->last_error = 'Table is marked as crashed';
			return [];
		}
		return $this->tables[ $name ]['rows'];
	}

	/**
	 * Inverse de DatabaseDumper::key_literal() : '' ; 0x suivi d'hexadécimal ; _<jeu du faux> 0x
	 * suivi d'hexadécimal. Toute autre forme lève, chaîne entre apostrophes et NULL compris : une
	 * valeur de reprise écrite par quote() serait la faille du § 4 (NO_BACKSLASH_ESCAPES).
	 */
	private function key_value( string $text ): string {
		if ( "''" === $text ) {
			return '';
		}
		if ( 1 === preg_match( '/\A(?:_' . preg_quote( $this->charset, '/' ) . ' )?0x((?:[0-9a-f]{2})+)\z/', $text, $m ) ) {
			return (string) hex2bin( $m[1] );
		}
		throw new \LogicException( 'Valeur de reprise hors de key_literal() (apostrophes, \\, NULL ou autre jeu) : ' . $text );
	}

	/**
	 * Motif du préfixe lu dans une requête d'information_schema : jamais de \, et toujours
	 * LIKE '<motif>%' ESCAPE '!' du préfixe du faux (§ 4, « Motifs LIKE sans \ »).
	 */
	private function motif( string $sql ): string {
		$like = DatabaseDumper::like_prefix( $this->base_prefix );
		if ( str_contains( $sql, '\\' ) || ! str_contains( $sql, ' LIKE ' . $like ) ) {
			throw new \LogicException( 'Motif LIKE hors de docs/backup-payload.md § 4 (barre oblique inverse, ou pas celui du préfixe) : ' . $sql );
		}

		return substr( $like, 1, - strlen( "' ESCAPE '!'" ) );
	}

	/**
	 * Table du faux dont le nom, écrit par ident() dans le jeu du faux, fait le texte exact
	 * $before . nom . $after ; null sinon.
	 */
	private function table_named( string $sql, string $before, string $after ): ?string {
		foreach ( array_keys( $this->tables ) as $name ) {
			$ident = self::ident_or_null( (string) $name, $this->charset );
			if ( null !== $ident && $before . $ident . $after === $sql ) {
				return (string) $name;
			}
		}
		return null;
	}

	/** Le nom écrit par ident(), ou null s'il est refusé dans ce jeu (le faux peut en contenir). */
	private static function ident_or_null( string $name, string $charset ): ?string {
		try {
			return DatabaseDumper::ident( $name, $charset );
		} catch ( BackupRefused $refus ) {
			unset( $refus );
			return null;
		}
	}

	/**
	 * @param array{columns: array<string, string>, pk: list<string>, rows: list<array<string, string|null>>, view?: bool, avg_row_length?: int, invisible?: list<string>, omitted?: list<string>, where_ignored?: bool} $table
	 */
	private static function avg_row_length( array $table ): int {
		if ( isset( $table['avg_row_length'] ) ) {
			return $table['avg_row_length'];
		}
		if ( [] === $table['rows'] ) {
			return 0;
		}
		$octets = 0;
		foreach ( $table['rows'] as $row ) {
			$octets += array_sum( array_map( static fn ( ?string $v ): int => null === $v ? 0 : strlen( $v ), $row ) );
		}
		return intdiv( $octets, count( $table['rows'] ) );
	}

	/**
	 * Deux clés comparées colonne après colonne (ordre lexicographique, comme une comparaison de
	 * tuples). Une colonne ENUM ou SET se compare par son rang pour ORDER BY ($by_rank vrai), comme du
	 * texte pour WHERE (clé) > (valeurs) ; toute autre colonne, de la même façon des deux côtés.
	 *
	 * @param array<string, mixed>  $a
	 * @param array<string, mixed>  $b
	 * @param list<string>          $pk
	 * @param array<string, string> $types Type de chaque colonne (SHOW FULL COLUMNS).
	 */
	private static function compare_keys( array $a, array $b, array $pk, array $types, bool $by_rank ): int {
		foreach ( $pk as $column ) {
			$x       = (string) $a[ $column ];
			$y       = (string) $b[ $column ];
			$members = self::members( $types[ $column ] ?? '' );
			if ( null !== $members ) {
				$order = $by_rank ? ( self::rank( $members, $x ) <=> self::rank( $members, $y ) ) : strcmp( $x, $y );
			} else {
				$order = is_numeric( $x ) && is_numeric( $y ) ? ( (float) $x <=> (float) $y ) : strcmp( $x, $y );
			}
			if ( 0 !== $order ) {
				return $order;
			}
		}
		return 0;
	}

	/**
	 * Sorte (enum ou set) et membres d'un type ENUM ou SET, dans l'ordre de la définition ; null pour
	 * tout autre type.
	 *
	 * @return array{0: string, 1: list<string>}|null
	 */
	private static function members( string $type ): ?array {
		if ( 1 !== preg_match( '/\A(enum|set)\((.*)\)\z/is', $type, $m ) ) {
			return null;
		}
		preg_match_all( "/'((?:[^']|'')*)'/", $m[2], $values );

		return [ strtolower( $m[1] ), array_map( static fn ( string $v ): string => str_replace( "''", "'", $v ), $values[1] ) ];
	}

	/**
	 * Rang que trie ORDER BY : pour un ENUM, la position de la valeur dans la définition (1 pour la
	 * première, 0 pour la valeur d'erreur '') ; pour un SET, la somme des bits de ses membres (le
	 * premier vaut 1, le deuxième 2…).
	 *
	 * @param array{0: string, 1: list<string>} $members
	 */
	private static function rank( array $members, string $value ): int {
		[ $kind, $list ] = $members;
		if ( 'enum' === $kind ) {
			$position = array_search( $value, $list, true );
			return false === $position ? 0 : $position + 1;
		}
		$bits = 0;
		foreach ( '' === $value ? [] : explode( ',', $value ) as $member ) {
			$position = array_search( $member, $list, true );
			if ( false === $position ) {
				throw new \LogicException( 'Valeur hors du SET : ' . $value );
			}
			$bits |= 1 << $position;
		}
		return $bits;
	}

	/**
	 * Motif LIKE relu avec ! pour échappement (ESCAPE '!').
	 */
	private static function like( string $pattern, string $value ): bool {
		$regex = '';
		for ( $i = 0, $n = strlen( $pattern ); $i < $n; $i++ ) {
			$c = $pattern[ $i ];
			if ( '!' === $c && $i + 1 < $n ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
				continue;
			}
			$regex .= '%' === $c ? '.*' : ( '_' === $c ? '.' : preg_quote( $c, '/' ) );
		}
		return 1 === preg_match( '/\A' . $regex . '\z/s', $value );
	}

	/**
	 * Requête reçue : comptée, erreur précédente oubliée ; reconnexion attendue faite avant le
	 * résultat.
	 */
	private function receive( string $sql ): void {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( null !== $this->reconnect && str_contains( $sql, $this->reconnect[0] ) ) {
			$this->closed[]  = $this->dbh;
			$this->dbh       = new \stdClass();
			$this->session   = $this->reconnect[1];
			$this->reconnect = null;
		}
	}
}
