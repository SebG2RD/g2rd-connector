<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

/**
 * Ce PHP appelle-t-il encore les fonctions d'arrêt après un épuisement de la mémoire ? Réponse
 * de la sonde tests/fixtures/command-runs/oom-shutdown-probe.php, lancée une seule fois par
 * processus PHPUnit, dans un processus à part (même binaire et même php.ini que
 * fatal-during-run.php), sans rien du connecteur.
 *
 * Non sous PHP 8.1.34 du dépôt ondrej, celui de la CI (cf. l'en-tête de la sonde) : PHP y
 * arrête le processus sans appeler aucune fonction d'arrêt. Aucun filet ne peut alors passer,
 * ni la reprise, ni la réactivation, ni le dernier filet, ni le relevé des erreurs PHP : plus
 * rien ne s'exécute après l'erreur. L'exécution reste en cours, et le ramassage la déclare
 * interrompue 600 s après son dernier signe de vie. Les tests vérifient alors exactement ce
 * repli, au lieu du dernier filet.
 */
final class OomShutdownProbe {

	/**
	 * Seule sortie admise du script à part quand ce PHP s'arrête ainsi : son propre message
	 * d'erreur fatale (écrit malgré display_errors=0), et rien d'autre.
	 */
	public const PHP_OWN_MESSAGE = '/^\nFatal error: Allowed memory size of \d+ bytes exhausted \(tried to allocate \d+ bytes\) in .*fatal-during-run\.php on line \d+$/D';

	/** Réponse de la sonde, gardée pour le processus PHPUnit ; null : pas encore lancée. */
	private static ?bool $calls_shutdown_functions = null;

	/**
	 * Vrai si ce PHP appelle encore les fonctions d'arrêt après un épuisement de la mémoire ;
	 * faux si la sonde n'a rien pu écrire (aucune fonction d'arrêt appelée). Vrai sans exec() :
	 * aucun processus à part n'est lancé, et le test est alors ignoré par son arret_reel().
	 */
	public static function php_calls_shutdown_functions(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return true;
		}
		if ( null === self::$calls_shutdown_functions ) {
			$sonde   = dirname( __DIR__ ) . '/fixtures/command-runs/oom-shutdown-probe.php';
			$fichier = (string) tempnam( sys_get_temp_dir(), 'g2rd-sonde-' );
			$sortie  = [];
			$code    = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 ' . escapeshellarg( $sonde ) . ' ' . escapeshellarg( $fichier ) . ' 2>&1', $sortie, $code );
			self::$calls_shutdown_functions = 'appelée' === (string) file_get_contents( $fichier );
			if ( is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}

		return self::$calls_shutdown_functions;
	}
}
