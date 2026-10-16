<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * États d'une exécution en arrière-plan côté site (§ 3.5 de la spécification) : la
 * plateforme lit ces valeurs telles quelles dans la réponse de lecture.
 */
final class RunStateTest extends TestCase {

	public function test_les_etats_ont_les_valeurs_du_contrat(): void {
		self::assertSame( 'accepted', RunState::ACCEPTED );
		self::assertSame( 'running', RunState::RUNNING );
		self::assertSame( 'done', RunState::DONE );
		self::assertSame( 'failed', RunState::FAILED );
		self::assertSame( 'interrupted', RunState::INTERRUPTED );
		self::assertSame( 'abandoned', RunState::ABANDONED );
	}

	/**
	 * État, terminal ?, accepte un résultat tardif ?
	 *
	 * @return iterable<string, array{string, bool, bool}>
	 */
	public static function states(): iterable {
		yield 'acceptée' => [ RunState::ACCEPTED, false, false ];
		yield 'en cours' => [ RunState::RUNNING, false, true ];
		yield 'terminée' => [ RunState::DONE, true, false ];
		yield 'échouée' => [ RunState::FAILED, true, false ];
		yield 'interrompue' => [ RunState::INTERRUPTED, true, true ];
		yield 'abandonnée' => [ RunState::ABANDONED, true, false ];
		yield 'inconnu' => [ 'sending', false, false ];
		yield 'vide' => [ '', false, false ];
		yield 'casse différente' => [ 'Done', false, false ];
	}

	/**
	 * `interrupted` est terminal (le ramassage l'a conclu) même s'il accepte encore un
	 * résultat tardif ; `accepted` et `running` sont ouverts.
	 */
	#[DataProvider( 'states' )]
	public function test_etats_terminaux( string $state, bool $terminal ): void {
		self::assertSame( $terminal, RunState::is_terminal( $state ) );
	}

	/**
	 * Un résultat s'écrit sur une exécution en cours, ou sur une exécution déclarée
	 * interrompue qui était en réalité vivante (§ 3.5, « Résultat tardif ») ; jamais sur
	 * une exécution jamais démarrée ni déjà conclue par son propre résultat.
	 */
	#[DataProvider( 'states' )]
	public function test_seuls_running_et_interrupted_acceptent_un_resultat_tardif( string $state, bool $terminal, bool $accepts_late_result ): void {
		self::assertSame( $accepts_late_result, RunState::accepts_late_result( $state ) );
	}
}
