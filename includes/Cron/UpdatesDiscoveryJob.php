<?php
/**
 * Job WP-Cron : découverte des mises à jour, sans visite d'administration.
 *
 * Raison d'être
 * -------------
 * Certains updaters tiers refusent de s'enregistrer hors d'un contexte
 * privilégié. SEOPress PRO l'écrit noir sur blanc :
 *
 *     if ( ! current_user_can( 'manage_options' ) && ! $doing_cron && ! $doing_cli ) {
 *         return;
 *     }
 *
 * La requête REST du connecteur n'est aucun des trois. WP-Cron, si — et c'est le
 * contexte que WordPress emploie lui-même pour les mises à jour automatiques :
 * tout updater qui prétend les supporter doit y fonctionner. Ce job y force donc
 * une vraie re-vérification, puis fige le résultat dans le cache du pont, que le
 * contexte REST rejouera.
 *
 * Sans lui, la détection dépendait d'une visite humaine du wp-admin — ce qui vide
 * de son sens une console de gestion de parc.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Cron;

use G2RD\Connector\Updates\PremiumUpdatesBridge;

final class UpdatesDiscoveryJob {

	public const HOOK = 'g2rd_connector_refresh_updates';

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * @internal Callback WordPress — public par nécessité.
	 */
	public function run(): void {
		PremiumUpdatesBridge::refresh_in_privileged_context();
	}

	/**
	 * Planifie l'événement s'il manque.
	 *
	 * Appelée à l'activation ET à chaque boot : les installations existantes ne
	 * rejouent pas le hook d'activation lors d'une simple mise à jour du plugin,
	 * or ce sont précisément celles qui ont besoin du correctif. L'appel est un
	 * no-op dès que l'événement existe.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'twicedaily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/** Prochaine exécution planifiée, `null` si aucune. */
	public static function next_run(): ?string {
		$timestamp = wp_next_scheduled( self::HOOK );

		return $timestamp ? gmdate( 'c', $timestamp ) : null;
	}

	/**
	 * Demande une découverte immédiate et réveille le cron.
	 *
	 * WP-Cron ne se déclenche que sur une requête HTTP : un site client à faible
	 * trafic peut rester muet des heures. Le snapshot du manager, lui, arrive
	 * toutes les heures — il sert donc de déclencheur. `spawn_cron()` part en
	 * requête non bloquante : la réponse du snapshot n'attend pas.
	 */
	public static function request_now(): void {
		// Idempotent sans garde à nous : WordPress refuse un événement ponctuel
		// identique (même hook, mêmes arguments) planifié à moins de dix minutes
		// d'un événement déjà en attente. Deux snapshots rapprochés n'en créent
		// donc qu'un. `spawn_cron()` a de son côté un verrou de 60 s.
		wp_schedule_single_event( time(), self::HOOK );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
