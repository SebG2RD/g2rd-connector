<?php
/**
 * AES-256-GCM du format G2BK v1 (Backup\Crypto\Aead).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Crypto;

use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : le cas `aes_256_gcm` de la section `self_test` du fichier de vecteurs
 * (générateur Python indépendant), et les cas 13 et 14 des vecteurs de la spécification de GCM
 * (McGrew et Viega, clé et nonce nuls, données associées vides), recontrôlés le 24/10/2026 par
 * `cryptography` 42.0.8 ; jamais une sortie du code testé.
 *
 * Piège mesuré le 24/10/2026 (PHP 8.4.3, OpenSSL 3.0.15 ; aussi dans
 * docs/superpowers/mesures/2026-10-24-sauvegardes/chiffrement/resultats-gcm-natif.txt, dépôt de la
 * plateforme) : openssl_decrypt() accepte une étiquette de 15, 4 ou 1 octet(s) quand ce sont les
 * premiers octets de la vraie. Sur un bloc final vide tronqué, une ouverture qui prendrait « les
 * 16 derniers octets, ou tout ce qui reste » rendrait '' : un bloc final vide accepté sans
 * étiquette complète. Les cas d'étiquette tronquée partent donc du bloc vide, où ce piège mord.
 */
final class AeadTest extends TestCase {

	/** Cas 13 de GCM : clé de 32 zéros, nonce de 12 zéros, clair et données associées vides. */
	private const GCM_CAS_13_ETIQUETTE = '530f8afbc74536b9a963b4f1c4cb738b';

	/** Cas 14 de GCM : même clé et même nonce, clair de 16 zéros. */
	private const GCM_CAS_14_CHIFFRE = 'cea7403d4d606b6e074ec5d3baf39d18';

	private const GCM_CAS_14_ETIQUETTE = 'd0d1c8a799996bf0265b98b5d48ab919';

	/**
	 * Le cas `aes_256_gcm` du fichier de vecteurs, en octets.
	 *
	 * @return array{key: string, nonce: string, aad: string, pt: string, ct: string, tag: string}
	 */
	private static function vecteur(): array {
		$self_test = VectorsFixture::section( 'self_test' );
		$cas       = $self_test['aes_256_gcm'] ?? null;
		self::assertIsArray( $cas, 'Cas aes_256_gcm absent de la section self_test.' );

		return [
			'key'   => VectorsFixture::bin( $cas['key'] ?? null ),
			'nonce' => VectorsFixture::bin( $cas['nonce'] ?? null ),
			'aad'   => VectorsFixture::bin( $cas['aad'] ?? null ),
			'pt'    => VectorsFixture::bin( $cas['pt'] ?? null ),
			'ct'    => VectorsFixture::bin( $cas['ct'] ?? null ),
			'tag'   => VectorsFixture::bin( $cas['tag'] ?? null ),
		];
	}

	/** Bit de poids faible de l'octet n° $offset inversé. */
	private static function inverse_un_bit( string $octets, int $offset ): string {
		$octets[ $offset ] = chr( ord( $octets[ $offset ] ) ^ 0x01 );
		return $octets;
	}

	public function test_le_vecteur_aes_256_gcm_est_reproduit(): void {
		$v = self::vecteur();
		self::assertSame( 16, strlen( $v['tag'] ) );

		$scelle = Aead::seal( $v['key'], $v['nonce'], $v['aad'], $v['pt'] );

		self::assertSame( bin2hex( $v['ct'] . $v['tag'] ), bin2hex( $scelle ), 'Chiffré ‖ étiquette (16) attendus.' );
		self::assertSame( strlen( $v['pt'] ) + Aead::TAG_BYTES, strlen( $scelle ) );

		// Cas 14 de GCM : un bloc entier de 16 octets.
		self::assertSame(
			self::GCM_CAS_14_CHIFFRE . self::GCM_CAS_14_ETIQUETTE,
			bin2hex( Aead::seal( str_repeat( "\0", 32 ), str_repeat( "\0", 12 ), '', str_repeat( "\0", 16 ) ) )
		);

		self::assertSame( 32, Aead::KEY_BYTES );
		self::assertSame( 12, Aead::NONCE_BYTES );
		self::assertSame( 16, Aead::TAG_BYTES );
	}

	public function test_ouverture_du_vecteur(): void {
		$v = self::vecteur();

		self::assertSame( bin2hex( $v['pt'] ), bin2hex( (string) Aead::open( $v['key'], $v['nonce'], $v['aad'], $v['ct'] . $v['tag'] ) ) );

		$clair = Aead::open( str_repeat( "\0", 32 ), str_repeat( "\0", 12 ), '', (string) hex2bin( self::GCM_CAS_14_CHIFFRE . self::GCM_CAS_14_ETIQUETTE ) );
		self::assertSame( str_repeat( "\0", 16 ), $clair );
	}

	public function test_bloc_vide_chiffre_et_ouvert(): void {
		// Cas 13 de GCM : l'étiquette seule, valeur publiée.
		$scelle = Aead::seal( str_repeat( "\0", 32 ), str_repeat( "\0", 12 ), '', '' );
		self::assertSame( self::GCM_CAS_13_ETIQUETTE, bin2hex( $scelle ) );
		self::assertSame( '', Aead::open( str_repeat( "\0", 32 ), str_repeat( "\0", 12 ), '', $scelle ), 'Un bloc final vide rend la chaîne vide, pas null.' );

		// Même chose avec des données associées, comme un bloc final vide du format.
		$v      = self::vecteur();
		$scelle = Aead::seal( $v['key'], $v['nonce'], $v['aad'], '' );
		self::assertSame( Aead::TAG_BYTES, strlen( $scelle ) );
		self::assertSame( '', Aead::open( $v['key'], $v['nonce'], $v['aad'], $scelle ) );
	}

	public function test_echec_d_ouverture_d_un_bloc_vide_rend_null(): void {
		$v      = self::vecteur();
		$scelle = Aead::seal( $v['key'], $v['nonce'], $v['aad'], '' );
		self::assertSame( '', Aead::open( $v['key'], $v['nonce'], $v['aad'], $scelle ), 'Préalable : le bloc vide intact s\'ouvre.' );

		for ( $offset = 0; $offset < Aead::TAG_BYTES; $offset++ ) {
			self::assertNull(
				Aead::open( $v['key'], $v['nonce'], $v['aad'], self::inverse_un_bit( $scelle, $offset ) ),
				'Étiquette d\'un bloc vide altérée à l\'octet ' . $offset . ' : null attendu, jamais \'\'.'
			);
		}
		self::assertNull( Aead::open( $v['key'], $v['nonce'], '', $scelle ), 'Bloc vide sous d\'autres données associées.' );
	}

	public function test_etiquette_tronquee_a_15_octets_refusee(): void {
		$v = self::vecteur();

		// Le chiffré du vecteur raccourci d'un octet : les 16 derniers octets ne sont plus l'étiquette.
		self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'], substr( $v['ct'] . $v['tag'], 0, -1 ) ) );

		// Bloc final vide tronqué à 15 octets : 15 premiers octets de la vraie étiquette, que
		// openssl_decrypt() seul accepterait (piège du docblock).
		$scelle = Aead::seal( $v['key'], $v['nonce'], $v['aad'], '' );
		self::assertSame( '', Aead::open( $v['key'], $v['nonce'], $v['aad'], $scelle ), 'Préalable : le bloc vide intact s\'ouvre.' );
		self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'], substr( $scelle, 0, 15 ) ) );
	}

	public function test_etiquette_d_un_octet_refusee(): void {
		$v      = self::vecteur();
		$scelle = Aead::seal( $v['key'], $v['nonce'], $v['aad'], '' );
		self::assertSame( '', Aead::open( $v['key'], $v['nonce'], $v['aad'], $scelle ), 'Préalable : le bloc vide intact s\'ouvre.' );

		// Bloc final vide réduit au premier octet de son étiquette.
		self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'], substr( $scelle, 0, 1 ) ) );

		// Chiffré du vecteur suivi d'un seul octet d'étiquette.
		self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'], $v['ct'] . $v['tag'][0] ) );

		// Toute longueur sous 16 octets, objet vide compris.
		for ( $longueur = 0; $longueur < Aead::TAG_BYTES; $longueur++ ) {
			self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'], substr( $scelle, 0, $longueur ) ), 'Objet de ' . $longueur . ' octet(s).' );
		}
	}

	public function test_donnees_associees_differentes_refusees(): void {
		$v      = self::vecteur();
		$scelle = $v['ct'] . $v['tag'];

		self::assertNull( Aead::open( $v['key'], $v['nonce'], self::inverse_un_bit( $v['aad'], 0 ), $scelle ) );
		self::assertNull( Aead::open( $v['key'], $v['nonce'], self::inverse_un_bit( $v['aad'], strlen( $v['aad'] ) - 1 ), $scelle ) );
		self::assertNull( Aead::open( $v['key'], $v['nonce'], '', $scelle ) );
		self::assertNull( Aead::open( $v['key'], $v['nonce'], $v['aad'] . "\0", $scelle ) );
		self::assertNull( Aead::open( $v['key'], $v['nonce'], substr( $v['aad'], 0, -1 ), $scelle ) );
	}

	public function test_mauvaise_cle_refusee(): void {
		$v      = self::vecteur();
		$scelle = $v['ct'] . $v['tag'];

		self::assertNull( Aead::open( self::inverse_un_bit( $v['key'], 0 ), $v['nonce'], $v['aad'], $scelle ) );
		self::assertNull( Aead::open( self::inverse_un_bit( $v['key'], 31 ), $v['nonce'], $v['aad'], $scelle ) );
		self::assertNull( Aead::open( str_repeat( "\0", 32 ), $v['nonce'], $v['aad'], $scelle ) );

		// Le nonce d'un autre bloc non plus (même clé) : un bloc déplacé ne s'ouvre pas.
		self::assertNull( Aead::open( $v['key'], self::inverse_un_bit( $v['nonce'], 11 ), $v['aad'], $scelle ) );
	}

	public function test_longueur_de_nonce_fausse_est_une_erreur_de_programmation(): void {
		$v = self::vecteur();

		$cas = [];
		foreach ( [ 0, 8, 11, 13, 16 ] as $longueur ) {
			$cas[ 'nonce de ' . $longueur . ' octets' ] = [ $v['key'], str_repeat( "\x5a", $longueur ) ];
		}
		// Clé : OpenSSL complète en silence une clé courte par des zéros (mesuré le 24/10/2026).
		foreach ( [ 0, 16, 31, 33, 64 ] as $longueur ) {
			$cas[ 'clé de ' . $longueur . ' octets' ] = [ str_repeat( "\x5a", $longueur ), $v['nonce'] ];
		}

		foreach ( $cas as $nom => [ $cle, $nonce ] ) {
			foreach ( [ 'seal', 'open' ] as $methode ) {
				try {
					if ( 'seal' === $methode ) {
						Aead::seal( $cle, $nonce, $v['aad'], $v['pt'] );
					} else {
						Aead::open( $cle, $nonce, $v['aad'], $v['ct'] . $v['tag'] );
					}
					self::fail( $methode . ' accepté à tort : ' . $nom );
				} catch ( \InvalidArgumentException $e ) {
					self::assertNotInstanceOf( FormatError::class, $e, $methode . ', ' . $nom );
					// Message constant : aucun octet reçu n'y est recopié.
					self::assertStringNotContainsString( "\x5a\x5a", $e->getMessage() );
					self::assertStringNotContainsString( '5a5a', $e->getMessage() );
				}
			}
		}
	}

	public function test_disponibilite_lue_dans_la_liste_des_chiffrements(): void {
		$appels = 0;
		$liste  = static function ( array $chiffrements ) use ( &$appels ): \Closure {
			return static function () use ( $chiffrements, &$appels ): array {
				++$appels;
				return $chiffrements;
			};
		};

		self::assertTrue( Aead::available( $liste( [ 'aes-128-cbc', 'aes-256-gcm', 'aes-256-cbc' ] ) ) );
		self::assertFalse( Aead::available( $liste( [ 'aes-128-gcm', 'aes-256-cbc', 'chacha20-poly1305' ] ) ) );
		self::assertFalse( Aead::available( $liste( [] ) ) );
		self::assertFalse( Aead::available( $liste( [ 'aes-256-gcm ' ] ) ), 'Nom exact attendu.' );

		// Rien de mémorisé : la liste est relue à chaque appel.
		$appels = 0;
		$fixe   = $liste( [ 'aes-256-gcm' ] );
		self::assertTrue( Aead::available( $fixe ) );
		self::assertTrue( Aead::available( $fixe ) );
		self::assertSame( 2, $appels );

		// La vraie liste d'OpenSSL : le poste et la CI ont aes-256-gcm.
		self::assertTrue( Aead::available() );
	}
}
