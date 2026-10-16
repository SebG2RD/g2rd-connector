<?php
/**
 * Processus PHP à part de AfterResponseRunnerTest : fin de réponse PAR DÉFAUT
 * (AfterResponseRunner::finish_request()), en ligne de commande, où ni
 * litespeed_finish_request() ni fastcgi_finish_request() n'existent. Elle ferme tous les
 * tampons de sortie (niveau 0) : PHPUnit ne saurait pas retrouver les siens après un test.
 *
 * Lancé sans php.ini (`php -n`). Charge l'amorce des tests (chargeur, doublures des
 * classes WordPress), Brain Monkey pour les quelques fonctions WordPress utilisées, et la
 * fausse table d'options : l'enregistrement de l'exécution et le créneau sont les vrais.
 * Seuls l'exécuteur, qui note son passage, et l'horloge sont injectés ; la fin de réponse
 * et l'écriture du JSON sont celles par défaut.
 *
 * Écrit : le JSON de la réponse (par l'écriture par défaut, dans un tampon ouvert ici,
 * vidé par la fin de réponse), puis une ligne JSON {notices, servie, niveau_avant,
 * niveau_pendant_la_commande, executions, etat, creneau, journal}. `journal` : les lignes
 * écrites au journal PHP (error_log(), redirigé vers un fichier temporaire).
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
use G2RD\Connector\Tests\CommandRuns\FakeOptionsTable;

require dirname( __DIR__, 2 ) . '/bootstrap.php';

$g2rd_notices = [];
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$g2rd_notices ): bool {
		$g2rd_notices[] = $errno . ' ' . $errstr;
		return true;
	}
);

$g2rd_journal = (string) tempnam( sys_get_temp_dir(), 'g2rd-journal-' );
ini_set( 'error_log', $g2rd_journal );

Monkey\setUp();
Functions\stubTranslationFunctions();
Functions\when( 'wp_json_encode' )->alias(
	static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
);
Functions\when( 'wp_cache_delete' )->justReturn( true );

$GLOBALS['wpdb'] = new FakeOptionsTable();

$g2rd_id  = '0123456789abcdef0123456789abcdef';
$g2rd_now = 1789000000;

// État laissé par la route d'acceptation : créneau pris, exécution acceptée.
ExecutionSlot::acquire( $g2rd_id, 'optimize_database', $g2rd_now );
RunStore::create(
	[
		'command_id'  => $g2rd_id,
		'command'     => 'optimize_database',
		'body_sha256' => str_repeat( 'ab', 32 ),
		'state'       => RunState::ACCEPTED,
		'accepted_at' => $g2rd_now,
		'started_at'  => null,
		'alive_at'    => null,
		'finished_at' => null,
	]
);

$g2rd_executions = 0;
$g2rd_pendant    = null;
$g2rd_runner     = new AfterResponseRunner(
	executor: static function () use ( &$g2rd_executions, &$g2rd_pendant ): array {
		++$g2rd_executions;
		$g2rd_pendant = ob_get_level();
		return [
			'status' => 'done',
			'result' => [],
		];
	},
	clock: static fn (): int => $g2rd_now
);
$g2rd_runner->queue( $g2rd_id, 'optimize_database', null );

ob_start();
$g2rd_avant  = ob_get_level();
$g2rd_servie = $g2rd_runner->maybe_serve(
	false,
	new WP_REST_Response(
		[
			'command_id'         => $g2rd_id,
			'state'              => RunState::ACCEPTED,
			'accepted_at'        => gmdate( 'c', $g2rd_now ),
			'poll_after_seconds' => 2,
			'served_at'          => gmdate( 'c', $g2rd_now ),
		],
		202
	),
	new WP_REST_Request( 'POST', '/g2rd/v1/command-runs' ),
	null
);

$g2rd_etat    = RunStore::read( $g2rd_id )['state'] ?? null;
$g2rd_creneau = ExecutionSlot::holder();
// Fins de ligne de la plateforme (PHP_EOL) : « \r\n » sous Windows.
$g2rd_lignes = array_values( array_filter( (array) preg_split( '/\R/', (string) file_get_contents( $g2rd_journal ) ) ) );
unlink( $g2rd_journal );
Monkey\tearDown();

echo "\n" . json_encode(
	[
		'notices'                    => $g2rd_notices,
		'servie'                     => $g2rd_servie,
		'niveau_avant'               => $g2rd_avant,
		'niveau_pendant_la_commande' => $g2rd_pendant,
		'executions'                 => $g2rd_executions,
		'etat'                       => $g2rd_etat,
		'creneau'                    => $g2rd_creneau,
		'journal'                    => $g2rd_lignes,
	]
);
