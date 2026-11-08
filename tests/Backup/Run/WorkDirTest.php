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

	// ── Retrait d'un départ refusé : jamais en suivant un lien ───────────────

	/**
	 * Le chemin `job-<id>` est un lien vers un dossier hors du dossier privé, qui contient des
	 * fichiers aux noms du module : le retrait ne le suit pas. Il rend faux, le lien reste, les
	 * fichiers visés aussi. Appel direct après ensure() : par start(), clear_job() passerait avant
	 * le retrait. Sous Windows sans le droit de créer des liens symboliques, une jonction en tient
	 * lieu ; ignoré si le système refuse les deux.
	 */
	public function test_retrait_d_un_depart_ne_suit_jamais_un_dossier_qui_est_un_lien(): void {
		$id = str_repeat( '1', 32 );
		self::assertTrue( WorkDir::ensure() );
		$dehors = $this->dossier . '/dehors';
		mkdir( $dehors );
		file_put_contents( $dehors . '/manifest.jsonl', "{\"t\":\"head\"}\n" );
		file_put_contents( $dehors . '/seg-0.seg', 'segment' );
		$lien = WorkDir::private_dir() . '/job-' . $id;
		if ( ! self::lien_vers_un_dossier( $dehors, $lien ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
		try {
			self::assertFalse( WorkDir::discard_new_job( $id ), 'lien : rien n\'est retiré' );
			clearstatcache();
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
			self::assertSame( [ 'manifest.jsonl', 'seg-0.seg' ], array_values( array_diff( (array) scandir( $dehors ), [ '.', '..' ] ) ), 'dossier visé intact' );
			self::assertSame( "{\"t\":\"head\"}\n", file_get_contents( $dehors . '/manifest.jsonl' ) );
			self::assertSame( 'segment', file_get_contents( $dehors . '/seg-0.seg' ) );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Le dossier `job-<id>` est un vrai dossier, mais son `manifest.jsonl` est un lien vers un
	 * fichier hors du dossier privé : le lien et sa cible restent, donc le dossier aussi, et le
	 * retrait rend faux. Le vrai fichier du module à côté, lui, est retiré. Lien vers un fichier :
	 * une jonction Windows n'en tient pas lieu, ignoré sans le droit de créer des liens.
	 */
	public function test_retrait_d_un_depart_ne_suit_jamais_un_fichier_qui_est_un_lien(): void {
		$id = str_repeat( '2', 32 );
		self::assertTrue( WorkDir::ensure() );
		$cible = $this->dossier . '/dehors.jsonl';
		file_put_contents( $cible, "à garder\n" );
		$job = WorkDir::private_dir() . '/job-' . $id;
		mkdir( $job );
		file_put_contents( $job . '/seg-0.part', 'partie' );
		$lien = $job . '/manifest.jsonl';
		if ( ! function_exists( 'symlink' ) || ! @symlink( $cible, $lien ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai mené en CI Linux.' );
		}
		try {
			self::assertFalse( WorkDir::discard_new_job( $id ), 'un lien reste : le dossier aussi' );
			clearstatcache();
			self::assertTrue( is_link( $lien ), 'le lien reste' );
			self::assertSame( "à garder\n", file_get_contents( $cible ), 'fichier visé intact' );
			self::assertDirectoryExists( $job, 'dossier gardé' );
			self::assertSame( [ 'manifest.jsonl' ], array_values( array_diff( (array) scandir( $job ), [ '.', '..' ] ) ), 'seul le vrai fichier du module est retiré' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Un lien cassé au chemin `job-<id>` (sa cible n'existe pas) compte comme déjà là : le départ
	 * ne prend jamais ce chemin pour un dossier qu'il a créé. Le retrait ne le touche pas non plus.
	 */
	public function test_un_lien_casse_au_chemin_du_dossier_de_la_sauvegarde_compte_comme_deja_la(): void {
		$id = str_repeat( '3', 32 );
		self::assertTrue( WorkDir::ensure() );
		self::assertFalse( WorkDir::job_exists( $id ), 'rien encore au chemin' );
		$absent = $this->dossier . '/absent';
		$lien   = WorkDir::private_dir() . '/job-' . $id;
		if ( ! self::lien_vers_un_dossier( $absent, $lien ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
		try {
			clearstatcache();
			self::assertFileDoesNotExist( $absent, 'cible absente' );
			self::assertFalse( is_dir( $lien ), 'lien cassé : rien au bout' );

			self::assertTrue( WorkDir::job_exists( $id ), 'lien cassé : quelque chose est déjà là' );
			self::assertFalse( WorkDir::discard_new_job( $id ) );
			clearstatcache();
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	// ── Outils ────────────────────────────────────────────────────────────────

	/**
	 * Lien symbolique de `$link` vers le dossier `$target` (qui peut ne pas exister) ; sous Windows
	 * sans le droit de créer des liens, une jonction (`mklink /J`, sans droit particulier, comme
	 * dans ProtectedUpdateTest) en tient lieu. Faux si le système refuse les deux.
	 */
	private static function lien_vers_un_dossier( string $target, string $link ): bool {
		if ( function_exists( 'symlink' ) && @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			return true;
		}
		if ( '\\' !== DIRECTORY_SEPARATOR || ! function_exists( 'exec' ) ) {
			return false;
		}
		$to_windows = static fn ( string $path ): string => '"' . str_replace( '/', '\\', $path ) . '"';
		exec( 'cmd /c mklink /J ' . $to_windows( $link ) . ' ' . $to_windows( $target ) . ' 2>&1', $output, $code );
		clearstatcache();
		return 0 === $code && ( is_link( $link ) || file_exists( $link ) );
	}

	/** Retire le lien lui-même, jamais sa cible : rmdir() pour une jonction ou un lien de dossier sous Windows, unlink() ailleurs. */
	private static function retirer_lien( string $link ): void {
		if ( ! ( '\\' === DIRECTORY_SEPARATOR ? @rmdir( $link ) : @unlink( $link ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
