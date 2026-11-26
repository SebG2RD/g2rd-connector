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
 * au connecteur (DB_NAME, DB_USER, DB_HOST, DB_PASSWORD).
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

	/**
	 * Requête de wpdb::print_error() (« … for query %s made by %s ») dont une valeur porte un « ; »,
	 * une apostrophe échappée par mysqli_real_escape_string (« \' »), un guillemet échappé, une barre
	 * finale (NO_BACKSLASH_ESCAPES), ou dépasse 1 000 caractères : masquée entière, « made by »
	 * compris. Mêmes cas que PhpErrorTextTest du manager (seconde barrière), plus la longueur.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function requetes_aux_valeurs_piegees(): iterable {
		$error      = 'WordPress database error Data too long for column \'meta_value\' at row 1 for query ';
		$serialized = 's:11:"Jean Dupont";s:5:"phone";s:10:"0612345678";}\') made by do_action(\'x\')';
		yield 'requête brute, valeur sérialisée' => [ $error . 'INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (12, \'billing\', \'a:2:{s:4:"name";' . $serialized ];
		yield 'reste collé au masque' => [ $error . '[sql]' . $serialized ];
		yield 'reste après le masque et une espace' => [ $error . '[sql] Jean Dupont, 0612345678\') made by do_action(\'x\')' ];
		yield 'apostrophe échappée puis « ; »' => [ $error . 'INSERT INTO wp_comments (comment_author, comment_content) VALUES (\'Jean\', \'Bonjour, c\\\'est Jean Dupont; rappelez-moi au 0612345678\') made by wp_insert_comment' ];
		yield 'apostrophe échappée puis « ; », en minuscules' => [ $error . 'update wp_options set option_value = \'Rue de l\\\'Eglise; Jean Dupont, tel 0612345678\' where option_name = \'contact\'' ];
		yield 'guillemet échappé puis « ; »' => [ $error . 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (7, "note", "Il a dit \\"oui; rappelez Jean Dupont au 0612345678\\"")' ];
		yield 'valeur finie par une barre, sans échappement par barre' => [ $error . 'INSERT INTO wp_postmeta (meta_key, meta_value) VALUES (\'C:\\\', \'Jean Dupont; 0612345678\') made by f' ];
		yield 'requête de plus de 1 000 caractères' => [ $error . 'INSERT INTO wp_postmeta (meta_key, meta_value) VALUES (\'note\', \'' . str_repeat( 'x', 1100 ) . ' Jean Dupont 0612345678\') made by f' ];
		// En-tête non reconnu par les motifs de requête : seule la règle « for query » la masque.
		yield '« ; » entre SELECT et FROM' => [ $error . 'SELECT CONCAT(\';\', meta_value) FROM wp_usermeta WHERE meta_value = \'Jean Dupont 0612345678\' made by do_action(\'x\')' ];
		yield 'plus de 300 caractères entre SELECT et FROM' => [ $error . 'SELECT ' . implode( ', ', array_map( static fn ( int $i ): string => 's.col_' . $i, range( 10, 41 ) ) ) . ' FROM wp_wc_order_stats s WHERE s.note = \'Jean Dupont 0612345678\' made by f' ];
		yield 'requête SHOW' => [ $error . 'SHOW TABLES LIKE \'Jean Dupont 0612345678\' made by do_action(\'x\')' ];
		yield 'requête CALL' => [ $error . 'CALL wp_enregistre_contact(\'Jean Dupont\', \'0612345678\') made by f' ];
		yield 'requête SHOW sur plusieurs lignes' => [ $error . "SHOW TABLES\nLIKE 'x'\r\nOR 'Jean Dupont 0612345678'\nmade by f" ];
	}

	#[DataProvider( 'requetes_aux_valeurs_piegees' )]
	public function test_une_requete_est_masquee_entiere_malgre_ses_valeurs( string $input ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertStringNotContainsString( 'Jean Dupont', $out );
		self::assertStringNotContainsString( '0612345678', $out );
		self::assertStringContainsString( 'WordPress database error', $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * Après « for query », tout part jusqu'à la fin, « made by » et la pile d'appels de WordPress
	 * compris : une valeur peut écrire son propre « made by ». Requête sans guillemet (règle « for
	 * query » seule) ou avec (première apostrophe d'un message d'erreur SQL).
	 */
	public function test_apres_for_query_la_requete_et_le_made_by_partent(): void {
		self::assertSame(
			'WordPress database error Duplicate column for query [sql]',
			Redactor::redact( 'WordPress database error Duplicate column for query INSERT INTO wp_x VALUES (\'a;b\'); Jean Dupont made by do_action(\'init\')', [], Redactor::MAX_CHARS, [] )
		);
		self::assertSame(
			'WordPress database error Lock wait timeout exceeded; try restarting transaction for query [sql]',
			Redactor::redact( 'WordPress database error Lock wait timeout exceeded; try restarting transaction for query CALL wp_x(0612345678) made by do_action(\'init\')', [], Redactor::MAX_CHARS, [] )
		);
	}

	/**
	 * Requête citée hors de wpdb::print_error() (exception d'une extension, « Query failed: … ») :
	 * pas de « for query », la lecture des chaînes de SQL_TAIL la masque entière malgré un « ; »
	 * dans une valeur, un « \' » ou un « \" », ou plus de 1 000 caractères. Une valeur finie par
	 * « \ » sans échappement (NO_BACKSLASH_ESCAPES) décale cette lecture : ce qui suit le masque
	 * jusqu'au « made by », sinon jusqu'à la fin, appartient encore à la requête.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function requetes_citees_sans_for_query(): iterable {
		$error = 'Uncaught Exception: Query failed: ';
		yield 'apostrophe échappée puis « ; »' => [ $error . 'INSERT INTO wp_comments (comment_content) VALUES (\'c\\\'est Jean Dupont; 0612345678\')' ];
		yield 'valeur sérialisée aux « ; »' => [ $error . 'INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (12, \'billing\', \'a:2:{s:4:\\"name\\";s:11:\\"Jean Dupont\\";s:5:\\"phone\\";s:10:\\"0612345678\\";}\')' ];
		yield 'valeur de plus de 1 000 caractères' => [ $error . 'INSERT INTO wp_postmeta (meta_key, meta_value) VALUES (\'note\', \'' . str_repeat( 'x', 1100 ) . ' Jean Dupont 0612345678\')' ];
		yield 'apostrophe échappée puis « ; », en minuscules' => [ $error . 'update wp_options set option_value = \'Rue de l\\\'Eglise; Jean Dupont, tel 0612345678\' where option_name = \'contact\'' ];
		yield 'guillemet échappé puis « ; »' => [ $error . 'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (7, "note", "Il a dit \\"oui; rappelez Jean Dupont au 0612345678\\"")' ];
		yield 'valeur finie par une barre, sans échappement par barre' => [ $error . 'INSERT INTO wp_postmeta (meta_key, meta_value) VALUES (\'C:\\\', \'Jean Dupont; 0612345678\')' ];
		// Repère d'erreur SQL (« Syntax error ») : tout part dès la première apostrophe, la requête suit.
		yield 'requête après « near », valeur finie par une barre' => [ 'Uncaught Exception: Syntax error near INSERT INTO wp_postmeta (meta_key, meta_value) VALUES (\'C:\\\', \'Jean Dupont; 0612345678\')', 'Uncaught Exception: Syntax error near [sql]' ];
	}

	#[DataProvider( 'requetes_citees_sans_for_query' )]
	public function test_une_requete_citee_sans_for_query_est_masquee_entiere( string $input, string $start = 'Uncaught Exception: Query failed: [sql]' ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertStringNotContainsString( 'Jean Dupont', $out );
		self::assertStringNotContainsString( '0612345678', $out );
		self::assertStringStartsWith( $start, $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * Textes hostiles sous la borne de lecture (4 000 caractères : rien n'est coupé avant les
	 * motifs), dont ceux qui visent la lecture des chaînes échappées. Vérifié à la main aussi avec
	 * php -d pcre.jit=0 ; la CI tourne avec le JIT.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function textes_hostiles_sous_la_borne(): iterable {
		yield 'guillemets' => [ str_repeat( '\'"', 2000 ) ];
		yield 'apostrophes échappées' => [ str_repeat( '\'\\\'', 1300 ) ];
		// 25 + 3 000 + 500 = 3 525 caractères : les 500 barres finales passent sous la borne.
		yield 'requête aux chaînes échappées sans fin' => [ 'INSERT INTO wp_x VALUES (' . str_repeat( '\'\\\'; "\\"; ', 300 ) . str_repeat( '\\', 500 ) ];
		yield 'requête en minuscules aux chaînes ouvertes' => [ 'update wp_x set a = ' . str_repeat( '"\\', 1900 ) ];
		yield 'for query répété' => [ str_repeat( 'for query [sql] made by ', 160 ) ];
		yield 'for query sans made by' => [ 'for query [sql]' . str_repeat( ' made b', 500 ) ];
		yield 'for query répété sans masque ni made by' => [ str_repeat( 'for query x made b ', 210 ) ];
		yield 'masque répété sans made by' => [ str_repeat( '[sql] made b', 330 ) ];
		yield 'near et masque répétés' => [ str_repeat( 'near [sql] at ', 280 ) ];
		yield 'fragment de syntaxe répété' => [ str_repeat( 'near [sql] at line 1 ', 190 ) ];
		// « made by » ne finit pas la suite gardée : pas de fin du texte juste après le premier
		// « at line 1 », tout part d'une seule lecture jusqu'à la fin.
		yield 'fragment de syntaxe suivi de made by' => [ str_repeat( 'near [sql] at line 1 ' . str_repeat( 'fm', 95 ) . ' made by ', 18 ) ];
		// Couches forgées (« at line N », suite fermée par un guillemet devant un autre « at line N ») :
		// 300 couches d'un seul tenant, puis 110 fragments qui en ont chacun une.
		yield 'couches forgées à la suite' => [ 'near [sql]' . str_repeat( " at line 1 x'", 300 ) ];
		yield 'fragments forgés répétés' => [ str_repeat( "near [sql] at line 1 x' at line 1 ", 110 ) ];
		// « made by » suivi d'un guillemet et de « at line N » (300 caractères lus au plus) : 120 fois
		// de suite, puis 12 séries de 37 « made by » refusés chacun à la lecture de la série.
		yield 'made by forgés répétés' => [ str_repeat( "for query x made by y' at line 1 ", 120 ) ];
		yield 'séries de made by forgés' => [ 'for query x' . str_repeat( str_repeat( ' made by', 37 ) . "' at line 1", 12 ) ];
		// Repère « near ' » répété : le premier suffit, une seule lecture.
		yield 'near ouvert répété' => [ str_repeat( "near 'x ", 450 ) ];
		// Premier repère gardé, puis aucune apostrophe hors d'un mot : une seule lecture.
		yield 'repère SQL puis apostrophes dans les mots' => [ 'syntax error ' . str_repeat( "d'x ", 990 ) ];
	}

	#[DataProvider( 'textes_hostiles_sous_la_borne' )]
	public function test_un_texte_hostile_sous_la_borne_reste_rapide_et_lisible( string $input ): void {
		self::assertLessThanOrEqual( 4000, mb_strlen( $input ) );

		$start = hrtime( true );
		$out   = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );
		$ms    = ( hrtime( true ) - $start ) / 1e6;

		self::assertLessThan( 100, $ms, sprintf( '%.1f ms : un motif retombe en arrière sur ce texte', $ms ) );
		self::assertNotSame( Redactor::UNREADABLE, $out, 'un motif a atteint une limite de PCRE sur ce texte' );
		self::assertLessThanOrEqual( Redactor::MAX_CHARS, mb_strlen( $out ) );
	}

	public function test_un_texte_vide_est_illisible(): void {
		self::assertSame( Redactor::UNREADABLE, Redactor::redact( '', [] ) );
		self::assertSame( Redactor::UNREADABLE, Redactor::redact( "\u{200B}\u{0007}", [] ) );
	}

	/**
	 * Repère d'erreur SQL lu sur le texte entier (LogTail) : au-delà des 4 000 caractères lus par
	 * redact(), entités HTML décodées et blancs ramenés à une espace comme dans redact().
	 */
	public function test_le_repere_sql_est_lu_sur_le_texte_entier(): void {
		self::assertTrue( Redactor::is_sql_error( str_repeat( 'Lorem ipsum ', 400 ) . "Duplicate entry 'x" ) );
		self::assertTrue( Redactor::is_sql_error( 'Erreur : the right syntax to use near &#039;x' ) );
		self::assertTrue( Redactor::is_sql_error( "Duplicate\n\tentry 'x" ) );
		self::assertTrue( Redactor::is_sql_error( 'Duplicate entry [sql] for key' ) );
		self::assertFalse( Redactor::is_sql_error( 'Uncaught JsonException: Syntax error in /x/wp-content/plugins/p/x.php:42' ) );
		self::assertFalse( Redactor::is_sql_error( "Duplicate entry d'un" ) );
		self::assertFalse( Redactor::is_sql_error( 'syntax error, unexpected token "}"' ) );
		self::assertFalse( Redactor::is_sql_error( 'Undefined array key "near"' ) );
		// Valeur échappée deux fois (esc_html( esc_html( $wpdb->last_error ) )).
		self::assertTrue( Redactor::is_sql_error( 'SQLSTATE[23000]: Duplicate entry &amp;#039;Jean' ) );
		// Repères répétés sans valeur : faux, et en une seule lecture.
		$started = hrtime( true );
		self::assertFalse( Redactor::is_sql_error( str_repeat( 'syntax error duplicate entry ', 550 ) ) );
		self::assertLessThan( 10, ( hrtime( true ) - $started ) / 1e6 );
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
		// Repère d'erreur SQL (valeur incorrecte) : tout part dès la première apostrophe.
		yield 'valeur incorrecte sous MariaDB (1366)' => [ "Incorrect integer value: '' for column `u123456789_wp`.`wp_wc_order_stats`.`num_items_sold` at row 1", 'Incorrect integer value: [sql]' ];
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
		// Repère d'erreur SQL (SQLSTATE) : tout part dès le premier accent grave hors d'un mot.
		yield 'définisseur entre accents graves' => [
			'SQLSTATE[42000]: Access denied; you need (at least one of) the SUPER privilege(s) for this operation: CREATE DEFINER=`u123456789_wp`@`localhost` TRIGGER `wp_x_ai` AFTER INSERT ON `wp_x` FOR EACH ROW BEGIN END',
			'SQLSTATE[42000]: Access denied; you need (at least one of) the SUPER privilege(s) for this operation: CREATE DEFINER=[sql]',
		];
		// Après « for query », la requête entière part, définisseur compris.
		yield 'définisseur entre accents graves après « for query »' => [
			'Access denied; you need (at least one of) the SUPER privilege(s) for this operation for query CREATE DEFINER=`u123456789_wp`@`localhost` TRIGGER `wp_x_ai` AFTER INSERT ON `wp_x` FOR EACH ROW BEGIN END',
			'Access denied; you need (at least one of) the SUPER privilege(s) for this operation for query [sql]',
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
	 * requête avec ses valeurs (souvent une valeur collée sans préparation). Dans un message d'erreur
	 * SQL, tout part dès la première apostrophe (ou guillemet, ou accent grave) hors d'un mot qui
	 * suit le premier repère : une valeur choisit ses guillemets, sa propre fermeture « ' at line N »,
	 * « for query » et « made by », et l'endroit où le message est coupé (saut de ligne et « Stack
	 * trace: », ligne du journal finie par « in x:1 ») ; rien de ce qu'elle écrit dans ce message ne reste (limite connue : une ligne entière du journal). « at line
	 * N », « for query » et « made by » partent avec elle. Formes MySQL, MariaDB, wpdb, PDO, mysqli,
	 * message enveloppé (apostrophe avant le repère), sortie d'un connecteur plus ancien.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function erreurs_de_syntaxe_sql(): iterable {
		yield 'valeurs de formulaire' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', '06 12 34 56 78', 'Hunter2x')' at line 1 for query INSERT INTO wp_contacts (prenom, nom, tel, code) VALUES ('Jean', 'O'Dupont', '06 12 34 56 78', 'Hunter2x')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		yield 'PDO, fragment seul' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Jean Dupont' WHERE ID = 5' at line 3",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'fragment vide' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near '' at line 1",
			'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		// Une valeur qui contient « ' at line 1 » arrête le fragment trop tôt : chaque couche forgée,
		// jusqu'au guillemet qui précède la vraie fin « ' at line N », part avec le fragment, même si
		// elle écrit « made by » ou « for query' », ou si un motif de requête en mangerait la fermeture.
		yield 'fin de fragment forgée par une valeur' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', 'x' at line 1 c', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 c', '0612345678')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		yield 'fin de fragment forgée suivie de made by' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', 'x' at line 1 made by', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 made by', '0612345678') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		yield 'fin de fragment forgée suivie de for query collé' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', 'x' at line 1 for query', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 for query', '0612345678')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		yield 'fin de fragment forgée suivie d\'une requête' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Dupont', 'x' at line 1 0612345678 INSERT INTO a', 'b')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 0612345678 INSERT INTO a', 'b')",
			'You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		yield 'deux couches forgées' => [
			"You have an error in your SQL syntax near 'Dupont', 'x' at line 1 y' at line 1 made by', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 y' at line 1 made by', '0612345678')",
			'You have an error in your SQL syntax near [sql]',
		];
		// La couche forgée écrit « for query » puis « made by » suivi d'un guillemet et de « at line N » :
		// ce « made by » n'est pas la pile d'appels de WordPress, il ne finit pas la requête.
		yield 'couche forgée avec for query puis made by' => [
			"You have an error in your SQL syntax near 'Dupont', 'x' at line 1 for query z made by', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 for query z made by', '0612345678') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'couche forgée avec for query puis made by, guillemets autour' => [
			"You have an error in your SQL syntax near 'Dupont', 'x' at line 1 for query z' made by w', '0612345678')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 for query z' made by w', '0612345678') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'couche forgée avec for query puis made by, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Dupont', 'x' at line 1 for query z made by', '0612345678')' at line 1",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		// « for query' » forgé, puis une valeur qui écrit « for query » : rien ne reste après le masque.
		yield 'for query collé forgé puis valeur avec for query' => [
			"WordPress database error You have an error in your SQL syntax near 'y' at line 1 for query', '0612345678', 'for query z')' at line 1 for query INSERT INTO wp_contacts (nom, tel, msg) VALUES ('x' y' at line 1 for query', '0612345678', 'for query z') made by do_action('x')",
			'WordPress database error You have an error in your SQL syntax near [sql]',
		];
		// Du texte sans guillemet après « at line N », puis un « for query » (ou une requête) écrit
		// par la valeur, n'est pas le message : il part avec le fragment.
		yield 'couche forgée sans guillemet puis for query' => [
			"You have an error in your SQL syntax near 'Dupont', 'x' at line 1 0612345678 for query ', 'b')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 0612345678 for query ', 'b') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'couche forgée sans guillemet puis requête et for query' => [
			"You have an error in your SQL syntax near 'Dupont', 'x' at line 1 0612345678 INSERT INTO a for query ', 'b')' at line 1 for query INSERT INTO wp_contacts (nom, message, tel) VALUES ('O'Dupont', 'x' at line 1 0612345678 INSERT INTO a for query ', 'b') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'texte sans guillemet puis for query forgé' => [
			"You have an error in your SQL syntax near 'a' at line 1 Jean Dupont 0612345678 for query b', 'x')' at line 1 for query INSERT INTO wp_contacts (nom, tel) VALUES ('a' at line 1 Jean Dupont 0612345678 for query b', 'x') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'texte sans guillemet puis for query forgé, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1 Jean Dupont 0612345678 for query b')' at line 1",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		// Le « made by » écrit par la valeur avant son faux « for query » n'est pas la pile d'appels de
		// WordPress : rien de ce qui suit « near [sql] » ne reste.
		yield 'texte sans guillemet puis made by et for query forgés' => [
			"You have an error in your SQL syntax near 'a' at line 1 Jean Dupont made by 0612345678 for query b', 'x')' at line 1 for query INSERT INTO wp_contacts (nom, tel) VALUES ('a' at line 1 Jean Dupont made by 0612345678 for query b', 'x') made by do_action('wp_ajax_contact')",
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'made by puis for query forgés, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1 made by 0612345678 Dupont for query b')' at line 1",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		// MySQL et MariaDB n'écrivent rien après « at line N » : un texte qui va jusqu'à la fin vient
		// de la valeur, qui choisit où le message est coupé (saut de ligne et « Stack trace: », ligne
		// du journal finie par « in x:1 »). Il part avec le fragment.
		yield 'texte sans guillemet jusqu\'à une coupe forgée' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1 Jean Dupont 0612345678",
			'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'texte sans guillemet jusqu\'à une coupe forgée, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1 Jean Dupont 0612345678",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		// La valeur écrit sa fermeture, « for query » puis « made by » avant la coupe : la vraie pile
		// d'appels a disparu, son « made by » serait pris pour celui de WordPress.
		yield 'fermeture, for query et made by forgés jusqu\'à une coupe forgée, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1 for query x made by Jean Dupont 0612345678",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'fermeture, for query et made by forgés jusqu\'à une coupe forgée, wpdb' => [
			"WordPress database error You have an error in your SQL syntax near 'a' at line 1 for query x made by Jean Dupont 0612345678",
			'WordPress database error You have an error in your SQL syntax near [sql]',
		];
		yield 'fermeture, for query et made by forgés, sortie d\'un connecteur plus ancien' => [
			'You have an error in your SQL syntax near [sql] at line 1 for query [sql] made by Jean Dupont 0612345678',
			'You have an error in your SQL syntax near [sql]',
		];
		// Le numéro de ligne part avec le fragment, vrai ou forgé par la valeur (un téléphone, des
		// chiffres d'une autre écriture).
		yield 'numéro de ligne forgé, wpdb' => [
			"WordPress database error You have an error in your SQL syntax near 'a' at line 10612345678 for query b', 'x')' at line 1 for query INSERT INTO wp_contacts (nom, tel) VALUES ('a' at line 10612345678 for query b', 'x') made by do_action('wp_ajax_contact')",
			'WordPress database error You have an error in your SQL syntax near [sql]',
		];
		yield 'numéro de ligne forgé, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 10612345678",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'numéro de ligne forgé, sortie d\'un connecteur plus ancien' => [
			'You have an error in your SQL syntax near [sql] at line 10612345678 for query [sql]',
			'You have an error in your SQL syntax near [sql]',
		];
		yield 'numéro de ligne en chiffres d\'une autre écriture' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'a' at line 1٠٦١٢٣٤٥٦٧٨",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'numéro de ligne à quatre chiffres' => [
			"You have an error in your SQL syntax near 'Dupont' at line 9999",
			'You have an error in your SQL syntax near [sql]',
		];
		// Une valeur qui écrit la coupe du message (saut de ligne et « Stack trace: », ligne du journal
		// finie par « in x:1 ») avant la fermeture « ' at line N » laisse un « near ' » ouvert : tout
		// ce qui le suit part.
		yield 'fragment coupé avant sa fermeture' => [
			"You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Jean Dupont 0612345678",
			'You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'fragment coupé avant sa fermeture, forme PDO' => [
			"SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Jean Dupont 0612345678",
			'SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]',
		];
		yield 'fragment coupé avant sa fermeture, forme mysqli' => [
			"Uncaught mysqli_sql_exception: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'Jean Dupont 0612345678",
			'Uncaught mysqli_sql_exception: You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near [sql]',
		];
		// Message enveloppé par une extension : une apostrophe AVANT le repère (« Couldn't ») ne
		// compte pas, le texte qui précède le repère reste lisible.
		yield 'message enveloppé, apostrophe avant le repère, fragment coupé' => [
			"Uncaught Exception: Couldn't insert row: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near 'Jean Dupont 0612345678",
			"Uncaught Exception: Couldn't insert row: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near [sql]",
		];
		yield 'message enveloppé, apostrophe avant le repère, fragment fermé' => [
			"Uncaught Exception: Couldn't insert row: You have an error in your SQL syntax near 'a' at line 1 Jean Dupont 0612345678",
			"Uncaught Exception: Couldn't insert row: You have an error in your SQL syntax near [sql]",
		];
		// Erreur d'analyse de MySQL sans « SQL syntax » (« %s near '%-.80s' at line %d »), ou de SQLite
		// (« near "…": syntax error », repère après la valeur) : le repère est alors « near » suivi d'un
		// guillemet, que MySQL écrit avant la valeur et qu'aucune coupe ne retire. Hors message SQL, un
		// « near ' » part aussi (texte d'un gabarit) : la confidentialité passe avant le diagnostic.
		yield 'erreur d\'analyse sans SQL syntax, forme mysqli' => [
			"Uncaught mysqli_sql_exception: memory exhausted near 'Jean Dupont 0612345678' at line 1",
			'Uncaught mysqli_sql_exception: memory exhausted near [sql]',
		];
		yield 'erreur d\'analyse sans SQL syntax, fragment coupé avant sa fermeture' => [
			"Uncaught mysqli_sql_exception: memory exhausted near 'Jean Dupont 0612345678",
			'Uncaught mysqli_sql_exception: memory exhausted near [sql]',
		];
		yield 'erreur de syntaxe de SQLite, repère après la valeur' => [ 'near "Jean Dupont 0612345678": syntax error', 'near [sql]' ];
		yield 'near sans numéro de ligne' => [ "Unexpected character near 'é' in template", 'Unexpected character near [sql]' ];
		yield 'near ouvert hors message SQL' => [ "Unexpected character near 'é", 'Unexpected character near [sql]' ];
		// Sortie d'un connecteur plus ancien sans repère : ce qui suit un masque [sql] part.
		yield 'sortie d\'un connecteur plus ancien sans repère' => [ 'Erreur near [sql] at line 1 0612345678 [sql]', 'Erreur near [sql]' ];
	}

	#[DataProvider( 'erreurs_de_syntaxe_sql' )]
	public function test_le_fragment_d_une_erreur_de_syntaxe_sql_est_masque( string $input, string $expected ): void {
		$out = Redactor::redact( $input, [], Redactor::MAX_CHARS, [] );

		self::assertSame( $expected, $out );
		self::assertSame( $out, Redactor::redact( $out, [], Redactor::MAX_CHARS, [] ), 'Un texte déjà filtré ne change plus.' );
	}

	/**
	 * Valeur recopiée par MySQL et MariaDB (en double, 1062 ; incorrecte, 1366, 1292, 1411, 1525),
	 * souvent saisie par un visiteur (téléphone, date de naissance, nom). Le message garde son
	 * texte jusqu'à l'apostrophe qui ouvre la valeur ; tout ce qui suit part, la colonne, la clé, la
	 * fonction et « at row N » compris : une valeur peut écrire sa propre fermeture, « for key »,
	 * « near » ou un repère, puis couper le message avant sa fermeture. Une valeur vide part aussi :
	 * la garder lisible laisserait une valeur écrire « '' » puis son texte. Sortie d'un connecteur
	 * plus ancien (« '[secret]'Dupont ») comprise.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function valeurs_recopiees_par_mysql(): iterable {
		yield 'valeur incorrecte sous MySQL (1366)' => [ "Incorrect integer value: '06 12 34 56 78' for column 'tel' at row 1", 'Incorrect integer value: [sql]' ];
		yield 'valeur incorrecte sous MariaDB (1366), requête comprise' => [
			"Incorrect integer value: '06 12 34 56 78' for column `u123456789_wp`.`wp_contacts`.`tel` at row 1 for query INSERT INTO wp_contacts (nom, tel) VALUES ('Dupont', '06 12 34 56 78')",
			'Incorrect integer value: [sql]',
		];
		yield 'valeur avec une apostrophe (1366)' => [ "Incorrect integer value: 'O'Brien' for column 'tel' at row 1", 'Incorrect integer value: [sql]' ];
		yield 'valeur tronquée (1292)' => [ "Truncated incorrect DOUBLE value: 'Jean Dupont'", 'Truncated incorrect DOUBLE value: [sql]' ];
		yield 'valeur tronquée avec une apostrophe, requête de WordPress (1292)' => [
			"Truncated incorrect DOUBLE value: 'l'école Jean Moulin' for query SELECT * FROM wp_g2rd_inscriptions WHERE classe = 'l'école Jean Moulin'",
			'Truncated incorrect DOUBLE value: [sql]',
		];
		yield 'valeur tronquée d\'un type à taille (1292)' => [ "Truncated incorrect CHAR(10) value: 'Jean Dupont de la Fontaine'", 'Truncated incorrect CHAR(10) value: [sql]' ];
		yield 'date et heure incorrectes (1292)' => [ "Incorrect datetime value: '12/05/1980 10:00' for column 'date_naissance' at row 1", 'Incorrect datetime value: [sql]' ];
		yield 'date incorrecte (1292)' => [ "Incorrect date value: '12/05/1980' for column 'naissance' at row 1", 'Incorrect date value: [sql]' ];
		yield 'valeur refusée par une fonction (1411)' => [ "Incorrect datetime value: '12/05/1980' for function str_to_date", 'Incorrect datetime value: [sql]' ];
		yield 'valeur incorrecte sans suite (1525)' => [ "Incorrect DATE value: '31/02/1980'", 'Incorrect DATE value: [sql]' ];
		yield 'octets d\'un texte (1366)' => [ "Incorrect string value: '\xF0\x9F\x98\x80 J...' for column 'comment_content' at row 1", 'Incorrect string value: [sql]' ];
		yield 'exception PDO (1292)' => [ "SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: 'Jean Dupont'", 'SQLSTATE[22007]: Invalid datetime format: 1292 Truncated incorrect DOUBLE value: [sql]' ];
		yield 'valeur incorrecte vide' => [ "Incorrect integer value: '' for column 'num_items_sold' at row 1", 'Incorrect integer value: [sql]' ];
		yield 'valeur tronquée vide' => [ "Truncated incorrect DOUBLE value: ''", 'Truncated incorrect DOUBLE value: [sql]' ];
		yield 'valeur trop longue (1406)' => [ "WordPress database error Data too long for column 'meta_value' at row 1", 'WordPress database error Data too long for column [sql]' ];
		yield 'valeur en double qui écrit near (wpdb)' => [ "WordPress database error Duplicate entry 'O'Dupont 0612345678 near 'x' for key 'email'", 'WordPress database error Duplicate entry [sql]' ];
		yield 'valeur incorrecte qui écrit near (PDO)' => [ "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'O'Dupont 0612345678 near 'x' for column 'tel' at row 1", 'SQLSTATE[HY000]: General error: 1366 Incorrect integer value: [sql]' ];
		yield 'valeur en double qui écrit un repère SQL puis near (mysqli)' => [ "Uncaught mysqli_sql_exception: Duplicate entry 'O'Dupont 0612345678 sql syntax near 'x' for key 'email'", 'Uncaught mysqli_sql_exception: Duplicate entry [sql]' ];
		// La valeur coupe le message avant sa fermeture (saut de ligne et « Stack trace: », ligne du journal).
		yield 'valeur en double coupée avant sa fermeture (wpdb)' => [ "WordPress database error Duplicate entry 'O'Dupont 0612345678", 'WordPress database error Duplicate entry [sql]' ];
		yield 'valeur en double coupée avant sa fermeture (mysqli)' => [ "Uncaught mysqli_sql_exception: Duplicate entry 'O'Dupont 0612345678", 'Uncaught mysqli_sql_exception: Duplicate entry [sql]' ];
		yield 'valeur incorrecte coupée avant sa fermeture' => [ "Incorrect integer value: 'O'Dupont 0612345678", 'Incorrect integer value: [sql]' ];
		yield 'valeur incorrecte coupée avant sa fermeture (PDO)' => [ "SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'O'Dupont 0612345678", 'SQLSTATE[HY000]: General error: 1366 Incorrect integer value: [sql]' ];
		yield 'message enveloppé avec apostrophes avant le repère, valeur en double coupée' => [ "Uncaught Exception: Couldn't save 'contact': WordPress database error Duplicate entry 'O'Dupont 0612345678", "Uncaught Exception: Couldn't save 'contact': WordPress database error Duplicate entry [sql]" ];
		// Sortie d'un connecteur plus ancien : la valeur coupée avant sa fermeture suit « '[secret]' ».
		yield 'valeur en double, sortie d\'un connecteur plus ancien' => [ "WordPress database error Duplicate entry '[secret]'Dupont 0612345678", 'WordPress database error Duplicate entry [sql]' ];
		yield 'valeur incorrecte, sortie d\'un connecteur plus ancien' => [ "Incorrect integer value: '[secret]'Dupont 0612345678", 'Incorrect integer value: [sql]' ];
		// Apostrophes en entités HTML : exception levée avec esc_html( $wpdb->last_error ) (WPCS 3),
		// erreur affichée par wpdb::print_error() (htmlspecialchars, ENT_QUOTES), reprise en sortie parasite.
		yield 'valeur en double échappée par esc_html()' => [ 'Uncaught Exception: Duplicate entry &#039;Jean Dupont 0612345678&#039; for key &#039;email&#039;', 'Uncaught Exception: Duplicate entry [sql]' ];
		yield 'erreur affichée en HTML par wpdb::print_error()' => [
			'<div id="error"><p class="wpdberror"><strong>WordPress database error:</strong> [Duplicate entry &#039;Jean Dupont 0612345678&#039; for key &#039;email&#039;]<br /><code>INSERT INTO wp_x (a) VALUES (&#039;Jean Dupont 0612345678&#039;)</code></p></div>',
			'<div id="error"><p class="wpdberror"><strong>WordPress database error:</strong> [Duplicate entry [sql]',
		];
		yield 'valeur incorrecte, apostrophe en entité HTML5' => [ 'Incorrect integer value: &apos;O&apos;Dupont 0612345678&apos; for column &apos;tel&apos;', 'Incorrect integer value: [sql]' ];
		yield 'erreur de SQLite, guillemets en entités HTML' => [ 'near &quot;Jean Dupont 0612345678&quot;: syntax error', 'near [sql]' ];
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
		yield 'version à trois nombres' => [ "Requires version '6.4.0' of WordPress" ];
		// La règle de la valeur incorrecte ne vise que « Incorrect <type> value: '…' ».
		yield 'valeur incorrecte hors de MySQL' => [ "Incorrect value for field 'email' in form 'contact'" ];
		yield 'nombre de paramètres incorrect' => [ "Incorrect parameter count in the call to native function 'concat'" ];
		yield 'valeur incorrecte sans apostrophe' => [ 'Incorrect integer value: 42 given' ];
		// Sans repère d'erreur SQL, les apostrophes d'un message ordinaire ne comptent pas.
		yield 'clé de tableau inconnue' => [ "Undefined index: 'foo' in wp-content/plugins/x/x.php:3" ];
		yield 'clé de tableau inconnue entre guillemets' => [ 'Warning: Undefined array key "foo" in wp-content/plugins/x/x.php on line 3' ];
		// Une apostrophe dans un mot (« can't », « d'un ») ne compte pas, même après un repère.
		yield 'commandes désynchronisées' => [ "Commands out of sync; you can't run this command now" ];
		yield 'commandes désynchronisées (wpdb)' => [ "WordPress database error Commands out of sync; you can't run this command now" ];
		yield 'apostrophes dans les mots après un repère' => [ "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away, can't reconnect d'abord" ];
		// Une erreur d'analyse de PHP (« syntax error, unexpected … ») n'est pas une erreur SQL : son jeton est du code.
		yield 'erreur d\'analyse de PHP 8' => [ 'syntax error, unexpected token "}", expecting ";"' ];
		yield 'erreur d\'analyse de PHP 7' => [ "syntax error, unexpected '}', expecting ';'" ];
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
			str_repeat( 'syntax error near ', 220 ),
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
		// « [sql] » en dernier : la suite d'un masque de requête lui appartient (cf. Redactor::patterns()).
		self::assertSame(
			'Erreur [secret] pour le compte [secret] et [sql]',
			Redactor::redact( 'Erreur [secret] pour le compte secret et [sql]', [], Redactor::MAX_CHARS, [ 'secret', 'sql' ] )
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
		define( 'DB_PASSWORD', 'Xy7kLm2pQz' );

		self::assertSame( [ 'srv123.hstgr.io:3306', 'u123456789_admin', 'srv123.hstgr.io', 'u123456789_wp', 'Xy7kLm2pQz' ], Redactor::known_values() );
		self::assertSame(
			'Base [secret] du compte [secret] hors ligne sur [secret] (repli sur [secret])',
			Redactor::redact( 'Base U123456789_WP du compte u123456789_admin hors ligne sur srv123.hstgr.io:3306 (repli sur srv123.hstgr.io)', [] )
		);
	}

	/**
	 * Mot de passe de la base court, écrit sans nom de champ ni « password= » devant : trop court
	 * pour les motifs de jeton, sans clé reconnue, seuls les motifs le laissent passer ; la
	 * barrière par valeur le masque.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_le_mot_de_passe_de_la_base_est_masque_par_valeur(): void {
		define( 'DB_USER', 'u123456789_admin' );
		define( 'DB_PASSWORD', 'Xy7kLm2pQz' );

		$input = 'Connexion Redis refusée (auth Xy7kLm2pQz) pour u123456789_admin, nouvel essai avec Xy7kLm2pQz';

		self::assertSame( [ 'u123456789_admin', 'Xy7kLm2pQz' ], Redactor::known_values() );
		self::assertStringContainsString( 'Xy7kLm2pQz', Redactor::redact( $input, [], Redactor::MAX_CHARS, [] ) );
		self::assertSame(
			'Connexion Redis refusée (auth [secret]) pour [secret], nouvel essai avec [secret]',
			Redactor::redact( $input, [] )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_les_valeurs_par_defaut_d_une_installation_ne_sont_pas_lues(): void {
		define( 'DB_NAME', 'wordpress' );
		define( 'DB_USER', 'root' );
		define( 'DB_HOST', 'localhost:3306' );
		define( 'DB_PASSWORD', '' );

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
