<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Commands;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Tests\TestCase;

/**
 * Signe de vie de l'optimisation de la base (§ 3.5 de la spécification, « chaque table
 * optimisée ») : l'action `g2rd_connector_command_progress` est déclenchée après chaque
 * OPTIMIZE TABLE, réussi ou non, et nulle part ailleurs. Sans écouteur, elle ne change
 * rien : la commande rend le même tableau qu'avant.
 *
 * Faux $wpdb réduit aux requêtes de la commande : l'état des tables (SHOW TABLE STATUS,
 * avant puis après), OPTIMIZE TABLE, et la recherche des transients expirés. Toute autre
 * requête lève une exception.
 */
final class OptimizeDatabaseProgressTest extends TestCase {

	/**
	 * Requêtes OPTIMIZE TABLE et actions de progrès, dans l'ordre où elles ont eu lieu.
	 *
	 * @var list<string>
	 */
	private array $journal = [];

	protected function setUp(): void {
		parent::setUp();
		$this->journal = [];
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_une_action_de_progres_apres_chaque_table_optimisee(): void {
		$this->base(
			[
				[ 'wp_comments', 524288, 0 ],
				[ 'wp_options', 1048576, 0 ],
				[ 'wp_postmeta', 2097152, 0 ],
				[ 'wp_posts', 0, 0 ],
			]
		);
		Actions\expectDone( 'g2rd_connector_command_progress' )->whenHappen(
			function ( ...$args ): void {
				$this->journal[] = 'progrès ' . implode( ', ', $args );
			}
		);

		CommandExecutor::run( 'optimize_database' );

		self::assertSame(
			[
				'OPTIMIZE wp_comments',
				'progrès optimize_table',
				'OPTIMIZE wp_options',
				'progrès optimize_table',
				'OPTIMIZE wp_postmeta',
				'progrès optimize_table',
			],
			$this->journal,
			'Trois tables à optimiser, trois actions, chacune après son OPTIMIZE ; aucune pour la table compacte.'
		);
	}

	/**
	 * Même tableau rendu qu'avant l'action : une table dont l'optimisation échoue n'est
	 * pas comptée, mais le processus a bien avancé (action déclenchée quand même).
	 */
	public function test_le_resultat_de_la_commande_est_inchange(): void {
		$this->base(
			[
				[ 'wp_comments', 524288, 0 ],
				[ 'wp_options', 1048576, 0 ],
				[ 'wp_postmeta', 2097152, 2097152 ],
				[ 'wp_posts', 0, 0 ],
			],
			[ 'wp_postmeta' ],
			[ '_transient_timeout_feed_1', '_transient_timeout_feed_2' ]
		);

		$outcome = CommandExecutor::run( 'optimize_database' );

		self::assertSame(
			[
				'status' => 'done',
				'result' => [
					'tables_optimized'   => 2,
					'tables_total'       => 4,
					'overhead_before_mb' => 3.5,
					'overhead_after_mb'  => 2.0,
					'transients_deleted' => 2,
				],
			],
			$outcome
		);
		self::assertSame( 3, did_action( 'g2rd_connector_command_progress' ) );
	}

	public function test_sans_table_a_optimiser_aucune_action(): void {
		$this->base(
			[
				[ 'wp_options', 0, 0 ],
				[ 'wp_posts', 0, 0 ],
			]
		);

		$outcome = CommandExecutor::run( 'optimize_database' );

		self::assertSame( 0, $outcome['result']['tables_optimized'] ?? null );
		self::assertSame( 0, did_action( 'g2rd_connector_command_progress' ) );
		self::assertSame( [], $this->journal );
	}

	/**
	 * Pose le faux $wpdb.
	 *
	 * @param list<array{0: string, 1: int, 2: int}> $tables     [ nom, Data_free avant, Data_free après ].
	 * @param list<string>                           $en_echec   Tables dont OPTIMIZE TABLE échoue.
	 * @param list<string>                           $transients Options `_transient_timeout_*` expirées.
	 */
	private function base( array $tables, array $en_echec = [], array $transients = [] ): void {
		$note = function ( string $ligne ): void {
			$this->journal[] = $ligne;
		};

		$GLOBALS['wpdb'] = new class( $tables, $en_echec, $transients, $note ) {
			public string $prefix  = 'wp_';
			public string $options = 'wp_options';

			/** @var list<list<array{Name: string, Data_free: string}>> États des tables : avant, puis après. */
			private array $statuts = [];

			/**
			 * @param list<array{0: string, 1: int, 2: int}> $tables
			 * @param list<string>                           $en_echec
			 * @param list<string>                           $transients
			 */
			public function __construct( array $tables, private array $en_echec, private array $transients, private \Closure $note ) {
				foreach ( [ 1, 2 ] as $moment ) {
					$this->statuts[] = array_map(
						static fn ( array $t ): array => [
							'Name'      => $t[0],
							'Data_free' => (string) $t[ $moment ],
						],
						$tables
					);
				}
			}

			/** @return list<array{Name: string, Data_free: string}> */
			public function get_results( string $sql, string $output ): array {
				if ( "SHOW TABLE STATUS LIKE 'wp_%'" !== $sql || ARRAY_A !== $output || [] === $this->statuts ) {
					throw new \LogicException( 'Lecture non prévue : ' . $sql );
				}
				return array_shift( $this->statuts );
			}

			public function query( string $sql ): int|false {
				if ( 1 !== preg_match( '/^OPTIMIZE TABLE `(wp_[a-z_]+)`$/D', $sql, $m ) ) {
					throw new \LogicException( 'Écriture non prévue : ' . $sql );
				}
				( $this->note )( 'OPTIMIZE ' . $m[1] );
				return in_array( $m[1], $this->en_echec, true ) ? false : 1;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return list<string> */
			public function get_col( string $sql ): array {
				if ( ! str_contains( $sql, 'WHERE option_name LIKE %s AND option_value < %d' ) ) {
					throw new \LogicException( 'Recherche non prévue : ' . $sql );
				}
				return $this->transients;
			}
		};
	}
}
