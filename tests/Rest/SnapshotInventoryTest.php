<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use G2RD\Connector\PhpErrors\LoggingProbe;

/**
 * Forme de l'inventaire (GET /snapshot) : ce que lit la plateforme ne change pas
 * avec l'allègement du connecteur. Test de caractérisation, écrit sur le code
 * d'avant l'allègement et joué tel quel après.
 */
final class SnapshotInventoryTest extends SnapshotTestCase {

	public function test_la_forme_json_de_l_inventaire_est_inchangee(): void {
		$this->update_transients_checked( 3600 );

		self::assertSame( self::expected_shape(), self::shape( $this->snapshot() ) );
	}

	/** Même forme quand tout est rafraîchi (transients absents). */
	public function test_la_forme_est_la_meme_apres_un_rafraichissement(): void {
		self::assertSame( self::expected_shape(), self::shape( $this->snapshot() ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function expected_shape(): array {
		return [
			'site'              => [
				'name'                 => 'string',
				'url'                  => 'string',
				'admin_email'          => 'string',
				'language'             => 'string',
				'timezone'             => 'string',
				'multisite'            => 'bool',
				'site_icon_url'        => 'string',
				'connector_version'    => 'string',
				'login_url'            => 'string',
				'direct_login_enabled' => 'bool',
				'admins'               => 'list',
			],
			'wp_core'           => [
				'version_installed' => 'string',
				'version_latest'    => 'string',
				'has_update'        => 'bool',
			],
			'plugins'           => [
				'list' => [
					'file'              => 'string',
					'slug'              => 'string',
					'name'              => 'string',
					'version_installed' => 'string',
					'version_latest'    => 'string',
					'is_active'         => 'bool',
					'has_update'        => 'bool',
					'plugin_uri'        => 'string',
					'author'            => 'string',
				],
			],
			'themes'            => [
				'list' => [
					'stylesheet'        => 'string',
					'name'              => 'string',
					'version_installed' => 'string',
					'version_latest'    => 'string',
					'is_active'         => 'bool',
					'has_update'        => 'bool',
					'theme_uri'         => 'string',
					'author'            => 'string',
					// Seul ajout (1.14, cf. SnapshotThemeOriginTest) : origine réelle du thème.
					'wporg'             => 'bool',
				],
			],
			'translations'      => [
				'installed_languages' => [ 'list' => 'string' ],
				'site_language'       => 'string',
				'updates_available'   => 'list',
				'updates_count'       => 'int',
			],
			'server'            => [
				'php_version'     => 'string',
				'wp_version'      => 'string',
				'server_software' => 'string',
				'mysql_version'   => 'string',
				'memory_limit'    => 'string',
				'max_execution'   => 'int',
				'upload_max_size' => 'string',
			],
			'db_health'         => [
				'spam_comments'         => 'int',
				'post_revisions'        => 'int',
				'trashed_posts'         => 'int',
				'expired_transients'    => 'int',
				'autoloaded_options_mb' => 'float',
				'db_overhead_mb'        => 'float',
				'collected_at'          => 'string',
			],
			'updates_discovery' => [
				'captured_at'   => 'string',
				'stale'         => 'bool',
				'cron_disabled' => 'bool',
				'next_run'      => 'null',
			],
			'capabilities'      => [ 'list' => 'string' ],
			'restore_points'    => [
				'items'        => 'list',
				'count'        => 'int',
				'total_bytes'  => 'int',
				'dir_writable' => 'bool',
			],
			'pending_outcomes'  => 'list',
			// Phase 4 des sauvegardes (tâche 31) : bloc `backup` d'un site aux sauvegardes éteintes.
			'backup'            => [
				'version'              => 'int',
				'enabled'              => 'bool',
				'available'            => 'bool',
				'disabled_by_constant' => 'bool',
				'engine'               => 'string',
				'signing_public_key'   => 'null',
				'signing_key_id'       => 'null',
				'fingerprint'          => 'null',
				'site_key'             => 'null',
				'state'                => [
					'phase'            => 'string',
					'backup_id'        => 'null',
					'type'             => 'null',
					'scope'            => 'null',
					'segments_written' => 'int',
					'pending'          => 'int',
					'slice_started_at' => 'null',
				],
				'last'                 => 'null',
			],
			'environment'       => [
				'version'                => 'int',
				'php_sapi'               => 'string',
				'extensions'             => [
					'sodium'  => 'bool',
					'openssl' => 'bool',
					'zip'     => 'bool',
					'zlib'    => 'bool',
					'mysqli'  => 'bool',
				],
				// Seul type qui dépend du PHP qui joue le test : la version n'est lue que si sodium est natif.
				'sodium_library_version' => extension_loaded( 'sodium' ) ? 'string' : 'null',
				'exec_available'         => 'bool',
				'memory_limit_bytes'     => 'int',
				'max_execution_seconds'  => 'int',
				'disk'                   => [
					'status'      => 'string',
					'free_bytes'  => 'int',
					'total_bytes' => 'int',
				],
				'sizes'                  => [
					'status'           => 'string',
					'measured_at'      => 'null',
					'wp_content_bytes' => 'null',
					'uploads_bytes'    => 'null',
					'files_counted'    => 'null',
					'db_bytes'         => 'null',
					'db_status'        => 'null',
					'scan_ms'          => 'null',
				],
			],
			'php_errors'        => [
				'version'      => 'int',
				'enabled'      => 'bool',
				'coverage'     => 'string',
				'sources'      => 'list',
				'collected_at' => 'null',
				'truncated'    => 'bool',
				'groups'       => 'list',
			],
			// Lu dans le PHP qui joue le test : seule la taille du journal dépend du poste.
			'php_logging'       => [
				'version'            => 'int',
				'log_errors'         => self::shape( ( new LoggingProbe() )->describe()['log_errors'] ?? null ),
				'display_errors'     => self::shape( ( new LoggingProbe() )->describe()['display_errors'] ?? null ),
				'wp_debug'           => 'bool',
				'wp_debug_log'       => 'bool',
				'wp_debug_display'   => 'null',
				'error_log'          => 'string',
				'error_log_readable' => self::shape( ( new LoggingProbe() )->describe()['error_log_readable'] ?? null ),
				'error_log_bytes'    => self::shape( ( new LoggingProbe() )->describe()['error_log_bytes'] ?? null ),
			],
		];
	}
}
