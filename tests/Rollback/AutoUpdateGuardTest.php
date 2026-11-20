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

	private function core_offer( string $version ): object {
		return (object) [
			'response' => 'autoupdate',
			'current'  => $version,
			'version'  => $version,
		];
	}

	public function test_the_blocked_core_version_is_refused_until_a_newer_one_is_offered(): void {
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, '7.1.5' );

		self::assertFalse( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.1.5' ) ) );
		self::assertNull( ( new AutoUpdateGuard() )->filter_core( null, $this->core_offer( '7.1.4' ) ), 'une version plus ancienne reste à WordPress' );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all() );

		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.1.6' ) ) );
		self::assertSame( [], AutoUpdateGuard::all(), 'le blocage est levé' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.1.5' ) ) );
	}

	/**
	 * find_core_auto_update() passe au filtre CHAQUE offre `autoupdate`, une par branche, la plus
	 * récente d'abord (7.2.0 puis 7.1.5), même une majeure que WordPress refuse (`$update` faux) :
	 * elle ne doit pas lever le blocage, sinon 7.1.5 serait réinstallée dans le même passage.
	 */
	public function test_a_newer_core_offer_of_another_branch_keeps_the_block(): void {
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, '7.1.5' );

		self::assertFalse( ( new AutoUpdateGuard() )->filter_core( false, $this->core_offer( '7.2.0' ) ) );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all() );
		self::assertFalse( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.1.5' ) ) );

		AutoUpdateGuard::block( AutoUpdateGuard::CORE, '7.2' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.2.1' ) ), 'une majeure bloquée tombe sur la mineure suivante de sa branche' );
		self::assertSame( [], AutoUpdateGuard::all() );
	}

	public function test_core_and_plugins_are_blocked_apart(): void {
		AutoUpdateGuard::block( 'akismet/akismet.php', '7.1.5' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, $this->core_offer( '7.1.5' ) ), 'le blocage d\'une extension ne touche pas le cœur' );

		AutoUpdateGuard::unblock( 'akismet/akismet.php' );
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, '7.1.5' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter( true, $this->item( 'akismet/akismet.php', '7.1.5' ) ), 'ni celui du cœur une extension' );
		self::assertSame( [ AutoUpdateGuard::CORE => '7.1.5' ], AutoUpdateGuard::all() );
	}

	public function test_a_malformed_core_offer_is_passed_through(): void {
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, '7.1.5' );
		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, 'pas un objet' ) );
		self::assertTrue( ( new AutoUpdateGuard() )->filter_core( true, (object) [ 'version' => '7.1.5' ] ) );
	}

	public function test_both_filters_are_registered(): void {
		$guard = new AutoUpdateGuard();
		$guard->register();

		self::assertNotFalse( has_filter( 'auto_update_plugin', [ $guard, 'filter' ] ) );
		self::assertNotFalse( has_filter( 'auto_update_core', [ $guard, 'filter_core' ] ) );
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
