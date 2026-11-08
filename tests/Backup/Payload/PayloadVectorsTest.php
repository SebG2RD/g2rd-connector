<?php
/**
 * Charge utile des segments G2P1 (Backup\Payload) : écrivain et lecteur stricts, contre les
 * vecteurs du générateur indépendant de la plateforme.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Payload;

use G2RD\Connector\Backup\Payload\PayloadError;
use G2RD\Connector\Backup\Payload\PayloadFormat;
use G2RD\Connector\Backup\Payload\PayloadReader;
use G2RD\Connector\Backup\Payload\PayloadWriter;
use PHPUnit\Framework\TestCase;

/**
 * Vecteurs du générateur Python indépendant (backup-payload-vectors.json, copie à l'octet de
 * backend/tests/fixtures/backup-payload-vectors.json du dépôt de la plateforme, branche
 * ajout/sauvegardes-module, version du commit dcbbc335 ; produits par
 * tools/backup-format-vectors/generer_vecteurs_charge.py, jamais modifiés à la main).
 *
 * Classe pure : pas de Brain Monkey ici.
 */
final class PayloadVectorsTest extends TestCase {

	/** @return array<string, mixed> */
	private static function vecteurs(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/backup-payload-vectors.json' ), true, 32, JSON_THROW_ON_ERROR );
	}

	public function test_en_tete_de_quarante_octets(): void {
		self::assertSame( 40, self::vecteurs()['header_bytes'] );
		self::assertSame( 40, strlen( PayloadFormat::header( 1, 0, 0, 0, 0, 0, 0, 0, 0 ) ) );
	}

	public function test_l_ecrivain_reproduit_chaque_flux_stocke_a_l_octet(): void {
		foreach ( self::vecteurs()['records'] as $cas ) {
			$flux     = '';
			$ecrivain = new PayloadWriter( static function ( string $octets ) use ( &$flux ): void {
				$flux .= $octets;
			} );
			foreach ( $cas['records'] as $e ) {
				self::ecrire( $ecrivain, $e );
			}
			$ecrivain->end();
			self::assertSame( $cas['stream_hex'], bin2hex( $flux ), $cas['name'] );
			self::assertSame( strlen( $flux ), $ecrivain->written(), $cas['name'] . ' : octets comptés' );
		}
	}

	public function test_le_lecteur_relit_chaque_flux(): void {
		$v = self::vecteurs();
		foreach ( array_merge( $v['records'], $v['inflate'] ) as $cas ) {
			$lus = PayloadReader::parse( (string) hex2bin( $cas['stream_hex'] ) );
			self::assertCount( count( $cas['records'] ), $lus, $cas['name'] );
			foreach ( $cas['records'] as $i => $attendu ) {
				self::assertSame( $attendu['type'], $lus[ $i ]['type'], $cas['name'] );
				self::assertSame( $attendu['path_hex'], bin2hex( $lus[ $i ]['path'] ), $cas['name'] );
				self::assertSame( $attendu['offset'], $lus[ $i ]['offset'], $cas['name'] );
				self::assertSame( $attendu['total_size'], $lus[ $i ]['total_size'], $cas['name'] );
				self::assertSame( $attendu['mtime'], $lus[ $i ]['mtime'], $cas['name'] );
				self::assertSame( $attendu['mode'], $lus[ $i ]['mode'], $cas['name'] );
				self::assertSame( $attendu['data_hex'], bin2hex( $lus[ $i ]['data'] ), $cas['name'] );
				self::assertSame( $attendu['compressed'], $lus[ $i ]['compressed'], $cas['name'] );
			}
		}
	}

	public function test_chaque_flux_altere_est_refuse(): void {
		$malformes = self::vecteurs()['malformed'];
		// Les trois règles que gzinflate(), unpack( 'J' ) et un mode non borné laisseraient passer.
		$noms = array_column( $malformes, 'name' );
		foreach ( [ 'deflate_suivi_d_octets', 'decalage_au_dela_de_2_63', 'mode_au_dela_de_07777' ] as $sensible ) {
			self::assertContains( $sensible, $noms, $sensible . ' : cas absent des vecteurs' );
		}
		foreach ( $malformes as $cas ) {
			try {
				PayloadReader::parse( (string) hex2bin( $cas['stream_hex'] ) );
				self::fail( $cas['name'] . ' : devait être refusé' );
			} catch ( PayloadError $e ) {
				self::assertSame( $cas['expected'], $e->reason(), $cas['name'] );
			}
		}
	}

	public function test_un_morceau_compressible_est_compresse_et_relu(): void {
		$flux     = '';
		$ecrivain = new PayloadWriter( static function ( string $octets ) use ( &$flux ): void {
			$flux .= $octets;
		} );
		$donnees  = str_repeat( 'body{margin:0}', 1000 );
		$ecrivain->file_chunk( 'style.css', 0, strlen( $donnees ), 0, 0644, $donnees, true );
		$ecrivain->end();

		$lus = PayloadReader::parse( $flux );
		self::assertTrue( $lus[0]['compressed'] );
		self::assertSame( $donnees, $lus[0]['data'] );
		self::assertLessThan( strlen( $donnees ), strlen( $flux ) );
	}

	public function test_un_morceau_trop_grand_ou_un_chemin_refuse_sont_des_erreurs_de_programmation(): void {
		$ecrivain = new PayloadWriter( static function ( string $octets ): void {
			unset( $octets );
		} );
		$this->expectException( \InvalidArgumentException::class );
		$ecrivain->file_chunk( '../x', 0, 1, 0, 0, 'x', false );
	}

	public function test_un_morceau_de_plus_d_un_mio_est_refuse_par_l_ecrivain(): void {
		$recu     = '';
		$ecrivain = new PayloadWriter( static function ( string $octets ) use ( &$recu ): void {
			$recu .= $octets;
		} );
		$donnees = str_repeat( 'a', PayloadFormat::MAX_RAW_BYTES + 1 );
		try {
			$ecrivain->file_chunk( 'gros.bin', 0, strlen( $donnees ), 0, 0644, $donnees, false );
			self::fail( 'Un morceau de plus de 1 Mio devait être refusé.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertSame( PayloadFormat::MAGIC, $recu, 'Rien après la magie : le morceau refusé n\'est pas écrit.' );
		}
	}

	public function test_un_decalage_negatif_est_refuse_par_l_ecrivain(): void {
		$ecrivain = new PayloadWriter( static function ( string $octets ): void {
			unset( $octets );
		} );
		// unpack( 'J' ) relirait -1 comme 2^64 - 1 : le lecteur le refuserait (decalage_au_dela_de_2_63).
		$this->expectException( \InvalidArgumentException::class );
		$ecrivain->sql( -1, 'SELECT 1;', false );
	}

	/**
	 * Morceaux qui finissent après leur taille déclarée, près de 2^63 : offset + raw_length y dépasse
	 * PHP_INT_MAX. Une somme calculée deviendrait un flottant arrondi à 2^63, comme total_size, et le
	 * dépassement passerait. Le lecteur Python de référence, en entiers exacts, refuse ces flux.
	 *
	 * @return array<string, array{int, int, string}> Décalage, taille déclarée, données.
	 */
	private static function morceaux_au_dela_de_2_63(): array {
		return [
			'1 octet en PHP_INT_MAX'  => [ PHP_INT_MAX, PHP_INT_MAX, 'x' ],
			'1 Mio qui finit en 2^63' => [ PHP_INT_MAX - PayloadFormat::MAX_RAW_BYTES + 1, PHP_INT_MAX, str_repeat( 'a', PayloadFormat::MAX_RAW_BYTES ) ],
		];
	}

	public function test_un_morceau_qui_finit_au_dela_de_sa_taille_pres_de_2_63_est_refuse_par_le_lecteur(): void {
		foreach ( self::morceaux_au_dela_de_2_63() as $nom => [ $decalage, $taille, $donnees ] ) {
			// Construit à la main : l'écrivain refuse ce morceau, et les vecteurs ne se modifient pas.
			$flux = PayloadFormat::MAGIC
				. PayloadFormat::header( PayloadFormat::T_FILE, PayloadFormat::C_STORED, 5, strlen( $donnees ), strlen( $donnees ), $decalage, $taille, 0, 0644 ) . 'a.txt' . $donnees
				. PayloadFormat::header( PayloadFormat::T_END, 0, 0, 0, 0, 0, 0, 0, 0 );
			try {
				PayloadReader::parse( $flux );
				self::fail( $nom . ' : devait être refusé' );
			} catch ( PayloadError $e ) {
				self::assertSame( PayloadError::MALFORMED, $e->reason(), $nom );
			}
		}
	}

	public function test_un_morceau_qui_finit_au_dela_de_sa_taille_pres_de_2_63_est_refuse_par_l_ecrivain(): void {
		foreach ( self::morceaux_au_dela_de_2_63() as $nom => [ $decalage, $taille, $donnees ] ) {
			$recu     = '';
			$ecrivain = new PayloadWriter( static function ( string $octets ) use ( &$recu ): void {
				$recu .= $octets;
			} );
			try {
				$ecrivain->file_chunk( 'a.txt', $decalage, $taille, 0, 0644, $donnees, false );
				self::fail( $nom . ' : devait être refusé' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertSame( PayloadFormat::MAGIC, $recu, $nom . ' : rien après la magie, le morceau refusé n\'est pas écrit' );
			}
		}
	}

	public function test_un_morceau_qui_finit_pile_a_sa_taille_pres_de_2_63_est_ecrit_et_relu(): void {
		// Bord accepté : offset + raw_length = total_size = PHP_INT_MAX. Le contrôle ne doit pas être plus strict que le format.
		$cas = [
			'1 octet' => [ PHP_INT_MAX - 1, 'x' ],
			'1 Mio'   => [ PHP_INT_MAX - PayloadFormat::MAX_RAW_BYTES, str_repeat( 'a', PayloadFormat::MAX_RAW_BYTES ) ],
			'0 octet' => [ PHP_INT_MAX, '' ],
		];
		foreach ( $cas as $nom => [ $decalage, $donnees ] ) {
			$flux     = '';
			$ecrivain = new PayloadWriter( static function ( string $octets ) use ( &$flux ): void {
				$flux .= $octets;
			} );
			$ecrivain->file_chunk( 'a.txt', $decalage, PHP_INT_MAX, 0, 0644, $donnees, false );
			$ecrivain->end();
			$lus = PayloadReader::parse( $flux );
			self::assertCount( 1, $lus, $nom );
			self::assertSame( $decalage, $lus[0]['offset'], $nom );
			self::assertSame( PHP_INT_MAX, $lus[0]['total_size'], $nom );
			self::assertSame( $donnees, $lus[0]['data'], $nom );
		}
	}

	/**
	 * @param array<string, mixed> $e
	 */
	private static function ecrire( PayloadWriter $ecrivain, array $e ): void {
		$chemin  = (string) hex2bin( $e['path_hex'] );
		$donnees = (string) hex2bin( $e['data_hex'] );
		match ( $e['type'] ) {
			'file'     => $ecrivain->file_chunk( $chemin, $e['offset'], $e['total_size'], $e['mtime'], $e['mode'], $donnees, false ),
			'dir'      => $ecrivain->directory( $chemin, $e['mtime'], $e['mode'] ),
			'link'     => $ecrivain->link( $chemin, $donnees, $e['mtime'], $e['mode'] ),
			'deleted'  => $ecrivain->deleted( $chemin ),
			'sql'      => $ecrivain->sql( $e['offset'], $donnees, false ),
			'manifest' => $ecrivain->manifest( $e['offset'], $donnees, false ),
		};
	}
}
