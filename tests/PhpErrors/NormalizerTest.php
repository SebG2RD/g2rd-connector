<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use Brain\Monkey\Functions;
use G2RD\Connector\PhpErrors\Normalizer;
use G2RD\Connector\PhpErrors\Redactor;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Normalisation d'une erreur PHP : niveau, chemin relatif à WordPress, composant, empreinte
 * stable, version au moment du relevé.
 */
final class NormalizerTest extends TestCase {

	private const ROOTS = [
		'/srv/site/'    => '',
		'/srv/content/' => 'wp-content/',
	];

	/** @var mixed Version de WordPress d'avant le test. */
	private mixed $wp_version = null;

	protected function setUp(): void {
		parent::setUp();
		$this->wp_version = $GLOBALS['wp_version'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wp_version'] = $this->wp_version;
		parent::tearDown();
	}

	/**
	 * @return iterable<string, array{int, string|null}>
	 */
	public static function niveaux(): iterable {
		yield 'E_ERROR' => [ E_ERROR, 'fatal' ];
		yield 'E_PARSE' => [ E_PARSE, 'fatal' ];
		yield 'E_CORE_ERROR' => [ E_CORE_ERROR, 'fatal' ];
		yield 'E_COMPILE_ERROR' => [ E_COMPILE_ERROR, 'fatal' ];
		yield 'E_USER_ERROR' => [ E_USER_ERROR, 'fatal' ];
		yield 'E_RECOVERABLE_ERROR' => [ E_RECOVERABLE_ERROR, 'error' ];
		yield 'E_WARNING' => [ E_WARNING, null ];
		yield 'E_DEPRECATED' => [ E_DEPRECATED, null ];
	}

	#[DataProvider( 'niveaux' )]
	public function test_niveau_d_un_type_d_erreur( int $type, ?string $attendu ): void {
		self::assertSame( $attendu, Normalizer::level_of_error_type( $type ) );
	}

	/**
	 * @return iterable<string, array{string, string, array<string, string>}>
	 */
	public static function chemins(): iterable {
		yield 'racine de WordPress' => [ '/srv/site/wp-config.php', 'wp-config.php', self::ROOTS ];
		yield 'wp-content hors de la racine' => [ '/srv/content/plugins/a/b.php', 'wp-content/plugins/a/b.php', self::ROOTS ];
		yield 'compte devant wp-includes' => [ '/home/u1/domains/x/public_html/wp-includes/functions.php', 'wp-includes/functions.php', self::ROOTS ];
		yield 'hors de WordPress' => [ '/usr/share/php/Foo.php', '[path]/Foo.php', self::ROOTS ];
		yield 'Windows' => [ 'C:\\srv\\site\\wp-content\\themes\\t\\functions.php', 'wp-content/themes/t/functions.php', [ 'C:/srv/site/' => '' ] ];
		yield 'remontée' => [ '/srv/site/../etc/x.php', '[path]/x.php', self::ROOTS ];
		yield 'vide' => [ '', '[path]/unknown', self::ROOTS ];
	}

	/**
	 * @param array<string, string> $roots
	 */
	#[DataProvider( 'chemins' )]
	public function test_chemin_relatif( string $file, string $attendu, array $roots ): void {
		self::assertSame( $attendu, Normalizer::relative_file( $file, $roots ) );
	}

	public function test_un_chemin_trop_long_garde_sa_fin(): void {
		$file = Normalizer::relative_file( '/srv/site/wp-content/plugins/' . str_repeat( 'd/', 200 ) . 'fichier.php', self::ROOTS );

		self::assertSame( Normalizer::FILE_MAX_CHARS, mb_strlen( $file ) );
		self::assertStringStartsWith( '…', $file );
		self::assertStringEndsWith( 'fichier.php', $file );
	}

	/**
	 * @return iterable<string, array{string, string, string|null}>
	 */
	public static function composants(): iterable {
		yield 'extension' => [ 'wp-content/plugins/exemple/x.php', 'plugin', 'exemple' ];
		yield 'extension d\'un seul fichier' => [ 'wp-content/plugins/hello.php', 'plugin', 'hello' ];
		yield 'mu-plugin d\'un fichier' => [ 'wp-content/mu-plugins/exemple-hebergeur.php', 'mu', 'exemple-hebergeur' ];
		yield 'mu-plugin en dossier' => [ 'wp-content/mu-plugins/outil/charge.php', 'mu', 'outil' ];
		yield 'thème' => [ 'wp-content/themes/exemple-theme/functions.php', 'theme', 'exemple-theme' ];
		yield 'cœur, wp-includes' => [ 'wp-includes/functions.php', 'core', null ];
		yield 'cœur, wp-admin' => [ 'wp-admin/includes/file.php', 'core', null ];
		yield 'wp-config' => [ 'wp-config.php', 'unknown', null ];
		yield 'hors de WordPress' => [ '[path]/Foo.php', 'unknown', null ];
		yield 'dossier caché' => [ 'wp-content/plugins/.cache/x.php', 'unknown', null ];
	}

	#[DataProvider( 'composants' )]
	public function test_composant( string $relative, string $type, ?string $slug ): void {
		self::assertSame(
			[
				'type' => $type,
				'slug' => $slug,
			],
			Normalizer::component( $relative )
		);
	}

	public function test_l_empreinte_ignore_nombres_identifiants_et_chaines(): void {
		$file = 'wp-content/plugins/exemple/x.php';

		self::assertSame(
			Normalizer::fingerprint( 'warning', 'plugin', 'exemple', $file, 'Undefined array key "reglages" at offset 12 for 0x7ffd1234 and deadbeefcafe' ),
			Normalizer::fingerprint( 'warning', 'plugin', 'exemple', $file, 'Undefined array key "autre" at offset 99 for 0x1 and feedfacecafe0' )
		);
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', Normalizer::fingerprint( 'fatal', 'core', null, 'wp-includes/x.php', 'm' ) );
	}

	public function test_l_empreinte_change_avec_le_composant_ou_le_niveau(): void {
		$file  = 'wp-content/plugins/exemple/x.php';
		$base  = Normalizer::fingerprint( 'warning', 'plugin', 'exemple', $file, 'Erreur' );

		self::assertNotSame( $base, Normalizer::fingerprint( 'warning', 'plugin', 'autre', $file, 'Erreur' ) );
		self::assertNotSame( $base, Normalizer::fingerprint( 'fatal', 'plugin', 'exemple', $file, 'Erreur' ) );
	}

	public function test_la_ligne_n_entre_pas_dans_l_empreinte(): void {
		$a = Normalizer::occurrence( 'fatal', WP_CONTENT_DIR . '/plugins/exemple/x.php', 10, 'Erreur', 'fatal_catcher' );
		$b = Normalizer::occurrence( 'fatal', WP_CONTENT_DIR . '/plugins/exemple/x.php', 99, 'Erreur', 'fatal_catcher' );

		self::assertSame( $a['fp'], $b['fp'] );
		self::assertSame( 99, $b['line'] );
	}

	public function test_occurrence_d_une_erreur_fatale(): void {
		$file  = WP_CONTENT_DIR . '/plugins/exemple/x.php';
		$error = [
			'type'    => E_ERROR,
			'message' => "Uncaught Error: Call to undefined function x() for contact@exemple.fr in {$file}:87\nStack trace:\n#0 /home/u1/a.php(3): y()\n#1 {main}\n  thrown in {$file} on line 87",
			'file'    => $file,
			'line'    => 87,
		];

		$occurrence = Normalizer::occurrence_from_error( $error );

		self::assertNotNull( $occurrence );
		self::assertSame( 'fatal', $occurrence['level'] );
		self::assertSame( 'plugin', $occurrence['type'] );
		self::assertSame( 'exemple', $occurrence['slug'] );
		self::assertSame( 'wp-content/plugins/exemple/x.php', $occurrence['file'] );
		self::assertSame( 87, $occurrence['line'] );
		self::assertSame( 'fatal_catcher', $occurrence['source'] );
		self::assertStringContainsString( 'Call to undefined function x()', $occurrence['message'] );
		self::assertStringEndsWith( 'for [email]', $occurrence['message'], 'Suffixe « in fichier:ligne » retiré : le fichier et la ligne ont leurs champs.' );
		self::assertStringNotContainsString( 'x.php:87', $occurrence['message'] );
		self::assertStringNotContainsString( 'Stack trace', $occurrence['message'] );
		self::assertStringNotContainsString( 'contact@exemple.fr', $occurrence['message'] );
		self::assertStringNotContainsString( '/home/u1', $occurrence['message'] );
	}

	/**
	 * La même erreur vue par le relevé à l'arrêt (error_get_last()) et par le journal (ligne
	 * découpée par LogTail, message sans « in fichier:ligne ») : même empreinte, même message,
	 * donc un seul groupe dans le tampon.
	 */
	public function test_la_meme_erreur_vue_par_les_deux_sources_a_la_meme_empreinte(): void {
		$file = WP_CONTENT_DIR . '/themes/exemple-theme/functions.php';

		$releve_a  = Normalizer::occurrence_from_error(
			[
				'type'    => E_ERROR,
				'message' => "Uncaught Error: Call to undefined function exemple() in {$file}:214\nStack trace:\n#0 {main}\n  thrown",
				'file'    => $file,
				'line'    => 214,
			]
		);
		$journal_a = Normalizer::occurrence( 'fatal', $file, 214, 'Uncaught Error: Call to undefined function exemple()', Normalizer::SOURCE_LOG );
		$releve_b  = Normalizer::occurrence_from_error(
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
				'file'    => $file,
				'line'    => 12,
			]
		);
		$journal_b = Normalizer::occurrence( 'fatal', $file, 12, 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)', Normalizer::SOURCE_LOG );

		self::assertNotNull( $releve_a );
		self::assertNotNull( $releve_b );
		self::assertSame( $journal_a['fp'], $releve_a['fp'], 'Exception non rattrapée : même empreinte.' );
		self::assertSame( $journal_a['message'], $releve_a['message'] );
		self::assertSame( $journal_b['fp'], $releve_b['fp'], 'Erreur fatale simple : même empreinte.' );
		self::assertSame(
			'Cannot redeclare exemple() (previously declared in wp-content/plugins/a/a.php:12)',
			Normalizer::occurrence( 'fatal', $file, 20, 'Cannot redeclare exemple() (previously declared in ' . WP_CONTENT_DIR . '/plugins/a/a.php:12)', Normalizer::SOURCE_LOG )['message'],
			'Seul le suffixe FINAL est retiré : une mention au milieu du message reste (filtrée).'
		);
	}

	/**
	 * Le message d'une exception non rattrapée n'a aucune borne : il est coupé à la borne du
	 * filtre avant les retraits de la pile d'appels et du suffixe, pour que la fonction d'arrêt
	 * ne recopie jamais plusieurs mégaoctets. Le rendu est celui de ses premiers octets.
	 */
	public function test_un_message_geant_n_est_lu_que_sur_la_borne_du_filtre(): void {
		self::assertSame(
			( new \ReflectionClassConstant( Redactor::class, 'INPUT_MAX_BYTES' ) )->getValue(),
			Normalizer::MESSAGE_MAX_BYTES,
			'Même borne que le filtre : il ne lit rien au-delà.'
		);

		$file    = WP_CONTENT_DIR . '/plugins/exemple/x.php';
		$message = 'Uncaught RuntimeException: ' . str_repeat( 'réponse ', 1000 ) . str_repeat( 'x', 8 * 1024 * 1024 ) . " in {$file}:12\nStack trace:\n#0 {main}\n  thrown";

		$occurrence = Normalizer::occurrence( 'fatal', $file, 12, $message, Normalizer::SOURCE_FATAL );

		self::assertSame( Normalizer::occurrence( 'fatal', $file, 12, substr( $message, 0, Normalizer::MESSAGE_MAX_BYTES ), Normalizer::SOURCE_FATAL ), $occurrence );
		self::assertStringStartsWith( 'Uncaught RuntimeException: réponse réponse', $occurrence['message'] );
		self::assertLessThanOrEqual( Redactor::MAX_CHARS, mb_strlen( $occurrence['message'] ) );
		self::assertStringEndsWith( '…', $occurrence['message'] );
	}

	public function test_une_erreur_sans_gravite_n_est_pas_une_occurrence(): void {
		self::assertNull(
			Normalizer::occurrence_from_error(
				[
					'type'    => E_WARNING,
					'message' => 'x',
					'file'    => 'y',
					'line'    => 1,
				]
			)
		);
	}

	public function test_version_au_moment_du_releve(): void {
		$GLOBALS['wp_version'] = '6.6.2';
		Functions\when( 'get_plugins' )->alias(
			static fn ( string $folder = '' ): array => '/exemple' === $folder ? [ 'exemple.php' => [ 'Version' => '2.4.1' ] ] : []
		);
		Functions\when( 'wp_get_theme' )->alias(
			static fn ( string $slug ): \WP_Theme => new \WP_Theme( $slug, [ 'Version' => '1.9.0' ], 'exemple-theme' === $slug )
		);

		self::assertSame( '6.6.2', Normalizer::component_version( 'core', null ) );
		self::assertSame( '2.4.1', Normalizer::component_version( 'plugin', 'exemple' ) );
		self::assertNull( Normalizer::component_version( 'plugin', 'absente' ) );
		self::assertSame( '1.9.0', Normalizer::component_version( 'theme', 'exemple-theme' ) );
		self::assertNull( Normalizer::component_version( 'theme', 'absent' ) );
		self::assertNull( Normalizer::component_version( 'mu', 'outil' ) );
		self::assertNull( Normalizer::component_version( 'unknown', null ) );
	}

	public function test_une_version_illisible_est_inconnue(): void {
		Functions\when( 'get_plugins' )->alias(
			static function (): array {
				throw new \RuntimeException( 'Lecture impossible' );
			}
		);
		$GLOBALS['wp_version'] = str_repeat( '9', 60 );

		self::assertNull( Normalizer::component_version( 'plugin', 'exemple' ) );
		self::assertNull( Normalizer::component_version( 'core', null ), 'Plus de 50 caractères.' );
	}
}
