/**
 * Panneau principal du plugin G2RD Connector — état + actions.
 *
 * Affiché soit standalone (menu top-level), soit comme tab dans Options G2RD.
 *
 * Les actions save / enroll / unenroll appellent les endpoints REST d'admin
 * locale (namespace g2rd/v1/admin), gardés par capability + nonce. La réponse
 * de chaque endpoint est le payload boot frais : on rafraîchit l'état local
 * sans recharger la page.
 */

import { useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	ToggleControl,
	Notice,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import type { ConnectorBootData } from './index';

interface Props {
	data: ConnectorBootData;
}

type Busy = null | 'save' | 'enroll' | 'unenroll';
type ActionNotice = { status: 'success' | 'error'; message: string } | null;

export function ConnectorPanel( { data }: Props ): JSX.Element {
	const [ state, setState ] = useState( data );
	const [ token, setToken ] = useState( '' );
	const [ busy, setBusy ] = useState< Busy >( null );
	const [ notice, setNotice ] = useState< ActionNotice >( null );
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState< string | null >( null );

	const settingsPayload = () => ( {
		manager_url: state.managerUrl,
		heartbeat_enabled: state.heartbeatEnabled,
		events_enabled: state.eventsEnabled,
		remote_commands_enabled: state.remoteCommandsEnabled,
		force_ipv4_to_manager: state.forceIpv4ToManager,
		signature_required: state.signatureRequired,
	} );

	const save = async (): Promise< void > => {
		setBusy( 'save' );
		setNotice( null );
		try {
			const fresh = await apiFetch< ConnectorBootData >( {
				path: 'g2rd/v1/admin/save',
				method: 'POST',
				data: settingsPayload(),
			} );
			setState( fresh );
			setNotice( {
				status: 'success',
				message: 'Réglages enregistrés.',
			} );
		} catch ( e ) {
			setNotice( { status: 'error', message: ( e as Error ).message } );
		} finally {
			setBusy( null );
		}
	};

	const enroll = async (): Promise< void > => {
		setBusy( 'enroll' );
		setNotice( null );
		try {
			const fresh = await apiFetch< ConnectorBootData >( {
				path: 'g2rd/v1/admin/enroll',
				method: 'POST',
				data: { ...settingsPayload(), invitation_token: token.trim() },
			} );
			setState( fresh );
			setToken( '' );
			setNotice( {
				status: 'success',
				message: `Site enrôlé avec succès (site #${ fresh.siteId }).`,
			} );
		} catch ( e ) {
			setNotice( { status: 'error', message: ( e as Error ).message } );
		} finally {
			setBusy( null );
		}
	};

	const unenroll = async (): Promise< void > => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Déconnecter ce site du manager ?' ) ) {
			return;
		}
		setBusy( 'unenroll' );
		setNotice( null );
		try {
			const fresh = await apiFetch< ConnectorBootData >( {
				path: 'g2rd/v1/admin/unenroll',
				method: 'POST',
			} );
			setState( fresh );
			setNotice( {
				status: 'success',
				message: 'Site déconnecté du manager.',
			} );
		} catch ( e ) {
			setNotice( { status: 'error', message: ( e as Error ).message } );
		} finally {
			setBusy( null );
		}
	};

	const testHealth = async (): Promise< void > => {
		setTesting( true );
		setTestResult( null );
		try {
			const resp = await apiFetch< {
				ok: boolean;
				connector_version: string;
			} >( {
				path: 'g2rd/v1/health',
			} );
			setTestResult( `OK — connector v${ resp.connector_version }` );
		} catch ( e ) {
			setTestResult( `Échec : ${ ( e as Error ).message }` );
		} finally {
			setTesting( false );
		}
	};

	const anyBusy = busy !== null;

	return (
		<div className="g2rd-connector-panel">
			<Card>
				<CardHeader>
					<h2>G2RD Connector</h2>
					<span className="g2rd-connector-version">
						v{ state.connectorVersion }
					</span>
				</CardHeader>
				<CardBody>
					{ state.enrolled ? (
						<Notice status="success" isDismissible={ false }>
							Site enrôlé — connecté à{ ' ' }
							<code>{ state.managerUrl }</code> en tant que site #
							{ state.siteId }.
							{ state.lastHeartbeatAt && (
								<>
									{ ' ' }
									Dernier heartbeat :{ ' ' }
									{ new Date(
										state.lastHeartbeatAt
									).toLocaleString() }
									.
								</>
							) }
							<br />
							{ state.lastUpdatesCapture ? (
								<>
									Dernière capture des MAJ tierces :{ ' ' }
									{ new Date(
										state.lastUpdatesCapture.capturedAt
									).toLocaleString() }
									{ ' — ' }
									{ state.lastUpdatesCapture.plugins }{ ' ' }
									extension(s),{ ' ' }
									{ state.lastUpdatesCapture.themes }{ ' ' }
									thème(s).
								</>
							) : (
								<>
									Dernière capture des MAJ tierces : jamais.
									Ouvrez Extensions ou Tableau de bord → Mises
									à jour pour l&apos;amorcer.
								</>
							) }
						</Notice>
					) : (
						<Notice status="warning" isDismissible={ false }>
							Site non enrôlé. Renseignez l&apos;URL du manager et
							le token d&apos;invitation, puis cliquez « Enrôler
							le site ».
						</Notice>
					) }

					{ notice && (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.message }
						</Notice>
					) }

					<TextControl
						label="URL du manager"
						value={ state.managerUrl }
						onChange={ ( v ) =>
							setState( { ...state, managerUrl: v } )
						}
						help="Ex : https://wp-manager.g2rd.fr"
						disabled={ anyBusy }
					/>

					{ ! state.enrolled && (
						<TextControl
							label="Token d'invitation"
							value={ token }
							onChange={ setToken }
							help="Généré dans le manager → fiche du site → « Inviter ce site à se connecter » (valide 15 min)."
							autoComplete="off"
							disabled={ anyBusy }
						/>
					) }

					<ToggleControl
						label="Heartbeat horaire"
						help="Le plugin envoie ses métriques au manager toutes les heures."
						checked={ state.heartbeatEnabled }
						onChange={ ( v ) =>
							setState( { ...state, heartbeatEnabled: v } )
						}
						disabled={ anyBusy }
					/>

					<ToggleControl
						label="Webhook events"
						help="Notifier le manager en temps réel (logins, plugins, mises à jour)."
						checked={ state.eventsEnabled }
						onChange={ ( v ) =>
							setState( { ...state, eventsEnabled: v } )
						}
						disabled={ anyBusy }
					/>

					<ToggleControl
						label="Commandes distantes"
						help="Autoriser le manager à déclencher clear_cache / update_core à distance."
						checked={ state.remoteCommandsEnabled }
						onChange={ ( v ) =>
							setState( {
								...state,
								remoteCommandsEnabled: v,
							} )
						}
						disabled={ anyBusy }
					/>
					<ToggleControl
						label="Forcer l'IPv4 vers le manager"
						help="À activer si l'hébergeur bloque l'IPv6 sortante du serveur : les battements de cœur, les événements et l'enrôlement passent alors par l'IPv4. Ne concerne que les appels vers le manager."
						checked={ state.forceIpv4ToManager }
						onChange={ ( v ) =>
							setState( {
								...state,
								forceIpv4ToManager: v,
							} )
						}
						disabled={ anyBusy }
					/>

					<ToggleControl
						label="Exiger des commandes signées"
						help={
							state.signatureRequired
								? 'Toute requête du manager non signée ou mal signée est refusée. À désactiver si le manager cesse de signer et que le site devient injoignable.'
								: `La signature est vérifiée et remontée au manager, mais une requête non signée reste acceptée. ${
										state.signatureFailures.failed_count > 0
											? `${ state.signatureFailures.failed_count } échec(s) de vérification constaté(s) : n'activez pas avant d'en avoir trouvé la cause.`
											: 'Aucun échec de vérification constaté.'
								  }`
						}
						checked={ state.signatureRequired }
						onChange={ ( v ) =>
							setState( { ...state, signatureRequired: v } )
						}
						disabled={ anyBusy }
					/>

					<p className="description">
						Points de restauration des extensions :{ ' ' }
						{ state.restorePoints.count } ({ ' ' }
						{ ( state.restorePoints.total_bytes / 1048576 ).toFixed(
							1
						) }{ ' ' }
						Mo){ ' ' }
						{ state.restorePoints.dir_writable
							? ''
							: '— dossier non accessible en écriture : le rollback est indisponible sur ce site.' }
					</p>

					<div className="g2rd-connector-actions">
						{ ! state.enrolled && (
							<Button
								variant="primary"
								onClick={ enroll }
								isBusy={ busy === 'enroll' }
								disabled={ anyBusy || '' === token.trim() }
							>
								Enrôler le site
							</Button>
						) }

						<Button
							variant="secondary"
							onClick={ save }
							isBusy={ busy === 'save' }
							disabled={ anyBusy }
						>
							Enregistrer
						</Button>

						{ state.enrolled && (
							<Button
								variant="secondary"
								isDestructive
								onClick={ unenroll }
								isBusy={ busy === 'unenroll' }
								disabled={ anyBusy }
							>
								Déconnecter du manager
							</Button>
						) }

						<Button
							variant="tertiary"
							onClick={ testHealth }
							isBusy={ testing }
							disabled={ anyBusy }
						>
							Tester la connectivité
						</Button>
						{ testResult && (
							<span className="g2rd-connector-test-result">
								{ testResult }
							</span>
						) }
					</div>
				</CardBody>
			</Card>
		</div>
	);
}
