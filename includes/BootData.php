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

use G2RD\Connector\Rollback\RestorePointInventory;
use G2RD\Connector\Rollback\UnverifiedDownloads;
use G2RD\Connector\Security\SignatureState;
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
			'forceIpv4ToManager'    => (bool) ( $s['force_ipv4_to_manager'] ?? false ),
			'allowDirectLogin'      => (bool) ( $s['allow_direct_login'] ?? true ),
			'signatureRequired'     => 'required' === ( $s['signature_policy'] ?? 'report' ),
			'signatureFailures'     => SignatureState::stats(),
			'restorePoints'         => RestorePointInventory::describe(),
			// Restaurations wordpress.org installées sans contrôle d'empreinte (K3) :
			// compteur + dernier cas, pour le diagnostic (pas encore affiché).
			'unverifiedDownloads'   => UnverifiedDownloads::stats(),
			// État du jeton de connexion (K4) : `none`, `ok`, `legacy` (écriture v2 activée
			// et ancien format non authentifié, accepté : normal juste après la mise à jour,
			// suspect s'il dure),
			// `refused` (ancien format refusé par le mode strict) ou `unreadable` (altéré
			// en base ou sels changés). Pas encore affiché par l'app React.
			'tokenState'            => Settings::token_state(),
			// Mode strict (constante G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN) actif.
			'tokenStrict'           => Settings::strict_token_storage(),
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
