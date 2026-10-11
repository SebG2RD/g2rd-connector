<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

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
		];
	}
}
