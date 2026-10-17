/**
 * État vrai de la connexion au manager, et cause d'un échec d'enrôlement.
 *
 * Fonctions pures (ni React, ni WordPress), affichées par ConnectorPanel.tsx ;
 * tests/Admin/ConnectorPanelTest.php en vérifie les règles et les textes.
 */

/**
 * Silence au-delà duquel le panneau signale l'absence de signe de vie : le
 * signe de vie part toutes les heures, deux heures sans échange réussi en
 * manquent au moins un.
 */
export const SILENCE_MS = 2 * 60 * 60 * 1000;

/** Réglages et dates enregistrés sur le serveur dont dépend l'état affiché. */
export interface ConnectionFacts {
	enrolled: boolean;
	/** Jeton stocké mais illisible ou refusé (BootData tokenState). */
	tokenBroken: boolean;
	heartbeatEnabled: boolean;
	/**
	 * Dernier échange réussi avec le manager : écrit seulement après un signe de
	 * vie accepté (ManagerClient::heartbeat) ou un relevé du site par le manager
	 * (SnapshotController::handle). ISO 8601, null si aucun.
	 */
	lastHeartbeatAt: string | null;
	/** Enrôlement réussi, échange lui aussi. ISO 8601, null si inconnu. */
	enrolledAt: string | null;
}

/**
 * - « not-enrolled » : pas encore enrôlé ;
 * - « interrupted » : jeton inutilisable, le site ne parle plus au manager ;
 * - « connected » : un échange a réussi depuis moins de SILENCE_MS ;
 * - « heartbeat-off » : signe de vie désactivé, rien ne permet d'en juger ;
 * - « silent » : signe de vie actif, mais aucun échange réussi depuis plus de
 *   SILENCE_MS (since : date du dernier, en millisecondes ; null si aucun).
 */
export type ConnectionStatus =
	| { kind: 'not-enrolled' }
	| { kind: 'interrupted' }
	| { kind: 'connected' }
	| { kind: 'heartbeat-off' }
	| { kind: 'silent'; since: number | null };

/**
 * Date ISO en millisecondes, ou null si absente ou illisible.
 *
 * @param value Date ISO 8601.
 */
function timeOf( value: string | null ): number | null {
	if ( null === value || '' === value ) {
		return null;
	}
	const time = Date.parse( value );
	return Number.isNaN( time ) ? null : time;
}

/**
 * État de la connexion, d'après la preuve du dernier échange réussi : jamais
 * « connecté » sur la seule foi d'un jeton présent.
 *
 * L'heure de comparaison est celle du navigateur : un écart d'horloge de moins
 * d'une heure avec le serveur reste absorbé par SILENCE_MS (une heure de marge
 * au-delà du battement horaire).
 *
 * @param facts Réglages et dates enregistrés.
 * @param now   Heure courante, en millisecondes.
 */
export function connectionStatus(
	facts: ConnectionFacts,
	now: number
): ConnectionStatus {
	if ( ! facts.enrolled ) {
		return { kind: 'not-enrolled' };
	}
	if ( facts.tokenBroken ) {
		return { kind: 'interrupted' };
	}
	const times = [
		timeOf( facts.lastHeartbeatAt ),
		timeOf( facts.enrolledAt ),
	].filter( ( time ): time is number => null !== time );
	const last = times.length > 0 ? Math.max( ...times ) : null;
	if ( null !== last && now - last < SILENCE_MS ) {
		return { kind: 'connected' };
	}
	if ( ! facts.heartbeatEnabled ) {
		return { kind: 'heartbeat-off' };
	}
	return { kind: 'silent', since: last };
}

/**
 * Durée écoulée, arrondie à l'heure (« 5 heures ») puis au jour à partir de
 * 48 heures (« 3 jours »).
 *
 * @param since Début, en millisecondes.
 * @param now   Heure courante, en millisecondes.
 */
export function elapsed( since: number, now: number ): string {
	const hours = Math.max( 0, Math.floor( ( now - since ) / 3600000 ) );
	if ( hours < 48 ) {
		return `${ hours } ${ hours > 1 ? 'heures' : 'heure' }`;
	}
	const days = Math.floor( hours / 24 );
	return `${ days } jours`;
}

/**
 * Causes probables d'un silence du signe de vie (état « silent »), affichées
 * dans leur propre paragraphe, avant les actions (SILENCE_ACTIONS) : un seul
 * bloc d'environ 90 mots rendait les actions difficiles à repérer.
 */
export const SILENCE_CAUSES =
	'Causes probables : WP-Cron ne s’est pas déclenché, faute de visites sur le site (ou parce que DISABLE_WP_CRON est défini dans wp-config.php sans tâche cron de remplacement chez l’hébergeur) ; ou les appels sortants du serveur vers le manager sont bloqués, par exemple l’IPv6 refusée par l’hébergeur ou son CDN.';

/**
 * Actions devant un silence du signe de vie, dans l'ordre. « IPv4 vers le
 * manager » et « Enregistrer » sont l'interrupteur et le bouton du même panneau.
 */
export const SILENCE_ACTIONS =
	'Que faire : rechargez cette page dans une minute (votre visite relance WP-Cron) ; si l’alerte reste, activez « IPv4 vers le manager », cliquez sur « Enregistrer » et attendez le prochain signe de vie (une heure au plus) ; vérifiez que WP-Cron s’exécute ; si rien ne change, contactez le support G2RD.';

/**
 * Famille d'un échec d'enrôlement :
 * - « offline » : le navigateur n'a pas joint WordPress ;
 * - « session » : WordPress a refusé la demande (session expirée) ;
 * - « invalid » : paramètres refusés par WordPress (adresse, jeton vide) ;
 * - « unreachable » : le manager n'a pas répondu, ou pas correctement ;
 * - « blocked » : refus HTTP 403 qui ne vient pas du manager (CDN, pare-feu) ;
 * - « wrong-address » : HTTP 404, l'adresse ne mène pas au manager ;
 * - « throttled » : trop de tentatives (HTTP 429 du manager) ;
 * - « refused » : le manager a refusé l'invitation (HTTP 403 avec sa réponse) ;
 * - « manager-message » : autre refus du manager (400, 422…), dont la raison
 *   est son message, affiché entre parenthèses : aucune cause n'est supposée ;
 * - « unknown » : réponse inattendue.
 */
export type EnrollFailure =
	| 'offline'
	| 'session'
	| 'invalid'
	| 'unreachable'
	| 'blocked'
	| 'wrong-address'
	| 'throttled'
	| 'refused'
	| 'manager-message'
	| 'unknown';

/**
 * Famille d'un échec d'enrôlement, d'après l'erreur rejetée par apiFetch :
 * { code, message, data: { status, manager_answered } } du serveur
 * (Rest\AdminController::handle_enroll), ou { code: 'fetch_error' } du
 * navigateur.
 *
 * Le manager répond lui-même 403 à une invitation invalide ou expirée, avec un
 * message JSON (manager_answered) : seul un 403 sans réponse du manager est un
 * blocage en chemin (incident du CDN sur l'IPv6 du 25/09), devant lequel une
 * nouvelle invitation ne servirait à rien.
 *
 * Les autres refus du manager n'ont pas une cause unique : 400 « Payload JSON
 * invalide », 422 pour une adresse de site en http:// (il faut le https du site,
 * pas une nouvelle invitation)… Le panneau renvoie alors au message du manager.
 * Un refus non classé sans réponse du manager (page HTML d'un CDN, par exemple)
 * est une réponse incorrecte : « unreachable ».
 *
 * @param error Valeur rejetée.
 */
export function enrollFailureKind( error: unknown ): EnrollFailure {
	const rejected = ( error ?? {} ) as {
		code?: unknown;
		data?: { status?: unknown; manager_answered?: unknown } | null;
	};
	const code = 'string' === typeof rejected.code ? rejected.code : '';
	const status = Number( rejected.data?.status ) || 0;

	if ( 'fetch_error' === code ) {
		return 'offline';
	}
	if (
		'rest_invalid_param' === code ||
		'rest_missing_callback_param' === code
	) {
		return 'invalid';
	}
	if ( code.startsWith( 'rest_' ) ) {
		return 401 === status || 403 === status ? 'session' : 'unknown';
	}
	if (
		'http_request_failed' === code ||
		'g2rd_connector_enroll_invalid_response' === code ||
		status >= 500
	) {
		return 'unreachable';
	}
	if ( 403 === status ) {
		return true === rejected.data?.manager_answered ? 'refused' : 'blocked';
	}
	if ( 404 === status ) {
		return 'wrong-address';
	}
	if ( 429 === status ) {
		return 'throttled';
	}
	if ( status >= 400 ) {
		return true === rejected.data?.manager_answered
			? 'manager-message'
			: 'unreachable';
	}
	return 'unknown';
}

/**
 * Cause probable et action de chaque famille d'échec d'enrôlement, après
 * « Le site n'a pas été enrôlé (détail). ».
 */
export const ENROLL_FAILURES: Record< EnrollFailure, string > = {
	offline:
		'Votre navigateur n’a pas pu joindre WordPress : connexion internet coupée, ou site momentanément indisponible. Vérifiez votre connexion, puis réessayez.',
	session:
		'WordPress a refusé la demande avant de contacter le manager. Cause probable : votre session WordPress a expiré. Rechargez la page, puis réessayez.',
	invalid:
		'WordPress a refusé la demande avant de contacter le manager : adresse du manager invalide, ou jeton d’invitation vide. Saisissez l’adresse complète du manager (avec https://) et le jeton, puis réessayez.',
	unreachable:
		'Le manager ne répond pas, ou pas correctement. Causes probables : adresse du manager erronée, manager momentanément indisponible, ou appels sortants du serveur bloqués (IPv6 refusée par l’hébergeur, notamment). Vérifiez l’adresse du manager, activez « IPv4 vers le manager », cliquez sur « Enregistrer », puis réessayez avec le même jeton (valable 15 minutes après sa création).',
	blocked:
		'La requête a été bloquée avant d’atteindre le manager (refus HTTP 403 sans réponse du manager) : CDN ou pare-feu, le plus souvent sur l’IPv6 sortante du serveur. Activez « IPv4 vers le manager », cliquez sur « Enregistrer », puis réessayez avec le même jeton (valable 15 minutes après sa création). Ne générez pas de nouvelle invitation : elle serait bloquée de la même façon.',
	'wrong-address':
		'L’adresse indiquée ne mène pas au manager (page introuvable, HTTP 404). Corrigez l’adresse du manager (par exemple https://wp-manager.g2rd.fr), cliquez sur « Enregistrer », puis réessayez avec le même jeton (valable 15 minutes après sa création).',
	throttled:
		'Trop de tentatives d’enrôlement depuis ce serveur : le manager les a suspendues. Patientez quelques minutes, puis réessayez, avec une nouvelle invitation si la vôtre a plus de 15 minutes.',
	refused:
		'Le manager a refusé l’enrôlement. Cause probable : jeton d’invitation expiré (valable 15 minutes) ou déjà utilisé, ou adresse de ce site différente de celle enregistrée dans le manager. Générez une nouvelle invitation depuis la fiche du site dans le manager (en vérifiant l’adresse du site), puis réessayez.',
	'manager-message':
		'Le manager a refusé l’enrôlement et en donne la raison entre parenthèses. Suivez l’indication du manager ci-dessus, puis réessayez (avec une nouvelle invitation si la vôtre a plus de 15 minutes) ; si l’indication ne suffit pas, contactez le support G2RD.',
	unknown:
		'Réponse inattendue de WordPress. Cause probable : extension de sécurité, pare-feu ou erreur PHP sur ce site. Rechargez la page, puis réessayez ; si cela se répète, contactez le support G2RD.',
};
