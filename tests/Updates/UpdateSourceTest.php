<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Updates;

use Brain\Monkey\Functions;
use G2RD\Connector\Tests\TestCase;
use G2RD\Connector\Updates\UpdateSource;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Provenance des mises à jour (1.18.0) : `wporg`, `vendor` ou null, lue dans les entrées `response`
 * puis `no_update` d'un transient de mise à jour, sans appel réseau (spécification « Notes de
 * version », § 5.2).
 */
final class UpdateSourceTest extends TestCase {

	private const FILE = 'akismet/akismet.php';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
	}

	public function test_une_entree_de_wordpress_org_vaut_wporg_dans_response_comme_dans_no_update(): void {
		self::assertSame( UpdateSource::WPORG, UpdateSource::of( self::transient( [ self::FILE => self::wporg_entry() ], [] ), self::FILE ) );
		self::assertSame( UpdateSource::WPORG, UpdateSource::of( self::transient( [], [ self::FILE => self::wporg_entry() ] ), self::FILE ) );
	}

	public function test_un_paquet_de_downloads_wordpress_org_suffit(): void {
		$entry = (object) [
			'new_version' => '5.4',
			'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.4.zip',
		];

		self::assertSame( UpdateSource::WPORG, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	public function test_un_id_w_org_suffit_sans_adresse(): void {
		$entry = (object) [
			'id'          => 'w.org/plugins/akismet',
			'new_version' => '5.4',
			'package'     => '',
		];

		self::assertSame( UpdateSource::WPORG, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	public function test_une_entree_d_editeur_vaut_vendor(): void {
		$entry = (object) [
			'id'          => 'advanced-custom-fields-pro/acf.php',
			'slug'        => 'advanced-custom-fields-pro',
			'new_version' => '6.8.11',
			'url'         => 'https://www.advancedcustomfields.com/',
			'package'     => 'https://connect.advancedcustomfields.com/v2/plugins/download?p=pro',
		];

		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	/** Un updater tiers qui se dit wordpress.org sans y prendre son paquet ne passe pas pour lui. */
	public function test_un_id_w_org_avec_un_paquet_d_ailleurs_vaut_vendor(): void {
		$entry          = self::wporg_entry();
		$entry->package = 'https://premium.example/akismet.zip';

		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	public function test_une_entree_sans_aucune_preuve_vaut_vendor(): void {
		$entry = (object) [
			'slug'        => 'akismet',
			'new_version' => '5.4',
		];

		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function adresses_qui_ressemblent(): iterable {
		yield 'domaine qui finit par wordpress.org' => [ 'package', 'https://notwordpress.org/akismet.zip' ];
		yield 'domaine qui commence par wordpress.org' => [ 'url', 'https://wordpress.org.premium.example/plugins/akismet/' ];
		yield 'identifiants devant l’hôte' => [ 'package', 'https://wordpress.org@premium.example/akismet.zip' ];
	}

	#[DataProvider( 'adresses_qui_ressemblent' )]
	public function test_une_adresse_qui_ressemble_a_wordpress_org_vaut_vendor( string $key, string $address ): void {
		$entry         = self::wporg_entry();
		$entry->{$key} = $address;

		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( self::transient( [ self::FILE => $entry ], [] ), self::FILE ) );
	}

	/** `response` (ce que WordPress installerait) passe avant `no_update`. */
	public function test_response_passe_avant_no_update(): void {
		$editeur = (object) [
			'new_version' => '5.4',
			'package'     => 'https://premium.example/akismet.zip',
		];

		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( self::transient( [ self::FILE => $editeur ], [ self::FILE => self::wporg_entry() ] ), self::FILE ) );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function transients_sans_entree(): iterable {
		yield 'transient absent' => [ false ];
		yield 'pas un objet' => [ 'update_plugins' ];
		yield 'sans listes' => [ (object) [ 'last_checked' => 1 ] ];
		yield 'listes qui ne sont pas des tableaux' => [
			(object) [
				'response'  => 'x',
				'no_update' => 3,
			],
		];
		yield 'autre extension seulement' => [
			(object) [
				'response'  => [ 'hello.php' => (object) [ 'new_version' => '1.0' ] ],
				'no_update' => [],
			],
		];
	}

	#[DataProvider( 'transients_sans_entree' )]
	public function test_sans_entree_la_provenance_est_inconnue( mixed $transient ): void {
		self::assertNull( UpdateSource::of( $transient, self::FILE ) );
		self::assertNull( UpdateSource::entry( $transient, self::FILE ) );
	}

	public function test_une_entree_illisible_est_ignoree(): void {
		self::assertSame( UpdateSource::WPORG, UpdateSource::of( self::transient( [ self::FILE => 'cassée' ], [ self::FILE => self::wporg_entry() ] ), self::FILE ) );
	}

	public function test_les_entrees_de_themes_sont_des_tableaux(): void {
		$wporg     = [
			'theme'       => 'twentytwentyfive',
			'new_version' => '1.3',
			'url'         => 'https://wordpress.org/themes/twentytwentyfive/',
			'package'     => 'https://downloads.wordpress.org/theme/twentytwentyfive.1.3.zip',
		];
		$premium   = [
			'theme'       => 'premium-pro',
			'new_version' => '2.1',
			'url'         => 'https://premium.example/changelog/',
			'package'     => 'https://premium.example/download/premium-pro.zip',
		];
		$transient = self::transient( [ 'premium-pro' => $premium ], [ 'twentytwentyfive' => $wporg ] );

		self::assertSame( UpdateSource::WPORG, UpdateSource::of( $transient, 'twentytwentyfive' ) );
		self::assertSame( UpdateSource::VENDOR, UpdateSource::of( $transient, 'premium-pro' ) );
		self::assertSame( $premium, UpdateSource::entry( $transient, 'premium-pro' ) );
	}

	public function test_entry_rend_une_entree_d_extension_en_tableau(): void {
		$entry = UpdateSource::entry( self::transient( [], [ self::FILE => self::wporg_entry() ] ), self::FILE );

		self::assertIsArray( $entry );
		self::assertSame( 'akismet', $entry['slug'] ?? null );
	}

	/** Entrée d'extension telle que wordpress.org la rend (api.wordpress.org/plugins/update-check/1.1/). */
	private static function wporg_entry(): \stdClass {
		return (object) [
			'id'          => 'w.org/plugins/akismet',
			'slug'        => 'akismet',
			'plugin'      => self::FILE,
			'new_version' => '5.3',
			'url'         => 'https://wordpress.org/plugins/akismet/',
			'package'     => 'https://downloads.wordpress.org/plugin/akismet.5.3.zip',
		];
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $no_update
	 */
	private static function transient( array $response, array $no_update ): \stdClass {
		return (object) [
			'last_checked' => 1789000000,
			'response'     => $response,
			'no_update'    => $no_update,
		];
	}
}
