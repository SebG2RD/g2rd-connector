<?php
/**
 * Sonde de OomShutdownProbe (tests ShutdownOrderTest et FatalCatcherShutdownTest) : ce PHP
 * appelle-t-il encore les fonctions d'arrêt après un épuisement de la mémoire ? Rien du
 * connecteur ici : une fonction d'arrêt qui écrit « appelée » dans le fichier donné, puis la
 * même mémoire épuisée que le scénario « memoire » de fatal-during-run.php (limite abaissée à
 * 8 Mo de plus que l'usage du moment, allocations de 512 Ko jusqu'à l'erreur fatale).
 *
 * Lancé par le même binaire que PHPUnit, avec son php.ini, comme fatal-during-run.php.
 *
 * Oui pour PHP tel que publié par php.net (8.1 à 8.5) et pour les paquets ondrej de 8.2 à 8.5.
 * Non pour le paquet ondrej de PHP 8.1.34 (8.1.34-9 du 21/10/2026, celui que setup-php installe
 * sur la CI) : le correctif des CVE-2026-109618 et CVE-2026-109619, rétroporté de PHP 8.2.35
 * (« Avoid state corruption via bailout »), y arrête le processus dès la mémoire épuisée
 * (code 1), écrit le message de l'erreur sur stderr malgré display_errors=0, et n'appelle
 * aucune fonction d'arrêt. Le test de PHP lui-même, Zend/tests/oom_after_main_script.phpt, y
 * échoue de la même façon ; PHP 8.2.35 le passe.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

$g2rd_fichier = (string) ( $argv[1] ?? '' );
if ( '' === $g2rd_fichier ) {
	echo 'Usage : php oom-shutdown-probe.php <fichier>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aide en ligne de commande, texte fixe.
	exit( 2 );
}

register_shutdown_function(
	static function () use ( $g2rd_fichier ): void {
		file_put_contents( $g2rd_fichier, 'appelée' );
	}
);

ini_set( 'display_errors', '0' );
ini_set( 'log_errors', '0' );
ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 8 * 1024 * 1024 ) );
$g2rd_tas = [];
while ( true ) {
	$g2rd_tas[] = str_repeat( 'x', 512 * 1024 );
}
