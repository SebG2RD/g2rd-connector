<?php
/**
 * Refus des deux routes d'exécution en arrière-plan (`POST /g2rd/v1/command-runs`,
 * `GET /g2rd/v1/command-runs/<numéro>`), § 3.3 et § 3.6 de la spécification.
 *
 * Chaque refus porte un code stable, `g2rd_connector_<code>`, que la plateforme lit pour
 * décider de la suite (attendre, repasser en synchrone, conclure) : ne pas les renommer.
 * Le statut HTTP est celui du contrat. Le message, en français, suit le format de
 * HeartbeatJob::refusal_message() : quoi, cause probable, action, sans détail interne.
 * La plateforme rédige ses propres phrases FR/EN à partir du code ; ce message sert au
 * journal et à qui lit la réponse.
 *
 * Les refus de jeton et de signature (401, 403) restent ceux d'Auth.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class Refusal {

	/**
	 * 409 : une autre exécution en arrière-plan tient le créneau, ou une mise à jour
	 * protégée (ou sa reprise par le cron du site) est en cours. Rien n'est enregistré.
	 */
	public const SITE_BUSY = 'site_busy';

	/** 409 : numéro déjà reçu avec un autre corps. Rien n'est exécuté. */
	public const COMMAND_ID_CONFLICT = 'command_id_conflict';

	/** 400 : numéro absent ou mal formé (RunStore::ID_PATTERN). */
	public const INVALID_COMMAND_ID = 'invalid_command_id';

	/** 400 : commande inconnue, exclue (AsyncCommands::EXCLUDED), ou sonde sans sa constante. */
	public const COMMAND_NOT_ASYNC = 'command_not_async';

	/** 400 : charge qui n'est pas un objet, ou durée de la sonde hors bornes. */
	public const INVALID_PAYLOAD = 'invalid_payload';

	/** 413 : corps de plus de 16 Ko, refusé avant d'être décodé. */
	public const BODY_TOO_LARGE = 'body_too_large';

	/** 503 : constante `G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS` posée. Rien n'est exécuté. */
	public const ASYNC_DISABLED = 'async_disabled';

	/**
	 * 404 : numéro inconnu du site. Distinct de la 404 `rest_no_route` de WordPress, que
	 * rend un connecteur sans la route.
	 */
	public const RUN_UNKNOWN = 'run_unknown';

	/**
	 * Statut HTTP de chaque refus.
	 *
	 * @var array<string, int>
	 */
	private const STATUSES = [
		self::SITE_BUSY           => 409,
		self::COMMAND_ID_CONFLICT => 409,
		self::INVALID_COMMAND_ID  => 400,
		self::COMMAND_NOT_ASYNC   => 400,
		self::INVALID_PAYLOAD     => 400,
		self::BODY_TOO_LARGE      => 413,
		self::ASYNC_DISABLED      => 503,
		self::RUN_UNKNOWN         => 404,
	];

	/**
	 * Le refus `$code`, prêt à être rendu par une route.
	 *
	 * Données rendues : `status`, et pour SITE_BUSY seulement `busy_with` (numéro de
	 * l'exécution qui tient le créneau, nul pour une mise à jour protégée) et `busy_since`
	 * (ISO 8601 UTC, comme les autres dates des routes ; nul si inconnue). Ces deux clés
	 * sont toujours présentes dans un SITE_BUSY.
	 *
	 * @param string               $code Un des codes ci-dessus.
	 * @param array<string, mixed> $data Pour SITE_BUSY : `busy_with` (chaîne) et
	 *                                   `busy_since` (secondes Unix). Le refus
	 *                                   d'ExecutionSlot::acquire() se passe tel quel.
	 *                                   Toute autre clé, d'un autre type, ou passée avec
	 *                                   un autre code, est ignorée : rien d'interne ne
	 *                                   sort par ici.
	 * @throws \InvalidArgumentException Code inconnu : erreur de programmation, jamais
	 *                                   changée en une réponse que la plateforme
	 *                                   interpréterait.
	 */
	public static function error( string $code, array $data = [] ): \WP_Error {
		if ( ! isset( self::STATUSES[ $code ] ) ) {
			throw new \InvalidArgumentException( 'Code de refus inconnu : ' . esc_html( $code ) );
		}

		$public = [ 'status' => self::STATUSES[ $code ] ];
		if ( self::SITE_BUSY !== $code ) {
			return new \WP_Error( 'g2rd_connector_' . $code, self::message( $code ), $public );
		}

		$busy_with            = $data['busy_with'] ?? null;
		$busy_since           = $data['busy_since'] ?? null;
		$busy_since           = is_int( $busy_since ) ? $busy_since : null;
		$public['busy_with']  = is_string( $busy_with ) ? $busy_with : null;
		$public['busy_since'] = null === $busy_since ? null : gmdate( 'c', $busy_since );

		return new \WP_Error( 'g2rd_connector_' . $code, self::busy_message( $busy_since ), $public );
	}

	/**
	 * Site occupé : la date n'est donnée que si elle est connue.
	 *
	 * @param int|null $since Prise du créneau, ou ouverture de la transaction (secondes Unix).
	 */
	private static function busy_message( ?int $since ): string {
		if ( null === $since ) {
			return __( 'L’opération n’a pas été lancée : une autre opération est en cours sur ce site. Rien n’a été modifié. Action : aucune, la plateforme réessaie seule.', 'g2rd-connector' );
		}

		return sprintf(
			/* translators: %s: date et heure UTC du début de l'opération en cours, par exemple 2026-10-16 14:00:00 UTC. */
			__( 'L’opération n’a pas été lancée : une autre opération est en cours sur ce site depuis %s. Rien n’a été modifié. Action : aucune, la plateforme réessaie seule.', 'g2rd-connector' ),
			gmdate( 'Y-m-d H:i:s', $since ) . ' UTC'
		);
	}

	/**
	 * Message des refus autres que SITE_BUSY. Un code ajouté à STATUSES sans son message
	 * lève une erreur (vue par les tests), au lieu d'emprunter le message d'un autre refus.
	 *
	 * @throws \InvalidArgumentException Code sans message : erreur de programmation.
	 */
	private static function message( string $code ): string {
		return match ( $code ) {
			self::COMMAND_ID_CONFLICT => __( 'L’opération n’a pas été lancée : ce numéro de commande a déjà servi à une autre commande sur ce site. Rien n’a été modifié par cette demande, et la commande déjà reçue sous ce numéro n’a pas été exécutée une seconde fois. Cause probable : erreur de la plateforme. Action : signaler ce refus au support G2RD.', 'g2rd-connector' ),
			self::INVALID_COMMAND_ID => __( 'L’opération n’a pas été lancée : son numéro de commande est absent ou mal formé. Rien n’a été modifié. Cause probable : requête altérée en chemin, ou plateforme incompatible avec cette version du connecteur. Action : relancer l’opération depuis la plateforme ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::COMMAND_NOT_ASYNC => __( 'L’opération n’a pas été lancée : ce site ne peut pas l’exécuter en arrière-plan. Rien n’a été modifié. Cause probable : commande inconnue de cette version du connecteur, commande réservée à l’exécution immédiate, ou sonde de diagnostic non activée sur ce site. Action : mettre à jour le connecteur G2RD sur ce site, puis relancer l’opération ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::INVALID_PAYLOAD => __( 'L’opération n’a pas été lancée : ses paramètres sont incomplets ou d’un format inattendu (pour la sonde de diagnostic : une durée de 10 à 600 secondes, par pas de 10). Rien n’a été modifié. Cause probable : requête altérée en chemin, ou plateforme incompatible avec cette version du connecteur. Action : relancer l’opération depuis la plateforme ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::BODY_TOO_LARGE => __( 'L’opération n’a pas été lancée : la demande reçue dépasse la taille admise (16 Ko). Rien n’a été modifié. Cause probable : paramètres anormalement volumineux, ou requête altérée en chemin. Action : relancer l’opération depuis la plateforme ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::ASYNC_DISABLED => __( 'L’opération n’a pas été lancée : l’exécution en arrière-plan est désactivée sur ce site (constante G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS dans wp-config.php). Rien n’a été modifié. Action : aucune, la plateforme passe par l’exécution habituelle ; pour réactiver l’exécution en arrière-plan, retirer cette constante de wp-config.php.', 'g2rd-connector' ),
			self::RUN_UNKNOWN => __( 'Opération inconnue du site : aucune exécution en arrière-plan n’y porte ce numéro. Cause probable : connecteur réinstallé, base de données restaurée, ou compte rendu déjà effacé (le site garde les 200 derniers, 7 jours au plus). Action : aucune, la plateforme vérifie l’état réel du site à la prochaine synchronisation.', 'g2rd-connector' ),
			default => throw new \InvalidArgumentException( 'Code de refus sans message : ' . esc_html( $code ) ),
		};
	}
}
