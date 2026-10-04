<?php
/**
 * Refus de la connexion directe, côté site (spec connexion WordPress §5.5).
 *
 * Codes propres au connecteur (le manager ne les voit pas : il ne reçoit rien
 * d'un refus). Chaque message dit ce qui se passe, la cause probable et quoi
 * faire ; il s'affiche dans une page WordPress (wp_die, HTTP 403) qui propose
 * aussi un lien vers la page de connexion du site.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\DirectLogin;

use G2RD\Connector\Security\DirectLoginTicket;

final class Refusal {

	public const INVALID    = 'invalid';
	public const WRONG_SITE = 'wrong_site';
	public const EXPIRED    = 'expired';
	public const FUTURE     = 'future';
	public const REPLAYED   = 'replayed';
	public const DISABLED   = 'disabled';
	public const NOT_ADMIN  = 'not_admin';

	/**
	 * Refus correspondant au verdict de la classe pure. Forme et signature se
	 * confondent volontairement : rien n'est révélé à qui fabrique des tickets.
	 */
	public static function from_ticket_status( string $status ): string {
		return match ( $status ) {
			DirectLoginTicket::STATUS_WRONG_SITE => self::WRONG_SITE,
			DirectLoginTicket::STATUS_EXPIRED    => self::EXPIRED,
			DirectLoginTicket::STATUS_FUTURE     => self::FUTURE,
			default                              => self::INVALID,
		};
	}

	public static function message( string $code ): string {
		return match ( $code ) {
			self::WRONG_SITE => __( 'Ce lien de connexion a été préparé pour un autre site. Vérifiez dans G2RD WP Manager que la fiche ouverte correspond bien à ce site, puis relancez la connexion.', 'g2rd-connector' ),
			self::EXPIRED    => __( 'Ce lien de connexion a expiré. Il n\'est valable qu\'une minute : relancez la connexion depuis G2RD WP Manager.', 'g2rd-connector' ),
			self::FUTURE     => __( 'Ce lien de connexion n\'est pas encore valable : l\'horloge de ce serveur semble en retard. Relancez la connexion depuis G2RD WP Manager ; si ce message revient, demandez à l\'hébergeur de remettre le serveur à l\'heure.', 'g2rd-connector' ),
			self::REPLAYED   => __( 'Ce lien de connexion a déjà été utilisé. Chaque lien ne sert qu\'une fois : relancez la connexion depuis G2RD WP Manager.', 'g2rd-connector' ),
			self::DISABLED   => __( 'La connexion directe est désactivée sur ce site. Un administrateur peut la réactiver dans les réglages de G2RD Connector ; en attendant, connectez-vous avec votre identifiant et votre mot de passe.', 'g2rd-connector' ),
			self::NOT_ADMIN  => __( 'Ce compte n\'a plus les droits d\'administrateur sur ce site. Il a pu être supprimé ou rétrogradé : lancez une synchronisation du site dans G2RD WP Manager puis choisissez un autre compte, ou connectez-vous avec votre identifiant et votre mot de passe.', 'g2rd-connector' ),
			default          => __( 'Ce lien de connexion n\'est pas valide. Il a pu être tronqué lors d\'un copier-coller, ou le site a été reconnecté à G2RD WP Manager entre-temps : relancez la connexion depuis G2RD WP Manager.', 'g2rd-connector' ),
		};
	}

	public static function title(): string {
		return __( 'Connexion directe impossible', 'g2rd-connector' );
	}
}
