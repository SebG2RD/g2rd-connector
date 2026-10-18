<?php
/**
 * Mesure bornée des volumes : taille de `wp-content`, des médias et de la base.
 *
 * Coûteuse (parcours de tous les fichiers, requête sur toutes les tables) : JAMAIS
 * dans une requête du snapshot. Elle est lancée au plus une fois par jour en tâche de
 * fond (Environment\SizeReport), qui garde son résultat daté ; le snapshot ne lit que
 * ce cache.
 *
 * Règles à ne pas changer :
 *  - parcours en flux (RecursiveDirectoryIterator), rien gardé en mémoire : mémoire
 *    constante quel que soit le nombre de fichiers ; seules les métadonnées sont lues,
 *    jamais le contenu d'un fichier ;
 *  - borné en temps et en nombre d'entrées, sur un seul budget pour les deux parcours :
 *    borne atteinte → arrêt, `partial` vrai, et les valeurs sont un MINIMUM. Les entrées
 *    sont les fichiers et les liens (`files_counted`) ; un dossier traversé n'en est pas
 *    une, mais il fait avancer le contrôle du temps : une arborescence faite surtout de
 *    dossiers vides (cache vidé, sessions) reste bornée en temps, pas en entrées ;
 *  - liens symboliques JAMAIS suivis : un lien vers le dossier d'un autre site du même
 *    compte ne doit pas être parcouru (isLink() testé avant tout, FOLLOW_SYMLINKS
 *    absent) ; le lien compte 0 octet. Un dossier des médias qui est un lien, ou qu'un
 *    lien sépare de wp-content, n'est pas mesuré (null) : ni suivi, ni compté 0. Seul
 *    wp-content, défini par le wp-config du site, est suivi s'il est un lien ;
 *  - une entrée illisible ou disparue pendant le parcours (cache, sessions, fichiers
 *    temporaires) compte 0 octet et ne fait jamais échouer la mesure ; un dossier
 *    illisible est sauté. Seul `wp-content` impossible à ouvrir la fait échouer ;
 *  - la racine du système de fichiers (`/`, ou un lecteur Windows comme `C:\`) n'est
 *    jamais parcourue (tout le serveur), ni un dossier des médias qui CONTIENT
 *    wp-content (ABSPATH, dossier du compte : wp-content compté deux fois, dossiers des
 *    autres sites parcourus). Ces médias ne sont pas mesurés ; un wp-content à la
 *    racine fait échouer ;
 *  - aucun chemin ni nom de fichier dans la sortie ni dans les erreurs : des totaux et
 *    un drapeau.
 *
 * Ne connaît que deux chemins et ignore tout du multisite : l'appelant choisit le
 * dossier des médias (celui du site principal). L'horloge et la fabrique du parcours
 * sont injectables (tests). Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Environment;

final class SizeMeter {

	/** Base mesurée. */
	public const DB_OK = 'ok';

	/** Base non mesurée (requête en erreur, aucune table vue, base injoignable). */
	public const DB_UNAVAILABLE = 'unavailable';

	/** Le temps écoulé n'est relu que tous les CHECK_EVERY pas (entrées et dossiers) : lire l'horloge coûte plus que compter. */
	private const CHECK_EVERY = 256;

	/** Message sans chemin : il peut finir affiché ou journalisé. */
	private const CONTENT_UNREADABLE = 'wp-content size not measured: the folder could not be opened (missing, or not readable by PHP). Check the permissions of the wp-content folder; the next measurement runs within a day.';

	private int $max_seconds;

	private int $max_entries;

	/** @var \Closure(): int Horloge en millisecondes. */
	private \Closure $clock;

	/** @var \Closure(string): \Iterator<mixed, \SplFileInfo> Parcours des entrées d'un dossier. */
	private \Closure $entries;

	/** Début de la mesure en cours, en millisecondes. */
	private int $started_ms = 0;

	/** Entrées (fichiers, liens) vues par la mesure en cours, tous parcours confondus. */
	private int $counted = 0;

	/** Pas de la mesure en cours (entrées et dossiers traversés) : rythme du contrôle du temps. */
	private int $steps = 0;

	/** Borne atteinte pendant la mesure en cours. */
	private bool $partial = false;

	/**
	 * @param int                                                     $max_seconds Budget de temps de la mesure des fichiers.
	 * @param int                                                     $max_entries Budget d'entrées (fichiers, liens ; pas les dossiers traversés), les deux
	 *                                                                             parcours confondus.
	 * @param (\Closure(): int)|null                                  $clock       Horloge en millisecondes ; par défaut, l'horloge monotone de PHP.
	 * @param (\Closure(string): \Iterator<mixed, \SplFileInfo>)|null $entries     Entrées d'un dossier, lève \UnexpectedValueException s'il ne
	 *                                                                             s'ouvre pas ; par défaut, le parcours récursif sans suivre les liens,
	 *                                                                             dossiers compris. Seul un \RecursiveIteratorIterator peut remettre des
	 *                                                                             dossiers (élément qui a des enfants) ; tout autre itérateur ne remet
	 *                                                                             que des entrées.
	 */
	public function __construct( int $max_seconds, int $max_entries, ?\Closure $clock = null, ?\Closure $entries = null ) {
		$this->max_seconds = $max_seconds;
		$this->max_entries = $max_entries;
		$this->clock       = $clock ?? static fn (): int => (int) ( hrtime( true ) / 1000000 );
		$this->entries     = $entries ?? static fn ( string $dir ): \Iterator => new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			// Dossiers remis eux aussi (avant leur contenu) : ils font avancer le contrôle du temps.
			\RecursiveIteratorIterator::SELF_FIRST,
			// Un sous-dossier illisible est sauté au lieu d'interrompre le parcours.
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
	}

	/**
	 * Taille de wp-content et des médias.
	 *
	 * Médias dans wp-content (cas normal) : sous-total pris pendant le même parcours.
	 * S'ils sont absents, 0 : le parcours de wp-content a tout vu, aucun média n'existe.
	 * Médias ailleurs (constante UPLOADS, option upload_path) : second parcours, sur le
	 * reste du même budget.
	 *
	 * `uploads_bytes` vaut null (non mesuré) sans dossier des médias, ou si ce dossier :
	 *  - est un lien, ou un lien le sépare de wp-content (jamais suivi : dans wp-content,
	 *    le lien compte 0 octet, et 0 passerait pour une mesure exacte) ;
	 *  - est la racine du système de fichiers, ou contient wp-content (jamais parcourus) ;
	 *  - hors de wp-content, ne s'ouvre pas (absent ou illisible : on ne sait pas
	 *    lequel), ou n'est pas ouvert parce que la borne est déjà atteinte (rien n'en a
	 *    été vu : null plutôt qu'un 0 qui passerait pour un minimum).
	 *
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @param string $uploads_dir Dossier des médias du site principal (`basedir`), ou ''.
	 * @return array{wp_content_bytes: int, uploads_bytes: int|null, files_counted: int, partial: bool, scan_ms: int}
	 * @throws \UnexpectedValueException Si wp-content ne s'ouvre pas (message sans chemin).
	 */
	public function measure_files( string $content_dir, string $uploads_dir ): array {
		$this->started_ms = ( $this->clock )();
		$this->counted    = 0;
		$this->steps      = 0;
		$this->partial    = false;

		$content = self::normalize( $content_dir );
		$root    = $this->open( $content );
		if ( null === $root ) {
			throw new \UnexpectedValueException( self::CONTENT_UNREADABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}

		$uploads = '' === $uploads_dir ? null : self::measurable_uploads( $content, self::normalize( $uploads_dir ) );
		$inside  = null !== $uploads && ( $uploads === $content || self::is_inside( $uploads, $content ) );

		[ $wp_content_bytes, $subtotal ] = $this->walk( $root, $inside ? $uploads : null );

		if ( $inside ) {
			$uploads_bytes = $subtotal;
		} else {
			$uploads_bytes = null === $uploads ? null : $this->measure_outside( $uploads );
		}

		return [
			'wp_content_bytes' => $wp_content_bytes,
			'uploads_bytes'    => $uploads_bytes,
			'files_counted'    => $this->counted,
			'partial'          => $this->partial,
			'scan_ms'          => max( 0, ( $this->clock )() - $this->started_ms ),
		];
	}

	/**
	 * Taille de la base : somme `Data_length + Index_length` des tables du préfixe de
	 * BASE (toutes les tables de l'installation, tous blogs d'un réseau compris).
	 *
	 * @return array{db_bytes: int|null, db_status: string}
	 */
	public function measure_database(): array {
		global $wpdb;

		$unavailable = [
			'db_bytes'  => null,
			'db_status' => self::DB_UNAVAILABLE,
		];
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLE STATUS de maintenance (lecture seule, requête préparée), une fois par jour en tâche de fond : aucun cache pertinent.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ),
				ARRAY_A
			);
			$error = (string) $wpdb->last_error;
		} catch ( \Throwable ) {
			// Base injoignable (mysqli en mode exceptions) : non mesurée, le reste de la mesure tient.
			return $unavailable;
		}
		if ( '' !== $error || ! is_array( $rows ) || [] === $rows ) {
			return $unavailable;
		}

		$bytes = 0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				// Une vue a des longueurs NULL : 0 octet.
				$bytes += (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 );
			}
		}
		return [
			'db_bytes'  => $bytes,
			'db_status' => self::DB_OK,
		];
	}

	/**
	 * Parcourt les entrées jusqu'au bout ou jusqu'à la borne.
	 *
	 * @param \Iterator<mixed, \SplFileInfo> $entries Entrées du dossier.
	 * @param string|null                    $uploads Dossier des médias (normalisé) dont prendre le sous-total, ou null.
	 * @return array{int, int} Total, sous-total des médias.
	 */
	private function walk( \Iterator $entries, ?string $uploads ): array {
		$total    = 0;
		$subtotal = 0;
		$tree     = $entries instanceof \RecursiveIteratorIterator ? $entries : null;
		foreach ( $entries as $entry ) {
			if ( $this->budget_exhausted() ) {
				$this->partial = true;
				break;
			}
			++$this->steps;
			// Dossier où le parcours va descendre : ni compté, ni mesuré (son contenu le sera).
			// Même critère que LEAVES_ONLY, un lien ou une jonction Windows reste une entrée.
			if ( null !== $tree && self::is_branch( $tree ) ) {
				continue;
			}
			++$this->counted;
			$bytes  = self::bytes( $entry );
			$total += $bytes;
			// Seuls les séparateurs de Windows sont à remplacer : la racine passée au parcours est déjà normalisée.
			if ( null !== $uploads && $bytes > 0 && self::is_inside( str_replace( '\\', '/', $entry->getPathname() ), $uploads ) ) {
				$subtotal += $bytes;
			}
		}
		return [ $total, $subtotal ];
	}

	/**
	 * Médias hors de wp-content : second parcours, sur le reste du budget.
	 *
	 * Borne déjà atteinte : le dossier n'est pas ouvert, et rien n'en ayant été vu, il
	 * n'est pas mesuré (null).
	 *
	 * @param string $uploads Dossier des médias, normalisé.
	 */
	private function measure_outside( string $uploads ): ?int {
		if ( $this->partial || $this->counted >= $this->max_entries || $this->time_spent() ) {
			$this->partial = true;
			return null;
		}
		$entries = $this->open( $uploads );
		return null === $entries ? null : $this->walk( $entries, null )[0];
	}

	/**
	 * Borne atteinte avant le pas suivant : nombre d'entrées (à chaque pas, un simple
	 * compteur) ou temps écoulé (relu tous les CHECK_EVERY pas, dossiers compris).
	 */
	private function budget_exhausted(): bool {
		if ( $this->partial || $this->counted >= $this->max_entries ) {
			return true;
		}
		return 0 === $this->steps % self::CHECK_EVERY && $this->time_spent();
	}

	/** Budget de temps épuisé (lit l'horloge). */
	private function time_spent(): bool {
		return ( $this->clock )() - $this->started_ms >= $this->max_seconds * 1000;
	}

	/**
	 * L'élément courant a des enfants : dossier où le parcours va descendre. Un dossier
	 * illisible en est un aussi (CATCH_GET_CHILD le saute ensuite). En cas de doute
	 * (entrée disparue entre-temps), c'est une entrée : elle comptera 0 octet.
	 *
	 * @param \RecursiveIteratorIterator<\RecursiveIterator<mixed, mixed>> $tree Parcours en cours.
	 */
	private static function is_branch( \RecursiveIteratorIterator $tree ): bool {
		try {
			return $tree->callHasChildren();
		} catch ( \RuntimeException ) {
			return false;
		}
	}

	/**
	 * Octets d'une entrée : 0 pour un lien (jamais suivi : getSize() lirait sa cible) et
	 * pour une entrée illisible ou disparue (getSize() lève alors « stat failed », message
	 * qui porte le chemin : ni gardé ni journalisé).
	 */
	private static function bytes( \SplFileInfo $entry ): int {
		try {
			if ( $entry->isLink() ) {
				return 0;
			}
			return max( 0, (int) $entry->getSize() );
		} catch ( \RuntimeException ) {
			return 0;
		}
	}

	/**
	 * Entrées d'un dossier ; null s'il ne s'ouvre pas.
	 *
	 * La racine du système de fichiers n'est jamais remise au parcours (cf.
	 * is_root()) : ce serait parcourir tout le serveur, dossiers des autres sites du
	 * même compte compris. Un chemin que PHP refuse avant même de l'ouvrir
	 * (\ValueError : octet nul) compte comme un dossier qui ne s'ouvre pas.
	 *
	 * @return \Iterator<mixed, \SplFileInfo>|null
	 */
	private function open( string $dir ): ?\Iterator {
		if ( self::is_root( $dir ) ) {
			return null;
		}
		try {
			return ( $this->entries )( $dir );
		} catch ( \UnexpectedValueException | \ValueError ) {
			return null;
		}
	}

	/**
	 * Dossier des médias à mesurer, ou null s'il ne doit pas l'être : racine du système,
	 * dossier qui contient wp-content, lien (cf. measure_files()).
	 *
	 * @param string $content wp-content, normalisé.
	 * @param string $uploads Dossier des médias, normalisé.
	 */
	private static function measurable_uploads( string $content, string $uploads ): ?string {
		if ( self::is_root( $uploads ) || self::is_inside( $content, $uploads ) ) {
			return null;
		}
		if ( $uploads === $content ) {
			// wp-content lui-même : suivi même s'il est un lien, comme pour son propre total.
			return $uploads;
		}
		if ( self::is_inside( $uploads, $content ) ) {
			// Chaque dossier entre wp-content et les médias : le parcours ne passe aucun lien.
			$base = $content;
			$rest = substr( $uploads, strlen( $content ) + 1 );
		} else {
			// Hors de wp-content : le dossier des médias lui-même.
			$slash = strrpos( $uploads, '/' );
			$base  = false === $slash ? '.' : substr( $uploads, 0, $slash );
			$rest  = false === $slash ? $uploads : substr( $uploads, $slash + 1 );
		}
		try {
			return self::crosses_link( $base, $rest ) ? null : $uploads;
		} catch ( \ValueError ) {
			// Chemin refusé par PHP (octet nul) : rien à mesurer.
			return null;
		}
	}

	/**
	 * Un lien sépare `$base/$rest` de $base (`$rest` compris, $base non) : lien
	 * symbolique, ou jonction Windows, que is_link() ne voit pas mais dont le chemin réel
	 * est ailleurs.
	 *
	 * Le chemin réel du dossier est comparé à celui de $base suivi de $rest. realpath()
	 * n'est appelé que sur un dossier qui existe et que PHP a le droit de lire (is_dir()
	 * vrai, muet même hors d'open_basedir) : jamais d'avertissement, qui porterait le
	 * chemin. Un dossier absent n'a pas de lien à suivre.
	 *
	 * @param string $base Dossier de départ, normalisé (vide ou `C:` pour une racine).
	 * @param string $rest Suite du chemin sous $base, sans `/` au début.
	 * @throws \ValueError Si PHP refuse le chemin.
	 */
	private static function crosses_link( string $base, string $rest ): bool {
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

	/**
	 * Racine du système de fichiers, une fois normalisée : vide (`/` ou `\`) ou lecteur
	 * Windows seul (`C:\` ou `C:/` deviennent `C:`, que PHP lirait comme le dossier
	 * COURANT du lecteur).
	 */
	private static function is_root( string $path ): bool {
		return '' === $path || 1 === preg_match( '#^[A-Za-z]:$#', $path );
	}

	/**
	 * Chemin comparable : séparateurs `/`, séparateurs répétés réduits à un (un
	 * WP_CONTENT_DIR défini avec un `/` final donne `…//uploads`) sauf en tête (chemin
	 * réseau Windows), sans `/` final. Sans dépendre de wp_normalize_path().
	 */
	private static function normalize( string $path ): string {
		return rtrim( (string) preg_replace( '#(?<=.)/{2,}#', '/', str_replace( '\\', '/', $path ) ), '/' );
	}

	/**
	 * $path est dans $dir : comparaison AVEC le séparateur final, sinon
	 * `wp-content/uploads-old` passerait pour un sous-dossier de `wp-content/uploads`.
	 */
	private static function is_inside( string $path, string $dir ): bool {
		return str_starts_with( $path, $dir . '/' );
	}
}
