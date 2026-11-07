<?php
/**
 * Parcours reprenable de la racine du site (ABSPATH) pour les sauvegardes : en profondeur, entrées
 * de chaque dossier triées octet par octet (le tri de scandir() dépend de la locale), dossier rendu
 * avant son contenu, liens jamais suivis (rendus avec leur cible, taille 0), exclusions ni rendues
 * ni parcourues. walk( $after ) reprend juste après $after, même disparu : l'ordre ne dépend que des
 * noms. Mémoire : la liste d'un dossier à la fois par niveau de profondeur.
 *
 * Chemins relatifs à la racine, séparateur `/`. Une entrée illisible (lstat() refusé) est sautée
 * sans bruit : le parcours ne lève jamais pour une entrée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Files;

use G2RD\Connector\Support\PathRules;

/**
 * @phpstan-type Entry array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null}
 */
final class FileWalker {

	private const TYPE_MASK = 0170000;

	private const TYPE_LINK = 0120000;

	private const TYPE_DIR = 0040000;

	private const TYPE_FILE = 0100000;

	private string $root;

	/** @var array<string, true> */
	private array $excluded = [];

	/**
	 * @param string       $root     Racine du parcours (ABSPATH).
	 * @param list<string> $excluded Chemins relatifs exclus (dossier ou fichier).
	 */
	public function __construct( string $root, array $excluded = [] ) {
		$this->root = PathRules::normalize( $root );
		foreach ( $excluded as $path ) {
			$clean = trim( PathRules::normalize( $path ), '/' );
			if ( '' !== $clean ) {
				$this->excluded[ $clean ] = true;
			}
		}
	}

	/**
	 * @return \Generator<int, Entry, mixed, void>
	 */
	public function walk( ?string $after = null ): \Generator {
		$cursor = null === $after || '' === $after ? null : explode( '/', trim( $after, '/' ) );
		yield from $this->walk_dir( '', $cursor );
	}

	/**
	 * Entrée d'un chemin relatif (reprise d'un fichier commencé), ou null.
	 *
	 * @return Entry|null
	 */
	public function entry( string $relative ): ?array {
		return $this->stat( trim( $relative, '/' ) );
	}

	/** Ordre du parcours : composant par composant, octet par octet ; un parent avant ses enfants. */
	public static function compare( string $a, string $b ): int {
		$left  = explode( '/', $a );
		$right = explode( '/', $b );
		$count = min( count( $left ), count( $right ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$order = strcmp( $left[ $i ], $right[ $i ] );
			if ( 0 !== $order ) {
				return $order < 0 ? -1 : 1;
			}
		}

		return count( $left ) <=> count( $right );
	}

	/**
	 * @param list<string>|null $cursor Composants restants du curseur, ou null.
	 * @return \Generator<int, Entry, mixed, void>
	 */
	private function walk_dir( string $relative, ?array $cursor ): \Generator {
		$absolute = '' === $relative ? $this->root : $this->root . '/' . $relative;
		$names    = @scandir( $absolute, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dossier illisible ou disparu pendant le parcours : sauté sans avertissement (le message porterait le chemin).
		if ( false === $names ) {
			return;
		}
		$names = array_values( array_diff( $names, [ '.', '..' ] ) );
		sort( $names, SORT_STRING );

		$first = null === $cursor ? null : $cursor[0];
		foreach ( $names as $name ) {
			$child = '' === $relative ? $name : $relative . '/' . $name;
			if ( null !== $first ) {
				$order = strcmp( $name, $first );
				if ( $order < 0 ) {
					continue;
				}
				if ( 0 === $order ) {
					$first = null;
					$rest  = array_slice( (array) $cursor, 1 );
					$entry = $this->excluded( $child ) ? null : $this->stat( $child );
					if ( null !== $entry && 'dir' === $entry['type'] ) {
						yield from $this->walk_dir( $child, [] === $rest ? null : $rest );
					}
					continue;
				}
				$first = null;
			}
			if ( $this->excluded( $child ) ) {
				continue;
			}
			$entry = $this->stat( $child );
			if ( null === $entry ) {
				continue;
			}
			yield $entry;
			if ( 'dir' === $entry['type'] ) {
				yield from $this->walk_dir( $child, null );
			}
		}
	}

	private function excluded( string $relative ): bool {
		return isset( $this->excluded[ $relative ] );
	}

	/**
	 * @return Entry|null
	 */
	private function stat( string $relative ): ?array {
		if ( '' === $relative ) {
			return null;
		}
		$absolute = $this->root . '/' . $relative;
		$stat     = @lstat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- entrée disparue ou illisible : sautée sans avertissement.
		if ( false === $stat ) {
			return null;
		}
		$kind = $stat['mode'] & self::TYPE_MASK;
		$type = match ( $kind ) {
			self::TYPE_LINK => 'link',
			self::TYPE_DIR  => 'dir',
			self::TYPE_FILE => 'file',
			default         => null,
		};
		if ( null === $type ) {
			return null;
		}
		$target = 'link' === $type ? @readlink( $absolute ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cible illisible : lien gardé, cible vide.

		return [
			'path'   => $relative,
			'type'   => $type,
			'size'   => 'file' === $type ? max( 0, (int) $stat['size'] ) : 0,
			'mtime'  => max( 0, (int) $stat['mtime'] ),
			'mode'   => $stat['mode'] & 07777,
			'target' => 'link' === $type ? ( false === $target ? '' : $target ) : null,
		];
	}
}
