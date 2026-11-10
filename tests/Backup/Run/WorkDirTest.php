<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Tests\Backup\Support\LiensSurDisque;
use G2RD\Connector\Tests\TestCase;

final class WorkDirTest extends TestCase {

	use LiensSurDisque;

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

	/**
	 * Départ d'une sauvegarde (clear_job, appelé par start) : un `job-<id>` qui est un lien vers un
	 * dossier hors du dossier privé n'est pas suivi ; le lien et les fichiers visés restent.
	 */
	public function test_nettoyage_d_un_depart_ne_suit_jamais_un_dossier_qui_est_un_lien(): void {
		$id = str_repeat( '4', 32 );
		self::assertTrue( WorkDir::ensure() );
		$dehors = $this->dossier . '/dehors-nettoyage';
		mkdir( $dehors );
		file_put_contents( $dehors . '/manifest.jsonl', "{\"t\":\"head\"}\n" );
		file_put_contents( $dehors . '/seg-0.seg', 'segment' );
		$lien = WorkDir::private_dir() . '/job-' . $id;
		if ( ! self::lien_vers_un_dossier( $dehors, $lien ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
		try {
			WorkDir::clear_job( $id );
			clearstatcache();
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
			self::assertSame( [ 'manifest.jsonl', 'seg-0.seg' ], array_values( array_diff( (array) scandir( $dehors ), [ '.', '..' ] ) ), 'dossier visé intact' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Départ d'une sauvegarde : dans un vrai `job-<id>`, un `manifest.jsonl` qui est un lien vers un
	 * fichier extérieur reste, sa cible aussi ; le vrai fichier du module à côté est retiré.
	 */
	public function test_nettoyage_d_un_depart_garde_un_fichier_qui_est_un_lien(): void {
		$id = str_repeat( '5', 32 );
		self::assertTrue( WorkDir::ensure() );
		$cible = $this->dossier . '/dehors-nettoyage.jsonl';
		file_put_contents( $cible, "à garder\n" );
		$job = WorkDir::private_dir() . '/job-' . $id;
		mkdir( $job );
		file_put_contents( $job . '/seg-0.part', 'partie' );
		$lien = $job . '/manifest.jsonl';
		if ( ! function_exists( 'symlink' ) || ! @symlink( $cible, $lien ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai mené en CI Linux.' );
		}
		try {
			WorkDir::clear_job( $id );
			clearstatcache();
			self::assertTrue( is_link( $lien ), 'le lien reste' );
			self::assertSame( "à garder\n", file_get_contents( $cible ), 'fichier visé intact' );
			self::assertSame( [ 'manifest.jsonl' ], array_values( array_diff( (array) scandir( $job ), [ '.', '..' ] ) ), 'seul le vrai fichier du module est retiré' );
			// Le départ qui suit le nettoyage ne reçoit pas ce dossier : le journal serait écrit dans la cible.
			self::assertNull( WorkDir::job( $id ), 'un lien du module reste dans le dossier : départ refusé' );
			self::assertSame( "à garder\n", file_get_contents( $cible ), 'rien écrit dans la cible' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Un lien symbolique au chemin `job-<id>` n'est jamais pris pour le dossier de la sauvegarde :
	 * job() rend null (refus « dossier de travail indisponible ») au lieu d'écrire à travers le lien.
	 */
	public function test_le_dossier_d_une_sauvegarde_n_est_jamais_un_lien(): void {
		$id = str_repeat( '6', 32 );
		self::assertTrue( WorkDir::ensure() );
		$dehors = $this->dossier . '/dehors-job';
		mkdir( $dehors );
		$lien = WorkDir::private_dir() . '/job-' . $id;
		if ( ! function_exists( 'symlink' ) || ! @symlink( $dehors, $lien ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai mené en CI Linux.' );
		}
		try {
			self::assertNull( WorkDir::job( $id ) );
			self::assertSame( [], array_values( array_diff( (array) scandir( $dehors ), [ '.', '..' ] ) ), 'rien écrit à travers le lien' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Un vrai dossier `job-<id>` avec de vrais fichiers du module (reprise d'une sauvegarde) reste
	 * accepté par job(), comme un dossier neuf.
	 */
	public function test_le_dossier_d_une_sauvegarde_avec_de_vrais_fichiers_est_accepte(): void {
		$id = str_repeat( '7', 32 );
		self::assertTrue( WorkDir::ensure() );
		$job = WorkDir::private_dir() . '/job-' . $id;
		mkdir( $job );
		file_put_contents( $job . '/manifest.jsonl', "{\"t\":\"head\"}\n" );
		file_put_contents( $job . '/seg-0.part', 'partie' );

		self::assertSame( $job, WorkDir::job( $id ) );
		self::assertSame( WorkDir::private_dir() . '/job-' . str_repeat( '8', 32 ), WorkDir::job( str_repeat( '8', 32 ) ), 'dossier neuf' );
	}

	// ── Dossier privé qui est un lien : jamais suivi ────────────────────────────

	/**
	 * Un ancien dossier privé (sels changés) qui est un lien vers un dossier extérieur rempli de
	 * fichiers aux noms du module : le nettoyage de ensure(), à chaque tranche, ne le suit pas. Rien
	 * n'est retiré dehors (protections, dossier d'une sauvegarde, état local), aucun avertissement
	 * (rmdir() sur le lien), le lien reste.
	 */
	public function test_un_ancien_dossier_prive_qui_est_un_lien_n_est_jamais_suivi(): void {
		$dehors = $this->dossier_aux_noms_du_module( 'dehors-ancien' );
		$lien   = $this->dossier . '/' . str_repeat( 'f', 32 );
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			self::assertTrue( WorkDir::ensure(), 'le dossier privé actuel reste utilisable' );
			self::assertTrue( WorkDir::ensure(), 'tranche suivante : rien de plus' );
			self::assert_intact( $dehors );
			clearstatcache( true );
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Désinstallation : le dossier privé actuel est un lien vers un dossier extérieur. remove_all()
	 * ne le suit pas : rien n'est retiré dehors, aucun avertissement, le lien reste.
	 */
	public function test_la_desinstallation_ne_suit_jamais_un_dossier_prive_qui_est_un_lien(): void {
		$dehors = $this->dossier_aux_noms_du_module( 'dehors-actuel' );
		$lien   = WorkDir::private_dir();
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			WorkDir::remove_all();
			self::assert_intact( $dehors );
			clearstatcache( true );
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Le dossier privé est un lien vers un dossier extérieur vide : ensure() et job() refusent, et
	 * rien n'est écrit dehors (ni protections, ni `baseline/`, ni dossier de sauvegarde).
	 */
	public function test_un_dossier_prive_qui_est_un_lien_est_refuse_sans_rien_ecrire_dehors(): void {
		$dehors = $this->dossier . '/dehors-vide';
		mkdir( $dehors, 0777, true );
		$lien = WorkDir::private_dir();
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			self::assertFalse( WorkDir::ensure() );
			self::assertNull( WorkDir::job( str_repeat( '9', 32 ) ) );
			self::assertSame( [], self::contenu( $dehors ), 'rien écrit à travers le lien' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Départ d'une sauvegarde (clear_job) et retrait d'un départ refusé (discard_new_job) quand le
	 * dossier privé est un lien : le dossier `job-<id>` vu à travers lui n'est pas touché.
	 */
	public function test_nettoyage_et_retrait_ne_suivent_jamais_un_dossier_prive_qui_est_un_lien(): void {
		$dehors = $this->dossier_aux_noms_du_module( 'dehors-depart' );
		$lien   = WorkDir::private_dir();
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			WorkDir::clear_job( str_repeat( 'e', 32 ) );
			self::assertFalse( WorkDir::discard_new_job( str_repeat( 'e', 32 ) ) );
			self::assert_intact( $dehors );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	// ── `baseline/` qui est un lien : jamais suivi ──────────────────────────────

	/**
	 * Dossier extérieur qui porte un état local aux noms du module (`current.json`, `<32 hex>.jsonl`).
	 */
	private function etat_local_dehors( string $nom ): string {
		$dehors = $this->dossier . '/' . $nom;
		mkdir( $dehors, 0777, true );
		file_put_contents( $dehors . '/current.json', '{}' );
		file_put_contents( $dehors . '/' . str_repeat( 'e', 32 ) . '.jsonl', 'état local' );
		return $dehors;
	}

	/** L'état local extérieur de etat_local_dehors() n'a rien perdu. */
	private static function assert_etat_local_intact( string $dehors ): void {
		clearstatcache( true );
		self::assertSame( [ 'current.json', str_repeat( 'e', 32 ) . '.jsonl' ], self::contenu( $dehors ), 'état local extérieur gardé' );
		self::assertSame( 'état local', file_get_contents( $dehors . '/' . str_repeat( 'e', 32 ) . '.jsonl' ) );
	}

	/**
	 * Un ancien dossier privé (sels changés), vrai dossier, dont `baseline/` est un lien vers un
	 * dossier extérieur : le nettoyage de ensure() vide l'ancien dossier sans suivre le lien. Rien
	 * n'est retiré dehors, aucun avertissement (rmdir() sur le lien), le lien reste.
	 */
	public function test_le_nettoyage_ne_suit_jamais_un_etat_local_qui_est_un_lien(): void {
		$dehors = $this->etat_local_dehors( 'dehors-etat-ancien' );
		$ancien = $this->dossier . '/' . str_repeat( 'f', 32 );
		mkdir( $ancien );
		$lien = $ancien . '/baseline';
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			self::assertTrue( WorkDir::ensure() );
			self::assertTrue( WorkDir::ensure(), 'tranche suivante : rien de plus' );
			self::assert_etat_local_intact( $dehors );
			clearstatcache( true );
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/** Désinstallation : `baseline/` du dossier privé actuel est un lien ; rien n'est retiré dehors. */
	public function test_la_desinstallation_ne_suit_jamais_un_etat_local_qui_est_un_lien(): void {
		self::assertTrue( WorkDir::ensure() );
		$dehors = $this->etat_local_dehors( 'dehors-etat-actuel' );
		$lien   = WorkDir::baseline_dir();
		rmdir( $lien );
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			WorkDir::remove_all();
			self::assert_etat_local_intact( $dehors );
			clearstatcache( true );
			self::assertTrue( is_link( $lien ) || file_exists( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * `baseline/` du dossier privé actuel est un lien vers un dossier extérieur vide : ensure() et
	 * job() refusent (l'état local serait lu, promu et retiré dans la cible), rien n'est écrit dehors.
	 */
	public function test_un_etat_local_qui_est_un_lien_est_refuse_sans_rien_ecrire_dehors(): void {
		self::assertTrue( WorkDir::ensure() );
		$dehors = $this->dossier . '/dehors-etat-vide';
		mkdir( $dehors );
		$lien = WorkDir::baseline_dir();
		rmdir( $lien );
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
		try {
			self::assertFalse( WorkDir::ensure() );
			self::assertNull( WorkDir::job( str_repeat( '9', 32 ) ) );
			self::assertSame( [], self::contenu( $dehors ), 'rien écrit à travers le lien' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	// ── Protection qui est un lien : jamais écrite ─────────────────────────────

	/**
	 * Un fichier de protection (`.htaccess`) du dossier de travail ou du dossier privé est un lien
	 * cassé vers un fichier extérieur (lien symbolique, ou jonction Windows vers rien) : ensure() ne
	 * l'écrit jamais à travers lui (la cible n'est pas créée), il refuse ; job() aussi. Le lien reste.
	 */
	public function test_une_protection_qui_est_un_lien_casse_n_est_jamais_ecrite(): void {
		self::assertTrue( WorkDir::ensure() );
		mkdir( $this->dossier . '/dehors' );
		foreach ( [ 'dossier de travail' => WorkDir::path(), 'dossier privé' => WorkDir::private_dir() ] as $cas => $dossier ) {
			$garde = $dossier . '/.htaccess';
			$cible = $this->dossier . '/dehors/' . md5( $cas ) . '.txt';
			unlink( $garde );
			try {
				self::lien_vers_un_fichier_ou_ignorer( $cible, $garde );
				self::assertFalse( WorkDir::ensure(), $cas );
				self::assertNull( WorkDir::job( str_repeat( '9', 32 ) ), $cas );
				clearstatcache( true );
				self::assertFileDoesNotExist( $cible, $cas . ' : rien écrit à travers le lien' );
				self::assertTrue( self::est_un_lien( $garde ), $cas . ' : le lien reste' );
			} finally {
				self::retirer_lien( $garde );
				file_put_contents( $garde, 'protection remise' );
			}
		}
	}

	// ── Jonction vers un fichier, jonction cassée, nom en majuscules, octet nul ──

	/**
	 * Fichier de réglages hors du dossier privé, visé par les liens des essais suivants.
	 */
	private function reglages_dehors(): string {
		mkdir( $this->dossier . '/site', 0777, true );
		$cible = $this->dossier . '/site/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		return $cible;
	}

	/**
	 * Un fichier du module (`seg-5.part`) est un lien vers wp-config.php : sous Windows, une jonction
	 * vers un fichier (mklink /J, sans droit), que PHP ne voit ni comme un lien, ni comme un dossier,
	 * ni comme un fichier ; ailleurs, un lien symbolique. job() refuse, blocked_by_link() le dit, la
	 * partie n'est pas « dans le dossier privé », et wp-config.php reste intact.
	 */
	public function test_un_fichier_du_module_qui_est_une_jonction_vers_un_fichier_est_refuse(): void {
		$id    = str_repeat( 'a', 32 );
		$job   = (string) WorkDir::job( $id );
		$cible = $this->reglages_dehors();
		$lien  = $job . '/seg-5.part';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			self::assertNull( WorkDir::job( $id ), 'tranche refusée' );
			self::assertTrue( WorkDir::blocked_by_link( $id ), 'cause dite : un lien' );
			self::assertFalse( WorkDir::within_private( $lien ) );
			self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), 'cible intacte' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Un journal du module (`manifest.jsonl`) est un lien cassé : jonction vers rien sous Windows,
	 * lien symbolique ailleurs. job() refuse, et la cible n'est jamais créée.
	 */
	public function test_un_fichier_du_module_qui_est_un_lien_casse_est_refuse(): void {
		$id     = str_repeat( 'b', 32 );
		$job    = (string) WorkDir::job( $id );
		$absent = $this->dossier . '/absent.jsonl';
		$lien   = $job . '/manifest.jsonl';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $absent, $lien );
			self::assertNull( WorkDir::job( $id ) );
			self::assertTrue( WorkDir::blocked_by_link( $id ) );
			self::assertFalse( WorkDir::within_private( $lien ) );
			clearstatcache( true );
			self::assertFileDoesNotExist( $absent, 'cible jamais créée' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Un lien au nom d'un fichier du module écrit en majuscules (`SEG-5.PART`) : sous Windows, c'est
	 * le même fichier que `seg-5.part`, que le module écrirait à travers lui. Refusé comme les autres.
	 */
	public function test_un_lien_au_nom_d_un_fichier_du_module_en_majuscules_est_refuse(): void {
		$id    = str_repeat( 'c', 32 );
		$job   = (string) WorkDir::job( $id );
		$cible = $this->reglages_dehors();
		$lien  = $job . '/SEG-5.PART';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			self::assertNull( WorkDir::job( $id ) );
			self::assertTrue( WorkDir::blocked_by_link( $id ) );
			self::assertSame( "<?php // réglages\n", file_get_contents( $cible ) );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/** Un chemin qui porte un octet nul n'est jamais pris pour un chemin sans lien. */
	public function test_un_chemin_avec_un_octet_nul_est_refuse(): void {
		self::assertTrue( WorkDir::ensure() );
		self::assertTrue( WorkDir::link_between( WorkDir::path(), WorkDir::private_dir() . "/job-x\0y" ) );
		self::assertTrue( WorkDir::link_between( WorkDir::path() . "\0", WorkDir::private_dir() ) );
		self::assertFalse( WorkDir::within_private( WorkDir::private_dir() . "/seg-0\0.part" ) );
	}

	// ── open_private() : chaque fichier du module ouvert sans jamais suivre de lien ──

	/**
	 * Modes de open_private() sur un vrai fichier du module : `ab` crée puis ajoute, `xb` refuse un
	 * fichier déjà là, `cb` vide après contrôle, `rb` et `r+b` refusent un fichier absent ; un
	 * chemin hors du dossier privé n'est jamais ouvert ; un mode inconnu est une erreur de
	 * programmation.
	 */
	public function test_open_private_ouvre_un_fichier_du_module_selon_son_mode(): void {
		$job     = (string) WorkDir::job( str_repeat( 'f', 32 ) );
		$fichier = $job . '/manifest.jsonl';
		foreach ( [ 'un', 'deux' ] as $mot ) {
			$h = WorkDir::open_private( $fichier, 'ab' );
			self::assertIsResource( $h );
			fwrite( $h, $mot );
			fclose( $h );
		}
		self::assertSame( 'undeux', file_get_contents( $fichier ) );
		self::assertNull( WorkDir::open_private( $fichier, 'xb' ), 'fichier neuf : refusé s\'il existe' );
		$h = WorkDir::open_private( $fichier, 'cb' );
		self::assertIsResource( $h );
		fwrite( $h, 'trois' );
		fclose( $h );
		self::assertSame( 'trois', WorkDir::read_private( $fichier ) );
		self::assertNull( WorkDir::open_private( $job . '/index.bin', 'rb' ), 'absent : rien à lire' );
		self::assertNull( WorkDir::open_private( $job . '/index.bin', 'r+b' ) );
		self::assertNull( WorkDir::open_private( $this->dossier . '/dehors.jsonl', 'ab' ), 'hors du dossier privé' );
		self::assertFileDoesNotExist( $this->dossier . '/dehors.jsonl' );
		$this->expectException( \InvalidArgumentException::class );
		WorkDir::open_private( $fichier, 'wb' );
	}

	/**
	 * Un lien physique (fichier à deux noms : wp-config.php et `seg-3.part`) dans le dossier de la
	 * sauvegarde : job() refuse et le dit, et aucun mode de open_private() ne l'ouvre. Le contenu
	 * de wp-config.php n'est jamais touché.
	 */
	public function test_un_lien_physique_n_est_jamais_ouvert(): void {
		$id    = str_repeat( '0', 32 );
		$job   = (string) WorkDir::job( $id );
		$cible = $this->reglages_dehors();
		$lien  = $job . '/seg-3.part';
		if ( ! @link( $cible, $lien ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::markTestSkipped( 'Le système refuse de créer un lien physique.' );
		}
		self::assertNull( WorkDir::job( $id ) );
		self::assertTrue( WorkDir::blocked_by_link( $id ) );
		foreach ( [ 'xb', 'ab', 'cb', 'r+b', 'rb' ] as $mode ) {
			self::assertNull( WorkDir::open_private( $lien, $mode ), $mode );
		}
		self::assertSame( "<?php // réglages\n", file_get_contents( $cible ) );
	}

	// ── Dossier de travail (g2rd-backups) qui est lui-même un lien ─────────────

	/** Le dossier de travail devient `$chemin` (filtre g2rd_connector_backup_dir). */
	private function dossier_de_travail( string $chemin ): void {
		Functions\when( 'apply_filters' )->alias( static fn ( string $nom, $valeur ) => 'g2rd_connector_backup_dir' === $nom ? $chemin : $valeur );
	}

	/**
	 * `wp-content/g2rd-backups` est un lien vers `wp-content/uploads` : ensure() refuse avant d'y
	 * créer quoi que ce soit (sinon ses protections « Require all denied » mettraient toutes les
	 * images du site en 403) ; job() refuse et blocked_by_link() le dit ; la désinstallation n'y
	 * retire rien. Le lien reste.
	 */
	public function test_un_dossier_de_travail_qui_est_un_lien_est_refuse_sans_rien_ecrire_dans_sa_cible(): void {
		$contenu = $this->dossier . '/wp-content';
		mkdir( $contenu . '/uploads', 0777, true );
		file_put_contents( $contenu . '/uploads/photo.jpg', 'image' );
		$lien = $contenu . '/g2rd-backups';
		$this->dossier_de_travail( $lien );
		try {
			self::lien_vers_un_dossier_ou_ignorer( $contenu . '/uploads', $lien );
			self::assertFalse( WorkDir::ensure() );
			self::assertNull( WorkDir::job( str_repeat( 'd', 32 ) ) );
			self::assertTrue( WorkDir::blocked_by_link( str_repeat( 'd', 32 ) ) );
			WorkDir::remove_all();
			self::assertSame( [ 'photo.jpg' ], self::contenu( $contenu . '/uploads' ), 'rien écrit ni retiré dans les médias' );
			self::assertTrue( self::est_un_lien( $lien ), 'le lien reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Seul le dernier élément du dossier de travail est examiné : un wp-content placé derrière un
	 * lien par l'hébergeur, ou un déploiement `current -> releases/42`, reste accepté (le dossier
	 * de travail lui-même est un vrai dossier, sous le lien).
	 */
	public function test_un_lien_au_dessus_du_dossier_de_travail_reste_accepte(): void {
		foreach (
			[
				'wp-content relié par l\'hébergeur' => [ 'stockage/wp-content', 'site-wp-content', 'site-wp-content/g2rd-backups' ],
				'déploiement current -> releases'   => [ 'releases/42', 'current', 'current/wp-content/g2rd-backups' ],
			] as $cas => [ $reel, $nom_du_lien, $travail ]
		) {
			mkdir( $this->dossier . '/' . $reel, 0777, true );
			$lien = $this->dossier . '/' . $nom_du_lien;
			$this->dossier_de_travail( $this->dossier . '/' . $travail );
			try {
				self::lien_vers_un_dossier_ou_ignorer( $this->dossier . '/' . $reel, $lien );
				self::assertTrue( WorkDir::ensure(), $cas );
				$job = WorkDir::job( str_repeat( 'e', 32 ) );
				self::assertIsString( $job, $cas );
				self::assertFalse( WorkDir::blocked_by_link( str_repeat( 'e', 32 ) ), $cas );
				self::assertTrue( WorkDir::within_private( $job . '/manifest.jsonl' ), $cas );
				WorkDir::remove_all();
				clearstatcache( true );
				self::assertDirectoryDoesNotExist( $this->dossier . '/' . $travail, $cas . ' : désinstallation faite' );
			} finally {
				self::retirer_lien( $lien );
			}
		}
	}

	// ── Outils ────────────────────────────────────────────────────────────────

	/**
	 * Dossier hors du dossier privé, rempli de fichiers aux noms de ceux du module : protections,
	 * dossier d'une sauvegarde (`job-<e…e>`), état local (`baseline/`).
	 */
	private function dossier_aux_noms_du_module( string $nom ): string {
		$dehors = $this->dossier . '/' . $nom;
		$job    = $dehors . '/job-' . str_repeat( 'e', 32 );
		mkdir( $job, 0777, true );
		mkdir( $dehors . '/baseline' );
		foreach ( [ 'index.php', '.htaccess', 'web.config' ] as $garde ) {
			file_put_contents( $dehors . '/' . $garde, 'à garder' );
		}
		file_put_contents( $job . '/manifest.jsonl', "{\"t\":\"head\"}\n" );
		file_put_contents( $job . '/seg-0.seg', 'segment' );
		file_put_contents( $dehors . '/baseline/current.json', '{}' );
		file_put_contents( $dehors . '/baseline/' . str_repeat( 'e', 32 ) . '.jsonl', 'état local' );
		return $dehors;
	}

	/** Le dossier extérieur de dossier_aux_noms_du_module() n'a rien perdu. */
	private static function assert_intact( string $dehors ): void {
		clearstatcache( true );
		self::assertSame( [ '.htaccess', 'baseline', 'index.php', 'job-' . str_repeat( 'e', 32 ), 'web.config' ], self::contenu( $dehors ), 'protections et dossiers gardés' );
		self::assertSame( [ 'manifest.jsonl', 'seg-0.seg' ], self::contenu( $dehors . '/job-' . str_repeat( 'e', 32 ) ), 'fichiers de la sauvegarde gardés' );
		self::assertSame( [ 'current.json', str_repeat( 'e', 32 ) . '.jsonl' ], self::contenu( $dehors . '/baseline' ), 'état local gardé' );
		self::assertSame( 'à garder', file_get_contents( $dehors . '/.htaccess' ) );
		self::assertSame( 'état local', file_get_contents( $dehors . '/baseline/' . str_repeat( 'e', 32 ) . '.jsonl' ) );
	}
}
