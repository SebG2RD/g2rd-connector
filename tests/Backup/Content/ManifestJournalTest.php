<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use G2RD\Connector\Backup\Content\ManifestJournal;
use PHPUnit\Framework\TestCase;

/**
 * Journal du manifeste (docs/backup-payload.md § 5) : octets exacts des vecteurs `manifest_lines`,
 * chemin et nom de table non UTF-8 en base64, journal rendu par morceaux dans l'ordre.
 */
final class ManifestJournalTest extends TestCase {

	public function test_lignes_du_manifeste_des_vecteurs(): void {
		$v = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/backup-payload-vectors.json' ), true, 32, JSON_THROW_ON_ERROR );
		self::assertNotEmpty( $v['manifest_lines'] ?? [], 'section manifest_lines absente ou vide : le test ne prouverait rien' );
		foreach ( $v['manifest_lines'] as $cas ) {
			self::assertSame( $cas['expected_hex'], bin2hex( ManifestJournal::encode( $cas['object'] ) ), $cas['name'] );
		}
	}

	public function test_un_chemin_non_utf8_s_ecrit_en_base64(): void {
		self::assertSame( [ 'path' => 'a/été' ], ManifestJournal::path_field( 'a/été' ) );
		self::assertSame( [ 'path_b64' => base64_encode( "a/\xe9t\xe9" ) ], ManifestJournal::path_field( "a/\xe9t\xe9" ) );
	}

	public function test_un_nom_de_table_non_utf8_s_ecrit_en_base64(): void {
		self::assertSame( [ 'name' => 'wp_options' ], ManifestJournal::name_field( 'wp_options' ) );
		self::assertSame( [ 'name_b64' => base64_encode( "wp_caf\xe9" ) ], ManifestJournal::name_field( "wp_caf\xe9" ) );
	}

	public function test_journal_ajoute_puis_rendu_par_morceaux_dans_l_ordre(): void {
		$fichier = sys_get_temp_dir() . '/g2rd-manifeste-' . bin2hex( random_bytes( 4 ) ) . '.jsonl';
		$journal = new ManifestJournal( $fichier );
		for ( $i = 0; $i < 3000; $i++ ) {
			$journal->append( [ 't' => 'file', 'path' => str_repeat( 'x', 500 ) . $i ] );
		}
		$rendu    = '';
		$decalage = [];
		$journal->stream( static function ( int $offset, string $morceau ) use ( &$rendu, &$decalage ): void {
			$decalage[] = $offset;
			$rendu     .= $morceau;
		} );

		self::assertSame( (string) file_get_contents( $fichier ), $rendu );
		self::assertSame( 0, $decalage[0] );
		self::assertGreaterThan( 1, count( $decalage ), 'morceaux d\'au plus 1 Mio' );
		unlink( $fichier );
	}
}
