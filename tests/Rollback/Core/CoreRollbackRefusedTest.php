<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback\Core;

use G2RD\Connector\Rollback\Core\CoreRollbackRefused;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Codes stables du retour arrière du cœur (spécification du 19/11/2026, § 4.2, plus
 * `core_rollback_failed`) : le manager traduit le code ; la phrase anglaise (quoi, cause probable,
 * action) ne sert qu'au journal.
 */
final class CoreRollbackRefusedTest extends TestCase {

	/**
	 * @return iterable<string, array{string, bool}> Code, et « rien n'a été modifié ».
	 */
	public static function codes(): iterable {
		yield 'core_rollback_expired' => [ 'core_rollback_expired', true ];
		yield 'core_rollback_version_changed' => [ 'core_rollback_version_changed', true ];
		yield 'core_rollback_db_changed' => [ 'core_rollback_db_changed', true ];
		yield 'core_rollback_busy' => [ 'core_rollback_busy', true ];
		yield 'core_rollback_wporg_unreachable' => [ 'core_rollback_wporg_unreachable', true ];
		yield 'core_rollback_integrity' => [ 'core_rollback_integrity', false ];
		yield 'core_rollback_failed' => [ 'core_rollback_failed', false ];
	}

	#[DataProvider( 'codes' )]
	public function test_chaque_code_a_sa_phrase( string $code, bool $nothing_changed ): void {
		$refused = CoreRollbackRefused::because( $code );

		self::assertSame( $code, $refused->error_code() );
		$message = $refused->getMessage();
		self::assertStringStartsWith( 'WordPress ', $message );
		self::assertStringContainsString( ': ', $message, 'quoi, puis la cause' );
		self::assertSame( $nothing_changed, str_contains( $message, 'Nothing was changed.' ) );
		self::assertLessThan( 400, strlen( $message ) );
	}

	public function test_les_sept_codes_sont_des_constantes(): void {
		self::assertSame(
			[ 'core_rollback_expired', 'core_rollback_version_changed', 'core_rollback_db_changed', 'core_rollback_busy', 'core_rollback_wporg_unreachable', 'core_rollback_integrity', 'core_rollback_failed' ],
			[ CoreRollbackRefused::EXPIRED, CoreRollbackRefused::VERSION_CHANGED, CoreRollbackRefused::DB_CHANGED, CoreRollbackRefused::BUSY, CoreRollbackRefused::WPORG_UNREACHABLE, CoreRollbackRefused::INTEGRITY, CoreRollbackRefused::FAILED ]
		);
	}

	public function test_le_detail_suit_la_phrase_entre_parentheses(): void {
		$refused = CoreRollbackRefused::because( CoreRollbackRefused::INTEGRITY, '2 file(s) differ, first: wp-settings.php' );

		self::assertStringEndsWith( ' (2 file(s) differ, first: wp-settings.php)', $refused->getMessage() );
	}

	public function test_un_code_inconnu_est_une_erreur_de_programmation(): void {
		$this->expectException( \InvalidArgumentException::class );

		CoreRollbackRefused::because( 'core_rollback_inconnu' );
	}
}
