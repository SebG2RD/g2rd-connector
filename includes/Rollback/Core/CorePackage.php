<?php
/**
 * Paquet d'une version publiée de WordPress, repris sur wordpress.org pour le retour arrière du cœur
 * (spécification du 19/11/2026, § 4.3). Rien n'est gardé : le paquet est un fichier temporaire de
 * WordPress (download_url()), supprimé une fois installé, ou dès un refus.
 *
 * - Adresse : `https://downloads.wordpress.org/release/wordpress-{version}.zip` en anglais (`en_US`),
 *   `…/release/{locale}/wordpress-{version}.zip` sinon (relevé du 19/11/2026 : `release/en_US/`
 *   répond 404). Hôte fixe ; version et langue au format strict : rien de ce que le manager envoie
 *   n'entre dans l'adresse sans contrôle.
 * - Langue du paquet installé : `$wp_local_package` (posée par le `version.php` d'un paquet traduit),
 *   sinon `en_US`, celle que WordPress utilise pour ses propres empreintes.
 * - Existence et intégrité : `<paquet>.sha1` publié par downloads.wordpress.org ; 404 = paquet de cette
 *   langue absent, repli `en_US` (les traductions installées restent en place). Le paquet téléchargé
 *   doit avoir ce SHA-1, sinon il est supprimé avant toute installation. WordPress ne vérifie plus la
 *   signature des paquets du cœur depuis 6.6 (et ne pouvait rien prouver avant : échec souple, aucune
 *   clé de confiance, aucun `.sig` publié) : ce contrôle est le seul avant la copie.
 * - Empreintes des fichiers (`api.wordpress.org/core/checksums/1.0/`), lues AVANT le téléchargement,
 *   en https seulement : get_core_checksums() repasse en http quand https échoue et coupe à 3 s.
 *
 * Limite connue : sur Hostinger, une extension « must-use » renvoie les requêtes vers
 * api.wordpress.org à son propre serveur ; s'il ne sert pas les empreintes, le retour arrière est
 * refusé avant tout changement (`core_rollback_wporg_unreachable`).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback\Core;

final class CorePackage {

	public const HOST = 'downloads.wordpress.org';

	public const DEFAULT_LOCALE = 'en_US';

	private const CHECKSUMS_API = 'https://api.wordpress.org/core/checksums/1.0/';

	/** `7.1.4`, `6.4` : versions publiées seulement (ni `-beta`, ni `-RC`, ni `-src`). */
	private const VERSION_PATTERN = '/\A\d{1,3}\.\d{1,3}(?:\.\d{1,3})?\z/';

	/** `fr_FR`, `de_DE_formal`, `pt_PT_ao90`, `ary` : forme des langues de WordPress, sans `/` ni `.`. */
	private const LOCALE_PATTERN = '/\A[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]{1,12})?\z/';

	/** Délai des petites requêtes (SHA-1 du paquet, empreintes des fichiers). */
	private const SMALL_TIMEOUT = 30;

	/** Délai du téléchargement : celui que WordPress donne à download_url() pour ses paquets. */
	private const DOWNLOAD_TIMEOUT = 300;

	/** Bornes des réponses : empreintes (330 Ko pour 7.1.4), `.sha1` (41 octets). */
	private const CHECKSUMS_MAX_BYTES = 4194304;
	private const SHA1_MAX_BYTES      = 1024;

	public static function is_version( string $version ): bool {
		return 1 === preg_match( self::VERSION_PATTERN, $version );
	}

	public static function is_locale( string $locale ): bool {
		return 1 === preg_match( self::LOCALE_PATTERN, $locale );
	}

	/** Langue du paquet installé : `$wp_local_package` s'il est bien formé, sinon `en_US`. */
	public static function installed_locale(): string {
		global $wp_local_package;
		return is_string( $wp_local_package ) && self::is_locale( $wp_local_package ) ? $wp_local_package : self::DEFAULT_LOCALE;
	}

	/**
	 * @throws \InvalidArgumentException Version ou langue mal formée : erreur de programmation
	 *                                   (l'appelant les contrôle d'abord).
	 */
	public static function url( string $version, string $locale ): string {
		if ( ! self::is_version( $version ) || ! self::is_locale( $locale ) ) {
			throw new \InvalidArgumentException( 'invalid WordPress version or locale for a package address (programming error: check them with is_version() and is_locale() first)' );
		}
		$folder = self::DEFAULT_LOCALE === $locale ? '' : $locale . '/';
		return 'https://' . self::HOST . '/release/' . $folder . 'wordpress-' . $version . '.zip';
	}

	/**
	 * Télécharge et vérifie le paquet de `$version` (langue `$locale`, sinon `en_US`) et lit les
	 * empreintes de ses fichiers. Rien n'est installé ici.
	 *
	 * @return array{path: string, locale: string, checksums: array<string, string>}
	 * @throws CoreRollbackRefused WPORG_UNREACHABLE (wordpress.org injoignable, paquet introuvable,
	 *                             réponse inattendue) ou INTEGRITY (paquet différent de son SHA-1) ;
	 *                             aucun fichier n'est alors laissé sur le disque.
	 */
	public static function fetch( string $version, string $locale ): array {
		[ $used, $sha1 ] = self::published_package( $version, $locale );
		$checksums       = self::checksums( $version, $used );

		$path = download_url( self::url( $version, $used ), self::DOWNLOAD_TIMEOUT );
		if ( is_wp_error( $path ) ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'download: ' . $path->get_error_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		$actual = sha1_file( $path );
		if ( false === $actual || ! hash_equals( $sha1, $actual ) ) {
			wp_delete_file( $path );
			throw CoreRollbackRefused::because( CoreRollbackRefused::INTEGRITY, 'package SHA-1 differs from the one published by ' . self::HOST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}

		return [
			'path'      => $path,
			'locale'    => $used,
			'checksums' => $checksums,
		];
	}

	/**
	 * Fichiers du cœur absents ou différents de leur empreinte, `wp-content/` exclu (comme le
	 * contrôle de update_core() après la copie). Une entrée dont le chemin remonte (`..`) ou part de
	 * la racine compte comme différente, sans être lue.
	 *
	 * @param array<string, string> $checksums Chemin relatif ⇒ md5.
	 * @return list<string>
	 */
	public static function mismatched_files( array $checksums, string $root ): array {
		$root = rtrim( $root, '/\\' ) . '/';
		$bad  = [];
		foreach ( $checksums as $file => $md5 ) {
			if ( str_starts_with( $file, 'wp-content/' ) ) {
				continue;
			}
			// Comme update_core() : .html/.txt de la racine jamais contrôlés ; wp-config-sample.php absent toléré, car des extensions de sécurité les suppriment sur upgrader_process_complete (All-In-One Security).
			if ( ! str_contains( $file, '/' ) && in_array( pathinfo( $file, PATHINFO_EXTENSION ), [ 'html', 'txt' ], true ) ) {
				continue;
			}
			if ( 'wp-config-sample.php' === $file && ! file_exists( $root . $file ) ) {
				continue;
			}
			if ( str_contains( $file, '..' ) || str_starts_with( $file, '/' ) || ! is_file( $root . $file ) || md5_file( $root . $file ) !== $md5 ) {
				$bad[] = $file;
			}
		}
		return $bad;
	}

	/**
	 * @return array{0: string, 1: string} Langue retenue et SHA-1 publié.
	 * @throws CoreRollbackRefused WPORG_UNREACHABLE.
	 */
	private static function published_package( string $version, string $locale ): array {
		foreach ( array_values( array_unique( [ $locale, self::DEFAULT_LOCALE ] ) ) as $candidate ) {
			$sha1 = self::published_sha1( self::url( $version, $candidate ) );
			if ( null !== $sha1 ) {
				return [ $candidate, $sha1 ];
			}
		}
		throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'package not found: WordPress ' . $version ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
	}

	/**
	 * SHA-1 publié pour ce paquet ; null si le paquet n'existe pas (404).
	 *
	 * @throws CoreRollbackRefused WPORG_UNREACHABLE.
	 */
	private static function published_sha1( string $package_url ): ?string {
		$response = wp_safe_remote_get(
			$package_url . '.sha1',
			[
				'timeout'             => self::SMALL_TIMEOUT,
				'limit_response_size' => self::SHA1_MAX_BYTES,
			]
		);
		if ( is_wp_error( $response ) ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'sha1: ' . $response->get_error_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return null;
		}
		$sha1 = strtolower( trim( (string) wp_remote_retrieve_body( $response ) ) );
		if ( 200 !== $code || 1 !== preg_match( '/\A[0-9a-f]{40}\z/', $sha1 ) ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'sha1: HTTP ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		return $sha1;
	}

	/**
	 * @return array<string, string>
	 * @throws CoreRollbackRefused WPORG_UNREACHABLE.
	 */
	private static function checksums( string $version, string $locale ): array {
		$url      = self::CHECKSUMS_API . '?' . http_build_query(
			[
				'version' => $version,
				'locale'  => $locale,
			],
			'',
			'&'
		);
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::SMALL_TIMEOUT,
				'limit_response_size' => self::CHECKSUMS_MAX_BYTES,
			]
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body     = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		$list     = is_array( $body ) ? ( $body['checksums'] ?? null ) : null;
		if ( ! is_array( $list ) || [] === $list ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'checksums: HTTP ' . $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		$checksums = [];
		foreach ( $list as $file => $md5 ) {
			if ( ! is_string( $file ) || ! is_string( $md5 ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $md5 ) ) {
				throw CoreRollbackRefused::because( CoreRollbackRefused::WPORG_UNREACHABLE, 'checksums: unexpected entry' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
			}
			$checksums[ $file ] = $md5;
		}
		return $checksums;
	}
}
