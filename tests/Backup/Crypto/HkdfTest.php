<?php
/**
 * HKDF-SHA256 du format G2BK v1 (Backup\Crypto\Hkdf), extraction et expansion séparées.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Crypto;

use G2RD\Connector\Backup\Crypto\Hkdf;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : RFC 5869 annexe A.1 lue dans la section `self_test` du fichier de vecteurs
 * (générateur Python indépendant) ; annexes A.2 et A.3 recopiées de la RFC et recontrôlées le
 * 24/10/2026 par `cryptography` 42.0.8 ; hash_hkdf() de PHP sert de seconde implémentation pour
 * la plus grande longueur permise. Jamais une sortie du code testé.
 */
final class HkdfTest extends TestCase {

	/** RFC 5869 A.2 : entrées longues, L = 82 (trois blocs de HMAC). */
	private const A2_PRK = '06a6b88c5853361a06104c9ceb35b45cef760014904671014a193f40c15fc244';

	private const A2_OKM = 'b11e398dc80327a1c8e7f78c596a49344f012eda2d4efad8a050cc4c19afa97c59045a99cac7827271cb41c65e590e09da3275600c2f09b8367793a9aca3db71cc30c58179ec3e87c14c01d5c1f3434f1d87';

	/** RFC 5869 A.3 : IKM de A.1, sel et info vides, L = 42. */
	private const A3_PRK = '19ef24a32c717b167f33a91d6f648bdf96596776afdb6377ac434c1c293ccb04';

	private const A3_OKM = '8da4e775a563c18f715f802a063c5a31b8a11f5c5ee1879ec3454e5f3c738d2d9d201395faa4b61a96c8';

	/**
	 * Le cas `hkdf_sha256` (RFC 5869 A.1) du fichier de vecteurs, en octets.
	 *
	 * @return array{ikm: string, salt: string, info: string, length: int, prk: string, okm: string}
	 */
	private static function a1(): array {
		$self_test = VectorsFixture::section( 'self_test' );
		$cas       = $self_test['hkdf_sha256'] ?? null;
		self::assertIsArray( $cas, 'Cas hkdf_sha256 absent de la section self_test.' );
		self::assertIsInt( $cas['length'] ?? null );

		return [
			'ikm'    => VectorsFixture::bin( $cas['ikm'] ?? null ),
			'salt'   => VectorsFixture::bin( $cas['salt'] ?? null ),
			'info'   => VectorsFixture::bin( $cas['info'] ?? null ),
			'length' => $cas['length'],
			'prk'    => VectorsFixture::bin( $cas['prk'] ?? null ),
			'okm'    => VectorsFixture::bin( $cas['okm'] ?? null ),
		];
	}

	public function test_rfc_5869_a1_reproduite(): void {
		$a1 = self::a1();
		self::assertSame( 42, $a1['length'] );

		$prk = Hkdf::extract( $a1['salt'], $a1['ikm'] );
		self::assertSame( bin2hex( $a1['prk'] ), bin2hex( $prk ), 'Extraction.' );
		self::assertSame( bin2hex( $a1['okm'] ), bin2hex( Hkdf::expand( $prk, $a1['info'], $a1['length'] ) ), 'Expansion.' );

		// A.2 : entrées de 80 octets, trois blocs de HMAC à l'expansion.
		$prk = Hkdf::extract( self::suite( 0x60, 80 ), self::suite( 0x00, 80 ) );
		self::assertSame( self::A2_PRK, bin2hex( $prk ) );
		self::assertSame( self::A2_OKM, bin2hex( Hkdf::expand( $prk, self::suite( 0xb0, 80 ), 82 ) ) );
	}

	public function test_sel_vide_egal_a_32_zeros(): void {
		$ikm = self::a1()['ikm'];

		$prk = Hkdf::extract( '', $ikm );
		self::assertSame( bin2hex( Hkdf::extract( str_repeat( "\0", 32 ), $ikm ) ), bin2hex( $prk ), 'Sel vide = HMAC de clé vide = 32 zéros (RFC 5869 § 2.2).' );
		self::assertSame( self::A3_PRK, bin2hex( $prk ), 'RFC 5869 A.3, extraction.' );
		self::assertSame( self::A3_OKM, bin2hex( Hkdf::expand( $prk, '', 42 ) ), 'RFC 5869 A.3, expansion (info vide).' );
	}

	public function test_longueur_hors_bornes_refusee(): void {
		$a1  = self::a1();
		$prk = $a1['prk'];

		foreach ( [ 0, -1, -42, 8161, 8192, PHP_INT_MAX, PHP_INT_MIN ] as $longueur ) {
			try {
				Hkdf::expand( $prk, $a1['info'], $longueur );
				self::fail( 'Longueur acceptée à tort : ' . $longueur );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringNotContainsString( bin2hex( $prk ), $e->getMessage() );
			}
		}

		// Les deux bornes sont permises : 1 octet, et 255 × 32 = 8 160 octets.
		self::assertSame( bin2hex( substr( $a1['okm'], 0, 1 ) ), bin2hex( Hkdf::expand( $prk, $a1['info'], 1 ) ) );
		$plus_long = Hkdf::expand( $prk, $a1['info'], 8160 );
		self::assertSame( 8160, strlen( $plus_long ) );
		self::assertSame( bin2hex( $a1['okm'] ), bin2hex( substr( $plus_long, 0, 42 ) ) );
		self::assertSame(
			bin2hex( hash_hkdf( 'sha256', $a1['ikm'], 8160, $a1['info'], $a1['salt'] ) ),
			bin2hex( $plus_long ),
			'Même sortie que hash_hkdf(), seconde implémentation.'
		);
	}

	/** Octets $debut, $debut + 1, … ($longueur octets), comme les entrées de l'annexe A.2. */
	private static function suite( int $debut, int $longueur ): string {
		$octets = '';
		for ( $i = 0; $i < $longueur; $i++ ) {
			$octets .= chr( $debut + $i );
		}
		return $octets;
	}
}
