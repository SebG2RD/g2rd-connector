<?php
/**
 * Plafond local des événements « connexion échouée » (`user.login_failed`).
 *
 * En force brute, chaque mot de passe essayé déclenchait un appel à la plateforme
 * (jusqu'à 896 événements par jour mesurés sur un site ; par xmlrpc
 * `system.multicall`, plusieurs essais dans une seule requête). La plateforme
 * démarrait à chaque fois, sur le même serveur que les sites du parc.
 *
 * Au plus MAX_PER_MINUTE événements partent par minute calendaire et par site
 * (sous la limite de 120 par minute de la plateforme). Au-delà, les tentatives de
 * la minute ne sont ni envoyées ni comptées : le compteur n'est plus réécrit (une
 * écriture de transient coûte deux écritures d'options sans cache objet, et
 * xmlrpc `system.multicall` enchaîne des centaines d'essais par requête). Il
 * repart de zéro à la minute suivante.
 *
 * Pas d'événement récapitulatif : le format existant n'en prévoit pas. Les types
 * d'événements du connecteur (`user.login`, `user.login_failed`,
 * `plugin.activated`…) décrivent chacun UN fait ; un `user.login_failed` portant un
 * nombre serait compté par la plateforme comme une seule tentative, avec un
 * identifiant vide. Un récapitulatif demanderait un accord côté plateforme
 * (nouveau type ou nouveau champ interprété) : il n'est pas inventé ici.
 *
 * Plafond « souple » : deux requêtes simultanées peuvent lire le même compteur ;
 * quelques événements de plus peuvent alors partir dans la minute, jamais un par
 * tentative comme avant.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Events;

final class LoginFailedThrottle {

	/** Événements « connexion échouée » envoyés au plus par minute et par site. */
	public const MAX_PER_MINUTE = 30;

	/** Compteur de la minute en cours : `{window: int, count: int}`. */
	public const TRANSIENT_KEY = 'g2rd_connector_login_failed_window';

	/** Durée d'une fenêtre : la minute calendaire. */
	private const WINDOW_SECONDS = 60;

	/**
	 * Durée de vie du compteur : deux fenêtres, puis il disparaît seul (aucune
	 * ligne qui s'accumule en base).
	 */
	private const TRANSIENT_TTL = 2 * self::WINDOW_SECONDS;

	/** @var \Closure(): int */
	private \Closure $clock;

	/**
	 * @param (\Closure(): int)|null $clock Heure courante (secondes Unix) ; `time()` par défaut. Remplacée par les tests.
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Dit si l'événement d'une tentative échouée peut partir, et le compte s'il part.
	 * Plafond atteint : refus, sans aucune écriture.
	 */
	public function allow(): bool {
		$window = intdiv( ( $this->clock )(), self::WINDOW_SECONDS );
		$state  = get_transient( self::TRANSIENT_KEY );

		$count = 0;
		if ( is_array( $state ) && isset( $state['window'], $state['count'] ) && (int) $state['window'] === $window ) {
			$count = (int) $state['count'];
		}
		if ( $count >= self::MAX_PER_MINUTE ) {
			return false;
		}
		++$count;

		set_transient(
			self::TRANSIENT_KEY,
			[
				'window' => $window,
				'count'  => $count,
			],
			self::TRANSIENT_TTL
		);

		return true;
	}
}
