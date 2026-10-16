<?php
/**
 * Processus PHP à part de ShutdownOrderTest : une commande s'arrête en pleine exécution en
 * arrière-plan, et les fonctions d'arrêt passent dans leur ordre RÉEL (§ 3.5 de la
 * spécification). Un vrai arrêt de PHP, pas une simulation : seul un processus à part peut
 * mourir d'une erreur fatale sans emporter PHPUnit.
 *
 * Lancé sans php.ini (`php -n`), avec deux arguments : le fichier d'état (créé par le test)
 * et le scénario :
 *   - « fatale » : erreur fatale pendant la commande. Un fichier d'extension est chargé deux
 *     fois (« Cannot redeclare », erreur de compilation que rien ne rattrape), et le nom de
 *     sa fonction rend le message plus long que 2 Ko ;
 *   - « fatale-dans-la-reprise » : la même, puis une seconde erreur fatale, dans la reprise ;
 *   - « sortie » : exit pendant la commande (wp_die() d'une extension), après un simple
 *     avertissement, que error_get_last() rend encore à l'arrêt ;
 *   - « fin-normale » : la commande rend son résultat.
 *
 * Charge l'amorce des tests (chargeur, doublures des classes WordPress), Brain Monkey pour
 * les quelques fonctions WordPress utilisées, et la fausse table d'options. Le coureur, son
 * dernier filet, l'enregistrement de l'exécution, le créneau et les résultats de reprise
 * (PendingOutcomes) sont les vrais ; seuls l'exécuteur, l'horloge, la fin de réponse et
 * l'écriture du JSON sont injectés. L'exécuteur arme, comme une mise à jour protégée, deux
 * fonctions d'arrêt témoins, dans leur ordre réel : « reprise »
 * (ProtectedUpdate::recover_on_shutdown(), qui consigne son résultat dans PendingOutcomes
 * quand la mise à jour meurt en route), puis « réactivation »
 * (CommandExecutor::perform_plugin_upgrade()).
 *
 * Le processus peut mourir à tout moment : son état est recopié dans le fichier d'état après
 * chaque requête à la table et chaque passage d'un témoin, en JSON {journal, lignes,
 * options, notices}. `journal` : dans l'ordre, les changements d'état de l'exécution
 * (« exécution running »…) et du créneau (« créneau pris », « créneau rendu »), et les
 * passages des témoins (« reprise », « réactivation »). `notices` : erreurs PHP non fatales
 * levées dans le processus, l'avertissement voulu du scénario « sortie » excepté.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\Rollback\PendingOutcomes;
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

/**
 * Faux $wpdb du processus : la fausse table des options, recopiée dans le fichier d'état
 * après chaque requête (le processus peut mourir à la suivante).
 */
final class G2rdTableRecopiee {

	/**
	 * @param FakeOptionsTable $table    Table des options en mémoire.
	 * @param \Closure(): void $recopier Recopie l'état dans le fichier.
	 */
	public function __construct( private FakeOptionsTable $table, private \Closure $recopier ) {
	}

	/** Propriétés de wpdb (`options`, `last_error`), lues sur la fausse table. */
	public function __get( string $name ): mixed {
		return $this->table->$name;
	}

	/**
	 * Requête passée à la fausse table, puis état recopié.
	 *
	 * @param array<mixed> $args
	 */
	public function __call( string $name, array $args ): mixed {
		$result = $this->table->$name( ...$args );
		( $this->recopier )();

		return $result;
	}
}

const G2RD_SCENARIOS = [ 'fatale', 'fatale-dans-la-reprise', 'sortie', 'fin-normale' ];

$g2rd_fichier  = (string) ( $argv[1] ?? '' );
$g2rd_scenario = (string) ( $argv[2] ?? '' );
if ( '' === $g2rd_fichier || ! in_array( $g2rd_scenario, G2RD_SCENARIOS, true ) ) {
	echo 'Usage : php -n fatal-during-run.php <fichier d’état> <' . implode( '|', G2RD_SCENARIOS ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aide en ligne de commande, texte fixe.
	exit( 2 );
}

$g2rd_id        = '0123456789abcdef0123456789abcdef';
$g2rd_extension = 'akismet/akismet.php';
// 03/09/2026 00:26:40 UTC : acceptation et démarrage.
$g2rd_debut      = 1789000000;
$g2rd_maintenant = $g2rd_debut;
$g2rd_alerte     = 'Avertissement d’une extension, sans gravité';

// Ni affichage ni journal : l'erreur fatale reste lisible par error_get_last(), et la sortie
// du processus reste vide.
ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );

$g2rd_journal = [];
$g2rd_options = [];
$g2rd_notices = [];
$g2rd_suivi   = [
	'etat'    => null,
	'creneau' => false,
];
$g2rd_table   = new FakeOptionsTable();

$g2rd_recopier = static function () use ( &$g2rd_journal, &$g2rd_options, &$g2rd_notices, &$g2rd_suivi, $g2rd_table, $g2rd_fichier, $g2rd_id ): void {
	$ligne = $g2rd_table->rows[ RunStore::option_name( $g2rd_id ) ]['value'] ?? null;
	$etat  = null === $ligne ? null : ( json_decode( $ligne, true )['state'] ?? '?' );
	if ( $etat !== $g2rd_suivi['etat'] ) {
		$g2rd_journal[]     = 'exécution ' . $etat;
		$g2rd_suivi['etat'] = $etat;
	}
	$creneau = isset( $g2rd_table->rows[ ExecutionSlot::OPTION ] );
	if ( $creneau !== $g2rd_suivi['creneau'] ) {
		$g2rd_journal[]        = $creneau ? 'créneau pris' : 'créneau rendu';
		$g2rd_suivi['creneau'] = $creneau;
	}
	file_put_contents(
		$g2rd_fichier,
		json_encode(
			[
				'journal' => $g2rd_journal,
				'lignes'  => $g2rd_table->rows,
				'options' => $g2rd_options,
				'notices' => $g2rd_notices,
			]
		)
	);
};

$g2rd_noter = static function ( string $passage ) use ( &$g2rd_journal, $g2rd_recopier ): void {
	$g2rd_journal[] = $passage;
	$g2rd_recopier();
};

// Erreurs non fatales relevées, puis laissées à PHP (false) : error_get_last() les rend
// encore à l'arrêt, comme sur un site sans gestionnaire d'erreurs.
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$g2rd_notices, $g2rd_alerte ): bool {
		if ( $g2rd_alerte !== $errstr ) {
			$g2rd_notices[] = $errno . ' ' . $errstr;
		}
		return false;
	}
);

/**
 * Erreur fatale qu'aucun try ne rattrape : un fichier qui déclare la fonction `$nom`, chargé
 * deux fois, comme une extension dont un fichier est inclus une seconde fois pendant sa mise
 * à jour.
 */
$g2rd_fatale = static function ( string $suffixe, string $nom ) use ( $g2rd_fichier ): void {
	$fichier = $g2rd_fichier . '.' . $suffixe . '.php';
	file_put_contents( $fichier, '<?php function ' . $nom . "() {}\n" );
	require $fichier;
	require $fichier;
};

Monkey\setUp();
Functions\stubTranslationFunctions();
Functions\when( 'wp_json_encode' )->alias(
	static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
);
// Pas de cache d'options : le créneau d'une commande d'extension relit la transaction
// protégée sans lui (UpdateTransaction::in_progress()).
Functions\when( 'wp_cache_delete' )->justReturn( true );
Functions\when( 'wp_cache_get' )->justReturn( false );
Functions\when( 'wp_cache_set' )->justReturn( true );
Functions\when( 'get_option' )->alias(
	static function ( string $key, $fallback = false ) use ( &$g2rd_options ) {
		return array_key_exists( $key, $g2rd_options ) ? $g2rd_options[ $key ] : $fallback;
	}
);
Functions\when( 'update_option' )->alias(
	static function ( string $key, $value ) use ( &$g2rd_options, $g2rd_recopier ): bool {
		$g2rd_options[ $key ] = $value;
		$g2rd_recopier();
		return true;
	}
);

$GLOBALS['wpdb'] = new G2rdTableRecopiee( $g2rd_table, $g2rd_recopier );

// Résultat de reprise consigné AVANT le démarrage, pour la même extension (une mise à jour
// morte la veille) : il ne concerne pas cette exécution.
PendingOutcomes::add(
	[
		'plugin_file'      => $g2rd_extension,
		'restore_point_id' => 'rp-akismet-5-1',
		'step'             => 'health',
		'outcome'          => 'interrupted_unverified',
	],
	$g2rd_debut - 86400
);

// État laissé par la route d'acceptation : créneau pris, exécution acceptée.
ExecutionSlot::acquire( $g2rd_id, 'update_plugin', $g2rd_debut );
RunStore::create(
	[
		'command_id'  => $g2rd_id,
		'command'     => 'update_plugin',
		'body_sha256' => str_repeat( 'ab', 32 ),
		'state'       => RunState::ACCEPTED,
		'accepted_at' => $g2rd_debut,
		'started_at'  => null,
		'alive_at'    => null,
		'finished_at' => null,
	]
);

$g2rd_runner = new AfterResponseRunner(
	terminator: static fn (): bool => true,
	executor: static function () use ( $g2rd_scenario, $g2rd_extension, $g2rd_debut, $g2rd_alerte, $g2rd_noter, $g2rd_fatale, &$g2rd_maintenant ): array {
		// Au début de ProtectedUpdate::run(), la reprise d'une mise à jour morte, quelle que
		// soit son extension : deux résultats consignés depuis le démarrage, l'un pour une
		// autre extension, l'autre pour celle-ci, avant le résultat de la reprise à l'arrêt.
		PendingOutcomes::add(
			[
				'plugin_file'      => 'hello-dolly/hello.php',
				'restore_point_id' => 'rp-hello-1-7',
				'step'             => 'install',
				'outcome'          => 'recovered_rolled_back',
			],
			$g2rd_debut
		);
		PendingOutcomes::add(
			[
				'plugin_file'      => $g2rd_extension,
				'restore_point_id' => 'rp-akismet-5-2',
				'step'             => 'health',
				'outcome'          => 'interrupted_unverified',
			],
			$g2rd_debut
		);

		// Filets armés pendant le travail, dans leur ordre réel : la reprise de la
		// transaction (début de ProtectedUpdate::run()), puis la réactivation
		// (CommandExecutor::perform_plugin_upgrade()).
		register_shutdown_function(
			static function () use ( $g2rd_scenario, $g2rd_extension, $g2rd_noter, $g2rd_fatale, &$g2rd_maintenant ): void {
				$g2rd_noter( 'reprise' );
				if ( 'fin-normale' === $g2rd_scenario ) {
					// La mise à jour a fermé sa transaction : rien à reprendre.
					return;
				}
				PendingOutcomes::add(
					[
						'plugin_file'      => $g2rd_extension,
						'restore_point_id' => 'rp-akismet-5-3',
						'step'             => 'install',
						'outcome'          => 'recovered_rolled_back',
					],
					$g2rd_maintenant
				);
				if ( 'fatale-dans-la-reprise' === $g2rd_scenario ) {
					$g2rd_fatale( 'reprise', 'g2rd_reprise_chargee_deux_fois' );
				}
			}
		);
		register_shutdown_function(
			static function () use ( $g2rd_noter ): void {
				$g2rd_noter( 'réactivation' );
			}
		);

		// La mise à jour a duré 30 s.
		$g2rd_maintenant = $g2rd_debut + 30;
		if ( 'sortie' === $g2rd_scenario ) {
			trigger_error( $g2rd_alerte, E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped -- avertissement voulu, texte fixe, que error_get_last() rend encore à l'arrêt.
			exit;
		}
		if ( 'fatale' === $g2rd_scenario || 'fatale-dans-la-reprise' === $g2rd_scenario ) {
			$g2rd_fatale( 'commande', 'g2rd_extension_chargee_deux_fois_' . str_repeat( 'x', 3000 ) );
		}

		return [
			'status' => 'done',
			'result' => [ 'plugin' => $g2rd_extension ],
		];
	},
	clock: static function () use ( &$g2rd_maintenant ): int {
		return $g2rd_maintenant;
	},
	output: static function (): void {
		// Réponse « reçue » : sans objet pour ce test.
	}
);
$g2rd_runner->queue( $g2rd_id, 'update_plugin', [ 'file' => $g2rd_extension ] );
$g2rd_runner->maybe_serve(
	false,
	new WP_REST_Response(
		[
			'command_id'         => $g2rd_id,
			'state'              => RunState::ACCEPTED,
			'accepted_at'        => gmdate( 'c', $g2rd_debut ),
			'poll_after_seconds' => 2,
			'served_at'          => gmdate( 'c', $g2rd_debut ),
		],
		202
	),
	new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' ),
	null
);
