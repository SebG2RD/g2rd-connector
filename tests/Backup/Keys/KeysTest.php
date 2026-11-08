<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Keys;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\SiteKeyCertificate;
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

	/**
	 * Charge certifiée par la clé du service du vecteur version_1 (signée avec sa graine), pour
	 * d'autres références : un certificat valable, mais pour un autre site ou une autre agence.
	 *
	 * @return array<string, mixed>
	 */
	private static function certifiee_pour( string $agence, string $site, int $version ): array {
		$cas    = self::certificat( 'version_1' );
		$sodium = new Sodium();
		$paire  = $sodium->ed25519_keypair_from_seed( (string) hex2bin( $cas['seed'] ) );
		self::assertSame( $cas['service_public_key'], bin2hex( $paire['public'] ), 'graine du service du vecteur' );
		$message = SiteKeyCertificate::message( (string) hex2bin( $agence ), (string) hex2bin( $site ), $version, (string) hex2bin( $cas['public_key'] ) );

		return [
			'tenant_ref'     => $agence,
			'site_ref'       => $site,
			'version'        => $version,
			'public_key'     => $cas['public_key'],
			'certificate'    => bin2hex( $sodium->ed25519_sign( $message, $paire['secret'] ) ),
			'service_key_id' => $cas['service_key_id'],
		];
	}

	private static function refus( callable $operation ): BackupRefused {
		try {
			$operation();
		} catch ( BackupRefused $refus ) {
			return $refus;
		}
		self::fail( 'Refus attendu.' );
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

	public function test_cle_de_site_que_la_base_n_enregistre_pas_jamais_dite_recue(): void {
		Functions\when( 'update_option' )->justReturn( false );

		$refus = self::refus( fn () => self::magasin()->receive( self::charge( 'version_1' ), self::NOW ) );

		self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		self::assertSame( 30, $refus->retry_after() );
		self::assertStringContainsString( 'clé', $refus->getMessage() );
		self::assertNull( SiteKeyStore::current() );
	}

	public function test_meme_reponse_rejouee_dans_la_meme_seconde_reste_recue(): void {
		// update_option() de WordPress rend false, sans rien écrire, pour une valeur inchangée.
		Functions\when( 'update_option' )->alias(
			function ( string $nom, $valeur ): bool {
				if ( array_key_exists( $nom, $this->options ) && $this->options[ $nom ] === $valeur ) {
					return false;
				}
				$this->options[ $nom ] = $valeur;
				return true;
			}
		);
		$m       = self::magasin();
		$premier = $m->receive( self::charge( 'version_1' ), self::NOW );

		self::assertSame( $premier, $m->receive( self::charge( 'version_1' ), self::NOW ) );
	}

	public function test_cle_certifiee_pour_un_autre_site_ou_une_autre_agence_refusee(): void {
		$m = self::magasin();
		$m->receive( self::charge( 'version_1' ), self::NOW );
		$avant  = SiteKeyStore::current();
		$agence = self::charge( 'version_1' )['tenant_ref'];
		$site   = self::charge( 'version_1' )['site_ref'];

		foreach ( [ [ str_repeat( 'bb', 16 ), str_repeat( 'cc', 16 ) ], [ $agence, str_repeat( 'cc', 16 ) ], [ str_repeat( 'bb', 16 ), $site ] ] as [ $autre_agence, $autre_site ] ) {
			$refus = self::refus( fn () => $m->receive( self::certifiee_pour( $autre_agence, $autre_site, 2 ), self::NOW + 1 ) );

			self::assertSame( BackupRefused::KEY_OTHER_SITE, $refus->reason_code() );
			self::assertNull( $refus->retry_after(), 'jamais réessayé' );
			self::assertSame( $avant, SiteKeyStore::current(), 'la clé détenue reste celle du site' );
		}

		// Témoin : ce certificat est valable ; sans clé détenue, aucune référence ne permet de le
		// refuser (limite écrite en tête de SiteKeyStore).
		unset( $this->options[ SiteKeyStore::OPTION ] );
		self::assertSame( 2, $m->receive( self::certifiee_pour( str_repeat( 'bb', 16 ), str_repeat( 'cc', 16 ), 2 ), self::NOW + 2 )['key_version'] );
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

	public function test_cle_de_signature_que_la_base_n_enregistre_pas_jamais_rendue(): void {
		Functions\when( 'add_option' )->justReturn( false );
		$cle = new SigningKey( new Sodium() );

		foreach ( [ self::NOW, self::NOW + 1 ] as $instant ) {
			$refus = self::refus( fn () => $cle->ensure( $instant ) );
			self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		}
		self::assertNull( SigningKey::public_key() );
		self::assertNull( $cle->pair() );
	}

	public function test_enregistrement_abime_remplace_par_une_cle_que_le_site_rouvre(): void {
		$this->options[ SigningKey::OPTION ] = [
			'seed'       => 'enc:v1:abime',
			'public_key' => 'pas-hexa',
		];
		$cle = new SigningKey( new Sodium() );

		$public = $cle->ensure( self::NOW );

		self::assertSame( $public, SigningKey::public_key() );
		self::assertSame( $public, $cle->pair()['public'] ?? null );
		self::assertSame( $public, $cle->ensure( self::NOW + 1 ), 'gardée ensuite' );
	}

	public function test_enregistrement_abime_que_la_base_ne_remplace_pas_aucune_cle_rendue(): void {
		$this->options[ SigningKey::OPTION ] = 'abîmée';
		Functions\when( 'update_option' )->justReturn( false );
		$cle = new SigningKey( new Sodium() );

		$refus = self::refus( fn () => $cle->ensure( self::NOW ) );

		self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		self::assertSame( 'abîmée', $this->options[ SigningKey::OPTION ] );
		self::assertNull( SigningKey::public_key() );
		self::assertNull( $cle->pair() );
	}

	public function test_graine_qui_ne_se_rouvre_plus_cle_recreee_a_reapprouver(): void {
		$cle      = new SigningKey( new Sodium() );
		$ancienne = $cle->ensure( self::NOW );
		// Graine scellée avec d'autres sels que ceux de wp-config.php : sels régénérés depuis.
		$iv = random_bytes( 16 );
		$this->options[ SigningKey::OPTION ]['seed'] = 'enc:v1:' . base64_encode( $iv . (string) openssl_encrypt( bin2hex( random_bytes( 32 ) ), 'aes-256-cbc', hash( 'sha256', 'g2rd-connector|autres-sels', true ), OPENSSL_RAW_DATA, $iv ) );
		self::assertNull( $cle->pair(), 'la graine ne se rouvre plus' );

		$nouvelle = $cle->ensure( self::NOW + 10 );

		self::assertNotSame( $ancienne, $nouvelle, 'nouvelle empreinte, à réapprouver' );
		self::assertSame( $nouvelle, SigningKey::public_key() );
		self::assertSame( $nouvelle, $cle->pair()['public'] ?? null );
		self::assertSame( self::NOW + 10, $this->options[ SigningKey::OPTION ]['created_at'] );
	}

	public function test_moteur_ed25519_absent_la_cle_enregistree_n_est_pas_touchee(): void {
		( new SigningKey( new Sodium() ) )->ensure( self::NOW );
		$avant       = $this->options[ SigningKey::OPTION ];
		$sans_moteur = new SigningKey( new Sodium( static fn ( string $nom ): bool => false, static fn (): bool => false ) );

		$refus = self::refus( fn () => $sans_moteur->ensure( self::NOW + 1 ) );

		self::assertSame( BackupRefused::CRYPTO_UNAVAILABLE, $refus->reason_code() );
		self::assertSame( $avant, $this->options[ SigningKey::OPTION ] );
	}
}
