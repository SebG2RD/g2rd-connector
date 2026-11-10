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
 * Jamais à travers un lien (within_private(), link_between()) : un lien symbolique, même cassé, ou
 * une jonction Windows placé dans le dossier privé ou à sa place ne fait jamais écrire, lire ni
 * supprimer quoi que ce soit hors de lui. Le départ ou la tranche est refusé, le nettoyage passe
 * son chemin, et le lien reste. Chaque fichier du module est ouvert par open_private(), qui
 * revérifie au moment même de l'ouverture (un lien posé pendant la tranche, après le contrôle de
 * son début, ne reçoit rien) et refuse aussi un lien physique (fichier à plusieurs noms).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Support\PathRules;

final class WorkDir {

	/** Fichiers d'une sauvegarde, en plus des segments `seg-<n>.part` et `seg-<n>.seg`. */
	private const JOB_FILES = [ 'segments.jsonl', 'manifest.jsonl', 'baseline-new.jsonl', 'index.bin', 'index.sig' ];

	/** Journaux d'une sauvegarde écrits en ajout, tronqués à leur longueur validée (choix n° 15). */
	public const JOURNALS = [
		'manifest' => 'manifest.jsonl',
		'baseline' => 'baseline-new.jsonl',
		'segments' => 'segments.jsonl',
	];

	/** Modes de open_private() : fichier neuf, ajout, fichier existant à modifier, lecture. */
	private const OPEN_MODES = [ 'xb', 'ab', 'r+b', 'rb' ];

	/** Bits du type de fichier dans le mode de stat(), et la valeur d'un fichier ordinaire. */
	private const TYPE_MASK = 0170000;

	private const TYPE_FILE = 0100000;

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
	 * privé (sels changés) de ses seuls fichiers du module ; faux si impossible, ou si un lien (même
	 * cassé) est à la place du dossier de travail lui-même, du dossier privé, de `baseline/` ou d'un
	 * fichier de protection (rien n'est alors créé ni écrit à travers lui). Les liens sont revus avant
	 * chaque création et à la fin, et chaque protection est écrite par write_guard() : un lien posé
	 * pendant ensure() ne reçoit aucune protection. Idempotent.
	 */
	public static function ensure(): bool {
		$dir     = self::path();
		$private = self::private_dir();
		// Avant toute création : un dossier de travail qui est un lien (vers wp-content/uploads, par
		// exemple) recevrait sinon des protections « Require all denied » qui couperaient sa cible.
		if ( self::link_in_work_dir() ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		foreach ( [ $dir, $private ] as $folder ) {
			if ( self::link_in_work_dir() || ( ! is_dir( $folder ) && ! wp_mkdir_p( $folder ) ) ) {
				return false;
			}
			self::remove_guard_leftovers( $folder );
			foreach ( self::GUARDS as $name => $content ) {
				$file = $folder . '/' . $name;
				if ( ! file_exists( $file ) && ! self::write_guard( $file, $content ) ) {
					return false;
				}
			}
		}
		if ( self::link_in_work_dir() || ( ! is_dir( self::baseline_dir() ) && ! wp_mkdir_p( self::baseline_dir() ) ) ) {
			return false;
		}
		self::sweep_stale();
		// Revu à la fin : un lien posé pendant ensure() (protection comprise) refuse aussi.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- contrôle du dossier de travail avant d'y écrire un segment.
		return ! self::link_in_work_dir() && is_writable( $private );
	}

	/**
	 * Longueurs des journaux de la sauvegarde (octets ; 0 pour un journal absent).
	 *
	 * @return array{manifest: int, baseline: int, segments: int}
	 */
	public static function journal_sizes( string $job ): array {
		clearstatcache( true );
		$sizes = [];
		foreach ( self::JOURNALS as $key => $file ) {
			// lstat() : la taille d'un lien n'est jamais celle de sa cible.
			$stat          = self::lstat_of( $job . '/' . $file );
			$sizes[ $key ] = false !== $stat && self::TYPE_FILE === ( $stat['mode'] & self::TYPE_MASK ) ? (int) $stat['size'] : 0;
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
	 * une longueur validée est négative (état abîmé : rien n'est tronqué), si un journal n'est pas
	 * un fichier ordinaire à lui seul (lien, jonction, lien physique : open_private()) ou si la
	 * troncature échoue.
	 *
	 * @param array<string, mixed> $committed Longueurs validées, clés de JOURNALS.
	 */
	public static function truncate_journals( string $job, array $committed ): bool {
		clearstatcache( true );
		foreach ( self::JOURNALS as $key => $file ) {
			$length = is_int( $committed[ $key ] ?? null ) ? $committed[ $key ] : 0;
			$path   = $job . '/' . $file;
			$stat   = self::lstat_of( $path );
			$size   = false === $stat ? 0 : (int) $stat['size'];
			if ( $length < 0 || $size < $length ) {
				return false;
			}
			if ( $size === $length ) {
				continue;
			}
			$handle = self::open_private( $path, 'r+b' );
			if ( null === $handle ) {
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
		$dir = self::private_dir() . '/job-' . $backup_id;
		// Jamais à travers un lien. Un lien au chemin du dossier (même vers un dossier, même cassé), une
		// jonction Windows, un dossier privé qui est un lien (refusé par ensure()), ou un fichier du
		// module qui est un lien (reste d'un départ précédent, que le nettoyage ne touche pas) : null,
		// le départ ou la tranche est refusé. Sinon le journal, les segments ou l'index seraient écrits
		// dans la cible du lien (wp-config.php, par exemple). Vérifié à chaque appel, donc à chaque tranche.
		if ( self::link_in_job( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) ) {
			return null;
		}
		// Revu après la création : un lien posé entre-temps (autre processus) refuse aussi. Le départ
		// retire alors le dossier qu'il vient de créer, sans suivre de lien (discard_new_job()).
		return self::blocked_by_link( $backup_id ) ? null : $dir;
	}

	/**
	 * Un lien (symbolique, même cassé, ou jonction) bloque-t-il le dossier de travail ou celui de
	 * $backup_id : à la place d'un fichier de protection, du dossier privé, de `baseline/`, du
	 * dossier de la sauvegarde ou d'un fichier du module qui y est ? C'est alors la cause d'un job()
	 * à null, et le refus le dit (BackupRefused::DETAIL_LINK) au lieu d'accuser les droits du dossier.
	 *
	 * @throws \InvalidArgumentException Identifiant qui n'est pas 32 hex minuscules.
	 */
	public static function blocked_by_link( string $backup_id ): bool {
		self::assert_id( $backup_id );
		return self::link_in_work_dir() || self::link_in_job( self::private_dir() . '/job-' . $backup_id );
	}

	/**
	 * Ouvre un fichier du module rangé dans le dossier privé, jamais à travers un lien ; null sinon
	 * (rien n'est alors lu ni écrit). Le chemin est vérifié (within_private()), puis le fichier :
	 * présent, ce doit être un fichier ordinaire à un seul nom (lstat() : ni lien symbolique, ni
	 * jonction, ni lien physique) ; un fichier qui n'est pas ordinaire est refusé avant toute
	 * ouverture. Puis le descripteur ouvert est comparé au chemin (fstat() et lstat() : même
	 * appareil, même inode, un seul nom, le même qu'avant l'ouverture), et le chemin revérifié ;
	 * sinon il est fermé sans rien écrire ni lire. C'est ce contrôle après l'ouverture qui protège :
	 * PHP résout les liens avant d'ouvrir, et suit un lien cassé même en mode `xb`. Limite qui
	 * reste, inévitable en PHP (ni O_NOFOLLOW ni openat()) : un lien cassé posé entre le lstat() et
	 * l'ouverture (fichier absent ouvert en `xb`, ou fichier existant ouvert en `ab`, deux modes qui
	 * créent) fait créer un fichier VIDE à sa cible, aussitôt refermé sans rien y écrire ; rien
	 * d'autre n'est jamais écrit hors du dossier privé. Modes : `xb` fichier neuf (refusé s'il
	 * existe) ; `ab` ajout à la fin, vrai O_APPEND sur un fichier existant (créé en `xb` s'il est
	 * absent) ; `r+b` fichier existant à modifier (troncature d'un journal) ; `rb` lecture.
	 *
	 * @return resource|null
	 * @throws \InvalidArgumentException Mode inconnu (erreur de programmation).
	 */
	public static function open_private( string $path, string $mode ) {
		if ( ! in_array( $mode, self::OPEN_MODES, true ) ) {
			throw new \InvalidArgumentException( 'Mode d\'ouverture inconnu (erreur de programmation).' );
		}
		if ( ! self::within_private( $path ) ) {
			return null;
		}
		$before = self::lstat_of( $path );
		if ( false === $before ? in_array( $mode, [ 'r+b', 'rb' ], true ) : ( 'xb' === $mode || ! self::plain_file( $before ) ) ) {
			return null;
		}
		$handle = @fopen( $path, false === $before ? 'xb' : $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fichier du module dans le dossier privé, contrôlé avant et après l'ouverture ; échec rendu par la valeur de retour.
		if ( false === $handle ) {
			return null;
		}
		$opened = fstat( $handle );
		$after  = self::lstat_of( $path );
		$same   = false !== $opened
			&& false !== $after
			&& self::plain_file( $opened )
			&& self::plain_file( $after )
			&& self::same_file( $opened, $after )
			&& ! self::link_between( self::path(), $path ); // Aucun lien apparu sur le chemin entre-temps.
		if ( $same && false !== $before ) {
			// Le fichier ouvert est celui qui a été contrôlé avant l'ouverture, pas un autre mis à sa place.
			$same = self::same_file( $before, $after );
		}
		if ( ! $same ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermé sans rien écrire.
			return null;
		}

		return $handle;
	}

	/**
	 * Remplace le contenu d'un fichier du module du dossier privé : écrit dans un fichier neuf au nom
	 * aléatoire, à côté (open_private(), `xb`), puis mis à sa place par rename(), qui remplace un lien
	 * posé à ce chemin sans jamais le suivre (sous Windows, une jonction ne se remplace pas : refus).
	 * Faux si impossible ; le fichier neuf est alors retiré.
	 */
	public static function replace_private( string $path, string $content ): bool {
		$temp   = $path . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$handle = self::open_private( $temp, 'xb' );
		if ( null === $handle ) {
			return false;
		}
		$written = fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- fichier neuf ouvert par open_private().
		$closed  = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du fichier écrit.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- fichier neuf mis à sa place dans le même dossier ; échec rendu par la valeur de retour.
		if ( strlen( $content ) !== $written || ! $closed || ! self::within_private( dirname( $path ) ) || ! @rename( $temp, $path ) ) {
			self::remove_private_file( $temp );
			return false;
		}

		return true;
	}

	/** Contenu d'un fichier du module du dossier privé, lu par open_private() ; null si refusé ou illisible. */
	public static function read_private( string $path ): ?string {
		$handle = self::open_private( $path, 'rb' );
		if ( null === $handle ) {
			return null;
		}
		$content = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du fichier ouvert ci-dessus.

		return false === $content ? null : $content;
	}

	/**
	 * Retire un fichier du module du dossier privé : un fichier ordinaire seulement, atteint sans
	 * lien (jamais un lien, une jonction ni un dossier, que le module ne crée pas). Vrai s'il n'y a
	 * plus rien à ce chemin.
	 */
	public static function remove_private_file( string $path ): bool {
		if ( ! self::within_private( $path ) ) {
			return false;
		}
		$stat = self::lstat_of( $path );
		if ( false === $stat ) {
			return true;
		}
		if ( self::TYPE_FILE !== ( $stat['mode'] & self::TYPE_MASK ) ) {
			return false;
		}
		wp_delete_file( $path );

		return false === self::lstat_of( $path );
	}

	/**
	 * Refus WORK_DIR_UNAVAILABLE d'une lecture ou d'une écriture refusée sur $path : avec la phrase
	 * du lien (BackupRefused::DETAIL_LINK) si un lien ou un lien physique est en cause, à ce chemin
	 * ou dans le dossier de travail ; sinon la phrase des droits du dossier.
	 */
	public static function unavailable( string $path ): BackupRefused {
		return new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE, null, self::link_detail( self::foreign( $path ) || self::link_in_work_dir() ) );
	}

	/**
	 * Refus WORK_DIR_UNAVAILABLE quand job() rend null pour $backup_id : avec la phrase du lien si
	 * un lien en est la cause (blocked_by_link()), sinon la phrase des droits du dossier.
	 *
	 * @throws \InvalidArgumentException Identifiant qui n'est pas 32 hex minuscules.
	 */
	public static function job_unavailable( string $backup_id ): BackupRefused {
		return new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE, null, self::link_detail( self::blocked_by_link( $backup_id ) ) );
	}

	/**
	 * Détail du refus : le dossier de travail lui-même est un lien (DETAIL_WORK_DIR_LINK, « ou à sa
	 * place »), un autre lien est en cause (DETAIL_LINK), ou aucun.
	 */
	private static function link_detail( bool $link ): string {
		if ( self::work_dir_is_link() ) {
			return BackupRefused::DETAIL_WORK_DIR_LINK;
		}

		return $link ? BackupRefused::DETAIL_LINK : '';
	}

	/**
	 * $path est-il un lien, un lien physique, ou rangé derrière un lien (hors du dossier privé, ou
	 * atteint à travers un lien) ? Un fichier absent ne l'est pas.
	 */
	public static function foreign( string $path ): bool {
		return ! self::within_private( $path ) || self::hard_linked( $path );
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
	 * lien, ou que l'on n'atteint qu'à travers un lien (dossier privé qui en est un, jonction
	 * Windows), n'est pas touché. Ne lève jamais et n'avertit jamais ; faux si le dossier reste
	 * (l'appelant rend son refus d'origine tel quel).
	 */
	public static function discard_new_job( string $backup_id ): bool {
		try {
			self::assert_id( $backup_id );
			$dir = self::private_dir() . '/job-' . $backup_id;
			if ( ! self::within_private( $dir ) || ! is_dir( $dir ) ) {
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
	 * étranger n'est jamais touché, ni un dossier privé qui est un lien (clear_private()), ni un
	 * dossier de travail qui en est un : rien n'est alors fait.
	 */
	public static function remove_all(): void {
		$dir = self::path();
		if ( self::work_dir_is_link() || ! is_dir( $dir ) ) {
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

	/**
	 * Retire les fichiers du module d'un dossier privé, puis le dossier s'il ne garde que ses
	 * protections. Jamais à travers un lien : un dossier privé qui est un lien (même vers un dossier)
	 * ou une jonction n'est pas parcouru ; rien n'est retiré dans sa cible, et le lien reste.
	 */
	private static function clear_private( string $private_path ): void {
		if ( self::link_between( self::path(), $private_path ) || ! is_dir( $private_path ) ) {
			return;
		}
		foreach ( (array) scandir( $private_path ) as $name ) {
			if ( 1 === preg_match( '/\Ajob-[0-9a-f]{32}\z/', (string) $name ) ) {
				self::clear_job_dir( $private_path . '/' . $name );
			}
		}
		// `baseline/` qui est un lien (ou une jonction) : jamais parcouru, rien n'est retiré dans sa
		// cible ; un fichier de l'état local qui est un lien reste.
		$baseline = $private_path . '/baseline';
		if ( ! self::link_between( $private_path, $baseline ) && is_dir( $baseline ) ) {
			foreach ( (array) scandir( $baseline ) as $name ) {
				$name = (string) $name;
				if ( 1 === preg_match( '/\A(current\.json|current\.json\.[0-9a-f]{16}\.tmp|[0-9a-f]{32}\.jsonl)\z/', $name ) && ! self::link_between( $baseline, $baseline . '/' . $name ) ) {
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

	/**
	 * Nom d'un fichier que le module écrit dans un dossier `job-<id>` (journaux, index, segments, et
	 * fichier neuf d'un index pas encore mis à sa place : replace_private()).
	 */
	private static function is_job_file( string $name ): bool {
		return in_array( $name, self::JOB_FILES, true ) || 1 === preg_match( '/\A(seg-[0-9]{1,4}\.(part|seg)|index\.(bin|sig)\.[0-9a-f]{16}\.tmp)\z/', $name );
	}

	/**
	 * $path, le dossier privé ou un chemin qui en part, s'atteint-il depuis le dossier de travail
	 * sans traverser aucun lien (link_between()) ? Règle commune du module avant d'écrire, de lire
	 * ou de retirer quoi que ce soit dans le dossier privé : faux pour un chemin hors de lui, pour un
	 * dossier privé qui est un lien, et pour un lien (même cassé) ou une jonction entre lui et $path.
	 * Un élément absent n'a rien à suivre : vrai pour un fichier pas encore créé.
	 */
	public static function within_private( string $path ): bool {
		$private = PathRules::normalize( self::private_dir() );
		$path    = PathRules::normalize( $path );
		return ( $path === $private || PathRules::is_inside( $path, $private ) ) && ! self::link_between( self::path(), $path );
	}

	/**
	 * Un lien sépare-t-il $path de $base ? Chaque élément entre $base (exclu) et $path (compris) est
	 * examiné par is_link_at() : un lien symbolique, même cassé, ou une jonction Windows (vers un
	 * dossier, un fichier ou rien) rend vrai. Un élément absent n'a rien à suivre. Vrai aussi pour un
	 * $path qui n'est pas sous $base, qui contient `.` ou `..`, ou qui porte un octet nul (PHP 8 ne
	 * lève pas toujours d'erreur pour lui : refusé avant tout) : rien n'y est alors lu, écrit ni
	 * retiré. Un dossier wp-content placé derrière un lien par l'hébergeur reste accepté : $base est
	 * exclu, et les chemins réels de ses éléments le traversent tous. Cache des chemins réels vidé
	 * d'abord (un dossier remplacé par un lien y resterait résolu à son ancien chemin).
	 */
	public static function link_between( string $base, string $path ): bool {
		if ( str_contains( $base . $path, "\0" ) ) {
			return true;
		}
		$base = PathRules::normalize( $base );
		$path = PathRules::normalize( $path );
		if ( $path === $base ) {
			return false;
		}
		if ( ! PathRules::is_inside( $path, $base ) ) {
			return true;
		}
		clearstatcache( true );
		foreach ( explode( '/', substr( $path, strlen( $base ) + 1 ) ) as $name ) {
			if ( in_array( $name, [ '', '.', '..' ], true ) || self::is_link_at( $base, $name ) ) {
				return true;
			}
			$base .= '/' . $name;
		}

		return false;
	}

	/**
	 * `$base/$name` est-il un lien, ou un élément dont la place ne se vérifie pas ? Vrai pour un lien
	 * symbolique (même cassé) et pour une jonction Windows vers un dossier, vers un fichier ou vers
	 * rien : PHP y rend is_link(), is_dir() et is_file() faux, mais son chemin réel (realpath() suit
	 * la jonction, même cassée) n'est pas là où elle est rangée. Vrai aussi quand l'élément existe
	 * mais qu'un chemin réel ne se lit pas : refus plutôt que supposition. Faux pour un élément
	 * absent (rien à suivre). Règle stricte du module ; PathRules::crosses_link(), qui sert à la
	 * mesure de la taille du site, reste tel quel.
	 */
	private static function is_link_at( string $base, string $name ): bool {
		$path = $base . '/' . $name;
		if ( is_link( $path ) ) {
			return true;
		}
		// is_link() garde en cache le lstat() d'une jonction (Windows) : vidé avant ce qui suit.
		clearstatcache( true );
		if ( ! @file_exists( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir : aucun avertissement, qui porterait le chemin.
			return false;
		}
		$real_base = @realpath( PathRules::is_root( $base ) ? $base . '/' : $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idem.
		$real_path = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idem.
		if ( false === $real_base || false === $real_path ) {
			return true;
		}
		$expected = PathRules::normalize( $real_base ) . '/' . $name;
		$actual   = PathRules::normalize( $real_path );
		// Windows ne distingue pas la casse, et realpath() y rend celle du disque.
		return '\\' === DIRECTORY_SEPARATOR ? 0 !== strcasecmp( $actual, $expected ) : $actual !== $expected;
	}

	/**
	 * Un lien est-il là où le module écrit hors des dossiers des sauvegardes : à la place du dossier
	 * de travail lui-même (work_dir_is_link()), d'un fichier de protection du dossier de travail ou
	 * du dossier privé (un lien cassé serait écrit à travers lui, sa cible créée ailleurs), du
	 * dossier privé (ou entre lui et le dossier de travail), ou de `baseline/` (état local des
	 * incrémentales, lu, promu et retiré par IncrementalBaseline) ? Relu sur le disque à chaque appel.
	 *
	 * @phpstan-impure
	 */
	private static function link_in_work_dir(): bool {
		if ( self::work_dir_is_link() ) {
			return true;
		}
		$dir     = self::path();
		$private = self::private_dir();
		foreach ( array_keys( self::GUARDS ) as $guard ) {
			if ( self::link_between( $dir, $dir . '/' . $guard ) || self::link_between( $dir, $private . '/' . $guard ) ) {
				return true;
			}
		}

		return ! self::within_private( self::baseline_dir() );
	}

	/**
	 * Le dossier de travail (`wp-content/g2rd-backups`, ou celui du filtre) est-il lui-même un lien ?
	 * Seul son dernier élément est examiné : un wp-content placé derrière un lien par l'hébergeur, ou
	 * un déploiement `current -> releases/…`, reste accepté.
	 */
	private static function work_dir_is_link(): bool {
		$dir = PathRules::normalize( self::path() );
		return str_contains( $dir, "\0" ) || self::is_link_at( dirname( $dir ), basename( $dir ) );
	}

	/**
	 * Un lien est-il au chemin du dossier `job-<id>` (ou avant lui, depuis le dossier de travail), ou
	 * à la place d'un fichier que le module y écrit (lien physique compris) ? Nom comparé sans la
	 * casse : sous Windows, `SEG-5.PART` est le fichier `seg-5.part` que le module écrirait. Dossier
	 * absent : rien à suivre.
	 */
	private static function link_in_job( string $dir ): bool {
		if ( ! self::within_private( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dossier illisible : aucun lien lu, l'écriture qui suit échoue d'elle-même.
			$name = (string) $name;
			if ( self::is_job_file( strtolower( $name ) ) && ( self::link_between( $dir, $dir . '/' . $name ) || self::hard_linked( $dir . '/' . $name ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fichier ordinaire à un seul nom (stat() ou fstat()) : ni lien, ni jonction, ni lien physique.
	 *
	 * @param array<int|string, int> $stat
	 */
	private static function plain_file( array $stat ): bool {
		return self::TYPE_FILE === ( $stat['mode'] & self::TYPE_MASK ) && 1 === $stat['nlink'];
	}

	/**
	 * Même fichier : même appareil, même inode.
	 *
	 * @param array<int|string, int> $a
	 * @param array<int|string, int> $b
	 */
	private static function same_file( array $a, array $b ): bool {
		return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
	}

	/** Fichier ordinaire à plusieurs noms (lien physique : son contenu est aussi celui d'un autre fichier). */
	private static function hard_linked( string $path ): bool {
		$stat = self::lstat_of( $path );

		return false !== $stat && self::TYPE_FILE === ( $stat['mode'] & self::TYPE_MASK ) && $stat['nlink'] > 1;
	}

	/**
	 * lstat() du chemin, cache vidé d'abord ; faux s'il n'y a rien (sans avertissement). lstat() ne
	 * suit jamais un lien : c'est le lien lui-même qui est décrit.
	 *
	 * @return array<int|string, int>|false
	 */
	private static function lstat_of( string $path ): array|false {
		clearstatcache( true );
		return @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- chemin absent : faux, sans avertissement.
	}

	/**
	 * Retire de $dir les fichiers neufs de protection laissés par un processus tué entre leur
	 * création et leur rename() (write_guard()) : noms exactement `<protection>.<16 hex>.tmp`,
	 * fichiers ordinaires seulement (lstat() : jamais un lien, ni sa cible). Appelé par ensure(),
	 * qui ne tourne que sous le verrou des tranches (job(), depuis le départ ou une tranche) : aucun
	 * autre ensure() n'écrit au même moment une protection que ce balayage retirerait.
	 */
	private static function remove_guard_leftovers( string $dir ): void {
		foreach ( (array) @scandir( $dir ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dossier illisible : rien à retirer.
			$stat = self::lstat_of( $dir . '/' . $name );
			if ( 1 === preg_match( '/\A(index\.php|\.htaccess|web\.config)\.[0-9a-f]{16}\.tmp\z/', (string) $name ) && false !== $stat && self::TYPE_FILE === ( $stat['mode'] & self::TYPE_MASK ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Écrit un fichier de protection absent comme replace_private() : dans un fichier neuf au nom
	 * aléatoire, à côté (qu'on ne peut deviner pour y poser un lien d'avance), contrôlé juste après
	 * son ouverture (fichier ordinaire à un seul nom, celui-là même qui est ouvert) ; puis, après une
	 * dernière vérification qu'aucun lien n'est apparu (dossier de travail lui-même, ou entre lui et
	 * la protection), mis à sa place par rename(), qui remplace un lien posé à ce chemin sans le
	 * suivre (une jonction Windows ne se remplace pas : refus). `xb` seul ne protège pas : PHP suit
	 * un lien cassé même dans ce mode. Refus : le fichier neuf est retiré s'il est toujours celui
	 * créé ici. Limite : un lien posé entre la dernière vérification et rename() n'est pas vu (PHP
	 * n'offre ni openat() ni renameat()).
	 */
	private static function write_guard( string $file, string $content ): bool {
		$temp   = $file . '.' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$handle = @fopen( $temp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fichier neuf d'une protection, contrôlé après l'ouverture ; échec rendu par la valeur de retour.
		if ( false === $handle ) {
			return false;
		}
		$opened = fstat( $handle );
		$now    = self::lstat_of( $temp );
		$ok     = false !== $opened && false !== $now && self::plain_file( $opened ) && self::plain_file( $now ) && self::same_file( $opened, $now );
		if ( $ok ) {
			$ok = strlen( $content ) === fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- fichier neuf contrôlé ci-dessus.
		}
		$ok = fclose( $handle ) && $ok; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du fichier neuf.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- fichier neuf mis à sa place dans le même dossier ; échec rendu par la valeur de retour.
		if ( $ok && ! self::work_dir_is_link() && ! self::link_between( self::path(), $file ) && @rename( $temp, $file ) ) {
			return true;
		}
		$left = self::lstat_of( $temp );
		if ( false !== $opened && false !== $left && self::same_file( $opened, $left ) ) {
			wp_delete_file( $temp ); // Le fichier neuf créé ici, et lui seul.
		}

		return false;
	}

	/**
	 * Retire les fichiers du module d'un dossier `job-<id>`, puis le dossier s'il est vide. Jamais à
	 * travers un lien : un dossier qui est un lien, qui est une jonction, ou que l'on n'atteint qu'à
	 * travers un dossier privé qui est un lien, n'est pas touché, et un fichier qui est un lien reste.
	 */
	private static function clear_job_dir( string $dir ): void {
		if ( self::link_between( self::path(), $dir ) || ! is_dir( $dir ) ) {
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
		self::remove_guard_leftovers( $dir );
		if ( [] !== array_diff( (array) scandir( $dir ), [ '.', '..', 'index.php', '.htaccess', 'web.config' ] ) ) {
			return;
		}
		foreach ( array_keys( self::GUARDS ) as $guard ) {
			wp_delete_file( $dir . '/' . $guard );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- dossier créé par le module, vide.
		rmdir( $dir );
	}

	/**
	 * Dossier de travail tel qu'un message peut le nommer : relatif au dossier qui contient
	 * wp-content (« wp-content/g2rd-backups » sur un site ordinaire, ou là où le filtre
	 * g2rd_connector_backup_dir le place), sinon son seul nom ; jamais un chemin complet du serveur.
	 */
	public static function display_name(): string {
		$relative = self::relative_to( dirname( rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) ) );

		return null === $relative || '' === $relative ? basename( self::path() ) : $relative;
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
