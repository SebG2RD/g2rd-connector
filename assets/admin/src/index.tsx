/**
 * G2RD Connector — Admin React app
 *
 * Deux modes de montage :
 *  1. Tab dans Options G2RD (thème g2rd-theme ≥ v1.19) → s'enregistre via
 *     window.G2RDOptions.registerTab() exposé par le patch thème.
 *  2. Page standalone (menu top-level) → monte sur #g2rd-connector-root.
 */

import { createRoot, StrictMode } from '@wordpress/element';
import { ConnectorPanel } from './ConnectorPanel';
import './style.css';

declare global {
	interface Window {
		G2RDConnectorData?: ConnectorBootData;
		G2RDOptions?: {
			registerTab: ( tab: ExternalTab ) => void;
		};
	}
}

export interface ConnectorBootData {
	managerUrl: string;
	enrolled: boolean;
	siteId: number | null;
	enrolledAt: string | null;
	lastHeartbeatAt: string | null;
	heartbeatEnabled: boolean;
	eventsEnabled: boolean;
	remoteCommandsEnabled: boolean;
	/**
	 * Forcer l'IPv4 pour les appels sortants vers le manager (et eux seuls). À
	 * activer quand l'hébergeur refuse l'IPv6 sortante du serveur : battements de
	 * cœur, événements et enrôlement passent alors par l'IPv4.
	 */
	forceIpv4ToManager: boolean;
	/**
	 * Politique de signature des commandes du manager : true = toute requête non
	 * signée est refusée (`required`), false = vérifiée et remontée, mais acceptée
	 * (`report`). Issue de secours locale si le manager cessait de signer.
	 */
	signatureRequired: boolean;
	signatureFailures: {
		failed_count: number;
		last_failed_at: number | null;
		last_code: string | null;
	};
	/** Points de restauration détenus par ce site (rollback des extensions). */
	restorePoints: {
		count: number;
		total_bytes: number;
		dir_writable: boolean;
	};
	restUrl: string;
	nonce: string;
	connectorVersion: string;
	/**
	 * Dernière copie des MAJ annoncées par des updaters tiers, prise depuis un
	 * écran d'administration. Ces MAJ ne sont visibles du manager que grâce à
	 * elle (cf. PremiumUpdatesBridge côté PHP). null = jamais faite.
	 */
	lastUpdatesCapture: {
		capturedAt: string;
		plugins: number;
		themes: number;
	} | null;
}

interface ExternalTab {
	key: string;
	label: string;
	description?: string;
	icon?: string;
	mount: ( container: HTMLElement, data: ConnectorBootData ) => void;
}

const STANDALONE_ROOT_ID = 'g2rd-connector-root';

function mountStandalone(): void {
	const node = document.getElementById( STANDALONE_ROOT_ID );
	const data = window.G2RDConnectorData;
	if ( ! node || ! data ) {
		return;
	}
	createRoot( node ).render(
		<StrictMode>
			<ConnectorPanel data={ data } />
		</StrictMode>
	);
}

function registerAsThemeTab(): void {
	if ( ! window.G2RDOptions?.registerTab ) {
		return;
	}
	window.G2RDOptions.registerTab( {
		key: 'connector',
		label: 'Manager G2RD',
		description: 'Connexion au tableau de bord centralisé.',
		icon: 'cloud',
		mount: ( container, data ) => {
			createRoot( container ).render(
				<StrictMode>
					<ConnectorPanel data={ data } />
				</StrictMode>
			);
		},
	} );
}

window.addEventListener( 'DOMContentLoaded', () => {
	mountStandalone();
	registerAsThemeTab();
} );
