<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunLifecycle;
use G2RD\Connector\CommandRuns\RunReaper;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cycle de vie d'une exécution en arrière-plan (§ 3.5 de la spécification) : chaque
 * passage d'état est une écriture conditionnelle sur la valeur relue. Une exécution est
 * soit démarrée, soit abandonnée, jamais les deux ; un résultat réel est écrit même sur
 * une exécution déclarée interrompue (résultat tardif), et jamais perdu parce qu'un
 * ramassage est passé entre la lecture et l'écriture.
 */
final class RunLifecycleTest extends TestCase {

	private const NOW = 1789000000;
	private const ID  = '0123456789abcdef0123456789abcdef';

	/** Résultat d'une mise à jour réussie, de la forme de la réponse de `/command`. */
	private const DONE = [
		'status' => 'done',
		'result' => [
			'plugin'      => 'akismet/akismet.php',
			'old_version' => '5.3',
			'new_version' => '5.3.1',
		],
		'error'  => null,
	];

	/** Résultat d'une commande qui a levé une erreur. */
	private const FAILED = [
		'status' => 'failed',
		'result' => null,
		'error'  => 'Le paquet de mise à jour n’a pas pu être téléchargé.',
	];

	private FakeOptionsTable $table;

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_le_demarrage_passe_accepted_a_running_une_seule_fois(): void {
		$this->accepte( self::NOW );

		$acceptee = $this->table->rows[ RunStore::option_name( self::ID ) ]['value'];

		self::assertTrue( RunLifecycle::start( self::ID, self::NOW + 1 ) );

		self::assertStringStartsWith( 'UPDATE wp_options SET option_value = ', (string) end( $this->table->queries ) );
		self::assertStringEndsWith( "AND option_value = '" . addslashes( $acceptee ) . "'", (string) end( $this->table->queries ), 'Écriture conditionnelle sur la valeur relue.' );
		$record = RunStore::read( self::ID );
		self::assertSame( RunState::RUNNING, $record['state'] ?? null );
		self::assertSame( self::NOW + 1, $record['started_at'] ?? null );
		self::assertSame( self::NOW + 1, $record['alive_at'] ?? null, 'Le démarrage est le premier signe de vie.' );
		self::assertSame( self::NOW, $record['accepted_at'] ?? null );

		self::assertFalse( RunLifecycle::start( self::ID, self::NOW + 2 ), 'Déjà démarrée : jamais une seconde fois.' );
		self::assertSame( self::NOW + 1, RunStore::read( self::ID )['started_at'] ?? null );
	}

	public function test_une_execution_abandonnee_ne_demarre_jamais(): void {
		$this->accepte( self::NOW );
		RunReaper::sweep_one( self::ID, self::NOW + 121 );
		self::assertSame( RunState::ABANDONED, $this->etat() );

		self::assertFalse( RunLifecycle::start( self::ID, self::NOW + 122 ), 'Ramassée avant le démarrage : rien ne doit s\'exécuter.' );

		$record = RunStore::read( self::ID );
		self::assertSame( RunState::ABANDONED, $record['state'] ?? null );
		self::assertNull( $record['started_at'] ?? null );
	}

	/**
	 * Démarrage et ramassage au même instant, dans les deux ordres (faux $wpdb,
	 * `before_write` : l'autre processus écrit entre la lecture et l'écriture de celui
	 * qu'on observe). Une seule des deux écritures passe : l'exécution est démarrée (et
	 * garde son créneau), ou abandonnée (et rend son créneau), jamais les deux.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function ordres(): array {
		return [
			'le démarrage écrit le premier' => [ true ],
			'le ramassage écrit le premier' => [ false ],
		];
	}

	#[DataProvider( 'ordres' )]
	public function test_un_demarrage_gagne_contre_un_ramassage_simultane( bool $demarrage_d_abord ): void {
		ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );
		$this->accepte( self::NOW );
		$instant = self::NOW + 121;
		$autre   = null;

		if ( $demarrage_d_abord ) {
			$this->table->before_write = static function () use ( &$autre, $instant ): void {
				$autre = RunLifecycle::start( self::ID, $instant );
			};
			RunReaper::sweep_one( self::ID, $instant );
			$demarree = $autre;
		} else {
			$this->table->before_write = static function () use ( $instant ): void {
				RunReaper::sweep_one( self::ID, $instant );
			};
			$demarree = RunLifecycle::start( self::ID, $instant );
		}

		$record = RunStore::read( self::ID );
		self::assertSame( $demarrage_d_abord, $demarree );
		if ( $demarrage_d_abord ) {
			self::assertSame( RunState::RUNNING, $record['state'] ?? null, 'Le ramassage perd son écriture : la valeur a changé.' );
			self::assertNull( $record['finished_at'] ?? null );
			self::assertSame( self::ID, ExecutionSlot::holder()['command_id'] ?? null, 'Le créneau reste à l\'exécution démarrée.' );
		} else {
			self::assertSame( RunState::ABANDONED, $record['state'] ?? null, 'Le démarrage perd, relit et renonce.' );
			self::assertNull( $record['started_at'] ?? null );
			self::assertNull( ExecutionSlot::holder(), 'Close par le ramassage : créneau rendu.' );
		}
	}

	public function test_signe_de_vie_et_etape(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );

		RunLifecycle::alive( self::ID, self::NOW + 30, 'upgrading' );
		$record = RunStore::read( self::ID );
		self::assertSame( self::NOW + 30, $record['alive_at'] ?? null );
		self::assertSame( 'upgrading', $record['step'] ?? null );

		RunLifecycle::alive( self::ID, self::NOW + 60, null );
		$record = RunStore::read( self::ID );
		self::assertSame( self::NOW + 60, $record['alive_at'] ?? null );
		self::assertSame( 'upgrading', $record['step'] ?? null, 'Sans étape nouvelle, la précédente reste.' );
		self::assertSame( self::NOW, $record['started_at'] ?? null );
		self::assertSame( RunState::RUNNING, $record['state'] ?? null );
	}

	/**
	 * Un signe de vie ne fait rien sur une exécution qui n'est pas en cours : il ne
	 * démarre pas une exécution acceptée, ne rouvre ni ne réécrit une exécution conclue.
	 *
	 * @return array<string, array{string}>
	 */
	public static function etats_hors_cours(): array {
		return [
			'acceptée'    => [ RunState::ACCEPTED ],
			'réussie'     => [ RunState::DONE ],
			'échouée'     => [ RunState::FAILED ],
			'interrompue' => [ RunState::INTERRUPTED ],
			'abandonnée'  => [ RunState::ABANDONED ],
		];
	}

	#[DataProvider( 'etats_hors_cours' )]
	public function test_signe_de_vie_sans_effet_hors_d_une_execution_en_cours( string $state ): void {
		$this->pose( $state );
		$avant = $this->table->rows;

		RunLifecycle::alive( self::ID, self::NOW + 30, 'upgrading' );

		self::assertSame( $avant, $this->table->rows );
		self::assertSame( [], $this->ecritures() );
	}

	/**
	 * Un signe de vie perdu (un ramassage a écrit entre sa lecture et son écriture) n'est
	 * pas réessayé : le suivant le remplacerait, et ici l'exécution n'est plus en cours.
	 */
	public function test_un_signe_de_vie_perdu_n_est_pas_reessaye(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		$this->table->before_write = static function (): void {
			RunReaper::sweep_one( self::ID, self::NOW + 700 );
		};
		$ecritures_avant = count( $this->ecritures() );

		RunLifecycle::alive( self::ID, self::NOW + 700, 'upgrading' );

		self::assertCount( $ecritures_avant + 2, $this->ecritures(), 'Celle du ramassage, puis la nôtre, perdue : pas de nouvel essai.' );
		$record = RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $record['state'] ?? null );
		self::assertSame( self::NOW, $record['alive_at'] ?? null );
		self::assertArrayNotHasKey( 'step', $record );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function resultats(): array {
		return [
			'réussie' => [ self::DONE, RunState::DONE ],
			'échouée' => [ self::FAILED, RunState::FAILED ],
		];
	}

	#[DataProvider( 'resultats' )]
	public function test_le_resultat_conclut_l_execution_selon_son_statut( array $outcome, string $state ): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		RunLifecycle::alive( self::ID, self::NOW + 40, 'upgrading' );

		self::assertTrue( RunLifecycle::finish( self::ID, $outcome, self::NOW + 90 ), 'Résultat écrit.' );

		$record = RunStore::read( self::ID );
		self::assertSame( $state, $record['state'] ?? null );
		self::assertSame( $outcome, $record['outcome'] ?? null, 'Le résultat est gardé tel que la commande l\'a rendu.' );
		self::assertSame( self::NOW + 90, $record['finished_at'] ?? null );
		self::assertSame( self::NOW + 90, $record['alive_at'] ?? null, 'La fin est le dernier signe de vie.' );
		self::assertSame( self::NOW, $record['started_at'] ?? null );
		self::assertSame( 'upgrading', $record['step'] ?? null, 'La dernière étape est gardée.' );
		self::assertArrayNotHasKey( 'interruption', $record );
	}

	/**
	 * Une exécution déclarée interrompue par le ramassage (étape de plus de 10 minutes
	 * sans signe de vie) était en réalité vivante : son résultat est écrit quand même. Un
	 * résultat réel vaut mieux qu'une présomption. L'interruption est gardée pour
	 * l'historique.
	 */
	public function test_resultat_tardif_ecrit_apres_interrupted(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		RunReaper::sweep_one( self::ID, self::NOW + 601 );
		$interruption = RunStore::read( self::ID )['interruption'] ?? null;
		self::assertSame( RunReaper::REASON_NO_SIGN_OF_LIFE, $interruption['reason'] ?? null );

		self::assertTrue( RunLifecycle::finish( self::ID, self::DONE, self::NOW + 700 ), 'Résultat tardif écrit.' );

		$record = RunStore::read( self::ID );
		self::assertSame( RunState::DONE, $record['state'] ?? null );
		self::assertSame( self::DONE, $record['outcome'] ?? null );
		self::assertSame( self::NOW + 700, $record['finished_at'] ?? null );
		self::assertSame( $interruption, $record['interruption'] ?? null, 'L\'interruption reste dans l\'historique.' );
	}

	/**
	 * Le ramassage passe entre la lecture de l'exécution (en cours) et l'écriture du
	 * résultat : l'écriture conditionnelle est perdue, le résultat relit (interrompue) et
	 * réécrit. Il n'est jamais perdu.
	 */
	public function test_resultat_ecrit_meme_si_le_ramassage_passe_entre_lecture_et_ecriture(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		$this->table->before_write = static function (): void {
			RunReaper::sweep_one( self::ID, self::NOW + 601 );
		};

		self::assertTrue( RunLifecycle::finish( self::ID, self::FAILED, self::NOW + 601 ), 'Résultat écrit au second essai.' );

		$record = RunStore::read( self::ID );
		self::assertSame( RunState::FAILED, $record['state'] ?? null );
		self::assertSame( self::FAILED, $record['outcome'] ?? null );
		self::assertSame( RunReaper::REASON_NO_SIGN_OF_LIFE, $record['interruption']['reason'] ?? null, 'Le ramassage a bien eu lieu entre-temps.' );
	}

	/**
	 * Écritures concurrentes sans fin : le résultat est réessayé trois fois au plus,
	 * jamais en boucle sans fin.
	 */
	public function test_le_resultat_est_reessaye_trois_fois_au_plus(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		$signe = self::NOW;
		$autre = function () use ( &$autre, &$signe ): void {
			// Un autre processus réécrit l'exécution, toujours en cours, juste avant chacune
			// de nos écritures.
			$name               = RunStore::option_name( self::ID );
			$record             = (array) json_decode( $this->table->rows[ $name ]['value'], true );
			$record['alive_at'] = ++$signe;

			$this->table->rows[ $name ]['value'] = (string) json_encode( $record );
			$this->table->before_write           = $autre;
		};
		$this->table->before_write = $autre;
		$ecritures_avant           = count( $this->ecritures() );

		$ecrit                     = RunLifecycle::finish( self::ID, self::DONE, self::NOW + 90 );
		$this->table->before_write = null;

		self::assertFalse( $ecrit, 'Essais épuisés : résultat non écrit.' );
		self::assertCount( $ecritures_avant + 3, $this->ecritures(), 'Trois écritures au plus.' );
		self::assertSame( RunState::RUNNING, $this->etat() );
	}

	/**
	 * Un résultat n'est écrit que sur une exécution en cours ou interrompue : jamais sur
	 * une exécution qui n'a pas démarré, ni sur une exécution déjà conclue autrement.
	 *
	 * @return array<string, array{string}>
	 */
	public static function etats_sans_resultat(): array {
		return [
			'acceptée'   => [ RunState::ACCEPTED ],
			'réussie'    => [ RunState::DONE ],
			'échouée'    => [ RunState::FAILED ],
			'abandonnée' => [ RunState::ABANDONED ],
		];
	}

	#[DataProvider( 'etats_sans_resultat' )]
	public function test_aucun_resultat_ecrit_hors_d_une_execution_en_cours_ou_interrompue( string $state ): void {
		$this->pose( $state );
		$avant = $this->table->rows;

		self::assertFalse( RunLifecycle::finish( self::ID, self::DONE, self::NOW + 90 ) );

		self::assertSame( $avant, $this->table->rows );
		self::assertSame( [], $this->ecritures() );
	}

	public function test_interruption_d_une_execution_en_cours(): void {
		$this->accepte( self::NOW );
		RunLifecycle::start( self::ID, self::NOW );
		$reprise = [
			'plugin_file' => 'akismet/akismet.php',
			'outcome'     => 'restored',
		];

		self::assertTrue( RunLifecycle::interrupt( self::ID, 'fatal_error', 'Allowed memory size exhausted', $reprise, self::NOW + 50 ) );

		$record = RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $record['state'] ?? null );
		self::assertSame( self::NOW + 50, $record['finished_at'] ?? null );
		self::assertSame(
			[
				'reason'   => 'fatal_error',
				'error'    => 'Allowed memory size exhausted',
				'recovery' => $reprise,
			],
			$record['interruption'] ?? null
		);
		self::assertArrayNotHasKey( 'outcome', $record );

		self::assertFalse( RunLifecycle::interrupt( self::ID, 'fatal_error', 'autre', null, self::NOW + 60 ), 'Déjà interrompue : l\'interruption n\'est pas réécrite.' );
		self::assertSame( 'Allowed memory size exhausted', RunStore::read( self::ID )['interruption']['error'] ?? null );
	}

	#[DataProvider( 'etats_sans_resultat' )]
	public function test_seule_une_execution_en_cours_est_interrompue( string $state ): void {
		$this->pose( $state );
		$avant = $this->table->rows;

		self::assertFalse( RunLifecycle::interrupt( self::ID, 'fatal_error', 'Erreur', null, self::NOW + 50 ) );

		self::assertSame( $avant, $this->table->rows );
	}

	/**
	 * Base qui refuse l'écriture, puis la lecture : rien ne démarre, rien ne lève (le
	 * signe de vie et le résultat sont écrits au milieu ou à la fin d'une mise à jour), et
	 * rien n'est conclu sur une lecture refusée.
	 */
	public function test_base_qui_refuse_rien_ne_demarre_et_rien_ne_leve(): void {
		$this->accepte( self::NOW );
		$acceptee = $this->table->rows;

		foreach ( [ 'fails', 'read_fails' ] as $panne ) {
			$this->table->{$panne} = true;

			self::assertFalse( RunLifecycle::start( self::ID, self::NOW + 1 ), $panne . ' : pas de démarrage, rien ne s\'exécute.' );
			RunLifecycle::alive( self::ID, self::NOW + 2, 'upgrading' );
			self::assertFalse( RunLifecycle::finish( self::ID, self::DONE, self::NOW + 3 ), $panne );
			self::assertFalse( RunLifecycle::interrupt( self::ID, 'fatal_error', 'Erreur', null, self::NOW + 4 ) );
			self::assertSame( $acceptee, $this->table->rows, $panne );

			$this->table->{$panne} = false;
		}

		// Démarrée, puis la base refuse l'écriture du résultat : l'écriture refusée n'est
		// pas réessayée à l'identique, et rien ne lève.
		self::assertTrue( RunLifecycle::start( self::ID, self::NOW + 5 ) );
		$demarree           = $this->table->rows;
		$this->table->fails = true;
		$ecritures_avant    = count( $this->ecritures() );
		self::assertFalse( RunLifecycle::finish( self::ID, self::DONE, self::NOW + 90 ) );
		self::assertCount( $ecritures_avant + 1, $this->ecritures() );
		self::assertSame( $demarree, $this->table->rows );
	}

	public function test_numero_inconnu_ou_mal_forme_rien_n_est_ecrit(): void {
		self::assertFalse( RunLifecycle::start( self::ID, self::NOW ) );
		self::assertFalse( RunLifecycle::start( 'pas-un-numero', self::NOW ) );
		RunLifecycle::alive( self::ID, self::NOW, null );
		RunLifecycle::finish( self::ID, self::DONE, self::NOW );
		self::assertFalse( RunLifecycle::interrupt( self::ID, 'fatal_error', 'Erreur', null, self::NOW ) );

		self::assertSame( [], $this->table->rows );
		self::assertSame( [], $this->ecritures() );
	}

	/** Enregistre l'exécution, acceptée à `$at`, comme la route d'acceptation. */
	private function accepte( int $at ): void {
		self::assertTrue(
			RunStore::create(
				[
					'command_id'  => self::ID,
					'command'     => 'update_plugin',
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => RunState::ACCEPTED,
					'accepted_at' => $at,
					'started_at'  => null,
					'alive_at'    => null,
					'finished_at' => null,
				]
			)
		);
	}

	/** Pose l'exécution dans l'état `$state`, avec des dates récentes. */
	private function pose( string $state ): void {
		$this->table->rows[ RunStore::option_name( self::ID ) ] = [
			'value'    => (string) json_encode(
				[
					'command_id'  => self::ID,
					'command'     => 'update_plugin',
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => $state,
					'accepted_at' => self::NOW - 20,
					'started_at'  => RunState::ACCEPTED === $state ? null : self::NOW - 19,
					'alive_at'    => RunState::ACCEPTED === $state ? null : self::NOW - 10,
					'finished_at' => RunState::is_terminal( $state ) ? self::NOW - 5 : null,
				]
			),
			'autoload' => 'no',
		];
	}

	private function etat(): ?string {
		return RunStore::read( self::ID )['state'] ?? null;
	}

	/** @return list<string> Écritures tentées (création, changement d'état, suppression). */
	private function ecritures(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => ! str_starts_with( $q, 'SELECT ' ) ) );
	}
}
