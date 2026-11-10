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
 * Jamais à travers un lien : l'état local vit dans le dossier privé, et chacun de ses fichiers est
 * lu par Run\WorkDir::open_private() (ni lien symbolique, ni jonction, ni lien physique, contrôlé
 * au moment même de l'ouverture). Un `current.json` ou un `<backup_id>.jsonl` qui est un lien n'est
 * jamais lu : l'état local est dit illisible (l'incrémentale devient une complète, et le dit). La
 * promotion n'écrit jamais à travers un lien : la description est écrite dans un fichier neuf puis
 * mise à sa place par rename() (Run\WorkDir::replace_private()), qui remplace un lien sans le
 * suivre ; les incrémentales redeviennent alors possibles.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Content;

use G2RD\Connector\Backup\Run\WorkDir;

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
		$raw  = WorkDir::read_private( $this->dir . '/' . self::CURRENT );
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
		// Description qui est un lien, ou rangée derrière un lien : jamais lue, illisible.
		if ( ! WorkDir::within_private( $this->dir . '/' . self::CURRENT ) ) {
			return self::refused( 'baseline_unreadable' );
		}
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
		$hash = self::sha256_and_lines( $file )['sha256'];
		if ( null === $hash || ! hash_equals( $current['sha256'], $hash ) ) {
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
	 * (fichiers `<32 hex>.jsonl` du module, jamais un lien). Faux si impossible, ou si $source ou le
	 * dossier de l'état local n'est atteint qu'à travers un lien (rien n'est alors déplacé ni écrit) :
	 * la sauvegarde reste réussie ; la prochaine incrémentale deviendra une complète, et le dira.
	 * rename() remplace un lien posé à la place du fichier visé ou de la description sans le suivre.
	 */
	public function promote( string $source, string $backup_id, int $created_at ): bool {
		$target = $this->dir . '/' . $backup_id . '.jsonl';
		if ( ! WorkDir::within_private( $source ) || ! WorkDir::within_private( $this->dir ) ) {
			return false;
		}
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- fichier du module déplacé du dossier de la sauvegarde vers baseline/, dans le même dossier privé protégé ; échec rendu par la valeur de retour.
			return false;
		}
		$read = self::sha256_and_lines( $target );
		if ( null === $read['sha256'] ) {
			return false;
		}
		$description = [
			'backup_id'  => $backup_id,
			'created_at' => $created_at,
			'entries'    => $read['lines'],
			'sha256'     => $read['sha256'],
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- description de l'état local, valeurs scalaires.
		$written = WorkDir::replace_private( $this->dir . '/' . self::CURRENT, (string) json_encode( $description ) );
		foreach ( (array) glob( $this->dir . '/*.jsonl' ) as $old ) {
			$name = basename( (string) $old );
			if ( 1 === preg_match( '/\A[0-9a-f]{32}\.jsonl\z/', $name ) && $backup_id . '.jsonl' !== $name ) {
				WorkDir::remove_private_file( (string) $old ); // Un lien n'est jamais retiré, sa cible non plus.
			}
		}

		return $written;
	}

	/**
	 * Empreinte SHA-256 et nombre de lignes d'un fichier de l'état local, lu en une passe par
	 * Run\WorkDir::open_private() ; empreinte null s'il est absent, illisible ou un lien.
	 *
	 * @return array{sha256: string|null, lines: int}
	 */
	private static function sha256_and_lines( string $file ): array {
		$handle = WorkDir::open_private( $file, 'rb' );
		if ( null === $handle ) {
			return [
				'sha256' => null,
				'lines'  => 0,
			];
		}
		$context = hash_init( 'sha256' );
		$lines   = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- lecture ligne à ligne.
			hash_update( $context, $line );
			++$lines;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du fichier ouvert ci-dessus.

		return [
			'sha256' => hash_final( $context ),
			'lines'  => $lines,
		];
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
