<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Environment;

use G2RD\Connector\Environment\SizeMeter;
use G2RD\Connector\Tests\Rollback\FilesystemTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mesure bornée des volumes : wp-content, médias, base (D4, E2). Elle tourne une fois
 * par jour en tâche de fond (tâche 7), sur un serveur partagé par une trentaine de
 * sites du même compte.
 *
 * Les risques couverts : un parcours sans fin (trop de fichiers, disque lent) ; un
 * lien symbolique suivi vers le dossier d'un autre site du même compte ; une mesure
 * qui échoue parce qu'un fichier de cache a disparu pendant le parcours ; un dossier
 * voisin au nom proche (`uploads-old`) compté dans les médias ; une requête de la
 * base non préparée.
 *
 * Les fichiers sont réels (dossier temporaire de FilesystemTestCase) ; seules
 * l'horloge et, pour simuler une entrée qui disparaît, la fabrique du parcours sont
 * injectées.
 */
final class SizeMeterTest extends FilesystemTestCase {

	/** Dossier wp-content de l'arborescence temporaire. */
	private string $content = '';

	/** $wpdb global d'origine, rétabli après chaque test. */
	private bool $had_wpdb = false;

	private mixed $original_wpdb = null;

	/**
	 * Liens créés par le test, retirés avant le nettoyage du dossier temporaire (qui
	 * prendrait un lien vers un dossier pour un dossier).
	 *
	 * @var list<string>
	 */
	private array $links = [];

	/**
	 * Dossiers fermés par le test (droits retirés), rouverts avant le nettoyage.
	 *
	 * @var list<string>
	 */
	private array $closed = [];

	protected function setUp(): void {
		parent::setUp();
		$this->content = $this->root . '/wp-content';
		mkdir( $this->content, 0777, true );
		$this->links  = [];
		$this->closed = [];

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
	}

	protected function tearDown(): void {
		foreach ( $this->closed as $dir ) {
			chmod( $dir, 0777 );
		}
		foreach ( $this->links as $link ) {
			// Le lien seul, jamais sa cible : rmdir() pour une jonction Windows, unlink() ailleurs.
			if ( ! ( '\\' === DIRECTORY_SEPARATOR && is_dir( $link ) ? rmdir( $link ) : unlink( $link ) ) ) {
				self::fail( 'Lien de test impossible à retirer : ' . $link );
			}
		}
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	// ── wp-content et médias ────────────────────────────────────────────────────

	public function test_total_et_sous_total_des_medias(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'wp-content/themes/theme/style.css', 50 );
		$this->write( 'wp-content/uploads/2026/10/image.jpg', 1000 );
		$this->write( 'wp-content/uploads/document.pdf', 500 );

		// Première lecture au départ, les suivantes 42 ms plus tard.
		$readings = 0;
		$clock    = static function () use ( &$readings ): int {
			return 0 === $readings++ ? 1000 : 1042;
		};

		// Chemins tels que WordPress peut les rendre : séparateur final, antislashs (Windows).
		$result = ( new SizeMeter( 5, 100000, $clock ) )->measure_files(
			$this->content . '/',
			str_replace( '/', '\\', $this->content . '/uploads' )
		);

		self::assertSame(
			[
				'wp_content_bytes' => 1650,
				'uploads_bytes'    => 1500,
				'files_counted'    => 4,
				'partial'          => false,
				'scan_ms'          => 42,
			],
			$result
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function trailing_separators(): array {
		return [
			'séparateur final /'         => [ '/' ],
			'séparateur final \\ (Windows)' => [ '\\' ],
		];
	}

	/**
	 * WordPress forme le dossier des médias par `WP_CONTENT_DIR . '/uploads'` : un
	 * WP_CONTENT_DIR défini avec un séparateur final donne `…/wp-content//uploads`. Les
	 * séparateurs répétés sont réduits avant la comparaison, sinon aucun média n'entrerait
	 * dans le sous-total.
	 */
	#[DataProvider( 'trailing_separators' )]
	public function test_un_wp_content_termine_par_un_separateur_garde_le_sous_total_des_medias( string $separator ): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 5 );
		$this->write( 'wp-content/uploads/image.jpg', 10 );

		$content_dir = $this->content . $separator;
		$result      = ( new SizeMeter( 5, 100000 ) )->measure_files( $content_dir, $content_dir . '/uploads' );

		self::assertSame( 15, $result['wp_content_bytes'] );
		self::assertSame( 10, $result['uploads_bytes'], 'les médias sont reconnus malgré le séparateur répété' );
		self::assertSame( 2, $result['files_counted'] );
		self::assertFalse( $result['partial'] );
	}

	public function test_medias_hors_de_wp_content(): void {
		// Constante UPLOADS qui place les médias à côté de wp-content, pas dedans.
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'medias/2026/10/image.jpg', 1000 );
		$this->write( 'medias/document.pdf', 500 );

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $this->root . '/medias' );

		self::assertSame( 100, $result['wp_content_bytes'], 'les médias hors de wp-content n\'entrent pas dans son total' );
		self::assertSame( 1500, $result['uploads_bytes'] );
		self::assertSame( 3, $result['files_counted'], 'les deux parcours comptent' );
		self::assertFalse( $result['partial'] );
	}

	/**
	 * Option upload_path réglée sur wp-content : tout wp-content est « médias », en un
	 * seul parcours.
	 */
	public function test_medias_egaux_a_wp_content_mesures_en_un_seul_parcours(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'wp-content/2026/10/image.jpg', 1000 );

		$asked  = [];
		$result = ( new SizeMeter( 5, 100000, null, $this->recorded_entries( $asked ) ) )->measure_files( $this->content, $this->content . '/' );

		self::assertSame( 1100, $result['wp_content_bytes'] );
		self::assertSame( 1100, $result['uploads_bytes'], 'les médias sont tout wp-content' );
		self::assertSame( 2, $result['files_counted'] );
		self::assertFalse( $result['partial'] );
		self::assertSame( [ str_replace( '\\', '/', $this->content ) ], $asked, 'un seul parcours' );
	}

	/**
	 * Médias dans wp-content mais absents (site sans aucun média envoyé) : le parcours
	 * de wp-content a tout vu, aucun média n'existe, la mesure exacte est 0. Hors de
	 * wp-content, un dossier qui ne s'ouvre pas rend null : absent ou illisible, on ne
	 * sait pas lequel.
	 */
	public function test_medias_dans_wp_content_absents_valent_zero(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertSame( 0, $result['uploads_bytes'] );
		self::assertSame( 100, $result['wp_content_bytes'] );
		self::assertSame( 1, $result['files_counted'] );
		self::assertFalse( $result['partial'] );
	}

	/**
	 * Option upload_path réglée sur ABSPATH, le dossier du compte ou celui des domaines :
	 * le dossier des médias CONTIENT wp-content. Le parcourir compterait wp-content deux
	 * fois et passerait par les dossiers des autres sites du compte : il n'est pas
	 * mesuré (null), et wp-content n'est parcouru qu'une fois.
	 */
	public function test_medias_qui_contiennent_wp_content_ne_sont_pas_parcourus(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'autre-site/wp-content/uploads/enorme.bin', 5000 );

		$asked  = [];
		$result = ( new SizeMeter( 5, 100000, null, $this->recorded_entries( $asked ) ) )->measure_files( $this->content, $this->root );

		self::assertNull( $result['uploads_bytes'], 'configuration anormale : non mesurée' );
		self::assertSame( 100, $result['wp_content_bytes'] );
		self::assertSame( 1, $result['files_counted'], 'wp-content compté une seule fois' );
		self::assertFalse( $result['partial'] );
		self::assertSame( [ str_replace( '\\', '/', $this->content ) ], $asked, 'seul wp-content est parcouru' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unknown_uploads(): array {
		return [
			'aucun dossier des médias'                     => [ '' ],
			'dossier hors de wp-content impossible à ouvrir' => [ '/medias-absent' ],
			// PHP refuse ce chemin par une \ValueError, pas une \UnexpectedValueException.
			'chemin refusé par PHP (octet nul)'            => [ "/medias\0absent" ],
		];
	}

	#[DataProvider( 'unknown_uploads' )]
	public function test_medias_inconnus_rendent_null_sans_faire_echouer_la_mesure( string $uploads ): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$uploads_dir = '' === $uploads ? '' : $this->root . $uploads;

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $uploads_dir );

		self::assertNull( $result['uploads_bytes'] );
		self::assertSame( 100, $result['wp_content_bytes'], 'le reste est mesuré' );
		self::assertSame( 1, $result['files_counted'] );
		self::assertFalse( $result['partial'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function filesystem_roots(): array {
		return [
			'racine /'                           => [ '/' ],
			'racine \\ (Windows)'                => [ '\\' ],
			// « C: » seul désigne le dossier COURANT du lecteur pour PHP, pas sa racine.
			'racine de lecteur C:\\ (Windows)' => [ 'C:\\' ],
			'racine de lecteur C:/ (Windows)'   => [ 'C:/' ],
		];
	}

	/**
	 * Option upload_path à `/` : le dossier des médias est la racine du système de
	 * fichiers, vide (ou `C:` pour un lecteur Windows) une fois normalisé. Il n'est pas
	 * mesuré (null), et le reste l'est.
	 */
	#[DataProvider( 'filesystem_roots' )]
	public function test_medias_a_la_racine_du_systeme_rendent_null_sans_faire_echouer_la_mesure( string $root ): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $root );

		self::assertNull( $result['uploads_bytes'] );
		self::assertSame( 100, $result['wp_content_bytes'], 'le reste est mesuré' );
		self::assertSame( 1, $result['files_counted'] );
		self::assertFalse( $result['partial'] );
	}

	/**
	 * Parcourir la racine du système de fichiers, ce serait parcourir tout le serveur,
	 * dossiers des autres sites du même compte compris : elle n'est jamais remise au
	 * parcours, ni comme dossier des médias, ni comme wp-content (qui fait alors échouer
	 * la mesure, avec le même message fixe qu'un dossier absent).
	 */
	#[DataProvider( 'filesystem_roots' )]
	public function test_la_racine_du_systeme_n_est_jamais_parcourue( string $root ): void {
		$asked   = [];
		$entries = static function ( string $dir ) use ( &$asked ): \Iterator {
			$asked[] = $dir;
			return new \ArrayIterator( [] );
		};
		$meter   = new SizeMeter( 5, 100000, null, $entries );

		$meter->measure_files( $this->content, $root );
		self::assertSame( [ str_replace( '\\', '/', $this->content ) ], $asked, 'seul wp-content est parcouru' );

		$asked = [];
		try {
			$meter->measure_files( $root, '' );
			self::fail( 'wp-content à la racine du système doit faire échouer la mesure' );
		} catch ( \UnexpectedValueException $e ) {
			self::assertStringContainsString( 'wp-content', $e->getMessage() );
		}
		self::assertSame( [], $asked, 'rien n\'est parcouru' );
	}

	public function test_un_dossier_voisin_au_nom_proche_n_est_pas_compte_dans_les_medias(): void {
		$this->write( 'wp-content/uploads/image.jpg', 1000 );
		$this->write( 'wp-content/uploads-old/image.jpg', 300 );
		// Voisin de wp-content : ni dans son total, ni dans les médias.
		$this->write( 'wp-content-uploads/image.jpg', 7 );

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertSame( 1000, $result['uploads_bytes'], 'uploads-old n\'est pas dans uploads' );
		self::assertSame( 1300, $result['wp_content_bytes'], 'les deux dossiers sont dans wp-content, pas son voisin' );
		self::assertSame( 2, $result['files_counted'] );
	}

	// ── Bornes ──────────────────────────────────────────────────────────────────

	public function test_borne_en_nombre_d_entrees_rend_un_minimum_partiel(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->write( 'wp-content/uploads/fichier-' . $i . '.txt', 10 );
		}

		$result = ( new SizeMeter( 5, 4 ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertTrue( $result['partial'], 'borne atteinte' );
		self::assertSame( 4, $result['files_counted'], 'jamais plus d\'entrées que la borne' );
		self::assertSame( 40, $result['wp_content_bytes'], 'minimum : ce qui a été vu avant la borne' );
		self::assertSame( 40, $result['uploads_bytes'] );
	}

	/**
	 * @return array<string, array{int, int, int|null, bool}>
	 */
	public static function shared_entry_budgets(): array {
		return [
			// Budget, octets de wp-content, octets des médias, dossier des médias ouvert.
			'borne atteinte dans les médias'              => [ 4, 30, 100, true ],
			'borne atteinte pile à la fin de wp-content' => [ 3, 30, null, false ],
			'borne atteinte dans wp-content'              => [ 2, 20, null, false ],
		];
	}

	/**
	 * Un seul budget pour les deux parcours. Borne atteinte avant les médias hors de
	 * wp-content : leur dossier n'est pas ouvert, et rien n'en ayant été vu, ils ne sont
	 * pas mesurés (null), plutôt qu'un 0 qui passerait pour un minimum.
	 */
	#[DataProvider( 'shared_entry_budgets' )]
	public function test_le_second_parcours_partage_le_budget_d_entrees( int $budget, int $content_bytes, ?int $uploads_bytes, bool $uploads_opened ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->write( 'wp-content/plugins/fichier-' . $i . '.php', 10 );
			$this->write( 'medias/image-' . $i . '.jpg', 100 );
		}

		$asked  = [];
		$result = ( new SizeMeter( 5, $budget, null, $this->recorded_entries( $asked ) ) )->measure_files( $this->content, $this->root . '/medias' );

		self::assertTrue( $result['partial'] );
		self::assertSame( $budget, $result['files_counted'], 'un seul budget pour les deux parcours' );
		self::assertSame( $content_bytes, $result['wp_content_bytes'] );
		self::assertSame( $uploads_bytes, $result['uploads_bytes'], 'minimum, ou null si aucun média n\'a été vu' );
		$opened = [ str_replace( '\\', '/', $this->content ), str_replace( '\\', '/', $this->root . '/medias' ) ];
		self::assertSame( $uploads_opened ? $opened : [ $opened[0] ], $asked );
	}

	public function test_borne_en_temps_rend_un_minimum_partiel(): void {
		for ( $i = 0; $i < 600; $i++ ) {
			$this->write( 'wp-content/cache/fichier-' . $i . '.txt', 1 );
		}

		// Chaque entrée parcourue « coûte » 10 ms sur l'horloge injectée : la seconde de
		// budget est dépassée à la 100e entrée, et l'horloge n'est relue que toutes les
		// 256 entrées.
		$now     = 0;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$entries = static function ( string $dir ) use ( &$now ): \Generator {
			$real = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $real as $path => $entry ) {
				$now += 10;
				yield $path => $entry;
			}
		};

		$result = ( new SizeMeter( 1, 100000, $clock, $entries ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertTrue( $result['partial'], 'borne de temps atteinte' );
		self::assertSame( 256, $result['files_counted'], 'arrêt au premier contrôle après la borne' );
		self::assertSame( 256, $result['wp_content_bytes'], 'minimum : 256 fichiers d\'un octet sur 600' );
		self::assertSame( 0, $result['uploads_bytes'] );
		self::assertGreaterThanOrEqual( 1000, $result['scan_ms'] );
	}

	/**
	 * Une arborescence faite de dossiers vides (cache vidé mais gardé, sessions) ne
	 * remet aucun fichier : les dossiers traversés doivent eux aussi faire avancer le
	 * contrôle du temps, sinon le parcours n'aurait plus de borne. Ils ne comptent pas
	 * comme des entrées (`files_counted`).
	 */
	public function test_une_arborescence_de_dossiers_vides_reste_bornee_en_temps(): void {
		for ( $i = 0; $i < 300; $i++ ) {
			mkdir( $this->content . '/cache/session-' . $i, 0777, true );
		}

		// Chaque lecture de l'horloge après le départ avance de 600 ms : la seconde de
		// budget est dépassée au deuxième contrôle (256 dossiers plus loin).
		$readings = 0;
		$clock    = static function () use ( &$readings ): int {
			return 600 * $readings++;
		};

		$result = ( new SizeMeter( 1, 100000, $clock ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertTrue( $result['partial'], 'borne de temps atteinte sans aucun fichier' );
		self::assertSame( 0, $result['files_counted'], 'un dossier n\'est pas une entrée' );
		self::assertSame( 0, $result['wp_content_bytes'] );
		self::assertSame( 4, $readings, 'départ, deux contrôles pendant le parcours, durée finale' );
	}

	// ── Robustesse et sécurité du parcours ──────────────────────────────────────

	/**
	 * Un lien (vers le dossier d'un autre site du même compte, par exemple) n'est
	 * jamais suivi : ni parcouru, ni mesuré par sa cible. Sous Windows sans le droit de
	 * créer des liens symboliques, une jonction en tient lieu pour le dossier ; ignoré
	 * si le système refuse les deux (la CI Linux le joue).
	 */
	public function test_un_lien_symbolique_n_est_jamais_suivi(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'autre-site/wp-content/uploads/enorme.bin', 5000 );
		$this->write( 'autre-site/wp-config.php', 900 );

		$dir_link = $this->content . '/plugins/raccourci';
		if ( ! $this->link( $this->root . '/autre-site', $dir_link ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
		$expected_entries = 2;
		// Lien vers un fichier : getSize() suivrait le lien et rendrait la taille de la cible.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
		if ( @symlink( $this->root . '/autre-site/wp-config.php', $this->content . '/config-voisin.php' ) ) {
			$this->links[] = $this->content . '/config-voisin.php';
			++$expected_entries;
		}

		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertSame( 100, $result['wp_content_bytes'], 'rien de la cible des liens n\'est compté' );
		self::assertSame( $expected_entries, $result['files_counted'], 'un lien compte comme une entrée, pour 0 octet' );
		self::assertSame( 0, $result['uploads_bytes'], 'les médias de l\'autre site ne sont pas parcourus' );
		self::assertFalse( $result['partial'] );
	}

	/**
	 * @return array<string, array{string, string, int}>
	 */
	public static function linked_uploads(): array {
		return [
			// Emplacement du lien, dossier des médias, entrées comptées (wp-content seul).
			'médias = lien dans wp-content'       => [ 'wp-content/uploads', 'wp-content/uploads', 2 ],
			'médias sous un lien dans wp-content' => [ 'wp-content/partage', 'wp-content/partage/2026', 2 ],
			'médias = lien hors de wp-content'    => [ 'medias', 'medias', 1 ],
		];
	}

	/**
	 * Dossier des médias qui est un lien (stockage partagé, médias d'un autre site du
	 * compte) : dans wp-content, le parcours voit le lien comme une entrée de 0 octet et
	 * un sous-total de 0 passerait pour une mesure exacte ; hors de wp-content, l'ouvrir
	 * suivrait le lien. Dans les deux cas : non mesuré (null), jamais parcouru. Même
	 * précédent de lien (ou de jonction Windows) que test_un_lien_symbolique_n_est_jamais_suivi.
	 */
	#[DataProvider( 'linked_uploads' )]
	public function test_un_dossier_des_medias_atteint_par_un_lien_n_est_pas_mesure( string $link, string $uploads, int $entries ): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'autre-site/2026/enorme.bin', 5000 );

		if ( ! $this->link( $this->root . '/autre-site', $this->root . '/' . $link ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}

		$asked  = [];
		$result = ( new SizeMeter( 5, 100000, null, $this->recorded_entries( $asked ) ) )->measure_files( $this->content, $this->root . '/' . $uploads );

		self::assertNull( $result['uploads_bytes'], 'non mesuré : ni 0, ni la taille de la cible' );
		self::assertSame( 100, $result['wp_content_bytes'], 'rien de la cible n\'est compté' );
		self::assertSame( $entries, $result['files_counted'], 'un lien dans wp-content compte comme une entrée, pour 0 octet' );
		self::assertFalse( $result['partial'] );
		self::assertSame( [ str_replace( '\\', '/', $this->content ) ], $asked, 'la cible du lien n\'est jamais ouverte' );
	}

	public function test_dossier_illisible_ignore_sans_erreur(): void {
		$this->write( 'wp-content/plugins/extension/extension.php', 100 );
		$this->write( 'wp-content/uploads/image.jpg', 1000 );
		$this->write( 'wp-content/prive/secret.txt', 50 );

		$closed = $this->content . '/prive';
		chmod( $closed, 0000 );
		$this->closed[] = $closed;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- sonde : le dossier doit être illisible.
		$handle = @opendir( $closed );
		if ( false !== $handle ) {
			closedir( $handle );
			self::markTestSkipped( 'Le système ignore les droits retirés (Windows, ou tests lancés en root).' );
		}

		$this->expectOutputString( '' );
		$result = ( new SizeMeter( 5, 100000 ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertSame( 1100, $result['wp_content_bytes'], 'le dossier illisible est sauté' );
		self::assertSame( 1000, $result['uploads_bytes'] );
		self::assertSame( 2, $result['files_counted'] );
		self::assertFalse( $result['partial'], 'un dossier illisible n\'est pas une borne atteinte' );
	}

	public function test_un_fichier_disparu_pendant_le_parcours_ne_fait_pas_echouer_la_mesure(): void {
		$first  = $this->write( 'wp-content/cache/page-1.html', 300 );
		$second = $this->write( 'wp-content/cache/page-3.html', 200 );
		// Lu dans le dossier, supprimé avant son stat (cache, sessions, fichiers temporaires).
		$vanished = new class( $this->content . '/cache/page-2.html' ) extends \SplFileInfo {
			public function getSize(): int|false {
				throw new \RuntimeException( 'SplFileInfo::getSize(): stat failed for ' . $this->getPathname() );
			}
		};
		$asked   = [];
		$entries = static function ( string $dir ) use ( &$asked, $first, $second, $vanished ): \Iterator {
			$asked[] = $dir;
			return new \ArrayIterator( [ new \SplFileInfo( $first ), $vanished, new \SplFileInfo( $second ) ] );
		};

		$result = ( new SizeMeter( 5, 100000, null, $entries ) )->measure_files( $this->content, $this->content . '/uploads' );

		self::assertSame( 500, $result['wp_content_bytes'], 'l\'entrée disparue compte 0 octet' );
		self::assertSame( 3, $result['files_counted'], 'elle compte comme une entrée' );
		self::assertFalse( $result['partial'], 'ce n\'est pas une borne atteinte' );
		self::assertSame( [ str_replace( '\\', '/', $this->content ) ], $asked, 'un seul parcours : les médias sont dans wp-content' );
	}

	public function test_racine_illisible_fait_echouer_la_mesure(): void {
		$missing = $this->root . '/wp-content-absent';

		try {
			( new SizeMeter( 5, 100000 ) )->measure_files( $missing, $missing . '/uploads' );
			self::fail( 'wp-content impossible à ouvrir doit faire échouer la mesure' );
		} catch ( \UnexpectedValueException $e ) {
			// Message explicite, sans chemin du serveur : il ne doit rien révéler s'il est affiché.
			self::assertStringContainsString( 'wp-content', $e->getMessage() );
			self::assertStringNotContainsString( basename( $this->root ), $e->getMessage() );
			self::assertStringNotContainsString( 'wp-content-absent', $e->getMessage() );
			self::assertNull( $e->getPrevious(), 'l\'exception d\'origine, qui porte le chemin, n\'est pas transmise' );
		}
	}

	// ── Base de données ─────────────────────────────────────────────────────────

	public function test_taille_de_la_base_sur_le_prefixe_de_base(): void {
		$wpdb = $this->install_wpdb(
			[
				[
					'Name'         => 'wp_options',
					'Data_length'  => '1048576',
					'Index_length' => '16384',
				],
				// Table d'un blog secondaire (multisite) : sous le préfixe de base.
				[
					'Name'         => 'wp_2_posts',
					'Data_length'  => '2000',
					'Index_length' => '1000',
				],
				// Une vue : longueurs NULL.
				[
					'Name'         => 'wp_vue',
					'Data_length'  => null,
					'Index_length' => null,
				],
			]
		);

		self::assertSame(
			[
				'db_bytes'  => 1048576 + 16384 + 2000 + 1000,
				'db_status' => 'ok',
			],
			( new SizeMeter( 5, 100000 ) )->measure_database()
		);
		self::assertSame( [ 'wp\\_%' ], $wpdb->prepared[0]['args'], 'préfixe de base (wp_), pas celui du blog courant (wp_2_)' );
	}

	/**
	 * @return array<string, array{list<array<string, string|null>>|\Throwable, string}>
	 */
	public static function unavailable_databases(): array {
		return [
			'erreur SQL'         => [ [], "Access denied for user 'u123'@'localhost'" ],
			'résultat vide'      => [ [], '' ],
			'exception levée'    => [ new \RuntimeException( 'mysqli_sql_exception' ), '' ],
		];
	}

	/**
	 * @param list<array<string, string|null>>|\Throwable $rows  Réponse programmée.
	 * @param string                                    $error Erreur SQL laissée dans last_error.
	 */
	#[DataProvider( 'unavailable_databases' )]
	public function test_base_indisponible( array|\Throwable $rows, string $error ): void {
		$this->install_wpdb( $rows, $error );

		self::assertSame(
			[
				'db_bytes'  => null,
				'db_status' => 'unavailable',
			],
			( new SizeMeter( 5, 100000 ) )->measure_database()
		);
	}

	public function test_la_requete_de_la_base_est_preparee_et_echappee(): void {
		$wpdb              = $this->install_wpdb( [] );
		$wpdb->base_prefix = 'wp_site_';

		( new SizeMeter( 5, 100000 ) )->measure_database();

		self::assertCount( 1, $wpdb->prepared );
		self::assertSame( 'SHOW TABLE STATUS LIKE %s', $wpdb->prepared[0]['query'] );
		self::assertSame( [ 'wp\\_site\\_%' ], $wpdb->prepared[0]['args'], 'les _ du préfixe échappés (esc_like), puis le joker' );
		self::assertSame(
			[ [ $wpdb->prepared[0]['result'], ARRAY_A ] ],
			$wpdb->queries,
			'la requête envoyée est exactement celle que prepare() a rendue'
		);
	}

	// ── Outils ──────────────────────────────────────────────────────────────────

	/**
	 * Écrit un fichier de $bytes octets (dossiers créés au besoin) sous le dossier temporaire.
	 */
	private function write( string $relative, int $bytes ): string {
		$path = $this->root . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
		return $path;
	}

	/**
	 * Fabrique du parcours qui note chaque dossier demandé, puis rend le même parcours
	 * que la fabrique par défaut de SizeMeter.
	 *
	 * @param list<string> $asked Dossiers demandés, dans l'ordre (rempli par la fabrique).
	 * @return \Closure(string): \Iterator<mixed, \SplFileInfo>
	 */
	private function recorded_entries( array &$asked ): \Closure {
		return static function ( string $dir ) use ( &$asked ): \Iterator {
			$asked[] = $dir;
			return new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST,
				\RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		};
	}

	/**
	 * Lien symbolique vers un dossier, ou jonction sous Windows sans droit de créer des
	 * liens (même précédent que ProtectedUpdateTest).
	 */
	private function link( string $target, string $link ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
		$created = @symlink( $target, $link );
		if ( ! $created && '\\' === DIRECTORY_SEPARATOR && function_exists( 'exec' ) ) {
			$to_windows = static fn ( string $path ): string => '"' . str_replace( '/', '\\', $path ) . '"';
			exec( 'cmd /c mklink /J ' . $to_windows( $link ) . ' ' . $to_windows( $target ) . ' 2>&1', $output, $code );
			$created = 0 === $code && is_dir( $link );
		}
		if ( $created ) {
			$this->links[] = $link;
		}
		return $created;
	}

	/**
	 * Faux $wpdb réduit à ce que measure_database() demande : esc_like(), prepare() et
	 * get_results(), journalisés.
	 *
	 * @param list<array<string, string|null>>|\Throwable $rows  Lignes de SHOW TABLE STATUS, ou exception levée.
	 * @param string                                    $error Erreur SQL laissée dans last_error.
	 */
	private function install_wpdb( array|\Throwable $rows, string $error = '' ): object {
		$wpdb            = new class( $rows, $error ) {

			/** Préfixe commun à tout le réseau. */
			public string $base_prefix = 'wp_';

			/** Préfixe du blog courant (blog secondaire d'un réseau). */
			public string $prefix = 'wp_2_';

			public string $last_error = '';

			/** @var list<array{query: string, args: list<mixed>, result: string}> */
			public array $prepared = [];

			/** @var list<array{string, mixed}> */
			public array $queries = [];

			/**
			 * @param list<array<string, string|null>>|\Throwable $rows
			 */
			public function __construct( private array|\Throwable $rows, private string $error ) {}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				$result           = str_replace( '%s', "'" . addslashes( (string) $args[0] ) . "'", $query );
				$this->prepared[] = [
					'query'  => $query,
					'args'   => array_values( $args ),
					'result' => $result,
				];
				return $result;
			}

			/**
			 * @return list<array<string, string|null>>
			 */
			public function get_results( string $query, mixed $output ): array {
				$this->queries[] = [ $query, $output ];
				if ( $this->rows instanceof \Throwable ) {
					throw $this->rows;
				}
				$this->last_error = $this->error;
				return $this->rows;
			}
		};
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}
}
