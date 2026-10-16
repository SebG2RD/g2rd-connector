<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\CommandRuns\AsyncProbe;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunHeartbeat;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Sonde de diagnostic `async_probe` (décision n° 4 du propriétaire) : éteinte sans
 * `G2RD_CONNECTOR_ASYNC_PROBE` dans wp-config.php ; allumée, elle attend la durée demandée
 * par pas de 10 s, donne un signe de vie à chaque pas, rend
 * {requested, slept, started_at, finished_at}, et ne touche à rien d'autre sur le site.
 *
 * L'attente, le signe de vie et l'horloge sont injectés : l'attente fait avancer l'horloge
 * du test et rend, comme sleep(), les secondes qu'elle n'a pas attendues.
 */
final class AsyncProbeTest extends TestCase {

	/** 10/09/2026 00:26:40 UTC. */
	private const NOW = 1789000000;
	private const ID  = '0123456789abcdef0123456789abcdef';

	private FakeOptionsTable $table;

	/** Heure rendue par l'horloge injectée. */
	private int $now = self::NOW;

	/**
	 * Appels de l'attente et du signe de vie, dans l'ordre.
	 *
	 * @var list<string>
	 */
	private array $journal = [];

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		$this->now       = self::NOW;
		$this->journal   = [];
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		RunHeartbeat::detach();
	}

	protected function tearDown(): void {
		RunHeartbeat::detach();
		ignore_user_abort( false );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** Sans la constante (cas de tous les sites hors pilote), la sonde est éteinte. */
	public function test_eteinte_sans_constante(): void {
		self::assertSame( 'G2RD_CONNECTOR_ASYNC_PROBE', AsyncProbe::CONSTANT );
		self::assertFalse( defined( AsyncProbe::CONSTANT ) );

		self::assertFalse( AsyncProbe::enabled() );
	}

	/**
	 * Constante posée mais fausse : éteinte. Processus à part : une constante définie le
	 * reste jusqu'à la fin du processus PHP.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_eteinte_si_constante_fausse(): void {
		define( 'G2RD_CONNECTOR_ASYNC_PROBE', false );

		self::assertFalse( AsyncProbe::enabled() );
	}

	/** La ligne du pilote, `define( 'G2RD_CONNECTOR_ASYNC_PROBE', true );`, l'allume. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_allumee_si_constante_vraie(): void {
		define( 'G2RD_CONNECTOR_ASYNC_PROBE', true );

		self::assertTrue( AsyncProbe::enabled() );
	}

	/**
	 * Durée demandée → attentes successives, chacune suivie d'un signe de vie. Une durée
	 * qui n'est pas un multiple de 10 (refusée par la route) finit par un pas plus court.
	 *
	 * @return iterable<string, array{int, list<string>}>
	 */
	public static function durees(): iterable {
		yield '10 s : un pas' => [ 10, [ 'attente 10', 'signe de vie' ] ];
		yield '30 s : trois pas' => [ 30, [ 'attente 10', 'signe de vie', 'attente 10', 'signe de vie', 'attente 10', 'signe de vie' ] ];
		yield '15 s : un pas, puis 5 s' => [ 15, [ 'attente 10', 'signe de vie', 'attente 5', 'signe de vie' ] ];
		yield '600 s : soixante pas' => [ 600, array_merge( ...array_fill( 0, 60, [ 'attente 10', 'signe de vie' ] ) ) ];
	}

	/**
	 * @param list<string> $attendu
	 */
	#[DataProvider( 'durees' )]
	public function test_attend_par_pas_de_10_s_avec_signe_de_vie( int $secondes, array $attendu ): void {
		$resultat = AsyncProbe::run( [ 'seconds' => $secondes ], $this->attente(), $this->signe_de_vie(), $this->horloge() );

		self::assertSame( $attendu, $this->journal );
		self::assertSame( $secondes, $resultat['slept'] );
	}

	/**
	 * `slept` compte les secondes réellement attendues : celles que l'attente n'a pas pu
	 * tenir (interrompue par un signal, sleep() rend alors ce qui restait) n'y sont pas, et
	 * la sonde n'allonge pas les pas suivants pour les rattraper. Les dates de début et de
	 * fin sont celles de l'horloge : elles disent la durée écoulée, temps de travail compris.
	 *
	 * @return iterable<string, array{list<int>, int, array<string, int|string>}>
	 */
	public static function attentes(): iterable {
		yield 'attente tenue' => [
			[ 0, 0, 0 ],
			0,
			[
				'requested'   => 30,
				'slept'       => 30,
				'started_at'  => '2026-09-10T00:26:40+00:00',
				'finished_at' => '2026-09-10T00:27:10+00:00',
			],
		];
		yield 'deuxième pas interrompu 4 s avant sa fin' => [
			[ 0, 4, 0 ],
			0,
			[
				'requested'   => 30,
				'slept'       => 26,
				'started_at'  => '2026-09-10T00:26:40+00:00',
				'finished_at' => '2026-09-10T00:27:06+00:00',
			],
		];
		// Sous Windows, sleep() interrompue rend 192, pas ce qui restait : rien n'est compté.
		yield 'valeur rendue plus grande que le pas' => [
			[ 0, 192, 0 ],
			0,
			[
				'requested'   => 30,
				'slept'       => 20,
				'started_at'  => '2026-09-10T00:26:40+00:00',
				'finished_at' => '2026-09-10T00:27:00+00:00',
			],
		];
		yield 'serveur lent : 1 s de travail par pas' => [
			[ 0, 0, 0 ],
			1,
			[
				'requested'   => 30,
				'slept'       => 30,
				'started_at'  => '2026-09-10T00:26:40+00:00',
				'finished_at' => '2026-09-10T00:27:13+00:00',
			],
		];
	}

	/**
	 * @param list<int>                  $restes   Secondes non attendues, rendues à chaque pas.
	 * @param int                        $travail  Secondes écoulées à chaque pas hors attente.
	 * @param array<string, int|string>  $attendu
	 */
	#[DataProvider( 'attentes' )]
	public function test_rend_la_duree_reellement_attendue( array $restes, int $travail, array $attendu ): void {
		$attente = function ( int $secondes ) use ( &$restes, $travail ): int {
			$reste      = (int) array_shift( $restes );
			$this->now += max( 0, $secondes - $reste ) + $travail;
			return $reste;
		};

		$resultat = AsyncProbe::run( [ 'seconds' => 30 ], $attente, $this->signe_de_vie(), $this->horloge() );

		self::assertSame( $attendu, $resultat );
		self::assertSame( [ 'signe de vie', 'signe de vie', 'signe de vie' ], $this->journal, 'Un signe de vie par pas, même interrompu.' );
	}

	/**
	 * Hors de la route (qui n'admet que 10 à 600 s par pas de 10) : jamais plus de 10 minutes,
	 * et rien d'attendu sans durée entière positive. `requested` dit la durée entière
	 * demandée, 0 sans elle.
	 *
	 * @return iterable<string, array{array<mixed>, int, int}>
	 */
	public static function charges_hors_bornes(): iterable {
		yield 'plus de 10 minutes' => [ [ 'seconds' => 6000 ], 6000, 600 ];
		yield 'durée négative' => [ [ 'seconds' => -10 ], -10, 0 ];
		yield 'durée en texte' => [ [ 'seconds' => '60' ], 0, 0 ];
		yield 'sans durée' => [ [], 0, 0 ];
	}

	/**
	 * @param array<mixed> $charge
	 */
	#[DataProvider( 'charges_hors_bornes' )]
	public function test_jamais_plus_de_10_minutes_meme_hors_de_la_route( array $charge, int $demandee, int $attendue ): void {
		$resultat = AsyncProbe::run( $charge, $this->attente(), $this->signe_de_vie(), $this->horloge() );

		self::assertSame( $demandee, $resultat['requested'] );
		self::assertSame( $attendue, $resultat['slept'] );
		self::assertCount( intdiv( $attendue, AsyncProbe::STEP_SECONDS ) * 2, $this->journal );
	}

	/**
	 * Critère d'acceptation : la sonde n'écrit que son propre enregistrement. Seule, elle ne
	 * lit ni n'écrit aucune option et ne touche à aucun fichier du site. Exécutée par le
	 * coureur (exécuteur par défaut), ses signes de vie passant par l'écouteur du signe de
	 * vie : seuls l'enregistrement de l'exécution et le créneau sont lus et écrits, et il ne
	 * reste que l'enregistrement à la fin.
	 */
	public function test_aucune_option_ni_fichier_touche(): void {
		$fichiers_avant = self::fichiers_du_site();

		AsyncProbe::run( [ 'seconds' => 30 ], $this->attente(), $this->signe_de_vie(), $this->horloge() );

		self::assertSame( [], $this->table->queries, 'Seule, la sonde ne fait aucune requête.' );
		self::assertSame( [], $this->options, 'Aucune option écrite par l\'API des options.' );

		// Exécution complète, comme après une acceptation : enregistrement créé, créneau pris.
		self::assertTrue( ExecutionSlot::acquire( self::ID, AsyncCommands::PROBE, $this->now )['acquired'] );
		self::assertTrue(
			RunStore::create(
				[
					'command_id'  => self::ID,
					'command'     => AsyncCommands::PROBE,
					'body_sha256' => str_repeat( 'ab', 32 ),
					'state'       => RunState::ACCEPTED,
					'accepted_at' => $this->now,
					'started_at'  => null,
					'alive_at'    => null,
					'finished_at' => null,
				]
			)
		);
		$this->table->queries = [];
		// WordPress appelle les écouteurs de l'action : ici, celui du signe de vie.
		Actions\expectDone( 'g2rd_connector_command_progress' )->whenHappen(
			static function (): void {
				RunHeartbeat::on_progress();
			}
		);
		$runner = new AfterResponseRunner(
			terminator: static fn (): bool => true,
			clock: $this->horloge(),
			output: static function (): void {},
			sleep: $this->attente()
		);
		$runner->queue( self::ID, AsyncCommands::PROBE, [ 'seconds' => 30 ] );
		$runner->maybe_serve( false, new WP_REST_Response( [ 'command_id' => self::ID ], 202 ), new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' ), null );

		self::assertSame( RunState::DONE, RunStore::read( self::ID )['state'] ?? null );
		self::assertCount(
			3,
			preg_grep( '/^UPDATE wp_options SET .* WHERE option_name = \'' . ExecutionSlot::OPTION . '\'/', $this->table->queries ),
			'Un signe de vie écrit par pas (créneau rafraîchi) : ses requêtes sont contrôlées ci-dessous.'
		);
		$noms = array_map( static fn ( string $nom ): string => preg_quote( $nom, '/' ), [ RunStore::option_name( self::ID ), ExecutionSlot::OPTION ] );
		self::assertNotEmpty( $this->table->queries );
		foreach ( $this->table->queries as $requete ) {
			self::assertMatchesRegularExpression( '/\'(' . implode( '|', $noms ) . ')\'/', $requete, 'Requête hors de l\'enregistrement et du créneau.' );
		}
		self::assertSame( [ RunStore::option_name( self::ID ) ], array_keys( $this->table->rows ), 'Il ne reste que l\'enregistrement : le créneau est rendu.' );
		self::assertSame( [], $this->options );
		self::assertSame( $fichiers_avant, self::fichiers_du_site(), 'Aucun fichier du site créé, modifié ni supprimé.' );
	}

	/**
	 * Attente du test : fait avancer l'horloge de la durée demandée et la note au journal.
	 *
	 * @return \Closure(int): int
	 */
	private function attente(): \Closure {
		return function ( int $secondes ): int {
			$this->journal[] = 'attente ' . $secondes;
			$this->now      += $secondes;
			return 0;
		};
	}

	/** @return \Closure(): void */
	private function signe_de_vie(): \Closure {
		return function (): void {
			$this->journal[] = 'signe de vie';
		};
	}

	/** @return \Closure(): int */
	private function horloge(): \Closure {
		return fn (): int => $this->now;
	}

	/**
	 * Fichiers et dossiers du site de test (racine de WordPress et wp-content), avec leur
	 * taille et leur date de modification.
	 *
	 * @return array<string, string>
	 */
	private static function fichiers_du_site(): array {
		$etat = [];
		foreach ( [ ABSPATH, WP_CONTENT_DIR ] as $racine ) {
			if ( ! is_dir( $racine ) ) {
				continue;
			}
			$parcours = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $racine, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $parcours as $chemin => $fichier ) {
				$etat[ (string) $chemin ] = $fichier->isDir() ? 'dossier' : $fichier->getSize() . ' octets, ' . $fichier->getMTime();
			}
		}
		ksort( $etat );

		return $etat;
	}
}
