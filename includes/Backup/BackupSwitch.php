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
 * jamais une sauvegarde du réseau vers sa propre agence. Sur le site principal, seul un
 * super-administrateur change ces réglages (network_lock()) : l'administrateur du site principal
 * n'est pas propriétaire des données des autres blogs.
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

	public const LOCK_SECONDARY = 'secondary';

	public const LOCK_SUPER_ADMIN = 'super_admin';

	/**
	 * Pourquoi les réglages des sauvegardes ne peuvent pas être changés ici : `secondary` (site
	 * secondaire d'un réseau), `super_admin` (réseau multisite, utilisateur qui n'est pas
	 * super-administrateur : une sauvegarde couvre tout le réseau, ses tables et wp-config.php,
	 * que l'administrateur d'un blog n'atteint jamais), null (réglable). Hors multisite : null.
	 */
	public static function network_lock(): ?string {
		if ( ! function_exists( 'is_multisite' ) || true !== is_multisite() ) {
			return null;
		}
		if ( self::secondary_site() ) {
			return self::LOCK_SECONDARY;
		}
		return function_exists( 'is_super_admin' ) && true === is_super_admin() ? null : self::LOCK_SUPER_ADMIN;
	}

	/**
	 * Retire d'un lot de réglages ceux que l'utilisateur ne peut pas changer ici : la valeur
	 * enregistrée est gardée. Même règle pour le panneau React et le formulaire PHP.
	 *
	 * @param array<string, mixed> $settings Réglages à enregistrer.
	 * @return array<string, mixed>
	 */
	public static function without_locked_settings( array $settings ): array {
		$locked = array_intersect_key( $settings, array_flip( self::NETWORK_SETTINGS ) );
		if ( [] !== $locked && null !== self::network_lock() ) {
			return array_diff_key( $settings, $locked );
		}
		return $settings;
	}

	/**
	 * Phrase affichée à la place de l'aide quand le réglage est verrouillé (quoi, cause, action).
	 */
	public static function lock_message( ?string $lock ): ?string {
		if ( self::LOCK_SECONDARY === $lock ) {
			return __( 'Sur un réseau multisite, les sauvegardes se règlent sur le site principal : une sauvegarde couvre tout le réseau, ce réglage n\'a donc pas d\'effet sur ce site. Action : ouvrez les réglages du connecteur du site principal.', 'g2rd-connector' );
		}
		if ( self::LOCK_SUPER_ADMIN === $lock ) {
			return __( 'Sur un réseau multisite, seul un super-administrateur peut régler les sauvegardes : elles couvrent tout le réseau. Action : demandez au super-administrateur du réseau de les régler depuis le site principal.', 'g2rd-connector' );
		}
		return null;
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
