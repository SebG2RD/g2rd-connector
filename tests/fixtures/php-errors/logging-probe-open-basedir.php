<?php
/**
 * Processus PHP à part de LoggingProbeTest : la sonde de journalisation sous open_basedir,
 * comme chez un hébergeur qui borne PHP au dossier du site. Le réglage ne fait que se resserrer
 * dans un processus : il ne peut pas être posé puis levé dans celui de PHPUnit.
 *
 * Lancé avec `php -n -d display_errors=1 -d error_reporting=-1` : sans php.ini, aucun
 * open_basedir ni aucune extension venus du poste ou de la CI ; tout avertissement s'écrit sur
 * la sortie. La classe est chargée seule (elle ne dépend ni de WordPress ni du chargeur), puis
 * open_basedir est posé. Trois arguments : la zone permise, la racine de WordPress, le chemin
 * du journal (error_log). Écrit une seule ligne JSON {open_basedir, error_log,
 * readable_log_path} : open_basedir tel que PHP l'applique, statut rendu par describe(), et
 * chemin rendu par readable_log_path().
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

use G2RD\Connector\PhpErrors\LoggingProbe;

require dirname( __DIR__, 3 ) . '/includes/PhpErrors/LoggingProbe.php';

$zone = (string) ( $argv[1] ?? '' );
$root = (string) ( $argv[2] ?? '' );
$log  = (string) ( $argv[3] ?? '' );

ini_set( 'open_basedir', $zone );

$probe = new LoggingProbe(
	static fn ( string $option ): string|false => 'error_log' === $option ? $log : false,
	static fn (): mixed => null,
	$root,
	static fn (): bool => false
);

echo json_encode(
	[
		'open_basedir'      => ini_get( 'open_basedir' ),
		'error_log'         => $probe->describe()['error_log'] ?? null,
		'readable_log_path' => $probe->readable_log_path(),
	],
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
