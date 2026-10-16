<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\CommandRuns\StoreUnavailable;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Enregistrement d'une exécution en arrière-plan (§ 3.2 de la spécification) : une
 * option `g2rd_cmdrun_<numéro>` non autochargée, en JSON, créée et modifiée par des
 * écritures atomiques en SQL direct, lue sans get_option() ni cache d'objets, et
 * bornée à 64 Ko mesurés sur l'encodage que la route de lecture servira.
 */
final class RunStoreTest extends TestCase {

	private const NOW = 1789000000;
	private const ID  = '0123456789abcdef0123456789abcdef';

	private FakeOptionsTable $table;

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		// wp_json_encode() sans option, comme WordPress : json_encode() sans option, qui
		// échappe les caractères non ASCII et « / » — l'encodage de la réponse REST.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_creation_atomique_un_seul_gagnant_pour_le_meme_numero(): void {
		Functions\when( 'add_option' )->alias(
			static function (): bool {
				throw new \LogicException( 'add_option() lit puis écrit : il ne rend pas la création atomique.' );
			}
		);
		Functions\when( 'update_option' )->alias(
			static function (): bool {
				throw new \LogicException( 'update_option() remplacerait l\'enregistrement d\'un autre processus.' );
			}
		);

		self::assertTrue( RunStore::create( self::record() ), 'Le premier processus crée l\'enregistrement.' );
		$autre = self::record(
			[
				'command'     => 'update_theme',
				'accepted_at' => self::NOW + 1,
			]
		);
		self::assertFalse(
			RunStore::create( $autre ),
			'Le second, même numéro, perd : la clé unique de la table ne laisse passer qu\'une création.'
		);

		self::assertSame( 'update_plugin', RunStore::read( self::ID )['command'] ?? null, 'L\'enregistrement du gagnant n\'est pas remplacé.' );
		self::assertCount( 1, $this->table->rows );
		self::assertStringStartsWith( 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload)', $this->table->queries[0] );
		self::assertStringNotContainsString( 'ON DUPLICATE KEY', $this->table->queries[0], 'Une ligne existante n\'est jamais mise à jour.' );
	}

	public function test_l_enregistrement_n_est_pas_autocharge(): void {
		RunStore::create( self::record() );

		self::assertSame( 'g2rd_cmdrun_' . self::ID, RunStore::option_name( self::ID ) );
		self::assertSame( [ 'g2rd_cmdrun_' . self::ID ], array_keys( $this->table->rows ) );
		self::assertSame( 'no', $this->table->rows[ 'g2rd_cmdrun_' . self::ID ]['autoload'], 'Jamais chargée à chaque page : elle ne sert qu\'aux deux routes.' );
	}

	/**
	 * Sur un site à cache d'objets persistant (LiteSpeed Object Cache), get_option() peut
	 * servir une valeur périmée : l'état d'une exécution est toujours relu en base.
	 *
	 * TestCase simule déjà get_option() et wp_cache_get() par Functions\when() : un
	 * Functions\expect()->never() posé ensuite ne remplacerait pas cette simulation et
	 * passerait toujours. Les deux fonctions lèvent donc une exception si on les appelle.
	 */
	public function test_lecture_directe_en_base_sans_get_option(): void {
		RunStore::create( self::record() );
		foreach ( [ 'get_option', 'wp_cache_get' ] as $interdite ) {
			Functions\when( $interdite )->alias(
				static function () use ( $interdite ): void {
					throw new \LogicException( $interdite . '() pourrait servir un état périmé par le cache d\'objets.' );
				}
			);
		}

		self::assertSame( self::record(), RunStore::read( self::ID ) );
		self::assertSame( json_encode( self::record() ), RunStore::read_raw( self::ID ) );
		self::assertNull( RunStore::read( 'ffffffffffffffffffffffffffffffff' ), 'Numéro inconnu : rien.' );

		self::assertSame(
			"SELECT option_value FROM wp_options WHERE option_name = 'g2rd_cmdrun_" . self::ID . "' LIMIT 1",
			$this->table->queries[1]
		);
	}

	/**
	 * get_var() rend null aussi quand la base refuse la lecture (« MySQL server has gone
	 * away », trop de connexions). Pris pour un numéro inconnu, ce refus passager
	 * deviendrait une 404 `run_unknown`, que la plateforme lit comme une exécution perdue
	 * (état définitif) alors qu'elle tourne peut-être encore. Une lecture en échec lève
	 * donc StoreUnavailable, pour un numéro connu comme pour un inconnu : jamais null.
	 */
	public function test_lecture_en_echec_distincte_d_un_numero_inconnu(): void {
		$inconnu = 'ffffffffffffffffffffffffffffffff';
		RunStore::create( self::record() );
		$this->table->read_fails = true;

		$lectures = [
			'read_raw, numéro connu'   => static fn () => RunStore::read_raw( self::ID ),
			'read, numéro connu'       => static fn () => RunStore::read( self::ID ),
			'read_raw, numéro inconnu' => static fn () => RunStore::read_raw( $inconnu ),
			'read, numéro inconnu'     => static fn () => RunStore::read( $inconnu ),
		];
		foreach ( $lectures as $cas => $lecture ) {
			try {
				$lu = $lecture();
				self::fail( $cas . ' : la base a refusé la lecture, rien ne doit être rendu (rendu : ' . var_export( $lu, true ) . ').' );
			} catch ( StoreUnavailable $e ) {
				self::assertSame( 'MySQL server has gone away', $e->db_error(), $cas . ' : l\'erreur de la base est gardée pour le journal.' );
				self::assertSame( StoreUnavailable::MESSAGE, $e->getMessage() );
			}
		}

		$this->table->read_fails = false;
		self::assertNull( RunStore::read_raw( $inconnu ), 'Base revenue : un numéro inconnu rend null, sans exception.' );
		self::assertSame( self::record(), RunStore::read( self::ID ), 'Et l\'enregistrement est relu.' );
	}

	/**
	 * Message explicite (quoi, cause probable, action), sans détail interne : il peut
	 * être relayé tel quel par les routes.
	 */
	public function test_le_message_d_une_lecture_en_echec_est_explicite(): void {
		$erreur = new StoreUnavailable( "Table 'wp_options' is marked as crashed" );

		self::assertInstanceOf( \RuntimeException::class, $erreur );
		self::assertStringContainsString( 'n\'a pas pu être lu', $erreur->getMessage() );
		self::assertStringContainsString( 'Cause probable', $erreur->getMessage() );
		self::assertStringContainsString( 'Action', $erreur->getMessage() );
		self::assertStringNotContainsString( 'wp_options', $erreur->getMessage(), 'Le nom des tables reste au journal.' );
		self::assertSame( "Table 'wp_options' is marked as crashed", $erreur->db_error() );
	}

	/**
	 * Le ramassage clôt l'exécution entre la lecture et l'écriture du démarrage : le
	 * démarrage perd, l'exécution reste abandonnée — jamais démarrée ET abandonnée.
	 */
	public function test_ecriture_conditionnelle_perdue_si_la_valeur_a_change_entre_temps(): void {
		RunStore::create( self::record() );
		$relue = (string) RunStore::read_raw( self::ID );

		$abandonnee = self::record(
			[
				'state'       => RunState::ABANDONED,
				'finished_at' => self::NOW + 121,
			]
		);
		$demarree   = self::record(
			[
				'state'      => RunState::RUNNING,
				'started_at' => self::NOW + 1,
				'alive_at'   => self::NOW + 1,
			]
		);

		$this->table->before_write = static function () use ( $relue, $abandonnee ): void {
			RunStore::swap( self::ID, $relue, $abandonnee );
		};

		self::assertFalse(
			RunStore::swap( self::ID, $relue, $demarree ),
			'La valeur relue n\'est plus en base : l\'écriture est perdue.'
		);
		self::assertSame( RunState::ABANDONED, RunStore::read( self::ID )['state'] ?? null, 'L\'écriture de l\'autre processus est gardée.' );

		$updates = array_values( array_filter( $this->table->queries, static fn ( string $q ): bool => str_starts_with( $q, 'UPDATE ' ) ) );
		self::assertCount( 2, $updates );
		self::assertStringContainsString( "WHERE option_name = 'g2rd_cmdrun_" . self::ID . "' AND option_value = '", $updates[1] );
	}

	public function test_ecriture_conditionnelle_gagnee_sur_la_valeur_relue(): void {
		RunStore::create( self::record() );
		$relue = (string) RunStore::read_raw( self::ID );
		$lue   = RunStore::decode( $relue );
		self::assertSame( self::record(), $lue );

		$en_cours = array_merge(
			(array) $lue,
			[
				'state'      => RunState::RUNNING,
				'started_at' => self::NOW + 1,
				'alive_at'   => self::NOW + 1,
			]
		);
		self::assertTrue( RunStore::swap( self::ID, $relue, $en_cours ) );
		self::assertSame( $en_cours, RunStore::read( self::ID ) );

		self::assertFalse(
			RunStore::swap( self::ID, $relue, self::record( [ 'state' => RunState::ABANDONED ] ) ),
			'La même valeur relue ne sert qu\'une fois : elle n\'est plus en base.'
		);
		self::assertSame( RunState::RUNNING, RunStore::read( self::ID )['state'] ?? null );
	}

	/**
	 * Hygiène : l'option n'est jamais lue par get_option(), mais une copie laissée dans le
	 * cache d'options par un tiers serait périmée après chaque écriture.
	 */
	public function test_le_cache_d_options_est_vide_apres_chaque_ecriture(): void {
		$vides = [];
		Functions\when( 'wp_cache_delete' )->alias(
			static function ( string $key, string $group ) use ( &$vides ): bool {
				$vides[] = $group . ':' . $key;
				return true;
			}
		);

		RunStore::create( self::record() );
		RunStore::swap( self::ID, (string) RunStore::read_raw( self::ID ), self::record( [ 'state' => RunState::RUNNING ] ) );

		self::assertSame( [ 'options:g2rd_cmdrun_' . self::ID, 'options:g2rd_cmdrun_' . self::ID ], $vides );
	}

	public function test_erreur_tronquee_a_2_ko(): void {
		$erreur_longue = str_repeat( 'é', 3000 );
		RunStore::create(
			self::record(
				[
					'state'        => RunState::INTERRUPTED,
					'outcome'      => [
						'status' => 'failed',
						'error'  => $erreur_longue,
					],
					'interruption' => [
						'reason' => 'fatal_error',
						'error'  => str_repeat( 'x', 5000 ),
					],
				]
			)
		);

		$lue    = (array) RunStore::read( self::ID );
		$erreur = (string) $lue['outcome']['error'];
		self::assertLessThanOrEqual( RunStore::MAX_ERROR_BYTES, strlen( $erreur ) );
		self::assertSame( 1, preg_match( '//u', $erreur ), 'Coupée entre deux caractères, jamais au milieu d\'un « é ».' );
		self::assertStringStartsWith( 'ééé', $erreur );
		self::assertStringEndsWith( '…', $erreur, 'La coupure se voit.' );

		$interruption = (string) $lue['interruption']['error'];
		self::assertLessThanOrEqual( RunStore::MAX_ERROR_BYTES, strlen( $interruption ) );
		self::assertStringStartsWith( 'xxxx', $interruption );
		self::assertSame( 'fatal_error', $lue['interruption']['reason'] );

		$courte = 'La mise à jour a échoué : le dossier de l\'extension n\'est pas modifiable.';
		RunStore::swap(
			self::ID,
			(string) RunStore::read_raw( self::ID ),
			self::record(
				[
					'state'   => RunState::FAILED,
					'outcome' => [
						'status' => 'failed',
						'error'  => $courte,
					],
				]
			)
		);
		self::assertSame( $courte, RunStore::read( self::ID )['outcome']['error'] ?? null, 'Une erreur courte est gardée telle quelle.' );
	}

	/**
	 * Au-delà de 64 Ko : la sortie parasite part d'abord, puis les tableaux du résultat,
	 * le plus volumineux d'abord, jusqu'à repasser sous la borne. Les valeurs simples
	 * (extension, versions, issue) et le statut restent.
	 */
	public function test_enregistrement_borne_a_64_ko_et_resultat_reduit(): void {
		$resultat = [
			'plugin'  => 'akismet/akismet.php',
			'from'    => '5.3',
			'to'      => '5.3.1',
			'outcome' => 'updated',
			'moyen'   => array_fill( 0, 2000, 'bbbbbbbbbb' ),
			'gros'    => array_fill( 0, 4000, 'aaaaaaaaaa' ),
			'petit'   => [ 'c', 'd' ],
		];
		$record   = self::record(
			[
				'state'   => RunState::DONE,
				'outcome' => [
					'status'       => 'done',
					'result'       => $resultat,
					'error'        => null,
					'stray_output' => str_repeat( 'x', 500 ),
				],
			]
		);
		self::assertGreaterThan( RunStore::MAX_RECORD_BYTES, strlen( (string) json_encode( $record ) ) );

		self::assertTrue( RunStore::create( $record ) );

		$brute = (string) RunStore::read_raw( self::ID );
		self::assertLessThanOrEqual( RunStore::MAX_RECORD_BYTES, strlen( $brute ) );
		$issue = (array) ( RunStore::read( self::ID )['outcome'] ?? [] );
		self::assertSame( 'done', $issue['status'] );
		self::assertArrayNotHasKey( 'stray_output', $issue, 'La sortie parasite part d\'abord.' );
		self::assertTrue( $issue['result_truncated'] );
		self::assertSame(
			[
				'plugin'  => 'akismet/akismet.php',
				'from'    => '5.3',
				'to'      => '5.3.1',
				'outcome' => 'updated',
				'moyen'   => $resultat['moyen'],
				'petit'   => [ 'c', 'd' ],
			],
			$issue['result'],
			'Le plus gros tableau part, et lui seul : sans lui, l\'enregistrement tient dans la borne.'
		);
	}

	/**
	 * Quand retirer la sortie parasite suffit, le résultat reste entier : il n'est pas
	 * dit tronqué.
	 */
	public function test_sortie_parasite_retiree_d_abord_sans_toucher_au_resultat(): void {
		$sans_sortie = self::done_with( [ 'liste' => [ '' ] ] );
		$remplissage = str_repeat( 'a', RunStore::MAX_RECORD_BYTES - strlen( (string) json_encode( $sans_sortie ) ) - 100 );
		$record      = self::done_with( [ 'liste' => [ $remplissage ] ], str_repeat( 'x', 500 ) );
		self::assertGreaterThan( RunStore::MAX_RECORD_BYTES, strlen( (string) json_encode( $record ) ) );

		self::assertTrue( RunStore::create( $record ) );

		$issue = (array) ( RunStore::read( self::ID )['outcome'] ?? [] );
		self::assertArrayNotHasKey( 'stray_output', $issue );
		self::assertSame( [ 'liste' => [ $remplissage ] ], $issue['result'] );
		self::assertArrayNotHasKey( 'result_truncated', $issue );
	}

	/**
	 * Mesurée sur un encodage compact (caractères non ASCII et « / » laissés tels quels),
	 * la borne laisserait passer ce résultat : servi par la route de lecture, il ferait
	 * plus de 80 Ko, et la plateforme refuserait la réponse.
	 */
	public function test_la_borne_se_mesure_sur_l_encodage_servi(): void {
		$record = self::done_with(
			[
				'plugin'  => 'éditeur/éditeur.php',
				'chemins' => array_fill( 0, 1000, str_repeat( 'é/', 10 ) ),
			]
		);
		self::assertLessThan( RunStore::MAX_RECORD_BYTES, strlen( (string) json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		self::assertGreaterThan( RunStore::MAX_RECORD_BYTES, strlen( (string) json_encode( $record ) ) );

		self::assertTrue( RunStore::create( $record ) );

		$brute = (string) RunStore::read_raw( self::ID );
		$lue   = (array) RunStore::read( self::ID );
		self::assertSame( $brute, json_encode( $lue ), 'La valeur gardée est exactement l\'encodage que la route servira.' );
		self::assertLessThanOrEqual( RunStore::MAX_RECORD_BYTES, strlen( $brute ) );
		self::assertSame( [ 'plugin' => 'éditeur/éditeur.php' ], $lue['outcome']['result'] );
		self::assertTrue( $lue['outcome']['result_truncated'] );
	}

	/**
	 * Une valeur simple démesurée (texte de plusieurs dizaines de Ko) part aussi, en
	 * dernier recours, la plus longue d'abord : la borne tient toujours.
	 */
	public function test_en_dernier_recours_une_valeur_simple_demesuree_part_aussi(): void {
		$record = self::done_with(
			[
				'plugin'  => 'akismet/akismet.php',
				'message' => str_repeat( 'm', 70000 ),
				'tables'  => [ 'wp_posts' ],
			]
		);

		self::assertTrue( RunStore::create( $record ) );

		$issue = (array) ( RunStore::read( self::ID )['outcome'] ?? [] );
		self::assertSame( [ 'plugin' => 'akismet/akismet.php' ], $issue['result'] );
		self::assertTrue( $issue['result_truncated'] );
	}

	/**
	 * Plus rien à retirer et toujours trop gros (un champ fixé par le connecteur, ici la
	 * commande, démesuré : seul un appelant fautif le produit) : l'enregistrement n'est
	 * pas écrit (aucune requête), plutôt qu'écrit au-delà de ce que la plateforme lit.
	 */
	public function test_un_enregistrement_impossible_a_borner_n_est_pas_ecrit(): void {
		$record = self::record( [ 'command' => str_repeat( 'c', 70000 ) ] );

		self::assertSame( '', RunStore::encode( $record ) );
		self::assertFalse( RunStore::create( $record ) );
		self::assertSame( [], $this->table->queries );
	}

	/**
	 * L'étape est un libellé court, servi tel quel. Démesurée, elle ne doit pas rendre
	 * l'enregistrement impossible à écrire : elle est coupée comme une erreur.
	 */
	public function test_etape_demesuree_coupee_comme_une_erreur(): void {
		self::assertTrue( RunStore::create( self::record( [ 'step' => str_repeat( 'é', 40000 ) ] ) ) );

		$etape = (string) ( RunStore::read( self::ID )['step'] ?? '' );
		self::assertLessThanOrEqual( RunStore::MAX_ERROR_BYTES, strlen( $etape ) );
		self::assertSame( 1, preg_match( '//u', $etape ), 'Coupée entre deux caractères.' );
		self::assertStringEndsWith( '…', $etape );

		$relue = (string) RunStore::read_raw( self::ID );
		self::assertTrue( RunStore::swap( self::ID, $relue, self::record( [ 'step' => UpdateTransaction::STEP_UPGRADING ] ) ) );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, RunStore::read( self::ID )['step'] ?? null, 'Une étape ordinaire est gardée telle quelle.' );
	}

	/**
	 * Le résultat de reprise rattaché à une interruption est réduit comme le résultat.
	 * Sinon l'enregistrement ne pourrait plus être écrit, et le ramassage, qui le
	 * réécrit, échouerait à chaque passage. La partie la plus volumineuse est réduite
	 * d'abord : le résultat, plus petit, reste entier.
	 */
	public function test_resultat_de_reprise_demesure_reduit_le_resultat_reste_entier(): void {
		$reprise                = [
			'plugin_file'      => 'akismet/akismet.php',
			'restore_point_id' => 'rp-1',
			'step'             => UpdateTransaction::STEP_UPGRADING,
			'outcome'          => 'auto_rolled_back',
			'detail'           => str_repeat( 'd', 70000 ),
			'at'               => self::NOW + 5,
		];
		$record                 = self::done_with(
			[
				'plugin' => 'akismet/akismet.php',
				'liste'  => array_fill( 0, 100, 'xxxxxxxxxx' ),
			]
		);
		$record['interruption'] = [
			'reason'   => 'no_sign_of_life',
			'error'    => 'Aucun signe de vie depuis plus de 10 minutes.',
			'recovery' => $reprise,
		];

		self::assertTrue( RunStore::create( $record ) );

		self::assertLessThanOrEqual( RunStore::MAX_RECORD_BYTES, strlen( (string) RunStore::read_raw( self::ID ) ) );
		$lue = (array) RunStore::read( self::ID );
		self::assertSame( $record['outcome']['result'], $lue['outcome']['result'], 'Le résultat n\'est pas touché.' );
		self::assertArrayNotHasKey( 'result_truncated', $lue['outcome'] );
		unset( $reprise['detail'] );
		self::assertSame( $reprise, $lue['interruption']['recovery'], 'Seul le détail démesuré part.' );
		self::assertTrue( $lue['interruption']['recovery_truncated'] );
		self::assertSame( 'no_sign_of_life', $lue['interruption']['reason'] );
	}

	/**
	 * Un résultat qui n'est pas un tableau (un long texte) n'a pas d'entrée à retirer :
	 * il part entier, et le statut reste.
	 */
	public function test_un_resultat_simple_demesure_part_entier(): void {
		$record                      = self::done_with( [] );
		$record['outcome']['result'] = str_repeat( 'r', 70000 );

		self::assertTrue( RunStore::create( $record ) );

		$issue = (array) ( RunStore::read( self::ID )['outcome'] ?? [] );
		self::assertArrayHasKey( 'result', $issue );
		self::assertNull( $issue['result'] );
		self::assertTrue( $issue['result_truncated'] );
		self::assertSame( 'done', $issue['status'] );
	}

	/**
	 * Un résultat en liste (clés 0..n) reste une liste après réduction : sinon il serait
	 * servi en objet JSON (`{"0":…,"2":…}`), sous une autre forme que la réponse de
	 * `/command`.
	 */
	public function test_un_resultat_en_liste_reste_une_liste_apres_reduction(): void {
		$liste                       = [
			array_fill( 0, 10, 'petit' ),
			array_fill( 0, 7000, 'aaaaaaaaaa' ),
			[ 'moyen', 'moyen' ],
		];
		$record                      = self::done_with( [] );
		$record['outcome']['result'] = $liste;

		self::assertTrue( RunStore::create( $record ) );

		self::assertStringContainsString( '"result":[["petit"', (string) RunStore::read_raw( self::ID ), 'Servi en liste JSON, pas en objet.' );
		$issue = (array) ( RunStore::read( self::ID )['outcome'] ?? [] );
		self::assertSame( [ $liste[0], $liste[2] ], $issue['result'] );
		self::assertTrue( $issue['result_truncated'] );
	}

	/**
	 * create() faux ne dit pas à lui seul pourquoi : la relecture distingue la base qui
	 * refuse l'écriture (absent : rien n'a été reçu) du numéro déjà reçu (présent).
	 */
	public function test_creation_refusee_par_la_base_distincte_d_un_numero_deja_present(): void {
		$this->table->fails = true;
		self::assertFalse( RunStore::create( self::record() ) );
		self::assertNull( RunStore::read_raw( self::ID ), 'Absent : la base a refusé l\'écriture.' );

		$this->table->fails = false;
		self::assertTrue( RunStore::create( self::record() ) );
		self::assertFalse( RunStore::create( self::record() ) );
		self::assertNotNull( RunStore::read_raw( self::ID ), 'Présent : ce numéro a déjà été reçu.' );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function unreadable_values(): iterable {
		yield 'JSON coupé' => [ '{"command_id":"' . self::ID . '","state":"runn' ];
		yield 'objet PHP sérialisé' => [ 'O:8:"stdClass":1:{s:5:"state";s:4:"done";}' ];
		yield 'tableau PHP sérialisé' => [ 'a:1:{s:5:"state";s:4:"done";}' ];
		yield 'null' => [ 'null' ];
		yield 'texte' => [ '"done"' ];
		yield 'nombre' => [ '42' ];
		yield 'vide' => [ '' ];
		yield 'liste JSON vide' => [ '[]' ];
		yield 'objet JSON vide' => [ '{}' ];
		yield 'liste JSON' => [ '[1]' ];
		yield 'objet sans numéro' => [ '{"state":"done"}' ];
		yield 'numéro qui n\'est pas un texte' => [ '{"command_id":1,"state":"done"}' ];
		yield 'objet sans état' => [ '{"command_id":"' . self::ID . '"}' ];
	}

	/**
	 * Une valeur abîmée ou écrite par un autre moyen n'est jamais désérialisée ni prise
	 * pour un enregistrement : elle est rendue absente, sans erreur.
	 */
	#[DataProvider( 'unreadable_values' )]
	public function test_valeur_illisible_rendue_absente( string $value ): void {
		$this->table->rows[ 'g2rd_cmdrun_' . self::ID ] = [
			'value'    => $value,
			'autoload' => 'no',
		];

		self::assertNull( RunStore::read( self::ID ) );
		self::assertNull( RunStore::decode( $value ) );
	}

	public function test_base_qui_refuse_l_ecriture(): void {
		RunStore::create( self::record() );
		$relue              = (string) RunStore::read_raw( self::ID );
		$this->table->fails = true;

		self::assertFalse( RunStore::swap( self::ID, $relue, self::record( [ 'state' => RunState::RUNNING ] ) ) );
		self::assertFalse( RunStore::create( self::record( [ 'command_id' => 'fedcba9876543210fedcba9876543210' ] ) ) );

		$this->table->fails = false;
		self::assertSame(
			$relue,
			RunStore::read_raw( self::ID ),
			'Rien n\'a changé : la relecture rend la valeur attendue, ce n\'est pas une course perdue.'
		);
	}

	/**
	 * Un enregistrement est toujours écrit sous l'option de son propre numéro : sinon la
	 * route de lecture servirait, pour le numéro demandé, celui d'une autre exécution.
	 */
	public function test_swap_refuse_l_enregistrement_d_un_autre_numero(): void {
		RunStore::create( self::record() );
		$relue    = (string) RunStore::read_raw( self::ID );
		$requetes = count( $this->table->queries );

		$autre = self::record(
			[
				'command_id' => 'fedcba9876543210fedcba9876543210',
				'state'      => RunState::RUNNING,
			]
		);
		self::assertFalse( RunStore::swap( self::ID, $relue, $autre ) );
		$sans_numero = self::record( [ 'state' => RunState::RUNNING ] );
		unset( $sans_numero['command_id'] );
		self::assertFalse( RunStore::swap( self::ID, $relue, $sans_numero ) );

		self::assertCount( $requetes, $this->table->queries, 'Refusé avant toute requête.' );
		self::assertSame( $relue, RunStore::read_raw( self::ID ), 'Rien n\'a changé.' );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function malformed_ids(): iterable {
		yield 'vide' => [ '' ];
		yield 'nom du créneau' => [ 'slot' ];
		yield 'majuscules' => [ '0123456789ABCDEF0123456789ABCDEF' ];
		yield '31 caractères' => [ substr( self::ID, 0, 31 ) ];
		yield '33 caractères' => [ self::ID . 'a' ];
		yield 'saut de ligne final' => [ self::ID . "\n" ];
		yield 'hors hexadécimal' => [ 'g123456789abcdef0123456789abcdef' ];
		yield 'injection' => [ "' OR 1=1 -- 0123456789abcdef0123" ];
		yield 'nombre' => [ 123 ];
		yield 'absent' => [ null ];
	}

	/**
	 * Le numéro est contrôlé avant toute requête : un nom d'option arbitraire (le
	 * créneau `g2rd_cmdrun_slot`, par exemple) n'est jamais lu ni écrit par ce biais.
	 */
	#[DataProvider( 'malformed_ids' )]
	public function test_numero_mal_forme_refuse_avant_toute_requete( mixed $command_id ): void {
		$record = self::record( [ 'command_id' => $command_id ] );
		if ( null === $command_id ) {
			unset( $record['command_id'] );
		}

		self::assertFalse( RunStore::create( $record ) );
		if ( is_string( $command_id ) ) {
			self::assertNull( RunStore::read_raw( $command_id ) );
			self::assertNull( RunStore::read( $command_id ) );
			self::assertFalse( RunStore::swap( $command_id, '{}', $record ) );
		}
		self::assertSame( [], $this->table->queries );
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private static function record( array $changes = [] ): array {
		return array_merge(
			[
				'command_id'  => self::ID,
				'command'     => 'update_plugin',
				'body_sha256' => str_repeat( 'ab', 32 ),
				'state'       => RunState::ACCEPTED,
				'accepted_at' => self::NOW,
				'started_at'  => null,
				'alive_at'    => null,
				'finished_at' => null,
			],
			$changes
		);
	}

	/**
	 * Exécution terminée avec ce résultat (et cette sortie parasite).
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function done_with( array $result, ?string $stray_output = null ): array {
		$outcome = [
			'status' => 'done',
			'result' => $result,
			'error'  => null,
		];
		if ( null !== $stray_output ) {
			$outcome['stray_output'] = $stray_output;
		}

		return self::record(
			[
				'state'       => RunState::DONE,
				'started_at'  => self::NOW + 1,
				'alive_at'    => self::NOW + 30,
				'finished_at' => self::NOW + 30,
				'outcome'     => $outcome,
			]
		);
	}
}
