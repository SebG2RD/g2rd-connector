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
 * Ramassage des exécutions mortes en route (§ 3.5 de la spécification, cas 2 et 3) : un
 * processus tué sans fonction d'arrêt (CloudLinux, `kill`) ne clôt pas lui-même son
 * exécution. Acceptée depuis plus de 120 s sans démarrer : abandonnée (rien n'a été
 * modifié). En cours sans signe de vie depuis plus de 600 s : interrompue (le travail est
 * peut-être partiel). Chaque passage se fait par écriture conditionnelle, puis rend le
 * créneau s'il était tenu par l'exécution close. Le site ne relance jamais rien.
 */
final class RunReaperTest extends TestCase {

	private const NOW   = 1789000000;
	private const ID    = '0123456789abcdef0123456789abcdef';
	private const AUTRE = 'fedcba9876543210fedcba9876543210';

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

	/**
	 * Les deux portes du ramassage : un numéro (lecture par la plateforme) ou toutes les
	 * exécutions non terminées (acceptation).
	 *
	 * @return array<string, array{string}>
	 */
	public static function ramassages(): array {
		return [
			'un numéro' => [ 'un' ],
			'complet'   => [ 'complet' ],
		];
	}

	#[DataProvider( 'ramassages' )]
	public function test_accepted_garde_120_s_puis_abandoned_a_121_s( string $ramassage ): void {
		self::assertSame( 120, RunReaper::ACCEPTED_TIMEOUT_SECONDS );
		$this->accepte( self::ID, self::NOW );

		$this->ramasse( $ramassage, self::NOW + 120 );
		self::assertSame( RunState::ACCEPTED, $this->etat( self::ID ), 'À 120 s, le démarrage peut encore venir.' );

		$this->ramasse( $ramassage, self::NOW + 121 );
		$record = RunStore::read( self::ID );
		self::assertSame( RunState::ABANDONED, $record['state'] ?? null );
		self::assertSame( self::NOW + 121, $record['finished_at'] ?? null, 'Close à l\'instant du ramassage.' );
		self::assertNull( $record['started_at'] ?? null, 'Jamais démarrée : rien n\'a été modifié.' );
		self::assertArrayNotHasKey( 'outcome', $record );
		self::assertArrayNotHasKey( 'interruption', $record );
	}

	#[DataProvider( 'ramassages' )]
	public function test_running_garde_600_s_sans_signe_de_vie_puis_interrupted( string $ramassage ): void {
		self::assertSame( 600, RunReaper::SILENCE_TIMEOUT_SECONDS );
		self::assertSame( ExecutionSlot::STALE_AFTER_SECONDS, RunReaper::SILENCE_TIMEOUT_SECONDS, 'Même mort que le créneau et la transaction.' );
		$this->demarre( self::ID, self::NOW );

		$this->ramasse( $ramassage, self::NOW + 600 );
		self::assertSame( RunState::RUNNING, $this->etat( self::ID ), '600 s de silence : encore vivante.' );

		$this->ramasse( $ramassage, self::NOW + 601 );
		$record = RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $record['state'] ?? null );
		self::assertSame( self::NOW + 601, $record['finished_at'] ?? null );
		self::assertSame( self::NOW, $record['started_at'] ?? null, 'Le démarrage est gardé.' );
		self::assertSame( self::NOW, $record['alive_at'] ?? null, 'Le dernier signe de vie est gardé : il dit depuis quand elle se tait.' );
		self::assertSame( RunReaper::REASON_NO_SIGN_OF_LIFE, $record['interruption']['reason'] ?? null );
		self::assertSame( 'no_sign_of_life', RunReaper::REASON_NO_SIGN_OF_LIFE );
		self::assertArrayHasKey( 'recovery', $record['interruption'] ?? [] );
		self::assertNull( $record['interruption']['recovery'], 'Aucun résultat de reprise connu du ramassage.' );
		self::assertArrayNotHasKey( 'outcome', $record, 'Aucun résultat : la plateforme vérifie l\'état réel du site.' );

		$message = (string) ( $record['interruption']['error'] ?? '' );
		self::assertStringContainsString( '« update_plugin »', $message, 'Quoi : la commande.' );
		self::assertStringContainsString( 'Cause probable :', $message );
		self::assertStringContainsString( 'Action :', $message );
	}

	#[DataProvider( 'ramassages' )]
	public function test_un_signe_de_vie_repousse_la_mort( string $ramassage ): void {
		$this->demarre( self::ID, self::NOW );
		RunLifecycle::alive( self::ID, self::NOW + 500, 'upgrading' );

		$this->ramasse( $ramassage, self::NOW + 1100 );
		self::assertSame( RunState::RUNNING, $this->etat( self::ID ), '600 s depuis le dernier signe de vie, pas depuis le démarrage.' );

		$this->ramasse( $ramassage, self::NOW + 1101 );
		$record = RunStore::read( self::ID );
		self::assertSame( RunState::INTERRUPTED, $record['state'] ?? null );
		self::assertSame( 'upgrading', $record['step'] ?? null, 'La dernière étape connue est gardée.' );
	}

	/**
	 * Une exécution conclue n'est jamais rouverte ni réécrite par le ramassage, même
	 * vieille de 30 jours et sans aucun signe de vie.
	 *
	 * @return array<string, array{string}>
	 */
	public static function etats_terminaux(): array {
		return [
			'réussie'     => [ RunState::DONE ],
			'échouée'     => [ RunState::FAILED ],
			'interrompue' => [ RunState::INTERRUPTED ],
			'abandonnée'  => [ RunState::ABANDONED ],
		];
	}

	#[DataProvider( 'etats_terminaux' )]
	public function test_un_etat_terminal_n_est_jamais_modifie_par_le_ramassage( string $state ): void {
		$this->pose( self::ID, $state, self::NOW - 30 * 86400 );
		$avant = $this->table->rows;

		RunReaper::sweep_one( self::ID, self::NOW );
		self::assertSame( 0, RunReaper::sweep( self::NOW, 200 ) );

		self::assertSame( $avant, $this->table->rows );
		self::assertSame( [], $this->ecritures(), 'Aucune écriture tentée.' );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function executions_closes(): array {
		return [
			'abandonnée'  => [ false ],
			'interrompue' => [ true ],
		];
	}

	#[DataProvider( 'executions_closes' )]
	public function test_le_creneau_est_rendu_quand_le_ramassage_clot_son_execution( bool $demarree ): void {
		ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );
		if ( $demarree ) {
			$this->demarre( self::ID, self::NOW );
		} else {
			$this->accepte( self::ID, self::NOW );
		}
		$mort = self::NOW + ( $demarree ? 601 : 121 );

		RunReaper::sweep_one( self::ID, $mort - 1 );
		self::assertSame( self::ID, ExecutionSlot::holder()['command_id'] ?? null, 'Vivante : le créneau reste à elle.' );

		RunReaper::sweep_one( self::ID, $mort );
		self::assertTrue( RunState::is_terminal( $this->etat( self::ID ) ) );
		self::assertNull( ExecutionSlot::holder(), 'Close par le ramassage : le site est libre aussitôt, sans attendre 600 s.' );
	}

	public function test_le_creneau_d_une_autre_execution_n_est_pas_rendu(): void {
		$this->accepte( self::ID, self::NOW - 3600 );
		ExecutionSlot::acquire( self::AUTRE, 'update_theme', self::NOW );

		self::assertSame( 1, RunReaper::sweep( self::NOW, 200 ) );

		self::assertSame( RunState::ABANDONED, $this->etat( self::ID ) );
		self::assertSame( self::AUTRE, ExecutionSlot::holder()['command_id'] ?? null, 'Le créneau est à une autre exécution, vivante.' );
	}

	public function test_le_ramassage_complet_est_borne(): void {
		$ids = array_map( static fn ( int $n ): string => md5( 'execution-' . $n ), range( 1, 5 ) );
		foreach ( $ids as $id ) {
			$this->accepte( $id, self::NOW );
		}

		$creations = count( $this->table->queries );

		self::assertSame( 0, RunReaper::sweep( self::NOW + 121, 0 ) );
		self::assertCount( $creations, $this->table->queries, 'Borne nulle : aucune lecture.' );

		self::assertSame( 3, RunReaper::sweep( self::NOW + 121, 3 ) );
		self::assertStringEndsWith( ' LIMIT 3', $this->recherches()[0], 'Au plus 3 exécutions lues.' );
		self::assertSame(
			[
				RunState::ABANDONED => 3,
				RunState::ACCEPTED  => 2,
			],
			$this->compte_des_etats( $ids )
		);

		self::assertSame( 2, RunReaper::sweep( self::NOW + 121, 3 ), 'Le passage suivant reprend la suite.' );
		self::assertSame( 0, RunReaper::sweep( self::NOW + 121, 3 ) );
		self::assertSame( [ RunState::ABANDONED => 5 ], $this->compte_des_etats( $ids ) );
	}

	/**
	 * Le ramassage complet ne lit que les exécutions acceptées ou en cours : les
	 * enregistrements conclus (200 gardés, jusqu'à 64 Ko chacun) ne sont pas transférés,
	 * et n'entament pas la borne. Le créneau n'est pas lu non plus.
	 */
	public function test_les_executions_conclues_n_entament_pas_la_borne(): void {
		foreach ( range( 1, 5 ) as $n ) {
			$this->pose( str_repeat( (string) $n, 32 ), RunState::DONE, self::NOW - 600 );
		}
		ExecutionSlot::acquire( self::AUTRE, 'update_core', self::NOW );
		$derniere = 'ffffffffffffffffffffffffffffffff';
		$this->accepte( $derniere, self::NOW - 3600 );

		self::assertSame( 1, RunReaper::sweep( self::NOW, 2 ) );

		self::assertSame( RunState::ABANDONED, $this->etat( $derniere ), 'Rangée après les conclues, elle est quand même atteinte.' );
		self::assertSame(
			"SELECT option_name, option_value FROM wp_options WHERE option_name LIKE '" . addslashes( 'g2rd\_cmdrun\_%' ) . "'"
			. " AND ( option_value LIKE '" . addslashes( '%"state":"accepted"%' ) . "' OR option_value LIKE '" . addslashes( '%"state":"running"%' ) . "' ) LIMIT 2",
			$this->recherches()[0]
		);
	}

	/**
	 * Une date d'acceptation ou un signe de vie daté de plus de 600 s dans le futur
	 * (horloge du serveur qui a reculé, entier démesuré) ne garde pas l'exécution ouverte
	 * sans limite : tenue pour illisible, comme pour le créneau, elle est close.
	 *
	 * @return array<string, array{string, int, string}> [ état, date, état attendu ]
	 */
	public static function dates_du_futur(): array {
		return [
			'acceptée dans 601 s'          => [ RunState::ACCEPTED, self::NOW + 601, RunState::ABANDONED ],
			'acceptée dans 600 s'          => [ RunState::ACCEPTED, self::NOW + 600, RunState::ACCEPTED ],
			'signe de vie dans une heure'  => [ RunState::RUNNING, self::NOW + 3600, RunState::INTERRUPTED ],
			'signe de vie entier démesuré' => [ RunState::RUNNING, PHP_INT_MAX, RunState::INTERRUPTED ],
			'signe de vie dans 600 s'      => [ RunState::RUNNING, self::NOW + 600, RunState::RUNNING ],
		];
	}

	#[DataProvider( 'dates_du_futur' )]
	public function test_une_date_du_futur_au_dela_de_600_s_est_ramassee( string $state, int $date, string $attendu ): void {
		$this->pose( self::ID, $state, $date );

		RunReaper::sweep_one( self::ID, self::NOW );

		self::assertSame( $attendu, $this->etat( self::ID ) );
	}

	/**
	 * Le ramassage est du ménage : une base qui refuse la lecture ou l'écriture ne le fait
	 * jamais lever, et rien n'est conclu sur une lecture refusée. Le passage suivant reprend.
	 */
	public function test_base_qui_refuse_rien_n_est_conclu_et_rien_ne_leve(): void {
		ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );
		$this->accepte( self::ID, self::NOW );
		$avant = $this->table->rows;

		$this->table->read_fails = true;
		RunReaper::sweep_one( self::ID, self::NOW + 121 );
		self::assertSame( 0, RunReaper::sweep( self::NOW + 121, 200 ) );
		self::assertSame( $avant, $this->table->rows );

		$this->table->read_fails = false;
		$this->table->fails      = true;
		RunReaper::sweep_one( self::ID, self::NOW + 121 );
		self::assertSame( 0, RunReaper::sweep( self::NOW + 121, 200 ) );
		self::assertSame( $avant, $this->table->rows, 'Écriture refusée : ni close, ni créneau rendu.' );

		$this->table->fails = false;
		self::assertSame( 1, RunReaper::sweep( self::NOW + 121, 200 ), 'La base revenue, le passage suivant la clôt.' );
		self::assertNull( ExecutionSlot::holder() );
	}

	/**
	 * Numéro inconnu, mal formé, ou valeur illisible : rien à clore, rien n'est écrit. Une
	 * valeur abîmée qui mentionne un état ouvert est trouvée par la recherche, puis écartée.
	 */
	public function test_numero_inconnu_mal_forme_ou_illisible_rien_n_est_ecrit(): void {
		RunReaper::sweep_one( 'pas-un-numero', self::NOW );
		self::assertSame( [], $this->table->queries, 'Numéro mal formé : aucune requête.' );

		RunReaper::sweep_one( self::ID, self::NOW );
		$this->table->rows[ RunStore::option_name( self::AUTRE ) ] = [
			'value'    => '{"state":"accepted","accepted_at":1}',
			'autoload' => 'no',
		];
		RunReaper::sweep_one( self::AUTRE, self::NOW );
		self::assertSame( 0, RunReaper::sweep( self::NOW, 200 ) );

		self::assertSame( [], $this->ecritures() );
	}

	/** Passe le ramassage d'un numéro, ou le ramassage complet. */
	private function ramasse( string $ramassage, int $now ): void {
		if ( 'un' === $ramassage ) {
			RunReaper::sweep_one( self::ID, $now );
			return;
		}
		RunReaper::sweep( $now, 200 );
	}

	/** Enregistre une exécution acceptée à `$at`, comme la route d'acceptation. */
	private function accepte( string $id, int $at ): void {
		self::assertTrue(
			RunStore::create(
				[
					'command_id'  => $id,
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

	/** Acceptée et démarrée à `$at`. */
	private function demarre( string $id, int $at ): void {
		$this->accepte( $id, $at );
		self::assertTrue( RunLifecycle::start( $id, $at ) );
	}

	/**
	 * Pose un enregistrement dans l'état `$state`, toutes ses dates à `$at` (sans
	 * finished_at pour une exécution ouverte).
	 */
	private function pose( string $id, string $state, int $at ): void {
		$this->table->rows[ RunStore::option_name( $id ) ] = [
			'value'    => (string) json_encode(
				[
					'command_id'  => $id,
					'command'     => 'update_plugin',
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => $state,
					'accepted_at' => $at,
					'started_at'  => RunState::ACCEPTED === $state ? null : $at,
					'alive_at'    => RunState::ACCEPTED === $state ? null : $at,
					'finished_at' => RunState::is_terminal( $state ) ? $at : null,
				]
			),
			'autoload' => 'no',
		];
	}

	private function etat( string $id ): ?string {
		return RunStore::read( $id )['state'] ?? null;
	}

	/**
	 * Nombre d'exécutions par état, du plus fréquent au moins fréquent.
	 *
	 * @param list<string> $ids
	 * @return array<string, int>
	 */
	private function compte_des_etats( array $ids ): array {
		$compte = array_count_values( array_map( fn ( string $id ): string => (string) $this->etat( $id ), $ids ) );
		arsort( $compte );
		return $compte;
	}

	/** @return list<string> Écritures tentées (création, changement d'état, suppression). */
	private function ecritures(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => ! str_starts_with( $q, 'SELECT ' ) ) );
	}

	/** @return list<string> Recherches des exécutions non terminées. */
	private function recherches(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SELECT option_name, option_value ' ) ) );
	}
}
