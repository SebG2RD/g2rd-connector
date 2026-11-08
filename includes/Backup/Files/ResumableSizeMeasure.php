<?php
/**
 * Mesure reprenable de la taille de wp-content et des médias (décision n° 8) : le parcours des
 * sauvegardes (FileWalker : trié, liens jamais suivis, comptés 0 octet), borné en temps et en
 * entrées, reprend au chemin où le passage précédent s'est arrêté. Entre deux passages, les totaux
 * sont des minimums (`partial`) ; le dernier passage rend les totaux exacts. Médias hors de
 * wp-content ou séparés de lui par un lien : non pris en charge ici (Environment\SizeMeter garde
 * alors la mesure d'aujourd'hui, applies() faux).
 *
 * Comme SizeMeter : sans dossier des médias, ils ne sont pas mesurés (null) ; wp-content absent,
 * illisible ou racine du système de fichiers fait échouer la mesure (exception au message sans
 * chemin) ; aucun chemin dans les totaux. La reprise, elle, porte le chemin relatif à wp-content de
 * la dernière entrée vue : elle reste dans l'option de SizeReport, jamais lue par le snapshot.
 *
 * Aucun effet au chargement ; aucune fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Files;

use G2RD\Connector\Support\PathRules;

final class ResumableSizeMeasure {

	/** Le temps écoulé n'est relu que tous les CHECK_EVERY pas (comme SizeMeter). */
	private const CHECK_EVERY = 256;

	/** Message sans chemin, celui de SizeMeter : il peut finir affiché ou journalisé. */
	private const CONTENT_UNREADABLE = 'wp-content size not measured: the folder could not be opened (missing, or not readable by PHP). Check the permissions of the wp-content folder; the next measurement runs within a day.';

	/**
	 * Mesure prise en charge ici : médias absents ('') ou dans wp-content sans lien entre les deux.
	 *
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @param string $uploads_dir Dossier des médias du site principal (`basedir`), ou ''.
	 */
	public static function applies( string $content_dir, string $uploads_dir ): bool {
		return '' === $uploads_dir || null !== self::relative_uploads( PathRules::normalize( $content_dir ), PathRules::normalize( $uploads_dir ) );
	}

	/**
	 * Un passage : reprend après `resume['cursor']` (reprise valide), s'arrête à la première borne
	 * atteinte (pas : dossiers et entrées ; temps relu tous les CHECK_EVERY pas).
	 *
	 * @param string                    $content_dir WP_CONTENT_DIR.
	 * @param string                    $uploads_dir Dossier des médias (applies() vrai), ou ''.
	 * @param array<string, mixed>|null $resume      Reprise rendue par le passage précédent.
	 * @param int                       $max_seconds Budget de temps du passage.
	 * @param int                       $max_entries Budget de pas du passage.
	 * @param (\Closure(): int)|null    $clock       Horloge en millisecondes.
	 * @return array{wp_content_bytes: int, uploads_bytes: int|null, files_counted: int, partial: bool, scan_ms: int, resume: array{cursor: string, wp_content_bytes: int, uploads_bytes: int, files_counted: int}|null}
	 * @throws \UnexpectedValueException Si wp-content ne s'ouvre pas (message sans chemin).
	 */
	public static function measure( string $content_dir, string $uploads_dir, ?array $resume, int $max_seconds, int $max_entries, ?\Closure $clock = null ): array {
		$clock   = $clock ?? static fn (): int => (int) ( hrtime( true ) / 1000000 );
		$content = PathRules::normalize( $content_dir );
		if ( PathRules::is_root( $content ) || ! is_dir( $content ) ) {
			throw new \UnexpectedValueException( self::CONTENT_UNREADABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}
		$uploads = '' === $uploads_dir ? null : self::relative_uploads( $content, PathRules::normalize( $uploads_dir ) );
		$from    = self::valid_resume( $resume );
		$bytes   = $from['wp_content_bytes'] ?? 0;
		$media   = $from['uploads_bytes'] ?? 0;
		$counted = $from['files_counted'] ?? 0;
		$cursor  = $from['cursor'] ?? null;
		$started = $clock();
		$steps   = 0;
		$partial = false;
		$walker  = new FileWalker( $content );
		foreach ( $walker->walk( $cursor ) as $entry ) {
			if ( $steps >= $max_entries || ( 0 === $steps % self::CHECK_EVERY && $steps > 0 && $clock() - $started >= $max_seconds * 1000 ) ) {
				$partial = true;
				break;
			}
			++$steps;
			$cursor = $entry['path'];
			if ( 'dir' === $entry['type'] ) {
				continue;
			}
			++$counted;
			$bytes += $entry['size'];
			if ( null !== $uploads && ( '' === $uploads || PathRules::is_inside( $entry['path'], $uploads ) ) ) {
				$media += $entry['size'];
			}
		}
		if ( in_array( '', $walker->unlisted(), true ) ) {
			// Racine refusée : rien n'a été vu, 0 octet passerait pour une mesure.
			throw new \UnexpectedValueException( self::CONTENT_UNREADABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}

		return [
			'wp_content_bytes' => $bytes,
			'uploads_bytes'    => null === $uploads ? null : $media,
			'files_counted'    => $counted,
			'partial'          => $partial,
			'scan_ms'          => max( 0, $clock() - $started ),
			'resume'           => $partial && null !== $cursor ? [
				'cursor'           => $cursor,
				'wp_content_bytes' => $bytes,
				'uploads_bytes'    => $media,
				'files_counted'    => $counted,
			] : null,
		];
	}

	/**
	 * Reprise vérifiée : une valeur abîmée (option modifiée, version antérieure) compte comme absente,
	 * et la mesure repart du début.
	 *
	 * @param array<string, mixed>|null $resume Reprise lue dans l'option.
	 * @return array{cursor: string, wp_content_bytes: int, uploads_bytes: int, files_counted: int}|null
	 */
	private static function valid_resume( ?array $resume ): ?array {
		if ( null === $resume ) {
			return null;
		}
		$cursor  = $resume['cursor'] ?? null;
		$bytes   = $resume['wp_content_bytes'] ?? null;
		$media   = $resume['uploads_bytes'] ?? null;
		$counted = $resume['files_counted'] ?? null;
		if ( ! is_string( $cursor ) || '' === $cursor || ! is_int( $bytes ) || ! is_int( $media ) || ! is_int( $counted ) || min( $bytes, $media, $counted ) < 0 ) {
			return null;
		}

		return [
			'cursor'           => $cursor,
			'wp_content_bytes' => $bytes,
			'uploads_bytes'    => $media,
			'files_counted'    => $counted,
		];
	}

	/**
	 * Médias relatifs à wp-content ('' : wp-content lui-même), ou null s'ils n'y sont pas sans lien.
	 *
	 * @param string $content wp-content, normalisé.
	 * @param string $uploads Dossier des médias, normalisé.
	 */
	private static function relative_uploads( string $content, string $uploads ): ?string {
		if ( $uploads === $content ) {
			return '';
		}
		if ( ! PathRules::is_inside( $uploads, $content ) ) {
			return null;
		}
		$rest = substr( $uploads, strlen( $content ) + 1 );
		try {
			return PathRules::crosses_link( $content, $rest ) ? null : $rest;
		} catch ( \ValueError ) {
			return null;
		}
	}
}
