<?php
/**
 * Interrupteur des sauvegardes sur ce site : réglage `backup_enabled` (faux par défaut, case des
 * réglages du connecteur, décision P4-1) et constante d'arrêt `G2RD_CONNECTOR_DISABLE_BACKUP`
 * (wp-config.php), lue à chaque appel. Capacité `encrypted_backup` annoncée quand les sauvegardes
 * sont allumées, les commandes à distance permises et AES-256-GCM disponible.
 *
 * Multisite : une sauvegarde couvre toute l'installation (tables `base_prefix` de tous les blogs,
 * fichiers d'ABSPATH ; clés et état rangés sur le site principal, cf. State\MainSite). Seul le site
 * principal du réseau peut donc l'allumer, même règle que la lecture du journal PHP partagé
 * (PhpErrors\LoggingProbe). Sur un site secondaire, la case est sans effet : ni capacité, ni
 * commande, ni clé créée, ni état du réseau dans le snapshot ; l'administrateur d'un blog n'ouvre
 * jamais une sauvegarde du réseau vers sa propre agence.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup;

use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Settings;

final class BackupSwitch {

	public const DISABLE_CONSTANT = 'G2RD_CONNECTOR_DISABLE_BACKUP';

	public const CAPABILITY = 'encrypted_backup';

	/** Réglages des sauvegardes, sans effet hors du site principal d'un réseau. */
	public const NETWORK_SETTINGS = [ 'backup_enabled', 'force_ipv4_to_storage' ];

	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && (bool) constant( self::DISABLE_CONSTANT );
	}

	/**
	 * Site secondaire d'un réseau multisite (tout site du réseau autre que le principal).
	 */
	public static function secondary_site(): bool {
		return function_exists( 'is_multisite' )
			&& function_exists( 'is_main_site' )
			&& true === is_multisite()
			&& true !== is_main_site();
	}

	/**
	 * Le site secondaire est lu en dernier : case décochée (cas courant), aucun appel multisite.
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'backup_enabled' ) && ! self::disabled_by_constant() && ! self::secondary_site();
	}

	public static function capability_available(): bool {
		return self::enabled() && (bool) Settings::get( 'remote_commands_enabled' ) && Aead::available();
	}
}
