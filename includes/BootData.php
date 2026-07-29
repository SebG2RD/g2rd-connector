<?php
/**
 * Construction du payload « boot » injecté à l'app React d'administration.
 *
 * Source de vérité unique, partagée entre :
 *   - Admin\Page  : injection initiale via wp_localize_script / tab thème ;
 *   - Rest\AdminController : réponse renvoyée après save/enroll/unenroll pour
 *     que React rafraîchisse son état sans recharger la page.
 *
 * Centraliser ici évite deux constructions du même tableau qui pourraient
 * diverger (clés camelCase attendues côté TypeScript : cf. ConnectorBootData).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector;

use G2RD\Connector\Updates\PremiumUpdatesBridge;

final class BootData {

	/**
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$s = Settings::all();

		// Diagnostic : dernière copie des MAJ annoncées par des updaters tiers,
		// prise depuis un écran d'administration. Sans elle, ces MAJ sont
		// invisibles du manager (cf. PremiumUpdatesBridge). null = jamais faite.
		$capture = PremiumUpdatesBridge::last_capture();

		return [
			'managerUrl'            => (string) $s['manager_url'],
			'enrolled'              => Settings::is_enrolled(),
			'siteId'                => $s['site_id'],
			'enrolledAt'            => $s['enrolled_at'],
			'lastHeartbeatAt'       => $s['last_heartbeat_at'],
			'heartbeatEnabled'      => (bool) $s['heartbeat_enabled'],
			'eventsEnabled'         => (bool) $s['events_enabled'],
			'remoteCommandsEnabled' => (bool) $s['remote_commands_enabled'],
			'restUrl'               => rest_url( G2RD_CONNECTOR_REST_NS . '/' ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'connectorVersion'      => G2RD_CONNECTOR_VERSION,
			'lastUpdatesCapture'    => null === $capture ? null : [
				'capturedAt' => $capture['captured_at'],
				'plugins'    => $capture['plugins'],
				'themes'     => $capture['themes'],
			],
		];
	}
}
