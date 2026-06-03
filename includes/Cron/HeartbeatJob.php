<?php
/**
 * Job WP-Cron : heartbeat horaire vers le manager.
 *
 * Le hook 'g2rd_connector_heartbeat' est planifié à l'activation du plugin
 * et déclenché toutes les heures par WP-Cron.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Cron;

use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;

final class HeartbeatJob {

	public const HOOK = 'g2rd_connector_heartbeat';

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		if ( ! Settings::get( 'heartbeat_enabled' ) || ! Settings::is_enrolled() ) {
			return;
		}
		( new ManagerClient() )->heartbeat();
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
