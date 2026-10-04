<?php
/**
 * Point d'entrée de la connexion directe depuis G2RD WP Manager :
 * `admin-ajax.php?action=g2rd_login&ticket=…` (spec connexion WordPress §5.5).
 *
 * Pourquoi admin-ajax : il reste joignable quand la page de connexion est déplacée
 * (WPS Hide Login, Solid Security…), contrairement à /wp-admin/. Même précédent que
 * Rollback\HealthEndpoint. Les deux actions, avec et sans session, sont servies : la
 * personne peut déjà être connectée au site sous un autre compte.
 *
 * Ticket accepté (DirectLogin\Gate) : session du compte choisi dans le manager,
 * SANS déclencher le hook `wp_login` — une extension de double authentification du
 * site redemanderait sinon un code et annulerait la connexion ; la double
 * authentification exigée côté manager la remplace (spec §9). Le connecteur trace
 * lui-même la connexion (événement `direct_login`), envoyé après la réponse pour ne
 * jamais retarder la redirection.
 *
 * Ticket refusé : page WordPress en 403 avec un message explicite. Le lien vers la
 * page de connexion n'y figure que si le ticket est authentique (signature valide) :
 * sans ticket, ou avec un ticket mal formé ou mal signé, l'adresse de connexion —
 * peut-être masquée par le client — n'est pas révélée (écart volontaire avec la
 * lettre de la spec §5.5). Toutes les réponses interdisent le cache et le référent :
 * le ticket est dans l'adresse.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\DirectLogin;

use G2RD\Connector\Outbound\ManagerClient;

final class Endpoint {

	public const ACTION = 'g2rd_login';

	/** Type d'événement remonté au manager, qui le range en SITE_EVENT_DIRECT_LOGIN. */
	public const EVENT_TYPE = 'direct_login';

	/**
	 * Événement de la connexion ouverte, en attente de la fin de la réponse.
	 *
	 * @var array{user_login: string, wp_user_id: int, manager_user_id: int}|null
	 */
	private static ?array $pending_event = null;

	public function register(): void {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ $this, 'respond' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'respond' ] );
	}

	public function respond(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- le ticket signé (HMAC, 60 s, usage unique) tient lieu de nonce ; assaini juste après, puis contrôlé caractère par caractère par DirectLoginTicket::verify().
		$raw    = isset( $_GET['ticket'] ) ? wp_unslash( $_GET['ticket'] ) : '';
		$ticket = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		self::send_private_headers();

		$decision = ( new Gate() )->check( $ticket, time() );
		if ( ! isset( $decision['user'], $decision['payload'] ) ) {
			self::refuse( $decision['code'] ?? Refusal::INVALID );
			return;
		}

		self::open_session( $decision['user'], $decision['payload'] );
	}

	/**
	 * Dans admin-ajax, wp_die() rend du texte brut (`_ajax_wp_die_handler`). Ce point
	 * d'entrée est ouvert dans un onglet : on veut la page d'erreur WordPress
	 * habituelle (et son lien vers la page de connexion quand il est permis).
	 */
	public static function html_die_handler(): string {
		return '_default_wp_die_handler';
	}

	/**
	 * Envoie au manager l'événement de la connexion ouverte, une fois la réponse
	 * partie (hook `shutdown`). Sans effet s'il n'y en a pas.
	 */
	public static function report_pending(): void {
		$context             = self::$pending_event;
		self::$pending_event = null;
		if ( null === $context ) {
			return;
		}

		// Libère le navigateur avant l'appel sortant (jusqu'à 15 s si le manager ne
		// répond pas) : la redirection vers le tableau de bord n'attend jamais.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		( new ManagerClient() )->send_event( self::EVENT_TYPE, $context );
	}

	private static function send_private_headers(): void {
		nocache_headers();
		// API officielle LiteSpeed Cache : ne jamais mettre cette réponse en cache.
		do_action( 'litespeed_control_set_nocache', 'g2rd-connector direct login' );
		if ( ! headers_sent() ) {
			// Le ticket est dans l'adresse : il ne doit jamais partir en référent.
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	private static function refuse( string $code ): void {
		add_filter( 'wp_die_ajax_handler', [ self::class, 'html_die_handler' ] );

		// Le lien vers la page de connexion n'est proposé que pour un ticket
		// authentique : sur un site à connexion masquée, wp_login_url() rend l'adresse
		// cachée, qu'un appel sans ticket (ou avec un ticket forgé) ne doit pas révéler.
		// Un seul appel à wp_die() : même si un gestionnaire personnalisé ne s'arrêtait
		// pas, le lien ne peut pas s'afficher pour un ticket non authentique.
		$args = [ 'response' => 403 ];
		if ( Refusal::allows_login_link( $code ) ) {
			$args['link_url']  = esc_url( wp_login_url() );
			$args['link_text'] = esc_html__( 'Aller à la page de connexion', 'g2rd-connector' );
		}

		wp_die(
			esc_html( Refusal::message( $code ) ),
			esc_html( Refusal::title() ),
			$args // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valeurs échappées ci-dessus.
		);
	}

	/**
	 * @param \WP_User                                        $user    Compte à ouvrir (contrôlé par Gate).
	 * @param array{s: int, u: int, e: int, n: string, a: int} $payload Charge du ticket.
	 */
	private static function open_session( \WP_User $user, array $payload ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );

		self::$pending_event = [
			'user_login'      => (string) $user->user_login,
			'wp_user_id'      => (int) $user->ID,
			'manager_user_id' => $payload['a'],
		];
		add_action( 'shutdown', [ self::class, 'report_pending' ] );

		wp_safe_redirect( admin_url() );
		exit;
	}
}
