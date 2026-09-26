<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Outbound;

use Brain\Monkey\Functions;
use G2RD\Connector\Outbound\ManagerIpv4Guard;
use G2RD\Connector\Tests\TestCase;

/**
 * La garde IPv4 ne doit viser QUE le manager : c'est ce qui la rend inoffensive
 * pour tout le reste du site (wordpress.org, webhooks tiers, loopbacks).
 */
final class ManagerIpv4GuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
	}

	public function test_it_applies_to_the_manager_host_whatever_the_path_scheme_or_case(): void {
		$manager = 'https://wp-manager.g2rd.fr';

		self::assertTrue( ManagerIpv4Guard::applies_to( 'https://wp-manager.g2rd.fr/api/sites/enroll', $manager ) );
		self::assertTrue( ManagerIpv4Guard::applies_to( 'https://WP-MANAGER.g2rd.fr/healthz', $manager ) );
		self::assertTrue( ManagerIpv4Guard::applies_to( 'http://wp-manager.g2rd.fr:8080/api/agent/heartbeat', $manager ) );
	}

	public function test_it_leaves_every_other_host_alone(): void {
		$manager = 'https://wp-manager.g2rd.fr';

		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://api.wordpress.org/core/version-check/1.7/', $manager ) );
		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://g2rd.fr/wp-admin/admin-ajax.php', $manager ), 'le loopback du site n\'est pas le manager' );
		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://wp-manager.g2rd.fr.evil.example/api', $manager ), 'un hôte qui commence pareil n\'est pas le même hôte' );
		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://evil.example/?u=wp-manager.g2rd.fr', $manager ) );
	}

	/** Sans manager configuré, la garde ne peut pas s'appliquer « à tout ». */
	public function test_an_empty_manager_never_matches(): void {
		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://wp-manager.g2rd.fr/api', '' ) );
		self::assertFalse( ManagerIpv4Guard::applies_to( 'https://anything.example/', 'pas-une-url' ) );
	}

	/** Un handle qui n'est pas un CurlHandle (WP en fsockopen, tests) est ignoré sans erreur. */
	public function test_apply_ignores_a_non_curl_handle(): void {
		$this->options['g2rd_connector_settings'] = [ 'manager_url' => 'https://wp-manager.g2rd.fr' ];

		( new ManagerIpv4Guard() )->apply( 'pas-un-handle', [], 'https://wp-manager.g2rd.fr/api' );
		( new ManagerIpv4Guard() )->apply( null, [], 'https://wp-manager.g2rd.fr/api' );

		self::assertTrue( true, 'aucune exception, aucun avertissement' );
	}
}
