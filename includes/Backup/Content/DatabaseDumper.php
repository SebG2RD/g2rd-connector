<?php
/**
 * Dump de la base du site pour les sauvegardes (docs/backup-payload.md § 4) : tables du préfixe
 * exact, sans celles d'une installation voisine ni les vues ; `SHOW CREATE TABLE` ; lignes par lots
 * ordonnés par clé primaire et repris après la clé de la dernière ligne lue. Lecture par offset :
 * LIMIT/OFFSET pour une table sans clé, ORDER BY la clé LIMIT/OFFSET pour une clé qui contient un
 * ENUM, un SET, un FLOAT, un DOUBLE ou un REAL (UNSEEKABLE_TYPE : la valeur lue ne se compare pas
 * dans WHERE comme ORDER BY la trie, la reprise par la clé perdrait ou doublerait des lignes).
 * Colonnes binaires en hexadécimal ; colonnes FLOAT lues par leur texte exact (`c`*1e0, FLOAT_TYPE :
 * le texte lu tel quel est arrondi à 6 chiffres significatifs) ; colonnes générées (VIRTUAL, STORED,
 * versions système explicites de MariaDB) absentes de l'INSERT, comme le fait mysqldump : la
 * restauration les recalcule, et une valeur donnée la ferait échouer en mode strict ; lignes des
 * options du module exclues. Session en UTC pendant chaque avance (TIME_ZONE, annoncé par l'en-tête,
 * comme mysqldump) : une valeur TIMESTAMP se lit, se compare dans WHERE et se restaure dans le fuseau
 * de la session, et l'heure du passage à l'heure d'hiver est ambiguë dans un fuseau qui change
 * d'heure. Reprenable par curseur, borné par l'appelant ($stop). Aucune écriture dans la base ; le
 * fuseau de la session est rendu à la fin de l'avance.
 *
 * Limites écrites de la lecture par offset (tables sans clé comprises) :
 * - le coût : chaque lot relit les lignes qui le précèdent (OFFSET m fait parcourir m lignes au
 *   serveur avant de rendre le lot), si bien qu'une grande table coûte de plus en plus à chaque lot ;
 * - une ligne déjà copiée puis supprimée entre deux tranches décale les suivantes d'un rang : une
 *   ligne qui existe encore est sautée ; de même, une ligne ajoutée avant l'offset fait relire une
 *   ligne déjà copiée (dans une table à clé, la restauration échoue alors sur une clé en double).
 *
 * Avant toute lecture, le jeu de caractères de la session est vérifié, et de nouveau si la connexion
 * a été rouverte (une session rouverte hors d'UTC est refusée, réessayable) ; les noms s'écrivent entre accents graves selon ce jeu (ident()), jamais par
 * $wpdb->prepare() ; aucune requête exécutée sur le site ne dépend de \ ni du sql_mode (motifs
 * LIKE … ESCAPE '!', valeurs de la clé de reprise en hexadécimal).
 *
 * Le curseur passe par l'état JSON entre deux tranches (docs/backup-payload.md § 4, « Curseur du
 * dump ») : jeu de l'en-tête, clé de reprise en hexadécimal (after_hex), jamais d'octet brut lu dans
 * la base ; un curseur illisible est refusé, jamais remplacé par un départ. Les lignes se lisent par
 * leurs colonnes nommées, jamais par SELECT * (une colonne INVISIBLE n'y est pas rendue) ; une
 * reprise qui repartirait du début de la table est refusée (batch()). Limites écrites : une clé
 * primaire remplacée entre deux tranches par une autre du même nombre de colonnes n'est pas
 * détectée (la reprise compare alors les valeurs gardées à d'autres colonnes), ni une clé lue par
 * offset (UNSEEKABLE_TYPE) ajoutée à une table sans clé, ou retirée (la lecture par offset
 * continue, dans un autre ordre) ; une fois des lignes de la table lues, toute autre clé ajoutée ou
 * retirée, et une colonne de la clé qui devient d'un type de UNSEEKABLE_TYPE ou cesse de l'être (la
 * lecture changerait de sorte), sont refusées.
 *
 * Mémoire : un lot vise BATCH_BYTES (8 Mio) de valeurs ; $wpdb garde son résultat et sa conversion
 * en ARRAY_A (≈ 2 fois), l'instruction INSERT est émise par morceaux de FLUSH_BYTES (512 Kio). Une
 * table à colonne MEDIUMTEXT, LONGTEXT, MEDIUMBLOB, LONGBLOB ou JSON (LARGE_TYPE) lit avant chaque lot
 * les longueurs de ses lignes (bounded_limit()) et réduit le LIMIT pour que le lot reste sous
 * BATCH_BYTES, une ligne seule exceptée ; les autres tables se règlent sur la taille moyenne annoncée
 * puis sur la taille lue du lot précédent (next_limit()), leurs colonnes pesant 64 Kio au plus : le pic
 * d'un lot reste sous ≈ 20 Mio au-dessus d'une ligne isolée. Limite écrite : une ligne seule plus
 * grosse que la mémoire disponible reste impossible à copier (lot d'une ligne).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Support\ArrayShape;

/**
 * @phpstan-type Cursor array{table: int, after_hex: list<string>|null, offset: int, started: bool, header: bool, rows: int, create_sha256: string|null, limit: int, charset: string}
 * @phpstan-type Meta array{columns: list<string>, insert: list<string>, binary: array<string, bool>, float: array<string, bool>, pk: list<string>, seek: bool, avg_row: int, large: bool}
 */
final class DatabaseDumper {

	public const FOOTER = "SET FOREIGN_KEY_CHECKS=1;\n";

	/**
	 * Fuseau de la session pendant chaque avance, annoncé par l'en-tête (header()) : UTC, sans heure
	 * ambiguë. Une valeur TIMESTAMP se lit, se compare dans WHERE et se restaure dans le fuseau de la
	 * session : dans un fuseau qui change d'heure (SYSTEM sur un serveur réglé sur Paris), deux
	 * instants de l'heure du passage à l'heure d'hiver se lisent avec le même texte (essai du
	 * 08/11/2026 sur MariaDB 10.11.19 et MySQL 8.4.0 : clé TIMESTAMP, une ligne perdue à la reprise et
	 * une restauration en erreur 1062).
	 */
	public const TIME_ZONE = '+00:00';

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

	/** Valeur du curseur ou nom de table de l'état : hexadécimal minuscule, sans saut de ligne final (modificateur D). */
	private const HEX_PATTERN = '/^(?:[0-9a-f]{2})*$/D';

	/** Colonnes binaires (docs/backup-payload.md § 4 : blob, binary, varbinary, bit), lues au début du Type de SHOW FULL COLUMNS. */
	private const BINARY_TYPE = '/\A(?:tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i';

	/**
	 * Types dont la valeur lue ne se compare pas dans WHERE (clé) > (valeurs) comme ORDER BY les
	 * trie : une clé qui en contient un ne sert pas à la reprise, la table se lit par offset (ORDER BY
	 * la clé LIMIT … OFFSET …). Lus au début du Type de SHOW FULL COLUMNS, sans égard à la casse.
	 * - ENUM et SET : ORDER BY les trie par leur rang (ENUM : ordre de la définition ; SET : valeur
	 *   numérique), WHERE les compare comme du texte (essai du 08/11/2026 sur MariaDB 10.11.19 : la
	 *   reprise par la clé perdait ou doublait des lignes, clé simple comme composée).
	 * - FLOAT : lue telle quelle, la valeur est le texte arrondi à 6 chiffres significatifs (« 0.1 » ;
	 *   0.6999999 et 0.7 se lisent toutes deux « 0.7 »), que WHERE relit en un double qui n'est pas
	 *   le float rangé : après « 0.1 », la ligne du curseur est relue (refus réessayable sans fin) ;
	 *   après « 0.7 » lu de 0.6999999, la ligne 0.7 (0.699999988) n'est pas rendue, sans bruit
	 *   (essais du 08/11/2026 sur MariaDB 10.11.19 et MySQL 8.4.0). Elle se lit désormais par son
	 *   texte exact (FLOAT_TYPE) ; la clé reste lue par offset, comme l'a décidé le propriétaire (la
	 *   reprise par la clé n'a pas été réessayée avec ce texte).
	 * - DOUBLE, et REAL (son synonyme, ou celui de FLOAT avec REAL_AS_FLOAT) : texte lu exact sur
	 *   MariaDB 10.11.19, MySQL 8.0.16 et 8.4.0 (0.1e0+0.2e0 se lit 0.30000000000000004, essai du
	 *   08/11/2026) ; lus par offset aussi, par prudence.
	 */
	private const UNSEEKABLE_TYPE = '/\A(?:(?:enum|set)\(|float\b|double\b|real\b)/i';

	/**
	 * Colonne FLOAT (simple précision ; REAL avec REAL_AS_FLOAT s'y affiche float), dans la clé ou
	 * non, lue au début du Type de SHOW FULL COLUMNS : lue par `c`*1e0 AS `c`, le double exact du
	 * float rangé, que le texte rendu (au plus 17 chiffres) redonne exactement à l'INSERT. Lue telle
	 * quelle, la valeur est le texte arrondi à 6 chiffres : 0.6999999 et 0.7 s'écrivaient toutes deux
	 * '0.7' (restauration en erreur 1062 pour une clé), 48.856613 devenait 48.8566, sans bruit hors de
	 * la clé. Pourquoi *1e0 et pas +0e0 : x × 1 = x pour tout float, alors que -0 + 0 = +0 ; MySQL
	 * range -0 dans un FLOAT et '-0' le restaure, MariaDB le range 0 (essai du 08/11/2026 sur MariaDB
	 * 10.11.19, MySQL 8.0.16 et 8.4.0 : valeurs distinctes, restauration en STRICT_TRANS_TABLES
	 * identique bit à bit). CAST(… AS DOUBLE) n'existe qu'à partir de MySQL 8.0.17.
	 */
	private const FLOAT_TYPE = '/\Afloat\b/i';

	/**
	 * Colonne dont la valeur peut peser des Mio (MEDIUMTEXT, LONGTEXT, MEDIUMBLOB, LONGBLOB, JSON ; TEXT et BLOB
	 * s'arrêtent à 64 Kio) : le premier lot d'une table qui en a une est d'une ligne (initial_limit()), puis
	 * est ensuite borné en octets avant chaque lot (bounded_limit()). La taille moyenne d'information_schema ne suffit pas à le dimensionner : MySQL 8
	 * la garde 24 h (`information_schema_stats_expiry`), InnoDB l'estime par échantillon, et une table remplie
	 * depuis son annonce l'a toujours petite. Relevé du 08/11/2026 (MariaDB 10.11.19, `memory_limit=128M`, 300
	 * lignes de 2 Mio, taille moyenne annoncée 54 octets) : premier lot de 200 lignes, 400 Mio, erreur fatale.
	 */
	private const LARGE_TYPE = '/\A(?:(?:medium|long)(?:text|blob)|json)\b/i';

	/**
	 * Valeur de @@session.time_zone rendue à la fin d'une avance (restore_zone()) : SYSTEM, ±hh:mm ou
	 * nom de fuseau, sans apostrophe ni \ ; toute autre valeur n'est pas réécrite.
	 */
	private const ZONE_PATTERN = '/\A[A-Za-z0-9_+:\/-]{1,64}\z/';

	/**
	 * Colonne générée, absente de l'INSERT (comme le fait mysqldump) : Extra de SHOW FULL COLUMNS
	 * qui contient VIRTUAL GENERATED ou STORED GENERATED (relevé du 08/11/2026 : MariaDB 10.11.19,
	 * versions système explicites ROW START et ROW END comprises ; MySQL 8.4.0, suivi de INVISIBLE
	 * pour une colonne invisible). Jamais DEFAULT_GENERATED, que MySQL 8 écrit pour une valeur par
	 * défaut calculée (DEFAULT CURRENT_TIMESTAMP, DEFAULT (UUID())) : colonne ordinaire, à copier.
	 */
	private const GENERATED_EXTRA = '/\b(?:VIRTUAL|STORED) GENERATED\b/i';

	/** Une instruction INSERT est émise dès qu'elle dépasse cette taille (octets). */
	private const FLUSH_BYTES = 524288;

	/** @var \wpdb */
	private object $wpdb;

	/** @var array<string, array<string, true>> Lignes exclues : table → noms d'option. */
	private array $excluded_rows;

	/** Jeu de la connexion au moment de l'instance (charset_of()) : celui de l'en-tête d'un dump qui commence. */
	private string $charset;

	/** @var array<string, int>|null Taille moyenne des lignes par table, lue une fois par connexion. */
	private ?array $avg_rows = null;

	/**
	 * Hexadécimal minuscule de la clé primaire de la dernière ligne du dernier lot, relevé par batch().
	 *
	 * @var list<string>|null
	 */
	private ?array $last_key = null;

	/** LIMIT réellement lu par le dernier batch() (réduit par bounded_limit() pour une table à colonne large). */
	private int $used_limit = 1;

	/** Jeu contrôlé pour cette instance (une instance par requête ou par tranche), ou null. */
	private ?string $guarded = null;

	/** Jeu de la dernière session contrôlée par check_session(), ou null. */
	private ?string $session_charset = null;

	/** Identité de la connexion contrôlée (connection_identity()), ou null avant tout contrôle. */
	private ?string $identity = null;

	/** @var array{0: string, 1: string, 2: list<string>}|null Arguments du dernier contrôle complet. */
	private ?array $guard_for = null;

	/** Fuseau de la session avant que l'avance en cours le mette à UTC, rendu à sa fin ; null s'il l'était déjà. */
	private ?string $zone_before = null;

	/**
	 * @param \wpdb                              $wpdb          $wpdb (un double en test).
	 * @param array<string, array<string, true>> $excluded_rows Table des options du site principal → noms exclus.
	 */
	public function __construct( object $wpdb, array $excluded_rows = [] ) {
		$this->wpdb          = $wpdb;
		$this->excluded_rows = $excluded_rows;
		$this->charset       = self::charset_of( $wpdb );
	}

	/**
	 * En-tête du dump : SET NAMES du jeu de caractères de la connexion (spécification § 6.3), pour que
	 * les octets lus soient ceux que le dump annonce ; SET TIME_ZONE du fuseau des lectures
	 * (TIME_ZONE), pour que la restauration relise les valeurs TIMESTAMP aux mêmes instants (comme
	 * mysqldump).
	 */
	public static function header( string $charset ): string {
		return "-- G2RD backup dump v1\n/*!40101 SET NAMES " . $charset . " */;\n/*!40103 SET TIME_ZONE='" . self::TIME_ZONE . "' */;\nSET FOREIGN_KEY_CHECKS=0;\n";
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

	/**
	 * Curseur de départ.
	 *
	 * @return Cursor
	 */
	public static function start(): array {
		return [
			'table'         => 0,
			'after_hex'     => null,
			'offset'        => 0,
			'started'       => false,
			'header'        => false,
			'rows'          => 0,
			'create_sha256' => null,
			'limit'         => self::BATCH_ROWS,
			'charset'       => '',
		];
	}

	/**
	 * Avance le dump depuis $cursor jusqu'à ce que $stop() le demande (vérifié après un premier
	 * progrès, avant chaque lot) ou que tout soit écrit. Le jeu de la session est contrôlé avant le
	 * premier octet émis (guard()), et de nouveau après chaque requête si la connexion a été rouverte
	 * (rows()) : une tranche refusée n'écrit rien de ce qu'elle a lu dans une autre session. La
	 * session passe en UTC (TIME_ZONE) avant toute lecture des tables, et revient à son fuseau à la
	 * fin de l'avance, refus compris.
	 *
	 * @param list<string>              $tables Tables, dans l'ordre (tables(), ou tables_from_hex() de db.tables_hex).
	 * @param array<string, mixed>|null $cursor Curseur rendu par l'appel précédent (relu de l'état JSON) ; null pour commencer.
	 * @param \Closure(string): void    $emit   Reçoit le texte SQL, dans l'ordre.
	 * @param \Closure(): bool          $stop   Vrai quand le segment est plein ou la tranche finie.
	 * @return array{cursor: Cursor|null, tables_done: list<array{name: string, rows: int, create_sha256: string}>, rows: int}
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED, DB_CURSOR_INVALID (non réessayables), DB_READ_FAILED.
	 */
	public function advance( array $tables, ?array $cursor, \Closure $emit, \Closure $stop ): array {
		$cursor = self::cursor_from( $cursor );
		if ( $cursor['table'] >= count( $tables ) ) {
			// Rang hors de la liste (ou liste vide) : la fin du dump serait déclarée sans ses tables.
			throw new BackupRefused( BackupRefused::DB_CURSOR_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$charset = $cursor['header'] ? $cursor['charset'] : $this->charset;
		$this->guard( $charset, (string) $this->wpdb->base_prefix, $tables );
		try {
			// Avant toute lecture des tables : SHOW CREATE TABLE écrit aussi la valeur par défaut d'une colonne TIMESTAMP dans
			// le fuseau de la session.
			$this->utc_session();
			return $this->dump_from( $tables, $cursor, $charset, $emit, $stop );
		} finally {
			$this->restore_zone();
		}
	}

	/**
	 * Corps de advance(), une fois la session contrôlée et en UTC.
	 *
	 * @param list<string>           $tables
	 * @param Cursor                 $cursor
	 * @param \Closure(string): void $emit
	 * @param \Closure(): bool       $stop
	 * @return array{cursor: Cursor|null, tables_done: list<array{name: string, rows: int, create_sha256: string}>, rows: int}
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED, DB_CURSOR_INVALID (non réessayables), DB_READ_FAILED.
	 */
	private function dump_from( array $tables, array $cursor, string $charset, \Closure $emit, \Closure $stop ): array {
		$count    = count( $tables );
		$done     = [];
		$rows     = 0;
		$progress = false;
		if ( ! $cursor['header'] ) {
			$emit( self::header( $charset ) );
			$cursor['header']  = true;
			$cursor['charset'] = $charset;
			$progress          = true;
		}
		while ( $cursor['table'] < $count ) {
			$table = $tables[ $cursor['table'] ];
			$meta  = $this->metadata( $table, $charset );
			if ( ! $cursor['started'] ) {
				$create = $this->create_statement( $table, $charset );
				$emit( 'DROP TABLE IF EXISTS ' . self::ident( $table, $charset ) . ";\n" . $create . ";\n" );
				$cursor['started']       = true;
				$cursor['rows']          = 0;
				$cursor['create_sha256'] = hash( 'sha256', $create );
				$cursor['limit']         = self::initial_limit( $meta['avg_row'], $meta['large'] );
				$progress                = true;
			}
			while ( true ) {
				if ( $progress && $stop() ) {
					return [
						'cursor'      => $cursor,
						'tables_done' => $done,
						'rows'        => $rows,
					];
				}
				$batch = $this->batch( $table, $meta, $cursor );
				$used  = $this->used_limit;
				$bytes = self::batch_bytes( $batch );
				$kept  = $this->without_excluded( $table, $batch );
				$this->emit_inserts( $table, $meta, $kept, $emit, $charset );
				$cursor['rows'] += count( $kept );
				$rows           += count( $kept );
				$progress        = true;
				$fetched         = count( $batch );
				unset( $batch, $kept );
				if ( $fetched > 0 ) {
					if ( ! $meta['seek'] ) {
						$cursor['offset'] += $fetched;
					} else {
						$cursor['after_hex'] = $this->last_key;
					}
				}
				if ( $fetched < $used ) {
					break;
				}
				$cursor['limit'] = self::next_limit( $used, $bytes );
			}
			$done[]            = [
				'name'          => $table,
				'rows'          => $cursor['rows'],
				'create_sha256' => (string) $cursor['create_sha256'],
			];
			$next              = $cursor['table'] + 1;
			$cursor            = self::start();
			$cursor['table']   = $next;
			$cursor['header']  = true;
			$cursor['charset'] = $charset;
		}
		$emit( self::FOOTER );

		return [
			'cursor'      => null,
			'tables_done' => $done,
			'rows'        => $rows,
		];
	}

	/**
	 * Liste non vide de valeurs en hexadécimal minuscule (after_hex, db.tables_hex), chacune relue par
	 * hex2bin() après le contrôle strict de HEX_PATTERN (sans le modificateur D, $ accepterait « ab\n »).
	 *
	 * @phpstan-assert-if-true list<string> $value
	 */
	public static function hex_list( mixed $value ): bool {
		if ( ! is_array( $value ) || [] === $value || ! ArrayShape::is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $hex ) {
			if ( ! is_string( $hex ) || 1 !== preg_match( self::HEX_PATTERN, $hex ) || false === hex2bin( $hex ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Liste des tables relue de l'état (db.tables_hex, docs/backup-payload.md § 4, « Curseur du
	 * dump ») : noms tels que lus dans le jeu du dump, gardés en hexadécimal minuscule.
	 *
	 * @return list<string>
	 * @throws BackupRefused DB_CURSOR_INVALID (non réessayable) : liste absente, vide, hors de sa forme, ou nom vide.
	 */
	public static function tables_from_hex( mixed $value ): array {
		if ( ! self::hex_list( $value ) || in_array( '', $value, true ) ) {
			throw new BackupRefused( BackupRefused::DB_CURSOR_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return array_map( static fn ( string $hex ): string => (string) hex2bin( $hex ), $value );
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
	 * Fuseau de la session (@@session.time_zone), ou '' s'il ne se lit pas.
	 *
	 * @throws BackupRefused DB_READ_FAILED (réessayable).
	 */
	private function session_zone(): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- requête constante : fuseau de la session, pendant le dump (docs/backup-payload.md § 4).
		$row = $this->wpdb->get_row( 'SELECT @@session.time_zone', ARRAY_N );
		$this->fail_on_error( 'information_schema' );

		return is_array( $row ) && isset( $row[0] ) ? (string) $row[0] : '';
	}

	/**
	 * Session en UTC (TIME_ZONE) avant la première lecture d'une avance ; le fuseau d'avant est gardé
	 * pour restore_zone(). Vérifié après le SET : une session restée dans un autre fuseau est un refus.
	 *
	 * @throws BackupRefused DB_READ_FAILED (réessayable) : fuseau illisible, ou autre qu'UTC après le SET.
	 */
	private function utc_session(): void {
		$zone = $this->session_zone();
		if ( self::TIME_ZONE === $zone ) {
			return;
		}
		$this->zone_before = $zone;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- requête constante (TIME_ZONE, '+00:00') : fuseau de la session, variable de session, aucune donnée écrite.
		$this->wpdb->query( "SET time_zone = '" . self::TIME_ZONE . "'" );
		$this->fail_on_error( 'information_schema' );
		if ( self::TIME_ZONE !== $this->session_zone() ) {
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, 'information_schema' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}

	/**
	 * Fin d'une avance, refus compris : la session reprend le fuseau qu'elle avait avant
	 * utc_session() (les requêtes du site qui suivent, NOW() compris, restent dans leur fuseau).
	 * Une valeur hors de ZONE_PATTERN n'est pas réécrite ; un échec n'est pas un refus (le dump est
	 * déjà émis ou refusé).
	 */
	private function restore_zone(): void {
		$before            = $this->zone_before;
		$this->zone_before = null;
		if ( null === $before || 1 !== preg_match( self::ZONE_PATTERN, $before ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- valeur lue de @@session.time_zone et contrôlée par ZONE_PATTERN (ni apostrophe ni \, sûre quel que soit le sql_mode) ; variable de session, aucune donnée écrite.
		$this->wpdb->query( "SET time_zone = '" . $before . "'" );
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
		$this->avg_rows  = null;
		$this->check_session( $charset );
		$this->check_names( $charset, $prefix, $tables );
		$this->end_guard( $charset, $prefix, $tables );
	}

	/**
	 * Juste après une requête du dump, avant d'en employer le résultat (§ 4, « Reconnexion
	 * silencieuse ») : la reconnexion de wpdb::query() rejoue la requête dans une nouvelle session,
	 * qui doit être contrôlée avant que le moindre octet en soit écrit. Après le contrôle complet
	 * (guard_for connu : seulement pendant une avance, tables() le remettant à null avant ses
	 * lectures), la nouvelle session doit aussi être en UTC : wpdb::db_connect() ne règle que le jeu
	 * et le sql_mode, et la requête rejouée dans le fuseau du serveur a lu ses dates TIMESTAMP (et les
	 * valeurs par défaut de SHOW CREATE TABLE) dans ce fuseau.
	 *
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED (réessayable : session rouverte hors d'UTC pendant une avance, rien n'est employé de la requête rejouée), qui nomme $table.
	 */
	private function recheck( string $table ): void {
		if ( null === $this->identity || $this->connection_identity() === $this->identity ) {
			return;
		}
		$this->avg_rows = null;
		if ( null === $this->guard_for ) {
			// Dans tables(), avant que la liste soit connue : la session seulement.
			$this->check_session( (string) $this->session_charset );
			return;
		}
		[ $charset, $prefix, $tables ] = $this->guard_for;
		$this->guarded                 = null;
		$this->guard( $charset, $prefix, $tables );
		if ( self::TIME_ZONE !== $this->session_zone() ) {
			// La tranche suivante repart d'une session neuve, mise en UTC avant toute lecture.
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table).
		}
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
		$this->recheck( $table );

		return $rows;
	}

	/**
	 * Curseur relu de l'état (JSON), contrôlé champ par champ (docs/backup-payload.md § 4, « Curseur
	 * du dump ») : tout champ absent ou hors de sa forme est un refus, jamais un repli (un retour à la
	 * table 0, à l'offset 0 ou au début de la clé recopierait des lignes). Les champs liés le sont
	 * aussi : une table commencée l'est après l'en-tête et a son CREATE ; une table qui ne l'est pas
	 * n'a encore rien lu.
	 *
	 * @param array<string, mixed>|null $cursor
	 * @return Cursor
	 * @throws BackupRefused DB_CURSOR_INVALID (non réessayable).
	 */
	private static function cursor_from( ?array $cursor ): array {
		if ( null === $cursor ) {
			return self::start();
		}
		$table   = $cursor['table'] ?? null;
		$offset  = $cursor['offset'] ?? null;
		$rows    = $cursor['rows'] ?? null;
		$limit   = $cursor['limit'] ?? null;
		$started = $cursor['started'] ?? null;
		$header  = $cursor['header'] ?? null;
		$sha     = array_key_exists( 'create_sha256', $cursor ) ? $cursor['create_sha256'] : false;
		$after   = array_key_exists( 'after_hex', $cursor ) ? $cursor['after_hex'] : false;
		$set     = $cursor['charset'] ?? null;
		if ( ! is_int( $table ) || $table < 0 || ! is_int( $offset ) || $offset < 0 || ! is_int( $rows ) || $rows < 0
			|| ! is_int( $limit ) || $limit < 1 || $limit > self::BATCH_ROWS || ! is_bool( $started ) || ! is_bool( $header )
			|| ! ( null === $sha || ( is_string( $sha ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $sha ) ) )
			|| ! ( null === $after || self::hex_list( $after ) )
			|| ! is_string( $set ) || ! ( $header ? 1 === preg_match( '/\A[a-z0-9]{1,32}\z/', $set ) : '' === $set )
			|| ( $started ? ( ! $header || null === $sha ) : ( null !== $sha || null !== $after || 0 !== $offset || 0 !== $rows ) ) ) {
			throw new BackupRefused( BackupRefused::DB_CURSOR_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return [
			'table'         => $table,
			'after_hex'     => null === $after ? null : array_values( $after ),
			'offset'        => $offset,
			'started'       => $started,
			'header'        => $header,
			'rows'          => $rows,
			'create_sha256' => $sha,
			'limit'         => $limit,
			'charset'       => $set,
		];
	}

	/** Premier lot d'une table : une ligne si elle a une colonne qui peut peser des Mio (LARGE_TYPE), sinon BATCH_BYTES divisé par la taille moyenne d'une ligne, de 1 à BATCH_ROWS. */
	private static function initial_limit( int $average_row, bool $large ): int {
		if ( $large ) {
			return 1;
		}
		return max( 1, min( self::BATCH_ROWS, intdiv( self::BATCH_BYTES, max( 1, $average_row ) ) ) );
	}

	/**
	 * Lot suivant : moitié si le dernier a dépassé BATCH_BYTES (valeurs plus grosses que la moyenne
	 * annoncée), double s'il en a fait moins du quart ; de 1 à BATCH_ROWS.
	 */
	private static function next_limit( int $used, int $bytes ): int {
		if ( $bytes > self::BATCH_BYTES ) {
			return max( 1, intdiv( $used, 2 ) );
		}
		if ( $bytes < intdiv( self::BATCH_BYTES, 4 ) ) {
			return min( self::BATCH_ROWS, $used * 2 );
		}
		return $used;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private static function batch_bytes( array $rows ): int {
		$bytes = 0;
		foreach ( $rows as $row ) {
			foreach ( $row as $value ) {
				$bytes += is_string( $value ) ? strlen( $value ) : 8;
			}
		}
		return $bytes;
	}

	/**
	 * Lot borné en octets pour une table à colonne LARGE_TYPE : les longueurs des lignes du lot (mêmes
	 * WHERE, ORDER BY et LIMIT, sans les valeurs) sont lues d'abord, et le LIMIT réduit aux lignes dont la
	 * somme reste sous BATCH_BYTES (au moins une : une ligne seule plus grosse reste un lot d'une ligne).
	 * Sans cela, le lot doublerait après de petites lignes (next_limit()) et lirait d'un coup des Mio de
	 * valeurs (révisions de constructeur de pages après de petits articles). Le LIMIT n'est réduit que
	 * si le lot est tronqué : un lot qui rend moins de lignes que le LIMIT garde son LIMIT (fin de table).
	 *
	 * @param Meta   $meta
	 * @param \Closure(int): string $tail Suite de la requête après la table (WHERE, ORDER BY, LIMIT, OFFSET) pour un LIMIT donné.
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable), DB_READ_FAILED.
	 */
	private function bounded_limit( string $table, array $meta, string $name, \Closure $tail, int $limit, string $charset ): int {
		$sizes = implode(
			'+',
			array_map( static fn ( string $c ): string => 'COALESCE(LENGTH(' . self::ident( $c, $charset ) . '),0)', $meta['columns'] )
		);
		$rows  = $this->rows( 'SELECT (' . $sizes . ') AS g2rd_bytes FROM ' . $name . $tail( $limit ), $table );
		$sum   = 0;
		$keep  = 0;
		foreach ( $rows as $row ) {
			$size = (int) ( $row['g2rd_bytes'] ?? 0 );
			if ( $keep > 0 && $sum + $size > self::BATCH_BYTES ) {
				return $keep;
			}
			$sum += $size;
			++$keep;
		}

		return $limit;
	}

	/**
	 * Colonnes lues et colonnes de l'INSERT (et lesquelles sont binaires, lesquelles sont des FLOAT lus
	 * par leur texte exact, FLOAT_TYPE), clé primaire dans son ordre, lecture de la table (seek),
	 * taille moyenne des lignes. Chaque nom de colonne passe par
	 * ident() : un nom mal formé dans le jeu est refusé avant le premier octet de la table et avant
	 * toute lecture de ses lignes.
	 *
	 * Une colonne générée (GENERATED_EXTRA) n'est jamais dans l'INSERT ; elle n'est lue que si elle
	 * est dans la clé, dont key_hex() relève les valeurs (PRIMARY (id, re), où MariaDB ajoute la
	 * colonne ROW END d'une table à versions système), ou si la table n'a que des colonnes générées :
	 * elles sont alors lues pour compter les lignes (un SELECT sans colonne est une erreur de
	 * syntaxe), et l'INSERT n'en nomme aucune (INSERT INTO t () VALUES (), (), admis par MariaDB
	 * 10.11.19 et MySQL 8.4.0 en mode strict).
	 *
	 * seek est vrai pour une table dont la clé primaire sert à la reprise (WHERE (clé) > (curseur)) :
	 * elle en a une, et aucune de ses colonnes n'est d'un type de UNSEEKABLE_TYPE (une colonne de ces
	 * types hors de la clé ne change rien). Faux, la table se lit par offset : ORDER BY la clé
	 * LIMIT … OFFSET … si elle en a une (ordre déterministe, la clé étant unique), LIMIT … OFFSET …
	 * sinon.
	 *
	 * @return Meta
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED, DB_TABLES_NOT_FOUND (non réessayables), DB_READ_FAILED.
	 */
	private function metadata( string $table, string $charset ): array {
		$names      = [];
		$binary     = [];
		$float      = [];
		$unseekable = [];
		$generated  = [];
		$large      = false;
		foreach ( $this->rows( 'SHOW FULL COLUMNS FROM ' . self::ident( $table, $charset ), $table ) as $column ) {
			$name = (string) ( $column['Field'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			self::ident( $name, $charset );
			$type                = (string) ( $column['Type'] ?? '' );
			$names[]             = $name;
			$binary[ $name ]     = 1 === preg_match( self::BINARY_TYPE, $type );
			$float[ $name ]      = 1 === preg_match( self::FLOAT_TYPE, $type );
			$unseekable[ $name ] = 1 === preg_match( self::UNSEEKABLE_TYPE, $type );
			$generated[ $name ]  = 1 === preg_match( self::GENERATED_EXTRA, (string) ( $column['Extra'] ?? '' ) );
			$large               = $large || 1 === preg_match( self::LARGE_TYPE, $type );
		}
		if ( [] === $names ) {
			// Aucune colonne lue : ni SELECT ni INSERT ne s'écriraient ; refus avant le premier octet de la table.
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table).
		}
		$keys = $this->rows( 'SHOW KEYS FROM ' . self::ident( $table, $charset ) . " WHERE Key_name = 'PRIMARY'", $table );
		usort( $keys, static fn ( array $a, array $b ): int => (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 ) );
		$pk     = array_values( array_map( static fn ( array $key ): string => (string) ( $key['Column_name'] ?? '' ), $keys ) );
		$in_key = array_flip( $pk );
		$insert = array_values( array_filter( $names, static fn ( string $c ): bool => ! $generated[ $c ] ) );
		$read   = array_values( array_filter( $names, static fn ( string $c ): bool => ! $generated[ $c ] || isset( $in_key[ $c ] ) ) );

		return [
			'columns' => [] === $read ? $names : $read,
			'insert'  => $insert,
			'binary'  => $binary,
			'float'   => $float,
			'pk'      => $pk,
			'seek'    => [] !== $pk && [] === array_filter( $pk, static fn ( string $c ): bool => $unseekable[ $c ] ?? false ),
			'avg_row' => $this->avg_row( $table ),
			'large'   => $large,
		];
	}

	/**
	 * Taille moyenne d'une ligne de $table (§ 4, « Tables copiées ») : lue par la requête de la liste,
	 * une fois par connexion ; la ligne retenue est celle dont TABLE_NAME égale $table octet pour
	 * octet ; 0 sinon (d'où le lot initial de BATCH_ROWS). Aucun nom dans une chaîne (esc_like()).
	 *
	 * @throws BackupRefused DB_TABLES_NOT_FOUND, DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function avg_row( string $table ): int {
		if ( null === $this->avg_rows ) {
			$read = [];
			foreach ( $this->rows( self::tables_query( self::like_prefix( (string) $this->wpdb->base_prefix ) ), $table ) as $row ) {
				$values = array_values( $row );
				$read[ (string) ( $values[0] ?? '' ) ] = max( 0, (int) ( $values[1] ?? 0 ) );
			}
			$this->avg_rows = $read;
		}
		return $this->avg_rows[ $table ] ?? 0;
	}

	/**
	 * Lot suivant : par clé primaire (comparaison de tuples, ordre de la clé) si elle sert à la
	 * reprise (seek, metadata()) ; sinon par offset, ORDER BY la clé s'il y en a une (clé qui contient
	 * un type de UNSEEKABLE_TYPE). Colonnes nommées, celles de SHOW FULL COLUMNS dans leur ordre sauf
	 * les colonnes générées hors de la clé (metadata()), jamais SELECT * : une colonne INVISIBLE
	 * (MySQL 8.0.23+, MariaDB 10.3+), dont my_row_id, la clé que MySQL 8.0.30+ ajoute avec
	 * sql_generate_invisible_primary_key, n'est rendue que nommée ; les colonnes lues sont ainsi
	 * celles de l'INSERT, et celles de la clé. Une colonne FLOAT se lit `c`*1e0 AS `c` (FLOAT_TYPE) :
	 * l'alias garde son nom, que key_hex() et l'INSERT cherchent dans la ligne. Lue par offset, une
	 * table à clé s'ordonne par les colonnes de la clé qualifiées par la table (ORDER BY `t`.`c`) :
	 * ORDER BY `c` prendrait l'alias, une expression, et trierait toute la table sans l'index (EXPLAIN
	 * du 08/11/2026 : « Using filesort » sans clé ; avec `t`.`c`, PRIMARY), dans le même ordre (le
	 * double d'un float le garde). Par la clé, aucune colonne de la clé n'est un FLOAT (UNSEEKABLE_TYPE) :
	 * ORDER BY et WHERE nomment les colonnes elles-mêmes. Requête bâtie sans $wpdb->prepare() : noms
	 * par ident(), valeurs de la clé par key_literal() (hexadécimal, quel que soit le sql_mode du
	 * site), entiers convertis.
	 *
	 * Jamais une reprise au début de la table, qui recopierait des lignes (§ 4, « Curseur du dump ») :
	 * refus avant la requête si le curseur a une clé de reprise et que la table ne se lit pas par la
	 * clé (clé retirée, ou colonne de la clé devenue d'un type de UNSEEKABLE_TYPE), ou pas autant de
	 * valeurs que la clé, ou s'il avance par offset une table qui se lit maintenant par la clé (clé
	 * ajoutée entre deux tranches, ou colonne de la clé qui n'est plus d'un de ces types) ;
	 * refus avant d'émettre le lot si une ligne lue n'a pas une colonne de la clé (key_hex()), ou a la
	 * clé du curseur : la reprise n'a pas avancé (WHERE (clé) > (curseur) ne rend jamais la ligne du
	 * curseur, déjà écrite).
	 *
	 * @param Meta   $meta
	 * @param Cursor $cursor
	 * @return list<array<string, mixed>>
	 * @throws BackupRefused DB_CURSOR_INVALID, DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function batch( string $table, array $meta, array $cursor ): array {
		$pk      = $meta['pk'];
		$seek    = $meta['seek'];
		$limit   = $cursor['limit'];
		$charset = $cursor['charset'];
		$after   = $cursor['after_hex'];
		if ( null !== $after && ( ! $seek || count( $after ) !== count( $pk ) ) ) {
			// Reprise par la clé d'une table qui se lit par offset (sans clé, ou clé devenue d'un type de UNSEEKABLE_TYPE), ou
			// clé changée pendant la sauvegarde : jamais un WHERE aux valeurs décalées, ni un offset 0 qui recopierait la table.
			throw new BackupRefused( BackupRefused::DB_CURSOR_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( $seek && 0 !== $cursor['offset'] ) {
			// Table commencée par offset (sans clé, ou clé qui contient un type de UNSEEKABLE_TYPE) qui se lit maintenant par la
			// clé (clé ajoutée ou colonne changée entre deux tranches) : le lot par la clé repartirait du début et recopierait les
			// lignes déjà écrites.
			throw new BackupRefused( BackupRefused::DB_CURSOR_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$name    = self::ident( $table, $charset );
		$columns = implode( ',', array_map( static fn ( string $c ): string => self::ident( $c, $charset ), $pk ) );
		if ( ! $seek ) {
			// ORDER BY une clé (unique) qui contient un type de UNSEEKABLE_TYPE : ordre déterministe d'un lot à l'autre ; colonnes
			// qualifiées par la table, jamais l'alias d'un FLOAT lu par son texte exact.
			$order = implode( ',', array_map( static fn ( string $c ): string => $name . '.' . self::ident( $c, $charset ), $pk ) );
			$tail  = static fn ( int $n ): string => ( [] === $pk ? '' : ' ORDER BY ' . $order ) . ' LIMIT ' . $n . ' OFFSET ' . (int) $cursor['offset'];
		} elseif ( null === $after ) {
			$tail = static fn ( int $n ): string => ' ORDER BY ' . $columns . ' LIMIT ' . $n;
		} else {
			$values = implode(
				',',
				array_map(
					static fn ( string $c, string $h ): string => self::key_literal( (string) hex2bin( $h ), $meta['binary'][ $c ] ?? false, $charset ),
					$pk,
					$after
				)
			);
			$tail   = static fn ( int $n ): string => ' WHERE (' . $columns . ') > (' . $values . ') ORDER BY ' . $columns . ' LIMIT ' . $n;
		}
		if ( $meta['large'] && $limit > 1 ) {
			$limit = $this->bounded_limit( $table, $meta, $name, $tail, $limit, $charset );
		}
		$this->used_limit = $limit;
		$sql              = 'SELECT ' . self::select_list( $meta['columns'], $meta['float'], $charset ) . ' FROM ' . $name . $tail( $limit );
		$rows = $this->rows( $sql, $table );
		// Clé de chaque ligne contrôlée (présente, non nulle), même lue par offset ; celle de la dernière, relevée ici en
		// hexadécimal, sert à la reprise par la clé : l'appelant peut libérer le lot avant d'avancer.
		$this->last_key = null;
		if ( [] !== $pk ) {
			foreach ( $rows as $row ) {
				$key = self::key_hex( $table, $pk, $row );
				if ( $key === $after ) {
					// WHERE (clé) > (curseur) ne rend jamais la ligne du curseur, déjà écrite : la reprise n'a pas avancé, le
					// lot recopierait des lignes et le suivant relirait les mêmes, sans fin. Rien n'est émis de ce lot.
					throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table).
				}
				$this->last_key = $key;
			}
		}

		return $rows;
	}

	/**
	 * Colonnes lues d'un SELECT, dans leur ordre, chacune par ident() ; une colonne FLOAT
	 * (FLOAT_TYPE) par `c`*1e0 AS `c`, le double exact du float rangé, sous son propre nom.
	 *
	 * @param list<string>        $columns
	 * @param array<string, bool> $floats Colonnes FLOAT (FLOAT_TYPE).
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable) : nom mal formé dans $charset.
	 */
	private static function select_list( array $columns, array $floats, string $charset ): string {
		return implode(
			',',
			array_map(
				static function ( string $c ) use ( $floats, $charset ): string {
					$ident = self::ident( $c, $charset );
					return ( $floats[ $c ] ?? false ) ? $ident . '*1e0 AS ' . $ident : $ident;
				},
				$columns
			)
		);
	}

	/**
	 * Clé primaire de $row en hexadécimal, dans l'ordre de la clé. Une colonne de la clé absente de la
	 * ligne lue, ou nulle (une clé primaire ne l'est jamais), est un refus, jamais '' : la reprise
	 * WHERE (clé) > ('') repartirait du début de la table (une clé entière comparée à '' compare à 0).
	 *
	 * @param list<string>         $pk
	 * @param array<string, mixed> $row
	 * @return list<string>
	 * @throws BackupRefused DB_READ_FAILED (réessayable, rien n'est émis du lot), qui nomme la table.
	 */
	private static function key_hex( string $table, array $pk, array $row ): array {
		$hex = [];
		foreach ( $pk as $column ) {
			if ( ! isset( $row[ $column ] ) ) {
				throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table).
			}
			$hex[] = bin2hex( (string) $row[ $column ] );
		}

		return $hex;
	}

	/**
	 * @throws BackupRefused DB_CHARSET_MISMATCH, DB_NAME_UNSUPPORTED (non réessayables), DB_READ_FAILED.
	 */
	private function create_statement( string $table, string $charset ): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- requête bâtie ici : nom de la table par ident() (§ 4), aucune valeur ; lecture de la base pour la sauvegarde, rien à mettre en cache.
		$row = $this->wpdb->get_row( 'SHOW CREATE TABLE ' . self::ident( $table, $charset ), ARRAY_A );
		$this->fail_on_error( $table );
		$this->recheck( $table );
		$create = is_array( $row ) ? ( $row['Create Table'] ?? null ) : null;
		if ( ! is_string( $create ) || '' === $create ) {
			throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, $table ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et nom de table).
		}

		return $create;
	}

	/**
	 * Instructions INSERT d'un lot, sur les colonnes de l'INSERT ($meta['insert'] : sans les colonnes
	 * générées, qu'une colonne de la clé lue en plus ne rejoint pas).
	 *
	 * @param Meta                       $meta
	 * @param list<array<string, mixed>> $rows
	 * @param \Closure(string): void     $emit
	 * @throws BackupRefused DB_NAME_UNSUPPORTED (non réessayable).
	 */
	private function emit_inserts( string $table, array $meta, array $rows, \Closure $emit, string $charset ): void {
		$columns = $meta['insert'];
		$pending = [];
		$size    = 0;
		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $row as $column => $value ) {
				$values[ (string) $column ] = null === $value ? null : (string) $value;
				$size                      += null === $value ? 4 : strlen( (string) $value ) + 3;
			}
			$pending[] = $values;
			if ( $size >= self::FLUSH_BYTES ) {
				$emit( self::insert( $table, $columns, $meta['binary'], $pending, $charset ) );
				$pending = [];
				$size    = 0;
			}
		}
		if ( [] !== $pending ) {
			$emit( self::insert( $table, $columns, $meta['binary'], $pending, $charset ) );
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function without_excluded( string $table, array $rows ): array {
		$names = $this->excluded_rows[ $table ] ?? null;
		if ( null === $names ) {
			return $rows;
		}

		return array_values( array_filter( $rows, static fn ( array $row ): bool => ! isset( $names[ (string) ( $row['option_name'] ?? '' ) ] ) ) );
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
