<?php
/**
 * Certificat de clé de site (Backup\Format\SiteKeyCertificate, décision n° 15).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\SiteKeyCertificate;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : sections `site_key_certificate` (deux cas) et
 * `site_key_certificate_tampering` (quatre cas) du fichier de vecteurs, produites par le
 * générateur Python indépendant de la plateforme, jamais par le code testé.
 *
 * Moteurs : comme BackupIndexTest. Tout cas qui vérifie une signature porte le groupe
 * sodium-compat (le contrôle n° 5 passe par les classes Core de sodium_compat dans les deux
 * moteurs).
 */
final class SiteKeyCertificateTest extends TestCase {

	/** Domaine du certificat (docs/backup-format.md § 9 et § 10). */
	private const DOMAINE = "g2rd-keys/site-key-certificate-v1\x00";

	private bool $disable_fallback = false;

	private bool $fast_mult = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
		$this->fast_mult        = \ParagonIE_Sodium_Compat::$fastMult;
	}

	protected function tearDown(): void {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		\ParagonIE_Sodium_Compat::$fastMult                    = $this->fast_mult;
		parent::tearDown();
	}

	public function test_constante_du_domaine(): void {
		self::assertSame( self::DOMAINE, SiteKeyCertificate::DOMAIN );
	}

	public function test_le_fichier_porte_les_cas_attendus(): void {
		self::assertSame( [ 'version_1', 'version_7' ], array_column( VectorsFixture::section( 'site_key_certificate' ), 'name' ) );
		self::assertSame( [ 'autre_site', 'autre_version', 'autre_cle_publique', 'cle_de_service_non_epinglee' ], array_column( VectorsFixture::section( 'site_key_certificate_tampering' ), 'name' ) );
	}

	public function test_le_message_signe_est_celui_du_vecteur(): void {
		foreach ( VectorsFixture::section( 'site_key_certificate' ) as $cas ) {
			$message = SiteKeyCertificate::message( VectorsFixture::bin( $cas['tenant_ref'] ), VectorsFixture::bin( $cas['site_ref'] ), self::version( $cas ), VectorsFixture::bin( $cas['public_key'] ) );

			self::assertSame( $cas['message'], bin2hex( $message ), (string) $cas['name'] );
			// Domaine ‖ 00, références (16 + 16), version (4, gros-boutiste), clé X25519 (32).
			self::assertSame( strlen( self::DOMAINE ) + 16 + 16 + 4 + 32, strlen( $message ) );
			self::assertStringStartsWith( self::DOMAINE, $message );
			self::assertSame( pack( 'N', self::version( $cas ) ), substr( $message, strlen( self::DOMAINE ) + 32, 4 ) );
		}
	}

	public function test_message_refuse_les_arguments_hors_contrat(): void {
		$cas    = VectorsFixture::named( 'site_key_certificate', 'version_1' );
		$tenant = VectorsFixture::bin( $cas['tenant_ref'] );
		$site   = VectorsFixture::bin( $cas['site_ref'] );
		$cle    = VectorsFixture::bin( $cas['public_key'] );

		$essais = [
			'agence de 15 octets'        => [ substr( $tenant, 0, 15 ), $site, 1, $cle ],
			'agence en hexadécimal'      => [ bin2hex( $tenant ), $site, 1, $cle ],
			'site de 17 octets'          => [ $tenant, $site . "\0", 1, $cle ],
			'clé de 31 octets'           => [ $tenant, $site, 1, substr( $cle, 0, 31 ) ],
			'clé en hexadécimal'         => [ $tenant, $site, 1, bin2hex( $cle ) ],
			'version 0'                  => [ $tenant, $site, 0, $cle ],
			'version négative'           => [ $tenant, $site, -1, $cle ],
			// pack( 'N' ) garderait les 32 bits bas : 2³² donnerait le message de la version 0.
			'version 2³²'                => [ $tenant, $site, 4294967296, $cle ],
			'version 2³² + 1 (= 1 tronquée)' => [ $tenant, $site, 4294967297, $cle ],
		];
		foreach ( $essais as $libelle => [ $t, $s, $v, $k ] ) {
			try {
				SiteKeyCertificate::message( $t, $s, $v, $k );
				self::fail( 'message() a accepté : ' . $libelle );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'Cause probable :', $e->getMessage(), $libelle );
				self::assertStringContainsString( 'Action :', $e->getMessage(), $libelle );
			}
		}

		// Bornes incluses.
		self::assertSame( strlen( self::DOMAINE ) + 68, strlen( SiteKeyCertificate::message( $tenant, $site, 4294967295, $cle ) ) );
		self::assertSame( "\xff\xff\xff\xff", substr( SiteKeyCertificate::message( $tenant, $site, 4294967295, $cle ), strlen( self::DOMAINE ) + 32, 4 ) );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_certificats_des_vecteurs_se_verifient_en_natif(): void {
		self::assert_certificats_valides( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_certificats_des_vecteurs_se_verifient_en_compat(): void {
		self::assert_certificats_valides( self::compat() );
	}

	/**
	 * @param array<string, mixed> $cas Cas de `site_key_certificate_tampering`.
	 */
	#[DataProvider( 'certificats_alteres' )]
	#[Group( 'sodium-compat' )]
	public function test_les_certificats_alteres_sont_refuses( array $cas ): void {
		self::assertSame( 'site_key_certificate_invalid', $cas['expected_error'] );

		$epinglees = [];
		self::assertIsArray( $cas['pinned_service_keys'] );
		foreach ( $cas['pinned_service_keys'] as $epinglee ) {
			self::assertIsArray( $epinglee );
			$cle = VectorsFixture::bin( $epinglee['public_key'] );
			self::assertSame( $epinglee['service_key_id'], substr( hash( 'sha256', $cle ), 0, 16 ) );
			$epinglees[] = $cle;
		}

		foreach ( self::moteurs() as $moteur => $sodium ) {
			self::assertFalse( self::verifier( $cas, $epinglees, $sodium ), $moteur . ' : ' . (string) $cas['name'] );
		}
	}

	public function test_une_liste_epinglee_vide_refuse_tout(): void {
		$appels = 0;
		$espion = static function () use ( &$appels ): bool {
			++$appels;
			return true;
		};
		// Vérificateur doublé qui accepte tout : seul le refus de la classe est mesuré.
		$sodium = self::compat( $espion );

		foreach ( VectorsFixture::section( 'site_key_certificate' ) as $cas ) {
			self::assertFalse( self::verifier( $cas, [], $sodium ), (string) $cas['name'] );
		}
		self::assertSame( 0, $appels, 'Aucune vérification sans clé épinglée.' );

		// Sans moteur non plus, une liste vide refuse sans lever : rien n'est à vérifier.
		$sans = new Sodium( static fn (): bool => false, static fn (): bool => false );
		self::assertFalse( self::verifier( VectorsFixture::named( 'site_key_certificate', 'version_1' ), [], $sans ) );
	}

	#[Group( 'sodium-compat' )]
	public function test_la_verification_ne_leve_jamais(): void {
		$cas         = VectorsFixture::named( 'site_key_certificate', 'version_1' );
		$tenant      = VectorsFixture::bin( $cas['tenant_ref'] );
		$site        = VectorsFixture::bin( $cas['site_ref'] );
		$cle         = VectorsFixture::bin( $cas['public_key'] );
		$certificat  = VectorsFixture::bin( $cas['certificate'] );
		$id          = (string) $cas['service_key_id'];
		$service     = VectorsFixture::bin( $cas['service_public_key'] );
		$autre       = VectorsFixture::bin( VectorsFixture::named( 'site_key_certificate_tampering', 'cle_de_service_non_epinglee' )['pinned_service_keys'][0]['public_key'] );
		$id_de_autre = substr( hash( 'sha256', $autre ), 0, 16 );

		$essais = [
			'agence de 15 octets'                 => [ substr( $tenant, 0, 15 ), $site, 1, $cle, $certificat, $id, [ $service ] ],
			'site en hexadécimal'                 => [ $tenant, bin2hex( $site ), 1, $cle, $certificat, $id, [ $service ] ],
			'version 0'                           => [ $tenant, $site, 0, $cle, $certificat, $id, [ $service ] ],
			'version négative'                    => [ $tenant, $site, -7, $cle, $certificat, $id, [ $service ] ],
			'version 2³² + 1'                     => [ $tenant, $site, 4294967297, $cle, $certificat, $id, [ $service ] ],
			'version PHP_INT_MAX'                 => [ $tenant, $site, PHP_INT_MAX, $cle, $certificat, $id, [ $service ] ],
			'clé de site vide'                    => [ $tenant, $site, 1, '', $certificat, $id, [ $service ] ],
			'certificat vide'                     => [ $tenant, $site, 1, $cle, '', $id, [ $service ] ],
			'certificat de 63 octets'             => [ $tenant, $site, 1, $cle, substr( $certificat, 0, 63 ), $id, [ $service ] ],
			'certificat en hexadécimal'           => [ $tenant, $site, 1, $cle, bin2hex( $certificat ), $id, [ $service ] ],
			'certificat altéré d\'un bit'         => [ $tenant, $site, 1, $cle, chr( ord( $certificat[0] ) ^ 0x01 ) . substr( $certificat, 1 ), $id, [ $service ] ],
			'identifiant vide'                    => [ $tenant, $site, 1, $cle, $certificat, '', [ $service ] ],
			'identifiant en majuscules'           => [ $tenant, $site, 1, $cle, $certificat, strtoupper( $id ), [ $service ] ],
			'identifiant d\'une autre clé'        => [ $tenant, $site, 1, $cle, $certificat, $id_de_autre, [ $service, $autre ] ],
			'clé épinglée en hexadécimal'         => [ $tenant, $site, 1, $cle, $certificat, $id, [ bin2hex( $service ) ] ],
			'clé épinglée de 31 octets'           => [ $tenant, $site, 1, $cle, $certificat, $id, [ substr( $service, 0, 31 ) ] ],
			'entrées épinglées qui ne sont pas des chaînes' => [ $tenant, $site, 1, $cle, $certificat, $id, [ null, 42, [ $service ], true ] ],
			'clé épinglée nulle'                  => [ $tenant, $site, 1, $cle, $certificat, substr( hash( 'sha256', str_repeat( "\0", 32 ) ), 0, 16 ), [ str_repeat( "\0", 32 ) ] ],
			'clé épinglée hors de la courbe'      => [ $tenant, $site, 1, $cle, $certificat, substr( hash( 'sha256', (string) hex2bin( '0200000000000000000000000000000000000000000000000000000000000000' ) ), 0, 16 ), [ (string) hex2bin( '0200000000000000000000000000000000000000000000000000000000000000' ) ] ],
			'octets quelconques'                  => [ random_bytes( 16 ), random_bytes( 16 ), 3, random_bytes( 32 ), random_bytes( 64 ), bin2hex( random_bytes( 8 ) ), [ random_bytes( 32 ) ] ],
		];

		foreach ( self::moteurs() as $moteur => $sodium ) {
			foreach ( $essais as $libelle => [ $t, $s, $v, $k, $c, $i, $p ] ) {
				self::assertFalse( SiteKeyCertificate::verify( $t, $s, $v, $k, $c, $i, $p, $sodium ), $moteur . ' : ' . $libelle );
			}
			// Témoin : les mêmes arguments, justes, passent ; une entrée fausse dans la liste ne gêne pas.
			self::assertTrue( SiteKeyCertificate::verify( $tenant, $site, 1, $cle, $certificat, $id, [ null, 'x', $autre, $service ], $sodium ), $moteur . ' : témoin' );
		}
	}

	/**
	 * Une panne n'est pas un certificat invalide (même règle que Sodium::ed25519_verify()) : sans
	 * moteur, la vérification d'un certificat qui a une clé épinglée à essayer lève
	 * CRYPTO_UNAVAILABLE. Rendre faux ferait conclure, en phase 4, à un certificat faux, donc à une
	 * plateforme ou à un service suspect, pour une simple absence de cryptographie.
	 */
	public function test_sans_moteur_une_panne_n_est_pas_un_certificat_invalide(): void {
		$cas  = VectorsFixture::named( 'site_key_certificate', 'version_1' );
		$sans = new Sodium( static fn (): bool => false, static fn (): bool => false );

		try {
			self::verifier( $cas, [ VectorsFixture::bin( $cas['service_public_key'] ) ], $sans );
			self::fail( 'FormatError « crypto_unavailable » attendue.' );
		} catch ( FormatError $e ) {
			self::assertSame( FormatError::CRYPTO_UNAVAILABLE, $e->reason() );
			self::assertNull( $e->getPrevious() );
		}
	}

	public function test_la_classe_ne_passe_que_par_sodium(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Backup/Format/SiteKeyCertificate.php' );
		self::assertIsString( $source );

		$noms = [];
		foreach ( token_get_all( $source ) as $jeton ) {
			if ( is_array( $jeton ) && in_array( $jeton[0], [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ], true ) ) {
				$noms[] = $jeton[1];
			}
		}
		foreach ( $noms as $nom ) {
			self::assertStringStartsNotWith( 'sodium_', strtolower( ltrim( $nom, '\\' ) ), 'Appel direct à l\'extension sodium : ' . $nom );
			self::assertStringNotContainsString( 'paragonie', strtolower( $nom ), 'Appel direct à sodium_compat : ' . $nom );
			self::assertStringNotContainsString( 'pk_to_curve25519', $nom );
			self::assertStringNotContainsString( 'ge_mul_l', $nom );
		}
		self::assertContains( 'ed25519_verify', $noms, 'La lecture des jetons doit voir l\'appel attendu.' );
	}

	// ---------------------------------------------------------------------------------------
	// Fournisseurs.
	// ---------------------------------------------------------------------------------------

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function certificats_alteres(): iterable {
		foreach ( VectorsFixture::section( 'site_key_certificate_tampering' ) as $cas ) {
			/** @var array<string, mixed> $cas */
			yield (string) $cas['name'] => [ $cas ];
		}
	}

	// ---------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------

	private static function assert_certificats_valides( Sodium $sodium ): void {
		foreach ( VectorsFixture::section( 'site_key_certificate' ) as $cas ) {
			$nom     = (string) $cas['name'];
			$service = VectorsFixture::bin( $cas['service_public_key'] );

			// La paire du service, tirée de la graine du vecteur, redonne le certificat à l'octet.
			$paire = $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( $cas['seed'] ) );
			self::assertSame( $service, $paire['public'], $nom );
			self::assertSame( $cas['service_key_id'], substr( hash( 'sha256', $service ), 0, 16 ), $nom );
			$message = SiteKeyCertificate::message( VectorsFixture::bin( $cas['tenant_ref'] ), VectorsFixture::bin( $cas['site_ref'] ), self::version( $cas ), VectorsFixture::bin( $cas['public_key'] ) );
			self::assertSame( $cas['certificate'], bin2hex( $sodium->ed25519_sign( $message, $paire['secret'] ) ), $nom );

			self::assertTrue( self::verifier( $cas, [ $service ], $sodium ), $nom );
			// La clé du service parmi d'autres épinglées (rotation : l'ancienne reste listée).
			$autre = VectorsFixture::bin( VectorsFixture::named( 'site_key_certificate_tampering', 'cle_de_service_non_epinglee' )['pinned_service_keys'][0]['public_key'] );
			self::assertTrue( self::verifier( $cas, [ $autre, $service ], $sodium ), $nom . ' : parmi d\'autres' );
			self::assertFalse( self::verifier( $cas, [ $autre ], $sodium ), $nom . ' : seule une autre clé épinglée' );
		}

		// Le certificat de la version 1 ne vaut pas pour la version 7, ni l'inverse.
		$v1 = VectorsFixture::named( 'site_key_certificate', 'version_1' );
		$v7 = VectorsFixture::named( 'site_key_certificate', 'version_7' );
		$v1['certificate'] = $v7['certificate'];
		self::assertFalse( self::verifier( $v1, [ VectorsFixture::bin( $v7['service_public_key'] ) ], $sodium ) );
	}

	/**
	 * SiteKeyCertificate::verify() avec les champs d'un cas du fichier.
	 *
	 * @param array<string, mixed> $cas       Cas de `site_key_certificate` ou de sa section d'altérations.
	 * @param array<mixed>         $epinglees Clés publiques épinglées.
	 */
	private static function verifier( array $cas, array $epinglees, Sodium $sodium ): bool {
		return SiteKeyCertificate::verify(
			VectorsFixture::bin( $cas['tenant_ref'] ),
			VectorsFixture::bin( $cas['site_ref'] ),
			self::version( $cas ),
			VectorsFixture::bin( $cas['public_key'] ),
			VectorsFixture::bin( $cas['certificate'] ),
			(string) $cas['service_key_id'],
			$epinglees,
			$sodium
		);
	}

	/**
	 * @param array<string, mixed> $cas Cas du fichier.
	 */
	private static function version( array $cas ): int {
		self::assertIsInt( $cas['version'] );
		return $cas['version'];
	}

	private static function natif(): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, static fn (): bool => true );
	}

	private static function compat( ?\Closure $verifier = null ): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true, $verifier );
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
}
