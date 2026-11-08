<?php
/**
 * État local des incrémentales (décision n° 19) : la liste des fichiers de la dernière complète qui
 * porte les fichiers, dans l'ordre du parcours (FileWalker::compare), dans
 * `WorkDir::baseline_dir()/<backup_id>.jsonl` (`wp-content/g2rd-backups/<dossier privé>/baseline/`),
 * décrite par `current.json` (identifiant, date, nombre de lignes, SHA-256 du fichier). Une ligne :
 * `{"p"|"pb","k","s","m","h"}` (chemin ou chemin en base64, nature, taille, date, empreinte ou
 * null). Ne sort jamais du site ; protégé par les gardes du dossier de travail. Taille estimée, non
 * mesurée : 80 à 200 octets par ligne selon le chemin et l'empreinte, soit 2 à 5 Mo pour les 24 116
 * entrées comptées sur g2rd.fr le 23/10 (compte partiel, donc un minimum).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

final class IncrementalBaseline {

	/** Au-delà, l'incrémentale devient une complète (40 jours : la cadence par défaut est hebdomadaire). */
	public const MAX_AGE_SECONDS = 3456000;

	private const CURRENT = 'current.json';

	private string $dir;

	public function __construct( string $dir ) {
		$this->dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
	}

	/**
	 * @return array{backup_id: string, created_at: int, entries: int, sha256: string}|null
	 */
	public function current(): ?array {
		$raw  = @file_get_contents( $this->dir . '/' . self::CURRENT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- description de l'état local, absente au premier passage.
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! is_string( $data['backup_id'] ?? null ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $data['backup_id'] )
			|| ! is_int( $data['created_at'] ?? null ) || ! is_int( $data['entries'] ?? null ) || ! is_string( $data['sha256'] ?? null ) ) {
			return null;
		}

		return [
			'backup_id'  => $data['backup_id'],
			'created_at' => $data['created_at'],
			'entries'    => $data['entries'],
			'sha256'     => $data['sha256'],
		];
	}

	/**
	 * L'état local peut-il servir à une incrémentale de parent $parent_id ?
	 *
	 * @return array{file: string|null, reason: string|null} `reason` : baseline_missing,
	 *         baseline_other_backup, baseline_too_old, baseline_unreadable.
	 */
	public function usable_for( string $parent_id, int $now ): array {
		$current = $this->current();
		if ( null === $current ) {
			return self::refused( 'baseline_missing' );
		}
		if ( ! hash_equals( $current['backup_id'], $parent_id ) ) {
			return self::refused( 'baseline_other_backup' );
		}
		if ( $now - $current['created_at'] > self::MAX_AGE_SECONDS ) {
			return self::refused( 'baseline_too_old' );
		}
		$file = $this->dir . '/' . $current['backup_id'] . '.jsonl';
		$hash = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( false === $hash || ! hash_equals( $current['sha256'], $hash ) ) {
			return self::refused( 'baseline_unreadable' );
		}

		return [
			'file'   => $file,
			'reason' => null,
		];
	}

	/**
	 * Ligne de l'état local pour une entrée du parcours.
	 *
	 * @param array{path: string, type: string, size: int, mtime: int} $entry
	 */
	public static function line( array $entry, ?string $sha256 ): string {
		$path   = 1 === preg_match( '//u', $entry['path'] ) ? [ 'p' => $entry['path'] ] : [ 'pb' => base64_encode( $entry['path'] ) ];
		$fields = $path + [
			'k' => $entry['type'],
			's' => $entry['size'],
			'm' => $entry['mtime'],
			'h' => $sha256,
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- ligne de l'état local, chemin non UTF-8 déjà passé en base64.
		return json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
	}

	/**
	 * @return array{path: string, type: string, size: int, mtime: int, sha256: string|null}|null
	 */
	public static function decode_line( string $line ): ?array {
		$data = json_decode( $line, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$path = is_string( $data['p'] ?? null ) ? $data['p'] : ( is_string( $data['pb'] ?? null ) ? base64_decode( $data['pb'], true ) : false );
		if ( ! is_string( $path ) || ! is_string( $data['k'] ?? null ) || ! is_int( $data['s'] ?? null ) || ! is_int( $data['m'] ?? null ) ) {
			return null;
		}

		return [
			'path'   => $path,
			'type'   => $data['k'],
			'size'   => $data['s'],
			'mtime'  => $data['m'],
			'sha256' => is_string( $data['h'] ?? null ) ? $data['h'] : null,
		];
	}

	/**
	 * Même entrée que dans l'état local : même nature, même taille, même date.
	 *
	 * @param array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null} $entry
	 * @param array{path: string, type: string, size: int, mtime: int, sha256: string|null}          $base
	 */
	public static function same( array $entry, array $base ): bool {
		return $entry['type'] === $base['type'] && $entry['size'] === $base['size'] && $entry['mtime'] === $base['mtime'];
	}

	/**
	 * Fait de $source l'état local de la complète $backup_id ; retire les états locaux précédents
	 * (fichiers `<32 hex>.jsonl` du module). Faux si impossible (la sauvegarde reste réussie ; la
	 * prochaine incrémentale deviendra une complète, et le dira).
	 */
	public function promote( string $source, string $backup_id, int $created_at ): bool {
		$target = $this->dir . '/' . $backup_id . '.jsonl';
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- fichier du module déplacé du dossier de la sauvegarde vers baseline/, dans le même dossier privé protégé ; échec rendu par la valeur de retour.
			return false;
		}
		$hash  = hash_file( 'sha256', $target );
		$lines = 0;
		$h     = @fopen( $target, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- comptage des lignes de l'état local.
		if ( false === $hash || false === $h ) {
			return false;
		}
		while ( false !== fgets( $h ) ) {
			++$lines;
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du flux ouvert ci-dessus.
		$description = [
			'backup_id'  => $backup_id,
			'created_at' => $created_at,
			'entries'    => $lines,
			'sha256'     => $hash,
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- description de l'état local, écrite après lui.
		$written = file_put_contents( $this->dir . '/' . self::CURRENT, (string) json_encode( $description ) );
		foreach ( (array) glob( $this->dir . '/*.jsonl' ) as $old ) {
			$name = basename( (string) $old );
			if ( 1 === preg_match( '/\A[0-9a-f]{32}\.jsonl\z/', $name ) && $backup_id . '.jsonl' !== $name ) {
				wp_delete_file( (string) $old );
			}
		}

		return false !== $written;
	}

	/**
	 * @return array{file: null, reason: string}
	 */
	private static function refused( string $reason ): array {
		return [
			'file'   => null,
			'reason' => $reason,
		];
	}
}
