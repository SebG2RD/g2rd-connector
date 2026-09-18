<?php
/**
 * Empêche WordPress de réinstaller seul une version qu'on vient de retirer.
 *
 * Après un rollback (automatique ou manuel), les mises à jour automatiques de
 * WordPress réappliqueraient la version cassée dans les 12 heures. On mémorise
 * donc, par plugin, la version bloquée : tant que c'est celle proposée, le filtre
 * `auto_update_plugin` répond non. Dès qu'une version plus récente sort, le
 * blocage tombe tout seul — la version suivante corrige peut-être le problème.
 *
 * Ne touche qu'aux mises à jour AUTOMATIQUES : une campagne lancée depuis le
 * manager reste possible (elle prendra un nouveau point de restauration).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class AutoUpdateGuard {

	public const OPTION_KEY = 'g2rd_blocked_versions';

	public function register(): void {
		add_filter( 'auto_update_plugin', [ $this, 'filter' ], 20, 2 );
	}

	/**
	 * Bloque `$version` pour `$plugin_file`.
	 */
	public static function block( string $plugin_file, string $version ): void {
		$blocked                 = self::all();
		$blocked[ $plugin_file ] = $version;
		update_option( self::OPTION_KEY, $blocked, false );
	}

	public static function unblock( string $plugin_file ): void {
		$blocked = self::all();
		if ( isset( $blocked[ $plugin_file ] ) ) {
			unset( $blocked[ $plugin_file ] );
			update_option( self::OPTION_KEY, $blocked, false );
		}
	}

	/**
	 * @return array<string, string> plugin_file => version bloquée
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$blocked = [];
		foreach ( $stored as $file => $version ) {
			if ( is_string( $file ) && is_string( $version ) && '' !== $version ) {
				$blocked[ $file ] = $version;
			}
		}
		return $blocked;
	}

	/**
	 * Filtre `auto_update_plugin`.
	 *
	 * @param mixed $update Décision courante (bool|null).
	 * @param mixed $item   Objet de mise à jour (->plugin, ->new_version).
	 * @return mixed
	 */
	public function filter( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->plugin, $item->new_version ) ) {
			return $update;
		}
		$file    = (string) $item->plugin;
		$offered = (string) $item->new_version;
		$blocked = self::all()[ $file ] ?? null;
		if ( null === $blocked ) {
			return $update;
		}

		if ( version_compare( $offered, $blocked, '>' ) ) {
			// Une version plus récente existe : le blocage a fait son temps.
			self::unblock( $file );
			return $update;
		}
		if ( version_compare( $offered, $blocked, '=' ) ) {
			return false;
		}
		return $update;
	}
}
