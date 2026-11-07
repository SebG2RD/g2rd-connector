<?php
/**
 * Exécute une opération dans la table des options du site principal. En multisite, l'état de
 * sauvegarde vaut pour toute l'installation (fichiers et base sont communs au réseau) : un seul
 * état, lu et écrit dans la table du site principal quel que soit le blog qui reçoit la commande.
 * CommandRuns\OptionRow ne connaît que {$wpdb->options}, la table du blog courant ; d'où le
 * passage par switch_to_blog() puis restore_current_blog() dans un finally (modèle de
 * Environment\SizeReport::uploads_dir()). Pas de variante sur {$wpdb->sitemeta}, qui n'a pas de clé
 * unique sur meta_key (« INSERT IGNORE » n'y garantirait plus un seul créateur).
 *
 * Limite écrite : ExecutionSlot et UpdateTransaction restent par blog.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\State;

final class MainSite {

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public static function run( callable $operation ): mixed {
		if ( ! self::must_switch() ) {
			return $operation();
		}

		switch_to_blog( get_main_site_id() );
		try {
			return $operation();
		} finally {
			restore_current_blog();
		}
	}

	private static function must_switch(): bool {
		return function_exists( 'is_multisite' )
			&& is_multisite()
			&& function_exists( 'get_main_site_id' )
			&& get_current_blog_id() !== get_main_site_id();
	}
}
