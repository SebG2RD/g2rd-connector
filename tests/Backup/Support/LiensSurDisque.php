<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Support;

/**
 * Vrais liens sur le disque pour les tests du module de sauvegarde (dossier privé, dossier d'une
 * sauvegarde, état local, parties envoyées). Un lien est un lien symbolique ; sous Windows sans le
 * droit de créer des liens symboliques, une jonction (`mklink /J`, sans droit particulier) en tient
 * lieu, vers un dossier comme vers un fichier ou vers rien (jonction cassée). PHP y voit une
 * jonction ni comme un lien, ni comme un dossier, ni comme un fichier : c'est le cas que le module
 * doit refuser aussi. Seul le test qui exige un vrai lien symbolique (remplacé par rename(), que
 * Windows refuse sur une jonction) est ignoré sous Windows, et mené en CI Linux.
 */
trait LiensSurDisque {

	/**
	 * Lien symbolique de `$link` vers le dossier `$target` (qui peut ne pas exister) ; sous Windows
	 * sans le droit de créer des liens, une jonction en tient lieu. Faux si le système refuse les deux.
	 */
	private static function lien_vers_un_dossier( string $target, string $link ): bool {
		return self::lien_ou_jonction( $target, $link );
	}

	/**
	 * Lien symbolique de `$link` vers le fichier `$target` (qui peut ne pas exister) ; sous Windows
	 * sans le droit de créer des liens, une jonction vers ce fichier (ou vers rien) en tient lieu.
	 */
	private static function lien_vers_un_fichier( string $target, string $link ): bool {
		return self::lien_ou_jonction( $target, $link );
	}

	/** Le test qui demande un lien vers un fichier est ignoré si le système refuse lien et jonction. */
	private static function lien_vers_un_fichier_ou_ignorer( string $target, string $link ): void {
		if ( ! self::lien_vers_un_fichier( $target, $link ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
	}

	/** Le test qui exige un vrai lien symbolique (pas une jonction) est ignoré sur un poste qui le refuse. */
	private static function lien_symbolique_ou_ignorer( string $target, string $link ): void {
		if ( ! function_exists( 'symlink' ) || ! @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
			self::markTestSkipped( 'Liens symboliques indisponibles sur ce poste (Windows sans droit de création ; une jonction ne se remplace pas par rename()) : essai mené en CI Linux.' );
		}
	}

	/** Le test qui demande un lien vers un dossier est ignoré si le système refuse lien et jonction. */
	private static function lien_vers_un_dossier_ou_ignorer( string $target, string $link ): void {
		if ( ! self::lien_vers_un_dossier( $target, $link ) ) {
			self::markTestSkipped( 'Le système refuse de créer un lien symbolique ou une jonction.' );
		}
	}

	/**
	 * `$path` est-il (encore) un lien : lien symbolique, même cassé, ou jonction dont le chemin
	 * réel n'est pas là où elle est rangée ?
	 */
	private static function est_un_lien( string $path ): bool {
		clearstatcache( true );
		if ( is_link( $path ) ) {
			return true;
		}
		clearstatcache( true );
		$parent = realpath( dirname( $path ) );
		$reel   = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $parent && false !== $reel && 0 !== strcasecmp( str_replace( '\\', '/', $reel ), str_replace( '\\', '/', $parent ) . '/' . basename( $path ) );
	}

	/** Retire le lien lui-même, jamais sa cible : rmdir() pour une jonction (même vers un fichier) ou un lien de dossier sous Windows, unlink() ailleurs. */
	private static function retirer_lien( string $link ): void {
		if ( ! ( '\\' === DIRECTORY_SEPARATOR ? @rmdir( $link ) : @unlink( $link ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		clearstatcache( true );
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

	/**
	 * Lien symbolique, sinon jonction Windows (`mklink /J`, vers un dossier, un fichier ou rien).
	 */
	private static function lien_ou_jonction( string $target, string $link ): bool {
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
}
