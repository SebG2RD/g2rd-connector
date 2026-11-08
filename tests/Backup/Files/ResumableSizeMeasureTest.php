<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Files;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Files\ResumableSizeMeasure;
use G2RD\Connector\Environment\SizeReport;
use G2RD\Connector\Tests\TestCase;

/**
 * Mesure reprenable des tailles (phase 4, décision n° 8) : 5 s par passage, reprise au chemin
 * suivant, totaux minimums (`partial`) jusqu'au dernier passage, qui rend les totaux exacts. Le
 * dernier test passe par SizeReport sans mesureur injecté (chemin de production), sur le
 * WP_CONTENT_DIR des tests (tests/bootstrap.php), dans un dossier propre au test.
 *
 * Base du projet (Brain Monkey) : le dernier test simule les options de réseau et le filtre du
 * budget d'entrées.
 */
final class ResumableSizeMeasureTest extends TestCase {

	private const T = 1789000000;

	private string $contenu;

	/** @var list<string> Dossiers créés hors de $contenu, retirés après le test. */
	private array $temporaires = [];

	/** $wpdb global d'origine, rétabli après chaque test. */
	private bool $had_wpdb = false;

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->contenu       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-mesure-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->contenu . '/uploads/2026', 0777, true );
		mkdir( $this->contenu . '/plugins' );
		for ( $i = 0; $i < 30; $i++ ) {
			file_put_contents( $this->contenu . '/plugins/f' . $i . '.php', str_repeat( 'x', 10 ) );
			file_put_contents( $this->contenu . '/uploads/2026/m' . $i . '.jpg', str_repeat( 'y', 100 ) );
		}
	}

	protected function tearDown(): void {
		foreach ( array_merge( [ $this->contenu ], $this->temporaires ) as $dossier ) {
			if ( ! is_dir( $dossier ) ) {
				continue;
			}
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dossier, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $e ) {
				$e->isDir() ? rmdir( $e->getPathname() ) : unlink( $e->getPathname() );
			}
			rmdir( $dossier );
		}
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	public function test_en_une_fois_les_totaux_exacts(): void {
		$r = ResumableSizeMeasure::measure( $this->contenu, $this->contenu . '/uploads', null, 5, 100000 );

		self::assertFalse( $r['partial'] );
		self::assertNull( $r['resume'] );
		self::assertSame( 30 * 10 + 30 * 100, $r['wp_content_bytes'] );
		self::assertSame( 3000, $r['uploads_bytes'] );
		self::assertSame( 60, $r['files_counted'] );
	}

	public function test_par_petits_passages_les_memes_totaux_et_des_minimums_entre_deux(): void {
		$reprise = null;
		$passes  = 0;
		do {
			$r       = ResumableSizeMeasure::measure( $this->contenu, $this->contenu . '/uploads', $reprise, 5, 7 );
			$reprise = $r['resume'];
			++$passes;
			if ( null !== $reprise ) {
				self::assertTrue( $r['partial'] );
				self::assertLessThan( 3300, $r['wp_content_bytes'], 'minimum tant que la mesure n\'est pas finie' );
			}
		} while ( null !== $reprise && $passes < 100 );

		self::assertSame( 3300, $r['wp_content_bytes'] );
		self::assertSame( 3000, $r['uploads_bytes'], 'sous-total des médias cumulé d\'un passage à l\'autre' );
		self::assertSame( 60, $r['files_counted'] );
		self::assertGreaterThan( 5, $passes );
	}

	public function test_medias_hors_de_wp_content_mesure_d_aujourd_hui(): void {
		self::assertTrue( ResumableSizeMeasure::applies( $this->contenu, $this->contenu . '/uploads' ) );
		self::assertTrue( ResumableSizeMeasure::applies( $this->contenu, '' ) );
		self::assertFalse( ResumableSizeMeasure::applies( $this->contenu, dirname( $this->contenu ) . '/ailleurs' ) );
	}

	/** Sans dossier des médias, ils ne sont pas mesurés (null), comme SizeMeter : 0 passerait pour une mesure. */
	public function test_sans_dossier_des_medias_ils_ne_sont_pas_mesures(): void {
		$r = ResumableSizeMeasure::measure( $this->contenu, '', null, 5, 100000 );

		self::assertSame( 3300, $r['wp_content_bytes'] );
		self::assertNull( $r['uploads_bytes'] );
		self::assertSame( 60, $r['files_counted'] );
	}

	/**
	 * wp-content absent, ou racine du système de fichiers : la mesure échoue, comme SizeMeter (SizeReport
	 * l'inscrit `failed`), au lieu de rendre 0 octet mesuré « ok ». Message sans chemin.
	 */
	public function test_wp_content_introuvable_ou_racine_la_mesure_echoue_sans_chemin(): void {
		foreach ( [ $this->contenu . '/absent', '/' ] as $dossier ) {
			try {
				ResumableSizeMeasure::measure( $dossier, '', null, 5, 100000 );
				self::fail( 'mesure rendue pour ' . $dossier );
			} catch ( \UnexpectedValueException $e ) {
				self::assertStringNotContainsString( 'g2rd-mesure-', $e->getMessage() );
				self::assertStringContainsString( 'wp-content', $e->getMessage() );
			}
		}
	}

	/** Une reprise abîmée (option modifiée à la main, version antérieure) ne compte pas : mesure depuis le début. */
	public function test_une_reprise_abimee_repart_du_debut(): void {
		$abimee = [
			'cursor'           => 7,
			'wp_content_bytes' => 999999,
			'uploads_bytes'    => 0,
			'files_counted'    => 1,
		];

		$r = ResumableSizeMeasure::measure( $this->contenu, $this->contenu . '/uploads', $abimee, 5, 100000 );

		self::assertSame( 3300, $r['wp_content_bytes'] );
		self::assertSame( 60, $r['files_counted'] );
	}

	/**
	 * Chemin de production : SizeReport sans mesureur injecté. Premier passage borné (filtre ramené à
	 * 1 000 entrées, le minimum) : option avec `resume`, valeurs minimums ; un passage moins d'une
	 * heure plus tard ne mesure pas ; une heure plus tard, la mesure reprend et finit : option sans
	 * `resume`, dans sa forme d'aujourd'hui, médias exacts.
	 */
	public function test_size_report_sans_mesureur_reprend_au_passage_suivant_puis_finit(): void {
		$racine              = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/g2rd-mesure-' . bin2hex( random_bytes( 4 ) );
		$this->temporaires[] = $racine;
		mkdir( $racine . '/plugins', 0777, true );
		mkdir( $racine . '/uploads' );
		for ( $i = 0; $i < 520; $i++ ) {
			file_put_contents( $racine . '/plugins/f' . $i . '.php', str_repeat( 'x', 10 ) );
			file_put_contents( $racine . '/uploads/m' . $i . '.jpg', str_repeat( 'y', 100 ) );
		}

		$options = [];
		Functions\when( 'get_site_option' )->alias(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_site_option' )->alias(
			static function ( string $name, $value ) use ( &$options ): bool {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_get_upload_dir' )->justReturn( [ 'basedir' => $racine . '/uploads' ] );
		Filters\expectApplied( 'g2rd_connector_environment_scan_entries' )->andReturn( 1000 );
		// Sans $wpdb : verrou indisponible (mesure sans verrou), base non mesurée.
		unset( $GLOBALS['wpdb'] );

		SizeReport::refresh_if_due( self::T );

		$premier = $options[ SizeReport::OPTION ];
		self::assertSame( [ 'at', 'attempted_at', 'sizes', 'resume' ], array_keys( $premier ) );
		self::assertSame( 'partial', $premier['sizes']['status'], 'minimum tant que la mesure n\'est pas finie' );
		self::assertIsArray( $premier['resume'] );
		self::assertLessThan( 1040, $premier['sizes']['files_counted'] );

		SizeReport::refresh_if_due( self::T + 3599 );
		self::assertSame( $premier, $options[ SizeReport::OPTION ], 'moins d\'une heure après : rien' );

		SizeReport::refresh_if_due( self::T + 3600 );

		$fin = $options[ SizeReport::OPTION ];
		self::assertSame( [ 'at', 'attempted_at', 'sizes' ], array_keys( $fin ), 'mesure finie : option dans sa forme d\'aujourd\'hui' );
		self::assertSame( self::T + 3600, $fin['attempted_at'] );
		self::assertSame( 'ok', $fin['sizes']['status'] );
		self::assertSame( 52000, $fin['sizes']['uploads_bytes'] );
		self::assertGreaterThanOrEqual( 57200, $fin['sizes']['wp_content_bytes'] );
		self::assertGreaterThanOrEqual( 1040, $fin['sizes']['files_counted'] );
		self::assertSame( 'unavailable', $fin['sizes']['db_status'] );

		// Mesure finie : la garde de 20 h revient.
		SizeReport::refresh_if_due( self::T + 2 * 3600 );
		self::assertSame( $fin, $options[ SizeReport::OPTION ] );
	}
}
