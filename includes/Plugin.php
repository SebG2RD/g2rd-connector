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

final class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function boot(): void {
        // REST API (consommée par le manager).
        add_action( 'rest_api_init', [ new SnapshotController(), 'register' ] );
        add_action( 'rest_api_init', [ new HealthController(), 'register' ] );
        add_action( 'rest_api_init', [ new CommandController(), 'register' ] );

        // Admin (page d'options, tab dans Options G2RD ou menu top-level).
        if ( is_admin() ) {
            ( new Page() )->register();
        }

        // Cron (heartbeat horaire).
        ( new HeartbeatJob() )->register();

        // Listeners événements WP (login, plugin install, update fail, etc.).
        ( new Listener() )->register();
    }

    public static function activate(): void {
        Settings::ensure_defaults();
        HeartbeatJob::schedule();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        HeartbeatJob::unschedule();
        flush_rewrite_rules();
    }
}
