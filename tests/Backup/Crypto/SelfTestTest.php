<?php
/**
 * Autotest à valeurs connues des primitives du format G2BK v1 (Backup\Crypto\SelfTest).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Crypto;

use Brain\Monkey;
use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Crypto\Hkdf;
use G2RD\Connector\Backup\Crypto\SelfTest;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Valeurs attendues : celles du fichier de vecteurs (générateur Python indépendant, contrôlé contre
 * les RFC 7748, 8032 et 5869) et celle de FIPS 180-2 pour SHA-256 ; jamais une sortie du code
 * testé.
 *
 * Doublures de Sodium : la classe est finale (ni PHPUnit ni Mockery ne la doublent). Une doublure
 * est donc un vrai objet Sodium dont une méthode est redéfinie par Patchwork, chargé par Brain
 * Monkey. Patchwork ne redéfinit que les fichiers inclus après lui : ces tests tournent chacun dans
 * un processus à part (précédent : CommandRuns\AsyncProbeTest), où Patchwork est chargé avant
 * Sodium.php, Aead.php et Hkdf.php, quel que soit l'ordre des tests lancés avant. Les redéfinitions
 * sont retirées par Monkey\tearDown(). Elles ne sont jamais des fermetures statiques : Patchwork lie
 * la fermeture à l'objet appelé (bindTo), ce qu'une fermeture statique refuse.
 *
 * Moteur « compat » : la copie de sodium_compat de vendor (1.24.0, celle du parc), forcée en PHP
 * pur par $disableFallbackForUnitTests ; test_tout_passe_en_compat porte le groupe sodium-compat
 * et se rejoue contre une autre copie (commande du docblock de SodiumCompatCopieTest).
 */
final class SelfTestTest extends TestCase {

	/** SHA-256 de « abc », FIPS 180-2, annexe B.1. */
	private const FIPS_180_2_SHA256_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

	/** Domaine de la signature d'un index (docs/backup-format.md § 7.5). */
	private const INDEX_DOMAIN = "g2bk-v1/index\x00";

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

	// ---------------------------------------------------------------------------------------
	// Tout passe.
	// ---------------------------------------------------------------------------------------

	/** Groupe sodium-compat : en natif aussi, le contrôle n° 5 passe par les classes Core de sodium_compat. */
	#[Group( 'sodium-compat' )]
	public function test_tout_passe_en_natif(): void {
		self::assertSame( [ 'ok' => true, 'failed' => [], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( self::natif() ) );
	}

	#[Group( 'sodium-compat' )]
	public function test_tout_passe_en_compat(): void {
		self::assertSame( [ 'ok' => true, 'failed' => [], 'backend' => Sodium::BACKEND_COMPAT ], SelfTest::run( self::compat() ) );
	}

	public function test_la_liste_des_controles_est_celle_du_contrat(): void {
		self::assertSame( [ 'sha256', 'hkdf_sha256', 'aes_256_gcm', 'x25519', 'ed25519' ], SelfTest::CHECKS );
	}

	// ---------------------------------------------------------------------------------------
	// Constantes recopiées dans le code.
	// ---------------------------------------------------------------------------------------

	public function test_les_constantes_sont_celles_du_fichier_de_vecteurs(): void {
		$self_test = VectorsFixture::section( 'self_test' );
		// Une valeur ajoutée à la section self_test du fichier doit être reprise ici ou écartée
		// en connaissance de cause.
		self::assertSame( [ 'x25519', 'ed25519', 'hkdf_sha256', 'aes_256_gcm' ], array_keys( $self_test ) );

		self::assertSame( $self_test['x25519'], self::constante( 'X25519' ) );
		self::assertSame( $self_test['ed25519'], self::constante( 'ED25519' ) );
		self::assertSame( $self_test['hkdf_sha256'], self::constante( 'HKDF_SHA256' ) );
		self::assertSame( $self_test['aes_256_gcm'], self::constante( 'AES_256_GCM' ) );

		$ordre_2l = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_2l' );
		self::assertSame( FormatError::INDEX_SIGNATURE_INVALID, $ordre_2l['expected_error'] );
		self::assertSame(
			[
				'index'      => $ordre_2l['index'],
				'public_key' => $ordre_2l['public_key'],
				'signature'  => $ordre_2l['signature'],
			],
			self::constante( 'ED25519_ORDER_2L' )
		);
		self::assertSame( self::INDEX_DOMAIN, self::constante( 'INDEX_SIGNATURE_DOMAIN' ) );

		self::assertSame( [ 'message' => 'abc', 'digest' => self::FIPS_180_2_SHA256_ABC ], self::constante( 'SHA256' ) );
	}

	// ---------------------------------------------------------------------------------------
	// Primitive fausse, primitive qui lève, moteur absent.
	// ---------------------------------------------------------------------------------------

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_une_primitive_qui_calcule_faux_est_detectee(): void {
		$this->patchwork();
		$sodium = self::natif();
		$appels = 0;

		// Doublure du plan : l'échange X25519 rend son résultat décalé d'un octet.
		$redefinition = \Patchwork\redefine( [ Sodium::class, 'x25519' ], self::fausse( self::decale(), $appels ) );
		self::assertSame( [ 'ok' => false, 'failed' => [ 'x25519' ], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sodium ) );
		self::assertSame( 1, $appels, 'La doublure n\'a pas rendu son résultat : l\'échec viendrait d\'ailleurs.' );
		\Patchwork\restore( $redefinition );

		// Chaque autre primitive comparée, fausse à son tour : seul son contrôle échoue.
		$decale = self::decale();
		$pt     = VectorsFixture::bin( VectorsFixture::section( 'self_test' )['aes_256_gcm']['pt'] );
		$cas    = [
			'clé publique X25519 décalée'    => [ 'x25519', [ Sodium::class, 'x25519_base' ], $decale ],
			'clé publique Ed25519 décalée'   => [
				'ed25519',
				[ Sodium::class, 'ed25519_keypair_from_seed' ],
				static function ( array $paire ) use ( $decale ): array {
					$paire['public'] = $decale( $paire['public'] );
					return $paire;
				},
			],
			'signature Ed25519 décalée'      => [ 'ed25519', [ Sodium::class, 'ed25519_sign' ], $decale ],
			'extraction HKDF décalée'        => [ 'hkdf_sha256', [ Hkdf::class, 'extract' ], $decale ],
			'expansion HKDF décalée'         => [ 'hkdf_sha256', [ Hkdf::class, 'expand' ], $decale ],
			'chiffré GCM décalé'             => [ 'aes_256_gcm', [ Aead::class, 'seal' ], $decale ],
			'ouverture GCM qui accepte tout' => [ 'aes_256_gcm', [ Aead::class, 'open' ], static fn (): string => $pt ],
			'ouverture GCM qui refuse tout'  => [ 'aes_256_gcm', [ Aead::class, 'open' ], static fn (): ?string => null ],
		];
		foreach ( $cas as $nom => [ $controle, $methode, $transforme ] ) {
			$appels       = 0;
			$redefinition = \Patchwork\redefine( $methode, self::fausse( $transforme, $appels ) );
			$resultat     = SelfTest::run( $sodium );
			\Patchwork\restore( $redefinition );

			self::assertSame( [ 'ok' => false, 'failed' => [ $controle ], 'backend' => Sodium::BACKEND_NATIVE ], $resultat, $nom );
			self::assertGreaterThan( 0, $appels, 'La doublure n\'a pas rendu son résultat : ' . $nom );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_une_primitive_qui_leve_est_detectee(): void {
		$this->patchwork();
		$sodium = self::natif();

		$cas = [
			'x25519'      => [ [ Sodium::class, 'x25519_base' ], new \RuntimeException( 'doublure' ) ],
			'ed25519'     => [ [ Sodium::class, 'ed25519_sign' ], new \SodiumException( 'doublure' ) ],
			'hkdf_sha256' => [ [ Hkdf::class, 'expand' ], new \Error( 'doublure' ) ],
			'aes_256_gcm' => [ [ Aead::class, 'seal' ], new FormatError( FormatError::CRYPTO_UNAVAILABLE ) ],
		];
		foreach ( $cas as $controle => [ $methode, $exception ] ) {
			$levees       = [];
			$redefinition = \Patchwork\redefine(
				$methode,
				function () use ( $exception, &$levees ): never {
					$levees[] = $exception;
					throw $exception;
				}
			);
			$resultat     = SelfTest::run( $sodium );
			\Patchwork\restore( $redefinition );

			self::assertSame( [ 'ok' => false, 'failed' => [ $controle ], 'backend' => Sodium::BACKEND_NATIVE ], $resultat, $controle );
			self::assertSame( [ $exception ], $levees, 'Exception de la doublure levée une fois : ' . $controle );
		}

		// Le moteur lui-même qui lève : aucun moteur rapporté, les deux contrôles de sodium en
		// échec, et toujours aucune exception hors de run().
		\Patchwork\redefine(
			[ Sodium::class, 'backend' ],
			function (): never {
				throw new \LogicException( 'doublure' );
			}
		);
		self::assertSame( [ 'ok' => false, 'failed' => [ 'x25519', 'ed25519' ], 'backend' => Sodium::BACKEND_NONE ], SelfTest::run( $sodium ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_sans_gcm_l_autotest_echoue(): void {
		$this->patchwork();
		$sodium = self::natif();
		\Patchwork\redefine( [ Aead::class, 'available' ], \Patchwork\always( false ) );
		$appels = 0;
		foreach ( [ 'seal', 'open' ] as $methode ) {
			\Patchwork\redefine(
				[ Aead::class, $methode ],
				function () use ( &$appels ) {
					++$appels;
					return \Patchwork\relay();
				}
			);
		}

		self::assertSame( [ 'ok' => false, 'failed' => [ 'aes_256_gcm' ], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sodium ) );
		// Sans GCM, OpenSSL n'est pas appelé avec un chiffrement qu'il ne connaît pas (il
		// lèverait un avertissement PHP).
		self::assertSame( 0, $appels );
	}

	public function test_sans_moteur_sodium_l_autotest_echoue_sans_lever(): void {
		$sans_moteur = new Sodium( static fn (): bool => false, static fn (): bool => false );

		self::assertSame( [ 'ok' => false, 'failed' => [ 'x25519', 'ed25519' ], 'backend' => Sodium::BACKEND_NONE ], SelfTest::run( $sans_moteur ) );
	}

	// ---------------------------------------------------------------------------------------
	// Ed25519 : vérificateur et contrôle n° 5 éprouvés.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_un_verificateur_qui_accepte_ou_refuse_tout_est_detecte(): void {
		// Vérificateur du moteur remplacé (troisième paramètre de Sodium), contrôles stricts et
		// contrôle n° 5 réels. Celui qui accepte tout n'est démasqué que par la signature altérée
		// du TEST 1 : la clé d'ordre 2·L reste refusée par le n° 5.
		$verificateurs = [
			'vérificateur qui accepte tout' => static fn (): bool => true,
			'vérificateur qui refuse tout'  => static fn (): bool => false,
		];
		foreach ( $verificateurs as $nom => $verificateur ) {
			foreach ( self::moteurs( $verificateur ) as $moteur => $sodium ) {
				self::assertSame( [ 'ok' => false, 'failed' => [ 'ed25519' ], 'backend' => $sodium->backend() ], SelfTest::run( $sodium ), $nom . ', ' . $moteur );
			}
		}

		// Moteur natif sans les classes du contrôle n° 5 (wp-includes/sodium_compat illisible) :
		// toute vérification est refusée, refus sûr que l'autotest signale (docblock de Sodium).
		if ( extension_loaded( 'sodium' ) ) {
			$sans_classes = new Sodium( static fn (): bool => true, static fn (): bool => false );
			self::assertSame( [ 'ok' => false, 'failed' => [ 'ed25519' ], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sans_classes ) );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_un_controle_du_sous_groupe_qui_accepte_tout_est_detecte(): void {
		$this->patchwork();
		$ed            = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$test_1        = [ VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), VectorsFixture::bin( $ed['public_key'] ) ];
		$alteree       = $test_1;
		$alteree[0][0] = chr( ord( $alteree[0][0] ) ^ 0x01 );
		$vecteur       = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_2l' );
		$ordre_2l      = [ VectorsFixture::bin( $vecteur['signature'] ), self::INDEX_DOMAIN . $vecteur['index'], VectorsFixture::bin( $vecteur['public_key'] ) ];

		// 1. Doublure du plan : ed25519_verify rend vrai pour toute signature bien formée
		// (64 octets, clé de 32 octets). La signature altérée et celle de la clé d'ordre 2·L
		// passent : l'autotest le voit.
		$redefinition = \Patchwork\redefine(
			[ Sodium::class, 'ed25519_verify' ],
			fn ( string $signature, string $message, string $public_key ): bool => 64 === strlen( $signature ) && 32 === strlen( $public_key )
		);
		foreach ( self::moteurs() as $nom => $sodium ) {
			self::assertTrue( $sodium->ed25519_verify( ...$alteree ), 'La doublure doit accepter la signature altérée, ' . $nom );
			self::assertTrue( $sodium->ed25519_verify( ...$ordre_2l ), 'La doublure doit accepter la clé d\'ordre 2·L, ' . $nom );

			self::assertSame( [ 'ok' => false, 'failed' => [ 'ed25519' ], 'backend' => $sodium->backend() ], SelfTest::run( $sodium ), $nom );
		}
		\Patchwork\restore( $redefinition );

		// 2. Contrôle n° 5 qui accepte tout, le reste réel. Le vérificateur du moteur refuse
		// toujours la signature altérée : seul le refus de la clé d'ordre 2·L fait échouer
		// l'autotest, puisque sodium natif et la copie 1.24.0 acceptent sa signature d'eux-mêmes.
		if ( extension_loaded( 'sodium' ) && ! sodium_crypto_sign_verify_detached( ...$ordre_2l ) ) {
			self::markTestSkipped( 'Le sodium natif de ce PHP (libsodium ' . SODIUM_LIBRARY_VERSION . ') refuse déjà seul la clé d\'ordre 2·L : le cas ne montre plus rien. Action : relever ce que fait cette version et le reporter au § 8.1 de docs/backup-format.md.' );
		}
		\Patchwork\redefine( [ Sodium::class, 'main_subgroup' ], \Patchwork\always( true ) );
		foreach ( self::moteurs() as $nom => $sodium ) {
			self::assertTrue( $sodium->ed25519_verify( ...$test_1 ), 'TEST 1, ' . $nom );
			self::assertFalse( $sodium->ed25519_verify( ...$alteree ), 'TEST 1 altéré, ' . $nom );
			self::assertTrue( $sodium->ed25519_verify( ...$ordre_2l ), 'La doublure doit laisser passer la clé d\'ordre 2·L, ' . $nom );

			self::assertSame( [ 'ok' => false, 'failed' => [ 'ed25519' ], 'backend' => $sodium->backend() ], SelfTest::run( $sodium ), $nom );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_un_second_passage_recalcule_tout(): void {
		$this->patchwork();
		$sodium = self::natif();
		// Doublure juste au premier passage, fausse au second.
		$appels       = 0;
		$redefinition = \Patchwork\redefine(
			[ Sodium::class, 'x25519' ],
			function () use ( &$appels ): string {
				$juste = \Patchwork\relay();
				return 1 === ++$appels ? $juste : substr( $juste, 1 ) . $juste[0];
			}
		);

		self::assertSame( [ 'ok' => true, 'failed' => [], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sodium ), 'premier passage' );
		self::assertSame( [ 'ok' => false, 'failed' => [ 'x25519' ], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sodium ), 'second passage' );
		self::assertSame( 2, $appels, 'Un échange par passage : le second n\'a pas repris le résultat du premier.' );

		// L'échec non plus n'est pas mémorisé.
		\Patchwork\restore( $redefinition );
		self::assertSame( [ 'ok' => true, 'failed' => [], 'backend' => Sodium::BACKEND_NATIVE ], SelfTest::run( $sodium ), 'troisième passage' );
	}

	// ---------------------------------------------------------------------------------------
	// Aides.
	// ---------------------------------------------------------------------------------------

	/**
	 * Charge Brain Monkey, donc Patchwork, avant toute classe du connecteur (processus à part).
	 */
	private function patchwork(): void {
		foreach ( [ Sodium::class, Aead::class, Hkdf::class, SelfTest::class ] as $classe ) {
			self::assertFalse( class_exists( $classe, false ), $classe . ' déjà chargée avant Patchwork : sa doublure serait impossible (test hors de son processus à part ?).' );
		}
		Monkey\setUp();
		$this->monkey = true;
	}

	/**
	 * Résultat décalé d'un octet (premier octet passé à la fin).
	 *
	 * @return \Closure( string ): string
	 */
	private static function decale(): \Closure {
		return static fn ( string $octets ): string => substr( $octets, 1 ) . $octets[0];
	}

	/**
	 * Redéfinition Patchwork : appelle la vraie méthode, transforme son résultat, puis compte
	 * l'appel. Le compte prouve que la doublure a bien rendu son résultat : une erreur dans la
	 * doublure serait avalée par run() et ferait échouer le contrôle pour une autre raison.
	 * Fermeture non statique : Patchwork la lie à l'objet appelé.
	 *
	 * @param \Closure( mixed ): mixed $transforme Transformation du vrai résultat.
	 * @param int                      $appels     Compteur d'appels, incrémenté en place.
	 */
	private static function fausse( \Closure $transforme, int &$appels ): \Closure {
		return function () use ( $transforme, &$appels ) {
			$resultat = $transforme( \Patchwork\relay() );
			++$appels;
			return $resultat;
		};
	}

	/**
	 * Valeur d'une constante privée de SelfTest.
	 */
	private static function constante( string $nom ): mixed {
		return ( new \ReflectionClassConstant( SelfTest::class, $nom ) )->getValue();
	}

	/**
	 * Moteur natif. Le chargeur rend vrai : le contrôle n° 5 emploie les classes Core de
	 * sodium_compat déjà chargeables (vendor, ou la copie rejouée).
	 *
	 * @param ( \Closure( string, string, string ): bool )|null $verifier Vérificateur du moteur remplacé.
	 */
	private static function natif( ?\Closure $verifier = null ): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, static fn (): bool => true, $verifier );
	}

	/**
	 * @param ( \Closure( string, string, string ): bool )|null $verifier Vérificateur du moteur remplacé.
	 */
	private static function compat( ?\Closure $verifier = null ): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true, $verifier );
	}

	/**
	 * Les moteurs joués : natif si l'extension est chargée, compat toujours.
	 *
	 * @param ( \Closure( string, string, string ): bool )|null $verifier Vérificateur du moteur remplacé.
	 *
	 * @return array<string, Sodium>
	 */
	private static function moteurs( ?\Closure $verifier = null ): array {
		$moteurs = [];
		if ( extension_loaded( 'sodium' ) ) {
			$moteurs['natif'] = self::natif( $verifier );
		}
		$moteurs['compat'] = self::compat( $verifier );
		return $moteurs;
	}
}
