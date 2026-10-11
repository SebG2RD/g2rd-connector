<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;

/**
 * Origine réelle des thèmes : `wporg` vaut vrai seulement si wordpress.org connaît le
 * thème, d'après sa dernière réponse rangée dans le transient `update_themes`
 * (entrées `response` et `no_update`, wp_update_themes()). Aucun appel réseau de plus.
 * Le manager lit ce champ et garde son ancien calcul quand il est absent.
 *
 * Banc d'essai : Twenty Twenty-Five (wordpress.org), un thème enfant (actif), un
 * thème premium.
 */
final class SnapshotThemeOriginTest extends SnapshotTestCase {

	public function test_un_theme_connu_de_wordpress_org_vaut_vrai(): void {
		$this->update_transients_checked( 3600 );

		self::assertTrue( $this->themes()['twentytwentyfive']['wporg'] );
	}

	/** Mise à jour disponible : wordpress.org range le thème dans `response`. */
	public function test_un_theme_wordpress_org_a_mettre_a_jour_vaut_vrai(): void {
		$this->wporg_themes = [
			'response'  => [ 'twentytwentyfive' => self::wporg_theme( 'twentytwentyfive', '1.4' ) ],
			'no_update' => [],
		];
		$this->update_transients_checked( 3600 );

		$theme = $this->themes()['twentytwentyfive'];

		self::assertTrue( $theme['wporg'] );
		self::assertTrue( $theme['has_update'] );
	}

	public function test_un_theme_enfant_vaut_faux(): void {
		$this->update_transients_checked( 3600 );

		self::assertFalse( $this->themes()['g2rd-child']['wporg'] );
	}

	/**
	 * Un updater tiers range sa mise à jour dans `response`, comme wordpress.org : c'est
	 * l'adresse du paquet et de la fiche qui distingue les deux.
	 */
	public function test_un_theme_premium_annonce_par_son_propre_updater_vaut_faux(): void {
		$this->wporg_themes['response'] = [
			'premium-pro' => [
				'theme'       => 'premium-pro',
				'new_version' => '2.1',
				'url'         => 'https://premium.example/changelog/',
				'package'     => 'https://premium.example/download/premium-pro.zip?licence=abc',
			],
		];
		$this->update_transients_checked( 3600 );

		$theme = $this->themes()['premium-pro'];

		self::assertFalse( $theme['wporg'] );
		self::assertTrue( $theme['has_update'], 'La mise à jour tierce reste annoncée.' );
	}

	/** Un updater tiers qui pose sa propre adresse de paquet sur un thème homonyme ne passe pas pour wordpress.org. */
	public function test_une_entree_dont_le_paquet_vient_d_ailleurs_vaut_faux(): void {
		$entry                          = self::wporg_theme( 'twentytwentyfive', '1.4' );
		$entry['package']               = 'https://premium.example/twentytwentyfive.zip';
		$this->wporg_themes['response'] = [ 'twentytwentyfive' => $entry ];
		$this->wporg_themes['no_update'] = [];
		$this->update_transients_checked( 3600 );

		self::assertFalse( $this->themes()['twentytwentyfive']['wporg'] );
	}

	/**
	 * Seuls wordpress.org et ses sous-domaines comptent, pas un domaine qui leur
	 * ressemble : ni un domaine qui finit par « wordpress.org » sans en être un
	 * sous-domaine, ni un domaine qui commence par « wordpress.org ».
	 */
	public function test_un_domaine_qui_ressemble_a_wordpress_org_vaut_faux(): void {
		foreach (
			[
				'package' => 'https://notwordpress.org/twentytwentyfive.zip',
				'url'     => 'https://wordpress.org.premium.example/themes/twentytwentyfive/',
			] as $key => $address
		) {
			$entry                           = self::wporg_theme( 'twentytwentyfive', '1.3' );
			$entry[ $key ]                   = $address;
			$this->wporg_themes['no_update'] = [ 'twentytwentyfive' => $entry ];
			$this->update_transients_checked( 3600 );

			self::assertFalse( $this->themes()['twentytwentyfive']['wporg'], $address );
		}
	}

	/**
	 * wordpress.org injoignable : le transient n'a pas sa réponse (`no_update` absent).
	 * Le champ est omis plutôt que faux pour tous : la plateforme garde son ancien calcul.
	 */
	public function test_sans_reponse_de_wordpress_org_le_champ_est_absent(): void {
		Functions\when( 'wp_update_themes' )->alias(
			function (): void {
				$this->transients['update_themes'] = (object) [ 'last_checked' => time() ];
			}
		);

		foreach ( $this->themes() as $stylesheet => $theme ) {
			self::assertArrayNotHasKey( 'wporg', $theme, $stylesheet );
		}
	}

	public function test_aucun_appel_reseau_supplementaire(): void {
		$this->update_transients_checked( 3600 );
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'themes_api' )->never();

		$this->themes();

		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Thèmes de l'inventaire, par feuille de style.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function themes(): array {
		return array_column( $this->snapshot()['themes'], null, 'stylesheet' );
	}
}
