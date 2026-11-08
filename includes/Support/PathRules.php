<?php
/**
 * Règles de chemins : normalisation, appartenance à un dossier, racine du système de fichiers, lien
 * traversé. Extraites de Environment\SizeMeter (phase 4, tâche 4) sans changement de comportement,
 * pour servir aussi au parcours des sauvegardes (Backup\Files\FileWalker). Docblocks d'origine
 * dans SizeMeter, à l'identique.
 *
 * Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Support;

final class PathRules {

	/**
	 * Un lien sépare `$base/$rest` de $base : lien symbolique, ou jonction Windows (cf. SizeMeter).
	 *
	 * @throws \ValueError Si PHP refuse le chemin.
	 */
	public static function crosses_link( string $base, string $rest ): bool {
		$path = $base . '/' . $rest;
		// is_dir() avant is_link() : sous Windows, après is_link() sur une jonction, PHP
		// resert le lstat() en cache et is_dir() rendrait faux.
		if ( ! is_dir( $path ) ) {
			// Absent : rien à suivre, sauf un lien dont la cible manque.
			return is_link( $path );
		}
		if ( is_link( $path ) ) {
			return true;
		}
		// Une racine retrouve son `/` : `C:` seul serait le dossier courant du lecteur.
		$base_dir  = self::is_root( $base ) ? $base . '/' : $base;
		$real_base = is_dir( $base_dir ) ? realpath( $base_dir ) : false;
		$real_path = realpath( $path );
		if ( false === $real_base || false === $real_path ) {
			return false;
		}
		$expected = self::normalize( $real_base ) . '/' . $rest;
		$actual   = self::normalize( $real_path );
		// Windows ne distingue pas la casse, et realpath() y rend celle du disque.
		return '\\' === DIRECTORY_SEPARATOR ? 0 !== strcasecmp( $actual, $expected ) : $actual !== $expected;
	}

	/** Racine du système de fichiers, une fois normalisée (cf. SizeMeter). */
	public static function is_root( string $path ): bool {
		return '' === $path || 1 === preg_match( '#^[A-Za-z]:$#', $path );
	}

	/** Chemin comparable : séparateurs `/`, répétitions réduites sauf en tête, sans `/` final. */
	public static function normalize( string $path ): string {
		return rtrim( (string) preg_replace( '#(?<=.)/{2,}#', '/', str_replace( '\\', '/', $path ) ), '/' );
	}

	/** $path est dans $dir, comparaison avec le séparateur final. */
	public static function is_inside( string $path, string $dir ): bool {
		return str_starts_with( $path, $dir . '/' );
	}
}
