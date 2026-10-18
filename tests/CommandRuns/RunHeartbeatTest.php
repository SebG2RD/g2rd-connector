<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunHeartbeat;
use G2RD\Connector\CommandRuns\RunLifecycle;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Signe de vie d'une exécution en arrière-plan pendant qu'elle tourne (§ 3.5 de la
 * spécification) : chaque crochet connu d'une mise à jour, chaque table optimisée et
 * chaque étape de la transaction d'une mise à jour protégée rafraîchissent `alive_at` de
 * l'exécution et du créneau, au plus une fois toutes les 5 s. Les écouteurs sont appelés
 * au milieu d'une mise à jour : les filtres rendent leur valeur telle quelle, et rien ne
 * remonte jamais.
 *
 * Les crochets de WordPress sont simulés : add_filter(), add_action() et leurs retraits
 * tiennent un registre ; apply_filters() et do_action() exécutent les rappels enregistrés
 * comme WP_Hook (par priorité, avec les `accepted_args` premiers arguments ; un filtre
 * reçoit la valeur rendue par le rappel précédent, une action toujours les mêmes
 * arguments). UpdateTransaction, qui déclenche l'action de ses étapes, passe donc par lui.
 */
final class RunHeartbeatTest extends TestCase {

	private const NOW = 1789000000;
	private const ID  = '0123456789abcdef0123456789abcdef';

	/** Les trois filtres du programme de mise à jour de WordPress. */
	private const FILTRES = [ 'upgrader_pre_download', 'upgrader_pre_install', 'upgrader_post_install' ];

	private FakeOptionsTable $table;

	/** Heure rendue par l'horloge passée à attach(). */
	private int $now = self::NOW;

	/**
	 * Registre des crochets : nom → priorité → clé du rappel → [ rappel, accepted_args ].
	 *
	 * @var array<string, array<int, array<string, array{0: callable, 1: int}>>>
	 */
	private array $hooks = [];

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		$this->now       = self::NOW;
		$this->hooks     = [];
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);

		$add    = function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			$this->hooks[ $hook ][ $priority ][ self::cle( $callback ) ] = [ $callback, $accepted_args ];
			return true;
		};
		$remove = function ( string $hook, $callback, int $priority = 10 ): bool {
			$cle = self::cle( $callback );
			if ( ! isset( $this->hooks[ $hook ][ $priority ][ $cle ] ) ) {
				return false;
			}
			unset( $this->hooks[ $hook ][ $priority ][ $cle ] );
			return true;
		};
		Functions\when( 'add_filter' )->alias( $add );
		Functions\when( 'add_action' )->alias( $add );
		Functions\when( 'remove_filter' )->alias( $remove );
		Functions\when( 'remove_action' )->alias( $remove );
		Functions\when( 'apply_filters' )->alias(
			fn ( string $hook, $value, ...$args ) => $this->execute( $hook, true, $value, ...$args )
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				$this->execute( $hook, false, ...$args );
			}
		);

		// Aucune exécution attachée par un test précédent : état propre au processus.
		RunHeartbeat::detach();
	}

	protected function tearDown(): void {
		RunHeartbeat::detach();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Critique : un filtre qui rendrait autre chose que sa première valeur casserait toute
	 * mise à jour du site (téléchargement, installation, fin d'installation). Valeur
	 * rendue telle quelle, qu'il écrive le signe de vie ou non (deuxième appel, dans les
	 * 5 s), et même objet pour une erreur.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function filtres_et_valeurs(): array {
		$cas = [];
		foreach ( self::FILTRES as $filtre ) {
			$cas[ $filtre . ', faux' ]           = [ $filtre, false ];
			$cas[ $filtre . ', vrai' ]           = [ $filtre, true ];
			$cas[ $filtre . ', WP_Error' ]       = [ $filtre, new WP_Error( 'download_failed', 'Le paquet n’a pas pu être téléchargé.' ) ];
			$cas[ $filtre . ', chemin du paquet' ] = [ $filtre, '/tmp/akismet.5.3.1.zip' ];
			$cas[ $filtre . ', nul' ]            = [ $filtre, null ];
		}
		return $cas;
	}

	#[DataProvider( 'filtres_et_valeurs' )]
	public function test_les_trois_filtres_rendent_leur_premiere_valeur_inchangee( string $filtre, mixed $valeur ): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		$this->now = self::NOW + 30;

		$extra = [
			'plugin' => 'akismet/akismet.php',
			'type'   => 'plugin',
			'action' => 'update',
		];
		self::assertSame( $valeur, apply_filters( $filtre, $valeur, 'https://downloads.wordpress.org/plugin/akismet.zip', $extra ) );
		self::assertSame( self::NOW + 30, $this->execution()['alive_at'] ?? null, 'Le premier appel écrit le signe de vie.' );
		self::assertSame( $valeur, apply_filters( $filtre, $valeur, 'https://downloads.wordpress.org/plugin/akismet.zip', $extra ), 'Ni écrit (moins de 5 s), ni changé.' );
	}

	/**
	 * @return array<string, array{string, bool, list<mixed>}>
	 */
	public static function crochets(): array {
		return [
			'upgrader_pre_download'              => [ 'upgrader_pre_download', true, [ false, 'https://downloads.wordpress.org/plugin/akismet.zip' ] ],
			'upgrader_pre_install'               => [ 'upgrader_pre_install', true, [ true, [ 'plugin' => 'akismet/akismet.php' ] ] ],
			'upgrader_post_install'              => [ 'upgrader_post_install', true, [ true, [ 'plugin' => 'akismet/akismet.php' ], [] ] ],
			'upgrader_process_complete'          => [ 'upgrader_process_complete', false, [ new \stdClass(), [ 'type' => 'plugin' ] ] ],
			'table optimisée'                    => [ 'g2rd_connector_command_progress', false, [ 'optimize_table' ] ],
			'étape de la transaction protégée'   => [ 'g2rd_connector_update_txn_progress', false, [ UpdateTransaction::STEP_UPGRADING ] ],
		];
	}

	/**
	 * @param list<mixed> $args
	 */
	#[DataProvider( 'crochets' )]
	public function test_chaque_crochet_rafraichit_le_signe_de_vie( string $crochet, bool $filtre, array $args ): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		$this->now = self::NOW + 30;

		if ( $filtre ) {
			apply_filters( $crochet, ...$args );
		} else {
			do_action( $crochet, ...$args );
		}

		$execution = $this->execution();
		self::assertSame( self::NOW + 30, $execution['alive_at'] ?? null, 'Signe de vie de l\'exécution.' );
		self::assertSame( RunState::RUNNING, $execution['state'] ?? null );
		self::assertSame( self::NOW, $execution['started_at'] ?? null );
		self::assertSame( self::NOW + 30, ExecutionSlot::holder()['alive_at'] ?? null, 'Signe de vie du créneau.' );
		self::assertSame( self::NOW, ExecutionSlot::holder()['since'] ?? null, 'La prise du créneau ne change pas.' );
	}

	/**
	 * Les crochets d'une mise à jour s'enchaînent en quelques millisecondes : au plus une
	 * écriture (exécution et créneau) toutes les 5 s.
	 */
	public function test_au_plus_une_ecriture_toutes_les_5_s(): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		$ecritures = count( $this->ecritures() );

		$this->now = self::NOW + 10;
		apply_filters( 'upgrader_pre_download', false, 'https://downloads.wordpress.org/plugin/akismet.zip' );
		self::assertCount( $ecritures + 2, $this->ecritures(), 'Exécution et créneau.' );

		$suite = [
			11 => 'upgrader_pre_install',
			13 => 'upgrader_post_install',
			14 => 'upgrader_process_complete',
		];
		foreach ( $suite as $delai => $crochet ) {
			$this->now = self::NOW + $delai;
			if ( 'upgrader_process_complete' === $crochet ) {
				do_action( $crochet, new \stdClass(), [ 'type' => 'plugin' ] );
			} else {
				apply_filters( $crochet, true, [ 'plugin' => 'akismet/akismet.php' ] );
			}
		}
		self::assertCount( $ecritures + 2, $this->ecritures(), 'Moins de 5 s après la dernière écriture : rien n\'est écrit.' );
		self::assertSame( self::NOW + 10, $this->execution()['alive_at'] ?? null );
		self::assertSame( self::NOW + 10, ExecutionSlot::holder()['alive_at'] ?? null );

		$this->now = self::NOW + 15;
		do_action( 'g2rd_connector_command_progress', 'optimize_table' );
		self::assertCount( $ecritures + 4, $this->ecritures() );
		self::assertSame( self::NOW + 15, $this->execution()['alive_at'] ?? null );
		self::assertSame( self::NOW + 15, ExecutionSlot::holder()['alive_at'] ?? null );
	}

	/**
	 * L'étape de la transaction d'une mise à jour protégée, annoncée par
	 * UpdateTransaction::step() et touch(), est enregistrée dans `step` ; touch() garde
	 * l'étape en cours.
	 */
	public function test_l_etape_de_la_transaction_est_enregistree(): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		self::assertTrue( UpdateTransaction::open( [ 'plugin_file' => 'akismet/akismet.php' ], self::NOW ) );
		self::assertArrayNotHasKey( 'step', $this->execution(), 'L\'ouverture n\'annonce aucune étape.' );

		$this->now = self::NOW + 20;
		self::assertTrue( UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => 'p1' ], self::NOW + 20 ) );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $this->execution()['step'] ?? null );
		self::assertSame( self::NOW + 20, $this->execution()['alive_at'] ?? null );
		self::assertSame( self::NOW + 20, ExecutionSlot::holder()['alive_at'] ?? null );

		$this->now = self::NOW + 40;
		UpdateTransaction::touch( self::NOW + 40 );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $this->execution()['step'] ?? null, 'touch() garde l\'étape.' );
		self::assertSame( self::NOW + 40, $this->execution()['alive_at'] ?? null );
	}

	/**
	 * Une étape nouvelle est écrite sans attendre les 5 s : le contrôle de santé suit de
	 * près la fin du remplacement des fichiers, et ne serait sinon jamais affiché. Seule
	 * l'étape inchangée attend (touch() juste après).
	 */
	public function test_une_etape_nouvelle_est_ecrite_sans_attendre_les_5_s(): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		UpdateTransaction::open( [ 'plugin_file' => 'akismet/akismet.php' ], self::NOW );

		$this->now = self::NOW + 20;
		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [], self::NOW + 20 );
		$this->now = self::NOW + 21;
		UpdateTransaction::step( UpdateTransaction::STEP_HEALTH, [], self::NOW + 21 );

		self::assertSame( UpdateTransaction::STEP_HEALTH, $this->execution()['step'] ?? null );
		self::assertSame( self::NOW + 21, $this->execution()['alive_at'] ?? null );
		self::assertSame( self::NOW + 21, ExecutionSlot::holder()['alive_at'] ?? null );
		$ecritures = count( $this->ecritures() );

		$this->now = self::NOW + 22;
		UpdateTransaction::touch( self::NOW + 22 );
		self::assertCount( $ecritures, $this->ecritures(), 'Même étape, moins de 5 s : rien n\'est écrit.' );
		self::assertSame( self::NOW + 21, $this->execution()['alive_at'] ?? null );
	}

	/**
	 * Base qui refuse l'écriture ou la lecture, base qui lève (mysqli en mode exception),
	 * horloge qui lève : le signe de vie est perdu, mais rien ne remonte dans la mise à
	 * jour, et les filtres rendent leur valeur.
	 */
	public function test_une_base_qui_refuse_l_ecriture_ne_fait_rien_remonter(): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		$avant = $this->table->rows;
		$erreur = new WP_Error( 'download_failed', 'Le paquet n’a pas pu être téléchargé.' );

		foreach ( [ 'fails', 'read_fails' ] as $i => $panne ) {
			$this->table->{$panne} = true;
			$this->now             = self::NOW + 10 * ( $i + 1 );

			self::assertSame( $erreur, apply_filters( 'upgrader_pre_download', $erreur, 'https://downloads.wordpress.org/plugin/akismet.zip' ), $panne );
			do_action( 'g2rd_connector_update_txn_progress', UpdateTransaction::STEP_HEALTH );
			self::assertSame( $avant, $this->table->rows, $panne . ' : rien n\'est écrit.' );

			$this->table->{$panne} = false;
		}

		$GLOBALS['wpdb'] = new class() {
			public string $options = 'wp_options';

			/** @param list<mixed> $args */
			public function __call( string $name, array $args ): never {
				throw new \RuntimeException( 'MySQL server has gone away' );
			}
		};
		$this->now = self::NOW + 40;
		self::assertTrue( apply_filters( 'upgrader_pre_install', true, [ 'plugin' => 'akismet/akismet.php' ] ), 'Base qui lève.' );
		$this->now = self::NOW + 50;
		do_action( 'g2rd_connector_update_txn_progress', UpdateTransaction::STEP_ROLLING_BACK );
		$GLOBALS['wpdb'] = $this->table;

		RunHeartbeat::attach(
			self::ID,
			static function (): int {
				throw new \LogicException( 'Horloge en panne' );
			}
		);
		self::assertTrue( apply_filters( 'upgrader_post_install', true, [ 'plugin' => 'akismet/akismet.php' ], [] ), 'Horloge qui lève.' );
		do_action( 'upgrader_process_complete', new \stdClass(), [ 'type' => 'plugin' ] );

		self::assertSame( $avant, $this->table->rows );
	}

	public function test_detach_retire_tous_les_crochets(): void {
		$this->demarre();
		$requetes = count( $this->table->queries );

		RunHeartbeat::attach( self::ID, fn (): int => $this->now );

		self::assertSame(
			[
				'g2rd_connector_command_progress',
				'g2rd_connector_update_txn_progress',
				'upgrader_post_install',
				'upgrader_pre_download',
				'upgrader_pre_install',
				'upgrader_process_complete',
			],
			$this->crochets_branches()
		);
		self::assertCount( $requetes, $this->table->queries, 'Le branchement ne lit ni n\'écrit rien.' );

		RunHeartbeat::detach();

		self::assertSame( [], $this->crochets_branches() );
		$this->now = self::NOW + 30;
		self::assertTrue( RunHeartbeat::on_filter( true ), 'Appelé encore après le retrait (copie d\'un appel en cours) : la valeur reste.' );
		RunHeartbeat::on_progress();
		RunHeartbeat::on_transaction_step( UpdateTransaction::STEP_HEALTH );
		self::assertCount( $requetes, $this->table->queries, 'Plus d\'exécution attachée : rien n\'est lu ni écrit.' );
	}

	public function test_sans_execution_attachee_rien_n_est_ecrit(): void {
		$this->demarre();
		$requetes = count( $this->table->queries );
		$erreur   = new WP_Error( 'install_failed', 'Installation impossible.' );

		self::assertSame( $erreur, RunHeartbeat::on_filter( $erreur ) );
		self::assertFalse( RunHeartbeat::on_filter( false ) );
		RunHeartbeat::on_progress();
		RunHeartbeat::on_transaction_step( UpdateTransaction::STEP_UPGRADING );
		do_action( 'g2rd_connector_update_txn_progress', UpdateTransaction::STEP_HEALTH );

		self::assertCount( $requetes, $this->table->queries );
		self::assertSame( self::NOW, $this->execution()['alive_at'] ?? null );
	}

	/**
	 * Horloge qui recule (resynchronisation NTP) : le signe de vie est écrit à la nouvelle
	 * heure, au lieu de se taire jusqu'à ce que l'horloge rattrape la dernière écriture.
	 */
	public function test_une_horloge_qui_recule_ne_fait_pas_taire_le_signe_de_vie(): void {
		$this->demarre();
		RunHeartbeat::attach( self::ID, fn (): int => $this->now );
		$this->now = self::NOW + 300;
		do_action( 'g2rd_connector_command_progress', 'optimize_table' );
		self::assertSame( self::NOW + 300, $this->execution()['alive_at'] ?? null );

		$this->now = self::NOW + 60;
		do_action( 'g2rd_connector_command_progress', 'optimize_table' );

		self::assertSame( self::NOW + 60, $this->execution()['alive_at'] ?? null );
		self::assertSame( self::NOW + 60, ExecutionSlot::holder()['alive_at'] ?? null );
	}

	/**
	 * Exécution acceptée, créneau pris et exécution démarrée à NOW, comme la route
	 * d'acceptation puis l'exécution après la réponse.
	 */
	private function demarre(): void {
		self::assertTrue( ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW )['acquired'] );
		self::assertTrue(
			RunStore::create(
				[
					'command_id'  => self::ID,
					'command'     => 'update_plugin',
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => RunState::ACCEPTED,
					'accepted_at' => self::NOW,
					'started_at'  => null,
					'alive_at'    => null,
					'finished_at' => null,
				]
			)
		);
		self::assertTrue( RunLifecycle::start( self::ID, self::NOW ) );
	}

	/** @return array<string, mixed> */
	private function execution(): array {
		return (array) RunStore::read( self::ID );
	}

	/** @return list<string> Écritures tentées (création, changement d'état, suppression). */
	private function ecritures(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => ! str_starts_with( $q, 'SELECT ' ) ) );
	}

	/** @return list<string> Crochets où au moins un rappel est branché, par ordre alphabétique. */
	private function crochets_branches(): array {
		$branches = [];
		foreach ( $this->hooks as $crochet => $par_priorite ) {
			foreach ( $par_priorite as $rappels ) {
				if ( [] !== $rappels ) {
					$branches[] = $crochet;
					break;
				}
			}
		}
		sort( $branches );
		return $branches;
	}

	/**
	 * Exécute les rappels d'un crochet comme WP_Hook : par priorité croissante, avec les
	 * `accepted_args` premiers arguments. Filtre : chaque rappel reçoit la valeur rendue
	 * par le précédent, et la dernière est rendue. Action : mêmes arguments pour tous.
	 */
	private function execute( string $hook, bool $filtre, mixed ...$args ): mixed {
		$par_priorite = $this->hooks[ $hook ] ?? [];
		ksort( $par_priorite );
		foreach ( $par_priorite as $rappels ) {
			foreach ( $rappels as [ $rappel, $accepted_args ] ) {
				$retour = $rappel( ...array_slice( $args, 0, $accepted_args ) );
				if ( $filtre ) {
					$args[0] = $retour;
				}
			}
		}
		return $filtre ? $args[0] : null;
	}

	/** Clé d'un rappel, comme _wp_filter_build_unique_id(). */
	private static function cle( mixed $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			return ( is_object( $callback[0] ) ? spl_object_hash( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
		}
		return spl_object_hash( (object) $callback );
	}
}
