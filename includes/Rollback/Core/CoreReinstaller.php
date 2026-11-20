<?php
/**
 * Réinstallation d'une version publiée de WordPress depuis wordpress.org, par l'outil de mise à jour
 * de WordPress lui-même (spécification du 19/11/2026, § 4.3) : paquet téléchargé et vérifié par
 * CorePackage, offre `reinstall` de même forme que celles de l'API, Core_Upgrader::upgrade() sur ce
 * chemin local (WP_Upgrader::download_package() rend tel quel un fichier local qui existe), puis
 * fichiers comparés aux empreintes de wordpress.org. update_core() met le site en maintenance pendant
 * la copie (`.maintenance`), comme pour toute mise à jour, et lance la mise à niveau de la base, sans
 * effet quand sa version n'a pas changé (wp_upgrade()). Ni le connecteur ni ce module ne touchent à
 * `wp-content` ; WordPress y copie seulement les traductions d'un paquet traduit.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback\Core;

use Automatic_Upgrader_Skin;
use Core_Upgrader;
use G2RD\Connector\Rollback\UpdateTransaction;
use WP_Upgrader;

final class CoreReinstaller {

	/**
	 * @param (\Closure(): void)|null $before_copy Appelée une fois le paquet téléchargé et vérifié, juste
	 *                                            avant la copie : contrôles de l'appelant à refaire après
	 *                                            un téléchargement de plusieurs minutes. Ce qu'elle lève
	 *                                            arrête tout (paquet supprimé, rien de copié).
	 * @return string Langue du paquet réinstallé (`$locale`, ou `en_US` en repli).
	 * @throws CoreRollbackRefused BUSY (verrou `core_updater` de WordPress tenu), WPORG_UNREACHABLE,
	 *                             INTEGRITY, FAILED, ou le refus de `$before_copy`.
	 */
	public static function reinstall( string $version, string $locale, ?\Closure $before_copy = null ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = CorePackage::fetch( $version, $locale );
		try {
			// Signe de vie après le téléchargement (SHA-1 et empreintes : 30 s chacun, paquet : 300 s au
			// plus) : la copie repart avec 10 minutes devant elle avant qu'une reprise tienne la
			// transaction pour morte (UpdateTransaction::STALE_AFTER_SECONDS). Sans effet hors transaction.
			UpdateTransaction::touch();
			if ( null !== $before_copy ) {
				$before_copy();
			}
			// Marqueur « fichiers copiés », comme CommandExecutor::perform_core_upgrade() : update_core()
			// émet `_core_updated_successfully` une fois la copie faite, avant `upgrader_process_complete`.
			$copied      = false;
			$mark_copied = static function () use ( &$copied ): void {
				$copied = true;
			};
			add_action( '_core_updated_successfully', $mark_copied, PHP_INT_MIN );
			try {
				$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
				// pre_check_md5 à faux : il ne sert qu'à choisir un paquet partiel, exclu pour `reinstall`.
				$result = $upgrader->upgrade( self::offer( $version, $package['locale'], $package['path'] ), [ 'pre_check_md5' => false ] );
			} catch ( \Throwable $e ) {
				/** @var bool $copied Passé à vrai par référence, dans $mark_copied. */
				if ( ! $copied ) {
					// Classe seulement : le texte d'une exception peut porter un chemin du serveur.
					throw CoreRollbackRefused::because( CoreRollbackRefused::FAILED, get_class( $e ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
				}
				// Exception APRÈS la copie (crochet d'une extension sur `upgrader_process_complete`) : la
				// version d'avant est en place, les empreintes tranchent. Verrou `core_updater` rendu ici,
				// WordPress ne le rendant qu'après ce crochet (sinon `locked` pendant 15 minutes) ; il
				// appartient à cet upgrade(), qui vient de le prendre.
				WP_Upgrader::release_lock( 'core_updater' );
				$result = $version;
			} finally {
				remove_action( '_core_updated_successfully', $mark_copied, PHP_INT_MIN );
			}
		} finally {
			// Supprimé par WP_Upgrader::unpack_package() une fois décompressé ; reste quand
			// Core_Upgrader s'arrête avant (verrou, système de fichiers), ou sur une erreur ou un
			// refus survenu entre le téléchargement et la copie.
			if ( file_exists( $package['path'] ) ) {
				wp_delete_file( $package['path'] );
			}
		}
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			throw CoreRollbackRefused::because( 'locked' === $code ? CoreRollbackRefused::BUSY : CoreRollbackRefused::FAILED, 'WordPress upgrader: ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		if ( ! is_string( $result ) ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::FAILED, 'WordPress upgrader: no file system access' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		$bad = CorePackage::mismatched_files( $package['checksums'], ABSPATH );
		if ( [] !== $bad ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::INTEGRITY, count( $bad ) . ' file(s) differ, first: ' . $bad[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		return $package['locale'];
	}

	/**
	 * Offre de même forme que celles de l'API de wordpress.org (version-check 1.7, offre traduite) :
	 * Core_Upgrader::upgrade() lit `response`, les cinq clés de `packages` et `version` ; `locale` est
	 * gardée pour le journal.
	 */
	private static function offer( string $version, string $locale, string $package ): object {
		return (object) [
			'response' => 'reinstall',
			'version'  => $version,
			'locale'   => $locale,
			'packages' => (object) [
				'full'        => $package,
				'no_content'  => false,
				'new_bundled' => false,
				'partial'     => false,
				'rollback'    => false,
			],
		];
	}
}
