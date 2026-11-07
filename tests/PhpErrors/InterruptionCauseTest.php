<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\Tests\TestCase;

/**
 * Cause d'interruption d'une commande en arrière-plan (lue par la plateforme sur
 * GET /command-runs/<id>) : chemin relatif à WordPress, message filtré par PhpErrors\Redactor.
 */
final class InterruptionCauseTest extends TestCase {

	public function test_ni_chemin_du_serveur_ni_secret_dans_la_cause(): void {
		$file  = '/home/u123456789/domains/exemple.fr/public_html/wp-content/plugins/exemple/envoi.php';
		$cause = ( new \ReflectionMethod( AfterResponseRunner::class, 'interruption_cause' ) )->invoke(
			null,
			[
				'type'    => E_ERROR,
				'message' => 'Uncaught Error: envoi refusé pour contact@exemple.fr dans ' . $file . ':12',
				'file'    => $file,
				'line'    => 12,
			]
		);

		self::assertSame( AfterResponseRunner::REASON_FATAL_ERROR, $cause[0] );
		self::assertStringNotContainsString( '/home/', $cause[1] );
		self::assertStringNotContainsString( 'u123456789', $cause[1] );
		self::assertStringNotContainsString( 'contact@exemple.fr', $cause[1] );
		self::assertStringContainsString( '(wp-content/plugins/exemple/envoi.php, ligne 12)', $cause[1] );
	}

	public function test_sans_erreur_fatale_la_cause_ne_change_pas(): void {
		$cause = ( new \ReflectionMethod( AfterResponseRunner::class, 'interruption_cause' ) )->invoke( null, null );

		self::assertSame( AfterResponseRunner::REASON_SCRIPT_EXIT, $cause[0] );
	}

	/**
	 * Mémoire épuisée : message fixe de PHP, que des nombres, gardé tel quel (aucun filtre à
	 * charger dans le dernier filet) ; le fichier est toujours rendu relatif.
	 */
	public function test_memoire_epuisee_message_fixe_garde_et_fichier_relatif(): void {
		$file  = '/home/u123456789/domains/exemple.fr/public_html/wp-includes/class-wpdb.php';
		$cause = ( new \ReflectionMethod( AfterResponseRunner::class, 'interruption_cause' ) )->invoke(
			null,
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
				'file'    => $file,
				'line'    => 2351,
			]
		);

		self::assertSame( AfterResponseRunner::REASON_FATAL_ERROR, $cause[0] );
		self::assertStringContainsString( 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) (wp-includes/class-wpdb.php, ligne 2351)', $cause[1] );
		self::assertStringNotContainsString( '/home/', $cause[1] );
	}

	/** Un message qui ressemble au message fixe mais porte autre chose passe par le filtre. */
	public function test_un_faux_message_de_memoire_est_filtre(): void {
		$cause = ( new \ReflectionMethod( AfterResponseRunner::class, 'interruption_cause' ) )->invoke(
			null,
			[
				'type'    => E_ERROR,
				'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) pour contact@exemple.fr',
				'file'    => '/srv/x.php',
				'line'    => 1,
			]
		);

		self::assertStringNotContainsString( 'contact@exemple.fr', $cause[1] );
	}
}
