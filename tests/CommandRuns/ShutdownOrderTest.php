<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunHeartbeat;
use G2RD\Connector\CommandRuns\RunLifecycle;
use G2RD\Connector\CommandRuns\RunReaper;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dernier filet d'une exécution en arrière-plan, et ordre réel des fonctions d'arrêt
 * (§ 3.5 de la spécification, mort du processus, cas 1).
 *
 * Quand le processus meurt pendant la commande (erreur fatale, délai PHP dépassé), les filets
 * existants passent d'abord, dans leur ordre d'enregistrement : la reprise de la transaction
 * (ProtectedUpdate::recover_on_shutdown(), qui consigne son résultat dans PendingOutcomes),
 * puis la réactivation de l'extension (CommandExecutor::perform_plugin_upgrade()). Le dernier
 * filet de l'exécution passe après eux : il est enregistré depuis une fonction d'arrêt, et
 * PHP l'ajoute alors en fin de liste. Il déclare l'exécution interrompue, avec le message de
 * l'erreur et le résultat de reprise de son extension, puis rend le créneau.
 *
 * L'ordre est prouvé par un vrai arrêt de PHP, dans un processus à part
 * (tests/fixtures/command-runs/fatal-during-run.php) : seul un processus à part peut mourir
 * d'une erreur fatale sans emporter PHPUnit. Les cas sans arrêt réel (dernier filet désarmé,
 * commande hors extension) sont vérifiés ici, les fonctions d'arrêt confiées à
 * register_shutdown_function() étant appelées à la main, dans l'ordre où PHP les appelle.
 */
final class ShutdownOrderTest extends TestCase {

	/** 03/09/2026 00:26:40 UTC : acceptation et démarrage (mêmes valeurs que le script). */
	private const NOW       = 1789000000;
	private const ID        = '0123456789abcdef0123456789abcdef';
	private const EXTENSION = 'akismet/akismet.php';

	/** Durée de la mise à jour dans le script, jusqu'à son arrêt. */
	private const DUREE = 30;

	/** Résultat consigné par la reprise à l'arrêt, dans le script. */
	private const REPRISE = [
		'plugin_file'      => self::EXTENSION,
		'restore_point_id' => 'rp-akismet-5-3',
		'step'             => 'install',
		'outcome'          => 'recovered_rolled_back',
		'at'               => self::NOW + self::DUREE,
	];

	/** Début du journal, jusqu'au démarrage de l'exécution. */
	private const DEMARRAGE = [ 'créneau pris', 'exécution accepted', 'exécution running' ];

	private FakeOptionsTable $table;

	/** Journal d'erreurs PHP du test (error_log()), fichier temporaire ; '' : aucun. */
	private string $journal_php = '';

	/** Réglage `error_log` d'avant le test, rétabli à la fin. */
	private string $error_log_avant = '';

	/**
	 * Fonctions confiées à register_shutdown_function(), dans l'ordre, quand elle est simulée.
	 *
	 * @var list<callable>
	 */
	private array $confiees = [];

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		$this->confiees  = [];
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		RunHeartbeat::detach();
	}

	protected function tearDown(): void {
		RunHeartbeat::detach();
		if ( '' !== $this->journal_php ) {
			ini_set( 'error_log', $this->error_log_avant );
			if ( is_file( $this->journal_php ) ) {
				unlink( $this->journal_php );
			}
		}
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Critère d'acceptation : erreur fatale pendant la commande, vrai arrêt de PHP. La reprise
	 * passe, puis la réactivation, puis le dernier filet déclare l'exécution interrompue et
	 * rend le créneau, dans cet ordre.
	 */
	public function test_ordre_reprise_puis_reactivation_puis_execution_interrompue(): void {
		$arret = $this->arret_reel( 'fatale' );

		self::assertSame( 255, $arret['code'], 'Le processus meurt d\'une erreur fatale. ' . $arret['sortie'] );
		self::assertSame(
			array_merge( self::DEMARRAGE, [ 'reprise', 'réactivation', 'exécution interrupted', 'créneau rendu' ] ),
			$arret['journal']
		);
		self::assertSame( AfterResponseRunner::REASON_FATAL_ERROR, $arret['execution']['interruption']['reason'] ?? null );
		self::assertSame( self::NOW + self::DUREE, $arret['execution']['finished_at'] ?? null );
		self::assertArrayNotHasKey( 'outcome', $arret['execution'], 'Aucun résultat : la commande n\'a pas rendu la main.' );
		self::assertSame( [], $arret['notices'], 'Aucune notice, dernier filet compris.' );
	}

	/**
	 * Le résultat de la reprise, consigné juste avant par le filet de la transaction, est
	 * recopié dans l'exécution : la plateforme l'apprend à l'interrogation suivante, sans
	 * attendre la synchronisation. Seul le dernier résultat de cette extension consigné
	 * depuis le démarrage : ni celui de la veille, ni celui d'une autre extension, ni la
	 * reprise d'une mise à jour morte faite au début de la commande.
	 */
	public function test_le_resultat_de_reprise_est_rattache_a_l_execution(): void {
		$arret = $this->arret_reel( 'fatale' );

		self::assertSame( self::REPRISE, $arret['execution']['interruption']['recovery'] ?? null );
		self::assertCount( 4, $arret['options'][ PendingOutcomes::OPTION_KEY ] ?? [], 'Les quatre résultats restent consignés dans l\'inventaire.' );
	}

	/**
	 * Le message de l'erreur fatale (error_get_last()) est gardé dans l'exécution, après une
	 * phrase qui dit quoi, la cause probable et l'action. Plus long que 2 Ko, il est coupé à
	 * 2 Ko, marque comprise.
	 */
	public function test_le_message_de_l_erreur_fatale_est_garde_et_tronque(): void {
		$arret = $this->arret_reel( 'fatale' );

		$erreur = (string) ( $arret['execution']['interruption']['error'] ?? '' );
		self::assertSame( RunStore::MAX_ERROR_BYTES, strlen( $erreur ), 'Coupé à 2 Ko.' );
		self::assertStringEndsWith( '…', $erreur );
		self::assertStringStartsWith( 'La commande s’est arrêtée sur une erreur fatale de PHP', $erreur );
		self::assertStringContainsString( 'Cause probable', $erreur );
		self::assertStringContainsString( 'Action', $erreur );
		self::assertStringContainsString( 'Cannot redeclare', $erreur, 'Le message de l\'erreur est gardé.' );
		self::assertStringContainsString( 'g2rd_extension_chargee_deux_fois_xxx', $erreur );
	}

	/**
	 * La commande rend son résultat : le dernier filet, désarmé, ne fait rien à l'arrêt.
	 * L'exécution reste conclue, et les filets existants passent comme avant.
	 */
	public function test_fin_normale_le_dernier_filet_ne_fait_rien(): void {
		$arret = $this->arret_reel( 'fin-normale' );

		self::assertSame( 0, $arret['code'], $arret['sortie'] );
		self::assertSame(
			array_merge( self::DEMARRAGE, [ 'exécution done', 'créneau rendu', 'reprise', 'réactivation' ] ),
			$arret['journal']
		);
		self::assertSame( RunState::DONE, $arret['execution']['state'] ?? null );
		self::assertArrayNotHasKey( 'interruption', $arret['execution'] );
		self::assertSame( [], $arret['notices'] );
	}

	/**
	 * Une erreur fatale levée DANS une fonction d'arrêt empêche les suivantes de passer (essai
	 * du § 3.1) : ici, une seconde erreur fatale dans la reprise. Ni la réactivation ni le
	 * dernier filet ne passent : l'exécution reste en cours, créneau pris. Le ramassage prend
	 * le relais : 600 s après le dernier signe de vie, il la déclare interrompue et rend le
	 * créneau.
	 */
	public function test_erreur_fatale_dans_un_filet_precedent_le_ramassage_prend_le_relais(): void {
		$arret = $this->arret_reel( 'fatale-dans-la-reprise' );

		self::assertSame( 255, $arret['code'], $arret['sortie'] );
		self::assertSame( array_merge( self::DEMARRAGE, [ 'reprise' ] ), $arret['journal'], 'Rien ne passe après la reprise.' );
		self::assertSame( RunState::RUNNING, $arret['execution']['state'] ?? null );

		// La table laissée par le processus mort, reprise par un autre processus (le ramassage).
		$this->table->rows = $arret['lignes'];
		self::assertSame( self::ID, ExecutionSlot::holder()['command_id'] ?? null, 'Créneau resté pris.' );

		RunReaper::sweep_one( self::ID, self::NOW + RunReaper::SILENCE_TIMEOUT_SECONDS );
		self::assertSame( RunState::RUNNING, RunStore::read( self::ID )['state'] ?? null, 'Pas avant 600 s de silence.' );

		RunReaper::sweep_one( self::ID, self::NOW + RunReaper::SILENCE_TIMEOUT_SECONDS + 1 );
		$execution = (array) RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $execution['state'] ?? null );
		self::assertSame( RunReaper::REASON_NO_SIGN_OF_LIFE, $execution['interruption']['reason'] ?? null );
		self::assertNull( ExecutionSlot::holder(), 'Créneau rendu par le ramassage.' );
	}

	/**
	 * exit (wp_die() d'une extension) pendant la commande : le processus s'arrête sans erreur
	 * fatale, et error_get_last() rend encore un simple avertissement levé plus tôt. Les
	 * filets passent dans le même ordre ; l'exécution est déclarée interrompue, sans se dire
	 * arrêtée par une erreur fatale, et sans reprendre l'avertissement pour cause.
	 */
	public function test_arret_par_exit_l_execution_est_interrompue_sans_se_dire_fatale(): void {
		$arret = $this->arret_reel( 'sortie' );

		self::assertSame( 0, $arret['code'], $arret['sortie'] );
		self::assertSame(
			array_merge( self::DEMARRAGE, [ 'reprise', 'réactivation', 'exécution interrupted', 'créneau rendu' ] ),
			$arret['journal']
		);
		$interruption = (array) ( $arret['execution']['interruption'] ?? [] );
		self::assertSame( AfterResponseRunner::REASON_SCRIPT_EXIT, $interruption['reason'] ?? null );
		$erreur = (string) ( $interruption['error'] ?? '' );
		self::assertStringStartsWith( 'La commande s’est arrêtée avant d’avoir rendu son résultat', $erreur );
		self::assertStringContainsString( 'Cause probable', $erreur );
		self::assertStringContainsString( 'Action', $erreur );
		self::assertStringNotContainsString( 'Avertissement', $erreur, 'Un avertissement n\'est pas la cause de l\'arrêt.' );
		self::assertSame( self::REPRISE, $interruption['recovery'] ?? null );
		self::assertSame( [], $arret['notices'] );
	}

	/**
	 * Fin normale, mais résultat que la base n'a pas pris : l'exécution reste en cours. Le
	 * dernier filet, désarmé par la fin normale, ne la déclare pas interrompue « sur une
	 * erreur fatale » à l'arrêt (aucune n'a eu lieu) : il ne lit même pas la base. Le
	 * ramassage la clôt 600 s après son dernier signe de vie.
	 */
	public function test_fin_normale_sans_resultat_enregistre_le_dernier_filet_ne_fait_rien(): void {
		$this->capture_le_journal_php();
		$this->simule_les_fonctions_d_arret();
		$this->accepte( 'update_plugin' );
		$runner = new AfterResponseRunner(
			terminator: static fn (): bool => true,
			executor: function (): array {
				$this->table->fails = true;
				return [
					'status' => 'done',
					'result' => [],
				];
			},
			clock: static fn (): int => self::NOW,
			output: static function (): void {
			}
		);
		$runner->queue( self::ID, 'update_plugin', [ 'file' => self::EXTENSION ] );

		$runner->maybe_serve( false, $this->reponse(), new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' ), null );
		$this->table->fails = false;
		self::assertCount( 1, $this->confiees, 'Dernier filet armé avant la commande.' );
		self::assertSame( RunState::RUNNING, RunStore::read( self::ID )['state'] ?? null, 'Résultat non enregistré.' );

		$requetes = count( $this->table->queries );
		$this->arreter();

		self::assertSame( $requetes, count( $this->table->queries ), 'Aucune requête à l\'arrêt.' );
		self::assertSame( RunState::RUNNING, RunStore::read( self::ID )['state'] ?? null );
	}

	/**
	 * Commande qui ne touche à aucune extension (thème, cœur, base : dernier filet armé sans
	 * extension) : aucun résultat de reprise n'est rattaché, même si une reprise d'extension a
	 * été consignée depuis le démarrage. Arrêt sans erreur fatale relevée : cause
	 * « script_exit ». Le créneau est rendu.
	 */
	public function test_sans_extension_aucun_resultat_de_reprise_n_est_rattache(): void {
		$this->simule_les_fonctions_d_arret();
		$this->accepte( 'update_theme' );
		self::assertTrue( RunLifecycle::start( self::ID, self::NOW ) );
		PendingOutcomes::add(
			[
				'plugin_file'      => self::EXTENSION,
				'restore_point_id' => 'rp-akismet-5-3',
				'step'             => 'install',
				'outcome'          => 'recovered_rolled_back',
			],
			self::NOW + 1
		);
		$runner = new AfterResponseRunner( clock: static fn (): int => self::NOW + 5 );

		$runner->arm_last_net( self::ID, null, self::NOW );
		error_clear_last();
		$this->arreter();

		$execution = (array) RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $execution['state'] ?? null );
		self::assertSame( AfterResponseRunner::REASON_SCRIPT_EXIT, $execution['interruption']['reason'] ?? null );
		self::assertArrayHasKey( 'recovery', $execution['interruption'] );
		self::assertNull( $execution['interruption']['recovery'] );
		self::assertSame( self::NOW + 5, $execution['finished_at'] ?? null );
		self::assertNull( ExecutionSlot::holder(), 'Créneau rendu.' );
	}

	/**
	 * Lance le script dans un processus PHP à part, sans php.ini, et rend son relevé : code de
	 * sortie, sortie, journal, table des options, options, notices, et l'exécution décodée.
	 *
	 * @return array{code: int, sortie: string, journal: list<string>, lignes: array<string, array{value: string, autoload: string}>, options: array<string, mixed>, notices: list<string>, execution: array<string, mixed>}
	 */
	private function arret_reel( string $scenario ): array {
		if ( ! function_exists( 'exec' ) ) {
			self::markTestSkipped( 'exec() est désactivée : impossible de lancer un processus PHP à part.' );
		}
		$script = dirname( __DIR__ ) . '/fixtures/command-runs/fatal-during-run.php';
		$etat   = (string) tempnam( sys_get_temp_dir(), 'g2rd-arret-' );
		$sortie = [];
		$code   = 0;

		exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $etat ) . ' ' . escapeshellarg( $scenario ) . ' 2>&1', $sortie, $code );

		$releve = json_decode( (string) file_get_contents( $etat ), true );
		foreach ( array_merge( [ $etat ], (array) glob( $etat . '.*.php' ) ) as $fichier ) {
			if ( is_string( $fichier ) && is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}
		$texte = implode( "\n", $sortie );
		self::assertIsArray( $releve, 'Relevé du processus à part illisible. Sortie : ' . $texte );
		self::assertSame( '', $texte, 'Rien d\'écrit sur la sortie du processus à part.' );
		$ligne = $releve['lignes'][ RunStore::option_name( self::ID ) ]['value'] ?? null;

		return [
			'code'      => $code,
			'sortie'    => $texte,
			'journal'   => $releve['journal'] ?? [],
			'lignes'    => $releve['lignes'] ?? [],
			'options'   => $releve['options'] ?? [],
			'notices'   => $releve['notices'] ?? [],
			'execution' => is_string( $ligne ) ? (array) json_decode( $ligne, true ) : [],
		];
	}

	/**
	 * register_shutdown_function() simulée : les fonctions confiées sont gardées, dans l'ordre,
	 * pour arreter().
	 */
	private function simule_les_fonctions_d_arret(): void {
		Functions\when( 'register_shutdown_function' )->alias(
			function ( callable $callback ): void {
				$this->confiees[] = $callback;
			}
		);
	}

	/**
	 * Arrêt de PHP simulé : chaque fonction d'arrêt confiée, dans l'ordre, y compris celles
	 * qu'elles confient à leur tour, que PHP ajoute en fin de liste.
	 */
	private function arreter(): void {
		$i = 0;
		while ( isset( $this->confiees[ $i ] ) ) {
			( $this->confiees[ $i ] )();
			++$i;
		}
	}

	/** Exécution acceptée, créneau pris : l'état laissé par la route d'acceptation. */
	private function accepte( string $command ): void {
		self::assertTrue( ExecutionSlot::acquire( self::ID, $command, self::NOW )['acquired'] );
		self::assertTrue(
			RunStore::create(
				[
					'command_id'  => self::ID,
					'command'     => $command,
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => RunState::ACCEPTED,
					'accepted_at' => self::NOW,
					'started_at'  => null,
					'alive_at'    => null,
					'finished_at' => null,
				]
			)
		);
	}

	/** Réponse 202 de l'acceptation (CommandRunsController::accept()). */
	private function reponse(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'command_id'         => self::ID,
				'state'              => RunState::ACCEPTED,
				'accepted_at'        => gmdate( 'c', self::NOW ),
				'poll_after_seconds' => 2,
				'served_at'          => gmdate( 'c', self::NOW ),
			],
			202
		);
	}

	/** Redirige error_log() vers un fichier temporaire du test. */
	private function capture_le_journal_php(): void {
		$this->journal_php     = (string) tempnam( sys_get_temp_dir(), 'g2rd-journal-' );
		$this->error_log_avant = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->journal_php );
	}
}
