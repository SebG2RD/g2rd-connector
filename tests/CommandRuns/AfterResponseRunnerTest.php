<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunHeartbeat;
use G2RD\Connector\CommandRuns\RunReaper;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Répondre, puis exécuter (§ 3.1 de la spécification, recommandation A) : la réponse 202
 * de l'acceptation part, la réponse est terminée, puis la commande s'exécute dans le même
 * processus, dans le déroulé normal de la requête (filtre `rest_pre_serve_request`),
 * jamais dans une fonction d'arrêt.
 *
 * Les quatre dépendances du coureur sont injectées et tiennent un journal d'appels :
 * l'écriture du JSON, la fin de la réponse (litespeed_finish_request(),
 * fastcgi_finish_request() : jamais déclarées dans un test, cf. SnapshotCapabilitiesTest),
 * l'exécution de la commande et l'horloge. L'enregistrement de l'exécution et le créneau
 * sont les vrais (RunStore, ExecutionSlot), sur la table des options en mémoire.
 */
final class AfterResponseRunnerTest extends TestCase {

	/** 03/09/2026 00:26:40 UTC. */
	private const NOW = 1789000000;
	private const ID  = '0123456789abcdef0123456789abcdef';

	/** Réponse 202 de l'acceptation (CommandRunsController::accept()). */
	private const ACCEPTEE = [
		'command_id'         => self::ID,
		'state'              => 'accepted',
		'accepted_at'        => '2026-09-03T00:26:40+00:00',
		'poll_after_seconds' => 2,
		'served_at'          => '2026-09-03T00:26:40+00:00',
	];

	private FakeOptionsTable $table;

	/** Heure rendue par l'horloge injectée. */
	private int $now = self::NOW;

	/**
	 * Appels des dépendances injectées, dans l'ordre.
	 *
	 * @var list<string>
	 */
	private array $journal = [];

	/** JSON écrit par l'écriture par défaut du coureur de test. */
	private string $ecrit = '';

	/** Journal d'erreurs PHP du test (error_log()), fichier temporaire ; '' : aucun. */
	private string $journal_php = '';

	/** Réglage `error_log` d'avant le test, rétabli à la fin. */
	private string $error_log_avant = '';

	protected function setUp(): void {
		parent::setUp();
		$this->table     = new FakeOptionsTable();
		$GLOBALS['wpdb'] = $this->table;
		$this->now       = self::NOW;
		$this->journal   = [];
		$this->ecrit     = '';
		// wp_json_encode() sans option, comme WordPress.
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		ignore_user_abort( false );
		// Aucune exécution attachée par un test précédent : état propre au processus.
		RunHeartbeat::detach();
	}

	protected function tearDown(): void {
		RunHeartbeat::detach();
		ignore_user_abort( false );
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
	 * Critère d'acceptation : réponse écrite, réponse terminée, puis commande. La fermeture
	 * du client ne coupe plus rien dès avant l'écriture (LiteSpeed sans `noabort`), et la
	 * commande trouve son exécution démarrée.
	 */
	public function test_ordre_reponse_ecrite_puis_terminee_puis_commande(): void {
		$this->accepte();
		$runner = $this->coureur(
			executor: function ( string $command, ?array $payload ): array {
				$this->journal[] = 'commande ' . $command . ' ' . (string) wp_json_encode( $payload ) . ', exécution ' . ( RunStore::read( self::ID )['state'] ?? '?' );
				return [
					'status' => 'done',
					'result' => [ 'plugin' => 'akismet/akismet.php' ],
				];
			},
			output: function ( string $json ): void {
				$this->journal[] = 'réponse ' . $json . ', abandon du client ignoré : ' . ignore_user_abort();
			}
		);
		$runner->queue( self::ID, 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		$servie = $runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		self::assertTrue( $servie, 'Réponse servie par le connecteur : WordPress ne l\'écrit pas une seconde fois.' );
		self::assertSame(
			[
				'réponse ' . json_encode( self::ACCEPTEE ) . ', abandon du client ignoré : 1',
				'fin de réponse',
				'commande update_plugin {"file":"akismet\/akismet.php"}, exécution running',
			],
			$this->journal
		);
		self::assertSame( RunState::DONE, $this->execution()['state'] ?? null );
	}

	/**
	 * Le filtre passe sur toutes les requêtes REST du site, éditeur de blocs compris : sans
	 * exécution en attente, il rend sa valeur telle quelle, sans rien lire (ni la réponse,
	 * ni la base).
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function valeurs_recues(): array {
		return [
			'pas encore servie'          => [ false ],
			'servie par un autre filtre' => [ true ],
			'valeur d\'un autre filtre'  => [ 'servie' ],
		];
	}

	#[DataProvider( 'valeurs_recues' )]
	public function test_sans_execution_en_attente_le_filtre_ne_fait_rien( mixed $valeur ): void {
		$runner  = $this->coureur();
		$illisible = new class() extends WP_REST_Response {
			public function get_data(): mixed {
				throw new \LogicException( 'La réponse d\'une autre requête ne doit pas être lue.' );
			}
		};

		self::assertSame( $valeur, $runner->maybe_serve( $valeur, $illisible, $this->requete(), null ) );
		self::assertSame( $valeur, $runner->maybe_serve( $valeur, null, null, null ), 'Arguments inattendus : rien ne remonte.' );
		self::assertSame( [], $this->journal, 'Ni écriture, ni fin de réponse, ni commande.' );
		self::assertSame( [], $this->table->queries, 'Aucune lecture de la base.' );
		self::assertSame( 0, ignore_user_abort() );
	}

	/**
	 * Une erreur fatale levée dans une fonction d'arrêt empêche les suivantes de passer :
	 * la reprise d'une mise à jour protégée et la réactivation de l'extension. La commande
	 * est donc exécutée avant que le filtre ne rende la main, jamais confiée à une fonction
	 * d'arrêt.
	 */
	public function test_le_travail_n_est_jamais_confie_a_une_fonction_d_arret(): void {
		$confiees = [];
		Functions\when( 'register_shutdown_function' )->alias(
			static function ( callable $callback ) use ( &$confiees ): void {
				$confiees[] = $callback;
			}
		);
		$this->accepte();
		$executions = 0;
		$executeur  = static function () use ( &$executions ): array {
			++$executions;
			return [
				'status' => 'done',
				'result' => [],
			];
		};
		$runner     = $this->coureur( executor: $executeur );
		$runner->queue( self::ID, 'optimize_database', null );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		self::assertSame( 1, $executions, 'Commande exécutée avant que le filtre ne rende la main.' );
		self::assertNotContains( $executeur, $confiees, 'Jamais confiée à une fonction d\'arrêt.' );
		self::assertSame( RunState::DONE, $this->execution()['state'] ?? null, 'Résultat écrit avant que le filtre ne rende la main.' );
	}

	/**
	 * Le ramassage (autre processus) déclare l'exécution abandonnée entre la réponse et le
	 * démarrage : le démarrage est perdu, rien ne s'exécute. Une exécution est soit
	 * démarrée, soit abandonnée, jamais les deux.
	 */
	public function test_execution_ramassee_avant_le_demarrage_n_est_pas_lancee(): void {
		$this->accepte();
		$runner = $this->coureur(
			terminator: function (): bool {
				$this->journal[] = 'fin de réponse';
				// Le processus a été suspendu plus de 120 s : le ramassage est passé.
				RunReaper::sweep_one( self::ID, self::NOW + RunReaper::ACCEPTED_TIMEOUT_SECONDS + 1 );
				$this->now = self::NOW + RunReaper::ACCEPTED_TIMEOUT_SECONDS + 2;
				return true;
			}
		);
		$runner->queue( self::ID, 'update_core', null );

		self::assertTrue( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ), 'La réponse est déjà partie.' );

		self::assertNotContains( 'commande', $this->journal, 'Rien n\'est exécuté.' );
		$execution = $this->execution();
		self::assertSame( RunState::ABANDONED, $execution['state'] ?? null );
		self::assertNull( $execution['started_at'] ?? null );
		self::assertArrayNotHasKey( 'outcome', $execution );
		self::assertNull( ExecutionSlot::holder(), 'Le site est libre.' );
	}

	/**
	 * Le résultat a la forme de la réponse de `/command` (CommandController::handle()) :
	 * {command, status, result, error, at, stray_output?}, sans `signature_check` (la
	 * signature des routes en arrière-plan est toujours exigée).
	 */
	public function test_resultat_ecrit_avec_la_forme_de_command(): void {
		$this->accepte();
		$runner = $this->coureur(
			executor: function (): array {
				$this->now = self::NOW + 42;
				return [
					'status'       => 'done',
					'result'       => [
						'plugin'      => 'akismet/akismet.php',
						'old_version' => '5.3',
						'new_version' => '5.4',
					],
					'stray_output' => 'Notice : sortie d’une extension tierce',
				];
			}
		);
		$runner->queue( self::ID, 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		$execution = $this->execution();
		self::assertSame( RunState::DONE, $execution['state'] ?? null );
		self::assertSame( self::NOW + 42, $execution['finished_at'] ?? null );
		self::assertSame(
			[
				'command'      => 'update_plugin',
				'status'       => 'done',
				'result'       => [
					'plugin'      => 'akismet/akismet.php',
					'old_version' => '5.3',
					'new_version' => '5.4',
				],
				'error'        => null,
				'at'           => gmdate( 'c', self::NOW + 42 ),
				'stray_output' => 'Notice : sortie d’une extension tierce',
			],
			$execution['outcome'] ?? null
		);
	}

	/**
	 * La commande a échoué (code 500 de `/command`) : l'exécution est `failed`, avec
	 * l'erreur de la commande telle quelle.
	 */
	public function test_echec_de_la_commande_ecrit_failed(): void {
		$this->accepte();
		$runner = $this->coureur(
			executor: static fn (): array => [
				'status' => 'failed',
				'error'  => 'La mise à jour a échoué : le dossier de l’extension n’est pas accessible en écriture.',
			]
		);
		$runner->queue( self::ID, 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		$execution = $this->execution();
		self::assertSame( RunState::FAILED, $execution['state'] ?? null );
		self::assertSame(
			[
				'command' => 'update_plugin',
				'status'  => 'failed',
				'result'  => null,
				'error'   => 'La mise à jour a échoué : le dossier de l’extension n’est pas accessible en écriture.',
				'at'      => gmdate( 'c', self::NOW ),
			],
			$execution['outcome'] ?? null
		);
	}

	/**
	 * Commande que l'exécuteur ne connaît pas (il rend null, comme CommandExecutor::run()) :
	 * `failed`, avec un message explicite. Rien n'a été modifié.
	 */
	public function test_commande_inconnue_de_l_executeur_ecrit_failed_avec_un_message_explicite(): void {
		$this->accepte();
		$runner = $this->coureur( executor: static fn (): ?array => null );
		$runner->queue( self::ID, 'update_plugin', [ 'file' => 'akismet/akismet.php' ] );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		$execution = $this->execution();
		self::assertSame( RunState::FAILED, $execution['state'] ?? null );
		self::assertSame( 'failed', $execution['outcome']['status'] ?? null );
		self::assertArrayHasKey( 'result', $execution['outcome'] ?? [] );
		self::assertNull( $execution['outcome']['result'] );
		$erreur = (string) ( $execution['outcome']['error'] ?? '' );
		self::assertStringContainsString( '« update_plugin » n’a pas été exécutée', $erreur );
		self::assertStringContainsString( 'Rien n’a été modifié', $erreur );
		self::assertStringContainsString( 'Cause probable', $erreur );
		self::assertStringContainsString( 'Action', $erreur );
	}

	/**
	 * CommandExecutor::run() rattrape lui-même toute exception de la commande. Une
	 * exception d'une autre étape (exécuteur, signe de vie) est rattrapée ici : `failed`,
	 * message explicite, créneau rendu, signe de vie détaché, et rien ne remonte.
	 */
	public function test_une_exception_hors_de_la_commande_ecrit_failed(): void {
		$this->accepte();
		$runner = $this->coureur(
			executor: static function (): never {
				throw new \RuntimeException( 'Disque plein' );
			}
		);
		$runner->queue( self::ID, 'update_theme', [ 'stylesheet' => 'twentytwentyfour' ] );

		self::assertTrue( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ) );

		$execution = $this->execution();
		self::assertSame( RunState::FAILED, $execution['state'] ?? null );
		$erreur = (string) ( $execution['outcome']['error'] ?? '' );
		self::assertStringContainsString( '« update_theme »', $erreur );
		self::assertStringContainsString( 'Disque plein', $erreur );
		self::assertStringContainsString( 'Cause probable', $erreur );
		self::assertStringContainsString( 'Action', $erreur );
		self::assertNull( ExecutionSlot::holder() );
		self::assertFalse( has_filter( 'upgrader_pre_download', [ RunHeartbeat::class, 'on_filter' ] ) );
	}

	/**
	 * Par défaut, la commande passe par CommandExecutor::run(), tel quel : ici le
	 * nettoyage des commentaires indésirables, sur des fonctions WordPress simulées.
	 */
	public function test_par_defaut_la_commande_est_executee_par_command_executor(): void {
		Functions\when( 'get_comments' )->justReturn( [ 11, 12 ] );
		Functions\when( 'wp_delete_comment' )->justReturn( true );
		$this->accepte( 'delete_spam_comments' );
		$runner = new AfterResponseRunner(
			terminator: static fn (): bool => true,
			clock: fn (): int => $this->now,
			output: static function (): void {}
		);
		$runner->queue( self::ID, 'delete_spam_comments', null );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		$execution = $this->execution();
		self::assertSame( RunState::DONE, $execution['state'] ?? null );
		self::assertSame(
			[
				'deleted_count' => 2,
				'total_spam'    => 2,
			],
			$execution['outcome']['result'] ?? null
		);
	}

	/**
	 * Par défaut, le JSON de la réponse est écrit sur la sortie, comme
	 * WP_REST_Server::serve_request() : wp_json_encode() de ses données.
	 */
	public function test_par_defaut_le_json_est_ecrit_sur_la_sortie(): void {
		$this->accepte();
		$runner = new AfterResponseRunner(
			terminator: static fn (): bool => true,
			executor: static fn (): array => [
				'status' => 'done',
				'result' => [],
			],
			clock: fn (): int => $this->now
		);
		$runner->queue( self::ID, 'update_core', null );

		$this->expectOutputString( (string) json_encode( self::ACCEPTEE ) );
		self::assertTrue( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ) );
	}

	/**
	 * Le créneau d'exécution est rendu à la fin, que la commande ait réussi ou échoué : le
	 * site est libre pour la suivante.
	 *
	 * @return array<string, array{array<string, mixed>|null}>
	 */
	public static function issues(): array {
		return [
			'réussie'  => [
				[
					'status' => 'done',
					'result' => [],
				],
			],
			'échouée'  => [
				[
					'status' => 'failed',
					'error'  => 'Échec du téléchargement.',
				],
			],
			'inconnue' => [ null ],
		];
	}

	/**
	 * @param array<string, mixed>|null $issue
	 */
	#[DataProvider( 'issues' )]
	public function test_le_creneau_est_rendu_a_la_fin( ?array $issue ): void {
		$this->accepte();
		$tenu   = null;
		$runner = $this->coureur(
			executor: static function () use ( &$tenu, $issue ): ?array {
				$tenu = ExecutionSlot::holder()['command_id'] ?? null;
				return $issue;
			}
		);
		$runner->queue( self::ID, 'update_core', null );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		self::assertSame( self::ID, $tenu, 'Créneau tenu pendant l\'exécution.' );
		self::assertNull( ExecutionSlot::holder(), 'Créneau rendu à la fin.' );
		self::assertArrayNotHasKey( ExecutionSlot::OPTION, $this->table->rows );
	}

	/**
	 * Le signe de vie est attaché pendant la commande (crochets de mise à jour, tables
	 * optimisées, étapes d'une mise à jour protégée), puis détaché : plus rien n'est écrit
	 * pour cette exécution après sa fin.
	 */
	public function test_le_signe_de_vie_est_detache_a_la_fin(): void {
		$this->accepte();
		$pendant = [];
		$runner  = $this->coureur(
			executor: static function () use ( &$pendant ): array {
				$pendant = self::crochets_du_signe_de_vie();
				return [
					'status' => 'done',
					'result' => [],
				];
			}
		);
		$runner->queue( self::ID, 'optimize_database', null );

		$runner->maybe_serve( false, $this->reponse(), $this->requete(), null );

		self::assertSame( [ true, true, true ], $pendant, 'Attaché pendant la commande.' );
		self::assertSame( [ false, false, false ], self::crochets_du_signe_de_vie(), 'Détaché à la fin.' );
	}

	/**
	 * Sans litespeed_finish_request() ni fastcgi_finish_request(), la réponse ne peut pas
	 * partir avant la fin de la commande : la capacité `async_commands` n'est pas annoncée,
	 * le site reste en mode actuel (décision n° 6). Détection injectée : ces fonctions ne
	 * sont jamais déclarées dans un test.
	 */
	public function test_sans_fonction_de_fin_la_capacite_est_absente(): void {
		$demandees = [];
		$aucune    = static function ( string $fonction ) use ( &$demandees ): bool {
			$demandees[] = $fonction;
			return false;
		};

		self::assertFalse( AfterResponseRunner::finisher_available( $aucune ) );
		self::assertSame( [ 'litespeed_finish_request', 'fastcgi_finish_request' ], $demandees );
		self::assertTrue( AfterResponseRunner::finisher_available( static fn ( string $fonction ): bool => 'litespeed_finish_request' === $fonction ) );
		self::assertTrue( AfterResponseRunner::finisher_available( static fn ( string $fonction ): bool => 'fastcgi_finish_request' === $fonction ) );
		self::assertFalse( AfterResponseRunner::finisher_available(), 'En ligne de commande, aucune des deux n\'existe.' );
	}

	/**
	 * L'exécution programmée est oubliée dès qu'elle est prise en charge : un second
	 * passage du filtre dans le même processus (autre réponse REST) ne la relance pas.
	 */
	public function test_une_seule_execution_par_requete(): void {
		$this->accepte();
		$runner = $this->coureur();
		$runner->queue( self::ID, 'update_core', null );

		self::assertTrue( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ) );
		self::assertFalse( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ), 'Second passage : rien n\'est servi.' );

		self::assertSame( [ 'réponse', 'fin de réponse', 'commande update_core' ], $this->journal );
		self::assertSame( (string) json_encode( self::ACCEPTEE ), $this->ecrit, 'Réponse écrite une fois.' );
	}

	/**
	 * Une erreur interne pendant le service (ici, l'écriture de la réponse) ne remonte pas :
	 * rien n'a été écrit, WordPress sert la réponse lui-même, la commande s'exécute une
	 * fois (avant la réponse, faute de mieux), et la requête REST suivante n'est pas
	 * touchée.
	 */
	public function test_une_erreur_interne_du_filtre_ne_casse_pas_une_autre_requete_rest(): void {
		$this->accepte();
		$runner = $this->coureur(
			output: function (): void {
				$this->journal[] = 'réponse';
				throw new \RuntimeException( 'Sortie fermée' );
			}
		);
		$runner->queue( self::ID, 'update_core', null );

		self::assertFalse( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ), 'Rien d\'écrit : WordPress sert la réponse.' );
		self::assertSame( [ 'réponse', 'commande update_core' ], $this->journal, 'Sans fin de réponse : celle de WordPress serait perdue.' );
		self::assertSame( RunState::DONE, $this->execution()['state'] ?? null );
		self::assertNull( ExecutionSlot::holder() );

		$autre = new WP_REST_Response( [ 'id' => 42 ], 200 );
		self::assertFalse( $runner->maybe_serve( false, $autre, new WP_REST_Request( 'GET', '/wp/v2/posts/42' ), null ), 'La requête REST suivante est servie par WordPress.' );
		self::assertSame( [ 'réponse', 'commande update_core' ], $this->journal );
	}

	/**
	 * Un autre filtre a déjà servi la réponse : rien n'est écrit de plus (le corps serait
	 * doublé), la réponse est terminée, puis la commande s'exécute.
	 */
	public function test_reponse_deja_servie_par_un_autre_filtre_rien_n_est_ecrit_deux_fois(): void {
		$this->accepte();
		$runner = $this->coureur();
		$runner->queue( self::ID, 'update_core', null );

		self::assertTrue( $runner->maybe_serve( true, $this->reponse(), $this->requete(), null ) );

		self::assertSame( [ 'fin de réponse', 'commande update_core' ], $this->journal );
		self::assertSame( '', $this->ecrit, 'Rien n\'est écrit par le connecteur.' );
		self::assertSame( 1, ignore_user_abort() );
	}

	/**
	 * Fonction de fin présente mais inopérante (ou qui lève) : une ligne au journal PHP du
	 * site pour le diagnostic, et l'exécution continue. La justesse ne dépend pas de la
	 * rapidité : un renvoi du CDN trouverait le numéro et recevrait « déjà reçue ».
	 *
	 * @return array<string, array{\Closure(): bool}>
	 */
	public static function fins_inoperantes(): array {
		return [
			'rend faux' => [ static fn (): bool => false ],
			'lève'      => [
				static function (): never {
					throw new \RuntimeException( 'LSAPI indisponible' );
				},
			],
		];
	}

	#[DataProvider( 'fins_inoperantes' )]
	public function test_fin_de_reponse_inoperante_une_ligne_au_journal_et_l_execution_continue( \Closure $fin ): void {
		$this->capture_le_journal_php();
		$this->accepte();
		$runner = $this->coureur( terminator: $fin );
		$runner->queue( self::ID, 'update_core', null );

		self::assertTrue( $runner->maybe_serve( false, $this->reponse(), $this->requete(), null ) );

		self::assertSame( [ 'réponse', 'commande update_core' ], $this->journal );
		self::assertSame( RunState::DONE, $this->execution()['state'] ?? null );
		$lignes = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->journal_php ) ) ) );
		self::assertCount( 1, $lignes, 'Une ligne au journal PHP.' );
		self::assertStringContainsString( 'G2RD Connector', $lignes[0] );
		self::assertStringContainsString( self::ID, $lignes[0] );
		self::assertStringContainsString( 'update_core', $lignes[0] );
		self::assertStringContainsString( 'Cause probable', $lignes[0] );
		self::assertStringContainsString( 'Action', $lignes[0] );
	}

	/**
	 * `_envelope` ou `_jsonp` dans l'adresse (jamais envoyés par la plateforme) :
	 * WordPress écrit lui-même la réponse, sous une autre forme, après le filtre. Le
	 * connecteur ne prend pas la main : rien d'écrit, réponse non terminée (elle serait
	 * perdue), et la commande s'exécute dans le filtre, par le même chemin, jamais dans une
	 * fonction d'arrêt.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function adresses_enveloppees(): array {
		return [
			'_envelope' => [ [ '_envelope' => '1' ] ],
			'_jsonp'    => [ [ '_jsonp' => 'rappel' ] ],
		];
	}

	/**
	 * @param array<string, string> $parametres
	 */
	#[DataProvider( 'adresses_enveloppees' )]
	public function test_enveloppe_ou_jsonp_wordpress_sert_la_reponse( array $parametres ): void {
		$this->accepte();
		$runner = $this->coureur();
		$runner->queue( self::ID, 'update_core', null );

		self::assertFalse( $runner->maybe_serve( false, $this->reponse(), $this->requete( $parametres ), null ), 'WordPress sert la réponse.' );

		self::assertSame( [ 'commande update_core' ], $this->journal );
		self::assertSame( RunState::DONE, $this->execution()['state'] ?? null );
		self::assertNull( ExecutionSlot::holder() );
	}

	public function test_une_instance_partagee_pour_le_branchement(): void {
		self::assertSame( AfterResponseRunner::instance(), AfterResponseRunner::instance() );
	}

	/**
	 * Coureur aux dépendances injectées, qui notent leurs appels au journal.
	 *
	 * @param (\Closure(): bool)|null                                        $terminator
	 * @param (\Closure(string, array<mixed>|null): (array<string, mixed>|null))|null $executor
	 * @param (\Closure(string): void)|null                                  $output
	 */
	private function coureur( ?\Closure $terminator = null, ?\Closure $executor = null, ?\Closure $output = null ): AfterResponseRunner {
		return new AfterResponseRunner(
			terminator: $terminator ?? function (): bool {
				$this->journal[] = 'fin de réponse';
				return true;
			},
			executor: $executor ?? function ( string $command ): array {
				$this->journal[] = 'commande ' . $command;
				return [
					'status' => 'done',
					'result' => [],
				];
			},
			clock: fn (): int => $this->now,
			output: $output ?? function ( string $json ): void {
				$this->journal[] = 'réponse';
				$this->ecrit    .= $json;
			}
		);
	}


	/** Exécution acceptée, créneau pris : l'état laissé par la route d'acceptation. */
	private function accepte( string $command = 'update_plugin' ): void {
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

	/** @return array<string, mixed> */
	private function execution(): array {
		return (array) RunStore::read( self::ID );
	}

	private function reponse(): WP_REST_Response {
		return new WP_REST_Response( self::ACCEPTEE, 202 );
	}

	/**
	 * @param array<string, string> $parametres Paramètres de l'adresse (`?…`).
	 */
	private function requete( array $parametres = [] ): WP_REST_Request {
		$requete = new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' );
		$requete->set_query_params( $parametres );
		return $requete;
	}

	/**
	 * Écouteurs du signe de vie branchés : un filtre de mise à jour, la table optimisée,
	 * l'étape d'une mise à jour protégée.
	 *
	 * @return list<bool>
	 */
	private static function crochets_du_signe_de_vie(): array {
		return [
			false !== has_filter( 'upgrader_pre_download', [ RunHeartbeat::class, 'on_filter' ] ),
			false !== has_action( 'g2rd_connector_command_progress', [ RunHeartbeat::class, 'on_progress' ] ),
			false !== has_action( 'g2rd_connector_update_txn_progress', [ RunHeartbeat::class, 'on_transaction_step' ] ),
		];
	}

	/** Redirige error_log() vers un fichier temporaire du test. */
	private function capture_le_journal_php(): void {
		$this->journal_php     = (string) tempnam( sys_get_temp_dir(), 'g2rd-journal-' );
		$this->error_log_avant = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->journal_php );
	}
}
