<?php
/**
 * Listener des événements WordPress notables, repoussés en temps réel au manager.
 *
 * Événements observés :
 *   - wp_login                 → user.login
 *   - activated_plugin         → plugin.activated
 *   - deactivated_plugin       → plugin.deactivated
 *   - upgrader_process_complete → core.updated / plugin.updated / theme.updated
 *   - automatic_updates_complete → core.auto_update (avec rapport échecs)
 *   - wp_login_failed          → user.login_failed (sécurité)
 *
 * Les connexions échouées sont le seul événement qu'un tiers peut déclencher à
 * volonté (force brute, xmlrpc `system.multicall`) : elles partent avec une attente
 * plafonnée à 2 s (au lieu de 15), réponse non lue, et au plus
 * LoginFailedThrottle::MAX_PER_MINUTE fois par minute. Les autres événements
 * restent envoyés comme avant.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Events;

use G2RD\Connector\Outbound\ManagerClient;

final class Listener {

	private LoginFailedThrottle $login_failed_throttle;

	/**
	 * @param LoginFailedThrottle|null $login_failed_throttle Plafond des connexions échouées (remplacé par les tests).
	 */
	public function __construct( ?LoginFailedThrottle $login_failed_throttle = null ) {
		$this->login_failed_throttle = $login_failed_throttle ?? new LoginFailedThrottle();
	}

	public function register(): void {
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 1 );
		add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 1 );
		add_action( 'deactivated_plugin', [ $this, 'on_plugin_deactivated' ], 10, 1 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'automatic_updates_complete', [ $this, 'on_auto_updates' ], 10, 1 );
	}

	public function on_login( string $user_login, \WP_User $user ): void {
		$this->dispatch(
			'user.login',
			[
				'user_id'    => (int) $user->ID,
				'user_login' => $user_login,
				'roles'      => $user->roles,
			]
		);
	}

	/**
	 * Une tentative au-delà du plafond de la minute n'est ni envoyée ni comptée
	 * (cf. LoginFailedThrottle). Sinon l'événement part avec une attente plafonnée
	 * à 2 s (au lieu de 15), réponse non lue : la page de connexion (ou xmlrpc.php)
	 * attend la plateforme 2 s au plus.
	 */
	public function on_login_failed( string $user_login ): void {
		if ( ! $this->login_failed_throttle->allow() ) {
			return;
		}

		( new ManagerClient() )->send_event(
			'user.login_failed',
			[
				'user_login' => $user_login,
				'ip'         => $this->client_ip(),
			],
			false
		);
	}

	public function on_plugin_activated( string $plugin ): void {
		$this->dispatch( 'plugin.activated', [ 'file' => $plugin ] );
	}

	public function on_plugin_deactivated( string $plugin ): void {
		$this->dispatch( 'plugin.deactivated', [ 'file' => $plugin ] );
	}

	/**
	 * Hook upgrader_process_complete : capture le type de MAJ + items + acteur.
	 *
	 * v0.1.4 : on inclut désormais l'utilisateur WP qui a déclenché la MAJ
	 * (wp_get_current_user()). Si l'upgrade vient du cron WP auto-update, le
	 * user current est anonyme — on stocke alors 'cron' comme acteur.
	 *
	 * @param array<string, mixed> $hook_extra
	 */
	public function on_upgrader_complete( \WP_Upgrader $upgrader, array $hook_extra ): void {
		unset( $upgrader ); // param impose par le filter, non utilise

		$type   = (string) ( $hook_extra['type'] ?? '' );
		$action = (string) ( $hook_extra['action'] ?? '' );
		if ( '' === $type || 'update' !== $action ) {
			return;
		}

		$this->dispatch(
			$type . '.updated',
			[
				'type'  => $type,
				'items' => $hook_extra[ $type . 's' ] ?? ( $hook_extra[ $type ] ?? null ),
				'actor' => $this->current_actor(),
			]
		);
	}

	/**
	 * Identifie l'utilisateur WP qui a declenche l'action en cours, ou 'cron'
	 * si l'action vient du planificateur automatique.
	 *
	 * @return array<string, mixed>
	 */
	private function current_actor(): array {
		if ( wp_doing_cron() ) {
			return [
				'type' => 'cron',
			];
		}
		$user = wp_get_current_user();
		if ( 0 === (int) $user->ID ) {
			return [
				'type' => 'unknown',
			];
		}
		return [
			'type'       => 'user',
			'user_id'    => (int) $user->ID,
			'user_login' => (string) $user->user_login,
			'user_email' => (string) $user->user_email,
			'roles'      => array_values( (array) $user->roles ),
		];
	}

	/**
	 * @param array<string, mixed> $results
	 */
	public function on_auto_updates( array $results ): void {
		$summary = [];
		foreach ( $results as $type => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			$failed = 0;
			foreach ( $items as $item ) {
				if ( isset( $item->result ) && is_wp_error( $item->result ) ) {
					++$failed;
				}
			}
			$summary[ $type ] = [
				'total'  => count( $items ),
				'failed' => $failed,
			];
		}
		$this->dispatch( 'core.auto_update', [ 'summary' => $summary ] );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function dispatch( string $type, array $context ): void {
		// Envoi bloquant (délai de 15 s), erreur ignorée : un manager injoignable ne
		// casse pas WordPress. Les connexions échouées passent par on_login_failed().
		( new ManagerClient() )->send_event( $type, $context );
	}

	private function client_ip(): string {
		$candidates = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' ];
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			$ip  = trim( explode( ',', $raw )[0] );
			$ip  = filter_var( $ip, FILTER_VALIDATE_IP );
			if ( false !== $ip ) {
				return $ip;
			}
		}
		return '';
	}
}
