<?php
/**
 * Dossier de travail des sauvegardes : `wp-content/g2rd-backups` (filtre g2rd_connector_backup_dir),
 * protégé comme g2rd-snapshots (index.php, .htaccess, web.config), puis un DOSSIER PRIVÉ au nom
 * imprévisible (32 hex de hash_hmac( 'sha256', 'g2rd-backups-v1', wp_salt( 'auth' ) ), mêmes
 * gardes) qui contient un sous-dossier par sauvegarde (`job-<backup_id>`) et `baseline/` (état
 * local des incrémentales). nginx et OpenLiteSpeed ne lisent pas .htaccess : la protection qui vaut
 * partout est le nom du dossier privé, qui ne se devine pas sans wp-config.php (choix n° 14 du plan
 * de la phase 4). Sels changés : l'ancien dossier privé est vidé de ses seuls fichiers du module.
 * Exclu des sauvegardes. Le nettoyage ne retire que les fichiers que le module crée ; un fichier
 * étranger n'est jamais touché.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

final class WorkDir {

	/** Fichiers d'une sauvegarde, en plus des segments `seg-<n>.part` et `seg-<n>.seg`. */
	private const JOB_FILES = [ 'segments.jsonl', 'manifest.jsonl', 'baseline-new.jsonl', 'index.bin', 'index.sig' ];

	/** Journaux d'une sauvegarde écrits en ajout, tronqués à leur longueur validée (choix n° 15). */
	public const JOURNALS = [
		'manifest' => 'manifest.jsonl',
		'baseline' => 'baseline-new.jsonl',
		'segments' => 'segments.jsonl',
	];

	private const GUARDS = [
		'index.php'  => "<?php\n// Silence is golden.\n",
		'.htaccess'  => "# G2RD Connector : sauvegardes en préparation, jamais servies par HTTP.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
	];

	public static function path(): string {
		$default = rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/g2rd-backups';
		return rtrim( str_replace( '\\', '/', (string) apply_filters( 'g2rd_connector_backup_dir', $default ) ), '/' );
	}

	/** Nom du dossier privé : 32 hex dérivés des sels de wp-config.php, imprévisible sans eux. */
	public static function private_name(): string {
		return substr( hash_hmac( 'sha256', 'g2rd-backups-v1', (string) wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function private_dir(): string {
		return self::path() . '/' . self::private_name();
	}

	public static function baseline_dir(): string {
		return self::private_dir() . '/baseline';
	}

	/**
	 * Crée le dossier, le dossier privé, leurs protections et `baseline/` ; vide un ancien dossier
	 * privé (sels changés) de ses seuls fichiers du module ; faux si impossible. Idempotent.
	 */
	public static function ensure(): bool {
		$dir     = self::path();
		$private = self::private_dir();
		foreach ( [ $dir, $private ] as $folder ) {
			if ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) {
				return false;
			}
			foreach ( self::GUARDS as $name => $content ) {
				$file = $folder . '/' . $name;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichiers de protection du dossier de travail, écrits une fois (modèle de RestorePointStore::ensure_dir()).
				if ( ! file_exists( $file ) && false === file_put_contents( $file, $content ) ) {
					return false;
				}
			}
		}
		if ( ! is_dir( self::baseline_dir() ) && ! wp_mkdir_p( self::baseline_dir() ) ) {
			return false;
		}
		self::sweep_stale();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- contrôle du dossier de travail avant d'y écrire un segment.
		return is_writable( $private );
	}

	/**
	 * Longueurs des journaux de la sauvegarde (octets ; 0 pour un journal absent).
	 *
	 * @return array{manifest: int, baseline: int, segments: int}
	 */
	public static function journal_sizes( string $job ): array {
		clearstatcache();
		$sizes = [];
		foreach ( self::JOURNALS as $key => $file ) {
			$size          = is_file( $job . '/' . $file ) ? filesize( $job . '/' . $file ) : 0;
			$sizes[ $key ] = false === $size ? 0 : $size;
		}

		return [
			'manifest' => $sizes['manifest'],
			'baseline' => $sizes['baseline'],
			'segments' => $sizes['segments'],
		];
	}

	/**
	 * Tronque chaque journal à sa longueur validée (état de la sauvegarde). Faux si un journal est
	 * plus court que sa longueur validée (perdu ou abîmé : la sauvegarde ne peut pas continuer), si
	 * une longueur validée est négative (état abîmé : rien n'est tronqué) ou si la troncature échoue.
	 *
	 * @param array<string, mixed> $committed Longueurs validées, clés de JOURNALS.
	 */
	public static function truncate_journals( string $job, array $committed ): bool {
		clearstatcache();
		foreach ( self::JOURNALS as $key => $file ) {
			$length = is_int( $committed[ $key ] ?? null ) ? $committed[ $key ] : 0;
			$path   = $job . '/' . $file;
			$size   = is_file( $path ) ? (int) filesize( $path ) : 0;
			if ( $length < 0 || $size < $length ) {
				return false;
			}
			if ( $size === $length ) {
				continue;
			}
			$handle = @fopen( $path, 'r+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- journal de la sauvegarde, dans le dossier privé ; échec rendu par la valeur de retour.
			if ( false === $handle ) {
				return false;
			}
			$done = ftruncate( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- troncature du journal à sa longueur validée (WP_Filesystem ne tronque pas).
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du journal ouvert ci-dessus.
			if ( ! $done ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Dossier de la sauvegarde $backup_id, créé au besoin ; null si impossible.
	 *
	 * @throws \InvalidArgumentException Identifiant qui n'est pas 32 hex minuscules.
	 */
	public static function job( string $backup_id ): ?string {
		self::assert_id( $backup_id );
		if ( ! self::ensure() ) {
			return null;
		}
		$private = self::private_dir();
		$dir     = $private . '/job-' . $backup_id;
		clearstatcache( true );
		// Jamais à travers un lien. Un lien au chemin du dossier (même vers un dossier), un dossier dont
		// le chemin réel n'est pas directement sous le dossier privé (jonction Windows), ou un fichier du
		// module qui est un lien (reste d'un départ précédent, que le nettoyage ne touche pas) : null,
		// le départ ou la tranche est refusé. Sinon le journal, les segments ou l'index seraient écrits
		// dans la cible du lien (wp-config.php, par exemple). Vérifié à chaque appel, donc à chaque tranche.
		if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! self::directly_under( $dir, $private ) ) {
			return null;
		}
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dossier illisible : aucun lien lu, l'écriture qui suit échoue d'elle-même.
			if ( self::is_job_file( (string) $name ) && is_link( $dir . '/' . $name ) ) {
				return null;
			}
		}
		return $dir;
	}

	/** Retire les fichiers que le module a créés pour $backup_id, puis le dossier s'il est vide. */
	public static function clear_job( string $backup_id ): void {
		self::assert_id( $backup_id );
		self::clear_job_dir( self::private_dir() . '/job-' . $backup_id );
	}

	/**
	 * Quelque chose est-il déjà au chemin du dossier de $backup_id (dossier, fichier ou lien, même
	 * cassé) ? Lu juste avant job() : rien, le dossier qu'il rend est créé par cet appel.
	 *
	 * @throws \InvalidArgumentException Identifiant qui n'est pas 32 hex minuscules.
	 */
	public static function job_exists( string $backup_id ): bool {
		self::assert_id( $backup_id );
		$dir = self::private_dir() . '/job-' . $backup_id;
		return is_link( $dir ) || file_exists( $dir );
	}

	/**
	 * Retire le dossier de $backup_id qu'un départ vient de créer, quand ce départ est refusé
	 * ensuite : les seuls fichiers du module qui y sont (jamais un lien, jamais un fichier
	 * étranger), puis le dossier s'il est vide. Rien hors du dossier privé : un dossier qui est un
	 * lien, ou dont le chemin réel n'est pas directement sous le dossier privé, n'est pas touché.
	 * Ne lève jamais et n'avertit jamais ; faux si le dossier reste (l'appelant rend son refus
	 * d'origine tel quel).
	 */
	public static function discard_new_job( string $backup_id ): bool {
		try {
			self::assert_id( $backup_id );
			$private = self::private_dir();
			$dir     = $private . '/job-' . $backup_id;
			clearstatcache( true );
			if ( is_link( $dir ) || ! is_dir( $dir ) || ! self::directly_under( $dir, $private ) ) {
				return false;
			}
			foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- retrait au mieux : un dossier illisible reste, sans avertissement.
				$name = (string) $name;
				$file = $dir . '/' . $name;
				if ( self::is_job_file( $name ) && ! is_link( $file ) && is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			$left = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- retrait au mieux : un dossier illisible reste, sans avertissement.
			if ( false === $left || [] !== array_diff( $left, [ '.', '..' ] ) ) {
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par ce départ, vide ; échec rendu par la valeur de retour.
			return @rmdir( $dir );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Désinstallation : vide chaque dossier privé (l'actuel et ceux d'anciens sels) de ses fichiers
	 * du module, puis retire les protections et le dossier s'il ne reste rien d'autre. Un fichier
	 * étranger n'est jamais touché.
	 */
	public static function remove_all(): void {
		$dir = self::path();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( 1 === preg_match( '/\A[0-9a-f]{32}\z/', (string) $name ) ) {
				self::clear_private( $dir . '/' . $name );
			}
		}
		self::remove_if_only_guards( $dir );
	}

	/** Vide les dossiers privés d'anciens sels (32 hex, autre nom que l'actuel). */
	private static function sweep_stale(): void {
		$dir     = self::path();
		$current = self::private_name();
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			if ( $name !== $current && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $name ) && is_dir( $dir . '/' . $name ) ) {
				self::clear_private( $dir . '/' . $name );
			}
		}
	}

	/** Retire les fichiers du module d'un dossier privé, puis le dossier s'il ne garde que ses protections. */
	private static function clear_private( string $private_path ): void {
		foreach ( (array) scandir( $private_path ) as $name ) {
			if ( 1 === preg_match( '/\Ajob-[0-9a-f]{32}\z/', (string) $name ) ) {
				self::clear_job_dir( $private_path . '/' . $name );
			}
		}
		$baseline = $private_path . '/baseline';
		if ( is_dir( $baseline ) ) {
			foreach ( (array) scandir( $baseline ) as $name ) {
				if ( 'current.json' === $name || 1 === preg_match( '/\A[0-9a-f]{32}\.jsonl\z/', (string) $name ) ) {
					wp_delete_file( $baseline . '/' . $name );
				}
			}
			if ( [] === array_diff( (array) scandir( $baseline ), [ '.', '..' ] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par le module, vide.
				rmdir( $baseline );
			}
		}
		self::remove_if_only_guards( $private_path );
	}

	/** Nom d'un fichier que le module écrit dans un dossier `job-<id>` (journaux, index, segments). */
	private static function is_job_file( string $name ): bool {
		return in_array( $name, self::JOB_FILES, true ) || 1 === preg_match( '/\Aseg-[0-9]{1,4}\.(part|seg)\z/', $name );
	}

	/**
	 * Le chemin réel de $path est-il directement sous le chemin réel de $base ? Faux pour un lien
	 * ou une jonction qui mène ailleurs, et pour un chemin qui n'existe pas. Un dossier wp-content
	 * placé derrière un lien par l'hébergeur reste accepté : les deux chemins réels le traversent.
	 */
	private static function directly_under( string $path, string $base ): bool {
		$real_base = realpath( $base );
		$real_path = realpath( $path );
		return false !== $real_base && false !== $real_path && dirname( $real_path ) === $real_base;
	}

	/**
	 * Retire les fichiers du module d'un dossier `job-<id>`, puis le dossier s'il est vide. Jamais à
	 * travers un lien : un dossier qui est un lien, ou dont le chemin réel n'est pas directement sous
	 * son dossier parent (jonction Windows), n'est pas touché, et un fichier qui est un lien reste.
	 */
	private static function clear_job_dir( string $dir ): void {
		clearstatcache( true );
		if ( is_link( $dir ) || ! is_dir( $dir ) || ! self::directly_under( $dir, dirname( $dir ) ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			$name = (string) $name;
			$file = $dir . '/' . $name;
			if ( self::is_job_file( $name ) && ! is_link( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		if ( [] === array_diff( (array) scandir( $dir ), [ '.', '..' ] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par le module, vide.
			rmdir( $dir );
		}
	}

	private static function remove_if_only_guards( string $dir ): void {
		if ( [] !== array_diff( (array) scandir( $dir ), [ '.', '..', 'index.php', '.htaccess', 'web.config' ] ) ) {
			return;
		}
		foreach ( array_keys( self::GUARDS ) as $guard ) {
			wp_delete_file( $dir . '/' . $guard );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par le module, vide.
		rmdir( $dir );
	}

	/** Chemin du dossier de travail relatif à $root, ou null s'il n'est pas dessous. */
	public static function relative_to( string $root ): ?string {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$path = self::path();
		return str_starts_with( $path, $root . '/' ) ? substr( $path, strlen( $root ) + 1 ) : null;
	}

	private static function assert_id( string $backup_id ): void {
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $backup_id ) ) {
			throw new \InvalidArgumentException( 'Identifiant de sauvegarde mal formé (erreur de programmation : 32 hex minuscules attendus).' );
		}
	}
}
