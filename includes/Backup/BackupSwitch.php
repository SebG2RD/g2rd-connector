<?php
/**
 * Interrupteur des sauvegardes sur ce site : réglage `backup_enabled` (faux par défaut, case des
 * réglages du connecteur, décision P4-1) et constante d'arrêt `G2RD_CONNECTOR_DISABLE_BACKUP`
 * (wp-config.php), lue à chaque appel. Capacité `encrypted_backup` annoncée quand les sauvegardes
 * sont allumées, les commandes à distance permises et AES-256-GCM disponible.
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

	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && (bool) constant( self::DISABLE_CONSTANT );
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'backup_enabled' ) && ! self::disabled_by_constant();
	}

	public static function capability_available(): bool {
		return self::enabled() && (bool) Settings::get( 'remote_commands_enabled' ) && Aead::available();
	}
}
