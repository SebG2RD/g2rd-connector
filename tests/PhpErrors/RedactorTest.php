<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\PhpErrors\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Première barrière de filtrage du texte d'une erreur PHP (évolution stratégique, § 6 et § 8
 * « Filtrage ») : vecteurs partagés avec le manager, racines du site, borne de 500 caractères
 * jamais au milieu d'un masque, octets invalides, texte hostile, et barrière par valeur propre
 * au connecteur (DB_NAME, DB_USER, DB_HOST).
 */
final class RedactorTest extends PHPUnitTestCase {

	private const VECTORS = __DIR__ . '/../fixtures/php-error-redaction-vectors.json';

	/**
	 * @return iterable<string, array{string, list<string>, list<string>}>
	 */
	public static function vecteurs(): iterable {
		$decoded = json_decode( (string) file_get_contents( self::VECTORS ), true, 512, JSON_THROW_ON_ERROR );
		foreach ( $decoded['vectors'] as $vector ) {
			yield $vector['name'] => [ $vector['input'], $vector['must_not_contain'], $vector['must_contain'] ];
		}
	}

	/**
	 * Les vecteurs sont partagés avec le manager, qui ne connaît pas les valeurs du site : ils
	 * sont prouvés par les seuls motifs, barrière par valeur coupée (aucune valeur connue).
	 *
	 * @param list<string> $absents  Ce qui ne doit plus apparaître.
	 * @param list<string> $presents Ce qui doit rester lisible.
	 */
	#[DataProvider( 'vecteurs' )]
	public function test_vecteur_partage( string $input, array $absents, array $presents ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		foreach ( $absents as $absent ) {
			self::assertStringNotContainsString( $absent, $out );
		}
		foreach ( $presents as $present ) {
			self::assertStringContainsString( $present, $out );
		}
		self::assertLessThanOrEqual( Redactor::MAX_CHARS, mb_strlen( $out ) );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	public function test_le_fichier_de_vecteurs_est_lisible_et_sans_doublon(): void {
		$decoded = json_decode( (string) file_get_contents( self::VECTORS ), true, 512, JSON_THROW_ON_ERROR );
		$names   = array_column( $decoded['vectors'], 'name' );

		self::assertGreaterThanOrEqual( 21, count( $names ) );
		self::assertSame( $names, array_values( array_unique( $names ) ) );
		foreach ( $decoded['vectors'] as $vector ) {
			self::assertSame( [ 'name', 'input', 'must_not_contain', 'must_contain' ], array_keys( $vector ) );
		}
	}

	public function test_les_racines_du_site_sont_retirees_sous_toutes_leurs_formes(): void {
		$roots = [
			'/srv/site/'            => '',
			'/srv/site/wp-content/' => 'wp-content/',
		];

		self::assertSame(
			'Erreur dans wp-content/themes/t/functions.php:12 et wp-config.php:3',
			Redactor::redact( 'Erreur dans /srv/site/wp-content/themes/t/functions.php:12 et /srv/site/wp-config.php:3', $roots )
		);
		self::assertSame( 'Erreur dans wp-config.php:3', Redactor::redact( 'Erreur dans C:\\srv\\site\\wp-config.php:3', [ 'C:/srv/site/' => '' ] ) );
		self::assertSame( 'Erreur dans wp-content/x.php', Redactor::redact( 'Erreur dans C:\\srv\\site/wp-content/x.php', [ 'C:/srv/site/' => '' ] ), 'Forme mêlée de Windows.' );
	}

	public function test_les_racines_par_defaut_sont_wp_content_puis_abspath(): void {
		$content = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/';
		$abspath = rtrim( str_replace( '\\', '/', (string) ABSPATH ), '/' ) . '/';

		self::assertSame(
			[
				$content => 'wp-content/',
				$abspath => '',
			],
			Redactor::default_roots()
		);
	}

	public function test_coupe_a_500_caracteres_jamais_au_milieu_d_un_masque(): void {
		$prefix = str_repeat( 'z', 497 );

		$out = Redactor::redact( $prefix . ' 203.0.113.42', [] );

		self::assertSame( $prefix . '…', $out );
	}

	public function test_les_octets_invalides_sont_remplaces(): void {
		$out = Redactor::redact( "Erreur \xff\xfe ici", [] );

		self::assertNotFalse( json_encode( $out ) );
		self::assertStringStartsWith( 'Erreur', $out );
		self::assertStringEndsWith( 'ici', $out );
	}

	public function test_un_texte_vide_est_illisible(): void {
		self::assertSame( Redactor::UNREADABLE, Redactor::redact( '', [] ) );
		self::assertSame( Redactor::UNREADABLE, Redactor::redact( "\u{200B}\u{0007}", [] ) );
	}

	public function test_un_texte_hostile_ne_bloque_pas(): void {
		$input = str_repeat( '"\'', 1500 ) . ' ' . str_repeat( 'a.', 400 ) . 'x@' . str_repeat( 'b.', 300 );

		$start = microtime( true );
		$out   = Redactor::redact( $input, [] );

		self::assertLessThan( 1.0, microtime( true ) - $start );
		self::assertLessThanOrEqual( Redactor::MAX_CHARS, mb_strlen( $out ) );
	}

	/**
	 * Appel du dernier filet (tâche 14) : le message d'une exception non rattrapée n'a aucune
	 * borne. Seuls ses 16 000 premiers octets sont lus ; la coupe tombe ici au milieu d'un « é ».
	 */
	public function test_un_message_de_1_mo_donne_le_meme_rendu_que_ses_16_000_premiers_octets(): void {
		$input = 'Uncaught Exception: échec pour contact@exemple.fr in /home/u123456789/domains/exemple.fr/public_html/wp-content/plugins/x/x.php:3 '
			. str_repeat( 'é&<', 262144 );

		$out = Redactor::redact( $input, null, 2000 );

		self::assertGreaterThan( 1024 * 1024, strlen( $input ) );
		self::assertSame( Redactor::redact( substr( $input, 0, 16000 ), null, 2000 ), $out );
		self::assertStringContainsString( 'wp-content/plugins/x/x.php:3', $out );
		self::assertStringNotContainsString( 'u123456789', $out );
		self::assertStringNotContainsString( 'contact@exemple.fr', $out );
		self::assertNotFalse( json_encode( $out ) );
		self::assertLessThanOrEqual( 2000, mb_strlen( $out ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function chemins_du_serveur(): iterable {
		yield 'file://' => [ 'file:///home/u123456789/x/y.php' ];
		yield 'phar://' => [ 'phar:///home/u123456789/outils/app.phar/src/x.php' ];
		yield 'zip://' => [ 'zip:///home/u123456789/a.zip#b.txt' ];
		yield 'glob://' => [ 'glob:///home/u123456789/*.php' ];
		yield 'compress.zlib://' => [ 'compress.zlib:///home/u123456789/sauvegardes/base.sql.gz' ];
		yield 'compress.bzip2://' => [ 'compress.bzip2:///home/u123456789/sauvegardes/base.sql.bz2' ];
		yield 'barre de tête' => [ '/home/u123456789/.ssh/id_rsa' ];
		yield 'double barre de tête' => [ '//home/u123456789/x.php' ];
		yield 'include_path' => [ "(include_path='.:/opt/alt/php81/usr/share/pear:/home/u123456789/lib')" ];
	}

	#[DataProvider( 'chemins_du_serveur' )]
	public function test_les_chemins_du_serveur_sont_masques_sous_toutes_leurs_formes( string $path ): void {
		$out = Redactor::redact( 'Erreur sur ' . $path . ' ici', [] );

		self::assertStringNotContainsString( 'u123456789', $out );
		self::assertStringNotContainsString( '/home/', $out );
		self::assertStringNotContainsString( '/opt/', $out );
		self::assertStringStartsWith( 'Erreur sur ', $out );
		self::assertStringEndsWith( ' ici', $out );
	}

	/**
	 * Le compte et la base des messages MySQL et MariaDB, au-delà des vecteurs partagés : sur
	 * Hostinger, les deux commencent par l'identifiant du compte.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function comptes_et_bases_mysql(): iterable {
		yield 'limite sans hôte' => [ "User 'u123456789_wp' has exceeded the 'max_questions' resource (current value: 1000)", "User [secret] has exceeded the 'max_questions' resource (current value: 1000)" ];
		yield 'connexions, minuscules' => [ "user u123456789_wp already has more than 'max_user_connections' active connections", "user [secret] already has more than 'max_user_connections' active connections" ];
		yield 'routine entre accents graves' => [ 'EXECUTE command denied to user \'u123456789_wp\'@\'localhost\' for routine `u123456789_wp`.`calcul`', 'EXECUTE command denied to user [secret] for routine `[secret]`.`calcul`' ];
		yield 'procédure inconnue' => [ 'PROCEDURE `u123456789_wp`.`maj` does not exist', 'PROCEDURE `[secret]`.`maj` does not exist' ];
		yield 'compte anonyme' => [ "Access denied for user ''@'localhost' to database 'u123456789_wp'", "Access denied for user [secret] to database '[secret]'" ];
		yield 'compte verrouillé' => [ "Access denied for user 'u123456789_wp'@'localhost'. Account is locked.", 'Access denied for user [secret]. Account is locked.' ];
		yield 'opération sur un compte' => [ "Operation DROP USER failed for 'u123456789_wp'@'localhost'", 'Operation DROP USER failed for [secret]' ];
		yield 'renommage de fichiers' => [ "Error on rename of './u123456789_wp/#sql-1a2b_3c' to './u123456789_wp/wp_options' (errno: 150)", "Error on rename of './[secret]/#sql-1a2b_3c' to './[secret]/wp_options' (errno: 150)" ];
		yield 'format de fichier' => [ "Incorrect file format './u123456789_wp/wp_posts'", "Incorrect file format './[secret]/wp_posts'" ];
		yield 'écriture de fichier' => [ "Error writing file './u123456789_wp/#sql-1234.MYD' (Errcode: 28)", "Error writing file './[secret]/#sql-1234.MYD' (Errcode: 28)" ];
		yield 'fichier d\'index abîmé' => [ "Incorrect key file for table './u123456789_wp/wp_options.MYI'; try to repair it", "Incorrect key file for table './[secret]/wp_options.MYI'; try to repair it" ];
		yield 'espace de table' => [ "Tablespace for table '`u123456789_wp`.`wp_options`' exists.", "Tablespace for table '[secret].`wp_options`' exists." ];
		// Nom en trois parties (base, table, colonne) derrière « column » (1054, 1055, 3065).
		yield 'colonne hors du GROUP BY (1055)' => [
			"Expression #1 of SELECT list is not in GROUP BY clause and contains nonaggregated column 'u123456789_wp.wp_posts.post_date' which is not functionally dependent on columns in GROUP BY clause; this is incompatible with sql_mode=only_full_group_by",
			"Expression #1 of SELECT list is not in GROUP BY clause and contains nonaggregated column '[secret].wp_posts.post_date' which is not functionally dependent on columns in GROUP BY clause; this is incompatible with sql_mode=only_full_group_by",
		];
		yield 'colonne hors du GROUP BY sous MariaDB (1055)' => [ "'u123456789_wp.wp_posts.post_date' isn't in GROUP BY", "'[secret].wp_posts.post_date' isn't in GROUP BY" ];
		yield 'colonne de l\'ORDER BY (3065)' => [
			"Expression #1 of ORDER BY clause is not in SELECT list, references column 'u123456789_wp.wp_posts.post_date' which is not in SELECT list; this is incompatible with DISTINCT",
			"Expression #1 of ORDER BY clause is not in SELECT list, references column '[secret].wp_posts.post_date' which is not in SELECT list; this is incompatible with DISTINCT",
		];
		yield 'colonne inconnue en trois parties (1054)' => [ "Unknown column 'u123456789_wp.wp_posts.foo' in 'field list'", "Unknown column '[secret].wp_posts.foo' in 'field list'" ];
		// Nom en trois parties entre accents graves (MariaDB : 1366, 1265, 1690).
		yield 'valeur incorrecte sous MariaDB (1366)' => [ "Incorrect integer value: '' for column `u123456789_wp`.`wp_wc_order_stats`.`num_items_sold` at row 1", "Incorrect integer value: '' for column `[secret]`.`wp_wc_order_stats`.`num_items_sold` at row 1" ];
		yield 'valeur hors limites (1690)' => [ "BIGINT UNSIGNED value is out of range in '(`u123456789_wp`.`wp_wc_product_meta_lookup`.`stock_quantity` - 1)'", "BIGINT UNSIGNED value is out of range in '(`[secret]`.`wp_wc_product_meta_lookup`.`stock_quantity` - 1)'" ];
		// Clés étrangères (1451, 1452) et contrainte de MariaDB (4025).
		yield 'clé étrangère, ligne enfant (1452)' => [
			'Cannot add or update a child row: a foreign key constraint fails (`u123456789_wp`.`wp_wc_download_log`, CONSTRAINT `fk_wp_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `wp_woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE)',
			'Cannot add or update a child row: a foreign key constraint fails (`[secret]`.`wp_wc_download_log`, CONSTRAINT `fk_wp_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `wp_woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE)',
		];
		yield 'clé étrangère, ligne parente (1451)' => [
			'Cannot delete or update a parent row: a foreign key constraint fails (`u123456789_wp`.`wp_wc_download_log`, CONSTRAINT `fk_wp_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `wp_woocommerce_downloadable_product_permissions` (`permission_id`))',
			'Cannot delete or update a parent row: a foreign key constraint fails (`[secret]`.`wp_wc_download_log`, CONSTRAINT `fk_wp_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `wp_woocommerce_downloadable_product_permissions` (`permission_id`))',
		];
		yield 'contrainte JSON de MariaDB (4025)' => [ 'CONSTRAINT `wp_wc_orders_meta.meta_value` failed for `u123456789_wp`.`wp_wc_orders_meta`', 'CONSTRAINT `wp_wc_orders_meta.meta_value` failed for `[secret]`.`wp_wc_orders_meta`' ];
		// Vue (1356) et définisseur entre accents graves (CREATE … TRIGGER, VIEW, PROCEDURE).
		yield 'vue invalide (1356)' => [
			"View 'u123456789_wp.wp_vue_commandes' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them",
			"View '[secret].wp_vue_commandes' references invalid table(s) or column(s) or function(s) or definer/invoker of view lack rights to use them",
		];
		yield 'définisseur entre accents graves' => [
			'Access denied; you need (at least one of) the SUPER privilege(s) for this operation for query CREATE DEFINER=`u123456789_wp`@`localhost` TRIGGER `wp_x_ai` AFTER INSERT ON `wp_x` FOR EACH ROW BEGIN END',
			'Access denied; you need (at least one of) the SUPER privilege(s) for this operation for query CREATE DEFINER=[secret] TRIGGER `wp_x_ai` AFTER INSERT ON `wp_x` FOR EACH ROW BEGIN END',
		];
	}

	#[DataProvider( 'comptes_et_bases_mysql' )]
	public function test_le_compte_et_la_base_des_messages_mysql_sont_masques( string $input, string $expected ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertSame( $expected, $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * Erreur de syntaxe SQL (1064) : le fragment « near '…' at line N » recopie un morceau de la
	 * requête avec ses valeurs (souvent une valeur collée sans préparation).
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function erreurs_de_syntaxe_sql(): iterable {
		yield 'valeurs de formulaire' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', '06 12 34 56 78', 'Hunter2x')' at line 1 for query INSERT INTO wp_contacts (prenom, nom, tel, code) VALUES ('Jean', 'O'Dupont', '06 12 34 56 78', 'Hunter2x')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql] at line 1 for query [sql]',
		];
		yield 'PDO, fragment seul' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Jean Dupont' WHERE ID = 5' at line 3",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql] at line 3',
		];
		yield 'fragment vide' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '' at line 1",
			'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql] at line 1',
		];
	}

	#[DataProvider( 'erreurs_de_syntaxe_sql' )]
	public function test_le_fragment_d_une_erreur_de_syntaxe_sql_est_masque( string $input, string $expected ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertSame( $expected, $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * Valeur incorrecte (1366, 1292, 1411, 1525) : MySQL et MariaDB recopient la valeur refusée,
	 * souvent saisie par un visiteur (téléphone, date de naissance, nom). Elle est masquée comme
	 * la valeur en double ; le type, la colonne et la fonction restent lisibles.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function valeurs_recopiees_par_mysql(): iterable {
		yield 'valeur incorrecte sous MySQL (1366)' => [ "Incorrect integer value: '06 12 34 56 78' for column 'tel' at row 1", "Incorrect integer value: '[secret]' for column 'tel' at row 1" ];
		yield 'valeur incorrecte sous MariaDB (1366), requête comprise' => [
			"Incorrect integer value: '06 12 34 56 78' for column `u123456789_wp`.`wp_contacts`.`tel` at row 1 for query INSERT INTO wp_contacts (nom, tel) VALUES ('Dupont', '06 12 34 56 78')",
			"Incorrect integer value: '[secret]' for column `[secret]`.`wp_contacts`.`tel` at row 1 for query [sql]",
		];
		yield 'valeur avec une apostrophe (1366)' => [ "Incorrect integer value: 'O'Brien' for column 'tel' at row 1", "Incorrect integer value: '[secret]' for column 'tel' at row 1" ];
		yield 'valeur tronquée (1292)' => [ "Truncated incorrect DOUBLE value: 'Jean Dupont'", "Truncated incorrect DOUBLE value: '[secret]'" ];
		yield 'valeur tronquée avec une apostrophe, requête de WordPress (1292)' => [
			"Truncated incorrect DOUBLE value: 'l'école Jean Moulin' for query SELECT * FROM wp_g2rd_inscriptions WHERE classe = 'l'école Jean Moulin'",
			"Truncated incorrect DOUBLE value: '[secret]' for query [sql]",
		];
		yield 'valeur tronquée d\'un type à taille (1292)' => [ "Truncated incorrect CHAR(10) value: 'Jean Dupont de la Fontaine'", "Truncated incorrect CHAR(10) value: '[secret]'" ];
		yield 'date et heure incorrectes (1292)' => [ "Incorrect datetime value: '12/05/1980 10:00' for column 'date_naissance' at row 1", "Incorrect datetime value: '[secret]' for column 'date_naissance' at row 1" ];
		yield 'date incorrecte (1292)' => [ "Incorrect date value: '12/05/1980' for column 'naissance' at row 1", "Incorrect date value: '[secret]' for column 'naissance' at row 1" ];
		yield 'valeur refusée par une fonction (1411)' => [ "Incorrect datetime value: '12/05/1980' for function str_to_date", "Incorrect datetime value: '[secret]' for function str_to_date" ];
		yield 'valeur incorrecte sans suite (1525)' => [ "Incorrect DATE value: '31/02/1980'", "Incorrect DATE value: '[secret]'" ];
		yield 'octets d\'un texte (1366)' => [ "Incorrect string value: '\\xF0\\x9F\\x98\\x80 J...' for column 'comment_content' at row 1", "Incorrect string value: '[secret]' for column 'comment_content' at row 1" ];
		yield 'exception PDO (1292)' => [ "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'Jean Dupont'", "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: '[secret]'" ];
	}

	#[DataProvider( 'valeurs_recopiees_par_mysql' )]
	public function test_la_valeur_recopiee_par_mysql_est_masquee( string $input, string $expected ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertSame( $expected, $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function textes_proches_gardes(): iterable {
		yield 'utilisateur créé' => [ 'User admin a été créé' ];
		yield 'utilisateur entre apostrophes' => [ "User 'admin' a été créé" ];
		yield 'apostrophes autour d\'une arobase' => [ "Don't use '@' in names, it's bad" ];
		yield 'adresse entre apostrophes' => [ "Erreur sur l'adresse 'jean'@exemple" ];
		yield 'chemin relatif de WordPress' => [ "Failed opening required './wp-content/plugins/x/y.php'" ];
		yield 'mot FUNCTION' => [ 'The FUNCTION keyword is reserved' ];
		yield 'fonction sans base' => [ 'FUNCTION calcul does not exist' ];
		// La règle « './base/… » ne vise que les fichiers de données de MySQL, jamais une inclusion de PHP.
		yield 'inclusion relative de PHP' => [ "Failed opening required './vendor/autoload.php'" ];
		yield 'colonne en deux parties' => [ "Unknown column 'wp_posts.foo' in 'field list'" ];
		yield 'colonne en deux parties entre accents graves' => [ 'Champ `wp_posts`.`post_title` vide' ];
		yield 'vue d\'un gabarit' => [ "View 'admin.settings' not found" ];
		yield 'near sans numéro de ligne' => [ "Unexpected character near 'é' in template" ];
		yield 'version à trois nombres' => [ "Requires version '6.4.0' of WordPress" ];
		// La règle de la valeur incorrecte ne vise que « Incorrect <type> value: '…' ».
		yield 'valeur incorrecte hors de MySQL' => [ "Incorrect value for field 'email' in form 'contact'" ];
		yield 'nombre de paramètres incorrect' => [ "Incorrect parameter count in the call to native function 'concat'" ];
		yield 'valeur incorrecte sans apostrophe' => [ 'Incorrect integer value: 42 given' ];
		// Une valeur vide ne dit rien du visiteur, et c'est elle qu'il faut voir (chaîne vide
		// envoyée à une colonne de nombres) : la masquer laisserait croire à une valeur secrète.
		yield 'valeur incorrecte vide' => [ "Incorrect integer value: '' for column 'num_items_sold' at row 1" ];
		yield 'valeur tronquée vide' => [ "Truncated incorrect DOUBLE value: ''" ];
	}

	public function test_un_texte_hostile_visant_les_regles_mysql_ne_bloque_pas(): void {
		$inputs = [
			str_repeat( 'User ', 1000 ),
			str_repeat( "User '" . str_repeat( 'a', 99 ), 40 ),
			str_repeat( "'a'@'", 1000 ),
			str_repeat( "routine '" . str_repeat( 'a', 99 ), 40 ),
			str_repeat( "'./", 1500 ),
			str_repeat( "'./a/" . str_repeat( 'b', 290 ), 13 ),
			str_repeat( 'FUNCTION `' . str_repeat( 'a', 99 ) . '`.', 40 ),
			str_repeat( "near '", 650 ),
			str_repeat( "near '" . str_repeat( 'a', 300 ) . "' ", 12 ),
			str_repeat( "column '" . str_repeat( 'a', 99 ) . '.', 40 ),
			str_repeat( "'a.b.", 800 ),
			str_repeat( '`a`.', 1000 ),
			str_repeat( '(`a`.`b`', 500 ),
			str_repeat( '`a`@`', 800 ),
			str_repeat( "View 'a.", 500 ),
			str_repeat( "Incorrect a value: '", 250 ),
			str_repeat( "Incorrect a b c value: '" . str_repeat( 'x', 290 ), 13 ),
			str_repeat( "Truncated incorrect a value: '" . str_repeat( "x'", 145 ), 13 ),
			str_repeat( 'Incorrect a ', 400 ),
		];

		foreach ( $inputs as $input ) {
			$start = microtime( true );
			$out   = Redactor::redact( $input, [] );

			self::assertLessThan( 1.0, microtime( true ) - $start );
			self::assertNotSame( Redactor::UNREADABLE, $out );
		}
	}

	#[DataProvider( 'textes_proches_gardes' )]
	public function test_les_textes_proches_des_messages_mysql_restent_intacts( string $input ): void {
		self::assertSame( $input, Redactor::redact( $input, [] ) );
	}

	public function test_les_adresses_web_et_les_routes_du_site_restent_lisibles(): void {
		$input = 'Échec de https://api.exemple.fr/v1/sync puis de http://localhost:8080/wp-json/exemple/v1/statut et de /wp-json/wp/v2/posts';

		self::assertSame( $input, Redactor::redact( $input, [] ) );
	}

	/**
	 * Barrière propre au connecteur, après les motifs : les valeurs du site qui ne doivent jamais
	 * sortir sont masquées sous toutes les formes de message, même celles qu'aucun motif ne
	 * prévoit, casse ignorée. Elle n'entre pas dans les vecteurs partagés : le manager ne connaît
	 * pas ces valeurs.
	 */
	public function test_les_valeurs_connues_du_site_sont_masquees_partout(): void {
		$known = [ 'u123456789_wp', 'u123456789_admin', 'srv123.hstgr.io' ];

		$out = Redactor::redact( 'Réplication de U123456789_WP vers u123456789_wp_copie impossible : compte u123456789_admin, hôte srv123.hstgr.io injoignable', [], Redactor::MAX_CHARS, $known );

		self::assertSame( 'Réplication de [secret] vers [secret]_copie impossible : compte [secret], hôte [secret] injoignable', $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, $known ), 'Un texte déjà filtré ne change plus.' );
	}

	public function test_une_valeur_connue_n_est_masquee_ni_au_milieu_d_un_mot_ni_dans_un_masque(): void {
		self::assertSame(
			'Boutique [secret] fermée, voir workshop et shops',
			Redactor::redact( 'Boutique SHOP fermée, voir workshop et shops', [], Redactor::MAX_CHARS, [ 'shop' ] )
		);
		self::assertSame(
			'Erreur [secret] et [sql] pour le compte [secret]',
			Redactor::redact( 'Erreur [secret] et [sql] pour le compte secret', [], Redactor::MAX_CHARS, [ 'secret', 'sql' ] )
		);
	}

	public function test_les_valeurs_courtes_et_communes_a_tous_les_sites_ne_sont_pas_masquees(): void {
		$known = [ 'wp', 'db', ' ', 'localhost', 'localhost:3306', 'LOCALHOST:/var/run/mysqld/mysqld.sock', '127.0.0.1:3306', '::1', 'WordPress', 'root', 'mysql', 'mariadb', 'database' ];
		$input = 'WordPress database error: Failed to connect to localhost port 6379 as root (MySQL, MariaDB, wp, db)';

		self::assertSame( $input, Redactor::redact( $input, [], Redactor::MAX_CHARS, $known ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_les_valeurs_connues_sont_lues_dans_wp_config(): void {
		define( 'DB_NAME', 'u123456789_wp' );
		define( 'DB_USER', 'u123456789_admin' );
		define( 'DB_HOST', 'srv123.hstgr.io:3306' );

		self::assertSame( [ 'srv123.hstgr.io:3306', 'u123456789_admin', 'srv123.hstgr.io', 'u123456789_wp' ], Redactor::known_values() );
		self::assertSame(
			'Base [secret] du compte [secret] hors ligne sur [secret] (repli sur [secret])',
			Redactor::redact( 'Base U123456789_WP du compte u123456789_admin hors ligne sur srv123.hstgr.io:3306 (repli sur srv123.hstgr.io)', [] )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_les_valeurs_par_defaut_d_une_installation_ne_sont_pas_lues(): void {
		define( 'DB_NAME', 'wordpress' );
		define( 'DB_USER', 'root' );
		define( 'DB_HOST', 'localhost:3306' );

		self::assertSame( [], Redactor::known_values() );

		$input = 'WordPress database error: Failed to connect to localhost port 6379 as root';
		self::assertSame( $input, Redactor::redact( $input, [] ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sans_constante_de_base_aucune_valeur_n_est_lue(): void {
		self::assertFalse( defined( 'DB_NAME' ) );
		self::assertSame( [], Redactor::known_values() );
	}
}
