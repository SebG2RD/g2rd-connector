<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Le journal des versions (readme.txt) dit à qui profite l'économie d'OPcache : la mémoire
 * est celle de l'instance PHP/OPcache, partagée avec les autres sites du même serveur.
 */
final class ReadmeOpcacheTest extends PHPUnitTestCase {

	public function test_l_entree_unreleased_nomme_l_opcache_partage(): void {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );

		self::assertSame( 1, preg_match( '/^= Unreleased =\s*(.*?)^= /ms', $readme, $m ), 'Entrée « Unreleased » absente.' );

		$entry = (string) preg_replace( '/\s+/', ' ', $m[1] );
		self::assertStringContainsString( 'shared with the other sites served by the same PHP/OPcache instance', $entry );
	}

	/** L'option, ce qu'elle contient et sa fin de vie, comme les entrées précédentes le font pour leurs options. */
	public function test_l_entree_unreleased_decrit_l_option_et_restrict_api(): void {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		preg_match( '/^= Unreleased =\s*(.*?)^= /ms', $readme, $m );
		$entry = (string) preg_replace( '/\s+/', ' ', $m[1] ?? '' );

		foreach (
			[
				'`g2rd_connector_opcache_files`',
				'not autoloaded',
				'one short fingerprint per server',
				'no machine name is stored',
				'removed when the plugin is deleted',
				'`opcache.restrict_api`',
				'PHP warning',
			] as $expected
		) {
			self::assertStringContainsString( $expected, $entry );
		}
		self::assertStringNotContainsString( ', and a recompilation', $entry, 'Une phrase par fait.' );
	}
}
