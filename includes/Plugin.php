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
use G2RD\Connector\Events\Listener;
use G2RD\Connector\Rest\CommandController;
use G2RD\Connector\Rest\HealthController;
use G2RD\Connector\Rest\SnapshotController;
use G2RD\Connector\Updater\GitHubUpdater;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Health endpoint (public) — toujours actif pour permettre le ping initial.
		add_action( 'rest_api_init', [ new HealthController(), 'register' ] );

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

		// ── Gate "site enrôlé" : RIEN n'est exposé / envoyé tant que l'utilisateur
		// n'a pas explicitement enrôlé le site via la page admin. Conforme
		// Plugin Directory Guideline 7 (no phoning home without explicit consent).
		if ( ! Settings::is_enrolled() ) {
			return;
		}

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

	public static function activate(): void {
		Settings::ensure_defaults();
		// Le cron est planifié seulement à l'enrollment, pas à l'activation —
		// conforme guideline "no phoning home without consent".
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		HeartbeatJob::unschedule();
		flush_rewrite_rules();
	}
}
