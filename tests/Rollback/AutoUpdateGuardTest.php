<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Tests\TestCase;

final class AutoUpdateGuardTest extends TestCase {

	private function item( string $plugin, string $version ): object {
		return (object) [
			'plugin'      => $plugin,
			'new_version' => $version,
		];
	}

	public function test_blocked_version_is_refused(): void {
		AutoUpdateGuard::block( 'akismet/akismet.php', '5.7.2' );
		self::assertFalse( ( new AutoUpdateGuard() )->filter( true, $this->item( 'akismet/akismet.php', '5.7.2' ) ) );
	}

	public function test_other_plugins_are_untouched(): void {
		AutoUpdateGuard::block( 'akismet/akismet.php', '5.7.2' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, $this->item( 'hello.php', '5.7.2' ) ) );
		self::assertNull( ( new AutoUpdateGuard() )->filter( null, $this->item( 'hello.php', '1.0' ) ) );
	}

	public function test_a_newer_version_lifts_the_block(): void {
		AutoUpdateGuard::block( 'akismet/akismet.php', '5.7.2' );

		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, $this->item( 'akismet/akismet.php', '5.7.3' ) ) );
		self::assertSame( [], AutoUpdateGuard::all(), 'le blocage est levé' );
		// Et la version bloquée redevient acceptable si elle est reproposée plus tard : le blocage n'existe plus.
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, $this->item( 'akismet/akismet.php', '5.7.2' ) ) );
	}

	public function test_an_older_version_is_left_to_wordpress(): void {
		AutoUpdateGuard::block( 'akismet/akismet.php', '5.7.2' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, $this->item( 'akismet/akismet.php', '5.7.1' ) ) );
	}

	public function test_malformed_item_is_passed_through(): void {
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, 'pas un objet' ) );
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, (object) [ 'plugin' => 'x' ] ) );
	}

	public function test_option_is_written_without_autoload_and_tolerates_corruption(): void {
		$autoload = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $flag = null ) use ( &$autoload ): bool {
				$autoload              = $flag;
				$this->options[ $key ] = $value;
				return true;
			}
		);
		AutoUpdateGuard::block( 'akismet/akismet.php', '5.7.2' );
		self::assertFalse( $autoload );

		$this->options[ AutoUpdateGuard::OPTION_KEY ] = 'corrompu';
		self::assertSame( [], AutoUpdateGuard::all() );
	}
}
