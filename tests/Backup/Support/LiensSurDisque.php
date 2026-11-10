<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Support;

/**
 * Vrais liens sur le disque pour les tests du module de sauvegarde (dossier privé, dossier d'une
 * sauvegarde, état local, parties envoyées). Un lien vers un dossier est un lien symbolique, ou une
 * jonction Windows quand le poste refuse les liens symboliques ; un lien vers un fichier n'a pas
 * d'équivalent sans droit (le test qui en a besoin est alors ignoré, et mené en CI Linux).
 */
trait LiensSurDisque {

	/**
	 * Lien symbolique de `$link` vers le dossier `$target` (qui peut ne pas exister) ; sous Windows
	 * sans le droit de créer des liens, une jonction (`mklink /J`, sans droit particulier, comme
	 * dans ProtectedUpdateTest) en tient lieu. Faux si le système refuse les deux.
	 */
	private static function lien_vers_un_dossier( string $target, string $link ): bool {
		if ( function_exists( 'symlink' ) && @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			return true;
		}
		if ( '\\' !== DIRECTORY_SEPARATOR || ! function_exists( 'exec' ) ) {
			return false;
		}
		$to_windows = static fn ( string $path ): string => '"' . str_replace( '/', '\\', $path ) . '"';
		exec( 'cmd /c mklink /J ' . $to_windows( $link ) . ' ' . $to_windows( $target ) . ' 2>&1', $output, $code );
		clearstatcache( true );
		return 0 === $code && ( is_link( $link ) || file_exists( $link ) );
	}

	/** Lien symbolique de `$link` vers le fichier `$target` (qui peut ne pas exister) ; faux si le poste le refuse. */
	private static function lien_vers_un_fichier( string $target, string $link ): bool {
		return function_exists( 'symlink' ) && @symlink( $target, $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
	}

	/** Le test qui demande un lien vers un fichier est ignoré sur un poste qui le refuse. */
	private static function lien_vers_un_fichier_ou_ignorer( string $target, string $link ): void {
		if ( ! self::lien_vers_un_fichier( $target, $link ) ) {
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création) : essai mené en CI Linux.' );
		}
	}

	/** Le test qui demande un lien vers un dossier est ignoré si le système refuse lien et jonction. */
	private static function lien_vers_un_dossier_ou_ignorer( string $target, string $link ): void {
		if ( ! self::lien_vers_un_dossier( $target, $link ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
	}

	/** Retire le lien lui-même, jamais sa cible : rmdir() pour une jonction ou un lien de dossier sous Windows, unlink() ailleurs. */
	private static function retirer_lien( string $link ): void {
		if ( ! ( '\\' === DIRECTORY_SEPARATOR ? @rmdir( $link ) : @unlink( $link ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Noms des entrées d'un dossier (sans `.` ni `..`), triés.
	 *
	 * @return list<string>
	 */
	private static function contenu( string $dir ): array {
		$names = array_values( array_diff( (array) @scandir( $dir ), [ '.', '..' ] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		sort( $names );
		return array_map( 'strval', $names );
	}
}
