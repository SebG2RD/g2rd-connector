<?php
/**
 * Première barrière de filtrage du texte d'une erreur PHP avant qu'il quitte le site
 * (évolution stratégique, § 6 ; connecteur 1.16).
 *
 * Le message d'une erreur PHP peut porter le chemin du compte d'hébergement (`/home/u…/`,
 * commun à tous les sites du même compte et au manager), des identifiants de base, des
 * e-mails, des adresses IP, des jetons, des mots de passe, une requête SQL avec ses valeurs ou
 * la valeur saisie par un visiteur que MySQL recopie (valeur en double, valeur incorrecte).
 * Rien de cela ne sort du site. Le manager applique ensuite son propre filtre (seconde
 * barrière) ; les deux suivent les mêmes vecteurs (tests/fixtures/php-error-redaction-vectors.json,
 * partagé à l'octet).
 *
 * Barrière propre au connecteur, appliquée après les motifs : toute occurrence des valeurs du
 * site qui ne doivent jamais sortir (DB_NAME, DB_USER, DB_HOST et son hôte sans le port,
 * DB_PASSWORD, d'au moins 3 caractères, voir known_values()), casse ignorée, est masquée par
 * `[secret]`, sauf au milieu d'un mot plus long. Elle couvre les formes de message qu'aucun
 * motif ne prévoit. Les
 * valeurs par défaut communes à toutes les installations (« localhost », « root »…) n'en sont
 * pas : elles ne disent rien du site. Elle n'entre pas dans les vecteurs partagés : le manager
 * ne connaît pas ces valeurs, et les vecteurs se prouvent par les seuls motifs.
 *
 * Ce qui reste lisible : le chemin relatif à WordPress (`wp-content/plugins/x/x.php:12`), les
 * noms de fonctions, de classes et de méthodes, les numéros de version, le texte de l'erreur.
 * Les masques sont courts, en anglais neutre, distincts de ceux du manager (`[path]`, `[email]`…).
 *
 * Règles des motifs : répétitions possessives ou bornées, texte borné à 16 000 octets puis coupé
 * à 4 000 caractères avant filtrage. Un motif qui échoue rend UNREADABLE, jamais un texte à moitié
 * masqué. Classe pure, sans effet au chargement, appelable dans une fonction d'arrêt.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

final class Redactor {

	public const MASK_PATH   = '[path]';
	public const MASK_EMAIL  = '[email]';
	public const MASK_IP     = '[ip]';
	public const MASK_TOKEN  = '[token]';
	public const MASK_SECRET = '[secret]';
	public const MASK_SQL    = '[sql]';
	public const MASK_QUERY  = '[query]';
	public const MASK_DSN    = '[dsn]';

	/** Rendu quand un motif échoue ou que le texte est vide : jamais un texte à moitié masqué. */
	public const UNREADABLE = '[unreadable]';

	/** Longueur maximale du texte rendu, en caractères, « … » final compris (contrat : 500). */
	public const MAX_CHARS = 500;

	/** Longueur lue avant filtrage, en caractères. */
	private const INPUT_MAX_CHARS = 4000;

	/**
	 * Borne en octets posée avant tout traitement : un caractère UTF-8 fait au plus 4 octets.
	 * Le message d'une exception non rattrapée n'a aucune borne ; sans elle, un message de
	 * plusieurs mégaoctets serait recopié en entier en mémoire, jusque dans une fonction d'arrêt.
	 */
	private const INPUT_MAX_BYTES = 4 * self::INPUT_MAX_CHARS;

	/** Fin d'un nom de champ ou de paramètre dont la valeur est secrète. */
	private const SECRET_NAMES = 'token|key|password|passwd|pwd|pass|secret|ticket|sig|signature|nonce|auth|hash|credentials?|cookie|session';

	/** Constantes de wp-config.php dont la valeur ne sort jamais du site (barrière par valeur). */
	private const KNOWN_CONSTANTS = [ 'DB_NAME', 'DB_USER', 'DB_HOST', 'DB_PASSWORD' ];

	/** Longueur minimale d'une valeur connue masquée, en caractères. */
	private const KNOWN_VALUE_MIN_CHARS = 3;

	/**
	 * Valeurs par défaut communes à toutes les installations, jamais masquées : elles ne disent
	 * rien du site, et les masquer changerait en `[secret]` les mots « WordPress », « localhost »,
	 * « root » ou « MySQL » de tous les messages (comparées en minuscules, port ou socket ôtés).
	 */
	private const GENERIC_VALUES = [ 'localhost', '127.0.0.1', '::1', 'wordpress', 'root', 'mysql', 'mariadb', 'database' ];

	/**
	 * Racine du serveur devant le dossier de WordPress (même motif que SiteWarningText du
	 * manager) : « /home/u…/public_html/wp-content/ » devient « wp-content/ ».
	 */
	private const WORDPRESS_ROOT = '#(?:(?:\bfile:///)?(?<!\w)[A-Za-z]:|\bfile://|(?<![\w/.]))/(?:[\w.\-~]+/)+?(wp-content|wp-admin|wp-includes)/#u';

	/**
	 * Texte filtré, `$max_chars` caractères au plus.
	 *
	 * @param string                     $text         Texte brut de l'erreur.
	 * @param array<string, string>|null $roots        Racines du site => remplacement (null : default_roots()).
	 * @param int                        $max_chars    Longueur maximale rendue (500 pour le bloc php_errors).
	 * @param list<string>|null          $known_values Valeurs du site à masquer partout (null : known_values() ;
	 *                                                 [] : barrière par valeur coupée, motifs seuls).
	 */
	public static function redact( string $text, ?array $roots = null, int $max_chars = self::MAX_CHARS, ?array $known_values = null ): string {
		$text = self::strip_roots( self::normalize( $text ), $roots ?? self::default_roots() );

		$result = preg_replace( self::WORDPRESS_ROOT, '$1/', $text );
		if ( null === $result ) {
			return self::UNREADABLE;
		}
		$text = $result;

		foreach ( self::patterns() as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null === $result ) {
				return self::UNREADABLE;
			}
			$text = $result;
		}

		$values = null === $known_values ? self::known_values() : self::maskable( $known_values );
		if ( [] !== $values ) {
			$result = preg_replace( self::known_values_pattern( $values ), self::MASK_SECRET, $text );
			if ( null === $result ) {
				return self::UNREADABLE;
			}
			$text = $result;
		}

		$text = trim( $text );

		return '' === $text ? self::UNREADABLE : self::cut( $text, max( 2, $max_chars ) );
	}

	/**
	 * Valeurs du site masquées par la barrière propre au connecteur : DB_NAME, DB_USER, DB_HOST et
	 * DB_PASSWORD quand ces constantes sont définies, plus l'hôte de DB_HOST sans son port ni sa
	 * socket (« srv123.hstgr.io:3306 » donne aussi « srv123.hstgr.io »). DB_PASSWORD rattrape un
	 * mot de passe écrit sans nom de champ devant, trop court pour les motifs de jeton. Une valeur
	 * de moins de 3 caractères ou commune à toutes les installations (« localhost », « root », une
	 * base nommée « WordPress »…, voir GENERIC_VALUES) n'est pas retenue. De la plus longue à la
	 * plus courte, sans doublon (casse ignorée).
	 *
	 * @return list<string>
	 */
	public static function known_values(): array {
		$values = [];
		foreach ( self::KNOWN_CONSTANTS as $name ) {
			$value = defined( $name ) ? constant( $name ) : null;
			if ( ! is_string( $value ) ) {
				continue;
			}
			$values[] = $value;
			if ( 'DB_HOST' === $name && 1 === preg_match( '/^\s*+([^:\s]++):(?:\d{1,5}|\/\S*+)\s*+$/', $value, $host ) ) {
				$values[] = $host[1];
			}
		}

		return self::maskable( $values );
	}

	/**
	 * Valeurs retenues par la barrière : texte UTF-8 valide, blancs de bord ôtés, au moins
	 * 3 caractères, hors valeurs communes à toutes les installations, sans doublon (casse
	 * ignorée), de la plus longue à la plus courte (la plus longue l'emporte dans le motif).
	 *
	 * @param array<mixed> $values Valeurs candidates.
	 * @return list<string>
	 */
	private static function maskable( array $values ): array {
		$kept = [];
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
				continue;
			}
			$value   = trim( $value );
			$lower   = mb_strtolower( $value );
			$generic = in_array( $lower, self::GENERIC_VALUES, true )
				|| in_array( (string) preg_replace( '/:(?:\d{1,5}|\/\S*+)\z/', '', $lower ), self::GENERIC_VALUES, true );
			if ( mb_strlen( $value ) < self::KNOWN_VALUE_MIN_CHARS || $generic ) {
				continue;
			}
			$kept[ $lower ] = $value;
		}
		$kept = array_values( $kept );
		usort( $kept, static fn ( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		return $kept;
	}

	/**
	 * Motif de la barrière par valeur : chaque valeur, casse ignorée, ni au milieu d'un mot (lettre
	 * ou chiffre de part et d'autre ; un « _ » voisin n'empêche rien) ni dans un masque déjà posé
	 * (« [secret] » reste tel quel même si une valeur vaut « secret »).
	 *
	 * @param list<string> $values Valeurs retenues par maskable().
	 */
	private static function known_values_pattern( array $values ): string {
		$masks  = [ self::MASK_PATH, self::MASK_EMAIL, self::MASK_IP, self::MASK_TOKEN, self::MASK_SECRET, self::MASK_SQL, self::MASK_QUERY, self::MASK_DSN, self::UNREADABLE ];
		$quoted = static fn ( string $value ): string => preg_quote( $value, '/' );

		return '/(?:' . implode( '|', array_map( $quoted, $masks ) ) . ')(*SKIP)(*FAIL)|(?<![\p{L}\p{N}])(?:' . implode( '|', array_map( $quoted, $values ) ) . ')(?![\p{L}\p{N}])/iu';
	}

	/**
	 * Racines du site, du plus long au plus court : WP_CONTENT_DIR devient « wp-content/ »,
	 * ABSPATH disparaît. Barres obliques normalisées, « / » final.
	 *
	 * @return array<string, string>
	 */
	public static function default_roots(): array {
		$roots = [];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/' ] = 'wp-content/';
		}
		if ( defined( 'ABSPATH' ) ) {
			$roots[ rtrim( str_replace( '\\', '/', (string) ABSPATH ), '/' ) . '/' ] = '';
		}

		return $roots;
	}

	/**
	 * Texte borné à 16 000 octets avant tout traitement (mémoire et temps constants quelle que
	 * soit la taille du message), octets invalides remplacés (sinon json_encode() refuserait tout
	 * le bloc ; un caractère coupé par la borne l'est aussi), texte ramené à 4 000 caractères,
	 * caractères de contrôle, de mise en forme invisibles et blancs ramenés à une espace.
	 */
	private static function normalize( string $text ): string {
		$text  = substr( $text, 0, self::INPUT_MAX_BYTES );
		$text  = htmlspecialchars_decode( htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
		$text  = mb_substr( $text, 0, self::INPUT_MAX_CHARS );
		$clean = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\s]++/u', ' ', $text );

		return null === $clean ? '' : $clean;
	}

	/**
	 * Retire les racines du site, que leurs séparateurs soient « / », « \ » ou mêlés.
	 *
	 * @param array<string, string> $roots
	 */
	private static function strip_roots( string $text, array $roots ): string {
		uksort( $roots, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		foreach ( $roots as $root => $replacement ) {
			$segments = explode( '/', rtrim( str_replace( '\\', '/', $root ), '/' ) );
			if ( count( $segments ) < 2 ) {
				// Jamais « / » seul : tout chemin y passerait.
				continue;
			}
			$pattern = '#(?<![\w.\-~])' . implode( '[\\\\/]', array_map( static fn ( string $segment ): string => preg_quote( $segment, '#' ), $segments ) ) . '[\\\\/]#u';
			$result  = preg_replace( $pattern, str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $replacement ), $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		return $text;
	}

	/**
	 * Motifs, dans l'ordre d'application : fragment d'une erreur de syntaxe SQL (« near '…' at
	 * line N », 1064), requêtes SQL (elles emportent leurs valeurs), chaînes de connexion, comptes
	 * de base (« 'compte'@'hôte' », entre apostrophes ou accents graves, définisseur compris), noms
	 * de base des messages MySQL et MariaDB (tables, routines, vues, colonnes en trois parties,
	 * noms en trois parties entre accents graves, clés étrangères, contraintes, fichiers de
	 * données, routines inconnues), valeurs recopiées par MySQL (en double, incorrectes),
	 * constantes de wp-config, en-têtes, champs secrets, requêtes d'adresses, e-mails, adresses IP,
	 * chemins du serveur, jetons.
	 *
	 * @return array<string, string>
	 */
	private static function patterns(): array {
		$names = self::SECRET_NAMES;
		// Suite d'une valeur incorrecte recopiée par MySQL : colonne, fonction, requête de WordPress,
		// fichier d'une exception (« in wp-includes/… »), ou fin du texte.
		$value_end = '(?=\s++(?:for\s++(?:column|function|query)|in)\b|\s*+\z)';

		return [
			// Erreur de syntaxe SQL (1064) : « near '…' at line N » recopie un morceau de la requête et
			// ses valeurs (souvent une valeur collée sans préparation). Répétition paresseuse bornée.
			'/\b(near\s++)\'.{0,1000}?\'(?=\s++at\s++line\s++\d)/u' => '$1' . self::MASK_SQL,
			// Requête SQL, mots-clés en majuscules, avec sa structure.
			'/\b(?:SELECT\b[^;]{0,300}?\bFROM\b|INSERT\s++(?:IGNORE\s++)?INTO\b|REPLACE\s++INTO\b|UPDATE\s++`?[\w.]++`?\s++SET\b|DELETE\s++FROM\b|(?:ALTER|DROP|CREATE|TRUNCATE)\s++TABLE\b)[^;]{0,1000}+;?/u' => self::MASK_SQL,
			// En minuscules : seulement sur une table au nom de table (« wp_users »).
			'/\b(?:select\b[^;]{0,300}?\bfrom\s++`?\w*_\w*|insert\s++(?:ignore\s++)?into\s++`?\w*_\w*|update\s++`?\w*_\w*`?\s++set\b|delete\s++from\s++`?\w*_\w*)[^;]{0,1000}+;?/iu' => self::MASK_SQL,
			// Chaînes de connexion (PDO, Redis, Memcached).
			'/\b(?:mysqli?|pgsql|sqlite|sqlsrv|rediss?|memcached?):(?:\/\/)?[^\s"\'<>]{1,500}+/i' => self::MASK_DSN,
			// Compte de base de données : sur Hostinger, il commence par l'identifiant du compte.
			// « Access denied for user '…'@'…' », « command denied to user '…'@'…' », puis la même
			// forme ailleurs (« definer ('…'@'…') », sans blanc), la même entre accents graves
			// (« DEFINER=`…`@`…` » d'un CREATE TRIGGER, VIEW ou PROCEDURE), puis le compte sans hôte
			// des limites de connexions (« User '…' has exceeded », « User … already has more »).
			'/(\b(?:for|to)\s++user\s++)\'[^\']{0,100}+\'@\'[^\']{0,255}+\'/i' => '$1' . self::MASK_SECRET,
			'/\'[^\'\s]{0,100}+\'@\'[^\'\s]{0,255}+\'/u' => self::MASK_SECRET,
			'/`[^`\s]{0,100}+`@`[^`\s]{0,255}+`/u' => self::MASK_SECRET,
			'/\b(User\s++)(?:\'[^\']{1,100}+\'|[\w.\-]{1,100}+)(?=\s++(?:has\s++exceeded|already\s++has\s++more)\b)/iu' => '$1' . self::MASK_SECRET,
			// Nom de la base dans les messages MySQL et MariaDB. La table ou la routine reste lisible :
			// « Table '[secret].wp_options' », « Table './[secret]/wp_options' » (table abîmée),
			// « for table `[secret]`.`wp_options` », « for routine '[secret].calcul' ».
			'/\b((?:table|routine)\s++)\'(\.\/)?[^\'.\/\s]{1,100}+([.\/])([^\'\s]{1,200}+)\'?/iu' => '$1\'$2' . self::MASK_SECRET . '$3$4\'',
			// Même chose entre accents graves, et derrière « failed for » (contrainte de MariaDB, 4025 :
			// « CONSTRAINT `t.c` failed for `[secret]`.`t` »).
			'/\b((?:table|routine|failed\s++for)\s++)`[^`\s]{1,100}+`\./iu' => '$1`' . self::MASK_SECRET . '`.',
			// Vue invalide (1356) : « View '[secret].wp_vue' references invalid table(s)… ». Seule la
			// forme de MySQL : la vue d'un gabarit (« View 'admin.settings' not found ») reste lisible.
			'/\b(View\s++)\'[^\'.\s]{1,100}+\.(?=[^\'\s]{1,200}+\'\s++references\b)/u' => '$1\'' . self::MASK_SECRET . '.',
			// Colonne en trois parties, base, table et colonne (1054, 1055, 3065) : « column
			// '[secret].wp_posts.post_date' », puis la forme de MariaDB « '[secret].t.c' isn't in GROUP BY ».
			// Une colonne en deux parties (« 'wp_posts.foo' ») n'a pas de base : elle reste lisible.
			'/\b(column\s++)\'[^\'.\s]{1,100}+\.(?=[^\'.\s]{1,200}+\.[^\'\s]{1,200}+\')/iu' => '$1\'' . self::MASK_SECRET . '.',
			'/\'[^\'.\s]{1,100}+\.(?=[^\'.\s]{1,200}+\.[^\'\s]{1,200}+\'\s++isn\'t\s++in\s++GROUP\s++BY\b)/iu' => '\'' . self::MASK_SECRET . '.',
			// Nom en trois parties entre accents graves, dont la première est toujours la base (MariaDB :
			// 1366, 1265, 1690) : « for column `[secret]`.`wp_postmeta`.`meta_value` ».
			'/`[^`\s]{1,100}+`\.(?=`[^`\s]{1,200}+`\.`)/u' => '`' . self::MASK_SECRET . '`.',
			// Clé étrangère (1451, 1452) : « a foreign key constraint fails (`[secret]`.`t`, CONSTRAINT … ».
			'/(\(\s*+)`[^`\s]{1,100}+`\.(?=`[^`\s]{1,200}+`\s*+,\s*+CONSTRAINT\b)/iu' => '$1`' . self::MASK_SECRET . '`.',
			// « Unknown database '…' », « to database '…' », « Can't create database '…' ».
			'/\b(database\s++)([\'`])[^\'`]{0,100}+\2?/iu' => '$1$2' . self::MASK_SECRET . '$2',
			// Fichier du dossier de données, relatif à celui de MySQL : le premier segment est la base
			// (« Can't find file: './[secret]/wp_options.frm' », « Error on rename of './[secret]/#sql-1' »).
			// Les dossiers de WordPress et les inclusions de PHP restent lisibles (« './wp-content/…' »,
			// « Failed opening required './vendor/autoload.php' ») : une table de MySQL n'a jamais de
			// point dans son nom. L'anticipation est paresseuse et bornée (possessive, elle échouerait
			// toujours).
			'/\'\.\/(?!wp-content\/|wp-admin\/|wp-includes\/)(?![^\'\s]{0,300}?\.php\')[^\'\/\s]{1,100}+\//u' => '\'./' . self::MASK_SECRET . '/',
			// Routine stockée inconnue : « FUNCTION [secret].calcul does not exist ».
			'/\b((?:FUNCTION|PROCEDURE)\s++)(`?)[^\s.\'`]{1,100}+\2\.(?=\S{1,200}+\s++does\s++not\s++exist\b)/u' => '$1$2' . self::MASK_SECRET . '$2.',
			// Valeur en double (souvent un identifiant de connexion ou une adresse) : la clé reste.
			'/\b(Duplicate entry\s++)\'(?:.{0,300}?\'(?=\s++for\s++key\b)|[^\']{0,300}+\'?)/iu' => '$1\'' . self::MASK_SECRET . '\'',
			// Valeur incorrecte recopiée par MySQL et MariaDB (1366, 1292, 1411, 1525), souvent saisie
			// par un visiteur (téléphone, date de naissance, nom) : « Incorrect integer value: '[secret]'
			// for column 'tel' », « Truncated incorrect DOUBLE value: '[secret]' », « … for function
			// str_to_date ». Le type (« CHAR(10) » compris), la colonne et la fonction restent lisibles.
			// Une apostrophe dans la valeur (« O'Brien ») est prise jusqu'à la suite connue du message.
			// Une valeur vide reste lisible : elle ne dit rien du visiteur, et c'est elle qu'il faut voir.
			'/\b((?:Truncated\s++)?Incorrect\s++(?:[^\s:\']{1,32}+\s++){1,3}value:\s*+)\'(?!\'' . $value_end . ')(?:.{0,300}?\'' . $value_end . '|[^\']{0,300}+\'?)/iu' => '$1\'' . self::MASK_SECRET . '\'',
			// Valeur d'une constante (wp-config.php : base, clés, sels).
			'/\bdefine\s*+\(\s*+([\'"])(\w++)\1\s*+,\s*+(?:\'[^\']{0,500}+\'|"[^"]{0,500}+"|[^\s,)]{1,500}+)/u' => 'define( $1$2$1, \'' . self::MASK_SECRET . '\'',
			// En-têtes d'autorisation, puis Bearer ou Basic seul.
			'/\b((?:Proxy-)?Authorization)\s*+[:=]\s*+(?:(?:Bearer|Basic|Digest|Token)\s++)?[^\s,;"\'<>]++/i' => '$1: ' . self::MASK_SECRET,
			'/\b(Bearer|Basic)\s++[A-Za-z0-9._~+\/\-]++=*+/' => '$1 ' . self::MASK_SECRET,
			// Champs secrets : JSON ou tableau PHP, print_r, paramètre « nom=valeur ».
			'/(["\'])(\w*?(?:' . $names . '))\1\s*+(:|=>)\s*+(?:"[^"]{0,500}+"|\'[^\']{0,500}+\'|[^\s,}\])]{1,500}+)/iu' => '$1$2$1$3 "' . self::MASK_SECRET . '"',
			'/\[(\w*?(?:' . $names . '))\]\s*+=>\s*+[^\[\]()\s][^\[\]()\n]{0,200}+/iu' => '[$1] => ' . self::MASK_SECRET,
			'/\b(\w*?(?:' . $names . '))=(?:"[^"]{0,500}+"|\'[^\']{0,500}+\'|[^\s&"\'<>,;]{1,500}+)/iu' => '$1=' . self::MASK_SECRET,
			// Requête d'une adresse web : un ticket ou une signature y passe souvent.
			'~\b(https?://[^\s?#"\'<>]{1,500}+)\?[^\s"\'<>]{1,1000}+~i' => '$1?' . self::MASK_QUERY,
			// Adresses e-mail.
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu' => self::MASK_EMAIL,
			// Adresses IPv4, puis IPv6 (complète ou abrégée, avec au moins un chiffre).
			'/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?!\d|\.\d)/' => self::MASK_IP,
			'/(?<![\w:.])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}:){3,7}[0-9a-f]{1,4}(?![\w:])/i' => self::MASK_IP,
			'/(?<![\w:.])(?=[0-9a-f:]*\d)[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6}::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?![\w:])/i' => self::MASK_IP,
			// Chemins du serveur : Windows, puis Unix d'au moins deux segments (les routes du site
			// /wp-json/, /wp-admin/, /wp-content/ et /wp-includes/ restent lisibles). Un chemin Unix
			// commence après un flux de fichiers (file://, phar://, zip://, glob://, compress.*://),
			// après « : » sans seconde barre (include_path='.:/usr/share/php:/home/…', mais jamais le
			// « schéma:// » d'une adresse web), ou ailleurs par une ou deux barres (« //home/… »).
			'#(?:\bfile:///)?(?<!\w)[A-Za-z]:[\\\\/][^\s"\'<>|,;)\]]*+#u' => self::MASK_PATH,
			'#(?:\b(?:file|phar|zip|glob|compress\.(?:zlib|bzip2))://|(?<=:)(?!//)|(?<![\w/.:~-])/?)/(?!wp-json/|wp-admin/|wp-content/|wp-includes/)[\w.\-~]++/[^\s"\'<>|,;)\]]*+#u' => self::MASK_PATH,
			// Jetons : hexadécimal long, puis base64 long (chiffre, majuscule et minuscule), hors adresses.
			'/\b[0-9a-f]{32,}+\b/i' => self::MASK_TOKEN,
			'~\b[A-Za-z][A-Za-z0-9+.\-]*+://[^\s?#"\'<>]++(*SKIP)(*FAIL)|(?<![A-Za-z0-9+_\-])(?=[A-Za-z0-9+_\-]*[0-9])(?=[A-Za-z0-9+_\-]*[A-Z])(?=[A-Za-z0-9+_\-]*[a-z])[A-Za-z0-9+_\-]{40,}+={0,2}~u' => self::MASK_TOKEN,
		];
	}

	/**
	 * Coupe sur un caractère entier, jamais au milieu d'un masque, « … » final.
	 */
	private static function cut( string $text, int $max_chars ): string {
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max_chars - 1 );
		$cut = (string) preg_replace( '/\[[a-z]{0,10}\z/', '', $cut );

		return rtrim( $cut ) . '…';
	}
}
