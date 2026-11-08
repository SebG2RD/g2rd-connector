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
 * WHERE (clé) > (valeurs) les compare comme du texte ; une colonne FLOAT lue telle quelle rend le
 * texte arrondi à 6 chiffres significatifs (0.6999999 et 0.7 se lisent toutes deux « 0.7 »,
 * MariaDB 10.11.19 et MySQL 8.4.0), et WHERE relit ce texte en un double qui n'est pas la valeur
 * rangée ; lue `c`*1e0 AS `c`, elle rend le double exact du float rangé (seule expression admise,
 * et seulement pour une colonne FLOAT). Lue par offset, une table dont une colonne de la clé est
 * lue par cette expression doit s'ordonner par la colonne qualifiée (`t`.`c`) : ORDER BY `c`
 * prendrait l'alias (tri de toute la table, sans l'index). Une colonne TIMESTAMP est rangée en UTC
 * (valeurs des lignes du faux) et se lit dans le fuseau de la session ($time_zone : +00:00, ou
 * SYSTEM = Europe/Paris) ; WHERE relit son texte dans ce fuseau, une heure ambiguë (passage à
 * l'heure d'hiver) à l'instant le plus tardif, comme les deux serveurs (essai du 08/11/2026). Les
 * colonnes générées (Extra VIRTUAL GENERATED ou STORED GENERATED) ne se lisent que si elles sont
 * dans la clé : toute autre lecture qui les nomme lève.
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

	/** Lecture du fuseau de la session, au texte exact (§ 4, « Fuseau de la session »). */
	public const ZONE_QUERY = 'SELECT @@session.time_zone';

	/** Passage de la session en UTC, au texte exact. */
	public const UTC_QUERY = "SET time_zone = '+00:00'";

	/** Fuseau que le faux rend pour SYSTEM : celui d'un serveur réglé sur Paris (heure d'été, heure d'hiver). */
	private const SYSTEM_ZONE = 'Europe/Paris';

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
	 * début (comme `my_row_id` > '' qui compare à 0) ; extra : champ Extra de SHOW FULL COLUMNS
	 * d'une colonne (VIRTUAL GENERATED, STORED GENERATED, DEFAULT_GENERATED…), qui remplace
	 * INVISIBLE. Une valeur de colonne FLOAT est le texte écrit à l'INSERT (rangé en simple précision) ;
	 * une valeur de colonne TIMESTAMP, l'instant rangé, écrit en UTC.
	 *
	 * @var array<string, array{columns: array<string, string>, pk: list<string>, rows: list<array<string, string|null>>, view?: bool, avg_row_length?: int, invisible?: list<string>, omitted?: list<string>, where_ignored?: bool, extra?: array<string, string>}>
	 */
	public array $tables = [];

	/** @var list<string> Requêtes reçues, dans l'ordre. */
	public array $queries = [];

	/** Nom de table dont la lecture des lignes échoue, ou null. */
	public ?string $fails_on = null;

	/** @var list<string|null> Variables de jeu de la session : client, connexion, résultats. */
	public array $session = [ 'utf8mb4', 'utf8mb4', 'utf8mb4' ];

	/** Fuseau de la session (@@session.time_zone) : SYSTEM (Europe/Paris), +00:00, ou toute valeur qu'un test lui donne. */
	public string $time_zone = 'SYSTEM';

	/** Fuseau d'une session rouverte (celui du serveur) : wpdb::db_connect() ne le règle pas. */
	public string $default_time_zone = 'SYSTEM';

	/** Vrai : SET time_zone n'a pas d'effet (anomalie simulée : la session reste dans son fuseau). */
	public bool $zone_locked = false;

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
	 * Seule requête sans résultat admise : SET time_zone = '<fuseau>' (variable de session), le
	 * fuseau entre apostrophes, sans \ ni apostrophe (SYSTEM, ±hh:mm ou un nom) ; sans effet si
	 * $zone_locked. Toute autre requête lève.
	 */
	public function query( string $sql ): int {
		$this->receive( $sql );
		if ( 1 !== preg_match( "/\ASET time_zone = '([A-Za-z0-9_+:\/-]{1,64})'\z/", $sql, $m ) ) {
			throw new \LogicException( 'Requête non prévue par le faux $wpdb du dump (seul SET time_zone l\'est) : ' . $sql );
		}
		if ( ! $this->zone_locked ) {
			$this->time_zone = $m[1];
		}
		return 0;
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
					'Extra' => $this->tables[ $name ]['extra'][ (string) $field ] ?? ( in_array( (string) $field, $this->tables[ $name ]['invisible'] ?? [], true ) ? 'INVISIBLE' : '' ),
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
			$list  = $this->column_pattern( self::read_columns( $table ) );
			if ( null === $ident || null === $list || 1 !== preg_match( '/\ASELECT ' . $list[0] . ' FROM ' . preg_quote( $ident, '/' ) . ' /s', $sql, $m ) ) {
				continue;
			}
			$exact = [];
			foreach ( $list[1] as $group => $column ) {
				$exact[ $column ] = '' !== ( $m[ $group ] ?? '' );
			}
			return $this->select( (string) $candidate, substr( $sql, strlen( $m[0] ) - 1 ), $exact );
		}
		throw new \LogicException( 'Lecture non prévue par le faux $wpdb du dump (colonnes autres que celles que lit le dump, dans l\'ordre de la table ?) : ' . $sql );
	}

	/**
	 * Colonnes que le dump doit lire, dans l'ordre de la table : toutes, sauf les colonnes générées
	 * qui ne sont pas dans la clé ; toutes si la table n'a que des colonnes générées (lues pour
	 * compter les lignes : un SELECT sans colonne est une erreur de syntaxe).
	 *
	 * @param array{columns: array<string, string>, pk: list<string>, extra?: array<string, string>} $table
	 * @return array<string, string>
	 */
	private static function read_columns( array $table ): array {
		$read = [];
		foreach ( $table['columns'] as $column => $type ) {
			$column = (string) $column;
			if ( ! self::generated( $table, $column ) || in_array( $column, $table['pk'], true ) ) {
				$read[ $column ] = $type;
			}
		}
		return [] === $read ? $table['columns'] : $read;
	}

	/**
	 * Colonne générée : Extra VIRTUAL GENERATED ou STORED GENERATED (relevé du 08/11/2026 sur MariaDB
	 * 10.11.19, versions système explicites comprises, et MySQL 8.4.0, INVISIBLE en plus) ;
	 * DEFAULT_GENERATED (MySQL 8 : valeur par défaut calculée, DEFAULT CURRENT_TIMESTAMP) n'en est pas une.
	 *
	 * @param array{extra?: array<string, string>} $table
	 */
	private static function generated( array $table, string $column ): bool {
		return 1 === preg_match( '/\A(?:VIRTUAL|STORED) GENERATED(?: INVISIBLE)?\z/', $table['extra'][ $column ] ?? '' );
	}

	/**
	 * Motif de la liste des colonnes lues, dans leur ordre, chacune écrite par ident() et séparées par
	 * des virgules : une colonne FLOAT telle quelle ou `c`*1e0 AS `c` (groupe capturé, vide pour la
	 * lecture telle quelle) ; null si un nom est refusé dans le jeu du faux ou si la table n'a pas de
	 * colonne.
	 *
	 * @param array<string, string> $columns
	 * @return array{0: string, 1: array<int, string>}|null Motif, puis la colonne FLOAT de chaque groupe.
	 */
	private function column_pattern( array $columns ): ?array {
		$parts  = [];
		$groups = [];
		foreach ( $columns as $column => $type ) {
			$ident = self::ident_or_null( (string) $column, $this->charset );
			if ( null === $ident ) {
				return null;
			}
			$quoted = preg_quote( $ident, '/' );
			if ( self::is_float( $type ) ) {
				$groups[ count( $groups ) + 1 ] = (string) $column;
				$parts[]                        = $quoted . '((?:' . preg_quote( '*1e0 AS ', '/' ) . $quoted . ')?)';
			} else {
				$parts[] = $quoted;
			}
		}
		return [] === $parts ? null : [ implode( ',', $parts ), $groups ];
	}

	/**
	 * Lignes telles que la base les rend pour la liste des colonnes lues (read_columns()) : chaque
	 * colonne, dans l'ordre, sauf celles d'omitted (anomalie simulée) ; une colonne FLOAT ou
	 * TIMESTAMP, telle que la base l'écrit en texte (shown()).
	 *
	 * @param list<array<string, string|null>> $rows
	 * @param array<string, bool>              $exact Colonnes FLOAT lues `c`*1e0 AS `c`.
	 * @return list<array<string, string|null>>
	 */
	private function projected( string $name, array $rows, array $exact ): array {
		$omitted = $this->tables[ $name ]['omitted'] ?? [];
		$read    = [];
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( self::read_columns( $this->tables[ $name ] ) as $column => $type ) {
				$column = (string) $column;
				if ( in_array( $column, $omitted, true ) ) {
					continue;
				}
				if ( ! array_key_exists( $column, $row ) ) {
					throw new \LogicException( 'Ligne du faux sans la colonne ' . $column . ' de ' . $name );
				}
				$line[ $column ] = $this->shown( $type, $row[ $column ], $exact[ $column ] ?? false );
			}
			$read[] = $line;
		}
		return $read;
	}

	/**
	 * Valeur telle que la base l'écrit en texte : une colonne FLOAT lue telle quelle, arrondie à
	 * 6 chiffres significatifs (relevé du 08/11/2026, MariaDB 10.11.19 et MySQL 8.4.0 : 0.6999999 et
	 * 0.7 se lisent « 0.7 »), lue `c`*1e0 ($exact), le texte qui redonne exactement le float rangé ;
	 * une colonne TIMESTAMP dans le fuseau de la session ; toute autre colonne telle quelle.
	 */
	private function shown( string $type, ?string $value, bool $exact ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( self::is_float( $type ) ) {
			$single = self::single( $value );
			return $exact ? self::exact_text( $single ) : sprintf( '%.6g', $single );
		}
		if ( self::is_timestamp( $type ) ) {
			return $this->in_session_zone( $value );
		}
		return $value;
	}

	/** Texte le plus court (au plus 17 chiffres significatifs) qui se relit en $value exactement. */
	private static function exact_text( float $value ): string {
		for ( $digits = 1; $digits < 17; $digits++ ) {
			$text = sprintf( '%.' . $digits . 'g', $value );
			if ( (float) $text === $value ) {
				return $text;
			}
		}
		return sprintf( '%.17g', $value );
	}

	/** Colonne FLOAT (simple précision), lue au début du Type. */
	private static function is_float( string $type ): bool {
		return 1 === preg_match( '/\Afloat\b/i', $type );
	}

	/** Colonne TIMESTAMP (rangée en UTC, lue dans le fuseau de la session), lue au début du Type. */
	private static function is_timestamp( string $type ): bool {
		return 1 === preg_match( '/\Atimestamp\b/i', $type );
	}

	/** Fuseau de la session pour PHP : SYSTEM est Europe/Paris ; toute autre valeur, telle quelle. */
	private function zone(): \DateTimeZone {
		try {
			return new \DateTimeZone( 'SYSTEM' === $this->time_zone ? self::SYSTEM_ZONE : $this->time_zone );
		} catch ( \Exception $e ) {
			throw new \LogicException( 'Fuseau de session non prévu par le faux : ' . $this->time_zone, 0, $e );
		}
	}

	/**
	 * Date et heure, puis fraction de seconde (« .999999 », ou '').
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function split_time( string $text ): array {
		$dot = strpos( $text, '.' );
		return false === $dot ? [ $text, '' ] : [ substr( $text, 0, $dot ), substr( $text, $dot ) ];
	}

	/** Valeur TIMESTAMP rangée (UTC) écrite dans le fuseau de la session. */
	private function in_session_zone( string $utc ): string {
		if ( '+00:00' === $this->time_zone ) {
			return $utc;
		}
		[ $seconds, $fraction ] = self::split_time( $utc );
		$instant                = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $seconds, new \DateTimeZone( 'UTC' ) );
		if ( false === $instant ) {
			throw new \LogicException( 'TIMESTAMP du faux illisible : ' . $utc );
		}
		return $instant->setTimezone( $this->zone() )->format( 'Y-m-d H:i:s' ) . $fraction;
	}

	/**
	 * Texte d'une valeur TIMESTAMP de WHERE, relu dans le fuseau de la session, en UTC ; une heure
	 * ambiguë (deux instants s'écrivent ainsi au passage à l'heure d'hiver) prend l'instant le plus
	 * tardif, comme MariaDB 10.11.19 et MySQL 8.4.0 (essai du 08/11/2026 : après « 02:30:00 », le
	 * 25/10/2026 à Paris, la ligne de 01:30 UTC n'est pas rendue).
	 */
	private function from_session_zone( string $local ): string {
		if ( '+00:00' === $this->time_zone ) {
			return $local;
		}
		[ $seconds, $fraction ] = self::split_time( $local );
		$zone                   = $this->zone();
		$guess                  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $seconds, $zone );
		if ( false === $guess ) {
			throw new \LogicException( 'TIMESTAMP de WHERE illisible : ' . $local );
		}
		$best = new \DateTimeImmutable( '@' . $guess->getTimestamp() );
		foreach ( [ -3600, 0, 3600 ] as $shift ) {
			$candidate = new \DateTimeImmutable( '@' . ( $guess->getTimestamp() + $shift ) );
			if ( $candidate->setTimezone( $zone )->format( 'Y-m-d H:i:s' ) === $seconds && $candidate > $best ) {
				$best = $candidate;
			}
		}
		return $best->format( 'Y-m-d H:i:s' ) . $fraction;
	}

	/** Valeur rangée par une colonne FLOAT : le nombre écrit, arrondi au float de simple précision le plus proche. */
	private static function single( string $value ): float {
		$single = unpack( 'g', pack( 'g', (float) $value ) );
		if ( ! is_array( $single ) ) {
			throw new \LogicException( 'Float illisible : ' . $value );
		}
		return (float) $single[1];
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
		if ( self::ZONE_QUERY === $sql ) {
			if ( 'ARRAY_N' !== $output ) {
				throw new \LogicException( 'Fuseau de la session lu autrement que par ARRAY_N : ' . $output );
			}
			return [ $this->time_zone ];
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
	 * (sans WHERE) ; les valeurs se relisent par l'inverse de key_literal(), et lui seul. ORDER BY
	 * nomme les colonnes de la clé, ou les mêmes qualifiées par la table (`t`.`c`) ; non qualifiées,
	 * jamais si l'une est lue `c`*1e0 AS `c` (ORDER BY prendrait l'alias). Le tri de ORDER BY et la
	 * comparaison de WHERE sont ceux de la vraie base (compare_keys()).
	 *
	 * @param string              $rest  Texte qui suit le nom de la table (commence par une espace).
	 * @param array<string, bool> $exact Colonnes FLOAT lues `c`*1e0 AS `c`.
	 * @return list<array<string, string|null>>
	 */
	private function select( string $name, string $rest, array $exact ): array {
		$table = $this->tables[ $name ];
		$pk    = $table['pk'];
		$types = $table['columns'];
		if ( [] === $pk ) {
			if ( 1 !== preg_match( '/\A LIMIT (\d+) OFFSET (\d+)\z/', $rest, $m ) ) {
				throw new \LogicException( 'Lecture d\'une table sans clé primaire hors de LIMIT … OFFSET … : ' . $rest );
			}
			return $this->projected( $name, array_slice( $this->rows_of( $name ), (int) $m[2], (int) $m[1] ), $exact );
		}
		$columns   = implode( ',', array_map( fn ( string $c ): string => DatabaseDumper::ident( $c, $this->charset ), $pk ) );
		$qualified = implode( ',', array_map( fn ( string $c ): string => DatabaseDumper::ident( $name, $this->charset ) . '.' . DatabaseDumper::ident( $c, $this->charset ), $pk ) );
		$orders    = [ [ ' ORDER BY ' . $columns . ' LIMIT ', false ], [ ' ORDER BY ' . $qualified . ' LIMIT ', true ] ];
		$where     = ' WHERE (' . $columns . ') > (';
		$after     = null;
		$limit     = null;
		$by_table  = false;
		if ( str_starts_with( $rest, $where ) ) {
			$tail = substr( $rest, strlen( $where ) );
			foreach ( $orders as [ $order, $is_qualified ] ) {
				$cut = strpos( $tail, ')' . $order );
				if ( false !== $cut ) {
					$after    = array_map( [ $this, 'key_value' ], explode( ',', substr( $tail, 0, $cut ) ) );
					$limit    = substr( $tail, $cut + strlen( ')' . $order ) );
					$by_table = $is_qualified;
					break;
				}
			}
			if ( null === $after ) {
				throw new \LogicException( 'Reprise sans ORDER BY de la clé : ' . $rest );
			}
			if ( count( $after ) !== count( $pk ) ) {
				throw new \LogicException( 'Reprise dont le nombre de valeurs n\'est pas celui de la clé : ' . $rest );
			}
		} else {
			foreach ( $orders as [ $order, $is_qualified ] ) {
				if ( str_starts_with( $rest, $order ) ) {
					$limit    = substr( $rest, strlen( $order ) );
					$by_table = $is_qualified;
					break;
				}
			}
			if ( null === $limit ) {
				throw new \LogicException( 'Lecture d\'une table à clé primaire hors de ORDER BY la clé : ' . $rest );
			}
		}
		if ( ! $by_table && [] !== array_filter( $pk, static fn ( string $c ): bool => $exact[ $c ] ?? false ) ) {
			throw new \LogicException( 'ORDER BY la clé non qualifiée alors qu\'une de ses colonnes est lue `c`*1e0 AS `c` : l\'alias serait trié, sans l\'index (EXPLAIN : Using filesort) : ' . $rest );
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
		usort( $rows, fn ( array $a, array $b ): int => $this->compare_keys( $a, $b, $pk, $types, true ) );
		if ( null !== $after && empty( $table['where_ignored'] ) ) {
			$key  = array_combine( $pk, $after );
			$rows = array_values( array_filter( $rows, fn ( array $row ): bool => $this->compare_keys( $row, $key, $pk, $types, false ) > 0 ) );
		}

		return $this->projected( $name, array_slice( $rows, $offset, (int) $limit ), $exact );
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
	 * tuples) : $a est une ligne rangée ; $b aussi pour ORDER BY ($order_by vrai), les valeurs de
	 * WHERE (clé) > (valeurs) sinon. Une colonne ENUM ou SET se compare par son rang pour ORDER BY,
	 * comme du texte pour WHERE ; une colonne FLOAT, par la valeur rangée (simple précision) d'une
	 * ligne, et par le texte relu en double d'une valeur de WHERE (« 0.1 » vaut moins que le 0.1
	 * rangé) ; une colonne TIMESTAMP, par l'instant rangé (UTC) d'une ligne, et par le texte relu
	 * dans le fuseau de la session d'une valeur de WHERE (from_session_zone()) ; toute autre colonne,
	 * de la même façon des deux côtés.
	 *
	 * @param array<string, mixed>  $a
	 * @param array<string, mixed>  $b
	 * @param list<string>          $pk
	 * @param array<string, string> $types Type de chaque colonne (SHOW FULL COLUMNS).
	 */
	private function compare_keys( array $a, array $b, array $pk, array $types, bool $order_by ): int {
		foreach ( $pk as $column ) {
			$x       = (string) $a[ $column ];
			$y       = (string) $b[ $column ];
			$members = self::members( $types[ $column ] ?? '' );
			if ( null !== $members ) {
				$order = $order_by ? ( self::rank( $members, $x ) <=> self::rank( $members, $y ) ) : strcmp( $x, $y );
			} elseif ( self::is_float( $types[ $column ] ?? '' ) ) {
				$order = self::single( $x ) <=> ( $order_by ? self::single( $y ) : (float) $y );
			} elseif ( self::is_timestamp( $types[ $column ] ?? '' ) ) {
				$order = strcmp( $x, $order_by ? $y : $this->from_session_zone( $y ) );
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
	 * résultat, dans une session au fuseau du serveur ($default_time_zone).
	 */
	private function receive( string $sql ): void {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( null !== $this->reconnect && str_contains( $sql, $this->reconnect[0] ) ) {
			$this->closed[]  = $this->dbh;
			$this->dbh       = new \stdClass();
			$this->session   = $this->reconnect[1];
			$this->time_zone = $this->default_time_zone;
			$this->reconnect = null;
		}
	}
}
