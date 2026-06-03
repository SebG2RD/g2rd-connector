<?php
/**
 * Exécution locale des commandes G2RD côté WordPress.
 *
 * Utilisé par deux entry points :
 *   - Rest\CommandController : POST direct /wp-json/g2rd/v1/command (manager pousse)
 *   - Cron\HeartbeatJob : drain de la queue manager via GET /api/agent/sites/{id}/commands
 *
 * Sépare la logique d'exécution du transport REST pour pouvoir la tester et
 * la réutiliser. Liste des commandes : cf SiteCommandKind côté manager
 * (clear_cache, check_updates, update_core).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Commands;

use Core_Upgrader;

final class CommandExecutor {

	public const ALLOWED = [ 'clear_cache', 'check_updates', 'update_core' ];

	/**
	 * Exécute une commande. Retourne :
	 *   - [ 'status' => 'done', 'result' => array<string, mixed> ]
	 *   - [ 'status' => 'failed', 'error' => string ]
	 *   - null si la commande est inconnue.
	 *
	 * @return array{status:string,result?:array<string,mixed>,error?:string}|null
	 */
	public static function run( string $command ): ?array {
		if ( ! in_array( $command, self::ALLOWED, true ) ) {
			return null;
		}

		try {
			$result = match ( $command ) {
				'clear_cache'   => self::clear_cache(),
				'check_updates' => self::check_updates(),
				'update_core'   => self::update_core(),
			};
			return [
				'status' => 'done',
				'result' => $result,
			];
		} catch ( \Throwable $e ) {
			return [
				'status' => 'failed',
				'error'  => $e->getMessage(),
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function clear_cache(): array {
		wp_cache_flush();
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		return [ 'cleared' => [ 'object_cache', 'update_transients' ] ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function check_updates(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
		return [ 'refreshed' => true ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function update_core(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$updates = get_core_updates();
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return [
				'updated' => false,
				'reason'  => 'no_updates_available',
			];
		}

		$upgrader = new Core_Upgrader();
		$result   = $upgrader->upgrade( $updates[0] );

		return [
			'updated' => ! is_wp_error( $result ),
			'detail'  => is_wp_error( $result ) ? $result->get_error_message() : (string) $result,
		];
	}
}
