<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\CommandRuns\AfterResponseRunner;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\PhpErrors\Buffer;
use G2RD\Connector\Tests\TestCase;

/**
 * Relevé des erreurs PHP pendant un vrai arrêt de PHP (processus à part,
 * tests/fixtures/command-runs/fatal-during-run.php, troisième argument « releve ») : les filets
 * existants passent comme avant, le dernier filet garde l'erreur fatale pour cause, et l'erreur
 * est enregistrée en tout dernier, filtrée.
 */
final class FatalCatcherShutdownTest extends TestCase {

	private const ID = '0123456789abcdef0123456789abcdef';

	private const DEMARRAGE = [ 'créneau pris', 'exécution accepted', 'exécution running' ];

	public function test_l_erreur_fatale_est_enregistree_apres_tous_les_filets(): void {
		$arret = $this->arret_reel( 'fatale' );

		self::assertSame( 255, $arret['code'], $arret['sortie'] );
		self::assertSame(
			array_merge( self::DEMARRAGE, [ 'reprise', 'réactivation', 'exécution interrupted', 'créneau rendu', 'erreurs PHP enregistrées' ] ),
			$arret['journal']
		);
		self::assertSame( [], $arret['notices'], 'Aucune notice, relevé compris.' );
	}

	public function test_le_releve_ne_change_pas_la_cause_de_l_interruption(): void {
		$arret = $this->arret_reel( 'fatale' );

		self::assertSame( AfterResponseRunner::REASON_FATAL_ERROR, $arret['execution']['interruption']['reason'] ?? null );
		$erreur = (string) ( $arret['execution']['interruption']['error'] ?? '' );
		self::assertStringStartsWith( 'La commande s’est arrêtée sur une erreur fatale de PHP', $erreur );
		self::assertStringContainsString( 'Cannot redeclare', $erreur );
	}

	public function test_le_groupe_enregistre_est_filtre(): void {
		$arret = $this->arret_reel( 'fatale' );

		$groups = (array) ( $arret['options'][ Buffer::OPTION ]['groups'] ?? [] );
		self::assertCount( 1, $groups );
		$group = (array) array_values( $groups )[0];
		self::assertSame( 'fatal', $group['level'] );
		self::assertSame( 'unknown', $group['type'], 'Fichier temporaire hors de WordPress.' );
		self::assertStringStartsWith( '[path]/', (string) $group['file'] );
		self::assertStringStartsWith( 'Cannot redeclare', (string) $group['message'] );
		self::assertLessThanOrEqual( 500, mb_strlen( (string) $group['message'] ) );
		self::assertSame( 1, $group['count'] );
		self::assertSame( 'fatal_catcher', $group['source'] );
		foreach ( [ sys_get_temp_dir(), str_replace( '\\', '/', sys_get_temp_dir() ) ] as $dossier ) {
			self::assertStringNotContainsString( $dossier, (string) $group['message'] );
			self::assertStringNotContainsString( $dossier, (string) $group['file'] );
		}
	}

	public function test_fin_normale_rien_n_est_enregistre(): void {
		$arret = $this->arret_reel( 'fin-normale' );

		self::assertArrayNotHasKey( Buffer::OPTION, $arret['options'] );
		self::assertNotContains( 'erreurs PHP enregistrées', $arret['journal'] );
	}

	public function test_arret_par_exit_rien_n_est_enregistre(): void {
		$arret = $this->arret_reel( 'sortie' );

		self::assertArrayNotHasKey( Buffer::OPTION, $arret['options'], 'Un simple avertissement n\'est pas relevé.' );
	}

	/**
	 * Mémoire épuisée, relevé armé : le dernier filet passe encore (le relevé lui laisse la
	 * place). L'enregistrement du groupe, repoussé après lui, peut manquer de mémoire : il
	 * n'est pas exigé ici, et le compte rendu de la tâche dit ce qui a été observé.
	 */
	public function test_memoire_epuisee_le_dernier_filet_passe_avec_le_releve(): void {
		$arret = $this->arret_reel( 'memoire' );

		self::assertSame( 255, $arret['code'], $arret['sortie'] );
		self::assertContains( 'exécution interrupted', $arret['journal'] );
		self::assertSame( AfterResponseRunner::REASON_FATAL_ERROR, $arret['execution']['interruption']['reason'] ?? null );
	}

	/**
	 * Lance le script à part avec le relevé armé (même mise en œuvre que ShutdownOrderTest::arret_reel()).
	 *
	 * @return array{code: int, sortie: string, journal: list<string>, options: array<string, mixed>, notices: list<string>, execution: array<string, mixed>}
	 */
	private function arret_reel( string $scenario ): array {
		if ( ! function_exists( 'exec' ) ) {
			self::markTestSkipped( 'exec() est désactivée : impossible de lancer un processus PHP à part.' );
		}
		$script = dirname( __DIR__ ) . '/fixtures/command-runs/fatal-during-run.php';
		$etat   = (string) tempnam( sys_get_temp_dir(), 'g2rd-arret-' );
		$sortie = [];
		$code   = 0;

		exec( escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $etat ) . ' ' . escapeshellarg( $scenario ) . ' releve 2>&1', $sortie, $code );

		$releve = json_decode( (string) file_get_contents( $etat ), true );
		foreach ( array_merge( [ $etat ], (array) glob( $etat . '.*.php' ) ) as $fichier ) {
			if ( is_string( $fichier ) && is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}
		$texte = implode( "\n", $sortie );
		self::assertIsArray( $releve, 'Relevé du processus à part illisible. Sortie : ' . $texte );
		self::assertSame( '', $texte, 'Rien d\'écrit sur la sortie du processus à part.' );
		$ligne = $releve['lignes'][ RunStore::option_name( self::ID ) ]['value'] ?? null;

		return [
			'code'      => $code,
			'sortie'    => $texte,
			'journal'   => $releve['journal'] ?? [],
			'options'   => $releve['options'] ?? [],
			'notices'   => $releve['notices'] ?? [],
			'execution' => is_string( $ligne ) ? (array) json_decode( $ligne, true ) : [],
		];
	}
}
