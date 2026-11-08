<?php
/**
 * Journal du manifeste d'une sauvegarde (docs/backup-payload.md § 5) : une ligne JSON par objet,
 * ajoutée au fichier `manifest.jsonl` du dossier de la sauvegarde au fil des tranches, puis rendue
 * par morceaux d'au plus 1 Mio dans le segment du manifeste. Encodage exact du contrat (vecteurs
 * `manifest_lines`) : JSON sans espace, `/` et Unicode non échappés, U+2028 et U+2029 compris ; un
 * chemin ou un nom de table qui n'est pas de l'UTF-8 valide s'écrit en base64 (`path_b64`,
 * `name_b64`, au même rang).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

use G2RD\Connector\Backup\Commands\BackupRefused;

final class ManifestJournal {

	private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

	private const CHUNK_BYTES = 1048576;

	private string $file;

	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * @param array<string, mixed> $line
	 * @throws \JsonException Valeur impossible à encoder (erreur de programmation : chemin ou nom non passé par path_field() ou name_field()).
	 */
	public static function encode( array $line ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- octets exacts du contrat (vecteurs manifest_lines) ; wp_json_encode() réécrirait une chaîne non UTF-8.
		return json_encode( $line, self::FLAGS ) . "\n";
	}

	/**
	 * @return array{path: string}|array{path_b64: string}
	 */
	public static function path_field( string $path ): array {
		return 1 === preg_match( '//u', $path ) ? [ 'path' => $path ] : [ 'path_b64' => base64_encode( $path ) ];
	}

	/**
	 * Nom d'une table tel que lu par la connexion (jeu `db_charset` de la tête) : `name_b64` s'il
	 * n'est pas de l'UTF-8 valide (`wp_café` lu en latin1).
	 *
	 * @return array{name: string}|array{name_b64: string}
	 */
	public static function name_field( string $name ): array {
		return 1 === preg_match( '//u', $name ) ? [ 'name' => $name ] : [ 'name_b64' => base64_encode( $name ) ];
	}

	/**
	 * @param array<string, mixed> $line
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE si l'écriture échoue.
	 */
	public function append( array $line ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- journal du manifeste dans le dossier de travail protégé, ajouté ligne à ligne.
		if ( false === file_put_contents( $this->file, self::encode( $line ), FILE_APPEND ) ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}

	/**
	 * Rend le journal par morceaux, dans l'ordre.
	 *
	 * @param \Closure(int, string): void $emit Reçoit le décalage et le morceau.
	 * @return int Octets rendus.
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE si le journal est illisible.
	 */
	public function stream( \Closure $emit ): int {
		$handle = @fopen( $this->file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lecture en flux du journal du manifeste.
		if ( false === $handle ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$offset = 0;
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, self::CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- lecture en flux du journal du manifeste.
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$emit( $offset, $chunk );
				$offset += strlen( $chunk );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du flux ouvert ci-dessus.
		}

		return $offset;
	}
}
