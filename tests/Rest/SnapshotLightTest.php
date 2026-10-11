<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Rest\DbHealth;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Updates\PremiumUpdatesBridge;
use G2RD\Connector\Updates\WporgAnswers;

/**
 * Inventaire plus léger, sans perdre la vérité.
 *
 * Avant : chaque synchronisation vidait TOUT le cache objet du site
 * (wp_cache_flush()), forçait trois appels à wordpress.org (wp_version_check forcé,
 * wp_update_plugins, wp_update_themes, transients détruits d'abord) et relançait
 * cinq requêtes de santé de la base, dont un SHOW TABLE STATUS.
 */
final class SnapshotLightTest extends SnapshotTestCase {

	private const FRESH = 3600;

	private const STALE = 3 * 3600 + 60;

	// ─── Cache objet ─────────────────────────────────────────────────────────

	public function test_l_inventaire_ne_vide_plus_tout_le_cache_objet(): void {
		$this->update_transients_checked( self::FRESH );

		$this->snapshot();

		self::assertSame( 0, $this->calls_to( 'wp_cache_flush' ) );
		self::assertContains( [ 'plugins', 'plugins' ], $this->calls['wp_cache_delete'] ?? [], 'Liste des extensions (get_plugins()) relue sur le disque.' );
		self::assertContains( [ 'alloptions', 'options' ], $this->calls['wp_cache_delete'] ?? [], 'Options autochargées (active_plugins, stylesheet…) relues en base.' );
		self::assertSame( [ [ false ] ], $this->calls['wp_clean_themes_cache'] ?? [], 'Cache des thèmes, sans détruire update_themes.' );
	}

	// ─── Appels à wordpress.org ──────────────────────────────────────────────

	public function test_le_seuil_de_fraicheur_est_de_trois_heures(): void {
		self::assertSame( 3 * 3600, PremiumUpdatesBridge::UPDATE_CHECK_MAX_AGE );
	}

	public function test_transients_frais_aucun_appel_a_wordpress_org(): void {
		$this->update_transients_checked( self::FRESH );

		$this->snapshot();

		self::assertSame( 0, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
		self::assertSame( 0, $this->calls_to( 'delete_site_transient' ), 'Des données vraies ne sont pas détruites.' );
	}

	public function test_transients_perimes_un_appel_chacun(): void {
		$this->update_transients_checked( self::STALE );

		$this->snapshot();

		self::assertSame( [ [ [], true ] ], $this->calls['wp_version_check'] ?? [], 'Vérification du cœur forcée.' );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	public function test_transients_absents_un_appel_chacun(): void {
		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	public function test_seul_le_transient_perime_est_rafraichi(): void {
		$this->update_transients_checked( self::FRESH );
		$this->transients['update_plugins']->last_checked = time() - self::STALE;

		$this->snapshot();

		self::assertSame( 0, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
		self::assertSame( [ [ 'update_plugins' ] ], $this->calls['delete_site_transient'] ?? [] );
	}

	/** Cœur mis à jour depuis la dernière vérification : WordPress lui-même tient alors le transient pour invalide. */
	public function test_un_coeur_mis_a_jour_depuis_la_verification_est_reverifie(): void {
		$this->update_transients_checked( self::FRESH );
		$this->transients['update_core']->version_checked = '6.9.3';

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
	}

	/** Horloge revenue en arrière : une vérification « dans le futur » ne passe pas pour fraîche indéfiniment. */
	public function test_une_verification_datee_du_futur_est_perimee(): void {
		$this->update_transients_checked( -3600 );

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Vérification récente mais restée sans réponse de wordpress.org (réseau coupé
	 * après la destruction du transient) : WordPress n'a écrit que `last_checked`. Elle
	 * n'est pas crue 3 h : la synchronisation suivante réessaie, comme avant.
	 */
	public function test_une_verification_sans_reponse_n_est_pas_tenue_pour_fraiche(): void {
		$this->update_transients_checked( self::FRESH );
		$this->transients['update_plugins'] = (object) [ 'last_checked' => time() - 60 ];
		$this->transients['update_themes']  = (object) [ 'last_checked' => time() - 60 ];
		$this->transients['update_core']->updates = [];

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Le cas réel d'un échec réseau : wp_version_check(), wp_update_plugins() et
	 * wp_update_themes() écrivent `last_checked = time()` dans le transient existant
	 * AVANT d'appeler wordpress.org (wp-includes/update.php), puis s'arrêtent sur
	 * l'erreur. Dernière réponse reçue il y a 13 h (2 h du matin), cron WordPress sans
	 * réponse il y a 1 h (14 h) : à 15 h, le transient paraît vérifié il y a une heure
	 * avec les données de 2 h. Il est rafraîchi, pas cru 3 h de plus.
	 */
	public function test_un_echec_reseau_apres_une_ancienne_reponse_n_est_pas_tenu_pour_frais(): void {
		$this->update_transients_checked( 13 * 3600 );
		foreach ( [ 'update_core', 'update_plugins', 'update_themes' ] as $name ) {
			$this->transients[ $name ]->last_checked = time() - 3600;
		}

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Aucune réponse notée (connecteur tout juste mis à jour, requêtes vers
	 * wordpress.org court-circuitées par une extension) : rafraîchi à chaque fois,
	 * comme avant.
	 */
	public function test_sans_reponse_notee_de_wordpress_org_les_transients_sont_rafraichis(): void {
		$this->update_transients_checked( self::FRESH );
		unset( $this->site_options[ WporgAnswers::OPTION ] );

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/** Une réponse reçue pendant l'inventaire est notée : la synchronisation suivante n'appelle plus. */
	public function test_la_reponse_recue_au_rafraichissement_rend_la_synchronisation_suivante_legere(): void {
		$this->update_transients_checked( self::STALE );

		$this->snapshot();
		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/** Seule la vérification restée sans réponse est rafraîchie. */
	public function test_seule_la_verification_sans_reponse_recente_est_rafraichie(): void {
		$this->update_transients_checked( self::FRESH );
		$this->site_options[ WporgAnswers::OPTION ]['themes'] = time() - self::STALE;

		$this->snapshot();

		self::assertSame( 0, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Extension copiée hors de WordPress (FTP, hébergeur) : absente de `checked`, elle
	 * n'aurait aucune information de mise à jour pendant 3 h. wp_update_plugins() le
	 * détecte lui-même ($plugin_changed) ; l'inventaire aussi.
	 */
	public function test_une_extension_installee_hors_de_wordpress_rend_le_transient_perime(): void {
		$this->update_transients_checked( self::FRESH );
		unset( $this->transients['update_plugins']->checked['wp-seopress-pro/seopress-pro.php'] );

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
		self::assertSame( 0, $this->calls_to( 'wp_version_check' ) );
	}

	/** Extension mise à jour hors de WordPress : version installée différente de `checked`. */
	public function test_une_extension_mise_a_jour_hors_de_wordpress_rend_le_transient_perime(): void {
		$this->update_transients_checked( self::FRESH );
		$this->transients['update_plugins']->checked['akismet/akismet.php'] = '5.2';

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_themes' ) );
	}

	/** Pendant thème : absent de `checked`, ou version différente. */
	public function test_un_theme_installe_ou_mis_a_jour_hors_de_wordpress_rend_le_transient_perime(): void {
		$this->update_transients_checked( self::FRESH );
		unset( $this->transients['update_themes']->checked['premium-pro'] );

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );

		$this->calls = [];
		$this->update_transients_checked( self::FRESH );
		$this->transients['update_themes']->checked['twentytwentyfive'] = '1.2';

		$this->snapshot();

		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
	}

	/**
	 * Échec réseau sur une vérification du cœur périmée : le transient n'est plus
	 * détruit d'abord, la dernière réponse connue reste (au lieu d'un « aucune mise à
	 * jour » faux).
	 */
	public function test_la_derniere_reponse_du_coeur_n_est_pas_detruite_avant_la_verification(): void {
		$this->update_transients_checked( self::STALE );
		$offer = (object) [
			'response' => 'upgrade',
			'current'  => '7.0',
			'version'  => '7.0',
		];
		$this->transients['update_core']->updates = [ $offer ];
		Functions\when( 'wp_version_check' )->alias(
			function ( ...$args ): void {
				$this->calls['wp_version_check'][] = $args;
				// Comme wp_version_check() : `last_checked` écrit avant l'appel, puis
				// wordpress.org injoignable, rien d'autre.
				$this->transients['update_core']->last_checked = time();
			}
		);

		$core = $this->snapshot()['wp_core'];

		self::assertNotContains( [ 'update_core' ], $this->calls['delete_site_transient'] ?? [] );
		self::assertTrue( $core['has_update'] );
		self::assertSame( '7.0', $core['version_latest'] );
	}

	/** « Vérifier les MAJ » de la plateforme (commande check_updates) force toujours, même avec des transients frais. */
	public function test_check_updates_force_le_rafraichissement(): void {
		$this->update_transients_checked( self::FRESH );

		$outcome = CommandExecutor::run( 'check_updates' );

		self::assertSame( 'done', $outcome['status'] ?? null );
		self::assertSame( 1, $this->calls_to( 'wp_version_check' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_plugins' ) );
		self::assertSame( 1, $this->calls_to( 'wp_update_themes' ) );
	}

	/**
	 * Sans rafraîchissement, les mises à jour des updaters absents du contexte REST
	 * (SEOPress PRO) restent annoncées : le pont rejoue sa capture.
	 */
	public function test_les_mises_a_jour_tierces_restent_annoncees_sans_rafraichissement(): void {
		$this->update_transients_checked( self::FRESH );
		$this->capture['plugins'] = [
			'wp-seopress-pro/seopress-pro.php' => (object) [
				'slug'        => 'wp-seopress-pro',
				'plugin'      => 'wp-seopress-pro/seopress-pro.php',
				'new_version' => '8.1',
				'package'     => 'https://www.seopress.org/download/pro.zip',
			],
		];

		$plugins = array_column( $this->snapshot()['plugins'], null, 'file' );

		self::assertSame( 0, $this->calls_to( 'wp_update_plugins' ) );
		self::assertTrue( $plugins['wp-seopress-pro/seopress-pro.php']['has_update'] );
		self::assertSame( '8.1', $plugins['wp-seopress-pro/seopress-pro.php']['version_latest'] );
		self::assertFalse( $plugins['akismet/akismet.php']['has_update'] );
	}

	// ─── Santé de la base ────────────────────────────────────────────────────

	public function test_db_health_est_calcule_au_plus_une_fois_par_jour(): void {
		$this->update_transients_checked( self::FRESH );

		$first = $this->snapshot()['db_health'];
		self::assertSame( 5, $this->wpdb->health_queries(), 'Premier calcul : les 5 requêtes.' );

		$second = $this->snapshot()['db_health'];
		self::assertSame( 5, $this->wpdb->health_queries(), 'Dans les 24 h : aucune requête de plus.' );
		self::assertSame( $first, $second, 'Mêmes valeurs, et `collected_at` dit de quand elles datent.' );
		self::assertSame( 24 * 3600, DbHealth::MAX_AGE_SECONDS );
	}

	public function test_db_health_est_recalcule_apres_24_h(): void {
		$this->update_transients_checked( self::FRESH );
		$this->snapshot();
		$stored                        = $this->options[ DbHealth::OPTION ];
		$stored['at']                  = time() - DbHealth::MAX_AGE_SECONDS - 1;
		$this->options[ DbHealth::OPTION ] = $stored;
		$this->wpdb->revisions         = 40;

		$health = $this->snapshot()['db_health'];

		self::assertSame( 10, $this->wpdb->health_queries() );
		self::assertSame( 40, $health['post_revisions'] );
	}

	/** `collected_at` : date du calcul, pas de la synchronisation. */
	public function test_la_date_du_calcul_est_exposee(): void {
		$this->update_transients_checked( self::FRESH );
		$this->snapshot();
		$stored       = $this->options[ DbHealth::OPTION ];
		$stored['at'] = time() - 7200;
		$stored['health']['collected_at'] = gmdate( 'c', $stored['at'] );
		$this->options[ DbHealth::OPTION ] = $stored;

		self::assertSame( gmdate( 'c', $stored['at'] ), $this->snapshot()['db_health']['collected_at'] );
	}

	/** Cache dans une option NON autochargée : il ne pèse pas sur chaque page du site. */
	public function test_le_cache_n_est_pas_autocharge(): void {
		$autoload = [];
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $flag = null ) use ( &$autoload ): bool {
				$this->options[ $key ] = $value;
				$autoload[ $key ]      = $flag;
				return true;
			}
		);

		$this->snapshot();

		self::assertArrayHasKey( DbHealth::OPTION, $autoload );
		self::assertFalse( $autoload[ DbHealth::OPTION ] );
	}

	/** Une requête en échec n'est pas figée 24 h : le calcul suivant recommence. */
	public function test_un_calcul_en_echec_n_est_pas_mis_en_cache(): void {
		$this->update_transients_checked( self::FRESH );
		$this->wpdb->fail_on = "post_status = 'trash'";

		$this->snapshot();
		self::assertArrayNotHasKey( DbHealth::OPTION, $this->options );

		$this->wpdb->fail_on = null;
		$this->snapshot();
		self::assertSame( 10, $this->wpdb->health_queries() );
		self::assertArrayHasKey( DbHealth::OPTION, $this->options );
	}

	/**
	 * Après un nettoyage, la plateforme remet le compteur à 0 et attend la valeur
	 * réelle de la synchronisation suivante : le cache est oublié, même si la
	 * commande échoue en route (une partie a pu être supprimée).
	 */
	public function test_un_nettoyage_de_la_base_oublie_le_cache(): void {
		Functions\when( 'get_comments' )->alias(
			static function (): array {
				throw new \RuntimeException( 'base indisponible' );
			}
		);
		unset( $GLOBALS['wpdb'] );

		foreach ( [ 'delete_spam_comments', 'delete_post_revisions', 'empty_trashed_posts', 'delete_expired_transients', 'optimize_database' ] as $kind ) {
			$this->options[ DbHealth::OPTION ] = [
				'at'     => time(),
				'health' => [ 'spam_comments' => 500 ],
			];

			CommandExecutor::run( $kind );

			self::assertArrayNotHasKey( DbHealth::OPTION, $this->options, $kind );
		}
	}

	public function test_les_autres_commandes_gardent_le_cache(): void {
		$this->options[ DbHealth::OPTION ] = [
			'at'     => time(),
			'health' => [ 'spam_comments' => 500 ],
		];
		$this->update_transients_checked( self::FRESH );

		CommandExecutor::run( 'check_updates' );

		self::assertArrayHasKey( DbHealth::OPTION, $this->options );
	}

	// ─── OPcache ─────────────────────────────────────────────────────────────

	/**
	 * Seuls les fichiers du connecteur sont invalidés : les versions des extensions et
	 * des thèmes sont relues par get_file_data() (lecture du fichier, que l'OPcache ne
	 * sert jamais).
	 */
	public function test_opcache_n_invalide_que_les_fichiers_du_connecteur(): void {
		if ( ! defined( 'G2RD_CONNECTOR_DIR' ) ) {
			define( 'G2RD_CONNECTOR_DIR', dirname( __DIR__, 2 ) . '/' );
		}
		$dir = str_replace( '\\', '/', (string) G2RD_CONNECTOR_DIR );

		$files = array_map( static fn ( string $f ): string => str_replace( '\\', '/', $f ), SnapshotController::opcache_files() );

		self::assertContains( str_replace( '\\', '/', (string) G2RD_CONNECTOR_FILE ), $files );
		self::assertContains( $dir . 'includes/Rest/SnapshotController.php', $files );
		foreach ( $files as $file ) {
			self::assertTrue(
				str_starts_with( $file, $dir . 'includes/' ) || str_replace( '\\', '/', (string) G2RD_CONNECTOR_FILE ) === $file,
				$file . ' n\'est pas un fichier du connecteur.'
			);
			self::assertStringEndsWith( '.php', $file );
		}
	}
}
