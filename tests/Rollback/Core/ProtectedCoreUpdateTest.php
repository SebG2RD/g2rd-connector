<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Core_Upgrader;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\Core\ProtectedCoreUpdate;
use G2RD\Connector\Rollback\UpdateTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Mise à jour protégée du cœur (§ 4.1) : référence de santé, mise à jour, contrôle, retour
 * automatique à la version d'avant (seulement si la base n'a pas changé), version retirée bloquée
 * pour les mises à jour automatiques de WordPress AVANT la réinstallation, informations du bouton
 * gardées seulement sur demande, verrou commun aux mises à jour protégées (rien sans la transaction),
 * forme historique quand il n'y a rien à protéger.
 */
final class ProtectedCoreUpdateTest extends CoreRollbackTestCase {

	private const PAYLOAD = [
		'snapshot'        => true,
		'keep_on_success' => true,
		'grace_seconds'   => 259200,
	];

	private int $updates = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->updates        = 0;
		$this->loaded_version = '7.1.4';
		$this->install( '7.1.4' );
		Functions\when( 'get_core_updates' )->justReturn(
			[
				(object) [
					'response' => 'upgrade',
					'version'  => '7.1.5',
					'locale'   => 'en_US',
				],
			]
		);
	}

	public function test_site_sain_les_informations_du_bouton_sont_gardees(): void {
		$before = time();

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );

		self::assertTrue( $result['updated'] );
		self::assertSame( '7.1.5', $result['version_after'] );
		self::assertTrue( $result['protected'] );
		self::assertFalse( $result['rolled_back'] );
		self::assertNull( $result['rollback_reason'] );
		self::assertSame( 'healthy', $result['health']['verdict'] );
		self::assertSame( 61833, $result['db_version_before'] );
		self::assertSame( 61833, $result['db_version_after'] );
		self::assertGreaterThanOrEqual( $before + 259200, $result['restore_until'] );
		self::assertLessThanOrEqual( time() + 259200, $result['restore_until'] );
		self::assertArrayNotHasKey( 'outcome', $result, 'jamais lu comme l\'issue d\'une extension' );
		self::assertArrayNotHasKey( 'error_code', $result );
		self::assertSame(
			[
				'version_before' => '7.1.4',
				'version_after'  => '7.1.5',
				'locale'         => 'en_US',
				'db_version'     => 61833,
				'until'          => $result['restore_until'],
			],
			$this->options[ ProtectedCoreUpdate::RECORD_OPTION ]
		);
		self::assertSame( [], Core_Upgrader::$calls, 'aucune réinstallation' );
		self::assertSame( [], AutoUpdateGuard::all(), 'rien de retiré, rien de bloqué' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options, 'transaction fermée' );
	}

	public function test_sans_keep_on_success_rien_n_est_garde(): void {
		$this->options[ ProtectedCoreUpdate::RECORD_OPTION ] = [ 'version_before' => '7.1.3' ];

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( [ 'snapshot' => true ], $this->update_to_7_1_5() );

		self::assertNull( $result['restore_until'] );
		self::assertArrayNotHasKey( ProtectedCoreUpdate::RECORD_OPTION, $this->options, 'celles d\'une mise à jour précédente partent' );
	}

	public function test_site_casse_retour_automatique_depuis_wordpress_org(): void {
		$this->breaks_on = '7.1.5';
		$steps           = [];
		Actions\expectDone( 'g2rd_connector_update_txn_progress' )->zeroOrMoreTimes()->whenHappen(
			static function ( string $step ) use ( &$steps ): void {
				$steps[] = $step;
			}
		);
		$blocked_during            = null;
		Core_Upgrader::$on_upgrade = function ( object $offer ) use ( &$blocked_during ): void {
			$blocked_during = AutoUpdateGuard::all();
			$this->install( (string) $offer->version );
		};

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );

		self::assertFalse( $result['updated'] );
		self::assertSame( '7.1.4', $result['version_after'] );
		self::assertTrue( $result['rolled_back'] );
		self::assertSame( 'health_check_failed', $result['rollback_reason'] );
		self::assertSame( 'broken', $result['health']['verdict'] );
		self::assertSame( 'ok', $result['health']['after_rollback']['home']['state'] );
		self::assertSame( 'en_US', $result['locale'] );
		self::assertNull( $result['restore_until'] );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertCount( 1, Core_Upgrader::$calls );
		self::assertSame( '7.1.4', Core_Upgrader::$calls[0]['offer']->version );
		self::assertArrayNotHasKey( ProtectedCoreUpdate::RECORD_OPTION, $this->options );
		// step(), touch() après le téléchargement (CoreReinstaller), touch() après la réinstallation.
		self::assertSame( [ 'upgrading', 'health', 'rolling_back', 'rolling_back', 'rolling_back' ], $steps, 'signes de vie d\'une exécution en arrière-plan' );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], $blocked_during, 'bloquée AVANT la réinstallation : un arrêt entre la copie et le blocage la laisserait débloquée' );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all(), 'WordPress ne réinstallera pas 7.1.5 tout seul' );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	public function test_echec_en_cours_de_copie_retour_automatique(): void {
		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5( new WP_Error( 'copy_failed__copy_dir', 'Could not copy file.' ) ) );

		self::assertTrue( $result['rolled_back'] );
		self::assertSame( 'update_failed', $result['rollback_reason'] );
		self::assertArrayNotHasKey( 'after', $result['health'], 'une mise à jour échouée n\'est pas contrôlée' );
		self::assertArrayHasKey( 'after_rollback', $result['health'] );
		self::assertSame( '7.1.4', $this->version_on_disk() );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all(), 'la version proposée, pas celle d\'avant' );
	}

	public function test_echec_avant_la_copie_rien_a_defaire(): void {
		$result = ( new ProtectedCoreUpdate( $this->health ) )->run(
			self::PAYLOAD,
			static fn (): array => [
				[
					'updated'        => false,
					'version_before' => '7.1.4',
					'version_after'  => '7.1.4',
					'detail'         => 'Download failed.',
				],
				new WP_Error( 'download_failed', 'Download failed.' ),
			]
		);

		self::assertFalse( $result['rolled_back'] );
		self::assertNull( $result['rollback_reason'] );
		self::assertNull( $result['restore_until'] );
		self::assertSame( [], Core_Upgrader::$calls );
	}

	public function test_base_modifiee_aucun_retour_et_le_dire(): void {
		$this->breaks_on = '7.1.5';

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run(
			self::PAYLOAD,
			$this->update_to_7_1_5(
				null,
				function (): void {
					$this->table->rows['db_version']['value'] = '61900';
				}
			)
		);

		self::assertFalse( $result['rolled_back'] );
		self::assertSame( 'health_check_failed', $result['rollback_reason'] );
		self::assertSame( 'core_rollback_db_changed', $result['error_code'] );
		self::assertSame( 61833, $result['db_version_before'] );
		self::assertSame( 61900, $result['db_version_after'] );
		self::assertSame( [], Core_Upgrader::$calls, 'aucune réinstallation sur une base modifiée' );
		self::assertSame( '7.1.5', $this->version_on_disk() );
		self::assertSame( [], AutoUpdateGuard::all() );
	}

	public function test_base_modifiee_site_sain_rien_n_est_garde(): void {
		$result = ( new ProtectedCoreUpdate( $this->health ) )->run(
			self::PAYLOAD,
			$this->update_to_7_1_5(
				null,
				function (): void {
					$this->table->rows['db_version']['value'] = '61900';
				}
			)
		);

		self::assertTrue( $result['updated'] );
		self::assertNull( $result['restore_until'] );
		self::assertArrayNotHasKey( ProtectedCoreUpdate::RECORD_OPTION, $this->options );
	}

	public function test_retour_automatique_impossible_wordpress_org_injoignable(): void {
		$this->breaks_on = '7.1.5';
		$this->wporg     = [];

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );

		self::assertFalse( $result['rolled_back'] );
		self::assertSame( 'core_rollback_wporg_unreachable', $result['error_code'] );
		self::assertStringStartsWith( 'WordPress was not rolled back', $result['error'] );
		self::assertSame( '7.1.5', $this->version_on_disk() );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all(), 'posé avant la réinstallation et gardé : sans effet sur la version installée' );
	}

	/** Transaction reprise pendant la référence de santé : pas de mise à jour sans elle. */
	public function test_transaction_reprise_avant_la_mise_a_jour_rien_n_est_lance(): void {
		$this->on_probe = function ( int $probe ): void {
			if ( 1 === $probe ) {
				$this->take_over();
			}
		};

		try {
			( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );
			self::fail( 'refus attendu' );
		} catch ( \RuntimeException $busy ) {
			self::assertSame( UpdateTransaction::BUSY_MESSAGE, $busy->getMessage() );
		}
		self::assertSame( 0, $this->updates, 'rien n\'a été lancé' );
		self::assertSame( 'jeton-du-cron', $this->options[ UpdateTransaction::OPTION_KEY ]['recovery_token'] ?? null, 'la reprise garde sa transaction' );
	}

	/**
	 * Transaction reprise pendant la mise à jour (étape de plus de 10 minutes) : une autre mise à
	 * jour protégée peut tourner. Ni mesure (elle croiserait la sienne) ni retour automatique : mise
	 * à jour faite, site non contrôlé ; le bouton reste possible (rollback_core prendra sa propre
	 * transaction et refera tous ses contrôles).
	 */
	public function test_transaction_reprise_pendant_la_mise_a_jour_ni_controle_ni_retour(): void {
		$this->breaks_on = '7.1.5';

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run(
			self::PAYLOAD,
			$this->update_to_7_1_5(
				null,
				function (): void {
					$this->take_over();
				}
			)
		);

		self::assertTrue( $result['updated'] );
		self::assertSame( 'unverifiable', $result['health']['verdict'] );
		self::assertArrayNotHasKey( 'after', $result['health'] );
		self::assertSame( 2, $this->probes, 'seule la référence a été mesurée' );
		self::assertFalse( $result['rolled_back'] );
		self::assertNull( $result['rollback_reason'] );
		self::assertArrayNotHasKey( 'error_code', $result );
		self::assertIsInt( $result['restore_until'] );
		self::assertSame( [], Core_Upgrader::$calls, 'aucune réinstallation sans transaction' );
		self::assertSame( '7.1.5', $this->version_on_disk() );
	}

	/** Transaction reprise pendant le contrôle d'après : site cassé, mais aucune réinstallation sans elle. */
	public function test_transaction_reprise_avant_le_retour_aucune_reinstallation(): void {
		$this->breaks_on = '7.1.5';
		$this->on_probe  = function ( int $probe ): void {
			if ( 3 === $probe ) { // Première sonde d'après la mise à jour.
				$this->take_over();
			}
		};

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );

		self::assertSame( 'broken', $result['health']['verdict'] );
		self::assertFalse( $result['rolled_back'] );
		self::assertSame( 'health_check_failed', $result['rollback_reason'] );
		self::assertSame( 'core_rollback_busy', $result['error_code'] );
		self::assertSame( [], Core_Upgrader::$calls, 'aucune réinstallation sans transaction' );
		self::assertSame( '7.1.5', $this->version_on_disk() );
		self::assertSame( [], AutoUpdateGuard::all() );
		self::assertSame( 'jeton-du-cron', $this->options[ UpdateTransaction::OPTION_KEY ]['recovery_token'] ?? null );
	}

	public function test_une_autre_mise_a_jour_protegee_tourne(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'akismet/akismet.php' ], time() );
		// Tenue par un autre processus : celui-ci ne la tient pas.
		( new \ReflectionProperty( UpdateTransaction::class, 'held' ) )->setValue( null, null );

		try {
			( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, $this->update_to_7_1_5() );
			self::fail( 'refus attendu' );
		} catch ( \RuntimeException $busy ) {
			self::assertSame( UpdateTransaction::BUSY_MESSAGE, $busy->getMessage() );
		}
		self::assertSame( 0, $this->updates, 'rien n\'a été lancé' );
	}

	/**
	 * @return iterable<string, array{\Closure(self): void}>
	 */
	public static function nothing_to_protect(): iterable {
		yield 'WordPress est à jour' => [
			static function (): void {
				Functions\when( 'get_core_updates' )->justReturn(
					[
						(object) [
							'response' => 'latest',
							'version'  => '7.1.4',
							'locale'   => 'en_US',
						],
					]
				);
			},
		];
		yield 'aucune offre' => [
			static function (): void {
				Functions\when( 'get_core_updates' )->justReturn( false );
			},
		];
		yield 'version de développement installée' => [
			static function ( self $test ): void {
				$test->loaded_version = '7.2-beta1';
			},
		];
	}

	#[DataProvider( 'nothing_to_protect' )]
	public function test_rien_a_proteger_forme_historique( \Closure $setup ): void {
		$setup( $this );
		$historical = [
			'updated'        => false,
			'reason'         => 'no_updates_available',
			'version_before' => '7.1.4',
			'version_after'  => '7.1.4',
		];

		$result = ( new ProtectedCoreUpdate( $this->health ) )->run( self::PAYLOAD, static fn (): array => [ $historical, null ] );

		self::assertSame( $historical, $result );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	public function test_la_transaction_est_fermee_meme_sur_une_exception(): void {
		try {
			( new ProtectedCoreUpdate( $this->health ) )->run(
				self::PAYLOAD,
				static function (): array {
					throw new \RuntimeException( 'Fatal in upgrader_process_complete' );
				}
			);
			self::fail( 'exception attendue' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Fatal in upgrader_process_complete', $error->getMessage() );
		}
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	/**
	 * Mise à jour simulée vers 7.1.5, comme CommandExecutor::perform_core_upgrade() : fichiers de
	 * 7.1.5 écrits, forme historique, et l'erreur de WordPress à part.
	 *
	 * @return callable(): array{0: array<string, mixed>, 1: WP_Error|null}
	 */
	private function update_to_7_1_5( ?WP_Error $error = null, ?\Closure $also = null ): callable {
		return function () use ( $error, $also ): array {
			++$this->updates;
			$this->install( '7.1.5' );
			if ( null !== $also ) {
				$also();
			}
			if ( null === $error ) {
				return [
					[
						'updated'        => true,
						'version_before' => '7.1.4',
						'version_after'  => '7.1.5',
						'detail'         => '7.1.5',
					],
					null,
				];
			}
			return [
				[
					'updated'        => false,
					'version_before' => '7.1.4',
					'version_after'  => '7.1.4',
					'detail'         => $error->get_error_message(),
				],
				$error,
			];
		};
	}
}
