<?php
/**
 * Processus PHP à part de AfterResponseRunnerTest : vidage des tampons de sortie
 * (AfterResponseRunner::flush_output_buffers(), niveau par défaut) au-dessus d'un tampon
 * qu'on ne peut pas retirer, ouvert sans PHP_OUTPUT_HANDLER_REMOVABLE. PHP ne ferme un
 * tel tampon qu'à la fin du script : PHPUnit ne saurait pas le refermer après un test.
 *
 * Lancé sans php.ini (`php -n`), sans WordPress ni chargeur : seul le fichier de la
 * classe est inclus, ce que fait le vidage n'appelle rien d'autre.
 *
 * Écrit, à la fin du script, quand PHP vide le tampon non supprimable : le contenu des
 * tampons (« ABC »), puis une ligne JSON {avant, atteint, notices_du_vidage, temoin_ferme,
 * temoin_notices}. Le témoin est un ob_end_flush() sans contrôle sur ce tampon : PHP le
 * refuse et lève une notice, ce qui prouve que le relevé des notices fonctionne.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

require dirname( __DIR__, 3 ) . '/includes/CommandRuns/AfterResponseRunner.php';

$g2rd_notices = [];
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$g2rd_notices ): bool {
		$g2rd_notices[] = $errno . ' ' . $errstr;
		return true;
	}
);

ob_start( null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE );
echo 'A';
ob_start();
echo 'B';
ob_start();
echo 'C';

$g2rd_avant   = ob_get_level();
$g2rd_atteint = \G2RD\Connector\CommandRuns\AfterResponseRunner::flush_output_buffers();
$g2rd_vidage  = $g2rd_notices;

// Témoin, sans le contrôle de PHP_OUTPUT_HANDLER_REMOVABLE.
$g2rd_ferme = ob_end_flush();

echo "\n" . json_encode(
	[
		'avant'             => $g2rd_avant,
		'atteint'           => $g2rd_atteint,
		'notices_du_vidage' => $g2rd_vidage,
		'temoin_ferme'      => $g2rd_ferme,
		'temoin_notices'    => array_slice( $g2rd_notices, count( $g2rd_vidage ) ),
	]
);
