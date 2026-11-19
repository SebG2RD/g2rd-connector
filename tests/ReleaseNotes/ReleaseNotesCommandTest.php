<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use Brain\Monkey\Functions;
use G2RD\Connector\ReleaseNotes\HttpLimits;
use G2RD\Connector\ReleaseNotes\ReleaseNotesCommand;
use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Commande `get_release_notes` : composant cherché parmi les installés, provenance vue comme par
 * l'inventaire (capture rejouée en lecture seule), lu comme le lien « Afficher les détails de la
 * version » (plugins_api() / themes_api() avec les sections), appels bornés, wordpress.org jamais
 * interrogé pour un composant qui n'en vient pas, aucune écriture par le code de la commande.
 */
final class ReleaseNotesCommandTest extends ReleaseNotesTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->transients['update_plugins'] = self::update_plugins();
		$this->transients['update_themes']  = self::update_themes();
	}

	protected function command(): ReleaseNotesCommand {
		return new ReleaseNotesCommand( new HttpLimits( static fn (): float => 1000.0 ) );
	}

	public function test_les_notes_d_une_extension_d_editeur(): void {
		$this->vendor_answer = self::acf_answer();

		$result = $this->command()->run(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);

		self::assertSame(
			[
				'notes_html' => '<h4>6.8.11</h4><ul><li>Fix: field groups.</li></ul>',
				'truncated'  => false,
				'version'    => '6.8.11',
				'homepage'   => 'https://www.advancedcustomfields.com/',
			],
			$result
		);
		self::assertSame(
			[
				[
					'plugins_api',
					'plugin_information',
					[
						'slug'   => 'advanced-custom-fields-pro',
						'fields' => [ 'sections' => true ],
					],
				],
			],
			$this->api_calls
		);
		self::assertSame( 0, $this->wporg_calls );
	}

	public function test_garde_et_bornes_poses_pendant_la_lecture_puis_retires(): void {
		$this->vendor_answer = self::acf_answer();

		$this->command()->run(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);

		self::assertSame(
			[
				'guard'  => true,
				'limits' => true,
			],
			$this->during
		);
		$this->assert_nothing_left();
	}

	public function test_sans_reponse_de_l_editeur_wordpress_org_n_est_jamais_interroge(): void {
		$this->wporg_answer = self::acf_answer(); // Un homonyme sur wordpress.org.

		self::assertSame(
			ReleaseNotesRefused::NOT_PROVIDED,
			$this->refusal_of(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			)
		);
		self::assertSame( 0, $this->wporg_calls );
		$this->assert_nothing_left();
	}

	public function test_le_slug_est_celui_de_l_entree_de_mise_a_jour(): void {
		$this->transients['update_plugins']->response[ self::ACF ]->slug = 'acf-pro-entree';
		$this->vendor_answer = self::acf_answer();

		$this->command()->run(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);

		self::assertSame( 'acf-pro-entree', $this->api_calls[0][2]['slug'] );
	}

	public function test_sans_entree_le_slug_est_le_dossier_et_la_provenance_inconnue_garde_wordpress_org_a_l_ecart(): void {
		unset( $this->transients['update_plugins'] );
		$this->wporg_answer = self::acf_answer();

		self::assertSame(
			ReleaseNotesRefused::NOT_PROVIDED,
			$this->refusal_of(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			)
		);
		self::assertSame( 'advanced-custom-fields-pro', $this->api_calls[0][2]['slug'] );
		self::assertSame( 0, $this->wporg_calls );
	}

	public function test_une_extension_d_un_seul_fichier_prend_son_nom_sans_php(): void {
		self::assertSame(
			ReleaseNotesRefused::NOT_PROVIDED,
			$this->refusal_of(
				[
					'kind' => 'plugin',
					'file' => 'hello.php',
				]
			)
		);
		self::assertSame( 'hello', $this->api_calls[0][2]['slug'] );
	}

	/**
	 * Updater absent du contexte REST : `response` n'a pas son entrée, `no_update` a celle d'un
	 * homonyme de wordpress.org. Sans la capture, l'homonyme décide (témoin) ; avec elle, la
	 * provenance est celle de l'inventaire (`vendor`) et l'homonyme n'est pas lu.
	 */
	public function test_un_updater_absent_en_rest_la_capture_decide_et_l_homonyme_n_est_pas_lu(): void {
		$fichier = WP_PLUGIN_DIR . '/' . self::ACF;
		if ( ! is_dir( dirname( $fichier ) ) ) {
			mkdir( dirname( $fichier ), 0777, true );
		}
		file_put_contents( $fichier, "<?php\n/* Plugin Name: test */\n" );
		Functions\when( 'get_plugin_data' )->justReturn( [ 'Version' => '6.8.10' ] );
		$this->transients['update_plugins'] = (object) [
			'last_checked' => 1789000000,
			'response'     => [],
			'no_update'    => [
				self::ACF => (object) [
					'id'          => 'w.org/plugins/advanced-custom-fields-pro',
					'slug'        => 'advanced-custom-fields-pro',
					'new_version' => '1.0',
					'url'         => 'https://wordpress.org/plugins/advanced-custom-fields-pro/',
					'package'     => 'https://downloads.wordpress.org/plugin/advanced-custom-fields-pro.1.0.zip',
				],
			],
		];
		$this->wporg_answer = (object) [
			'version'  => '1.0',
			'sections' => [ 'changelog' => '<p>Homonyme.</p>' ],
		];

		try {
			$this->command()->run(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			);
			self::assertSame( 1, $this->wporg_calls, 'Témoin : sans capture, l’homonyme décide.' );

			$this->wporg_calls = 0;
			$this->capture     = [
				'plugins'     => [ self::ACF => self::acf_entry() ],
				'themes'      => [],
				'captured_at' => gmdate( 'c', time() - 3600 ),
			];

			self::assertSame(
				ReleaseNotesRefused::NOT_PROVIDED,
				$this->refusal_of(
					[
						'kind' => 'plugin',
						'file' => self::ACF,
					]
				)
			);
			self::assertSame( 0, $this->wporg_calls );
			self::assertTrue( $this->during['guard'] ?? null );
		} finally {
			unlink( $fichier );
			rmdir( dirname( $fichier ) );
		}
	}

	/**
	 * Même cas pour un thème : la capture rejouée décide, l'homonyme de wordpress.org n'est pas lu.
	 *
	 * Processus séparé : simuler get_file_data() la définit pour toute la suite, et
	 * SnapshotController::opcache_disk_version() teste function_exists() (cf.
	 * PremiumUpdatesBridgeReplayEntryTest).
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_un_theme_rejoue_depuis_la_capture_n_est_pas_lu_sur_wordpress_org(): void {
		$dossier = WP_CONTENT_DIR . '/themes/premium-pro';
		if ( ! is_dir( $dossier ) ) {
			mkdir( $dossier, 0777, true );
		}
		file_put_contents( $dossier . '/style.css', "/* Theme Name: Premium Pro */\n" );
		Functions\when( 'wp_get_theme' )->alias( static fn ( string $stylesheet ): \WP_Theme => new \WP_Theme( $stylesheet, [], 'premium-pro' === $stylesheet, $dossier ) );
		Functions\when( 'get_file_data' )->justReturn( [ 'Version' => '2.0' ] );
		$this->transients['update_themes'] = (object) [
			'last_checked' => 1789000000,
			'response'     => [],
			'no_update'    => [
				'premium-pro' => [
					'theme'       => 'premium-pro',
					'new_version' => '1.0',
					'url'         => 'https://wordpress.org/themes/premium-pro/',
					'package'     => 'https://downloads.wordpress.org/theme/premium-pro.1.0.zip',
				],
			],
		];
		$this->wporg_answer = (object) [
			'version'  => '1.0',
			'sections' => [ 'changelog' => '<p>Homonyme.</p>' ],
		];

		try {
			$this->command()->run(
				[
					'kind'       => 'theme',
					'stylesheet' => 'premium-pro',
				]
			);
			self::assertSame( 1, $this->wporg_calls, 'Témoin : sans capture, l’homonyme décide.' );

			$this->wporg_calls   = 0;
			$this->capture       = [
				'plugins'     => [],
				'themes'      => [ 'premium-pro' => self::update_themes()->response['premium-pro'] ],
				'captured_at' => gmdate( 'c', time() - 3600 ),
			];
			$this->vendor_answer = (object) [
				'version'  => '2.1',
				'sections' => [ 'changelog' => '<p>2.1</p>' ],
			];

			$result = $this->command()->run(
				[
					'kind'       => 'theme',
					'stylesheet' => 'premium-pro',
				]
			);

			self::assertSame( '<p>2.1</p>', $result['notes_html'] );
			self::assertSame( 0, $this->wporg_calls );
			self::assertTrue( $this->during['guard'] ?? null );
		} finally {
			unlink( $dossier . '/style.css' );
			rmdir( $dossier );
		}
	}

	public function test_une_extension_de_wordpress_org_est_lue_sur_wordpress_org(): void {
		$this->wporg_answer = (object) [
			'version'  => '5.4',
			'homepage' => 'https://akismet.com/',
			'sections' => [ 'changelog' => '<h4>5.4</h4><p>Fix.</p>' ],
		];

		$result = $this->command()->run(
			[
				'kind' => 'plugin',
				'file' => 'akismet/akismet.php',
			]
		);

		self::assertSame( '<h4>5.4</h4><p>Fix.</p>', $result['notes_html'] );
		self::assertSame( 1, $this->wporg_calls );
		self::assertSame(
			[
				'guard'  => false,
				'limits' => true,
			],
			$this->during
		);
	}

	public function test_les_notes_d_un_theme_d_editeur(): void {
		$this->vendor_answer = (object) [
			'version'  => '2.1',
			'homepage' => 'http://premium.example/',
			'sections' => [ 'changelog' => '<p>2.1</p>' ],
		];

		$result = $this->command()->run(
			[
				'kind'       => 'theme',
				'stylesheet' => 'premium-pro',
			]
		);

		self::assertSame(
			[
				'notes_html' => '<p>2.1</p>',
				'truncated'  => false,
				'version'    => '2.1',
				'homepage'   => null,
			],
			$result
		);
		self::assertSame(
			[
				[
					'themes_api',
					'theme_information',
					[
						'slug'   => 'premium-pro',
						'fields' => [ 'sections' => true ],
					],
				],
			],
			$this->api_calls
		);
		self::assertTrue( $this->during['guard'] ?? null );
	}

	public function test_un_theme_de_wordpress_org_sans_notes(): void {
		$this->wporg_answer = (object) [
			'version'  => '1.3',
			'sections' => [ 'description' => '<p>Theme</p>' ],
		];

		self::assertSame(
			ReleaseNotesRefused::NOT_PROVIDED,
			$this->refusal_of(
				[
					'kind'       => 'theme',
					'stylesheet' => 'twentytwentyfive',
				]
			)
		);
		self::assertSame( 1, $this->wporg_calls );
	}

	public function test_une_erreur_de_la_source_refus_source_unavailable(): void {
		$this->vendor_answer = new \WP_Error( 'plugins_api_failed', 'An unexpected error occurred.' );

		self::assertSame(
			ReleaseNotesRefused::SOURCE_UNAVAILABLE,
			$this->refusal_of(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			)
		);
	}

	public function test_une_exception_de_l_editeur_refus_source_unavailable_et_rien_ne_reste(): void {
		$this->vendor_answer = new \RuntimeException( 'licence server down' );

		self::assertSame(
			ReleaseNotesRefused::SOURCE_UNAVAILABLE,
			$this->refusal_of(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			)
		);
		$this->assert_nothing_left();
	}

	public function test_le_message_de_l_editeur_n_est_jamais_repris(): void {
		$this->vendor_answer = new \WP_Error( 'licence', 'Licence ABC-123 expired for client.example' );

		try {
			$this->command()->run(
				[
					'kind' => 'plugin',
					'file' => self::ACF,
				]
			);
			self::fail( 'Refus attendu.' );
		} catch ( ReleaseNotesRefused $e ) {
			self::assertStringNotContainsString( 'ABC-123', $e->getMessage() );
			self::assertStringNotContainsString( 'client.example', $e->getMessage() );
		}
	}

	/**
	 * @return iterable<string, array{array<mixed>}>
	 */
	public static function demandes_invalides(): iterable {
		yield 'vide' => [ [] ];
		yield 'type inconnu' => [ [ 'kind' => 'core' ] ];
		yield 'type en capitales' => [
			[
				'kind' => 'PLUGIN',
				'file' => 'advanced-custom-fields-pro/acf.php',
			],
		];
		yield 'extension sans fichier' => [ [ 'kind' => 'plugin' ] ];
		yield 'fichier vide' => [
			[
				'kind' => 'plugin',
				'file' => '',
			],
		];
		yield 'fichier qui n’est pas un texte' => [
			[
				'kind' => 'plugin',
				'file' => [ 'akismet/akismet.php' ],
			],
		];
		yield 'extension avec la clé d’un thème' => [
			[
				'kind'       => 'plugin',
				'stylesheet' => 'premium-pro',
			],
		];
		yield 'thème sans dossier' => [ [ 'kind' => 'theme' ] ];
		yield 'dossier qui n’est pas un texte' => [
			[
				'kind'       => 'theme',
				'stylesheet' => 5,
			],
		];
	}

	/**
	 * @param array<mixed> $payload
	 */
	#[DataProvider( 'demandes_invalides' )]
	public function test_une_demande_invalide_est_refusee_sans_rien_lire( array $payload ): void {
		self::assertSame( ReleaseNotesRefused::INVALID_REQUEST, $this->refusal_of( $payload ) );
		self::assertSame( [], $this->api_calls );
	}

	/**
	 * @return iterable<string, array{array<mixed>}>
	 */
	public static function composants_absents(): iterable {
		yield 'chemin hors des extensions' => [
			[
				'kind' => 'plugin',
				'file' => '../../wp-config.php',
			],
		];
		yield 'dossier de thème pris pour une extension' => [
			[
				'kind' => 'plugin',
				'file' => 'premium-pro',
			],
		];
		yield 'chemin hors des thèmes' => [
			[
				'kind'       => 'theme',
				'stylesheet' => '../uploads',
			],
		];
		yield 'fichier d’extension pris pour un thème' => [
			[
				'kind'       => 'theme',
				'stylesheet' => 'advanced-custom-fields-pro/acf.php',
			],
		];
	}

	/**
	 * @param array<mixed> $payload
	 */
	#[DataProvider( 'composants_absents' )]
	public function test_un_composant_absent_est_refuse_sans_rien_lire( array $payload ): void {
		self::assertSame( ReleaseNotesRefused::NOT_INSTALLED, $this->refusal_of( $payload ) );
		self::assertSame( [], $this->api_calls );
	}

	public function test_rien_n_est_ecrit_ni_en_cas_de_succes_ni_en_cas_de_refus(): void {
		$ecritures = [];
		foreach ( [ 'update_option', 'add_option', 'delete_option', 'update_site_option', 'add_site_option', 'delete_site_option', 'set_transient', 'delete_transient', 'set_site_transient', 'delete_site_transient', 'wp_cache_set', 'wp_cache_add', 'wp_cache_delete', 'wp_cache_flush', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_unschedule_event' ] as $fonction ) {
			Functions\when( $fonction )->alias(
				static function () use ( $fonction, &$ecritures ): bool {
					$ecritures[] = $fonction;
					return true;
				}
			);
		}
		$options = $this->options;

		$this->vendor_answer = self::acf_answer();
		$this->command()->run(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);
		$this->vendor_answer = false;
		$this->refusal_of(
			[
				'kind' => 'plugin',
				'file' => self::ACF,
			]
		);
		$this->refusal_of(
			[
				'kind' => 'plugin',
				'file' => 'akismet/akismet.php',
			]
		);
		$this->refusal_of(
			[
				'kind'       => 'theme',
				'stylesheet' => '../uploads',
			]
		);

		self::assertSame( [], $ecritures );
		self::assertSame( $options, $this->options );
	}
}
