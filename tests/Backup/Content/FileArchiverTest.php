<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use G2RD\Connector\Backup\Content\FileArchiver;
use G2RD\Connector\Backup\Payload\PayloadReader;
use G2RD\Connector\Backup\Payload\PayloadWriter;
use PHPUnit\Framework\TestCase;

/**
 * Archive d'une entrée du parcours (docs/backup-payload.md § 3 et § 5) : morceaux de 1 Mio repris au
 * décalage, empreinte d'un fichier lu en entier, dossier et lien, illisible compté sans rien écrire,
 * fichier changé pendant sa lecture (taille ou date différentes de celles du parcours) dit illisible.
 */
final class FileArchiverTest extends TestCase {

	/** Date de modification posée sur le fichier par entree(), puis relue comme le parcours. */
	private const MTIME = 1792843200;

	private string $racine;

	protected function setUp(): void {
		parent::setUp();
		$this->racine = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-archive-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->racine . '/d', 0777, true );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->racine . '/d/*' ) as $f ) {
			unlink( (string) $f );
		}
		rmdir( $this->racine . '/d' );
		rmdir( $this->racine );
		parent::tearDown();
	}

	/**
	 * @return array{0: PayloadWriter, 1: \Closure(): string}
	 */
	private static function ecrivain(): array {
		$flux = '';
		$e    = new PayloadWriter( static function ( string $o ) use ( &$flux ): void {
			$flux .= $o;
		} );
		return [ $e, static function () use ( &$flux, $e ): string {
			$e->end();
			return $flux;
		} ];
	}

	/** @return array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null} */
	private function entree( string $chemin, string $contenu ): array {
		file_put_contents( $this->racine . '/' . $chemin, $contenu );
		touch( $this->racine . '/' . $chemin, self::MTIME );
		return [ 'path' => $chemin, 'type' => 'file', 'size' => strlen( $contenu ), 'mtime' => $this->date_lue( $chemin ), 'mode' => 0644, 'target' => null ];
	}

	/**
	 * Date lue comme le parcours (`lstat()`, `FileWalker`), jamais déclarée en dur : dès qu'un test du
	 * processus a chargé Brain Monkey, l'enveloppe de flux de Patchwork rend la date + 1 à `lstat()`
	 * comme à `fstat()` ; les deux restent égales, comme en production.
	 */
	private function date_lue( string $chemin ): int {
		clearstatcache();
		$stat = lstat( $this->racine . '/' . $chemin );
		self::assertIsArray( $stat );
		return max( 0, (int) $stat['mtime'] );
	}

	/** Réécrit le fichier puis lui rend la date voulue : seule la règle visée par le test change. */
	private function reecrire( string $chemin, string $contenu, int $mtime ): void {
		file_put_contents( $this->racine . '/' . $chemin, $contenu );
		touch( $this->racine . '/' . $chemin, $mtime );
		clearstatcache();
	}

	public function test_un_fichier_lu_en_entier_a_son_empreinte(): void {
		[ $e, $fin ] = self::ecrivain();
		$r = ( new FileArchiver( $this->racine ) )->archive( $this->entree( 'd/a.txt', 'bonjour' ), 0, $e, static fn (): bool => false );

		self::assertTrue( $r['done'] );
		self::assertSame( hash( 'sha256', 'bonjour' ), $r['sha256'] );
		$lus = PayloadReader::parse( $fin() );
		self::assertSame( 'bonjour', $lus[0]['data'] );
		self::assertSame( 7, $lus[0]['total_size'] );
	}

	public function test_un_gros_fichier_coupe_en_morceaux_et_repris_au_decalage(): void {
		$contenu = random_bytes( FileArchiver::CHUNK_BYTES * 2 + 10 );
		$entree  = $this->entree( 'd/gros.bin', $contenu );
		$arch    = new FileArchiver( $this->racine );

		[ $e1, $fin1 ] = self::ecrivain();
		$premier       = $arch->archive( $entree, 0, $e1, static fn (): bool => true );
		[ $e2, $fin2 ] = self::ecrivain();
		$suite         = $arch->archive( $entree, $premier['offset'], $e2, static fn (): bool => false );

		self::assertFalse( $premier['done'] );
		self::assertSame( FileArchiver::CHUNK_BYTES, $premier['offset'], 'un morceau au moins, puis arrêt' );
		self::assertTrue( $suite['done'] );
		self::assertNull( $suite['sha256'], 'fichier réparti sur deux segments : pas d\'empreinte' );
		$morceaux = array_merge( PayloadReader::parse( $fin1() ), PayloadReader::parse( $fin2() ) );
		self::assertSame( $contenu, implode( '', array_column( $morceaux, 'data' ) ) );
		self::assertSame( [ 0, FileArchiver::CHUNK_BYTES, 2 * FileArchiver::CHUNK_BYTES ], array_column( $morceaux, 'offset' ) );
	}

	public function test_fichier_vide_dossier_et_lien(): void {
		[ $e, $fin ] = self::ecrivain();
		$a           = new FileArchiver( $this->racine );
		$a->archive( $this->entree( 'd/vide', '' ), 0, $e, static fn (): bool => false );
		$a->archive( [ 'path' => 'd', 'type' => 'dir', 'size' => 0, 'mtime' => 1, 'mode' => 0755, 'target' => null ], 0, $e, static fn (): bool => false );
		$a->archive( [ 'path' => 'd/l', 'type' => 'link', 'size' => 0, 'mtime' => 1, 'mode' => 0777, 'target' => '../ailleurs' ], 0, $e, static fn (): bool => false );

		self::assertSame( [ 'file', 'dir', 'link' ], array_column( PayloadReader::parse( $fin() ), 'type' ) );
	}

	public function test_fichier_illisible_compte_et_jamais_bloquant(): void {
		[ $e ] = self::ecrivain();
		$r     = ( new FileArchiver( $this->racine ) )->archive( [ 'path' => 'd/absent', 'type' => 'file', 'size' => 3, 'mtime' => 1, 'mode' => 0644, 'target' => null ], 0, $e, static fn (): bool => false );

		self::assertTrue( $r['done'] );
		self::assertTrue( $r['unreadable'] );
		self::assertSame( 4, $e->written(), 'rien d\'écrit après la magie' );
	}

	/**
	 * Fichier du parcours remplacé par un lien (ou un de ses dossiers) avant sa lecture : jamais suivi.
	 * Les deux cas sont essayés sur le banc Linux (liens indisponibles sans droit sous Windows).
	 */
	public function test_un_fichier_remplace_par_un_lien_n_est_jamais_lu(): void {
		$dehors = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-hors-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dehors );
		file_put_contents( $dehors . '/secret.txt', 'hors de la racine' );
		$entree = $this->entree( 'd/f.txt', 'hors de la racine' );
		unlink( $this->racine . '/d/f.txt' );
		try {
			if ( ! function_exists( 'symlink' ) || ! @symlink( $dehors . '/secret.txt', $this->racine . '/d/f.txt' ) ) {
				self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai refait sur le banc Linux.' );
			}
			[ $e ] = self::ecrivain();
			$r     = ( new FileArchiver( $this->racine ) )->archive( [ 'size' => 17, 'mtime' => (int) ( lstat( $dehors . '/secret.txt' )['mtime'] ?? 0 ) ] + $entree, 0, $e, static fn (): bool => false );

			self::assertTrue( $r['unreadable'], 'fichier devenu lien : illisible' );
			self::assertNull( $r['sha256'] );
			self::assertSame( 4, $e->written(), 'pas un octet du fichier visé' );
			unlink( $this->racine . '/d/f.txt' );

			// Un dossier remplacé par un lien : le fichier est lu par un chemin qui en traverse un.
			if ( ! @symlink( $dehors, $this->racine . '/d/dossier' ) ) {
				self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste.' );
			}
			[ $e ] = self::ecrivain();
			$r     = ( new FileArchiver( $this->racine ) )->archive( [ 'path' => 'd/dossier/secret.txt' ] + $entree, 0, $e, static fn (): bool => false );

			self::assertTrue( $r['unreadable'], 'dossier devenu lien : illisible' );
			self::assertSame( 4, $e->written() );
		} finally {
			foreach ( [ $this->racine . '/d/f.txt', $this->racine . '/d/dossier' ] as $lien ) {
				if ( is_link( $lien ) ) {
					unlink( $lien );
				}
			}
			unlink( $dehors . '/secret.txt' );
			rmdir( $dehors );
		}
	}

	/**
	 * Revue du 08/11/2026 : le cache des chemins résolus de PHP (realpath_cache_ttl) fait suivre à fopen() un
	 * dossier devenu lien et rend à realpath() l'ancien chemin. Le remplacement est fait par un processus
	 * EXTERNE (unlink(), rename() et symlink() de PHP vident le cache eux-mêmes). Banc Linux.
	 */
	public function test_un_dossier_remplace_par_un_lien_apres_une_lecture_du_meme_processus_n_est_jamais_suivi(): void {
		if ( 'Windows' === PHP_OS_FAMILY || ! function_exists( 'exec' ) ) {
			self::markTestSkipped( 'Remplacement par un processus externe (mv, ln) : essai refait sur le banc Linux.' );
		}
		$dehors = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-hors-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dehors );
		file_put_contents( $dehors . '/b.txt', 'hors' );
		mkdir( $this->racine . '/d/X' );
		$premiere = $this->entree( 'd/X/a.txt', 'dans' );
		$seconde  = $this->entree( 'd/X/b.txt', 'hors' );
		try {
			$archiveur = new FileArchiver( $this->racine );
			[ $e ]     = self::ecrivain();
			$r         = $archiveur->archive( $premiere, 0, $e, static fn (): bool => false );
			self::assertFalse( $r['unreadable'], 'premier fichier lu : le cache des chemins est rempli' );
			$ecrits = $e->written();

			exec( 'mv ' . escapeshellarg( $this->racine . '/d/X' ) . ' ' . escapeshellarg( $this->racine . '/d/X.old' ) . ' && ln -s ' . escapeshellarg( $dehors ) . ' ' . escapeshellarg( $this->racine . '/d/X' ), $sortie, $code );
			if ( 0 !== $code ) {
				self::markTestSkipped( 'mv ou ln indisponible sur ce poste.' );
			}
			$seconde['mtime'] = (int) ( stat( $dehors . '/b.txt' )['mtime'] ?? 0 );
			$r                = $archiveur->archive( $seconde, 0, $e, static fn (): bool => false );

			self::assertTrue( $r['unreadable'], 'dossier devenu lien : illisible malgré le cache' );
			self::assertNull( $r['sha256'] );
			self::assertSame( $ecrits, $e->written(), 'pas un octet du fichier visé' );
		} finally {
			if ( is_link( $this->racine . '/d/X' ) ) {
				unlink( $this->racine . '/d/X' );
			}
			if ( is_dir( $this->racine . '/d/X.old' ) ) {
				foreach ( (array) glob( $this->racine . '/d/X.old/*' ) as $f ) {
					unlink( (string) $f );
				}
				rmdir( $this->racine . '/d/X.old' );
			}
			if ( is_dir( $this->racine . '/d/X' ) ) {
				foreach ( (array) glob( $this->racine . '/d/X/*' ) as $f ) {
					unlink( (string) $f );
				}
				rmdir( $this->racine . '/d/X' );
			}
			unlink( $dehors . '/b.txt' );
			rmdir( $dehors );
		}
	}

	public function test_fichier_qui_retrecit_ou_grandit_pendant_la_lecture_est_dit_illisible(): void {
		$entree = $this->entree( 'd/change.bin', random_bytes( FileArchiver::CHUNK_BYTES * 2 ) );
		$this->reecrire( 'd/change.bin', random_bytes( 1000 ), self::MTIME );
		[ $e ] = self::ecrivain();
		self::assertTrue( ( new FileArchiver( $this->racine ) )->archive( $entree, 0, $e, static fn (): bool => false )['unreadable'], 'rétréci : jamais gardé tronqué avec sa taille d\'origine' );

		$entree = $this->entree( 'd/grandi.txt', 'abc' );
		$this->reecrire( 'd/grandi.txt', 'abcdef', self::MTIME );
		[ $e2 ] = self::ecrivain();
		$r      = ( new FileArchiver( $this->racine ) )->archive( $entree, 0, $e2, static fn (): bool => false );
		self::assertTrue( $r['unreadable'], 'grandi pendant la lecture' );
		self::assertNull( $r['sha256'] );
	}

	public function test_fichier_reecrit_a_taille_egale_ou_vide_qui_grandit_est_dit_illisible(): void {
		$entree = $this->entree( 'd/reecrit.txt', 'abc' );
		$this->reecrire( 'd/reecrit.txt', 'xyz', self::MTIME + 60 );
		[ $e ] = self::ecrivain();
		$r     = ( new FileArchiver( $this->racine ) )->archive( $entree, 0, $e, static fn (): bool => false );
		self::assertTrue( $r['unreadable'], 'même taille, date différente de celle du parcours : changé (§ 5)' );
		self::assertNull( $r['sha256'] );

		$entree = $this->entree( 'd/vide-grandi', '' );
		$this->reecrire( 'd/vide-grandi', 'abc', self::MTIME );
		[ $e2 ] = self::ecrivain();
		$r      = ( new FileArchiver( $this->racine ) )->archive( $entree, 0, $e2, static fn (): bool => false );
		self::assertTrue( $r['unreadable'], 'vide au parcours, plein à la lecture : jamais gardé vide' );
		self::assertNull( $r['sha256'] );
		self::assertSame( 4, $e2->written(), 'rien d\'écrit après la magie' );
	}

	public function test_media_stocke_sans_compression_texte_compresse(): void {
		self::assertFalse( FileArchiver::compressible( 'wp-content/uploads/photo.JPG' ) );
		self::assertFalse( FileArchiver::compressible( 'a.woff2' ) );
		self::assertTrue( FileArchiver::compressible( 'wp-content/themes/t/style.css' ) );
	}
}
