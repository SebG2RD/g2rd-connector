<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Purge des exécutions en arrière-plan (§ 3.2 de la spécification, décision n° 7) :
 * compte rendu gardé 7 jours après la fin ; au plus 200 enregistrements, les plus
 * anciennes exécutions terminées partent d'abord ; jamais une exécution non terminée,
 * ni le créneau ; un nombre de suppressions borné par passage. Et la suppression de
 * tout, créneau compris, à la désinstallation.
 */
final class RunStorePurgeTest extends TestCase {

	private const NOW = 1789000000;
	private const DAY = 86400;

	/** Créneau d'exécution du site (contrat stable, cf. tâche 14). */
	private const SLOT = 'g2rd_cmdrun_slot';

	private FakeOptionsTable $table;

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Chaque état terminal, fini depuis 7 jours et une seconde ; et, sans date de fin,
	 * dont la plus récente des dates connues (signe de vie, ou acceptation) a cet âge.
	 *
	 * @return array<string, array{0: string, 1: array<string, int|null>}>
	 */
	public static function terminees_depuis_plus_de_7_jours(): array {
		$limite = self::NOW - 7 * self::DAY - 1;

		return [
			'réussie' => [ RunState::DONE, self::dates_de_fin( $limite ) ],
			'échouée' => [ RunState::FAILED, self::dates_de_fin( $limite ) ],
			'interrompue' => [ RunState::INTERRUPTED, self::dates_de_fin( $limite ) ],
			'abandonnée' => [ RunState::ABANDONED, self::dates_de_fin( $limite ) ],
			'abandonnée sans date de fin, acceptée il y a plus de 7 jours' => [
				RunState::ABANDONED,
				[
					'accepted_at' => $limite,
					'finished_at' => null,
				],
			],
			'interrompue sans date de fin, dernier signe de vie il y a plus de 7 jours' => [
				RunState::INTERRUPTED,
				[
					'accepted_at' => $limite - self::DAY,
					'started_at'  => $limite - self::DAY,
					'alive_at'    => $limite,
					'finished_at' => null,
				],
			],
		];
	}

	public function test_la_conservation_est_celle_des_resultats_de_reprise(): void {
		self::assertSame( 7 * self::DAY, RunStore::RETENTION_SECONDS, 'Décision n° 7 : 7 jours.' );
		self::assertSame( PendingOutcomes::TTL, RunStore::RETENTION_SECONDS, 'Comme les résultats de reprise.' );
		self::assertSame( 200, RunStore::MAX_RECORDS );
	}

	/**
	 * @param array<string, int|null> $dates
	 */
	#[DataProvider( 'terminees_depuis_plus_de_7_jours' )]
	public function test_une_execution_terminee_depuis_plus_de_7_jours_est_supprimee( string $state, array $dates ): void {
		$this->put( self::id( 1 ), $state, $dates );
		$raw = $this->raw( self::id( 1 ) );

		self::assertSame( 1, RunStore::purge( self::NOW, 500 ) );

		self::assertSame( [], $this->table->rows );
		self::assertSame(
			"DELETE FROM wp_options WHERE option_name = 'g2rd_cmdrun_" . self::id( 1 ) . "' AND option_value = '" . addslashes( $raw ) . "'",
			end( $this->table->queries ),
			'Suppression conditionnelle, sur la valeur lue.'
		);
	}

	public function test_une_execution_terminee_depuis_moins_de_7_jours_est_gardee(): void {
		// Finie il y a 7 jours tout juste : « plus de 7 jours » n'est pas atteint.
		$this->put( self::id( 1 ), RunState::DONE, self::dates_de_fin( self::NOW - 7 * self::DAY ) );
		$this->put( self::id( 2 ), RunState::FAILED, self::dates_de_fin( self::NOW - self::DAY ) );
		// Acceptée il y a 30 jours, finie il y a 6 : l'âge compte depuis la fin.
		$this->put(
			self::id( 3 ),
			RunState::DONE,
			[
				'accepted_at' => self::NOW - 30 * self::DAY,
				'finished_at' => self::NOW - 6 * self::DAY,
			]
		);
		// Sans date de fin, la plus récente des dates connues compte : son dernier signe de vie.
		$this->put(
			self::id( 4 ),
			RunState::INTERRUPTED,
			[
				'accepted_at' => self::NOW - 8 * self::DAY,
				'started_at'  => self::NOW - 8 * self::DAY,
				'alive_at'    => self::NOW - 6 * self::DAY,
				'finished_at' => null,
			]
		);
		$avant = $this->table->rows;

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertSame( $avant, $this->table->rows );
		self::assertSame( [], $this->deletes(), 'Aucune suppression tentée.' );
	}

	public function test_au_dela_de_200_les_plus_anciennes_terminees_partent_d_abord(): void {
		// 205 exécutions terminées dans la semaine, de la plus récente (0) à la plus
		// ancienne (204) ; leurs numéros ne suivent pas cet ordre.
		for ( $i = 0; $i < 205; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW - 60 * ( $i + 1 ) ) );
		}
		// Deux exécutions non terminées, plus anciennes que toutes : elles comptent dans les
		// 200, mais ne partent jamais.
		$this->put( self::id( 1000 ), RunState::ACCEPTED, [ 'accepted_at' => self::NOW - self::DAY ] );
		$this->put(
			self::id( 1001 ),
			RunState::RUNNING,
			[
				'accepted_at' => self::NOW - self::DAY,
				'started_at'  => self::NOW - self::DAY,
				'alive_at'    => self::NOW - self::DAY,
			]
		);

		self::assertSame( 7, RunStore::purge( self::NOW, 500 ), '207 enregistrements : 7 de trop.' );

		self::assertCount( RunStore::MAX_RECORDS, $this->table->rows );
		for ( $i = 198; $i < 205; $i++ ) {
			self::assertArrayNotHasKey( RunStore::option_name( self::id( $i ) ), $this->table->rows, 'Les 7 plus anciennes terminées partent : ' . $i );
		}
		for ( $i = 0; $i < 198; $i++ ) {
			self::assertArrayHasKey( RunStore::option_name( self::id( $i ) ), $this->table->rows, 'Les plus récentes restent : ' . $i );
		}
		self::assertArrayHasKey( RunStore::option_name( self::id( 1000 ) ), $this->table->rows );
		self::assertArrayHasKey( RunStore::option_name( self::id( 1001 ) ), $this->table->rows );

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ), 'À 200, plus rien ne part.' );
	}

	public function test_une_execution_non_terminee_n_est_jamais_supprimee(): void {
		$il_y_a_30_jours = self::NOW - 30 * self::DAY;
		$this->put( self::id( 1 ), RunState::ACCEPTED, [ 'accepted_at' => $il_y_a_30_jours ] );
		$this->put(
			self::id( 2 ),
			RunState::RUNNING,
			[
				'accepted_at' => $il_y_a_30_jours,
				'started_at'  => $il_y_a_30_jours,
				'alive_at'    => $il_y_a_30_jours,
			]
		);
		// Un état inconnu n'est pas un état terminal.
		$this->put( self::id( 3 ), 'paused', [ 'accepted_at' => $il_y_a_30_jours ] );
		// Une valeur illisible ne dit ni son état ni sa date : gardée (retirée à la désinstallation).
		$this->table->rows[ RunStore::option_name( self::id( 4 ) ) ] = [
			'value'    => '{"command_id":',
			'autoload' => 'no',
		];
		// Au-delà de 200 enregistrements : seules les terminées partent, ici aucune.
		for ( $i = 10; $i < 210; $i++ ) {
			$this->put( self::id( $i ), RunState::RUNNING, [ 'alive_at' => $il_y_a_30_jours ] );
		}
		$avant = $this->table->rows;

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertSame( $avant, $this->table->rows );
		self::assertSame( [], $this->deletes() );
	}

	public function test_le_creneau_n_est_jamais_supprime_par_la_purge(): void {
		// 200 exécutions terminées dans la semaine : le créneau ne compte pas dans les 200.
		for ( $i = 0; $i < 200; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW - 60 * ( $i + 1 ) ) );
		}
		// Même s'il ressemblait à une exécution terminée depuis longtemps.
		$creneau = [
			'value'    => (string) json_encode(
				[
					'command_id'  => self::id( 0 ),
					'state'       => RunState::DONE,
					'finished_at' => self::NOW - 30 * self::DAY,
				]
			),
			'autoload' => 'no',
		];
		$this->table->rows[ self::SLOT ] = $creneau;
		// Ni aucune autre option du préfixe qui n'est pas un numéro d'exécution.
		$this->table->rows[ RunStore::OPTION_PREFIX . strtoupper( self::id( 0 ) ) ] = $creneau;

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertSame( $creneau, $this->table->rows[ self::SLOT ] );
		self::assertArrayHasKey( RunStore::OPTION_PREFIX . strtoupper( self::id( 0 ) ), $this->table->rows );
		self::assertCount( 202, $this->table->rows );
	}

	public function test_le_nombre_de_suppressions_est_borne(): void {
		// 30 exécutions finies depuis plus de 7 jours, de la plus récente (0) à la plus ancienne (29).
		for ( $i = 0; $i < 30; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW - 8 * self::DAY - $i ) );
		}

		self::assertSame( 0, RunStore::purge( self::NOW, 0 ) );
		self::assertSame( [], $this->table->queries, 'Aucune suppression permise : aucune requête.' );

		self::assertSame( 20, RunStore::purge( self::NOW, 20 ), 'Comme à chaque acceptation : 20 au plus.' );
		self::assertCount( 10, $this->table->rows );
		for ( $i = 0; $i < 10; $i++ ) {
			self::assertArrayHasKey( RunStore::option_name( self::id( $i ) ), $this->table->rows, 'Les plus anciennes sont parties d\'abord.' );
		}

		self::assertSame( 10, RunStore::purge( self::NOW, 20 ), 'Le passage suivant reprend la suite.' );
		self::assertSame( [], $this->table->rows );
	}

	/** La tâche biquotidienne (RestorePointPurgeJob::HOOK) purge à l'heure courante. */
	public function test_la_tache_biquotidienne_purge_a_l_heure_courante(): void {
		$maintenant = time();
		$this->put( self::id( 1 ), RunState::DONE, self::dates_de_fin( $maintenant - 8 * self::DAY ) );
		$this->put( self::id( 2 ), RunState::DONE, self::dates_de_fin( $maintenant - self::DAY ) );

		RunStore::purge_now();

		self::assertSame( [ RunStore::option_name( self::id( 2 ) ) ], array_keys( $this->table->rows ) );
	}

	/**
	 * Résultat tardif d'une exécution déclarée interrompue, écrit entre la lecture de la
	 * purge et sa suppression : la suppression, conditionnelle, n'a pas lieu.
	 */
	public function test_un_enregistrement_reecrit_entre_lecture_et_suppression_est_garde(): void {
		$this->put( self::id( 1 ), RunState::INTERRUPTED, self::dates_de_fin( self::NOW - 8 * self::DAY ) );
		$nom = RunStore::option_name( self::id( 1 ) );
		$tardif = (string) json_encode(
			[
				'command_id'  => self::id( 1 ),
				'state'       => RunState::DONE,
				'finished_at' => self::NOW,
			]
		);
		$this->table->before_write = function () use ( $nom, $tardif ): void {
			$this->table->rows[ $nom ]['value'] = $tardif;
		};

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertSame( $tardif, $this->table->rows[ $nom ]['value'] );
	}

	/**
	 * Deux purges en même temps (tâche biquotidienne et acceptation) : l'autre a déjà
	 * retiré l'excédent entre la lecture et la première suppression de celle-ci, qui
	 * n'en retire pas davantage.
	 */
	public function test_une_purge_simultanee_ne_fait_pas_partir_plus_que_l_excedent(): void {
		// 202 exécutions terminées dans la semaine : 2 de trop, les deux plus anciennes (200 et 201).
		for ( $i = 0; $i < 202; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW - 60 * ( $i + 1 ) ) );
		}
		$this->table->before_write = function (): void {
			unset( $this->table->rows[ RunStore::option_name( self::id( 200 ) ) ], $this->table->rows[ RunStore::option_name( self::id( 201 ) ) ] );
		};

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertCount( RunStore::MAX_RECORDS, $this->table->rows, 'Aucune suppression de plus que l\'excédent.' );
	}

	/** Ménage seulement : une base qui refuse s'arrête net, sans erreur, et sans insister. */
	public function test_base_qui_refuse_la_suppression(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW - 8 * self::DAY ) );
		}
		$this->table->fails = true;

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );

		self::assertCount( 5, $this->table->rows );
		self::assertCount( 1, $this->deletes(), 'Une seule suppression tentée.' );
	}

	public function test_base_qui_refuse_la_lecture(): void {
		$this->put( self::id( 1 ), RunState::DONE, self::dates_de_fin( self::NOW - 8 * self::DAY ) );
		$this->table->read_fails = true;

		self::assertSame( 0, RunStore::purge( self::NOW, 500 ) );
		self::assertCount( 1, $this->table->rows );
	}

	/**
	 * Désinstallation : toutes les options du préfixe partent, terminées ou non, créneau
	 * compris, lot après lot ; les options des autres extensions restent.
	 */
	public function test_la_desinstallation_retire_tout_par_lots_creneau_compris(): void {
		for ( $i = 0; $i < 1203; $i++ ) {
			$this->put( self::id( $i ), 0 === $i % 2 ? RunState::DONE : RunState::RUNNING, self::dates_de_fin( self::NOW ) );
		}
		$this->table->rows[ self::SLOT ] = [
			'value'    => '{}',
			'autoload' => 'no',
		];
		$this->table->rows[ RunStore::OPTION_PREFIX . 'illisible' ] = [
			'value'    => 'a:1:{}',
			'autoload' => 'no',
		];
		$this->table->rows['g2rd_cmdrunner_autre_extension'] = [
			'value'    => 'garde',
			'autoload' => 'yes',
		];
		$this->table->rows['g2rd_connector_settings'] = [
			'value'    => 'garde',
			'autoload' => 'yes',
		];

		self::assertSame( 1205, RunStore::delete_all() );

		self::assertSame( [ 'g2rd_cmdrunner_autre_extension', 'g2rd_connector_settings' ], array_keys( $this->table->rows ) );
		self::assertCount( 4, $this->scans(), 'Trois lots de 500 au plus, puis une recherche vide.' );
	}

	/** Une base qui refuse la suppression ne fait pas tourner la désinstallation sans fin. */
	public function test_la_desinstallation_s_arrete_si_la_base_refuse(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->put( self::id( $i ), RunState::DONE, self::dates_de_fin( self::NOW ) );
		}
		$this->table->fails = true;

		self::assertSame( 0, RunStore::delete_all() );

		self::assertCount( 3, $this->table->rows );
		self::assertCount( 1, $this->deletes(), 'Arrêt à la première suppression refusée.' );
	}

	/**
	 * Dates d'une exécution finie à `$finished_at` : acceptée et démarrée deux minutes
	 * plus tôt, dernier signe de vie dix secondes avant la fin.
	 *
	 * @return array<string, int>
	 */
	private static function dates_de_fin( int $finished_at ): array {
		return [
			'accepted_at' => $finished_at - 120,
			'started_at'  => $finished_at - 118,
			'alive_at'    => $finished_at - 10,
			'finished_at' => $finished_at,
		];
	}

	/** Numéro d'exécution n° `$n` : 32 caractères hexadécimaux, sans rapport avec l'ordre de `$n`. */
	private static function id( int $n ): string {
		return md5( 'execution-' . $n );
	}

	/**
	 * Écrit un enregistrement directement dans la table (sans passer par RunStore::create()).
	 *
	 * @param array<string, int|null> $dates
	 */
	private function put( string $id, string $state, array $dates ): void {
		$record = array_merge(
			[
				'command_id'  => $id,
				'command'     => 'update_plugin',
				'body_sha256' => str_repeat( 'ab', 32 ),
				'state'       => $state,
				'accepted_at' => null,
				'started_at'  => null,
				'alive_at'    => null,
				'finished_at' => null,
			],
			$dates
		);

		$this->table->rows[ RunStore::option_name( $id ) ] = [
			'value'    => (string) json_encode( $record ),
			'autoload' => 'no',
		];
	}

	private function raw( string $id ): string {
		return $this->table->rows[ RunStore::option_name( $id ) ]['value'];
	}

	/** @return list<string> Suppressions tentées. */
	private function deletes(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => str_starts_with( $q, 'DELETE ' ) ) );
	}

	/** @return list<string> Recherches par préfixe. */
	private function scans(): array {
		return array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SELECT option_name, option_value ' ) ) );
	}
}
