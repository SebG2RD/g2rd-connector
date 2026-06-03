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

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Settings;

final class HeartbeatJob {

	public const HOOK = 'g2rd_connector_heartbeat';

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		if ( ! Settings::is_enrolled() ) {
			return;
		}

		$client = new ManagerClient();

		if ( Settings::get( 'heartbeat_enabled' ) ) {
			$client->heartbeat();
		}

		// Drain de la queue de commandes distantes (opt-in via remote_commands_enabled).
		// Le manager fait le claim atomique côté serveur (PENDING → RUNNING) ; ici on
		// se contente d'exécuter et de notifier le résultat.
		if ( Settings::get( 'remote_commands_enabled' ) ) {
			$commands = $client->poll_commands();
			if ( ! is_wp_error( $commands ) && is_array( $commands ) ) {
				foreach ( $commands as $cmd ) {
					if ( ! isset( $cmd['id'], $cmd['kind'] ) ) {
						continue;
					}
					$outcome = CommandExecutor::run( (string) $cmd['kind'] );
					if ( null === $outcome ) {
						$client->send_command_result(
							(int) $cmd['id'],
							'failed',
							null,
							sprintf( 'Commande inconnue : %s', (string) $cmd['kind'] )
						);
						continue;
					}
					$client->send_command_result(
						(int) $cmd['id'],
						(string) $outcome['status'],
						$outcome['result'] ?? null,
						$outcome['error'] ?? null
					);
				}
			}
		}
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
