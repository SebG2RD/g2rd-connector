<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\StoreUnavailable;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Créneau d'exécution, un seul par site (§ 3.4 de la spécification) : l'option
 * `g2rd_cmdrun_slot` dit quelle exécution en arrière-plan tient le site, depuis quand, et
 * son dernier signe de vie. Pris par écriture atomique (INSERT IGNORE, ou écriture
 * conditionnelle sur la valeur relue quand son détenteur est mort), il n'a jamais deux
 * détenteurs. Mort déclarée au bout de 600 s sans signe de vie, comme une transaction de
 * mise à jour protégée.
 */
final class ExecutionSlotTest extends TestCase {

	private const NOW   = 1789000000;
	private const ID    = '0123456789abcdef0123456789abcdef';
	private const AUTRE = 'fedcba9876543210fedcba9876543210';
	private const SLOT  = 'g2rd_cmdrun_slot';

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

	public function test_creneau_libre_pris(): void {
		$prise = ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );

		self::assertSame( [ 'acquired' => true ], $prise );
		self::assertSame( self::SLOT, ExecutionSlot::OPTION );
		self::assertSame(
			[
				'command_id' => self::ID,
				'command'    => 'update_plugin',
				'since'      => self::NOW,
				'alive_at'   => self::NOW,
			],
			ExecutionSlot::holder()
		);
		self::assertSame( 'no', $this->table->rows[ self::SLOT ]['autoload'], 'Jamais chargé à chaque page : seules les deux routes le lisent.' );
		self::assertStringStartsWith(
			'INSERT IGNORE INTO wp_options (option_name, option_value, autoload)',
			$this->table->queries[1],
			'Créneau libre : création atomique, la clé unique de la table ne laisse passer qu\'un processus.'
		);
	}

	/**
	 * Le créneau est le verrou du site : sur un site à cache d'objets persistant
	 * (LiteSpeed Object Cache), get_option() pourrait le servir libre alors qu'il vient
	 * d'être pris. Il est toujours relu en base.
	 */
	public function test_le_creneau_est_lu_en_base_sans_cache(): void {
		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				if ( self::SLOT === $key ) {
					throw new \LogicException( 'get_option() pourrait servir un créneau périmé par le cache d\'objets.' );
				}
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
			}
		);

		ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );

		self::assertSame( self::ID, ExecutionSlot::holder()['command_id'] ?? null );
		self::assertSame(
			"SELECT option_value FROM wp_options WHERE option_name = '" . self::SLOT . "' LIMIT 1",
			$this->table->queries[0]
		);
	}

	public function test_creneau_tenu_par_une_execution_vivante_refuse_avec_son_numero_et_sa_date(): void {
		$this->tenu_par( self::AUTRE, self::NOW - 300, self::NOW - 5 );
		$avant = $this->table->rows[ self::SLOT ]['value'];

		$prise = ExecutionSlot::acquire( self::ID, 'optimize_database', self::NOW );

		self::assertSame(
			[
				'acquired'   => false,
				'busy_with'  => self::AUTRE,
				'busy_since' => self::NOW - 300,
				'reason'     => ExecutionSlot::REASON_RUN_IN_PROGRESS,
			],
			$prise
		);
		self::assertSame( $avant, $this->table->rows[ self::SLOT ]['value'], 'Le créneau de l\'exécution vivante n\'est pas touché.' );
	}

	public function test_creneau_d_une_execution_morte_depuis_601_s_repris(): void {
		$this->tenu_par( self::AUTRE, self::NOW - 3600, self::NOW - 601 );
		$morte = $this->table->rows[ self::SLOT ]['value'];

		$prise = ExecutionSlot::acquire( self::ID, 'update_core', self::NOW );

		self::assertSame( [ 'acquired' => true ], $prise );
		self::assertSame(
			[
				'command_id' => self::ID,
				'command'    => 'update_core',
				'since'      => self::NOW,
				'alive_at'   => self::NOW,
			],
			ExecutionSlot::holder()
		);
		self::assertSame(
			"UPDATE wp_options SET option_value = '" . addslashes( (string) $this->table->rows[ self::SLOT ]['value'] ) . "' WHERE option_name = '" . self::SLOT . "' AND option_value = '" . addslashes( $morte ) . "'",
			$this->table->queries[1],
			'Reprise par écriture conditionnelle sur la valeur relue : jamais une suppression puis une création.'
		);
	}

	/**
	 * Vivante tant que son dernier signe de vie date de 600 s au plus (alive_at ≥
	 * maintenant − 600), la limite de UpdateTransaction::STALE_AFTER_SECONDS.
	 *
	 * @return array<string, array{int}>
	 */
	public static function signes_de_vie_recents(): array {
		return [
			'599 s' => [ 599 ],
			'600 s' => [ 600 ],
		];
	}

	#[DataProvider( 'signes_de_vie_recents' )]
	public function test_pas_repris_a_599_s( int $silence ): void {
		self::assertSame( UpdateTransaction::STALE_AFTER_SECONDS, ExecutionSlot::STALE_AFTER_SECONDS );
		$this->tenu_par( self::AUTRE, self::NOW - 3600, self::NOW - $silence );

		$prise = ExecutionSlot::acquire( self::ID, 'update_core', self::NOW );

		self::assertFalse( $prise['acquired'] );
		self::assertSame( self::AUTRE, $prise['busy_with'] ?? null );
		self::assertSame( self::AUTRE, ExecutionSlot::holder()['command_id'] ?? null );
	}

	/**
	 * Une valeur illisible (abîmée, ou écrite par un autre moyen) ne dit ni qui tient le
	 * créneau ni s'il est vivant : elle ne doit pas bloquer le site pour toujours. Elle
	 * est reprise, par écriture conditionnelle comme un détenteur mort.
	 *
	 * @return array<string, array{string}>
	 */
	public static function valeurs_illisibles(): array {
		return [
			'pas du JSON'           => [ 'a:1:{s:3:"foo";s:3:"bar";}' ],
			'liste JSON'            => [ '[1,2]' ],
			'sans signe de vie'     => [ '{"command_id":"' . self::AUTRE . '","command":"update_core","since":1}' ],
			'signe de vie en texte' => [ '{"command_id":"' . self::AUTRE . '","command":"update_core","since":1,"alive_at":"1789000000"}' ],
		];
	}

	#[DataProvider( 'valeurs_illisibles' )]
	public function test_une_valeur_illisible_est_reprise( string $illisible ): void {
		$this->table->rows[ self::SLOT ] = [
			'value'    => $illisible,
			'autoload' => 'no',
		];
		self::assertNull( ExecutionSlot::holder(), 'Aucune exécution identifiable ne le tient.' );

		self::assertSame( [ 'acquired' => true ], ExecutionSlot::acquire( self::ID, 'update_core', self::NOW ) );
		self::assertSame( self::ID, ExecutionSlot::holder()['command_id'] ?? null );
	}

	/**
	 * Deux acceptations au même instant : le second processus écrit juste avant le
	 * premier (faux $wpdb, `before_write`). Sur un créneau libre comme sur un créneau
	 * mort, une seule écriture passe ; le perdant relit et répond « occupé » avec le
	 * numéro du gagnant.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function creneaux_a_prendre(): array {
		return [
			'créneau libre' => [ false ],
			'créneau mort'  => [ true ],
		];
	}

	#[DataProvider( 'creneaux_a_prendre' )]
	public function test_deux_prises_simultanees_un_seul_gagnant( bool $mort ): void {
		if ( $mort ) {
			$this->tenu_par( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::NOW - 3600, self::NOW - 900 );
		}
		$autre_prise               = null;
		$this->table->before_write = static function () use ( &$autre_prise ): void {
			$autre_prise = ExecutionSlot::acquire( self::AUTRE, 'update_theme', self::NOW );
		};

		$prise = ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );

		self::assertSame( [ 'acquired' => true ], $autre_prise, 'Le processus qui a écrit le premier tient le créneau.' );
		self::assertSame(
			[
				'acquired'   => false,
				'busy_with'  => self::AUTRE,
				'busy_since' => self::NOW,
				'reason'     => ExecutionSlot::REASON_RUN_IN_PROGRESS,
			],
			$prise,
			'Le second perd son écriture, relit et répond « occupé ».'
		);
		self::assertSame( self::AUTRE, ExecutionSlot::holder()['command_id'] ?? null );
	}

	/**
	 * Une mise à jour protégée vivante (UpdateTransaction), sans exécution en arrière-plan
	 * qui tienne le créneau : une reprise menée par le cron du site, que la plateforme ne
	 * voit pas. Une commande qui remplace les fichiers d'une extension attend.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function commandes_sur_les_fichiers_d_extension(): iterable {
		foreach ( AsyncCommands::TOUCHES_PLUGIN_FILES as $command ) {
			yield $command => [ $command ];
		}
	}

	#[DataProvider( 'commandes_sur_les_fichiers_d_extension' )]
	public function test_transaction_vivante_et_mise_a_jour_d_extension_refusee( string $command ): void {
		$this->transaction_vivante();

		$prise = ExecutionSlot::acquire( self::ID, $command, self::NOW );

		self::assertSame(
			[
				'acquired'   => false,
				'busy_since' => self::NOW - 240,
				'reason'     => ExecutionSlot::REASON_UPDATE_TRANSACTION,
			],
			$prise,
			'Occupé depuis l\'ouverture de la transaction ; aucune exécution en arrière-plan à nommer.'
		);
		self::assertSame( [], $this->table->rows, 'Le créneau n\'est pas pris.' );
	}

	/**
	 * Le créneau tenu par une exécution vivante passe avant la transaction : une mise à
	 * jour protégée lancée en arrière-plan tient les deux, et la réponse nomme l'exécution.
	 */
	public function test_creneau_vivant_et_transaction_vivante_nomment_l_execution(): void {
		$this->transaction_vivante();
		$this->tenu_par( self::AUTRE, self::NOW - 300, self::NOW - 5 );

		$prise = ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );

		self::assertSame( self::AUTRE, $prise['busy_with'] ?? null );
		self::assertSame( ExecutionSlot::REASON_RUN_IN_PROGRESS, $prise['reason'] ?? null );
	}

	/**
	 * Thèmes, cœur, traductions et optimisation ne touchent pas aux fichiers d'une
	 * extension : une transaction vivante ne les retient pas.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function commandes_hors_fichiers_d_extension(): iterable {
		foreach ( array_diff( AsyncCommands::ADMITTED, AsyncCommands::TOUCHES_PLUGIN_FILES ) as $command ) {
			yield $command => [ $command ];
		}
		yield AsyncCommands::PROBE => [ AsyncCommands::PROBE ];
	}

	#[DataProvider( 'commandes_hors_fichiers_d_extension' )]
	public function test_transaction_vivante_et_optimisation_acceptee( string $command ): void {
		$this->transaction_vivante();

		self::assertSame( [ 'acquired' => true ], ExecutionSlot::acquire( self::ID, $command, self::NOW ) );
		self::assertSame( $command, ExecutionSlot::holder()['command'] ?? null );
	}

	public function test_transaction_morte_ne_retient_pas_une_mise_a_jour_d_extension(): void {
		$this->transaction_vivante();
		$this->options[ UpdateTransaction::OPTION_KEY ]['updated_at'] = self::NOW - 601;

		self::assertSame( [ 'acquired' => true ], ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW ) );
	}

	public function test_rendu_seulement_par_son_detenteur(): void {
		ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );
		$tenu = $this->table->rows[ self::SLOT ]['value'];

		self::assertFalse( ExecutionSlot::release( self::AUTRE ), 'Une autre exécution ne rend pas le créneau.' );
		self::assertSame( $tenu, $this->table->rows[ self::SLOT ]['value'] ?? null );

		self::assertTrue( ExecutionSlot::release( self::ID ) );
		self::assertArrayNotHasKey( self::SLOT, $this->table->rows );
		self::assertSame(
			"DELETE FROM wp_options WHERE option_name = '" . self::SLOT . "' AND option_value = '" . addslashes( $tenu ) . "'",
			end( $this->table->queries ),
			'Suppression conditionnelle sur la valeur relue.'
		);

		self::assertFalse( ExecutionSlot::release( self::ID ), 'Déjà rendu : rien à faire.' );
		self::assertNull( ExecutionSlot::holder() );
	}

	/**
	 * Une exécution déclarée morte, dont le créneau a été repris, ne le rend pas en
	 * finissant : il est à une autre. Le détenteur est relu juste avant la suppression,
	 * et la suppression est conditionnelle sur la valeur relue.
	 */
	public function test_rendu_perdu_si_le_creneau_est_repris_entre_la_lecture_et_la_suppression(): void {
		$this->tenu_par( self::ID, self::NOW - 3600, self::NOW - 700 );
		$this->table->before_write = static function (): void {
			ExecutionSlot::acquire( self::AUTRE, 'update_theme', self::NOW );
		};

		self::assertFalse( ExecutionSlot::release( self::ID ) );
		self::assertSame( self::AUTRE, ExecutionSlot::holder()['command_id'] ?? null, 'Le créneau repris reste à son nouveau détenteur.' );
	}

	public function test_signe_de_vie_seulement_par_son_detenteur(): void {
		$this->tenu_par( self::ID, self::NOW - 300, self::NOW - 100 );
		$tenu = $this->table->rows[ self::SLOT ]['value'];

		ExecutionSlot::touch( self::AUTRE, self::NOW );
		self::assertSame( $tenu, $this->table->rows[ self::SLOT ]['value'], 'Le signe de vie d\'une autre exécution ne change rien.' );

		ExecutionSlot::touch( self::ID, self::NOW );
		self::assertStringStartsWith(
			'UPDATE wp_options SET option_value = ',
			end( $this->table->queries ),
			'Écriture conditionnelle sur la valeur relue.'
		);
		self::assertStringEndsWith( "AND option_value = '" . addslashes( $tenu ) . "'", end( $this->table->queries ) );
		self::assertSame(
			[
				'command_id' => self::ID,
				'command'    => 'update_core',
				'since'      => self::NOW - 300,
				'alive_at'   => self::NOW,
			],
			ExecutionSlot::holder(),
			'Seul le signe de vie change ; le début reste celui de la prise.'
		);

		ExecutionSlot::release( self::ID );
		ExecutionSlot::touch( self::ID, self::NOW + 5 );
		self::assertArrayNotHasKey( self::SLOT, $this->table->rows, 'Un signe de vie ne reprend jamais un créneau rendu.' );
	}

	/**
	 * La base refuse l'écriture, sur un créneau libre comme sur un créneau mort : il
	 * n'est pas pris, et ce n'est pas « occupé » (la route répondra 500, que la
	 * plateforme lit comme ambigu). Rien n'est écrit.
	 */
	#[DataProvider( 'creneaux_a_prendre' )]
	public function test_base_qui_refuse_l_ecriture_store_unavailable_et_non_occupe( bool $mort ): void {
		if ( $mort ) {
			$this->tenu_par( self::AUTRE, self::NOW - 3600, self::NOW - 900 );
		}
		$avant              = $this->table->rows;
		$this->table->fails = true;

		$prise = ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW );

		self::assertSame(
			[
				'acquired' => false,
				'reason'   => ExecutionSlot::REASON_STORE_UNAVAILABLE,
			],
			$prise
		);
		self::assertSame( $avant, $this->table->rows );
	}

	/**
	 * La base refuse la lecture : impossible de savoir si le créneau est libre. Il n'est
	 * jamais pris à l'aveugle (ni libre en base, ni tenu), et holder() ne le dit jamais
	 * libre (StoreUnavailable).
	 */
	public function test_base_qui_refuse_la_lecture_store_unavailable_rien_n_est_ecrit(): void {
		$indisponible            = [
			'acquired' => false,
			'reason'   => ExecutionSlot::REASON_STORE_UNAVAILABLE,
		];
		$this->table->read_fails = true;

		self::assertSame( $indisponible, ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW ) );
		self::assertSame( [], $this->table->rows, 'Libre en base, mais illisible : pas de création à l\'aveugle.' );

		$this->tenu_par( self::AUTRE, self::NOW - 300, self::NOW - 5 );
		$avant = $this->table->rows;
		self::assertSame( $indisponible, ExecutionSlot::acquire( self::ID, 'update_plugin', self::NOW ) );
		self::assertSame( $avant, $this->table->rows );
		self::assertFalse( ExecutionSlot::release( self::AUTRE ), 'Rendu impossible sans relire le détenteur.' );
		self::assertSame( $avant, $this->table->rows );

		try {
			ExecutionSlot::holder();
			self::fail( 'Une lecture refusée ne doit pas passer pour un créneau libre.' );
		} catch ( StoreUnavailable $e ) {
			self::assertSame( 'MySQL server has gone away', $e->db_error() );
		}
	}

	/**
	 * Signe de vie et rendu sont appelés au milieu d'une mise à jour, ou à sa fin : une
	 * base qui refuse ne les fait jamais lever.
	 */
	public function test_signe_de_vie_et_rendu_ne_levent_jamais_si_la_base_refuse(): void {
		$this->tenu_par( self::ID, self::NOW - 300, self::NOW - 100 );
		$avant = $this->table->rows;

		$this->table->fails = true;
		ExecutionSlot::touch( self::ID, self::NOW );
		self::assertFalse( ExecutionSlot::release( self::ID ) );
		self::assertSame( $avant, $this->table->rows );

		$this->table->fails      = false;
		$this->table->read_fails = true;
		ExecutionSlot::touch( self::ID, self::NOW );
		self::assertFalse( ExecutionSlot::release( self::ID ) );
		self::assertSame( $avant, $this->table->rows );
	}

	/**
	 * Pose le créneau tel qu'une exécution l'aurait écrit.
	 */
	private function tenu_par( string $command_id, int $since, int $alive_at ): void {
		$this->table->rows[ self::SLOT ] = [
			'value'    => (string) json_encode(
				[
					'command_id' => $command_id,
					'command'    => 'update_core',
					'since'      => $since,
					'alive_at'   => $alive_at,
				]
			),
			'autoload' => 'no',
		];
	}

	/**
	 * Une mise à jour protégée ouverte il y a 4 minutes, rafraîchie il y a 30 s (option
	 * simulée de TestCase, lue par UpdateTransaction::in_progress()).
	 */
	private function transaction_vivante(): void {
		$this->options[ UpdateTransaction::OPTION_KEY ] = [
			'id'          => 'a1b2c3d4e5f60708',
			'plugin_file' => 'akismet/akismet.php',
			'step'        => UpdateTransaction::STEP_UPGRADING,
			'started_at'  => self::NOW - 240,
			'updated_at'  => self::NOW - 30,
		];
	}
}
