<?php
/**
 * Point d'assemblage du module Rollback : construit les collaborateurs avec les
 * chemins WordPress réels, et permet aux tests de les remplacer d'un bloc.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class Services {

	private static ?self $override = null;

	public function __construct(
		public readonly RestorePointStore $store,
		public readonly Snapshotter $snapshotter,
		public readonly PluginRestorer $restorer,
		public readonly HealthChecker $health,
		public readonly string $plugins_root,
	) {
	}

	public static function make(): self {
		if ( null !== self::$override ) {
			return self::$override;
		}
		$store = new RestorePointStore();
		$root  = rtrim( (string) WP_PLUGIN_DIR, '/\\' );
		return new self( $store, new Snapshotter( $store, $root ), new PluginRestorer( $root ), new HealthChecker(), $root );
	}

	/**
	 * Le module peut-il fonctionner sur ce site ? Système de fichiers direct (nos
	 * opérations n'ont pas d'équivalent FTP) et une bibliothèque zip disponible.
	 */
	public static function supported(): bool {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return 'direct' === get_filesystem_method() && Snapshotter::is_supported();
	}

	/** Réservé aux tests. */
	public static function override( ?self $services ): void {
		self::$override = $services;
	}
}
