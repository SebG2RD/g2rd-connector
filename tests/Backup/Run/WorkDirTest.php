<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Tests\TestCase;

final class WorkDirTest extends TestCase {

	private string $dossier;

	protected function setUp(): void {
		parent::setUp();
		$this->dossier = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-workdir-' . bin2hex( random_bytes( 4 ) );
		Functions\when( 'apply_filters' )->alias( fn ( string $nom, $valeur ) => 'g2rd_connector_backup_dir' === $nom ? $this->dossier : $valeur );
		Functions\when( 'wp_salt' )->justReturn( 'sel-de-test' );
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $d ): bool => is_dir( $d ) || mkdir( $d, 0777, true ) );
		Functions\when( 'wp_delete_file' )->alias( static function ( string $f ): void {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		} );
	}

	/** Dossier temporaire du test, créé par lui seul : retiré après chaque test (comme FileWalkerTest). */
	protected function tearDown(): void {
		if ( is_dir( $this->dossier ) ) {
			$iterateur = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dossier, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterateur as $entree ) {
				$entree->isDir() && ! $entree->isLink() ? rmdir( $entree->getPathname() ) : unlink( $entree->getPathname() );
			}
			rmdir( $this->dossier );
		}
		parent::tearDown();
	}

	public function test_dossier_protege_et_dossier_de_la_sauvegarde(): void {
		$job   = WorkDir::job( str_repeat( 'a', 32 ) );
		$prive = $this->dossier . '/' . WorkDir::private_name();

		self::assertIsString( $job );
		foreach ( [ $this->dossier, $prive ] as $dossier ) {
			foreach ( [ 'index.php', '.htaccess', 'web.config' ] as $garde ) {
				self::assertFileExists( $dossier . '/' . $garde );
			}
		}
		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->dossier . '/.htaccess' ) );
		self::assertDirectoryExists( $prive . '/baseline' );
		self::assertSame( $prive . '/baseline', WorkDir::baseline_dir() );
		self::assertSame( $prive . '/job-' . str_repeat( 'a', 32 ), $job );
		self::assertSame( [ '.htaccess', WorkDir::private_name(), 'index.php', 'web.config' ], array_values( array_diff( (array) scandir( $this->dossier ), [ '.', '..' ] ) ), 'rien de devinable sous le dossier servi' );
	}

	public function test_le_nom_du_dossier_prive_vient_des_sels(): void {
		self::assertSame( substr( hash_hmac( 'sha256', 'g2rd-backups-v1', 'sel-de-test' ), 0, 32 ), WorkDir::private_name() );
		Functions\when( 'wp_salt' )->justReturn( 'autre-sel' );
		self::assertNotSame( substr( hash_hmac( 'sha256', 'g2rd-backups-v1', 'sel-de-test' ), 0, 32 ), WorkDir::private_name(), 'sels changés : autre dossier' );
	}

	public function test_un_ancien_dossier_prive_est_vide_de_ses_seuls_fichiers(): void {
		$ancien = $this->dossier . '/' . str_repeat( 'f', 32 );
		mkdir( $ancien . '/job-' . str_repeat( 'e', 32 ), 0777, true );
		mkdir( $ancien . '/baseline', 0777, true );
		file_put_contents( $ancien . '/job-' . str_repeat( 'e', 32 ) . '/manifest.jsonl', 'x' );
		file_put_contents( $ancien . '/baseline/' . str_repeat( 'e', 32 ) . '.jsonl', 'x' );
		file_put_contents( $ancien . '/baseline/current.json', '{}' );
		file_put_contents( $ancien . '/etranger.txt', 'x' );

		self::assertTrue( WorkDir::ensure() );

		self::assertDirectoryDoesNotExist( $ancien . '/job-' . str_repeat( 'e', 32 ) );
		self::assertDirectoryDoesNotExist( $ancien . '/baseline' );
		self::assertFileExists( $ancien . '/etranger.txt', 'un fichier étranger n\'est jamais touché' );
	}

	public function test_nettoyage_ne_retire_que_les_fichiers_du_module(): void {
		$job = (string) WorkDir::job( str_repeat( 'b', 32 ) );
		foreach ( [ 'seg-0.seg', 'seg-1.part', 'segments.jsonl', 'manifest.jsonl', 'index.bin', 'index.sig', 'baseline-new.jsonl' ] as $f ) {
			file_put_contents( $job . '/' . $f, 'x' );
		}
		file_put_contents( $job . '/etranger.txt', 'x' );

		WorkDir::clear_job( str_repeat( 'b', 32 ) );

		self::assertSame( [ 'etranger.txt' ], array_values( array_diff( (array) scandir( $job ), [ '.', '..' ] ) ), 'un fichier étranger n\'est jamais touché' );
	}

	public function test_journal_tronque_a_sa_longueur_validee(): void {
		$job = (string) WorkDir::job( str_repeat( 'c', 32 ) );
		file_put_contents( $job . '/manifest.jsonl', "ligne1\nligne2\n" );

		self::assertSame( [ 'manifest' => 14, 'baseline' => 0, 'segments' => 0 ], WorkDir::journal_sizes( $job ) );
		self::assertTrue( WorkDir::truncate_journals( $job, [ 'manifest' => 7, 'baseline' => 0, 'segments' => 0 ] ) );
		self::assertSame( "ligne1\n", file_get_contents( $job . '/manifest.jsonl' ) );
		self::assertFalse( WorkDir::truncate_journals( $job, [ 'manifest' => 99, 'baseline' => 0, 'segments' => 0 ] ), 'plus court que sa longueur validée : journal perdu' );
	}

	public function test_une_longueur_validee_negative_est_un_etat_abime_rien_n_est_tronque(): void {
		$job = (string) WorkDir::job( str_repeat( 'd', 32 ) );
		file_put_contents( $job . '/manifest.jsonl', "ligne1\nligne2\n" );

		self::assertFalse( WorkDir::truncate_journals( $job, [ 'manifest' => -1, 'baseline' => 0, 'segments' => 0 ] ) );
		self::assertSame( "ligne1\nligne2\n", file_get_contents( $job . '/manifest.jsonl' ), 'journal intact' );
	}

	public function test_un_identifiant_mal_forme_est_une_erreur_de_programmation(): void {
		$this->expectException( \InvalidArgumentException::class );
		WorkDir::job( '../x' );
	}
}
