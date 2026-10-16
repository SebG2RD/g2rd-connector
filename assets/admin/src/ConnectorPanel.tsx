/**
 * Panneau du connecteur G2RD : connexion au manager, échanges avec le manager,
 * sécurité des commandes, diagnostic.
 *
 * Monté comme onglet « Manager G2RD » de la console d'options du thème G2RD
 * (index.tsx). Deux dessins, choisis au montage (ui.ts) : natif dans la console
 * (classes du contrat du thème, à partir de la 1.41) ou autonome (classes
 * « g2rd-connector-… » de style.css, consoles plus anciennes). Mêmes éléments et
 * même structure dans les deux cas.
 *
 * Les actions save / enroll / unenroll appellent les endpoints REST d'admin
 * locale (namespace g2rd/v1/admin), gardés par capability + nonce. La réponse
 * de chaque endpoint est le payload boot frais : on rafraîchit l'état local
 * sans recharger la page.
 */

import { useState } from '@wordpress/element';
import { Button, FormToggle } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import type { ConnectorBootData } from './index';
import { Icon } from './icons';
import type { IconName } from './icons';
import { classesFor, detectUiMode } from './ui';

interface Props {
	data: ConnectorBootData;
	/**
	 * Reçoit les données fraîches renvoyées par le serveur après chaque action
	 * réussie (enregistrement, enrôlement, déconnexion), même si le panneau a été
	 * démonté entre-temps : l'onglet de la console du thème les garde pour son
	 * prochain montage (cf. index.tsx).
	 */
	onDataChange?: ( fresh: ConnectorBootData ) => void;
}

type Cx = ReturnType< typeof classesFor >;
type Busy = null | 'save' | 'enroll' | 'unenroll' | 'test';
type ActionNotice = {
	status: 'success' | 'error';
	message: string;
} | null;

/**
 * Réglages envoyés au serveur à l'enregistrement et à l'enrôlement.
 *
 * @param d État du panneau.
 */
function settingsPayload( d: ConnectorBootData ) {
	return {
		manager_url: d.managerUrl,
		heartbeat_enabled: d.heartbeatEnabled,
		events_enabled: d.eventsEnabled,
		remote_commands_enabled: d.remoteCommandsEnabled,
		force_ipv4_to_manager: d.forceIpv4ToManager,
		allow_direct_login: d.allowDirectLogin,
		signature_required: d.signatureRequired,
	};
}

/**
 * Jeton stocké mais inutilisable (BootData::tokenState) : le site reste
 * « enrôlé », mais ne parle plus au manager. Mêmes explications que l'avis PHP
 * (Page::token_problem).
 */
const TOKEN_PROBLEMS: Record< string, string > = {
	unreadable:
		'Le jeton de connexion au manager ne peut pas être lu : il a été modifié dans la base de données, ou les clés de sécurité de wp-config.php (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY) ont changé. Le site ne communique plus avec le manager. Si les clés ont changé, restaurez celles d’origine ; sinon, cliquez sur « Déconnecter du manager », puis enrôlez le site avec une nouvelle invitation générée depuis sa fiche dans le manager.',
	refused:
		'Le jeton de connexion au manager est stocké dans un ancien format non protégé contre la modification, que ce site refuse : la constante G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN est activée dans wp-config.php. Le site ne communique plus avec le manager. Cliquez sur « Déconnecter du manager », puis enrôlez le site avec une nouvelle invitation générée depuis sa fiche dans le manager.',
};

/**
 * Dernier échec de vérification de signature (SignatureState::stats), en phrase
 * claire. Une clé par code de RequestSignature et QueueSignature :
 * tests/Admin/ConnectorPanelTest.php vérifie qu'aucun ne manque.
 */
const SIGNATURE_FAILURES: Record< string, string > = {
	signature_invalid:
		'Signature invalide : la requête n’a pas été signée avec le jeton de ce site, ou elle a été modifiée en chemin.',
	signature_replayed:
		'Requête signée reçue une seconde fois (rejeu). Cause fréquente : un CDN qui renvoie une requête restée longtemps sans réponse.',
	clock_skew:
		'Horloge décalée : la requête était datée de plus de 5 minutes d’écart avec l’heure du site. Faites vérifier l’heure du serveur par l’hébergeur.',
	nonce_store_full:
		'Trop de requêtes signées en quelques minutes : le registre anti-rejeu du site était plein. Si cela se répète, contactez le support G2RD.',
	token_unavailable:
		'Jeton de connexion illisible ou refusé sur ce site : la signature ne pouvait pas être vérifiée. Déconnectez le site du manager, puis enrôlez-le avec une nouvelle invitation.',
	signature_missing:
		'Commande reçue sans signature alors qu’elle en exige une. Cause probable : manager antérieur aux commandes signées, ou réponse altérée en chemin.',
	signature_error:
		'Erreur inattendue pendant la vérification de la signature. Si cela se répète, contactez le support G2RD.',
};

const DATE_FORMAT = new Intl.DateTimeFormat( 'fr-FR', {
	dateStyle: 'medium',
	timeStyle: 'short',
} );

/**
 * Date lisible : chaîne ISO 8601, ou nombre de secondes Unix. null si absente ;
 * la valeur brute si elle est illisible (plutôt qu'une date fausse).
 *
 * @param value Date à afficher.
 */
function formatDate( value: string | number | null ): string | null {
	if ( null === value || '' === value ) {
		return null;
	}
	const date = new Date( 'number' === typeof value ? value * 1000 : value );
	return Number.isNaN( date.getTime() )
		? String( value )
		: DATE_FORMAT.format( date );
}

/**
 * « 1 thème », « 3 thèmes », « 0 thème » (singulier jusqu'à 1, en français).
 *
 * @param count Nombre.
 * @param one   Mot au singulier.
 * @param many  Mot au pluriel.
 */
function plural( count: number, one: string, many: string ): string {
	return `${ count.toLocaleString( 'fr-FR' ) } ${ count > 1 ? many : one }`;
}

/**
 * Message d'une erreur d'apiFetch (objet { code, message } du serveur, ou
 * erreur réseau), sans jamais rendre de texte vide.
 *
 * @param error Valeur rejetée.
 */
function errorDetail( error: unknown ): string {
	const message = ( error as { message?: unknown } | null )?.message;
	return 'string' === typeof message && '' !== message.trim()
		? message.trim()
		: 'aucune réponse exploitable du serveur';
}

export function ConnectorPanel( { data, onDataChange }: Props ): JSX.Element {
	const [ mode ] = useState( detectUiMode );
	const cx = classesFor( mode );
	const [ state, setState ] = useState( data );
	// Derniers réglages connus du serveur : ce qui diffère est « non enregistré ».
	const [ saved, setSaved ] = useState( data );
	const [ token, setToken ] = useState( '' );
	const [ busy, setBusy ] = useState< Busy >( null );
	const [ notice, setNotice ] = useState< ActionNotice >( null );

	const update = ( patch: Partial< ConnectorBootData > ): void =>
		setState( ( current ) => ( { ...current, ...patch } ) );

	const dirty =
		JSON.stringify( settingsPayload( state ) ) !==
		JSON.stringify( settingsPayload( saved ) );

	/**
	 * Action qui renvoie les données fraîches du serveur : état, réglages
	 * enregistrés et copie de l'onglet sont mis à jour ensemble.
	 *
	 * @param kind    Action en cours (boutons désactivés pendant ce temps).
	 * @param request Appel REST.
	 * @param success Message de réussite, d'après les données fraîches.
	 * @param failure Message d'échec, d'après le détail de l'erreur.
	 */
	const run = async (
		kind: Exclude< Busy, null >,
		request: () => Promise< ConnectorBootData >,
		success: ( fresh: ConnectorBootData ) => string,
		failure: ( detail: string ) => string
	): Promise< void > => {
		setBusy( kind );
		setNotice( null );
		try {
			const fresh = await request();
			setState( fresh );
			setSaved( fresh );
			onDataChange?.( fresh );
			setNotice( { status: 'success', message: success( fresh ) } );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: failure( errorDetail( e ) ),
			} );
		} finally {
			setBusy( null );
		}
	};

	const save = (): Promise< void > =>
		run(
			'save',
			() =>
				apiFetch< ConnectorBootData >( {
					path: 'g2rd/v1/admin/save',
					method: 'POST',
					data: settingsPayload( state ),
				} ),
			() => 'Réglages enregistrés.',
			( detail ) =>
				`Les réglages n’ont pas été enregistrés (${ detail }). Vérifiez l’adresse du manager (adresse complète, avec https://) ; si votre session WordPress a expiré, rechargez la page, puis réessayez.`
		);

	const enroll = (): Promise< void > =>
		run(
			'enroll',
			async () => {
				const fresh = await apiFetch< ConnectorBootData >( {
					path: 'g2rd/v1/admin/enroll',
					method: 'POST',
					data: {
						...settingsPayload( state ),
						invitation_token: token.trim(),
					},
				} );
				setToken( '' );
				return fresh;
			},
			( fresh ) =>
				`Site enrôlé : il est le site n° ${ fresh.siteId } du manager.`,
			( detail ) =>
				`Le site n’a pas été enrôlé (${ detail }). Cause probable : jeton d’invitation expiré (valable 15 minutes) ou déjà utilisé, ou adresse du manager erronée. Générez une nouvelle invitation depuis la fiche du site dans le manager, vérifiez l’adresse, puis réessayez.`
		);

	const unenroll = async (): Promise< void > => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			'Déconnecter ce site du manager ? Le manager ne pourra plus le suivre ni lui envoyer de commandes, jusqu’à un nouvel enrôlement avec une nouvelle invitation.'
		);
		if ( ! confirmed ) {
			return;
		}
		await run(
			'unenroll',
			() =>
				apiFetch< ConnectorBootData >( {
					path: 'g2rd/v1/admin/unenroll',
					method: 'POST',
				} ),
			() => 'Site déconnecté du manager.',
			( detail ) =>
				`Le site n’a pas été déconnecté (${ detail }). Si votre session WordPress a expiré, rechargez la page, puis réessayez.`
		);
	};

	// Sonde le point de contrôle public du connecteur sur ce site, celui
	// qu'interroge le manager. Ne touche à aucun réglage.
	const testHealth = async (): Promise< void > => {
		setBusy( 'test' );
		setNotice( null );
		try {
			const resp = await apiFetch< { connector_version: string } >( {
				path: 'g2rd/v1/health',
			} );
			setNotice( {
				status: 'success',
				message: `Le connecteur répond sur ce site (version ${ resp.connector_version }). Ce test part de votre navigateur : il ne vérifie pas que le manager, lui, joint le site.`,
			} );
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: `Le connecteur ne répond pas sur ce site (${ errorDetail(
					e
				) }). Cause probable : API REST de WordPress désactivée ou bloquée par une extension de sécurité ou le pare-feu de l’hébergeur. Vérifiez que l’adresse ${
					state.restUrl
				}health s’ouvre dans votre navigateur.`,
			} );
		} finally {
			setBusy( null );
		}
	};

	const anyBusy = busy !== null;
	const tokenProblem = state.enrolled
		? ( TOKEN_PROBLEMS[ state.tokenState ] ?? null )
		: null;
	const lastHeartbeat = formatDate( state.lastHeartbeatAt );
	const failures = state.signatureFailures;
	const lastFailure = formatDate( failures.last_failed_at );
	const capture = state.lastUpdatesCapture;
	const points = state.restorePoints;
	const pointsSummary =
		( points.count > 0
			? `${ plural( points.count, 'point', 'points' ) } (${ (
					points.total_bytes / 1048576
				).toLocaleString( 'fr-FR', { maximumFractionDigits: 1 } ) } Mo)`
			: 'aucun' ) +
		( points.dir_writable
			? ''
			: ' — dossier non inscriptible : la restauration des extensions est indisponible sur ce site.' );

	return (
		<div
			className={ `g2rd-connector-panel g2rd-connector-panel--${ mode }` }
		>
			<section
				className={ cx( 'section' ) }
				aria-labelledby="g2rd-connector-titre-connexion"
			>
				<div className={ cx( 'section__head' ) }>
					<div>
						<h3
							className={ cx( 'section__title' ) }
							id="g2rd-connector-titre-connexion"
						>
							Connexion au manager
						</h3>
						<p className={ cx( 'section__desc' ) }>
							Lie ce site WordPress au tableau de bord centralisé
							G2RD WP Manager.
						</p>
					</div>
					{ ! state.enrolled && (
						<Status cx={ cx } tone="neutral">
							Pas encore enrôlé
						</Status>
					) }
					{ null !== tokenProblem && (
						<Status cx={ cx } tone="warn">
							Connexion interrompue
						</Status>
					) }
					{ state.enrolled && null === tokenProblem && (
						<Status cx={ cx } tone="ok">
							Connecté
						</Status>
					) }
				</div>

				<div className="g2rd-connector-stack">
					{ null !== tokenProblem && (
						<div
							className={ `notice notice-warning inline ${ cx(
								'notice-inline'
							) }` }
						>
							<p>
								<strong>Connexion interrompue.</strong>{ ' ' }
								{ tokenProblem }
							</p>
						</div>
					) }

					{ state.enrolled && (
						<dl className="g2rd-connector-facts">
							<Fact cx={ cx } label="Site">
								<span className={ cx( 'chip', 'chip--muted' ) }>
									n° { state.siteId }
								</span>
							</Fact>
							<Fact cx={ cx } label="Dernier signe de vie">
								{ lastHeartbeat ?? 'aucun pour l’instant' }
							</Fact>
						</dl>
					) }

					<div className="g2rd-connector-fields">
						<TextField
							cx={ cx }
							id="g2rd-connector-manager-url"
							type="url"
							label="Adresse du manager"
							hint="Adresse de votre tableau de bord G2RD WP Manager, par exemple https://wp-manager.g2rd.fr."
							value={ state.managerUrl }
							onChange={ ( managerUrl ) =>
								update( { managerUrl } )
							}
							disabled={ anyBusy }
						/>
						{ ! state.enrolled && (
							<TextField
								cx={ cx }
								id="g2rd-connector-invitation"
								type="text"
								label="Jeton d’invitation"
								hint="Généré dans le manager : fiche du site, « Inviter ce site à se connecter ». Valable 15 minutes."
								value={ token }
								onChange={ setToken }
								disabled={ anyBusy }
							/>
						) }
					</div>

					<div className="g2rd-connector-actions">
						<Button
							variant="secondary"
							onClick={ testHealth }
							isBusy={ busy === 'test' }
							disabled={ anyBusy }
						>
							Tester la connexion
						</Button>
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
						{ state.enrolled && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ unenroll }
								isBusy={ busy === 'unenroll' }
								disabled={ anyBusy }
							>
								Déconnecter du manager
							</Button>
						) }
						<Button
							variant={ state.enrolled ? 'primary' : 'secondary' }
							onClick={ save }
							isBusy={ busy === 'save' }
							disabled={ anyBusy }
						>
							Enregistrer
						</Button>
					</div>
				</div>
			</section>

			<section
				className={ cx( 'section' ) }
				aria-labelledby="g2rd-connector-titre-echanges"
			>
				<div className={ cx( 'section__head' ) }>
					<div>
						<h3
							className={ cx( 'section__title' ) }
							id="g2rd-connector-titre-echanges"
						>
							Échanges avec le manager
						</h3>
						<p className={ cx( 'section__desc' ) }>
							Ce que ce site envoie au manager, et ce qu’il
							l’autorise à faire.
						</p>
					</div>
				</div>
				<div className={ cx( 'setting-list' ) }>
					<SettingRow
						cx={ cx }
						id="g2rd-connector-heartbeat"
						icon="pulse"
						title="Signe de vie horaire"
						description="Toutes les heures, le site envoie au manager ses versions (WordPress, PHP, connecteur), l’espace disque libre, le nombre d’extensions actives et d’utilisateurs, et relève les commandes en attente si les commandes à distance sont autorisées."
						checked={ state.heartbeatEnabled }
						onChange={ ( heartbeatEnabled ) =>
							update( { heartbeatEnabled } )
						}
						disabled={ anyBusy }
					/>
					<SettingRow
						cx={ cx }
						id="g2rd-connector-events"
						icon="bolt"
						title="Événements en temps réel"
						description="Prévient le manager dès qu’ils se produisent : connexions réussies ou échouées, extensions activées ou désactivées, mises à jour installées."
						checked={ state.eventsEnabled }
						onChange={ ( eventsEnabled ) =>
							update( { eventsEnabled } )
						}
						disabled={ anyBusy }
					/>
					<SettingRow
						cx={ cx }
						id="g2rd-connector-commands"
						icon="terminal"
						title="Commandes à distance"
						description="Autorise le manager à lancer des opérations sur ce site : mises à jour, restaurations d’extensions, vidage du cache, nettoyage de la base de données."
						checked={ state.remoteCommandsEnabled }
						onChange={ ( remoteCommandsEnabled ) =>
							update( { remoteCommandsEnabled } )
						}
						disabled={ anyBusy }
					/>
					<SettingRow
						cx={ cx }
						id="g2rd-connector-ipv4"
						icon="globe"
						title="IPv4 vers le manager"
						description="À activer si l’hébergeur bloque l’IPv6 sortante du serveur : signes de vie, événements et enrôlement passent alors par l’IPv4. Ne concerne que les appels vers le manager."
						checked={ state.forceIpv4ToManager }
						onChange={ ( forceIpv4ToManager ) =>
							update( { forceIpv4ToManager } )
						}
						disabled={ anyBusy }
					/>
					<SettingRow
						cx={ cx }
						id="g2rd-connector-direct-login"
						icon="login"
						title="Connexion directe depuis G2RD"
						description={
							state.allowDirectLogin
								? 'G2RD WP Manager peut ouvrir l’administration de ce site sans mot de passe, avec le compte administrateur choisi dans la fiche du site (lien à usage unique valable une minute, réservé aux comptes protégés par double authentification).'
								: 'Désactivée : le bouton du manager ouvre la page de connexion habituelle du site.'
						}
						checked={ state.allowDirectLogin }
						onChange={ ( allowDirectLogin ) =>
							update( { allowDirectLogin } )
						}
						disabled={ anyBusy }
					/>
				</div>
			</section>

			<section
				className={ cx( 'section' ) }
				aria-labelledby="g2rd-connector-titre-securite"
			>
				<div className={ cx( 'section__head' ) }>
					<div>
						<h3
							className={ cx( 'section__title' ) }
							id="g2rd-connector-titre-securite"
						>
							Sécurité des commandes
						</h3>
						<p className={ cx( 'section__desc' ) }>
							Vérification de la signature des requêtes envoyées
							par le manager.
						</p>
					</div>
				</div>
				<div className="g2rd-connector-stack">
					<div className={ cx( 'setting-list' ) }>
						<SettingRow
							cx={ cx }
							id="g2rd-connector-signature"
							icon="shield"
							title="Exiger des commandes signées"
							description={
								state.signatureRequired
									? 'Toute requête du manager non signée ou mal signée est refusée. À désactiver si le manager cesse de signer et que le site devient injoignable.'
									: `La signature est vérifiée et signalée au manager, mais une requête non signée reste acceptée.${
											failures.failed_count > 0
												? ' Des échecs de vérification ont été constatés : n’activez pas avant d’en avoir trouvé la cause.'
												: ''
										}`
							}
							checked={ state.signatureRequired }
							onChange={ ( signatureRequired ) =>
								update( { signatureRequired } )
							}
							disabled={ anyBusy }
						/>
					</div>
					<dl className="g2rd-connector-facts">
						<Fact cx={ cx } label="Échecs de vérification">
							{ failures.failed_count > 0
								? plural(
										failures.failed_count,
										'échec',
										'échecs'
									)
								: 'aucun' }
						</Fact>
						{ failures.failed_count > 0 && (
							<Fact cx={ cx } label="Dernier échec">
								{ lastFailure ?? 'date inconnue' }
							</Fact>
						) }
					</dl>
					{ failures.failed_count > 0 &&
						null !== failures.last_code && (
							<p className={ cx( 'section__footnote' ) }>
								{ SIGNATURE_FAILURES[ failures.last_code ] ??
									`Code technique du dernier échec : ${ failures.last_code }.` }
							</p>
						) }
				</div>
			</section>

			<section
				className={ cx( 'section' ) }
				aria-labelledby="g2rd-connector-titre-diagnostic"
			>
				<div className={ cx( 'section__head' ) }>
					<div>
						<h3
							className={ cx( 'section__title' ) }
							id="g2rd-connector-titre-diagnostic"
						>
							Diagnostic
						</h3>
						<p className={ cx( 'section__desc' ) }>
							État du connecteur sur ce site, pour le support.
						</p>
					</div>
				</div>
				<dl className="g2rd-connector-facts">
					<Fact cx={ cx } label="Version du connecteur">
						<span className={ cx( 'chip', 'chip--muted' ) }>
							{ state.connectorVersion }
						</span>
					</Fact>
					<Fact cx={ cx } label="Points de restauration">
						{ pointsSummary }
					</Fact>
					<Fact
						cx={ cx }
						label="Dernière capture des mises à jour tierces"
					>
						{ null === capture
							? 'jamais. Ouvrez Extensions ou Tableau de bord → Mises à jour pour l’amorcer.'
							: `${ formatDate( capture.capturedAt ) } — ${ plural(
									capture.plugins,
									'extension',
									'extensions'
								) }, ${ plural( capture.themes, 'thème', 'thèmes' ) }.` }
					</Fact>
				</dl>
			</section>

			{ /* Barre collée en bas de l'écran : résultat de la dernière action, et
			     rappel des modifications non enregistrées (un interrupteur basculé
			     n'est appliqué qu'à l'enregistrement). */ }
			<div
				className={
					null !== notice || dirty
						? `g2rd-connector-bar ${ cx( 'section' ) }`
						: 'g2rd-connector-bar'
				}
			>
				<div className="g2rd-connector-bar__message" aria-live="polite">
					{ null !== notice && (
						<div
							className={ `notice notice-${ notice.status } inline ${ cx(
								'notice-inline'
							) }` }
						>
							<p>{ notice.message }</p>
						</div>
					) }
				</div>
				{ null !== notice && (
					<Button
						variant="tertiary"
						onClick={ () => setNotice( null ) }
					>
						Masquer le message
					</Button>
				) }
				{ dirty && (
					<>
						<Status cx={ cx } tone="warn">
							Modifications non enregistrées
						</Status>
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ busy === 'save' }
							disabled={ anyBusy }
						>
							Enregistrer
						</Button>
					</>
				) }
			</div>
		</div>
	);
}

interface SettingRowProps {
	cx: Cx;
	id: string;
	icon: IconName;
	title: string;
	description: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	disabled: boolean;
}

/**
 * Ligne de réglage : icône, titre et description à gauche, interrupteur à
 * droite. Le titre est le libellé de l'interrupteur (cliquable), la description
 * lui est rattachée pour les lecteurs d'écran.
 *
 * @param props Propriétés de la ligne.
 */
function SettingRow( props: SettingRowProps ): JSX.Element {
	const { cx, id, icon, title, description, checked, onChange, disabled } =
		props;
	return (
		<div
			className={ `${ cx( 'setting-row' ) }${ checked ? ' is-on' : '' }` }
		>
			<span className={ cx( 'setting-row__icon' ) }>
				<Icon name={ icon } />
			</span>
			<div className={ cx( 'setting-row__main' ) }>
				<label className={ cx( 'setting-row__title' ) } htmlFor={ id }>
					{ title }
				</label>
				<p
					className={ cx( 'setting-row__desc' ) }
					id={ `${ id }-desc` }
				>
					{ description }
				</p>
			</div>
			<div className={ cx( 'setting-row__control' ) }>
				<FormToggle
					className={ cx( 'toggle' ) }
					id={ id }
					checked={ checked }
					disabled={ disabled }
					onChange={ ( event: { target: HTMLInputElement } ) =>
						onChange( event.target.checked )
					}
					aria-describedby={ `${ id }-desc` }
				/>
			</div>
		</div>
	);
}

interface TextFieldProps {
	cx: Cx;
	id: string;
	type: 'url' | 'text';
	label: string;
	hint: string;
	value: string;
	onChange: ( value: string ) => void;
	disabled: boolean;
}

/**
 * Champ en creux : libellé, champ, aide rattachée au champ.
 *
 * @param props Propriétés du champ.
 */
function TextField( props: TextFieldProps ): JSX.Element {
	const { cx, id, type, label, hint, value, onChange, disabled } = props;
	return (
		<div>
			<label className={ cx( 'field__label' ) } htmlFor={ id }>
				{ label }
			</label>
			<input
				className={ cx( 'field__input' ) }
				id={ id }
				type={ type }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
				disabled={ disabled }
				autoComplete="off"
				spellCheck={ false }
				aria-describedby={ `${ id }-hint` }
			/>
			<p className={ cx( 'field__hint' ) } id={ `${ id }-hint` }>
				{ hint }
			</p>
		</div>
	);
}

/**
 * Fait affiché (terme et valeur) d'une liste de description.
 *
 * @param props          Propriétés.
 * @param props.cx       Fabrique de classes du mode.
 * @param props.label    Terme.
 * @param props.children Valeur.
 */
function Fact( {
	cx,
	label,
	children,
}: {
	cx: Cx;
	label: string;
	children: JSX.Element | string;
} ): JSX.Element {
	return (
		<div>
			<dt className={ cx( 'field__label' ) }>{ label }</dt>
			<dd>{ children }</dd>
		</div>
	);
}

/**
 * Pastille d'état : point coloré et texte. Le texte porte l'état (le point est
 * décoratif) ; « neutral » sans couleur, « ok » vert, « warn » orangé.
 *
 * @param props          Propriétés.
 * @param props.cx       Fabrique de classes du mode.
 * @param props.tone     Ton de la pastille.
 * @param props.children Texte de l'état.
 */
function Status( {
	cx,
	tone,
	children,
}: {
	cx: Cx;
	tone: 'neutral' | 'ok' | 'warn';
	children: string;
} ): JSX.Element {
	const ok = 'ok' === tone;
	const warn = 'warn' === tone;
	return (
		<span
			className={ cx(
				'status',
				ok && 'status--ok',
				warn && 'status--warn'
			) }
		>
			<span className={ cx( 'status__dot' ) } aria-hidden="true" />
			{ children }
		</span>
	);
}
