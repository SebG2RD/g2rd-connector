<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Keys;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Keys\Fingerprint;
use G2RD\Connector\Backup\Keys\PinnedServiceKeys;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use G2RD\Connector\Tests\TestCase;

final class KeysTest extends TestCase {

	private const NOW = 1792843200;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'add_option' )->alias(
			function ( string $nom, $valeur ): bool {
				if ( array_key_exists( $nom, $this->options ) ) {
					return false;
				}
				$this->options[ $nom ] = $valeur;
				return true;
			}
		);
	}

	/** @return array<string, mixed> */
	private static function certificat( string $nom ): array {
		foreach ( VectorsFixture::all()['site_key_certificate'] as $cas ) {
			if ( $nom === $cas['name'] ) {
				return $cas;
			}
		}
		self::fail( 'Vecteur absent : ' . $nom );
	}

	/** @return array<string, mixed> */
	private static function charge( string $nom ): array {
		$cas = self::certificat( $nom );
		return [
			'tenant_ref'     => $cas['tenant_ref'],
			'site_ref'       => $cas['site_ref'],
			'version'        => $cas['version'],
			'public_key'     => $cas['public_key'],
			'certificate'    => $cas['certificate'],
			'service_key_id' => $cas['service_key_id'],
		];
	}

	private static function magasin(): SiteKeyStore {
		return new SiteKeyStore( new Sodium(), [ (string) hex2bin( self::certificat( 'version_1' )['service_public_key'] ) ] );
	}

	public function test_empreintes_de_comparaison_des_vecteurs(): void {
		foreach ( VectorsFixture::all()['empreintes_de_comparaison'] as $cas ) {
			$cle = (string) hex2bin( $cas['public_key'] );
			self::assertSame( $cas['fingerprint'], Fingerprint::comparison_fingerprint( $cle ), $cas['name'] );
			self::assertSame( $cas['display'], Fingerprint::display( $cle ), $cas['name'] );
		}
	}

	public function test_une_cle_certifiee_par_une_cle_epinglee_est_enregistree(): void {
		$resultat = self::magasin()->receive( self::charge( 'version_1' ), self::NOW );

		self::assertSame( 1, $resultat['key_version'] );
		self::assertSame( 32, strlen( $resultat['fingerprint'] ) );
		self::assertSame( self::charge( 'version_1' )['public_key'], SiteKeyStore::current()['public_key'] ?? null );
	}

	public function test_certificat_faux_ou_cle_du_service_non_epinglee_rien_n_est_enregistre(): void {
		$charge                = self::charge( 'version_1' );
		$charge['certificate'] = substr_replace( $charge['certificate'], '00', 0, 2 );

		foreach ( [ [ self::magasin(), $charge ], [ new SiteKeyStore( new Sodium() ), self::charge( 'version_1' ) ] ] as [ $magasin, $c ] ) {
			try {
				$magasin->receive( $c, self::NOW );
				self::fail( 'Refus attendu.' );
			} catch ( BackupRefused $refus ) {
				self::assertSame( BackupRefused::CERTIFICATE_INVALID, $refus->reason_code() );
			}
		}
		self::assertNull( SiteKeyStore::current() );
		self::assertSame( [], PinnedServiceKeys::KEYS, 'aucune clé épinglée avant le lot F' );
	}

	public function test_une_version_plus_ancienne_est_refusee(): void {
		$m = self::magasin();
		$m->receive( self::charge( 'version_1' ), self::NOW );
		$m->receive( self::charge( 'version_7' ), self::NOW + 1 );

		$this->expectException( BackupRefused::class );
		$this->expectExceptionMessage( 'plus ancienne' );
		$m->receive( self::charge( 'version_1' ), self::NOW + 2 );
	}

	public function test_charge_hors_contrat(): void {
		$charge            = self::charge( 'version_1' );
		$charge['version'] = '1';

		$this->expectException( BackupRefused::class );
		self::magasin()->receive( $charge, self::NOW );
	}

	public function test_cle_de_signature_creee_une_fois_chiffree_au_repos_et_rouverte(): void {
		$cle    = new SigningKey( new Sodium() );
		$public = $cle->ensure( self::NOW );

		self::assertSame( 32, strlen( $public ) );
		self::assertStringStartsWith( 'enc:', $this->options[ SigningKey::OPTION ]['seed'] );
		self::assertSame( $public, $cle->ensure( self::NOW + 10 ), 'jamais régénérée' );
		self::assertSame( $public, $cle->pair()['public'] ?? null );
		self::assertSame( 64, strlen( $cle->pair()['secret'] ?? '' ) );
	}
}
