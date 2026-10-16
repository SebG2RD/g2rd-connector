<?php
/**
 * Répondre, puis exécuter (§ 3.1 de la spécification, recommandation A).
 *
 * La route d'acceptation (CommandRunsController::accept()) enregistre la commande, puis
 * la confie à queue(). La réponse 202 part, la réponse est terminée
 * (litespeed_finish_request(), à défaut fastcgi_finish_request()), puis la commande
 * s'exécute dans le MÊME processus, par CommandExecutor::run() tel quel : même contexte
 * qu'aujourd'hui (REST), mêmes filets. Le CDN de l'hébergeur, qui renvoie une requête
 * restée 60 s sans réponse, n'a plus rien à renvoyer.
 *
 * Où : dans le déroulé normal de la requête, par le filtre `rest_pre_serve_request`
 * (maybe_serve()), JAMAIS dans une fonction d'arrêt. Une erreur fatale levée dans une
 * fonction d'arrêt empêche les suivantes de passer : la reprise d'une mise à jour
 * protégée (ProtectedUpdate::recover_on_shutdown()) et la réactivation de l'extension
 * (CommandExecutor::perform_plugin_upgrade()). Dans le déroulé normal, une erreur fatale
 * les laisse passer, comme aujourd'hui. Le filtre passe après l'envoi du statut et des
 * en-têtes ; il est branché en dernier, pour que les filtres qui posent des en-têtes
 * (rest_send_cors_headers()) passent avant que la réponse parte.
 *
 * Séquence, pour la requête qui a programmé une exécution :
 *   1. ignore_user_abort( true ) : sous LiteSpeed sans `noabort`, la fermeture du client
 *      couperait sinon le processus ;
 *   2. écriture du JSON de la réponse, comme WP_REST_Server::serve_request() ;
 *   3. fin de la réponse : tampons de sortie vidés, puis fonction de fin ;
 *   4. démarrage (RunLifecycle::start()) : perdu (exécution ramassée entre-temps, base
 *      qui refuse ou qui lève), rien ne s'exécute ;
 *   5. signe de vie attaché (RunHeartbeat ; s'il ne s'attache pas, la commande
 *      s'exécute quand même), commande exécutée, résultat écrit (RunLifecycle::finish()),
 *      de la forme de la réponse de `/command` ;
 *   6. signe de vie détaché, puis créneau rendu (ExecutionSlot::release()), chacun
 *      rattrapé à part.
 *
 * Base qui lève au lieu de refuser (mysqli remis en mode exception par une extension,
 * dépôt de base tiers comme HyperDB ou LudicrousDB) : rien ne remonte hors du filtre. Un
 * créneau qu'elle n'a pas laissé rendre est repris 600 s après son dernier signe de vie.
 *
 * Pertes écrites au journal PHP du site (une ligne : quoi, cause probable, action, sans
 * donnée sensible), pour comprendre plus tard une exécution « abandonnée » ou
 * « interrompue » : démarrage perdu (étape 4), signe de vie non attaché, résultat non
 * enregistré (étape 5).
 *
 * Pas de set_time_limit() : la commande garde les mêmes conditions qu'aujourd'hui.
 *
 * Fonction de fin absente, inopérante ou qui lève : une ligne au journal PHP du site,
 * et l'exécution continue. La justesse ne dépend pas de la rapidité : si la réponse ne
 * part qu'à la fin, un renvoi du CDN trouve le numéro et reçoit « déjà reçue ».
 *
 * Réponse que le connecteur n'écrit pas lui-même : `_envelope` ou `_jsonp` dans
 * l'adresse (jamais envoyés par la plateforme : WordPress écrit alors la réponse sous une
 * autre forme), réponse d'un autre type, JSON impossible à encoder, ou erreur pendant
 * l'écriture. WordPress l'écrit après le filtre, et rien ne passe après lui hors des
 * fonctions d'arrêt : la commande s'exécute donc dans le filtre, par le même chemin
 * (étapes 4 à 6), sans terminer la réponse (elle serait perdue), qui ne part qu'à la fin.
 * Plus lent, toujours juste, comme ci-dessus. Cas sans usage réel.
 *
 * Sur toutes les autres requêtes REST du site, éditeur de blocs compris, le filtre rend
 * sa valeur sans rien lire. Il ne lève jamais.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

use G2RD\Connector\Commands\CommandExecutor;
use WP_REST_Request;

final class AfterResponseRunner {

	/** Fonctions de fin de réponse, dans l'ordre de préférence. */
	private const FINISHERS = [ 'litespeed_finish_request', 'fastcgi_finish_request' ];

	/** Paramètres d'adresse pour lesquels WordPress écrit la réponse sous une autre forme. */
	private const WRAPPING_PARAMS = [ '_envelope', '_jsonp' ];

	/** Instance partagée par la route d'acceptation et le filtre. */
	private static ?self $instance = null;

	/**
	 * Termine la réponse : vrai si elle est partie.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $terminator;

	/**
	 * Exécute la commande : son issue ({status, result?, error?, stray_output?}), ou null
	 * si la commande est inconnue.
	 *
	 * @var \Closure(string, array<mixed>|null): (array<string, mixed>|null)
	 */
	private \Closure $executor;

	/**
	 * Heure courante (secondes Unix).
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Écrit le JSON de la réponse.
	 *
	 * @var \Closure(string): void
	 */
	private \Closure $output;

	/**
	 * Exécution programmée pour la requête en cours ; null : aucune.
	 *
	 * @var array{command_id: string, command: string, payload: array<mixed>|null}|null
	 */
	private ?array $pending = null;

	/**
	 * @param (\Closure(): bool)|null $terminator Termine la réponse ; par défaut, vide les
	 *        tampons de sortie (flush_output_buffers()) puis appelle
	 *        litespeed_finish_request(), à défaut fastcgi_finish_request() (faux si aucune
	 *        n'existe).
	 * @param (\Closure(string, array<mixed>|null): (array<string, mixed>|null))|null $executor
	 *        Exécute la commande ; par défaut, CommandExecutor::run().
	 * @param (\Closure(): int)|null    $clock  Heure courante ; time() par défaut.
	 * @param (\Closure(string): void)|null $output Écrit le JSON ; par défaut, sur la sortie.
	 */
	public function __construct( ?\Closure $terminator = null, ?\Closure $executor = null, ?\Closure $clock = null, ?\Closure $output = null ) {
		$this->terminator = $terminator ?? static fn (): bool => self::finish_request();
		$this->executor   = $executor ?? static fn ( string $command, ?array $payload ): ?array => self::run_command( $command, $payload );
		$this->clock      = $clock ?? static fn (): int => time();
		$this->output     = $output ?? static function ( string $json ): void {
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- corps JSON de la réponse REST, écrit comme WP_REST_Server::serve_request().
		};
	}

	/** Instance partagée, aux dépendances par défaut, pour le branchement au démarrage. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Le serveur sait-il terminer une réponse avant la fin du script ? Sinon, la réponse ne
	 * part qu'à la fin de la commande, et la capacité `async_commands` n'est pas annoncée.
	 *
	 * @param (\Closure(string): bool)|null $exists Détection d'une fonction ;
	 *        function_exists() par défaut.
	 */
	public static function finisher_available( ?\Closure $exists = null ): bool {
		$exists = $exists ?? static fn ( string $name ): bool => function_exists( $name );
		foreach ( self::FINISHERS as $name ) {
			if ( $exists( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Programme l'exécution acceptée pour après la réponse de cette requête. Une seule
	 * par requête : le créneau d'exécution, pris à l'acceptation et non réentrant, refuse
	 * toute autre acceptation tant qu'elle le tient.
	 *
	 * @param string            $command_id Numéro de l'exécution, enregistrée et acceptée.
	 * @param string            $command    Commande admise en arrière-plan.
	 * @param array<mixed>|null $payload    Charge de la commande, null si absente.
	 */
	public function queue( string $command_id, string $command, ?array $payload ): void {
		$this->pending = [
			'command_id' => $command_id,
			'command'    => $command,
			'payload'    => $payload,
		];
	}

	/**
	 * Filtre `rest_pre_serve_request`, branché en dernier : sert la réponse, la termine,
	 * puis exécute la commande programmée (cf. l'en-tête de la classe).
	 *
	 * @param mixed $served  Vrai si un filtre précédent a déjà servi la réponse.
	 * @param mixed $result  Réponse à servir (WP_HTTP_Response).
	 * @param mixed $request Requête REST courante.
	 * @param mixed $server  Serveur REST (non utilisé).
	 * @return mixed `$served` tel quel sans exécution programmée ; vrai si le connecteur a
	 *               écrit la réponse ; sinon `$served`, et WordPress l'écrit.
	 */
	public function maybe_serve( mixed $served, mixed $result = null, mixed $request = null, mixed $server = null ): mixed {
		unset( $server );
		$run = $this->pending;
		if ( null === $run ) {
			return $served;
		}
		// Oubliée avant tout : une seule exécution par requête, même si la suite échoue.
		$this->pending = null;

		$served = $this->serve( $served, $result, $request, $run );
		$this->execute( $run );

		return $served;
	}

	/**
	 * Écrit la réponse si personne ne l'a servie, puis la termine si elle est servie.
	 * Ne lève jamais.
	 *
	 * @param array{command_id: string, command: string, payload: array<mixed>|null} $run
	 * @return mixed Vrai si le connecteur a écrit la réponse ; sinon `$served` tel quel :
	 *               WordPress l'écrit s'il est faux.
	 */
	private function serve( mixed $served, mixed $result, mixed $request, array $run ): mixed {
		try {
			ignore_user_abort( true );
			if ( ! $served && $this->write( $result, $request ) ) {
				$served = true;
			}
			if ( $served ) {
				$this->finish_response( $run );
			}
		} catch ( \Throwable ) {
			// Erreur avant ou pendant l'écriture : `$served` dit encore à WordPress s'il
			// doit l'écrire lui-même.
			return $served;
		}

		return $served;
	}

	/**
	 * Écrit le JSON de la réponse, comme WP_REST_Server::serve_request() (statut et
	 * en-têtes déjà posés). Faux si le connecteur ne l'écrit pas : WordPress l'écrira.
	 */
	private function write( mixed $result, mixed $request ): bool {
		if ( ! $result instanceof \WP_HTTP_Response || self::wordpress_wraps( $request ) ) {
			return false;
		}
		$json = wp_json_encode( $result->get_data() );
		if ( ! is_string( $json ) ) {
			return false;
		}
		( $this->output )( $json );

		return true;
	}

	/**
	 * `_envelope` ou `_jsonp` dans l'adresse : WordPress écrit la réponse sous une autre
	 * forme (lus dans $_GET par WP_REST_Server::serve_request(), copiés dans les
	 * paramètres d'adresse de la requête).
	 */
	private static function wordpress_wraps( mixed $request ): bool {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}
		$query = $request->get_query_params();

		return [] !== array_intersect( self::WRAPPING_PARAMS, array_keys( $query ) );
	}

	/**
	 * Termine la réponse. Échec (fonction absente, inopérante ou qui lève) : une ligne au
	 * journal PHP du site, et l'exécution continue.
	 *
	 * @param array{command_id: string, command: string, payload: array<mixed>|null} $run
	 */
	private function finish_response( array $run ): void {
		try {
			$finished = ( $this->terminator )();
		} catch ( \Throwable ) {
			$finished = false;
		}
		if ( $finished ) {
			return;
		}

		self::log(
			sprintf(
				'la réponse « reçue » de l’opération %1$s (commande %2$s) n’a pas pu être terminée avant son exécution : litespeed_finish_request() et fastcgi_finish_request() sont absentes, sans effet ou en erreur. L’opération s’exécute quand même, une seule fois ; la plateforme peut ne recevoir la réponse qu’à sa fin. Cause probable : serveur sans LiteSpeed ni PHP-FPM, ou réglage du serveur qui retient la réponse. Action : aucune pour cette opération ; si le message se répète, le signaler à l’équipe G2RD.',
				$run['command_id'],
				$run['command']
			)
		);
	}

	/**
	 * Démarre, exécute et conclut l'exécution, puis détache le signe de vie et rend le
	 * créneau, quoi qu'il arrive. Ne lève jamais.
	 *
	 * @param array{command_id: string, command: string, payload: array<mixed>|null} $run
	 */
	private function execute( array $run ): void {
		try {
			if ( $this->begin( $run ) ) {
				$this->perform( $run );
			}
		} catch ( \Throwable $e ) {
			// Dernier rempart : chaque étape rattrape déjà ses erreurs, seule une fonction
			// de WordPress filtrée par une extension qui lève (traduction…) arrive ici.
			// Résultat perdu : le ramassage déclarera l'exécution interrompue 600 s après
			// son dernier signe de vie, et la plateforme vérifiera l'état réel du site.
			unset( $e );
		} finally {
			self::clean_up( $run['command_id'] );
		}
	}

	/**
	 * Démarre l'exécution (accepted → running). Faux si ce processus ne l'a pas démarrée :
	 * rien ne doit alors s'exécuter, et une ligne au journal PHP du site dit pourquoi.
	 *
	 * @param array{command_id: string, command: string, payload: array<mixed>|null} $run
	 */
	private function begin( array $run ): bool {
		try {
			$started = RunLifecycle::start( $run['command_id'], ( $this->clock )() );
		} catch ( \Throwable $e ) {
			// Base qui lève, horloge en panne : rien ne dit que le démarrage est écrit.
			// Rien ne s'exécute : une exécution close par le ramassage (la plateforme
			// vérifie l'état réel du site) vaut mieux qu'une commande lancée sans
			// démarrage enregistré.
			unset( $e );
			$started = false;
		}
		if ( ! $started ) {
			self::log( self::not_started_message( $run['command_id'], $run['command'] ) );
		}

		return $started;
	}

	/**
	 * Exécute la commande démarrée, puis écrit son résultat. Résultat non enregistré (base
	 * qui refuse ou qui lève) : une ligne au journal PHP du site.
	 *
	 * @param array{command_id: string, command: string, payload: array<mixed>|null} $run
	 */
	private function perform( array $run ): void {
		$command_id = $run['command_id'];
		$command    = $run['command'];

		try {
			RunHeartbeat::attach( $command_id, $this->clock );
		} catch ( \Throwable $e ) {
			// Écouteurs non branchés : la commande, qui n'a pas encore commencé, s'exécute
			// quand même, et son résultat est le sien (jamais « appliquée en partie » pour
			// cette erreur-ci).
			unset( $e );
			self::log(
				sprintf(
					'le signe de vie de l’opération %1$s (commande %2$s) n’a pas pu être branché. L’opération s’exécute quand même ; si l’une de ses étapes dure plus de 10 minutes, elle sera déclarée interrompue en pleine activité, puis son résultat sera écrit à la fin. Cause probable : extension qui lève une erreur à l’ajout d’un crochet WordPress. Action : aucune pour cette opération ; si le message se répète, le signaler à l’équipe G2RD.',
					$command_id,
					$command
				)
			);
		}

		$error = null;
		try {
			$executed = ( $this->executor )( $command, $run['payload'] );
		} catch ( \Throwable $e ) {
			$executed = null;
			$error    = $e;
		}

		$status = null === $error && 'done' === ( $executed['status'] ?? null ) ? 'done' : 'failed';
		try {
			$outcome = null === $error ? $this->outcome( $command, $executed ) : $this->unexpected_error( $command, $error );
			$written = RunLifecycle::finish( $command_id, $outcome, ( $this->clock )() );
		} catch ( \Throwable $e ) {
			unset( $e );
			$written = false;
		}
		if ( ! $written ) {
			self::log(
				sprintf(
					'le résultat de l’opération %1$s (commande %2$s) n’a pas été enregistré : la commande s’est terminée (issue : %3$s), mais l’écriture de son résultat a échoué. L’opération sera déclarée interrompue (si ce n’est déjà fait) 10 minutes après son dernier signe de vie. Cause probable : base de données du site indisponible, ou qui refuse l’écriture à cet instant. Action : vérifier l’état réel du site (versions installées) depuis la plateforme, et la base de données du site si le message se répète.',
					$command_id,
					$command,
					$status
				)
			);
		}
	}

	/**
	 * Détache le signe de vie, puis rend le créneau, chacun rattrapé à part : le créneau
	 * est rendu même si le détachement lève, et rien ne remonte hors du filtre. Une base
	 * qui lève sur le rendu laisse le créneau pris : il est repris 600 s après son dernier
	 * signe de vie (ExecutionSlot, limite connue « rendu perdu »).
	 */
	private static function clean_up( string $command_id ): void {
		try {
			RunHeartbeat::detach();
		} catch ( \Throwable $e ) {
			// Écouteurs peut-être restés branchés : sans effet sur une exécution conclue,
			// un signe de vie n'écrit que sur une exécution en cours et sur un créneau
			// tenu par elle.
			unset( $e );
		}

		try {
			ExecutionSlot::release( $command_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Ligne du journal pour un démarrage perdu. L'exécution est relue pour en dire la
	 * cause : ramassée (abandonnée) entre la réponse et le démarrage, ou démarrage que la
	 * base n'a pas pris.
	 */
	private static function not_started_message( string $command_id, string $command ): string {
		try {
			$record = RunStore::read( $command_id );
			$state  = is_string( $record['state'] ?? null ) ? $record['state'] : null;
		} catch ( \Throwable $e ) {
			unset( $e );
			$state = null;
		}

		if ( RunState::ABANDONED === $state ) {
			return sprintf(
				'l’opération %1$s (commande %2$s) n’a pas été exécutée : elle a été déclarée abandonnée avant son démarrage, et rien n’a été modifié. Cause probable : processus PHP du site suspendu plus de 2 minutes entre l’envoi de la réponse et le démarrage (serveur surchargé). Action : relancer l’opération depuis la plateforme si besoin ; si le message se répète, le signaler à l’équipe G2RD.',
				$command_id,
				$command
			);
		}

		return sprintf(
			'l’opération %1$s (commande %2$s) n’a pas été exécutée : son démarrage n’a pas pu être enregistré (état relu : %3$s), et rien n’a été modifié. Cause probable : base de données du site indisponible, ou qui refuse l’écriture à cet instant. Action : vérifier la base de données du site (journal d’erreurs, espace disque), puis relancer l’opération depuis la plateforme si besoin.',
			$command_id,
			$command,
			null === $state ? 'illisible' : '« ' . $state . ' »'
		);
	}

	/**
	 * Issue de la commande → résultat enregistré, de la forme de la réponse de `/command`
	 * (CommandController::handle()) : {command, status, result, error, at, stray_output?},
	 * sans `signature_check` (signature toujours exigée sur ces routes). `status` vaut
	 * « done » ou « failed », comme l'état que RunLifecycle::finish() en tire.
	 *
	 * @param array<string, mixed>|null $executed Issue rendue par l'exécuteur ; null :
	 *                                            commande inconnue, rien n'a été exécuté.
	 * @return array<string, mixed>
	 */
	private function outcome( string $command, ?array $executed ): array {
		if ( null === $executed ) {
			return $this->failure(
				$command,
				sprintf(
					/* translators: %s: nom technique de la commande, par exemple update_plugin. */
					__( 'La commande « %s » n’a pas été exécutée : ce site ne sait pas l’exécuter. Rien n’a été modifié. Cause probable : la plateforme et le connecteur installé ne sont pas à la même version (commande nouvelle ou retirée), ou cette version du connecteur admet la commande en arrière-plan sans savoir encore l’exécuter (cas de la sonde de diagnostic async_probe). Action : mettre à jour le connecteur G2RD de ce site, puis relancer l’opération depuis la plateforme ; si le connecteur est déjà à jour, le signaler à l’équipe G2RD.', 'g2rd-connector' ),
					$command
				)
			);
		}

		$outcome = [
			'command' => $command,
			'status'  => 'done' === ( $executed['status'] ?? null ) ? 'done' : 'failed',
			'result'  => $executed['result'] ?? null,
			'error'   => $executed['error'] ?? null,
			'at'      => gmdate( 'c', ( $this->clock )() ),
		];
		// Diagnostic : ce qu'une extension tierce ou une notice PHP a écrit pendant la
		// commande, capturé par CommandExecutor.
		if ( isset( $executed['stray_output'] ) && '' !== $executed['stray_output'] ) {
			$outcome['stray_output'] = $executed['stray_output'];
		}

		return $outcome;
	}

	/**
	 * Résultat d'échec, de la même forme.
	 *
	 * @return array<string, mixed>
	 */
	private function failure( string $command, string $error ): array {
		return [
			'command' => $command,
			'status'  => 'failed',
			'result'  => null,
			'error'   => $error,
			'at'      => gmdate( 'c', ( $this->clock )() ),
		];
	}

	/**
	 * Résultat d'échec d'une exception levée pendant l'exécution de la commande (hors de
	 * CommandExecutor::run(), qui rattrape les siennes) : la commande a commencé, elle a
	 * pu être appliquée en partie.
	 *
	 * @return array<string, mixed>
	 */
	private function unexpected_error( string $command, \Throwable $error ): array {
		// Sans point final : la phrase en ajoute un. Message vide : le type de l'erreur.
		$detail = rtrim( trim( $error->getMessage() ), '.' );
		if ( '' === $detail ) {
			$detail = get_class( $error );
		}

		return $this->failure(
			$command,
			sprintf(
				/* translators: 1: nom technique de la commande, par exemple update_plugin ; 2: message de l'erreur. */
				__( 'La commande « %1$s » s’est arrêtée sur une erreur inattendue : %2$s. Elle a pu être appliquée en partie. Cause probable : erreur d’une extension ou du connecteur pendant l’exécution en arrière-plan. Action : vérifier l’état du site (versions installées, fonctionnement), puis relancer l’opération depuis la plateforme si besoin.', 'g2rd-connector' ),
				$command,
				$detail
			)
		);
	}

	/**
	 * Exécuteur par défaut : CommandExecutor::run(), tel quel.
	 *
	 * La sonde `async_probe` n'y est pas encore branchée : elle le sera sur AsyncProbe::run()
	 * à la tâche 23, avec un test « par défaut, la sonde est exécutée par AsyncProbe ».
	 * D'ici là, CommandExecutor ne la connaît pas : la route d'acceptation l'admet (constante
	 * posée), et l'exécution est conclue en échec explicite (cf. outcome()), rien n'étant
	 * modifié.
	 *
	 * @param array<mixed>|null $payload
	 * @return array<string, mixed>|null
	 */
	private static function run_command( string $command, ?array $payload ): ?array {
		return CommandExecutor::run( $command, $payload );
	}

	/**
	 * Termine la réponse : tampons de sortie vidés (flush_output_buffers()), puis
	 * litespeed_finish_request(), à défaut fastcgi_finish_request(). Appelées seulement si
	 * elles existent : PHP ne les définit que sous LiteSpeed (lsphp) et PHP-FPM.
	 *
	 * @return bool Vrai si la réponse est partie ; faux si aucune fonction n'existe, ou si
	 *              elle a échoué.
	 */
	private static function finish_request(): bool {
		self::flush_output_buffers();
		flush();

		if ( function_exists( 'litespeed_finish_request' ) ) {
			return (bool) litespeed_finish_request();
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			return (bool) fastcgi_finish_request();
		}

		return false;
	}

	/**
	 * Vide et ferme les tampons de sortie au-dessus du niveau `$floor`, du plus haut au plus
	 * bas : leur contenu descend d'un niveau à chaque fermeture, jusqu'à la sortie. La
	 * boucle s'arrête, sans notice, au premier tampon qu'on ne peut pas retirer (ouvert
	 * sans PHP_OUTPUT_HANDLER_REMOVABLE) ou que PHP refuse de fermer : la fonction de fin
	 * vide de force ce qui reste.
	 *
	 * Publique pour les tests, qui ouvrent leurs propres tampons au-dessus de ceux de
	 * PHPUnit ; seule la fin de réponse par défaut l'appelle.
	 *
	 * @param int $floor Niveau à ne pas franchir : 0 (tous les tampons) pour la réponse.
	 * @return int Niveau atteint (ob_get_level()).
	 */
	public static function flush_output_buffers( int $floor = 0 ): int {
		while ( ob_get_level() > $floor ) {
			$status = ob_get_status();
			$flags  = is_int( $status['flags'] ?? null ) ? $status['flags'] : 0;
			if ( 0 === ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_flush() ) {
				break;
			}
		}

		return ob_get_level();
	}

	/** Une ligne au journal PHP du site, pour le diagnostic. */
	private static function log( string $message ): void {
		error_log( 'G2RD Connector : ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic d'exploitation voulu (fin de réponse impossible, démarrage perdu, signe de vie non branché, résultat non enregistré), sans donnée sensible.
	}
}
