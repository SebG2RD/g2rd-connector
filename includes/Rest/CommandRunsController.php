<?php
/**
 * Les deux routes des commandes exécutées en arrière-plan (§ 3.3 et § 3.6 de la
 * spécification) :
 *   POST /wp-json/g2rd/v1/command-runs           — acceptation (accept()) ;
 *   GET  /wp-json/g2rd/v1/command-runs/<numéro>  — lecture de l'état (read()).
 *
 * La plateforme confie au site une commande longue sous un numéro qu'elle a tiré
 * (`command_id`, 128 bits). Le site l'enregistre, répond « reçu » (202) en une à deux
 * secondes, puis l'exécute dans le même processus, une fois la réponse partie. Le CDN de
 * l'hébergeur, qui renvoie une requête restée 60 s sans réponse, n'a donc plus rien à
 * renvoyer ; et s'il renvoie quand même, la copie trouve le numéro et ne reçoit que l'état.
 *
 * Une même commande n'est programmée qu'une fois par numéro :
 *
 *   numéro inconnu, site libre              → 202 `accepted`, exécution programmée
 *   numéro inconnu, site occupé             → 409 `site_busy`, rien n'est enregistré
 *   numéro connu, même corps                → 200 `duplicate`, l'état sans le résultat
 *   numéro connu, autre corps               → 409 `command_id_conflict`
 *   renvoi du CDN (nonce vu), numéro connu   → 200 `duplicate`
 *   renvoi du CDN (nonce vu), numéro inconnu → 401 `signature_replayed`
 *
 * Accès : jeton ET signature valide, quelle que soit la politique du site
 * (Auth::require_signed_run_access()). Le résultat d'une commande ne sort jamais de
 * l'acceptation : il se lit par l'interrogation signée de la route de lecture, une fois
 * l'exécution conclue (done, failed). La route signée de la lecture est la route REST,
 * numéro compris, corps vide ; son renvoi est refusé (Auth).
 *
 * Taille : 16 Ko au plus, refusés par guard_body_size() sur `rest_pre_dispatch`, avant
 * que WordPress ne décode le JSON (WP_REST_Server::dispatch(), juste après ce filtre).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\CommandRuns\AsyncProbe;
use G2RD\Connector\CommandRuns\Capability;
use G2RD\Connector\CommandRuns\ExecutionSlot;
use G2RD\Connector\CommandRuns\Refusal;
use G2RD\Connector\CommandRuns\RunReaper;
use G2RD\Connector\CommandRuns\RunState;
use G2RD\Connector\CommandRuns\RunStore;
use G2RD\Connector\CommandRuns\StoreUnavailable;
use G2RD\Connector\Security\RequestSignature;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class CommandRunsController {

	/** Route d'acceptation, sous l'espace `g2rd/v1`. */
	private const ROUTE = '/command-runs';

	/**
	 * Route de lecture, sous l'espace `g2rd/v1`. Motif large, volontairement : un numéro
	 * mal formé reçoit le 400 `invalid_command_id` de read(), jamais la 404 `rest_no_route`
	 * de WordPress, que la plateforme lit comme « connecteur sans la route » (elle
	 * repasserait alors en synchrone une commande peut-être acceptée).
	 */
	private const READ_ROUTE = '/command-runs/(?P<command_id>[^/]+)';

	/** Taille maximale du corps d'une acceptation (octets). */
	public const MAX_BODY_BYTES = 16384;

	/** Délai conseillé à la plateforme avant sa première interrogation (secondes). */
	private const POLL_AFTER_SECONDS = 2;

	/** Exécutions examinées au plus par le ramassage, à chaque acceptation. */
	private const SWEEP_LIMIT = 200;

	/** Comptes rendus supprimés au plus par la purge, à chaque acceptation (§ 3.2). */
	private const PURGE_LIMIT = 20;

	/**
	 * Programme l'exécution de la commande acceptée, pour après la réponse.
	 *
	 * @var \Closure(string, string, array<mixed>|null): void
	 */
	private \Closure $queue;

	/**
	 * Heure courante (secondes Unix).
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * @param \Closure(string, string, array<mixed>|null): void $queue Programme l'exécution
	 *        acceptée, pour après la réponse : reçoit le numéro, la commande et sa charge
	 *        (null si absente), une fois par acceptation, et ne fait que les mémoriser.
	 * @param (\Closure(): int)|null $clock Heure courante (secondes Unix) ; time() par défaut.
	 */
	public function __construct( \Closure $queue, ?\Closure $clock = null ) {
		$this->queue = $queue;
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Enregistre les deux routes. Sans schéma `args` : la validation est faite par accept()
	 * (après le contrôle de taille) et par read(), avec les codes que la plateforme lit.
	 */
	public function register(): void {
		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'accept' ],
				'permission_callback' => [ Auth::class, 'require_signed_run_access' ],
			]
		);
		register_rest_route(
			G2RD_CONNECTOR_REST_NS,
			self::READ_ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'read' ],
				'permission_callback' => [ Auth::class, 'require_signed_run_access' ],
			]
		);
	}

	/**
	 * Filtre `rest_pre_dispatch`, priorité 5 : 413 `body_too_large` pour un corps de plus de
	 * 16 Ko sur la route d'acceptation. Il passe avant le décodage du JSON par WordPress, et
	 * avant l'authentification : la 413 n'en dit pas plus qu'une 401. Le filtre anti-cache
	 * (Plugin::prevent_rest_cache(), priorité 10) passe ensuite, et vaut aussi pour la 413.
	 *
	 * Branché sur toutes les requêtes REST du site : pour toute autre route, ou si un filtre
	 * précédent a déjà décidé de la réponse, il rend `$result` tel quel, sans lire le corps.
	 * Il ne lève jamais.
	 *
	 * @param mixed $result  Court-circuit éventuel du traitement (null : rien de décidé).
	 * @param mixed $server  Serveur REST (non utilisé).
	 * @param mixed $request Requête REST courante.
	 * @return mixed `$result` tel quel, ou le refus 413.
	 */
	public static function guard_body_size( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		unset( $server );
		try {
			if ( ! empty( $result ) || ! $request instanceof WP_REST_Request || ! self::is_accept_route( $request ) ) {
				return $result;
			}

			return self::body_too_large( $request ) ? Refusal::error( Refusal::BODY_TOO_LARGE ) : $result;
		} catch ( \Throwable ) {
			return $result;
		}
	}

	/**
	 * Accepte la commande, dans cet ordre :
	 *   0. ignore_user_abort() : une fermeture du client ne laisse pas une acceptation à
	 *      moitié faite ;
	 *   1. corps → numéro, commande admise, charge (400 sinon) ;
	 *   2. numéro connu → « déjà reçue » (même corps) ou conflit (autre corps) ;
	 *   3. numéro inconnu, copie d'une requête déjà reçue (renvoi du CDN) → 401 ;
	 *   4. constante d'arrêt → 503 ;
	 *   5. ramassage des exécutions mortes, purge des comptes rendus ;
	 *   6. prise du créneau : occupé → 409 `site_busy`, rien n'est enregistré ; tenu par ce
	 *      même numéro (une autre requête est en train de l'accepter) → « déjà reçue » si
	 *      l'enregistrement existe, 500 sinon ;
	 *   7. création de l'enregistrement (perdue : créneau rendu, puis « déjà reçue » si le
	 *      numéro est apparu entre-temps, 500 sinon) ;
	 *   8. exécution programmée pour après la réponse ;
	 *   9. 202.
	 *
	 * Le numéro connu passe avant la constante d'arrêt, volontairement : un renvoi d'une
	 * commande déjà acceptée, arrivé après la pose de la constante, recevrait 503 ; la
	 * plateforme croirait la commande jamais partie et la passerait par `/command` : elle
	 * s'exécuterait deux fois.
	 *
	 * Base qui refuse une lecture ou une écriture : 500, rien n'est exécuté par cette
	 * demande. La plateforme lit une 500 comme ambiguë et vérifie le numéro.
	 */
	public function accept( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		ignore_user_abort( true );
		$now = ( $this->clock )();

		// Le filtre de taille peut avoir été retiré par une autre extension : la borne tient
		// quand même.
		if ( self::body_too_large( $request ) ) {
			return Refusal::error( Refusal::BODY_TOO_LARGE );
		}
		$body   = (string) $request->get_body();
		$parsed = self::parse( $body );
		if ( $parsed instanceof WP_Error ) {
			return $parsed;
		}
		[ $command_id, $command, $payload ] = $parsed;
		$fingerprint                        = hash( 'sha256', $body );

		try {
			$known = RunStore::read( $command_id );
		} catch ( StoreUnavailable $e ) {
			return self::store_unavailable();
		}
		if ( null !== $known ) {
			return self::already_received( $command_id, $known, $fingerprint, $now );
		}

		if ( Auth::is_replayed_acceptance( $request ) ) {
			return self::replayed_unknown();
		}

		if ( Capability::disabled_by_constant() ) {
			return Refusal::error( Refusal::ASYNC_DISABLED );
		}

		RunReaper::sweep( $now, self::SWEEP_LIMIT );
		RunStore::purge( $now, self::PURGE_LIMIT );

		$slot = ExecutionSlot::acquire( $command_id, $command, $now );
		if ( ! $slot['acquired'] ) {
			if ( ExecutionSlot::REASON_STORE_UNAVAILABLE === ( $slot['reason'] ?? null ) ) {
				return self::store_unavailable();
			}
			// Le créneau n'est pas réentrant : tenu par ce même numéro, il dit qu'une autre
			// requête est en train d'accepter cette commande (la plateforme renvoie le même
			// numéro quand elle n'a pas eu de réponse). Ce n'est pas « site occupé » : la
			// plateforme repartirait sous un nouveau numéro, et la commande s'exécuterait
			// deux fois.
			if ( ( $slot['busy_with'] ?? null ) === $command_id ) {
				return self::received_meanwhile( $command_id, $fingerprint, $now, self::acceptance_in_progress() );
			}

			return Refusal::error( Refusal::SITE_BUSY, $slot );
		}

		$created = RunStore::create(
			[
				'command_id'  => $command_id,
				'command'     => $command,
				'body_sha256' => $fingerprint,
				'state'       => RunState::ACCEPTED,
				'accepted_at' => $now,
				'started_at'  => null,
				'alive_at'    => null,
				'finished_at' => null,
			]
		);
		if ( ! $created ) {
			// Numéro enregistré entre-temps par un autre processus, ou écriture refusée : le
			// créneau, pris pour rien, est rendu.
			ExecutionSlot::release( $command_id );

			return self::received_meanwhile( $command_id, $fingerprint, $now, self::store_unavailable() );
		}

		( $this->queue )( $command_id, $command, $payload );

		return new WP_REST_Response(
			[
				'command_id'         => $command_id,
				'state'              => RunState::ACCEPTED,
				'accepted_at'        => gmdate( 'c', $now ),
				'poll_after_seconds' => self::POLL_AFTER_SECONDS,
				'served_at'          => gmdate( 'c', $now ),
			],
			202
		);
	}

	/**
	 * Rend l'état de l'exécution `<numéro>` : ramassage léger (ce numéro, puis le détenteur
	 * du créneau, pas le ramassage complet de l'acceptation), puis relecture. Rien d'autre :
	 * ni inventaire, ni requête sortante, aucune autre écriture.
	 *
	 *   numéro mal formé              → 400 `invalid_command_id`, rien n'est lu ;
	 *   numéro inconnu (ou illisible) → 404 `run_unknown`, distinct de `rest_no_route` ;
	 *   lecture refusée par la base   → 500, jamais « inconnu » : la plateforme interroge
	 *                                   de nouveau ;
	 *   sinon                         → 200, cf. run_view().
	 *
	 * Le numéro est celui de la route (paramètre tiré du chemin par le motif), couverte par
	 * la signature. Jamais get_param() : un `?command_id=…` ajouté à l'adresse, hors
	 * signature, y passerait avant lui.
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$now        = ( $this->clock )();
		$command_id = $request->get_url_params()['command_id'] ?? null;
		if ( ! is_string( $command_id ) || 1 !== preg_match( RunStore::ID_PATTERN, $command_id ) ) {
			return Refusal::error( Refusal::INVALID_COMMAND_ID );
		}

		RunReaper::sweep_one( $command_id, $now );
		self::sweep_slot_holder( $command_id, $now );

		try {
			$record = RunStore::read( $command_id );
		} catch ( StoreUnavailable $e ) {
			return self::read_unavailable();
		}
		if ( null === $record ) {
			return Refusal::error( Refusal::RUN_UNKNOWN );
		}

		return new WP_REST_Response( self::run_view( $command_id, $record, $now ), 200 );
	}

	/**
	 * Corps de l'acceptation → [ numéro, commande, charge ], ou le refus 400.
	 *
	 * La charge est celle de `/command` pour chaque commande, contrôlée par CommandExecutor
	 * à l'exécution. Ici : absente, ou un objet JSON. Un objet vide peut arriver en `[]` :
	 * PHP encode ainsi un tableau vide. Pour la sonde : `seconds`, entier de 10 à 600, par
	 * pas de 10.
	 *
	 * @return array{0: string, 1: string, 2: array<mixed>|null}|WP_Error
	 */
	private static function parse( string $body ): array|WP_Error {
		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : [];

		$command_id = $data['command_id'] ?? null;
		if ( ! is_string( $command_id ) || 1 !== preg_match( RunStore::ID_PATTERN, $command_id ) ) {
			return Refusal::error( Refusal::INVALID_COMMAND_ID );
		}

		$command = $data['command'] ?? null;
		if ( ! is_string( $command ) || ! AsyncCommands::is_admitted( $command, AsyncProbe::enabled() ) ) {
			return Refusal::error( Refusal::COMMAND_NOT_ASYNC );
		}

		$payload = null;
		if ( array_key_exists( 'payload', $data ) ) {
			$payload = $data['payload'];
			// Un objet JSON décodé : un tableau à clés, ou vide. Une liste non vide n'en est pas un.
			if ( ! is_array( $payload ) || ( [] !== $payload && array_is_list( $payload ) ) ) {
				return Refusal::error( Refusal::INVALID_PAYLOAD );
			}
		}
		if ( AsyncCommands::PROBE === $command && ! self::is_probe_duration( $payload['seconds'] ?? null ) ) {
			return Refusal::error( Refusal::INVALID_PAYLOAD );
		}

		return [ $command_id, $command, $payload ];
	}

	/** Durée de la sonde : un entier de 10 à 600, multiple de 10. */
	private static function is_probe_duration( mixed $seconds ): bool {
		return is_int( $seconds )
			&& $seconds >= AsyncProbe::STEP_SECONDS
			&& $seconds <= AsyncProbe::MAX_SECONDS
			&& 0 === $seconds % AsyncProbe::STEP_SECONDS;
	}

	/**
	 * Le numéro est apparu entre la première lecture et la prise du créneau ou la création :
	 * relu, il donne « déjà reçue » (ou le conflit). Absent, `$if_absent` : rien n'a été
	 * enregistré par cette demande.
	 */
	private static function received_meanwhile( string $command_id, string $fingerprint, int $now, WP_Error $if_absent ): WP_REST_Response|WP_Error {
		try {
			$known = RunStore::read( $command_id );
		} catch ( StoreUnavailable $e ) {
			return self::store_unavailable();
		}

		return null === $known ? $if_absent : self::already_received( $command_id, $known, $fingerprint, $now );
	}

	/**
	 * Commande déjà reçue sous ce numéro : son état si le corps est le même (renvoi), le
	 * conflit sinon. Jamais le résultat, jamais l'étape : ils se lisent par l'interrogation
	 * signée. Rien n'est relancé, rien n'est réécrit.
	 *
	 * @param array<string, mixed> $known Enregistrement relu (RunStore::read()).
	 */
	private static function already_received( string $command_id, array $known, string $fingerprint, int $now ): WP_REST_Response|WP_Error {
		$stored = $known['body_sha256'] ?? null;
		if ( ! is_string( $stored ) || ! hash_equals( $stored, $fingerprint ) ) {
			return Refusal::error( Refusal::COMMAND_ID_CONFLICT );
		}

		return new WP_REST_Response(
			[
				'command_id'  => $command_id,
				'state'       => $known['state'],
				'duplicate'   => true,
				'accepted_at' => self::iso( $known['accepted_at'] ?? null ),
				'started_at'  => self::iso( $known['started_at'] ?? null ),
				'alive_at'    => self::iso( $known['alive_at'] ?? null ),
				'finished_at' => self::iso( $known['finished_at'] ?? null ),
				'served_at'   => gmdate( 'c', $now ),
			],
			200
		);
	}

	/**
	 * Ramasse le détenteur du créneau s'il en est un autre que `$command_id` (déjà ramassé) :
	 * une exécution morte en route ne retient pas le site jusqu'à l'acceptation suivante.
	 * Ménage seulement : une lecture refusée par la base n'empêche pas la lecture demandée.
	 */
	private static function sweep_slot_holder( string $command_id, int $now ): void {
		try {
			$holder = ExecutionSlot::holder();
		} catch ( StoreUnavailable $e ) {
			return;
		}
		if ( null !== $holder && $holder['command_id'] !== $command_id ) {
			RunReaper::sweep_one( $holder['command_id'], $now );
		}
	}

	/**
	 * Réponse de lecture : {command_id, command, state, accepted_at, started_at, alive_at,
	 * finished_at, step?, outcome?, interruption?, served_at}, dates en ISO 8601 UTC.
	 *   - `step` : étape de la transaction d'une mise à jour protégée, quand elle est
	 *     enregistrée (la dernière atteinte, pour une exécution conclue) ;
	 *   - `outcome` : le résultat, de la forme de la réponse de `/command`, seulement pour
	 *     une exécution conclue par lui (done, failed) : jamais avant la fin ;
	 *   - `interruption` : cause d'une interruption (gardée après un résultat tardif) ;
	 *   - `served_at` : heure de la réponse, pour que la plateforme écarte une réponse
	 *     figée par un cache.
	 * Ni l'empreinte du corps, ni rien d'autre de l'enregistrement. Bornée à 64 Ko par
	 * RunStore::encode(), à l'écriture.
	 *
	 * @param array<string, mixed> $record Enregistrement relu (RunStore::read()).
	 * @return array<string, mixed>
	 */
	private static function run_view( string $command_id, array $record, int $now ): array {
		$view = [
			'command_id'  => $command_id,
			'command'     => is_string( $record['command'] ?? null ) ? $record['command'] : null,
			'state'       => $record['state'],
			'accepted_at' => self::iso( $record['accepted_at'] ?? null ),
			'started_at'  => self::iso( $record['started_at'] ?? null ),
			'alive_at'    => self::iso( $record['alive_at'] ?? null ),
			'finished_at' => self::iso( $record['finished_at'] ?? null ),
		];
		if ( is_string( $record['step'] ?? null ) ) {
			$view['step'] = $record['step'];
		}
		if ( in_array( $record['state'], [ RunState::DONE, RunState::FAILED ], true ) && is_array( $record['outcome'] ?? null ) ) {
			$view['outcome'] = $record['outcome'];
		}
		if ( is_array( $record['interruption'] ?? null ) ) {
			$view['interruption'] = $record['interruption'];
		}
		$view['served_at'] = gmdate( 'c', $now );

		return $view;
	}

	/** Date d'un enregistrement (secondes Unix) en ISO 8601 UTC ; null si elle manque. */
	private static function iso( mixed $timestamp ): ?string {
		return is_int( $timestamp ) ? gmdate( 'c', $timestamp ) : null;
	}

	/**
	 * Route d'acceptation ? WordPress compare les routes sans tenir compte de la casse
	 * (WP_REST_Server::match_request_to_handler()) : le garde de taille aussi.
	 */
	private static function is_accept_route( WP_REST_Request $request ): bool {
		return 0 === strcasecmp( (string) $request->get_route(), '/' . G2RD_CONNECTOR_REST_NS . self::ROUTE );
	}

	private static function body_too_large( WP_REST_Request $request ): bool {
		return strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES;
	}

	/**
	 * Copie d'une requête déjà reçue (même nonce, signature valide), sous un numéro que le
	 * site ne connaît pas : elle ne déclenche rien. Le code est celui d'Auth ; la plateforme
	 * le lit comme ambigu (l'original a pu être accepté) et vérifie le numéro.
	 */
	private static function replayed_unknown(): WP_Error {
		return new WP_Error(
			'g2rd_connector_' . RequestSignature::CODE_REPLAYED,
			__( 'Demande refusée : c’est la copie d’une demande déjà reçue par le site (même signature), et aucune opération n’y est enregistrée sous ce numéro. Cette copie n’a rien lancé. Cause probable : un intermédiaire (CDN de l’hébergeur, pare-feu) a renvoyé la requête. Action : aucune, la plateforme vérifie l’état de l’opération auprès du site.', 'g2rd-connector' ),
			[ 'status' => 401 ]
		);
	}

	/**
	 * La base a refusé une lecture ou une écriture : rien n'est exécuté par cette demande,
	 * mais rien ne dit qu'une autre demande sous le même numéro n'a pas été reçue.
	 */
	private static function store_unavailable(): WP_Error {
		return new WP_Error(
			'g2rd_connector_store_unavailable',
			__( 'L’opération n’a pas pu être enregistrée sur le site : la base de données a refusé la lecture ou l’écriture. Rien n’a été exécuté par cette demande. Cause probable : base de données momentanément indisponible ou surchargée (connexion perdue, trop de connexions). Action : aucune, la plateforme vérifie auprès du site si l’opération a été reçue ; si l’erreur se répète, vérifier l’état de la base de données auprès de l’hébergeur.', 'g2rd-connector' ),
			[ 'status' => 500 ]
		);
	}

	/**
	 * La base a refusé de lire l'exécution : rien ne dit qu'elle est inconnue. L'exécution
	 * elle-même n'est pas touchée par cette lecture.
	 */
	private static function read_unavailable(): WP_Error {
		return new WP_Error(
			'g2rd_connector_store_unavailable',
			__( 'L’état de l’opération n’a pas pu être lu sur le site : la base de données a refusé la lecture. L’opération elle-même n’est pas touchée par cette demande. Cause probable : base de données momentanément indisponible ou surchargée (connexion perdue, trop de connexions). Action : aucune, la plateforme interroge de nouveau le site ; si l’erreur se répète, vérifier l’état de la base de données auprès de l’hébergeur.', 'g2rd-connector' ),
			[ 'status' => 500 ]
		);
	}

	/** Le même numéro est en cours d'acceptation par une autre requête. */
	private static function acceptance_in_progress(): WP_Error {
		return new WP_Error(
			'g2rd_connector_acceptance_in_progress',
			__( 'L’opération est en cours de réception sur le site par une autre demande portant le même numéro. Rien n’a été exécuté par cette demande, et l’opération ne le sera qu’une fois. Cause probable : la plateforme a renvoyé l’opération faute de réponse assez rapide. Action : aucune, la plateforme vérifie l’état de l’opération auprès du site ; si la réception a été interrompue, le site se libère seul en 10 minutes au plus.', 'g2rd-connector' ),
			[ 'status' => 500 ]
		);
	}
}
