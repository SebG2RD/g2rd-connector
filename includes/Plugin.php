<?php
/**
 * Boot du plugin G2RD Connector.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector;

use G2RD\Connector\Admin\Page;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Cron\UpdatesDiscoveryJob;
use G2RD\Connector\Events\Listener;
use G2RD\Connector\Rest\AdminController;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Rest\HealthController;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Updater\GitHubUpdater;
use G2RD\Connector\Updates\PremiumUpdatesBridge;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Health endpoint (public) — toujours actif pour permettre le ping initial.
		add_action( 'rest_api_init', [ new HealthController(), 'register' ] );

		// Endpoints d'admin LOCALE (save / enroll / unenroll), gardés par capability
		// `manage_options` + nonce REST. Enregistrés AVANT le gate d'enrôlement :
		// l'app React doit pouvoir enrôler un site pas encore enrôlé. Aucune requête
		// sortante n'est émise sans clic explicite de l'admin.
		add_action( 'rest_api_init', [ new AdminController(), 'register' ] );

		// Empêche tout cache de page (LiteSpeed / LSCWP) de mettre en cache nos
		// réponses REST. Cf. prevent_rest_cache() : sinon le manager reçoit un
		// inventaire FIGÉ → « mises à jour fantômes » survivant aux resyncs.
		add_filter( 'rest_pre_dispatch', [ $this, 'prevent_rest_cache' ], 10, 3 );

		// GitHub Updater — toujours actif (avant le gate enrollment) pour que les
		// sites avec plugin installé reçoivent les MAJ via Plugins → Mises à jour.
		// C'est de la lecture sortante vers api.github.com uniquement, pas vers
		// le manager : conforme wp.org Guideline 7 (pas de phoning home avant opt-in
		// au service externe — GitHub étant un service public open source neutre).
		( new GitHubUpdater() )->register();

		// Admin (page d'options, tab dans Options G2RD ou menu top-level).
		if ( is_admin() ) {
			( new Page() )->register();
		}

		// Migration de sécurité : ré-chiffre un site_token encore stocké en clair
		// (installations antérieures au chiffrement au repos). No-op une fois fait.
		Settings::maybe_migrate_token();

		// ── Gate "site enrôlé" : RIEN n'est exposé / envoyé tant que l'utilisateur
		// n'a pas explicitement enrôlé le site via la page admin. Conforme
		// Plugin Directory Guideline 7 (no phoning home without explicit consent).
		if ( ! Settings::is_enrolled() ) {
			return;
		}

		// Capture des MAJ annoncées par des updaters tiers. Certains ne
		// s'enregistrent pas dans le contexte REST (mesuré : SEOPRESS_Updater est
		// actif en wp-admin et en WP-CLI, absent en REST) — leurs entrées sont
		// donc mémorisées depuis l'administration, puis rejouées par le snapshot
		// et par les commandes de mise à jour. Cf. PremiumUpdatesBridge.
		//
		// Enregistré APRÈS le gate d'enrôlement (aucune écriture avant opt-in
		// explicite, guideline 7) et sous is_admin() : c'est le seul contexte où
		// il y a quelque chose à capturer.
		if ( is_admin() ) {
			( new PremiumUpdatesBridge() )->register();
		}

		// Découverte des MAJ en contexte WP-Cron, où les updaters tiers acceptent
		// de s'enregistrer (SEOPress teste explicitement DOING_CRON). C'est ce qui
		// affranchit le manager de toute visite humaine du wp-admin — la raison
		// d'être d'une console de parc.
		//
		// Enregistré SANS condition de réglage, contrairement au heartbeat : savoir
		// ce qui est à jour ne doit pas dépendre d'une option d'émission vers le
		// manager. La planification est réparée à chaque boot, sinon les sites déjà
		// installés — précisément ceux qui ont besoin du correctif — ne rejoueraient
		// jamais le hook d'activation. Après le gate d'enrôlement (guideline 7).
		( new UpdatesDiscoveryJob() )->register();
		UpdatesDiscoveryJob::schedule();

		// Endpoints REST sécurisés Bearer SiteToken (consommés par le manager).
		add_action( 'rest_api_init', [ new SnapshotController(), 'register' ] );
		if ( Settings::get( 'remote_commands_enabled' ) ) {
			add_action( 'rest_api_init', [ new CommandController(), 'register' ] );
		}

		// Outbound : cron heartbeat (opt-in via Settings).
		if ( Settings::get( 'heartbeat_enabled' ) ) {
			( new HeartbeatJob() )->register();
		}

		// Outbound : events temps réel (opt-in via Settings).
		if ( Settings::get( 'events_enabled' ) ) {
			( new Listener() )->register();
		}
	}

	/**
	 * Désactive la mise en cache des réponses REST du connecteur par LiteSpeed
	 * (LSCWP) et les caches de page serveur.
	 *
	 * Les endpoints `g2rd/v1` sont authentifiés par Bearer SiteToken : aux yeux
	 * d'un cache de page, ils ressemblent à des GET anonymes cacheables. LiteSpeed
	 * sert alors une réponse FIGÉE au manager (inventaire périmé → « mises à jour
	 * fantômes » qui survivent aux resyncs, car le PHP du connecteur — y compris
	 * tout son cache-busting interne — ne s'exécute même pas sur un cache HIT).
	 * On signale donc « ne pas cacher » via l'action officielle LSCWP et l'en-tête
	 * lu par LiteSpeed Web Server, dès qu'une route du namespace est servie.
	 *
	 * @param mixed            $result  Court-circuit éventuel du dispatch (inchangé).
	 * @param \WP_REST_Server  $server  Serveur REST (non utilisé).
	 * @param \WP_REST_Request $request Requête REST courante.
	 * @return mixed
	 */
	public function prevent_rest_cache( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( 0 === strpos( (string) $request->get_route(), '/' . G2RD_CONNECTOR_REST_NS ) ) {
			// API officielle LiteSpeed Cache : opt-out de cette réponse.
			do_action( 'litespeed_control_set_nocache', 'g2rd-connector dynamic REST response' );
			// Défense en profondeur : en-têtes lus au niveau LiteSpeed Web Server.
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
			}
		}
		return $result;
	}

	public static function activate(): void {
		Settings::ensure_defaults();
		// Le cron est planifié seulement à l'enrollment, pas à l'activation —
		// conforme guideline "no phoning home without consent".
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		HeartbeatJob::unschedule();
		UpdatesDiscoveryJob::unschedule();
		flush_rewrite_rules();
	}
}
