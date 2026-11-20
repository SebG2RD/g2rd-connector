<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use G2RD\Connector\Rollback\Core\CorePackage;
use G2RD\Connector\Rollback\Core\CoreRollbackRefused;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Paquet d'une version publiée, repris sur wordpress.org (§ 4.3) : adresse construite sur un hôte
 * fixe, version et langue strictes, repli `en_US`, SHA-1 publié vérifié, empreintes lues AVANT tout
 * téléchargement, rien laissé sur le disque après un refus.
 */
final class CorePackageTest extends CoreRollbackTestCase {

	public function test_adresses_des_paquets_par_langue(): void {
		self::assertSame( 'https://downloads.wordpress.org/release/wordpress-7.1.4.zip', CorePackage::url( '7.1.4', 'en_US' ) );
		self::assertSame( 'https://downloads.wordpress.org/release/fr_FR/wordpress-7.1.4.zip', CorePackage::url( '7.1.4', 'fr_FR' ) );
		self::assertSame( 'https://downloads.wordpress.org/release/de_DE_formal/wordpress-6.4.zip', CorePackage::url( '6.4', 'de_DE_formal' ) );
		foreach ( [ 'en_US', 'fr_FR', 'pt_PT_ao90', 'ary' ] as $locale ) {
			self::assertSame( 'downloads.wordpress.org', parse_url( CorePackage::url( '7.1.4', $locale ), PHP_URL_HOST ), $locale );
		}
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function malformed(): iterable {
		yield 'version de développement' => [ '7.2-beta1', 'en_US' ];
		yield 'version des sources' => [ '7.1.5-src', 'en_US' ];
		yield 'version à quatre nombres' => [ '7.1.4.1', 'en_US' ];
		yield 'version qui remonte' => [ '../7.1', 'en_US' ];
		yield 'version vide' => [ '', 'en_US' ];
		yield 'langue qui remonte' => [ '7.1.4', 'fr_FR/../../evil' ];
		yield 'langue avec un hôte' => [ '7.1.4', 'evil.example' ];
		yield 'langue en tiret' => [ '7.1.4', 'en-US' ];
		yield 'langue vide' => [ '7.1.4', '' ];
	}

	#[DataProvider( 'malformed' )]
	public function test_version_et_langue_strictes( string $version, string $locale ): void {
		$this->expectException( \InvalidArgumentException::class );

		CorePackage::url( $version, $locale );
	}

	public function test_langue_du_paquet_installe(): void {
		self::assertSame( 'en_US', CorePackage::installed_locale() );
		$GLOBALS['wp_local_package'] = 'fr_FR';
		self::assertSame( 'fr_FR', CorePackage::installed_locale() );
		$GLOBALS['wp_local_package'] = 'fr_FR/../x';
		self::assertSame( 'en_US', CorePackage::installed_locale() );
	}

	public function test_paquet_verifie_et_empreintes_lues_avant_le_telechargement(): void {
		$package = CorePackage::fetch( '7.1.4', 'en_US' );

		self::assertSame( 'en_US', $package['locale'] );
		self::assertSame( 'paquet-wordpress', file_get_contents( $package['path'] ) );
		self::assertSame( md5( self::FILES['wp-settings.php']['7.1.4'] ), $package['checksums']['wp-settings.php'] );
		self::assertSame(
			[
				'https://downloads.wordpress.org/release/wordpress-7.1.4.zip.sha1',
				'https://api.wordpress.org/core/checksums/1.0/?version=7.1.4&locale=en_US',
				'https://downloads.wordpress.org/release/wordpress-7.1.4.zip',
			],
			$this->requested
		);
	}

	public function test_paquet_de_langue_absent_repli_anglais(): void {
		$this->wporg['https://downloads.wordpress.org/release/fr_FR/wordpress-7.1.4.zip.sha1'] = [
			'code' => 404,
			'body' => '',
		];

		$package = CorePackage::fetch( '7.1.4', 'fr_FR' );

		self::assertSame( 'en_US', $package['locale'] );
		self::assertSame( 'https://downloads.wordpress.org/release/fr_FR/wordpress-7.1.4.zip.sha1', $this->requested[0] );
		self::assertSame( 'https://downloads.wordpress.org/release/wordpress-7.1.4.zip', end( $this->requested ) );
	}

	public function test_paquet_de_langue_publie(): void {
		$this->publish( '7.1.4', 'fr_FR' );

		self::assertSame( 'fr_FR', CorePackage::fetch( '7.1.4', 'fr_FR' )['locale'] );
	}

	/**
	 * @return iterable<string, array{\Closure(self): void, string}>
	 */
	public static function refusals(): iterable {
		yield 'wordpress.org injoignable' => [
			static function ( self $test ): void {
				$test->wporg = [];
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'paquet introuvable' => [
			static function ( self $test ): void {
				$test->wporg['https://downloads.wordpress.org/release/wordpress-7.1.4.zip.sha1']['code'] = 404;
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'SHA-1 publié illisible' => [
			static function ( self $test ): void {
				$test->wporg['https://downloads.wordpress.org/release/wordpress-7.1.4.zip.sha1']['body'] = '<html>challenge</html>';
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'empreintes absentes' => [
			static function ( self $test ): void {
				$test->wporg['https://api.wordpress.org/core/checksums/1.0/?version=7.1.4&locale=en_US']['body'] = '{"checksums":false}';
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'empreinte mal formée' => [
			static function ( self $test ): void {
				$test->wporg['https://api.wordpress.org/core/checksums/1.0/?version=7.1.4&locale=en_US']['body'] = '{"checksums":{"wp-settings.php":"zz"}}';
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'téléchargement refusé' => [
			static function ( self $test ): void {
				$test->wporg['https://downloads.wordpress.org/release/wordpress-7.1.4.zip']['code'] = 404;
			},
			CoreRollbackRefused::WPORG_UNREACHABLE,
		];
		yield 'paquet différent de son SHA-1' => [
			static function ( self $test ): void {
				$test->zip = 'paquet-modifié';
			},
			CoreRollbackRefused::INTEGRITY,
		];
	}

	#[DataProvider( 'refusals' )]
	public function test_refus_sans_rien_laisser_sur_le_disque( \Closure $setup, string $code ): void {
		$setup( $this );

		try {
			CorePackage::fetch( '7.1.4', 'en_US' );
			self::fail( 'refus attendu : ' . $code );
		} catch ( CoreRollbackRefused $refused ) {
			self::assertSame( $code, $refused->error_code() );
		}
		foreach ( $this->downloads as $file ) {
			self::assertFileDoesNotExist( $file );
		}
	}

	public function test_empreintes_des_fichiers_installes(): void {
		$this->install( '7.1.4' );
		$checksums = CorePackage::fetch( '7.1.4', 'en_US' )['checksums'];

		self::assertSame( [], CorePackage::mismatched_files( $checksums, ABSPATH ), 'wp-content est ignoré' );

		file_put_contents( ABSPATH . 'wp-settings.php', "<?php // modifié\n" );
		unlink( ABSPATH . 'wp-includes/version.php' );
		self::assertSame( [ 'wp-settings.php', 'wp-includes/version.php' ], CorePackage::mismatched_files( $checksums, ABSPATH ) );
		self::assertSame( [ '../wp-config.php' ], CorePackage::mismatched_files( [ '../wp-config.php' => md5( '' ) ], ABSPATH ), 'jamais lu' );

		// Retirés par une extension de sécurité après la copie (All-In-One Security) : jamais un échec.
		$extras = [
			'readme.html'          => md5( 'readme' ),
			'license.txt'          => md5( 'license' ),
			'wp-config-sample.php' => md5( 'sample' ),
		];
		self::assertSame( [], CorePackage::mismatched_files( $extras, ABSPATH ), 'absents tolérés' );
		file_put_contents( ABSPATH . 'wp-config-sample.php', "<?php // modifié\n" );
		file_put_contents( ABSPATH . 'readme.html', '<p>modifié</p>' );
		try {
			// readme.html présent mais modifié : jamais contrôlé (comme update_core()) ; wp-config-sample.php l'est.
			self::assertSame( [ 'wp-config-sample.php' ], CorePackage::mismatched_files( $extras, ABSPATH ), 'présent mais modifié' );
		} finally {
			unlink( ABSPATH . 'wp-config-sample.php' );
			unlink( ABSPATH . 'readme.html' );
		}
	}
}
