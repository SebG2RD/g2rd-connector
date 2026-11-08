<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Files;

use G2RD\Connector\Backup\Files\FileWalker;
use PHPUnit\Framework\TestCase;

final class FileWalkerTest extends TestCase {

	private string $racine;

	/** @var list<string> Dossiers dont les droits ont été retirés : rendus avant le nettoyage. */
	private array $droits_retires = [];

	protected function setUp(): void {
		parent::setUp();
		$this->racine = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-walker-' . bin2hex( random_bytes( 4 ) );
		foreach ( [ 'a/b', 'a.txt.d', 'wp-content/cache/x', 'wp-content/uploads' ] as $dossier ) {
			mkdir( $this->racine . '/' . $dossier, 0777, true );
		}
		foreach ( [ 'a/b/c.php' => 'c', 'a/z.txt' => 'zz', 'a.txt' => 'a', 'B.txt' => 'B', 'wp-content/cache/x/y' => 'y', 'wp-content/uploads/m.jpg' => 'mmm' ] as $fichier => $contenu ) {
			file_put_contents( $this->racine . '/' . $fichier, $contenu );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->droits_retires as $dossier ) {
			chmod( $dossier, 0755 );
		}
		$iterateur = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->racine, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterateur as $entree ) {
			$entree->isDir() && ! $entree->isLink() ? rmdir( $entree->getPathname() ) : unlink( $entree->getPathname() );
		}
		rmdir( $this->racine );
		parent::tearDown();
	}

	public function test_ordre_octet_par_octet_dossier_avant_son_contenu(): void {
		$chemins = $this->chemins( new FileWalker( $this->racine ) );

		self::assertSame(
			[ 'B.txt', 'a', 'a/b', 'a/b/c.php', 'a/z.txt', 'a.txt', 'a.txt.d', 'wp-content', 'wp-content/cache', 'wp-content/cache/x', 'wp-content/cache/x/y', 'wp-content/uploads', 'wp-content/uploads/m.jpg' ],
			$chemins
		);
	}

	public function test_exclusions_ni_rendues_ni_parcourues(): void {
		$chemins = $this->chemins( new FileWalker( $this->racine, [ 'wp-content/cache' ] ) );

		self::assertNotContains( 'wp-content/cache', $chemins );
		self::assertNotContains( 'wp-content/cache/x/y', $chemins );
		self::assertContains( 'wp-content/uploads/m.jpg', $chemins );
	}

	public function test_reprise_apres_chaque_entree_rend_exactement_la_suite(): void {
		$parcours = new FileWalker( $this->racine );
		$tous     = $this->chemins( $parcours );

		foreach ( $tous as $i => $curseur ) {
			self::assertSame( array_slice( $tous, $i + 1 ), $this->chemins( $parcours, $curseur ), 'après ' . $curseur );
		}
	}

	public function test_reprise_apres_une_entree_disparue(): void {
		$parcours = new FileWalker( $this->racine );

		self::assertSame( [ 'a/z.txt', 'a.txt', 'a.txt.d' ], array_slice( $this->chemins( $parcours, 'a/b/zzz-disparu' ), 0, 3 ) );
	}

	public function test_une_entree_porte_type_taille_date_et_droits(): void {
		$entrees = iterator_to_array( ( new FileWalker( $this->racine ) )->walk(), false );
		$z       = array_values( array_filter( $entrees, static fn ( array $e ): bool => 'a/z.txt' === $e['path'] ) )[0];

		self::assertSame( 'file', $z['type'] );
		self::assertSame( 2, $z['size'] );
		self::assertGreaterThan( 0, $z['mtime'] );
		self::assertIsInt( $z['mode'] );
		self::assertNull( $z['target'] );
		self::assertSame( 'dir', ( new FileWalker( $this->racine ) )->entry( 'a' )['type'] ?? null );
		self::assertNull( ( new FileWalker( $this->racine ) )->entry( 'absent' ) );
	}

	public function test_un_lien_n_est_jamais_suivi(): void {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->racine . '/a', $this->racine . '/lien' ) ) {
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai refait sur le banc (tâche 37, E1).' );
		}
		$entrees = iterator_to_array( ( new FileWalker( $this->racine ) )->walk(), false );
		$lien    = array_values( array_filter( $entrees, static fn ( array $e ): bool => 'lien' === $e['path'] ) )[0];

		self::assertSame( 'link', $lien['type'] );
		self::assertSame( 0, $lien['size'] );
		self::assertNotEmpty( $lien['target'] );
		self::assertSame( [], array_filter( $entrees, static fn ( array $e ): bool => str_starts_with( $e['path'], 'lien/' ) ), 'rien sous le lien' );
	}

	public function test_un_parcours_lisible_rend_tout_liste_et_rien_de_refuse(): void {
		$parcours = new FileWalker( $this->racine );
		$entrees  = iterator_to_array( $parcours->walk(), false );

		self::assertSame( [], array_filter( $entrees, static fn ( array $e ): bool => true !== $e['listed'] ), 'aucune entrée marquée non listée' );
		self::assertSame( [], $parcours->unlisted() );
		self::assertFalse( $parcours->within_unlisted( 'a/b/c.php' ) );
		self::assertTrue( ( new FileWalker( $this->racine ) )->entry( 'a' )['listed'] ?? null );
	}

	public function test_un_dossier_dont_la_liste_est_refusee_est_rendu_non_liste_jamais_tu(): void {
		$this->retirer_droits( 'a', 0000 );
		$parcours = new FileWalker( $this->racine );
		$entrees  = iterator_to_array( $parcours->walk(), false );

		self::assertSame( $this->arbre_sans_le_contenu_de_a(), array_column( $entrees, 'path' ) );
		$a = $this->entree( $entrees, 'a' );
		self::assertSame( [ 'dir', false ], [ $a['type'], $a['listed'] ], 'rendu comme dossier, marqué non listé' );
		self::assertSame( [ 'a' ], $parcours->unlisted() );
		self::assertTrue( $parcours->within_unlisted( 'a' ) );
		self::assertTrue( $parcours->within_unlisted( 'a/b/c.php' ), 'jamais « deleted » sous un dossier refusé' );
		self::assertFalse( $parcours->within_unlisted( 'a.txt' ) );
		self::assertFalse( $parcours->within_unlisted( 'a.txt.d/x' ) );

		chmod( $this->racine . '/a', 0755 );
		iterator_to_array( $parcours->walk(), false );
		self::assertSame( [], $parcours->unlisted(), 'remis à zéro à chaque parcours' );
	}

	public function test_un_dossier_liste_mais_non_traversable_est_rendu_non_liste_jamais_vide(): void {
		$this->retirer_droits( 'a', 0644 );
		$parcours = new FileWalker( $this->racine );
		$entrees  = iterator_to_array( $parcours->walk(), false );

		self::assertSame( $this->arbre_sans_le_contenu_de_a(), array_column( $entrees, 'path' ) );
		self::assertFalse( $this->entree( $entrees, 'a' )['listed'] );
		self::assertSame( [ 'a' ], $parcours->unlisted() );
		self::assertTrue( $parcours->within_unlisted( 'a/z.txt' ) );
	}

	public function test_la_reprise_dans_un_dossier_devenu_illisible_le_signale(): void {
		$this->retirer_droits( 'a', 0000 );
		$parcours = new FileWalker( $this->racine );

		self::assertSame( array_slice( $this->arbre_sans_le_contenu_de_a(), 2 ), $this->chemins( $parcours, 'a/b/c.php' ), 'son entrée est déjà passée : rien de rendu pour lui' );
		self::assertSame( [ 'a' ], $parcours->unlisted() );
		self::assertTrue( $parcours->within_unlisted( 'a/z.txt' ), 'la suite du dossier n\'est jamais dite supprimée' );
	}

	public function test_un_dossier_qui_perd_ses_droits_pendant_le_parcours_n_est_jamais_tu(): void {
		foreach ( [ 0000, 0644 ] as $mode ) {
			$libelle  = sprintf( 'droits de a passés en %04o pendant une pause du parcours', $mode );
			$parcours = new FileWalker( $this->racine );
			$chemins  = [];
			foreach ( $parcours->walk() as $entree ) {
				$chemins[] = $entree['path'];
				if ( 'a/b/c.php' === $entree['path'] ) {
					// Le générateur est en pause, comme pendant l'archivage d'un gros fichier de « a ».
					$this->retirer_droits( 'a', $mode );
				}
			}
			chmod( $this->racine . '/a', 0755 );

			self::assertSame( array_merge( [ 'B.txt', 'a', 'a/b', 'a/b/c.php' ], array_slice( $this->arbre_sans_le_contenu_de_a(), 2 ) ), $chemins, $libelle );
			self::assertNotContains( 'a/z.txt', $chemins, $libelle );
			self::assertSame( [ 'a' ], $parcours->unlisted(), $libelle );
			self::assertTrue( $parcours->within_unlisted( 'a/z.txt' ), $libelle . ' : la suite du dossier n\'est jamais dite supprimée' );
		}
	}

	public function test_un_fichier_supprime_pendant_le_parcours_n_est_pas_un_dossier_illisible(): void {
		// Aucun droit retiré : l'essai tourne aussi sous Windows. « a » reste lisible, seul « a/z.txt » disparaît.
		$parcours = new FileWalker( $this->racine );
		$chemins  = [];
		foreach ( $parcours->walk() as $entree ) {
			$chemins[] = $entree['path'];
			if ( 'a/b/c.php' === $entree['path'] ) {
				unlink( $this->racine . '/a/z.txt' );
			}
		}

		self::assertSame(
			[ 'B.txt', 'a', 'a/b', 'a/b/c.php', 'a.txt', 'a.txt.d', 'wp-content', 'wp-content/cache', 'wp-content/cache/x', 'wp-content/cache/x/y', 'wp-content/uploads', 'wp-content/uploads/m.jpg' ],
			$chemins
		);
		self::assertSame( [], $parcours->unlisted(), 'un dossier encore lisible n\'est jamais inscrit comme illisible' );
		self::assertFalse( $parcours->within_unlisted( 'a/z.txt' ), 'la vraie disparition reste une suppression' );
	}

	public function test_des_droits_retires_a_un_parent_pendant_le_parcours_designent_ce_parent(): void {
		// « p » ne contient que « q » : seul lost_access() depuis « p/q » peut désigner « p ».
		mkdir( $this->racine . '/p/q', 0777, true );
		file_put_contents( $this->racine . '/p/q/1.txt', '1' );
		file_put_contents( $this->racine . '/p/q/2.txt', '2' );
		$parcours = new FileWalker( $this->racine );
		$chemins  = [];
		foreach ( $parcours->walk() as $entree ) {
			$chemins[] = $entree['path'];
			if ( 'p/q/1.txt' === $entree['path'] ) {
				$this->retirer_droits( 'p', 0000 );
			}
		}
		chmod( $this->racine . '/p', 0755 );

		self::assertNotContains( 'p/q/2.txt', $chemins );
		self::assertContains( 'wp-content/uploads/m.jpg', $chemins, 'le parcours continue après le dossier fautif' );
		self::assertSame( [ 'p' ], $parcours->unlisted(), 'le parent fautif, pas « p/q » qui n\'est plus relisible à travers lui' );
		self::assertTrue( $parcours->within_unlisted( 'p/q/2.txt' ) );
	}

	public function test_comparaison_de_l_ordre_du_parcours(): void {
		self::assertLessThan( 0, FileWalker::compare( 'a/b', 'a.txt' ) );
		self::assertLessThan( 0, FileWalker::compare( 'a', 'a/b' ) );
		self::assertGreaterThan( 0, FileWalker::compare( 'b', 'a/zzz' ) );
		self::assertSame( 0, FileWalker::compare( 'x/y', 'x/y' ) );
	}

	public function test_un_nom_non_utf8_sert_de_curseur_sans_doublon(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows refuse un nom de fichier qui n\'est pas de l\'UTF-8 : essai mené sur Linux (CI, banc).' );
		}
		// Dans « a » : à la racine, ce nom (octet 0xE9, au-delà de tout l'ASCII) serait la dernière
		// entrée et rien ne le suivrait ; ici le parcours continue après lui (a.txt, …, zz.txt).
		$nom = "a/\xe9t\xe9.txt";
		file_put_contents( $this->racine . '/' . $nom, 'x' );
		file_put_contents( $this->racine . '/zz.txt', 'y' );
		$walker = new FileWalker( $this->racine, [] );
		self::assertContains( $nom, array_column( iterator_to_array( $walker->walk(), false ), 'path' ), 'nom rendu octet pour octet par le parcours' );

		$suite = array_column( iterator_to_array( $walker->walk( (string) hex2bin( bin2hex( $nom ) ) ), false ), 'path' );

		self::assertNotContains( $nom, $suite, 'repris après lui, jamais lui de nouveau' );
		self::assertContains( 'zz.txt', $suite );
	}

	/**
	 * @return list<string>
	 */
	private function chemins( FileWalker $parcours, ?string $apres = null ): array {
		return array_map( static fn ( array $e ): string => $e['path'], iterator_to_array( $parcours->walk( $apres ), false ) );
	}

	/**
	 * @return list<string>
	 */
	private function arbre_sans_le_contenu_de_a(): array {
		return [ 'B.txt', 'a', 'a.txt', 'a.txt.d', 'wp-content', 'wp-content/cache', 'wp-content/cache/x', 'wp-content/cache/x/y', 'wp-content/uploads', 'wp-content/uploads/m.jpg' ];
	}

	/**
	 * @param list<array<string, mixed>> $entrees
	 * @return array<string, mixed>
	 */
	private function entree( array $entrees, string $chemin ): array {
		$trouvees = array_values( array_filter( $entrees, static fn ( array $e ): bool => $chemin === $e['path'] ) );
		self::assertCount( 1, $trouvees, 'une seule entrée ' . $chemin );

		return $trouvees[0];
	}

	/** Retire des droits POSIX à un dossier d'essai ; saute l'essai là où les droits n'ont pas d'effet. */
	private function retirer_droits( string $relatif, int $mode ): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Droits POSIX sans effet sous Windows (chmod n\'y retire ni la liste ni la traversée d\'un dossier) : essai mené en CI Linux et sur le banc (tâche 37, E1).' );
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Essai lancé en root, qui passe outre les droits des dossiers : à mener sous un utilisateur ordinaire (CI Linux, banc, tâche 37, E1).' );
		}
		$dossier                = $this->racine . '/' . $relatif;
		$this->droits_retires[] = $dossier;
		chmod( $dossier, $mode );
		clearstatcache();
		$sans_effet = 0 === ( $mode & 0444 ) ? false !== @scandir( $dossier ) : false !== @lstat( $dossier . '/z.txt' );
		if ( $sans_effet ) {
			self::markTestSkipped( 'Les droits retirés n\'ont pas d\'effet pour cet utilisateur (root sans l\'extension posix ?) : essai à mener sous un utilisateur ordinaire (CI Linux, banc, tâche 37, E1).' );
		}
	}
}
