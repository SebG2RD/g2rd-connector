<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Support;

use G2RD\Connector\Support\PathRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles de chemins communes à la mesure des tailles et au parcours des sauvegardes (extraites de
 * Environment\SizeMeter sans changement : ses tests restent verts sans modification).
 */
final class PathRulesTest extends TestCase {

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function normalisations(): iterable {
		yield 'séparateurs Windows' => [ 'C:\\site\\wp-content\\', 'C:/site/wp-content' ];
		yield 'séparateurs répétés' => [ '/var/www//html///wp-content/', '/var/www/html/wp-content' ];
		yield 'chemin réseau gardé en tête' => [ '//serveur/partage//dossier', '//serveur/partage/dossier' ];
		yield 'racine' => [ '/', '' ];
		yield 'lecteur seul' => [ 'C:\\', 'C:' ];
	}

	#[DataProvider( 'normalisations' )]
	public function test_normalisation_des_chemins( string $brut, string $attendu ): void {
		self::assertSame( $attendu, PathRules::normalize( $brut ) );
	}

	public function test_un_dossier_voisin_au_meme_prefixe_n_est_pas_dedans(): void {
		self::assertTrue( PathRules::is_inside( '/a/wp-content/uploads/x', '/a/wp-content/uploads' ) );
		self::assertFalse( PathRules::is_inside( '/a/wp-content/uploads-old/x', '/a/wp-content/uploads' ) );
		self::assertFalse( PathRules::is_inside( '/a/wp-content/uploads', '/a/wp-content/uploads' ) );
	}

	public function test_racines_reconnues(): void {
		self::assertTrue( PathRules::is_root( '' ) );
		self::assertTrue( PathRules::is_root( 'C:' ) );
		self::assertFalse( PathRules::is_root( '/var' ) );
	}

	public function test_un_dossier_ordinaire_ne_traverse_aucun_lien(): void {
		$base = sys_get_temp_dir() . '/g2rd-pathrules-' . bin2hex( random_bytes( 4 ) );
		mkdir( $base . '/a/b', 0777, true );
		try {
			self::assertFalse( PathRules::crosses_link( PathRules::normalize( $base ), 'a/b' ) );
			self::assertFalse( PathRules::crosses_link( PathRules::normalize( $base ), 'absent' ), 'absent : rien à suivre' );
		} finally {
			rmdir( $base . '/a/b' );
			rmdir( $base . '/a' );
			rmdir( $base );
		}
	}
}
