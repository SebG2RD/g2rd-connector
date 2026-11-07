<?php
/**
 * Parcours reprenable de la racine du site (ABSPATH) pour les sauvegardes : en profondeur, entrées
 * de chaque dossier triées octet par octet (le tri de scandir() dépend de la locale), dossier rendu
 * avant son contenu, liens jamais suivis (rendus avec leur cible, taille 0), exclusions ni rendues
 * ni parcourues. walk( $after ) reprend juste après $after, même disparu : l'ordre ne dépend que des
 * noms. Mémoire : la liste d'un dossier à la fois par niveau de profondeur.
 *
 * Chemins relatifs à la racine, séparateur `/`. Le parcours ne lève jamais pour une entrée, et ne
 * confond pas « disparu » et « refusé » :
 * - une entrée disparue (entre la liste de son dossier et lstat(), ou un dossier entre lstat() et
 *   sa liste) est sautée sans bruit : elle n'existe plus, c'est son statut vrai ;
 * - un dossier dont le contenu est refusé (liste refusée, ou dossier listé mais non traversable, où
 *   lstat() de chaque entrée serait refusé) n'est jamais tu : rendu avec `listed` faux, contenu non
 *   parcouru, et inscrit dans unlisted(). À la reprise dans un dossier devenu illisible entre deux
 *   tranches, son entrée, déjà rendue, ne l'est plus : il figure seulement dans unlisted(). Racine
 *   refusée : '' dans unlisted().
 * L'appelant compte ces dossiers illisibles et n'inscrit jamais « supprimé » un chemin pour lequel
 * within_unlisted() est vrai.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Files;

use G2RD\Connector\Support\PathRules;

/**
 * `listed` : faux seulement pour un dossier rendu par walk() dont le contenu est refusé ; entry() ne
 * liste rien (toujours vrai).
 *
 * @phpstan-type Entry array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null, listed: bool}
 */
final class FileWalker {

	private const TYPE_MASK = 0170000;

	private const TYPE_LINK = 0120000;

	private const TYPE_DIR = 0040000;

	private const TYPE_FILE = 0100000;

	private string $root;

	/** @var array<string, true> */
	private array $excluded = [];

	/** @var array<string, true> Dossiers dont le contenu a été refusé pendant le dernier parcours. */
	private array $unlisted = [];

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
		$this->unlisted = [];
		$cursor         = null === $after || '' === $after ? null : explode( '/', trim( $after, '/' ) );
		$names          = $this->names( '' );
		if ( null === $names ) {
			$this->refuse( '' );
			return;
		}
		yield from $this->walk_dir( '', $names, $cursor );
	}

	/**
	 * Dossiers dont le contenu a été refusé pendant le parcours en cours ou le dernier, dans l'ordre
	 * du parcours ('' : la racine).
	 *
	 * @return list<string>
	 */
	public function unlisted(): array {
		return array_map( 'strval', array_keys( $this->unlisted ) );
	}

	/** Le chemin est un dossier de unlisted() ou se trouve sous l'un d'eux : son absence ne prouve rien. */
	public function within_unlisted( string $path ): bool {
		if ( [] === $this->unlisted ) {
			return false;
		}
		if ( isset( $this->unlisted[''] ) ) {
			return true;
		}
		$prefix = '';
		foreach ( explode( '/', trim( $path, '/' ) ) as $part ) {
			$prefix = '' === $prefix ? $part : $prefix . '/' . $part;
			if ( isset( $this->unlisted[ $prefix ] ) ) {
				return true;
			}
		}

		return false;
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
	 * @param list<string>      $names  Noms triés du dossier (names()).
	 * @param list<string>|null $cursor Composants restants du curseur, ou null.
	 * @return \Generator<int, Entry, mixed, void>
	 */
	private function walk_dir( string $relative, array $names, ?array $cursor ): \Generator {
		$first = null === $cursor ? null : $cursor[0];
		foreach ( $names as $name ) {
			$child   = '' === $relative ? $name : $relative . '/' . $name;
			$resumed = false;
			$rest    = null;
			if ( null !== $first ) {
				$order = strcmp( $name, $first );
				if ( $order < 0 ) {
					continue;
				}
				if ( 0 === $order ) {
					// Entrée du curseur : déjà rendue, seul son contenu reste à parcourir.
					$resumed = true;
					$rest    = array_slice( (array) $cursor, 1 );
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
			if ( 'dir' !== $entry['type'] ) {
				if ( ! $resumed ) {
					yield $entry;
				}
				continue;
			}
			$inner = $this->names( $child );
			if ( null === $inner ) {
				if ( $this->refuse( $child ) && ! $resumed ) {
					$entry['listed'] = false;
					yield $entry;
				}
				continue;
			}
			if ( ! $resumed ) {
				yield $entry;
			}
			yield from $this->walk_dir( $child, $inner, [] === $rest ? null : $rest );
		}
	}

	/**
	 * Noms d'un dossier sans « . » ni « .. », triés octet par octet ; null si sa liste échoue (refusée,
	 * ou dossier disparu : refuse() les départage) ou s'il n'est pas traversable.
	 *
	 * @return list<string>|null
	 */
	private function names( string $relative ): ?array {
		$absolute = $this->absolute( $relative );
		$names    = @scandir( $absolute, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- liste refusée ou dossier disparu : départagés par refuse(), sans avertissement (le message porterait le chemin).
		if ( false === $names ) {
			return null;
		}
		$names = array_values( array_diff( $names, [ '.', '..' ] ) );
		if ( [] !== $names && ! self::traversable( $absolute ) ) {
			return null;
		}
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Dossier traversable (droit x) : sans lui, lstat() de chacune de ses entrées est refusé. Windows
	 * n'a pas ce droit sur les dossiers (is_executable() y est toujours faux pour un dossier).
	 */
	private static function traversable( string $absolute ): bool {
		return 'Windows' === PHP_OS_FAMILY || is_executable( $absolute );
	}

	/**
	 * Liste d'un dossier impossible : vrai et inscrit dans unlisted() s'il est toujours un dossier
	 * (contenu refusé), faux s'il a disparu (relu sans le cache de stat de PHP).
	 */
	private function refuse( string $relative ): bool {
		$absolute = $this->absolute( $relative );
		clearstatcache( true, $absolute );
		$stat = @lstat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dossier disparu : faux, sans avertissement.
		if ( false === $stat || self::TYPE_DIR !== ( $stat['mode'] & self::TYPE_MASK ) ) {
			return false;
		}
		$this->unlisted[ $relative ] = true;

		return true;
	}

	private function absolute( string $relative ): string {
		return '' === $relative ? $this->root : $this->root . '/' . $relative;
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
		$stat     = @lstat( $absolute ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- entrée disparue entre la liste et lstat() (son dossier est traversable, vérifié par names()) : sautée sans avertissement.
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
			'listed' => true,
		];
	}
}
