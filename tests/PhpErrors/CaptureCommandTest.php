<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use Brain\Monkey\Functions;
use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\PhpErrors\CaptureCommand;
use G2RD\Connector\PhpErrors\LoggingProbe;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Commande signée `set_php_errors_capture` : active ou coupe le relevé, la lecture du journal
 * seulement avec lui, toute valeur non booléenne vaut « coupé » ; signature toujours exigée,
 * exécutée sur `/command` (instantanée).
 */
final class CaptureCommandTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7 ];
	}

	public function test_active_le_releve(): void {
		$result = $this->command()->run( [ 'capture' => true ] );

		self::assertSame(
			[
				'php_errors_enabled'  => true,
				'php_errors_log_tail' => false,
				'log_file'            => LoggingProbe::LOG_UNSET,
			],
			$result
		);
		self::assertTrue( Settings::get( 'php_errors_enabled' ) );
	}

	public function test_active_le_releve_et_la_lecture_du_journal(): void {
		$result = $this->command()->run(
			[
				'capture'  => true,
				'log_tail' => true,
			]
		);

		self::assertTrue( $result['php_errors_enabled'] );
		self::assertTrue( $result['php_errors_log_tail'] );
	}

	public function test_la_lecture_du_journal_seule_est_refusee(): void {
		$result = $this->command()->run(
			[
				'capture'  => false,
				'log_tail' => true,
			]
		);

		self::assertFalse( $result['php_errors_enabled'] );
		self::assertFalse( $result['php_errors_log_tail'] );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function charges_non_booleennes(): iterable {
		yield 'chaîne 1' => [ [ 'capture' => '1' ] ];
		yield 'entier 1' => [ [ 'capture' => 1 ] ];
		yield 'chaîne true' => [ [ 'capture' => 'true' ] ];
		yield 'null' => [ [ 'capture' => null ] ];
		yield 'absente' => [ [] ];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	#[DataProvider( 'charges_non_booleennes' )]
	public function test_une_valeur_non_booleenne_coupe( array $payload ): void {
		$this->command()->run( [ 'capture' => true ] );

		self::assertFalse( $this->command()->run( $payload )['php_errors_enabled'] );
	}

	public function test_coupe_le_releve(): void {
		$this->command()->run(
			[
				'capture'  => true,
				'log_tail' => true,
			]
		);

		$result = $this->command()->run( [ 'capture' => false ] );

		self::assertFalse( $result['php_errors_enabled'] );
		self::assertFalse( $result['php_errors_log_tail'] );
	}

	public function test_passe_par_l_executeur_des_commandes(): void {
		$outcome = CommandExecutor::run( 'set_php_errors_capture', [ 'capture' => true ] );

		self::assertSame( 'done', $outcome['status'] ?? null );
		self::assertTrue( $outcome['result']['php_errors_enabled'] ?? null );
		self::assertIsString( $outcome['result']['log_file'] ?? null );
	}

	public function test_signee_et_instantanee(): void {
		self::assertSame( 'set_php_errors_capture', CaptureCommand::NAME );
		self::assertContains( CaptureCommand::NAME, CommandExecutor::ALLOWED );
		self::assertContains( CaptureCommand::NAME, CommandExecutor::SIGNED_ONLY );
		self::assertContains( CaptureCommand::NAME, AsyncCommands::EXCLUDED );
	}

	private function command(): CaptureCommand {
		return new CaptureCommand( new LoggingProbe( static fn (): string => '', static fn (): mixed => null, '' ) );
	}
}
