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
 *    borne atteinte → arrêt, `partial` vrai, et les valeurs sont un MINIMUM ;
 *  - liens symboliques JAMAIS suivis : un lien vers le dossier d'un autre site du même
 *    compte ne doit pas être parcouru (isLink() testé avant tout, FOLLOW_SYMLINKS
 *    absent) ; le lien compte 0 octet ;
 *  - une entrée illisible ou disparue pendant le parcours (cache, sessions, fichiers
 *    temporaires) compte 0 octet et ne fait jamais échouer la mesure ; un dossier
 *    illisible est sauté. Seul `wp-content` impossible à ouvrir la fait échouer ;
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

	/** Le temps écoulé n'est relu que toutes les CHECK_EVERY entrées : lire l'horloge coûte plus que compter. */
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

	/** Entrées vues par la mesure en cours, tous parcours confondus. */
	private int $counted = 0;

	/** Borne atteinte pendant la mesure en cours. */
	private bool $partial = false;

	/**
	 * @param int                                                     $max_seconds Budget de temps de la mesure des fichiers.
	 * @param int                                                     $max_entries Budget d'entrées (fichiers, liens), les deux parcours confondus.
	 * @param (\Closure(): int)|null                                  $clock       Horloge en millisecondes ; par défaut, l'horloge monotone de PHP.
	 * @param (\Closure(string): \Iterator<mixed, \SplFileInfo>)|null $entries     Entrées d'un dossier, lève \UnexpectedValueException s'il ne
	 *                                                                             s'ouvre pas ; par défaut, le parcours récursif sans suivre les liens.
	 */
	public function __construct( int $max_seconds, int $max_entries, ?\Closure $clock = null, ?\Closure $entries = null ) {
		$this->max_seconds = $max_seconds;
		$this->max_entries = $max_entries;
		$this->clock       = $clock ?? static fn (): int => (int) ( hrtime( true ) / 1000000 );
		$this->entries     = $entries ?? static fn ( string $dir ): \Iterator => new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			// Un sous-dossier illisible est sauté au lieu d'interrompre le parcours.
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
	}

	/**
	 * Taille de wp-content et des médias.
	 *
	 * Médias dans wp-content (cas normal) : sous-total pris pendant le même parcours.
	 * Médias ailleurs (constante UPLOADS) : second parcours, sur le reste du même
	 * budget. `uploads_bytes` vaut null sans dossier des médias, ou si ce dossier, hors
	 * de wp-content, ne s'ouvre pas.
	 *
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @param string $uploads_dir Dossier des médias du site principal (`basedir`), ou ''.
	 * @return array{wp_content_bytes: int, uploads_bytes: int|null, files_counted: int, partial: bool, scan_ms: int}
	 * @throws \UnexpectedValueException Si wp-content ne s'ouvre pas (message sans chemin).
	 */
	public function measure_files( string $content_dir, string $uploads_dir ): array {
		$this->started_ms = ( $this->clock )();
		$this->counted    = 0;
		$this->partial    = false;

		$content        = self::normalize( $content_dir );
		$uploads        = '' === $uploads_dir ? null : self::normalize( $uploads_dir );
		$uploads_inside = null !== $uploads && ( $uploads === $content || self::is_inside( $uploads, $content ) );

		$root = $this->open( $content );
		if ( null === $root ) {
			throw new \UnexpectedValueException( self::CONTENT_UNREADABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}
		[ $wp_content_bytes, $uploads_bytes ] = $this->walk( $root, $uploads_inside ? $uploads : null );

		if ( null === $uploads ) {
			$uploads_bytes = null;
		} elseif ( ! $uploads_inside ) {
			$outside       = $this->open( $uploads );
			$uploads_bytes = null === $outside ? null : $this->walk( $outside, null )[0];
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
		foreach ( $entries as $entry ) {
			if ( $this->budget_exhausted() ) {
				$this->partial = true;
				break;
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
	 * Borne atteinte avant l'entrée suivante : nombre d'entrées (à chaque entrée, un
	 * simple compteur) ou temps écoulé (relu toutes les CHECK_EVERY entrées).
	 */
	private function budget_exhausted(): bool {
		if ( $this->partial || $this->counted >= $this->max_entries ) {
			return true;
		}
		return 0 === $this->counted % self::CHECK_EVERY
			&& ( $this->clock )() - $this->started_ms >= $this->max_seconds * 1000;
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
	 * @return \Iterator<mixed, \SplFileInfo>|null
	 */
	private function open( string $dir ): ?\Iterator {
		try {
			return ( $this->entries )( $dir );
		} catch ( \UnexpectedValueException ) {
			return null;
		}
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
