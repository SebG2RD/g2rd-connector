<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Refus de `get_release_notes` : codes stables (contrat release-notes-command-v1.json), phrase
 * « quoi, cause probable, action », issue de la forme de CommandExecutor::run(), jamais de
 * `retry_after`.
 */
final class ReleaseNotesRefusedTest extends TestCase {

	public function test_les_codes_de_la_commande_dans_l_ordre_du_contrat(): void {
		self::assertSame(
			[
				'release_notes_invalid_request',
				'release_notes_not_installed',
				'release_notes_not_provided',
				'release_notes_source_unavailable',
			],
			ReleaseNotesRefused::CODES
		);
		self::assertSame( 'release_notes_queue_unsupported', ReleaseNotesRefused::QUEUE_UNSUPPORTED );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function codes(): iterable {
		foreach ( [ 'release_notes_invalid_request', 'release_notes_not_installed', 'release_notes_not_provided', 'release_notes_source_unavailable', 'release_notes_queue_unsupported' ] as $code ) {
			yield $code => [ $code ];
		}
	}

	#[DataProvider( 'codes' )]
	public function test_chaque_refus_rend_son_code_et_une_phrase_quoi_cause_action( string $code ): void {
		$refus = new ReleaseNotesRefused( $code );
		$issue = $refus->outcome();

		self::assertSame( [ 'status', 'error', 'error_code' ], array_keys( $issue ) );
		self::assertSame( 'failed', $issue['status'] );
		self::assertSame( $code, $issue['error_code'] );
		self::assertSame( $code, $refus->reason_code() );
		self::assertStringStartsWith( 'Les notes de version n’ont pas été lues : ', $issue['error'] );
		self::assertStringContainsString( 'Rien n’a été modifié.', $issue['error'] );
		self::assertStringContainsString( ' Action : ', $issue['error'] );
	}

	public function test_les_phrases_sont_toutes_differentes(): void {
		$phrases = [];
		foreach ( self::codes() as [ $code ] ) {
			$phrases[] = ( new ReleaseNotesRefused( $code ) )->getMessage();
		}

		self::assertCount( 5, array_unique( $phrases ) );
	}

	public function test_un_code_inconnu_est_une_erreur_de_programmation(): void {
		$this->expectException( \InvalidArgumentException::class );

		new ReleaseNotesRefused( 'backup_disabled' );
	}
}
