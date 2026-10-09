<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Cron;

use Brain\Monkey\Functions;
use G2RD\Connector\Cron\UpdatesDiscoveryJob;
use G2RD\Connector\Updates\PremiumUpdatesBridge;
use G2RD\Connector\Tests\TestCase;

/**
 * Réveil du WP-Cron par le snapshot : seulement quand la découverte planifiée
 * (biquotidienne) est en retard.
 *
 * Avant : seuil de 6 h. La plateforme synchronise environ toutes les 7 h et la
 * découverte tourne toutes les 12 h : presque chaque synchronisation relançait
 * `spawn_cron()`, donc un second démarrage de WordPress et une recherche de mises
 * à jour complète, en double du job planifié.
 */
final class UpdatesDiscoveryWakeTest extends TestCase {

	/** Capture simulée (option `g2rd_updates_snapshot`), `false` si absente. */
	private mixed $capture = false;

	protected function setUp(): void {
		parent::setUp();
		$this->capture = false;
		Functions\when( 'get_site_option' )->alias( fn () => $this->capture );
	}

	public function test_une_capture_de_sept_heures_ne_relance_pas_le_cron(): void {
		$this->capture_since( 7 * 3600 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'spawn_cron' )->never();

		self::assertFalse( UpdatesDiscoveryJob::request_if_stale() );
	}

	/** Découverte biquotidienne passée depuis peu, ou sur le point de passer : on la laisse faire. */
	public function test_une_capture_de_douze_heures_et_demie_ne_relance_pas_le_cron(): void {
		$this->capture_since( 12 * 3600 + 1800 );
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'spawn_cron' )->never();

		self::assertFalse( UpdatesDiscoveryJob::request_if_stale() );
	}

	public function test_une_capture_de_quatorze_heures_relance_le_cron(): void {
		$this->capture_since( 14 * 3600 );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), UpdatesDiscoveryJob::HOOK );
		Functions\expect( 'spawn_cron' )->once();

		self::assertTrue( UpdatesDiscoveryJob::request_if_stale() );
	}

	/** Comportement inchangé : sans capture, le snapshot réveille la découverte. */
	public function test_sans_capture_le_cron_est_relance(): void {
		Functions\expect( 'wp_schedule_single_event' )->once()->with( \Mockery::type( 'int' ), UpdatesDiscoveryJob::HOOK );
		Functions\expect( 'spawn_cron' )->once();

		self::assertTrue( UpdatesDiscoveryJob::request_if_stale() );
	}

	/** Le seuil reste au-delà du rythme du job `twicedaily` (12 h), sinon le snapshot le doublerait. */
	public function test_le_seuil_depasse_le_rythme_biquotidien_de_la_decouverte(): void {
		self::assertSame( 13 * 3600, PremiumUpdatesBridge::STALE_AFTER_SECONDS );
		self::assertGreaterThan( 12 * 3600, PremiumUpdatesBridge::STALE_AFTER_SECONDS );
	}

	private function capture_since( int $seconds ): void {
		$this->capture = [
			'plugins'     => [],
			'themes'      => [],
			'captured_at' => gmdate( 'c', time() - $seconds ),
		];
	}
}
