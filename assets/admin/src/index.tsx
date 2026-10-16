/**
 * G2RD Connector — Admin React app
 *
 * Deux modes de montage :
 *  1. Tab dans Options G2RD (thème parent g2rd-theme ≥ v1.19) → s'enregistre via
 *     window.G2RDOptions.registerTab() exposé par le patch thème. La console lit
 *     libellé, description et icône dans le filtre PHP (Page::register_as_theme_tab) ;
 *     ceux passés ici n'en sont que le double.
 *  2. Page standalone (menu top-level, ou page de secours hors menu du mode
 *     onglet) → monte sur #g2rd-connector-root si la page porte ce conteneur.
 *     Aujourd'hui elle n'en porte pas : ces pages gardent le formulaire PHP
 *     (Page::render_standalone_page), et ce script n'y apporte que la feuille de
 *     style (correctif des cases cochées).
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
	 * Connexion directe depuis G2RD WP Manager (1.13) : le manager peut ouvrir
	 * l'administration de ce site sans mot de passe, par un lien signé à usage
	 * unique valable une minute. Cochée par défaut.
	 */
	allowDirectLogin: boolean;
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
	/** Rend la fonction de nettoyage, appelée par la console quand on quitte l'onglet. */
	mount: ( container: HTMLElement, data: ConnectorBootData ) => () => void;
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

/**
 * Racine React de chaque conteneur d'onglet, et démontage en attente.
 *
 * La console du thème appelle le nettoyage depuis un effet React, pendant son
 * propre rendu : un root.unmount() immédiat y est refusé par React (« Attempted
 * to synchronously unmount a root while React was already rendering »). Le
 * démontage est donc remis à la tâche suivante ; si la console remonte l'onglet
 * dans le même conteneur avant (StrictMode), la racine est reprise au lieu d'en
 * créer une seconde sur ce conteneur.
 */
const tabRoots = new WeakMap<
	HTMLElement,
	{ root: ReturnType< typeof createRoot >; unmountTimer?: number }
>();

/** Numéro de montage : clé du panneau, pour qu'un remontage reparte d'un état neuf. */
let tabMounts = 0;

function mountThemeTab(
	container: HTMLElement,
	data: ConnectorBootData
): () => void {
	let entry = tabRoots.get( container );
	if ( entry ) {
		window.clearTimeout( entry.unmountTimer );
	} else {
		entry = { root: createRoot( container ) };
		tabRoots.set( container, entry );
	}
	const mounted = entry;
	tabMounts++;
	mounted.root.render(
		<StrictMode>
			<ConnectorPanel key={ tabMounts } data={ data } />
		</StrictMode>
	);
	return () => {
		window.clearTimeout( mounted.unmountTimer );
		mounted.unmountTimer = window.setTimeout( () => {
			tabRoots.delete( container );
			mounted.root.unmount();
		} );
	};
}

function registerAsThemeTab(): void {
	if ( ! window.G2RDOptions?.registerTab ) {
		return;
	}
	window.G2RDOptions.registerTab( {
		key: 'connector',
		label: 'Manager G2RD',
		description: 'Connexion au tableau de bord centralisé G2RD WP Manager.',
		icon: 'cloud',
		mount: mountThemeTab,
	} );
}

window.addEventListener( 'DOMContentLoaded', () => {
	mountStandalone();
	registerAsThemeTab();
} );
