<?php
/**
 * Scellement HPKE du format G2BK v1 (Backup\Format\Hpke) : RFC 9180, mode base,
 * DHKEM(X25519, HKDF-SHA256), HKDF-SHA256, AES-256-GCM.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use Brain\Monkey;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : Brain Monkey ne sert qu'à un test, pour sa doublure de Sodium (classe finale) par
 * Patchwork, dans un processus à part (précédent : SelfTestTest).
 *
 * Valeurs attendues : section `hpke` du fichier de vecteurs (quatre cas : clé de données,
 * rescellement, enveloppe de secours, vide), produite par le générateur Python indépendant de la
 * plateforme, dont le code HPKE est contrôlé contre l'annexe A.1 de la RFC 9180. Jamais une sortie
 * du code testé. Les étapes intermédiaires du fichier (shared_secret, key, base_nonce) servent au
 * diagnostic d'un écart : la classe ne les expose pas.
 *
 * Moteurs : comme SodiumTest. Natif si l'extension est chargée ; « compat » = la copie de
 * sodium_compat de vendor (1.24.0, celle du parc), forcée en PHP pur par
 * $disableFallbackForUnitTests. Les cas qui jouent le moteur compat portent le groupe
 * sodium-compat (rejeu contre une autre copie : commande du docblock de SodiumCompatCopieTest).
 */
final class HpkeTest extends TestCase {

	/** Les quatre cas du fichier, dans son ordre : un par usage, plus un vide. */
	private const CAS = [ 'cle_de_donnees', 'rescellement', 'enveloppe_de_secours', 'vide' ];

	/** `info` de chaque usage (docs/backup-format.md § 6 et § 10). */
	private const INFOS = [
		'cle_de_donnees'       => 'g2bk-v1/dek',
		'rescellement'         => 'g2bk-v1/reseal',
		'enveloppe_de_secours' => 'g2rd-keys/recovery-v1',
		'vide'                 => '',
	];

	/**
	 * Coordonnées u d'ordre faible de X25519 (et leurs variantes p − 1, p, p + 1) : l'échange avec
	 * une clé secrète réduite (multiple de 8) rend 32 zéros. Recopiées de SodiumTest.
	 */
	private const X25519_ORDRE_FAIBLE = [
		'0000000000000000000000000000000000000000000000000000000000000000',
		'0100000000000000000000000000000000000000000000000000000000000000',
		'e0eb7a7c3b41b8ae1656e3faf19fc46ada098deb9c32b1fd866205165f49b800',
		'5f9c95bca3508c24b1d0b1559c83ef5b04445cc4581c8e86d8224eddd09f1157',
		'ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
		'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
		'eeffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
	];

	private bool $disable_fallback = false;

	/** Brain Monkey (et donc Patchwork) chargé par ce test : à refermer dans tearDown(). */
	private bool $monkey = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
	}

	protected function tearDown(): void {
		if ( $this->monkey ) {
			Monkey\tearDown();
		}
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		parent::tearDown();
	}

	public function test_identifiants_de_la_suite(): void {
		self::assertSame( 0, Hpke::MODE_BASE );
		self::assertSame( 0x0020, Hpke::KEM_ID );
		self::assertSame( 0x0001, Hpke::KDF_ID );
		self::assertSame( 0x0002, Hpke::AEAD_ID );
		self::assertSame( 32, Hpke::ENC_BYTES );
	}

	// ---------------------------------------------------------------------------------------
	// Vecteurs.
	// ---------------------------------------------------------------------------------------

	public function test_les_quatre_vecteurs_sont_reproduits_en_natif(): void {
		self::assert_vecteurs_reproduits( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_quatre_vecteurs_sont_reproduits_en_compat(): void {
		self::assert_vecteurs_reproduits( self::compat() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_quatre_vecteurs_s_ouvrent(): void {
		foreach ( self::moteurs() as $moteur => $sodium ) {
			$hpke = new Hpke( $sodium );
			foreach ( self::vecteurs() as $nom => $v ) {
				// assertSame : le cas vide rend '', jamais null.
				self::assertSame( $v['pt'], $hpke->open( $v['sk_r'], $v['enc'], $v['info'], $v['aad'], $v['ct'] ), $moteur . ' : ' . $nom );
			}
		}
	}

	// ---------------------------------------------------------------------------------------
	// Ce qui ne s'ouvre pas.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_mauvais_destinataire_ne_s_ouvre_pas(): void {
		// Toute chaîne de 32 octets est une clé secrète X25519 valide.
		$autre = hash( 'sha256', 'g2rd-tests/hpke/autre-destinataire', true );
		foreach ( self::cas_d_alteration() as [ $libelle, $hpke, , $v ] ) {
			self::assertNotSame( $v['sk_r'], $autre );
			self::assertNull( $hpke->open( $autre, $v['enc'], $v['info'], $v['aad'], $v['ct'] ), $libelle );
			// La clé éphémère de l'envoyeur n'ouvre pas non plus.
			self::assertNull( $hpke->open( $v['sk_e'], $v['enc'], $v['info'], $v['aad'], $v['ct'] ), $libelle . ' (clé éphémère)' );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_info_differente_ne_s_ouvre_pas(): void {
		foreach ( self::cas_d_alteration() as [ $libelle, $hpke, $nom, $v ] ) {
			$autres = [ $v['info'] . 'x', "\0" . $v['info'] ];
			if ( '' !== $v['info'] ) {
				$autres[] = substr( $v['info'], 0, -1 );
			}
			// Séparation des usages : l'info d'un autre usage n'ouvre pas.
			foreach ( self::INFOS as $usage => $info ) {
				if ( $usage !== $nom ) {
					$autres[] = $info;
				}
			}
			foreach ( array_unique( $autres ) as $info ) {
				self::assertNotSame( $v['info'], $info );
				self::assertNull( $hpke->open( $v['sk_r'], $v['enc'], $info, $v['aad'], $v['ct'] ), $libelle . ' avec l\'info « ' . $info . ' »' );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_aad_differente_ne_s_ouvre_pas(): void {
		foreach ( self::cas_d_alteration() as [ $libelle, $hpke, , $v ] ) {
			$autres = [ $v['aad'] . "\0" ];
			if ( '' !== $v['aad'] ) {
				$autres[] = substr( $v['aad'], 0, -1 );
				$autres[] = self::bit_inverse( $v['aad'], 0 );
				$autres[] = self::bit_inverse( $v['aad'], strlen( $v['aad'] ) - 1 );
			}
			foreach ( array_unique( $autres ) as $i => $aad ) {
				self::assertNotSame( $v['aad'], $aad );
				self::assertNull( $hpke->open( $v['sk_r'], $v['enc'], $v['info'], $aad, $v['ct'] ), $libelle . ', altération ' . $i );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_enc_modifie_ne_s_ouvre_pas(): void {
		foreach ( self::cas_d_alteration() as [ $libelle, $hpke, $nom, $v ] ) {
			$autres = [
				'premier bit'   => self::bit_inverse( $v['enc'], 0 ),
				'dernier octet' => self::bit_inverse( $v['enc'], 31 ),
				// Bit 255 mis : X25519 l'ignore (même échange), mais kem_context porte les octets reçus,
				// si bien que le secret partagé diffère.
				'bit 255'       => substr( $v['enc'], 0, 31 ) . chr( ord( $v['enc'][31] ) | 0x80 ),
				'enc du voisin' => self::autre_vecteur( $nom )['enc'],
				'31 octets'     => substr( $v['enc'], 0, 31 ),
				'33 octets'     => $v['enc'] . "\0",
				'vide'          => '',
			];
			foreach ( $autres as $alteration => $enc ) {
				self::assertNotSame( $v['enc'], $enc );
				self::assertNull( $hpke->open( $v['sk_r'], $enc, $v['info'], $v['aad'], $v['ct'] ), $libelle . ', enc ' . $alteration );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_chiffre_altere_ne_s_ouvre_pas(): void {
		foreach ( self::cas_d_alteration() as [ $libelle, $hpke, , $v ] ) {
			$longueur = strlen( $v['ct'] );
			$autres   = [
				'premier bit'           => self::bit_inverse( $v['ct'], 0 ),
				'dernier bit'           => self::bit_inverse( $v['ct'], $longueur - 1 ),
				'étiquette à 15 octets' => substr( $v['ct'], 0, -1 ),
				'étiquette d\'un octet' => substr( $v['ct'], 0, $longueur - 15 ),
				'octet en plus'         => $v['ct'] . "\0",
				'15 octets'             => substr( $v['ct'], 0, 15 ),
				'vide'                  => '',
			];
			foreach ( $autres as $alteration => $ct ) {
				self::assertNotSame( $v['ct'], $ct );
				self::assertNull( $hpke->open( $v['sk_r'], $v['enc'], $v['info'], $v['aad'], $ct ), $libelle . ', chiffré ' . $alteration );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_point_d_ordre_faible_ne_s_ouvre_pas(): void {
		$v = self::vecteurs()['cle_de_donnees'];
		foreach ( self::moteurs() as $moteur => $sodium ) {
			$hpke = new Hpke( $sodium );
			// Cas du plan : enc = 32 zéros ; puis les autres points d'ordre faible.
			foreach ( self::X25519_ORDRE_FAIBLE as $hex ) {
				self::assertNull( $hpke->open( $v['sk_r'], self::bin( $hex ), $v['info'], $v['aad'], $v['ct'] ), $moteur . ' : enc ' . $hex );
			}
		}
	}

	// ---------------------------------------------------------------------------------------
	// Tirage de la clé éphémère.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_cle_ephemere_tiree_a_chaque_scellement(): void {
		$v = self::vecteurs()['cle_de_donnees'];
		foreach ( self::moteurs() as $moteur => $sodium ) {
			// Tirage par défaut (random_bytes) : deux scellements du même clair, deux enc.
			$hpke   = new Hpke( $sodium );
			$un     = $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
			$deux   = $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
			$public = [ $un['enc'], $deux['enc'] ];
			self::assertNotSame( $un['enc'], $deux['enc'], $moteur );
			self::assertNotSame( $un['ciphertext'], $deux['ciphertext'], $moteur );
			self::assertNotContains( $v['enc'], $public, $moteur );
			self::assertSame( 48, strlen( $un['ciphertext'] ), $moteur );
			self::assertSame( $v['pt'], $hpke->open( $v['sk_r'], $un['enc'], $v['info'], $v['aad'], $un['ciphertext'] ), $moteur );
			self::assertSame( $v['pt'], $hpke->open( $v['sk_r'], $deux['enc'], $v['info'], $v['aad'], $deux['ciphertext'] ), $moteur );

			// Tirage injecté : une demande de 32 octets par scellement, aucune à l'ouverture.
			$demandes = [];
			$hpke     = new Hpke(
				$sodium,
				static function ( int $longueur ) use ( &$demandes ): string {
					$demandes[] = $longueur;
					return random_bytes( $longueur );
				}
			);
			$scelle = $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
			$hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
			$hpke->open( $v['sk_r'], $scelle['enc'], $v['info'], $v['aad'], $scelle['ciphertext'] );
			self::assertSame( [ 32, 32 ], $demandes, $moteur );
		}
	}

	public function test_un_tirage_de_mauvaise_longueur_est_une_erreur_de_programmation(): void {
		$v = self::vecteurs()['cle_de_donnees'];
		foreach ( [ 31, 33, 0 ] as $longueur ) {
			$hpke = new Hpke( self::compat(), static fn ( int $n ): string => str_repeat( "\x01", $longueur ) );
			try {
				$hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
				self::fail( 'Tirage de ' . $longueur . ' octets accepté.' );
			} catch ( \UnexpectedValueException $e ) {
				self::assertStringContainsString( '32 octets', $e->getMessage() );
				self::assertNull( $e->getPrevious() );
			}
		}
	}

	public function test_une_source_d_alea_en_panne_rend_crypto_unavailable(): void {
		$v    = self::vecteurs()['cle_de_donnees'];
		$hpke = new Hpke(
			self::compat(),
			static function (): string {
				// Ce que random_bytes() lève sans source d'aléa (\Random\RandomException en PHP 8.2+).
				throw new \Exception( 'Could not gather sufficient random data' );
			}
		);
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] ) );
	}

	// ---------------------------------------------------------------------------------------
	// Refus et erreurs.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_scellement_vers_une_cle_refusee(): void {
		$v = self::vecteurs()['cle_de_donnees'];
		foreach ( self::moteurs() as $moteur => $sodium ) {
			$hpke = new Hpke( $sodium );
			// Échange de 32 zéros : refus du destinataire (« refus si 32 zéros », § 5).
			foreach ( self::X25519_ORDRE_FAIBLE as $hex ) {
				self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $hpke->seal( self::bin( $hex ), $v['info'], $v['aad'], $v['pt'] ), $moteur . ' : ' . $hex );
			}
			foreach ( [ substr( $v['pk_r'], 0, 31 ), $v['pk_r'] . "\0", '' ] as $cle ) {
				self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $hpke->seal( $cle, $v['info'], $v['aad'], $v['pt'] ), $moteur . ' : clé de ' . strlen( $cle ) . ' octets' );
			}
		}
	}

	public function test_sans_moteur_ni_scellement_ni_ouverture(): void {
		$v    = self::vecteurs()['cle_de_donnees'];
		$hpke = new Hpke( new Sodium( static fn (): bool => false, static fn (): bool => false ) );
		// Une panne n'est pas un échec d'ouverture : jamais null ici.
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] ) );
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $hpke->open( $v['sk_r'], $v['enc'], $v['info'], $v['aad'], $v['ct'] ) );
	}

	/**
	 * Seul le refus de enc (BAD_PUBLIC_KEY) devient null à l'ouverture : une panne de l'échange
	 * remonte. Avec la vraie classe Sodium, la panne arrive plus tôt (clé publique du destinataire,
	 * même moteur) ; la doublure la place sur l'échange.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_une_panne_de_l_echange_n_est_pas_un_echec_d_ouverture(): void {
		self::assertFalse( class_exists( Sodium::class, false ), 'Sodium déjà chargée avant Patchwork : sa doublure serait impossible (test hors de son processus à part ?).' );
		Monkey\setUp();
		$this->monkey = true;

		$v      = self::vecteurs()['cle_de_donnees'];
		$hpke   = new Hpke( self::compat() );
		$appels = 0;
		// Fermeture non statique : Patchwork la lie à l'objet appelé.
		\Patchwork\redefine(
			[ Sodium::class, 'x25519' ],
			function () use ( &$appels ): string {
				++$appels;
				throw new FormatError( FormatError::CRYPTO_UNAVAILABLE );
			}
		);
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $hpke->open( $v['sk_r'], $v['enc'], $v['info'], $v['aad'], $v['ct'] ) );
		self::assertSame( 1, $appels, 'La panne doit venir de l\'échange, pas d\'ailleurs.' );
	}

	public function test_cle_secrete_de_mauvaise_longueur_est_une_erreur_de_programmation(): void {
		$v    = self::vecteurs()['cle_de_donnees'];
		$hpke = new Hpke( self::compat() );
		foreach ( [ substr( $v['sk_r'], 0, 31 ), $v['sk_r'] . "\0", bin2hex( $v['sk_r'] ) ] as $cle ) {
			try {
				$hpke->open( $cle, $v['enc'], $v['info'], $v['aad'], $v['ct'] );
				self::fail( 'Clé secrète de ' . strlen( $cle ) . ' octets acceptée.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( '32 octets', $e->getMessage() );
			}
		}
	}

	// ---------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------

	private static function assert_vecteurs_reproduits( Sodium $sodium ): void {
		foreach ( self::vecteurs() as $nom => $v ) {
			$demandes = [];
			$sk_e     = $v['sk_e'];
			$hpke     = new Hpke(
				$sodium,
				static function ( int $longueur ) use ( &$demandes, $sk_e ): string {
					$demandes[] = $longueur;
					return $sk_e;
				}
			);

			$scelle = $hpke->seal( $v['pk_r'], $v['info'], $v['aad'], $v['pt'] );
			self::assertSame( [ 'enc', 'ciphertext' ], array_keys( $scelle ), $nom );
			self::assertSame( bin2hex( $v['enc'] ), bin2hex( $scelle['enc'] ), $nom . ' : enc' );
			self::assertSame( bin2hex( $v['ct'] ), bin2hex( $scelle['ciphertext'] ), $nom . ' : ct (si enc est juste, comparer shared_secret, key et base_nonce du fichier)' );
			self::assertSame( strlen( $v['pt'] ) + 16, strlen( $scelle['ciphertext'] ), $nom );
			self::assertSame( [ 32 ], $demandes, $nom . ' : une seule clé éphémère de 32 octets tirée' );
		}
	}

	/**
	 * Les quatre cas de la section `hpke`, en octets, par nom.
	 *
	 * @return array<string, array{sk_r: string, pk_r: string, sk_e: string, info: string, aad: string, pt: string, enc: string, ct: string}>
	 */
	private static function vecteurs(): array {
		$vecteurs = [];
		foreach ( VectorsFixture::section( 'hpke' ) as $cas ) {
			self::assertIsArray( $cas );
			$nom = $cas['name'] ?? null;
			self::assertIsString( $nom );
			$v = [];
			foreach ( [ 'sk_r', 'pk_r', 'sk_e', 'info', 'aad', 'pt', 'enc', 'ct' ] as $champ ) {
				$v[ $champ ] = VectorsFixture::bin( $cas[ $champ ] ?? null );
			}
			$vecteurs[ $nom ] = $v;
		}
		self::assertSame( self::CAS, array_keys( $vecteurs ) );
		foreach ( self::INFOS as $nom => $info ) {
			self::assertSame( $info, $vecteurs[ $nom ]['info'], 'info du cas ' . $nom );
		}
		/** @var array<string, array{sk_r: string, pk_r: string, sk_e: string, info: string, aad: string, pt: string, enc: string, ct: string}> $vecteurs */
		return $vecteurs;
	}

	/**
	 * Cas des tests d'altération : les quatre vecteurs en natif ; en compat, le seul
	 * `cle_de_donnees`, l'usage du connecteur. Ces altérations portent sur les entrées de HKDF et
	 * d'AES-GCM, communes aux deux moteurs ; en compat, chaque ouverture coûte deux X25519 en PHP pur
	 * (environ 0,1 s chacun), et les quatre vecteurs y portaient chacun de ces tests jusqu'à 14 s.
	 * Ce qui dépend du moteur (vecteurs, points d'ordre faible, clés refusées) se joue en entier
	 * dans les deux.
	 *
	 * @return list<array{0: string, 1: Hpke, 2: string, 3: array{sk_r: string, pk_r: string, sk_e: string, info: string, aad: string, pt: string, enc: string, ct: string}}>
	 */
	private static function cas_d_alteration(): array {
		$cas = [];
		foreach ( self::moteurs() as $moteur => $sodium ) {
			$hpke = new Hpke( $sodium );
			foreach ( self::vecteurs() as $nom => $v ) {
				if ( 'compat' !== $moteur || 'cle_de_donnees' === $nom ) {
					$cas[] = [ $moteur . ' : ' . $nom, $hpke, $nom, $v ];
				}
			}
		}
		self::assertNotEmpty( $cas );
		return $cas;
	}

	/**
	 * Le cas suivant de la section (le premier après le dernier).
	 *
	 * @return array{sk_r: string, pk_r: string, sk_e: string, info: string, aad: string, pt: string, enc: string, ct: string}
	 */
	private static function autre_vecteur( string $nom ): array {
		$position = (int) array_search( $nom, self::CAS, true );
		return self::vecteurs()[ self::CAS[ ( $position + 1 ) % count( self::CAS ) ] ];
	}

	/**
	 * Moteur natif ; test sauté sans l'extension. Le chargeur rend vrai : les classes de
	 * sodium_compat (vendor) restent chargeables, comme dans SodiumTest.
	 */
	private static function natif(): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, static fn (): bool => true );
	}

	private static function compat(): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true );
	}

	/**
	 * Les moteurs joués : natif si l'extension est chargée, compat toujours.
	 *
	 * @return array<string, Sodium>
	 */
	private static function moteurs(): array {
		$moteurs = [];
		if ( extension_loaded( 'sodium' ) ) {
			$moteurs['natif'] = self::natif();
		}
		$moteurs['compat'] = self::compat();
		return $moteurs;
	}

	private static function assert_format_error( string $code, \Closure $operation, string $message = '' ): void {
		try {
			$operation();
		} catch ( FormatError $e ) {
			self::assertSame( $code, $e->reason(), $message );
			self::assertSame( $code, $e->getMessage(), $message );
			self::assertNull( $e->getPrevious(), $message );
			return;
		}
		self::fail( 'FormatError « ' . $code . ' » attendue. ' . $message );
	}

	private static function bit_inverse( string $octets, int $position ): string {
		$octets[ $position ] = chr( ord( $octets[ $position ] ) ^ 0x01 );
		return $octets;
	}

	private static function bin( string $hex ): string {
		return (string) hex2bin( $hex );
	}
}
