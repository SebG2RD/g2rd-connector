<?php
/**
 * Lecture de la fin du journal d'erreurs PHP du site (évolution stratégique, § 6, ligne LogTail),
 * DÉSACTIVÉE par défaut : seulement si le relevé (`php_errors_enabled`) ET la lecture
 * (`php_errors_log_tail`) sont activés, et seulement pour un journal rangé dans WordPress
 * (LoggingProbe::readable_log_path() : ni « .. », ni lien symbolique, ni chemin relatif).
 *
 * Passe sur la tâche biquotidienne (RestorePointPurgeJob::HOOK), jamais pendant une requête de
 * visiteur, sous le verrou MySQL `phplog` sans attendre. Au plus 256 Ko et 2 s par passage ; le
 * décalage est mémorisé avec l'empreinte du début du journal (son premier Ko au plus ; option
 * `g2rd_php_errors_log_cursor`, non autochargée) ; un journal plus court que le décalage, ou dont
 * le début a changé (renouvelé ou vidé), est relu : ses 256 derniers Ko au plus ; au-delà, le bloc
 * le dit (`truncated`), comme pour une avance de plus de 256 Ko, lue en partie. Taille, début et
 * lignes viennent du même descripteur. Une ligne en cours d'écriture est relue au passage
 * suivant ; une ligne de plus de 8 Ko est sautée.
 *
 * Lignes reconnues : « [date] PHP Fatal error / Parse error / Recoverable fatal error / Catchable
 * fatal error / Warning / Deprecated / Notice: message in fichier(:ligne| on line ligne) ». Elles
 * sont normalisées et filtrées comme une erreur fatale (Normalizer::occurrence()), regroupées par
 * empreinte, puis écrites en une fois (Buffer::record_many()).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

use G2RD\Connector\Settings;
use G2RD\Connector\Support\MysqlLock;

/**
 * @phpstan-type Collected array{fp: string, level: string, type: string, slug: string|null, file: string, line: int, message: string, source: string, version: string|null, first_seen: int, last_seen: int, count: int}
 */
final class LogTail {

	public const CURSOR_OPTION  = 'g2rd_php_errors_log_cursor';
	public const MAX_BYTES      = 262144;
	public const MAX_SECONDS    = 2.0;
	public const MAX_LINE_BYTES = 8192;
	public const HEAD_BYTES     = 1024;

	public const OUTCOME_OFF       = 'off';
	public const OUTCOME_NO_LOG    = 'no_log';
	public const OUTCOME_BUSY      = 'busy';
	public const OUTCOME_UNCHANGED = 'unchanged';
	public const OUTCOME_READ      = 'read';
	public const OUTCOME_FAILED    = 'failed';

	private const LOCK_PURPOSE = 'phplog';

	private const LEVELS = [
		'Fatal error'             => Normalizer::LEVEL_FATAL,
		'Parse error'             => Normalizer::LEVEL_FATAL,
		'Recoverable fatal error' => Normalizer::LEVEL_ERROR,
		'Catchable fatal error'   => Normalizer::LEVEL_ERROR,
		'Warning'                 => Normalizer::LEVEL_WARNING,
		'Deprecated'              => Normalizer::LEVEL_DEPRECATED,
		'Notice'                  => Normalizer::LEVEL_NOTICE,
	];

	/** Ligne du journal de PHP, octet par octet (une ligne mal encodée est relue par Redactor). */
	private const LINE = '/\A\[(?<date>[^\]]{1,64}+)\]\s++PHP\s++(?<kind>Fatal error|Parse error|Recoverable fatal error|Catchable fatal error|Warning|Deprecated|Notice):\s++(?<message>.+)\s++in\s++(?<file>\S+?)(?::(?<line>\d++)|\s++on\s++line\s++(?<line2>\d++))\s*+\z/';

	/** Rappel de la tâche biquotidienne, sans paramètre. */
	public static function run_now(): void {
		self::run( time() );
	}

	/**
	 * @param int                     $now   Horodatage Unix du passage.
	 * @param LoggingProbe|null       $probe Sonde du journal (tests).
	 * @param (\Closure(): float)|null $clock Horloge de la borne de 2 s (tests).
	 * @return string Issue (OUTCOME_*), pour les tests et le banc.
	 */
	public static function run( int $now, ?LoggingProbe $probe = null, ?\Closure $clock = null ): string {
		try {
			if ( true !== Settings::get( 'php_errors_enabled' ) || true !== Settings::get( 'php_errors_log_tail' ) ) {
				return self::OUTCOME_OFF;
			}
			$path = ( $probe ?? new LoggingProbe() )->readable_log_path();
			if ( null === $path ) {
				return self::OUTCOME_NO_LOG;
			}
			$clock = $clock ?? static fn (): float => microtime( true );
			$read  = static fn (): string => self::read( $path, $now, $clock );

			return MysqlLock::run( self::LOCK_PURPOSE, 0, $read, static fn (): string => self::OUTCOME_BUSY, $read );
		} catch ( \Throwable ) {
			return self::OUTCOME_FAILED;
		}
	}

	/**
	 * @param \Closure(): float $clock
	 */
	private static function read( string $path, int $now, \Closure $clock ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lecture seule et bornée d'un journal confiné dans ABSPATH ; WP_Filesystem ne sait pas lire depuis un décalage.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return self::OUTCOME_FAILED;
		}
		$file_id = hash( 'sha256', $path );
		/** @var array<string, Collected> $groups */
		$groups   = [];
		$versions = [];
		try {
			// Taille, empreinte du début et lignes viennent de ce descripteur : un journal renommé ou
			// recréé pendant le passage n'est pas mêlé à celui qu'on lit.
			$stat = fstat( $handle );
			if ( false === $stat ) {
				return self::OUTCOME_FAILED;
			}
			$size    = $stat['size'];
			$offset  = self::resume_offset( $handle, get_option( self::CURSOR_OPTION, null ), $file_id, $size );
			$resume  = null !== $offset;
			$start   = $offset ?? max( 0, $size - self::MAX_BYTES );
			$partial = ! $resume && $start > 0;
			if ( $size - $start > self::MAX_BYTES ) {
				$start   = $size - self::MAX_BYTES;
				$resume  = false;
				$partial = true;
			}
			if ( $start >= $size ) {
				$head = self::head( $handle, $size );
				if ( null === $head ) {
					return self::OUTCOME_FAILED;
				}
				self::save_cursor( $file_id, $size, $head, $now );
				return self::OUTCOME_UNCHANGED;
			}

			$position = $start;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- même lecture bornée.
			if ( 0 !== fseek( $handle, $start ) ) {
				return self::OUTCOME_FAILED;
			}
			if ( ! $resume && $start > 0 ) {
				// Arrivé au milieu d'une ligne : elle est sautée.
				self::skip_line( $handle, $size );
			}
			$began = $clock();
			while ( true ) {
				$line_start = ftell( $handle );
				if ( false === $line_start || $line_start >= $size ) {
					break;
				}
				if ( $clock() - $began > self::MAX_SECONDS ) {
					$partial = true;
					break;
				}
				$line = fgets( $handle, self::MAX_LINE_BYTES );
				if ( false === $line ) {
					break;
				}
				if ( ! str_ends_with( $line, "\n" ) ) {
					$after = ftell( $handle );
					if ( false === $after || $after >= $size ) {
						// Ligne en cours d'écriture : relue au passage suivant.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- même lecture bornée.
						fseek( $handle, $line_start );
						break;
					}
					// Ligne de plus de 8 Ko : sautée.
					self::skip_line( $handle, $size );
					continue;
				}
				self::collect( $groups, $versions, $line, $now );
			}
			$told     = ftell( $handle );
			$position = false === $told ? $position : $told;
			// Empreinte prise avant d'écrire le tampon : si elle manque, rien n'est enregistré et
			// le passage suivant reprend au décalage précédent (aucune ligne comptée deux fois).
			$head = self::head( $handle, $position );
			if ( null === $head ) {
				return self::OUTCOME_FAILED;
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du fichier ouvert ci-dessus.
			fclose( $handle );
		}

		if ( Buffer::record_many( array_values( $groups ), $now, $partial ) ) {
			self::save_cursor( $file_id, $position, $head, $now );
		}

		return self::OUTCOME_READ;
	}

	/**
	 * Décalage où reprendre, ou null si le journal est neuf pour le curseur : autre chemin, curseur
	 * illisible ou sans empreinte du début, journal plus court que le décalage, ou début changé
	 * (journal remplacé ou vidé sur place, puis réécrit au-delà de l'ancien décalage).
	 *
	 * @param resource $handle
	 */
	private static function resume_offset( $handle, mixed $cursor, string $file_id, int $size ): ?int {
		if ( ! is_array( $cursor ) || ( $cursor['file'] ?? null ) !== $file_id ) {
			return null;
		}
		$offset = $cursor['offset'] ?? null;
		$head   = $cursor['head'] ?? null;
		if ( ! is_int( $offset ) || $offset < 0 || $offset > $size || ! is_string( $head ) ) {
			return null;
		}

		return self::head( $handle, $offset ) === $head ? $offset : null;
	}

	/**
	 * Empreinte du début du journal ouvert : SHA-256 de ses min(`$offset`, HEAD_BYTES) premiers
	 * octets ; null si le début n'a pas pu être relu.
	 *
	 * @param resource $handle
	 */
	private static function head( $handle, int $offset ): ?string {
		$length = min( $offset, self::HEAD_BYTES );
		if ( $length <= 0 ) {
			return hash( 'sha256', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- même lecture bornée, au début du fichier.
		if ( 0 !== fseek( $handle, 0 ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- 1 Ko au plus, du même journal.
		$bytes = fread( $handle, $length );

		return false === $bytes ? null : hash( 'sha256', $bytes );
	}

	/**
	 * @param array<string, Collected>   $groups
	 * @param array<string, string|null> $versions
	 */
	private static function collect( array &$groups, array &$versions, string $line, int $now ): void {
		if ( 1 !== preg_match( self::LINE, rtrim( $line, "\r\n" ), $matches, PREG_UNMATCHED_AS_NULL ) ) {
			return;
		}
		// Le motif (LINE) ne reconnaît que les sortes de LEVELS : le niveau existe toujours.
		$level      = self::LEVELS[ $matches['kind'] ];
		$number     = null !== $matches['line'] ? (int) $matches['line'] : (int) ( $matches['line2'] ?? 0 );
		$occurrence = Normalizer::occurrence( $level, (string) $matches['file'], $number, (string) $matches['message'], Normalizer::SOURCE_LOG );
		$at         = self::date( (string) $matches['date'], $now );
		$key        = $occurrence['type'] . '|' . ( $occurrence['slug'] ?? '' );
		if ( ! array_key_exists( $key, $versions ) ) {
			$versions[ $key ] = Normalizer::component_version( $occurrence['type'], $occurrence['slug'] );
		}
		$fp = $occurrence['fp'];
		if ( isset( $groups[ $fp ] ) ) {
			++$groups[ $fp ]['count'];
			$groups[ $fp ]['first_seen'] = min( $groups[ $fp ]['first_seen'], $at );
			if ( $at >= $groups[ $fp ]['last_seen'] ) {
				$groups[ $fp ]['last_seen'] = $at;
				$groups[ $fp ]['line']      = $occurrence['line'];
				$groups[ $fp ]['message']   = $occurrence['message'];
			}
			return;
		}
		$groups[ $fp ] = $occurrence + [
			'version'    => $versions[ $key ],
			'first_seen' => $at,
			'last_seen'  => $at,
			'count'      => 1,
		];
	}

	/** Date d'une ligne (« 25-Oct-2026 06:00:12 UTC ») ; à défaut, ou dans le futur, l'heure du passage. */
	private static function date( string $text, int $now ): int {
		$date = \DateTimeImmutable::createFromFormat( 'd-M-Y H:i:s e', trim( $text ) );
		$at   = false === $date ? strtotime( $text ) : $date->getTimestamp();

		return is_int( $at ) && $at <= $now ? $at : $now;
	}

	/**
	 * Saute jusqu'à la fin de la ligne en cours, sans dépasser `$size`.
	 *
	 * @param resource $handle
	 */
	private static function skip_line( $handle, int $size ): void {
		while ( true ) {
			$position = ftell( $handle );
			if ( false === $position || $position >= $size ) {
				return;
			}
			$chunk = fgets( $handle, self::MAX_LINE_BYTES );
			if ( false === $chunk || str_ends_with( $chunk, "\n" ) ) {
				return;
			}
		}
	}

	private static function save_cursor( string $file_id, int $offset, string $head, int $now ): void {
		update_option(
			self::CURSOR_OPTION,
			[
				'file'   => $file_id,
				'offset' => $offset,
				'head'   => $head,
				'at'     => $now,
			],
			false
		);
	}
}
