<?php
/**
 * Refus et échecs à code stable du retour arrière du cœur de WordPress : commande `rollback_core`,
 * et retour automatique d'une mise à jour protégée du cœur (connecteur 1.18.0, spécification du
 * 19/11/2026, § 4.2). Le code part au manager, qui le traduit ; la phrase anglaise suit la règle
 * « quoi, cause probable, action » et ne sert qu'au journal.
 *
 * `core_rollback_failed` s'ajoute aux six codes de la spécification : l'outil de mise à jour de
 * WordPress a rendu une erreur pendant la réinstallation (copie, système de fichiers).
 *
 * Construite par because(), qui refuse un code inconnu. Chaque `throw` porte l'exception à la règle
 * d'échappement de PHPCS (WordPress.Security.EscapeOutput.ExceptionNotEscaped) : la phrase est fixe,
 * le détail court, et rien n'est affiché en HTML.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback\Core;

final class CoreRollbackRefused extends \RuntimeException {

	public const EXPIRED           = 'core_rollback_expired';
	public const VERSION_CHANGED   = 'core_rollback_version_changed';
	public const DB_CHANGED        = 'core_rollback_db_changed';
	public const BUSY              = 'core_rollback_busy';
	public const WPORG_UNREACHABLE = 'core_rollback_wporg_unreachable';
	public const INTEGRITY         = 'core_rollback_integrity';
	public const FAILED            = 'core_rollback_failed';

	private const MESSAGES = [
		self::EXPIRED           => 'WordPress was not rolled back: this site no longer keeps the previous version of this update (the rollback period is over, or the update was not protected). Nothing was changed. Restore a backup taken before the update if the previous version is still needed.',
		self::VERSION_CHANGED   => 'WordPress was not rolled back: the installed version is no longer the one this update installed (WordPress was updated or reinstalled since). Nothing was changed. Synchronise the site to read its current version.',
		self::DB_CHANGED        => 'WordPress was not rolled back: the database version (db_version) is not the one from before the update, or could not be read, so the previous version may not work with it. Nothing was changed. Restore a backup taken before the update if needed.',
		self::BUSY              => 'WordPress was not rolled back yet: another protected update, or the WordPress core update lock, is running on this site. Nothing was changed. Send the request again in a few minutes.',
		self::WPORG_UNREACHABLE => 'WordPress was not rolled back: the previous version could not be fetched from wordpress.org (site unable to reach it, package not found, or unexpected answer). Nothing was changed. Check that the site can reach wordpress.org, then try again.',
		self::INTEGRITY         => 'WordPress could not be rolled back cleanly: the downloaded package or the reinstalled files do not match the checksums published by wordpress.org. Reinstall WordPress from Dashboard > Updates ("Re-install version") on the site.',
		self::FAILED            => 'WordPress could not be rolled back: the WordPress upgrader reported an error while reinstalling the previous version (copy or file system). Check that the site works and its version in Dashboard > Updates; reinstall WordPress from there if needed.',
	];

	private function __construct( private readonly string $error_code, string $detail ) {
		parent::__construct( self::MESSAGES[ $error_code ] . ( '' === $detail ? '' : ' (' . $detail . ')' ) );
	}

	/**
	 * @param string $error_code Une des constantes de la classe.
	 * @param string $detail     Précision technique pour le journal (code de WordPress, fichier).
	 * @throws \InvalidArgumentException Code inconnu : erreur de programmation.
	 */
	public static function because( string $error_code, string $detail = '' ): self {
		if ( ! isset( self::MESSAGES[ $error_code ] ) ) {
			throw new \InvalidArgumentException( 'unknown core rollback refusal code (programming error: use a CoreRollbackRefused constant)' );
		}
		return new self( $error_code, $detail );
	}

	public function error_code(): string {
		return $this->error_code;
	}
}
