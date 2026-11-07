<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Files;

use G2RD\Connector\Backup\Files\FileWalker;
use PHPUnit\Framework\TestCase;

final class FileWalkerTest extends TestCase {

	private string $racine;

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

	public function test_comparaison_de_l_ordre_du_parcours(): void {
		self::assertLessThan( 0, FileWalker::compare( 'a/b', 'a.txt' ) );
		self::assertLessThan( 0, FileWalker::compare( 'a', 'a/b' ) );
		self::assertGreaterThan( 0, FileWalker::compare( 'b', 'a/zzz' ) );
		self::assertSame( 0, FileWalker::compare( 'x/y', 'x/y' ) );
	}

	/**
	 * @return list<string>
	 */
	private function chemins( FileWalker $parcours, ?string $apres = null ): array {
		return array_map( static fn ( array $e ): string => $e['path'], iterator_to_array( $parcours->walk( $apres ), false ) );
	}
}
