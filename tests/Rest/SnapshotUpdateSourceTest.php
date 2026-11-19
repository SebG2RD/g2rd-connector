<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;

/**
 * Provenance des mises à jour dans l'inventaire (1.18.0) : chaque extension et chaque thème porte
 * `update_source` (`wporg`, `vendor` ou null), lu dans la dernière vérification rangée par
 * WordPress (entrées `response` puis `no_update`, après le rejeu de la capture), sans appel réseau.
 * Le manager s'en sert pour choisir la source des notes de version et classer les extensions
 * premium (étape 3). Clé ajoutée, rien de retiré.
 *
 * Banc d'essai (SnapshotTestCase) : Akismet et SEOPress PRO ; Twenty Twenty-Five, un thème enfant,
 * un thème premium.
 */
final class SnapshotUpdateSourceTest extends SnapshotTestCase {

	private const SEOPRESS = 'wp-seopress-pro/seopress-pro.php';

	public function test_chaque_extension_et_chaque_theme_porte_la_cle(): void {
		$this->update_transients_checked( 3600 );
		$snapshot = $this->snapshot();

		foreach ( $snapshot['plugins'] as $plugin ) {
			self::assertArrayHasKey( 'update_source', $plugin, $plugin['file'] );
		}
		foreach ( $snapshot['themes'] as $theme ) {
			self::assertArrayHasKey( 'update_source', $theme, $theme['stylesheet'] );
		}
	}

	public function test_une_extension_sans_entree_vaut_null(): void {
		$this->update_transients_checked( 3600 );

		self::assertNull( self::source( $this->plugins()['akismet/akismet.php'] ) );
	}

	public function test_une_extension_connue_de_wordpress_org_vaut_wporg(): void {
		$this->update_transients_checked( 3600 );
		$this->transients['update_plugins']->no_update = [ 'akismet/akismet.php' => self::wporg_plugin( 'akismet', '5.3' ) ];

		self::assertSame( 'wporg', self::source( $this->plugins()['akismet/akismet.php'] ) );
	}

	public function test_une_extension_wordpress_org_a_mettre_a_jour_vaut_wporg(): void {
		$this->update_transients_checked( 3600 );
		$this->transients['update_plugins']->response = [ 'akismet/akismet.php' => self::wporg_plugin( 'akismet', '5.4' ) ];

		$plugin = $this->plugins()['akismet/akismet.php'];

		self::assertSame( 'wporg', self::source( $plugin ) );
		self::assertTrue( $plugin['has_update'] );
	}

	public function test_une_extension_premium_annoncee_par_son_editeur_vaut_vendor(): void {
		$this->update_transients_checked( 3600 );
		$this->transients['update_plugins']->response = [ self::SEOPRESS => self::seopress_entry() ];

		$plugin = $this->plugins()[ self::SEOPRESS ];

		self::assertSame( 'vendor', self::source( $plugin ) );
		self::assertTrue( $plugin['has_update'], 'La mise à jour de l’éditeur reste annoncée.' );
		self::assertSame( 'wp-seopress-pro', $plugin['slug'], 'Le slug ne change pas.' );
	}

	/** `response` (ce que WordPress installerait) passe avant `no_update`. */
	public function test_l_entree_de_response_passe_avant_celle_de_no_update(): void {
		$this->update_transients_checked( 3600 );
		$this->transients['update_plugins']->response  = [ self::SEOPRESS => self::seopress_entry() ];
		$this->transients['update_plugins']->no_update = [ self::SEOPRESS => self::wporg_plugin( 'wp-seopress-pro', '8.0' ) ];

		self::assertSame( 'vendor', self::source( $this->plugins()[ self::SEOPRESS ] ) );
	}

	public function test_une_entree_qui_se_dit_wordpress_org_avec_un_paquet_d_ailleurs_vaut_vendor(): void {
		$entry          = self::wporg_plugin( 'wp-seopress-pro', '8.1' );
		$entry->package = 'https://www.seopress.org/download/?licence=abc';
		$this->update_transients_checked( 3600 );
		$this->transients['update_plugins']->response = [ self::SEOPRESS => $entry ];

		self::assertSame( 'vendor', self::source( $this->plugins()[ self::SEOPRESS ] ) );
	}

	public function test_les_themes(): void {
		$this->wporg_themes['response'] = [
			'premium-pro' => [
				'theme'       => 'premium-pro',
				'new_version' => '2.1',
				'url'         => 'https://premium.example/changelog/',
				'package'     => 'https://premium.example/download/premium-pro.zip',
			],
		];
		$this->update_transients_checked( 3600 );

		$themes = $this->themes();

		self::assertSame( 'wporg', self::source( $themes['twentytwentyfive'] ) );
		self::assertSame( 'vendor', self::source( $themes['premium-pro'] ) );
		self::assertNull( self::source( $themes['g2rd-child'] ) );
		self::assertTrue( $themes['twentytwentyfive']['wporg'], 'Le champ wporg de la 1.14 ne change pas.' );
		self::assertFalse( $themes['premium-pro']['wporg'] );
	}

	/** Sans réponse de wordpress.org, `wporg` reste omis (1.14) ; la provenance dit ce qui est connu. */
	public function test_sans_reponse_de_wordpress_org_la_provenance_reste_celle_des_entrees(): void {
		Functions\when( 'wp_update_themes' )->alias(
			function (): void {
				$this->transients['update_themes'] = (object) [
					'last_checked' => time(),
					'response'     => [
						'premium-pro' => [
							'theme'       => 'premium-pro',
							'new_version' => '2.1',
							'url'         => 'https://premium.example/changelog/',
							'package'     => 'https://premium.example/download/premium-pro.zip',
						],
					],
				];
			}
		);

		$themes = $this->themes();

		self::assertNull( self::source( $themes['twentytwentyfive'] ) );
		self::assertSame( 'vendor', self::source( $themes['premium-pro'] ) );
		self::assertArrayNotHasKey( 'wporg', $themes['twentytwentyfive'] );
	}

	public function test_aucun_appel_reseau_supplementaire(): void {
		$this->update_transients_checked( 3600 );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'plugins_api' )->never();
		Functions\expect( 'themes_api' )->never();

		$this->snapshot();

		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * `update_source` d'une ligne de l'inventaire ; la clé doit exister (null compris).
	 *
	 * @param array<string, mixed> $row
	 */
	private static function source( array $row ): ?string {
		self::assertArrayHasKey( 'update_source', $row, (string) ( $row['file'] ?? $row['stylesheet'] ?? '?' ) );

		return $row['update_source'];
	}

	/**
	 * Extensions de l'inventaire, par fichier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins(): array {
		return array_column( $this->snapshot()['plugins'], null, 'file' );
	}

	/**
	 * Thèmes de l'inventaire, par feuille de style.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function themes(): array {
		return array_column( $this->snapshot()['themes'], null, 'stylesheet' );
	}

	/** Entrée d'extension telle que wordpress.org la rend (api.wordpress.org/plugins/update-check/1.1/). */
	private static function wporg_plugin( string $slug, string $version ): \stdClass {
		return (object) [
			'id'          => 'w.org/plugins/' . $slug,
			'slug'        => $slug,
			'plugin'      => $slug . '/' . $slug . '.php',
			'new_version' => $version,
			'url'         => 'https://wordpress.org/plugins/' . $slug . '/',
			'package'     => 'https://downloads.wordpress.org/plugin/' . $slug . '.' . $version . '.zip',
		];
	}

	/** Entrée posée par l'updater de SEOPress PRO. */
	private static function seopress_entry(): \stdClass {
		return (object) [
			'slug'        => 'wp-seopress-pro',
			'plugin'      => self::SEOPRESS,
			'new_version' => '8.1',
			'url'         => 'https://www.seopress.org/',
			'package'     => 'https://www.seopress.org/download/?licence=abc',
		];
	}
}
