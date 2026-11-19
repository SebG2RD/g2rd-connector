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
 * Même règle pour le cœur de WordPress (connecteur 1.18.0, clé CORE, filtre
 * `auto_update_core`), bloqué par Rollback\Core après un retour à la version
 * d'avant : WordPress installe seul les versions mineures par défaut et
 * réinstallerait la version retirée au passage suivant du cron (sous 12 h). Pas
 * dans la même requête : le verrou `core_updater`, tenu jusqu'après
 * wp_version_check( $stats ) (class-core-upgrader.php, WordPress 6.4.0
 * l. 259/262), y fait échouer la mise à jour automatique en `locked`, sautée
 * sans nouvel essai (class-wp-automatic-updater.php 6.4.0 l. 468-477).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class AutoUpdateGuard {

	public const OPTION_KEY = 'g2rd_blocked_versions';

	/**
	 * Clé du cœur de WordPress dans la liste : jamais celle d'une extension, dont le
	 * fichier finit toujours par `.php`.
	 */
	public const CORE = 'wordpress';

	public function register(): void {
		add_filter( 'auto_update_plugin', [ $this, 'filter' ], 20, 2 );
		add_filter( 'auto_update_core', [ $this, 'filter_core' ], 20, 2 );
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
		return self::decide( $update, (string) $item->plugin, (string) $item->new_version );
	}

	/**
	 * Filtre `auto_update_core` (WP_Automatic_Updater::should_update(),
	 * wp-admin/includes/class-wp-automatic-updater.php, WordPress 6.4.0 et 7.1.5
	 * l. 268) : l'offre porte la version proposée dans `current` (l. 221).
	 *
	 * @param mixed $update Décision courante (bool|null).
	 * @param mixed $item   Offre de mise à jour du cœur (->current).
	 * @return mixed
	 */
	public function filter_core( $update, $item ) {
		if ( ! is_object( $item ) || ! isset( $item->current ) ) {
			return $update;
		}
		return self::decide( $update, self::CORE, (string) $item->current );
	}

	/**
	 * @param mixed $update Décision courante (bool|null).
	 * @return mixed
	 */
	private static function decide( $update, string $key, string $offered ) {
		$blocked = self::all()[ $key ] ?? null;
		if ( null === $blocked ) {
			return $update;
		}

		if ( version_compare( $offered, $blocked, '>' ) ) {
			// Une version plus récente existe : le blocage a fait son temps.
			self::unblock( $key );
			return $update;
		}
		if ( version_compare( $offered, $blocked, '=' ) ) {
			return false;
		}
		return $update;
	}
}
