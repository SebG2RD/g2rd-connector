<?php
/**
 * Refus d'une commande par la porte des mises à jour ou par le module de sauvegarde, avec un code
 * stable (contrat « Codes de refus » du plan de la phase 4) et, pour les refus réessayables, un
 * délai `retry_after` de 1 à 60 s. Le message suit le format du connecteur : quoi, cause probable,
 * action ; aucun détail interne, aucune clé, aucun chemin. La plateforme rédige ses phrases FR/EN à
 * partir du code (phase 5).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Commands;

final class BackupRefused extends \RuntimeException {

	public const SLICE_RUNNING = 'backup_slice_running';

	public const UPDATE_IN_PROGRESS = 'update_in_progress';

	public const LOCK_UNAVAILABLE = 'backup_lock_unavailable';

	public const STORE_UNAVAILABLE = 'backup_store_unavailable';

	public const DB_READ_FAILED = 'backup_db_read_failed';

	public const DISABLED = 'backup_disabled';

	public const NO_SITE_KEY = 'backup_no_site_key';

	public const KEY_VERSION_MISMATCH = 'backup_key_version_mismatch';

	public const CERTIFICATE_INVALID = 'site_key_certificate_invalid';

	public const KEY_DOWNGRADE = 'site_key_version_downgrade';

	public const BAD_SITE_KEY = 'backup_bad_site_key';

	public const INVALID_PAYLOAD = 'backup_invalid_payload';

	public const ALREADY_RUNNING = 'backup_already_running';

	public const UNKNOWN_BACKUP = 'backup_unknown';

	public const INTERRUPTED = 'backup_interrupted';

	public const CRYPTO_UNAVAILABLE = 'crypto_unavailable';

	public const SELF_TEST_FAILED = 'self_test_failed';

	public const DISK_LOW = 'backup_disk_low';

	public const WORK_DIR_UNAVAILABLE = 'backup_work_dir_unavailable';

	public const TOO_MANY_SEGMENTS = 'backup_too_many_segments';

	public const QUEUE_UNSUPPORTED = 'backup_queue_unsupported';

	/** Refus du dump (docs/backup-payload.md § 4), non réessayables : session lue dans un autre jeu que celui du dump. */
	public const DB_CHARSET_MISMATCH = 'backup_db_charset_mismatch';

	/** Nom de table ou de colonne d'une table copiée perdu dans le jeu du dump, ou mal formé (ident()). */
	public const DB_NAME_UNSUPPORTED = 'backup_db_name_unsupported';

	/** Préfixe hors de [A-Za-z0-9_], ou <préfixe>options absente de la liste lue : jamais de dump vide réussi. */
	public const DB_TABLES_NOT_FOUND = 'backup_db_tables_not_found';

	/** Curseur du dump ou liste des tables relus de l'état et illisibles : jamais de repli silencieux. */
	public const DB_CURSOR_INVALID = 'backup_db_cursor_invalid';

	/** Tous les codes, dans l'ordre du contrat. */
	public const CODES = [
		self::SLICE_RUNNING,
		self::UPDATE_IN_PROGRESS,
		self::LOCK_UNAVAILABLE,
		self::STORE_UNAVAILABLE,
		self::DB_READ_FAILED,
		self::DISABLED,
		self::NO_SITE_KEY,
		self::KEY_VERSION_MISMATCH,
		self::CERTIFICATE_INVALID,
		self::KEY_DOWNGRADE,
		self::BAD_SITE_KEY,
		self::INVALID_PAYLOAD,
		self::ALREADY_RUNNING,
		self::UNKNOWN_BACKUP,
		self::INTERRUPTED,
		self::CRYPTO_UNAVAILABLE,
		self::SELF_TEST_FAILED,
		self::DISK_LOW,
		self::WORK_DIR_UNAVAILABLE,
		self::TOO_MANY_SEGMENTS,
		self::QUEUE_UNSUPPORTED,
		self::DB_CHARSET_MISMATCH,
		self::DB_NAME_UNSUPPORTED,
		self::DB_TABLES_NOT_FOUND,
		self::DB_CURSOR_INVALID,
	];

	/** Délai par défaut des refus réessayables (secondes). */
	private const DEFAULT_RETRY = [
		self::SLICE_RUNNING      => 20,
		self::UPDATE_IN_PROGRESS => 60,
		self::LOCK_UNAVAILABLE   => 30,
		self::STORE_UNAVAILABLE  => 30,
		self::DB_READ_FAILED     => 30,
	];

	public const MAX_RETRY_AFTER = 60;

	private string $reason_code;

	private ?int $retry;

	private string $detail;

	private ?string $stage;

	/** Étapes d'un autotest en échec (vue d'ensemble, lot 4-C, point 2). */
	public const STAGES = [ 'start', 'segment', 'index' ];

	/**
	 * @param string      $reason_code Un des codes de CODES.
	 * @param int|null    $retry_after Délai conseillé ; ignoré pour un refus non réessayable.
	 * @param string      $detail      Raison d'une interruption, nom de table, taille : jamais une clé.
	 * @param string|null $stage       Étape d'un autotest en échec (STAGES) ; ignorée pour un autre code.
	 * @throws \InvalidArgumentException Code inconnu : erreur de programmation.
	 */
	public function __construct( string $reason_code, ?int $retry_after = null, string $detail = '', ?string $stage = null ) {
		if ( ! in_array( $reason_code, self::CODES, true ) ) {
			throw new \InvalidArgumentException( 'Code de refus de sauvegarde inconnu : ' . esc_html( $reason_code ) );
		}
		$this->reason_code = $reason_code;
		$this->retry       = isset( self::DEFAULT_RETRY[ $reason_code ] )
			? max( 1, min( self::MAX_RETRY_AFTER, $retry_after ?? self::DEFAULT_RETRY[ $reason_code ] ) )
			: null;
		$this->detail      = $detail;
		$this->stage       = self::SELF_TEST_FAILED === $reason_code && in_array( $stage, self::STAGES, true ) ? $stage : null;
		$message           = self::message_for( $reason_code, $detail );
		if ( null !== $this->stage ) {
			$message .= ' ' . self::stage_sentence( $this->stage );
		}
		parent::__construct( $message );
	}

	/**
	 * Le même refus, avec l'étape de l'autotest en échec (sans effet pour un autre code ou si
	 * l'étape est déjà connue).
	 */
	public function with_stage( string $stage ): self {
		if ( null !== $this->stage || self::SELF_TEST_FAILED !== $this->reason_code ) {
			return $this;
		}

		return new self( $this->reason_code, $this->retry, $this->detail, $stage );
	}

	public function stage(): ?string {
		return $this->stage;
	}

	public function reason_code(): string {
		return $this->reason_code;
	}

	public function retry_after(): ?int {
		return $this->retry;
	}

	/**
	 * Issue d'une commande refusée, de la forme de CommandExecutor::run().
	 *
	 * @return array{status: string, error: string, error_code: string, retry_after?: int, stage?: string}
	 */
	public function outcome(): array {
		$outcome = [
			'status'     => 'failed',
			'error'      => $this->getMessage(),
			'error_code' => $this->reason_code,
		];
		if ( null !== $this->retry ) {
			$outcome['retry_after'] = $this->retry;
		}
		if ( null !== $this->stage ) {
			$outcome['stage'] = $this->stage;
		}

		return $outcome;
	}

	private static function stage_sentence( string $stage ): string {
		return match ( $stage ) {
			'start'   => __( 'Étape : contrôle au départ de la sauvegarde.', 'g2rd-connector' ),
			'segment' => __( 'Étape : contrôle après un segment ; le segment en cours est jeté.', 'g2rd-connector' ),
			default   => __( 'Étape : signature de l’index ; aucune signature ne sera envoyée.', 'g2rd-connector' ),
		};
	}

	private static function message_for( string $code, string $detail ): string {
		return match ( $code ) {
			self::SLICE_RUNNING => __( 'L’opération n’a pas été lancée : une tranche de sauvegarde se termine sur ce site. Rien n’a été modifié. Cause probable : la sauvegarde copiait le site à cet instant. Action : aucune, la plateforme réessaie seule dans quelques secondes.', 'g2rd-connector' ),
			self::UPDATE_IN_PROGRESS => __( 'La sauvegarde n’a pas avancé : une mise à jour est en cours sur ce site. Rien n’a été copié par cette demande. Cause probable : campagne de mises à jour, ou mise à jour lancée depuis l’administration du site. Action : aucune, la plateforme relance la sauvegarde après la mise à jour.', 'g2rd-connector' ),
			self::LOCK_UNAVAILABLE => __( 'La sauvegarde n’a pas avancé : elle ne peut pas se protéger d’une mise à jour simultanée sur ce site. Rien n’a été copié par cette demande. Cause probable : la base de données de l’hébergement refuse les verrous, ou elle est momentanément indisponible. Action : aucune si cela ne dure pas ; sinon, signaler l’hébergement au support G2RD.', 'g2rd-connector' ),
			self::STORE_UNAVAILABLE => __( 'La sauvegarde n’a pas avancé : son état n’a pas pu être lu ou écrit dans la base de données du site. Rien n’a été copié par cette demande. Cause probable : base de données momentanément indisponible ou surchargée. Action : aucune, la plateforme réessaie ; si cela dure, faire vérifier la base de données du site.', 'g2rd-connector' ),
			self::DB_READ_FAILED => sprintf(
				/* translators: %s: nom de la table de la base de données. */
				__( 'La sauvegarde n’a pas pu lire la table « %s » de la base de données du site. Ce qui était déjà copié est gardé ; la tranche sera refaite. Cause probable : base momentanément indisponible, ou table endommagée. Action : aucune si cela ne se répète pas ; sinon, faire réparer la base par l’hébergeur.', 'g2rd-connector' ),
				'' === $detail ? '?' : $detail
			),
			self::DISABLED => __( 'La sauvegarde n’a pas été lancée : les sauvegardes chiffrées sont désactivées sur ce site. Rien n’a été modifié. Cause probable : case « Sauvegardes chiffrées » décochée dans les réglages du connecteur, ou constante G2RD_CONNECTOR_DISABLE_BACKUP posée dans wp-config.php. Action : cocher la case dans les réglages du connecteur G2RD (ou retirer la constante), puis relancer.', 'g2rd-connector' ),
			self::NO_SITE_KEY => __( 'La sauvegarde n’a pas été lancée : ce site n’a pas encore reçu sa clé de chiffrement. Rien n’a été modifié. Cause probable : activation des sauvegardes pas encore terminée par la plateforme. Action : relancer l’activation des sauvegardes de ce site depuis la plateforme.', 'g2rd-connector' ),
			self::KEY_VERSION_MISMATCH => __( 'La sauvegarde n’a pas été lancée : la clé de chiffrement demandée n’est pas celle que le site détient. Rien n’a été modifié. Cause probable : clé renouvelée sur la plateforme sans que le site ait reçu la nouvelle. Action : renvoyer la clé au site depuis la plateforme, puis relancer.', 'g2rd-connector' ),
			self::CERTIFICATE_INVALID => __( 'La clé de chiffrement reçue a été refusée : sa signature par le service de clés G2RD est absente, fausse, ou faite par une clé que ce connecteur ne connaît pas. Aucune clé n’a été enregistrée. Cause probable : connecteur trop ancien pour le service de clés, ou clé qui ne vient pas du service de clés G2RD. Action : mettre le connecteur à jour, puis renvoyer la clé ; si le refus se répète, le signaler au support G2RD.', 'g2rd-connector' ),
			self::KEY_DOWNGRADE => __( 'La clé de chiffrement reçue a été refusée : elle est plus ancienne que celle que le site détient déjà, ou elle remplace une clé de même version. Aucune clé n’a été enregistrée. Cause probable : réponse rejouée, ou erreur de la plateforme. Action : renvoyer la clé la plus récente depuis la plateforme ; si le refus se répète, le signaler au support G2RD.', 'g2rd-connector' ),
			self::BAD_SITE_KEY => __( 'La clé de chiffrement reçue a été refusée : elle ne permet pas de chiffrer. Aucune clé n’a été enregistrée. Cause probable : erreur du service de clés. Action : demander une nouvelle clé (rotation) depuis la plateforme ; si le refus se répète, le signaler au support G2RD.', 'g2rd-connector' ),
			self::INVALID_PAYLOAD => __( 'La commande de sauvegarde n’a pas été exécutée : ses paramètres sont absents ou d’un format inattendu. Rien n’a été modifié. Cause probable : plateforme et connecteur de versions différentes. Action : mettre le connecteur à jour, puis relancer ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::ALREADY_RUNNING => __( 'La sauvegarde n’a pas été lancée : une autre sauvegarde est en cours sur ce site. Rien n’a été modifié. Cause probable : sauvegarde précédente pas encore terminée. Action : attendre sa fin, ou l’arrêter depuis la plateforme, puis relancer.', 'g2rd-connector' ),
			self::UNKNOWN_BACKUP => __( 'La commande de sauvegarde n’a pas été exécutée : cette sauvegarde n’est pas celle en cours sur ce site. Rien n’a été modifié. Cause probable : sauvegarde déjà terminée, arrêtée, ou remplacée par une autre. Action : aucune, la plateforme vérifie l’état du site à la prochaine synchronisation.', 'g2rd-connector' ),
			self::INTERRUPTED => sprintf(
				/* translators: %s: raison de l'interruption, en clair. */
				__( 'La sauvegarde a été interrompue et ne sera pas terminée : %s. Ce qu’elle avait préparé sur le site est retiré ; elle n’est pas comptée comme réussie. Action : aucune, la plateforme la refait sous un nouveau numéro.', 'g2rd-connector' ),
				self::interruption( $detail )
			),
			self::CRYPTO_UNAVAILABLE => __( 'La sauvegarde n’a pas été lancée : le PHP de l’hébergement n’offre pas le chiffrement nécessaire (OpenSSL avec AES-256-GCM ; X25519 et Ed25519 par sodium ou par la copie livrée avec WordPress). Rien n’a été modifié. Cause probable : extension OpenSSL désactivée par l’hébergeur. Action : demander à l’hébergeur d’activer OpenSSL, puis relancer.', 'g2rd-connector' ),
			self::SELF_TEST_FAILED => __( 'La sauvegarde a été arrêtée : le site n’a pas pu chiffrer ou signer de façon fiable (contrôle à valeurs connues en échec). Aucun index n’a été envoyé ; rien n’est gardé comme sauvegarde. Cause probable : calcul cryptographique faux du PHP de l’hébergement, par exemple sous JIT. Action : relancer ; si cela se répète, signaler l’hébergement au support G2RD, les sauvegardes de ce site restant arrêtées.', 'g2rd-connector' ),
			self::DISK_LOW => sprintf(
				/* translators: %s: espace nécessaire, par exemple « 114 Mio ». */
				__( 'La sauvegarde a été arrêtée : il ne reste pas assez d’espace disque sur l’hébergement pour préparer la suite (au moins %s nécessaires). Rien n’est gardé comme sauvegarde. Cause probable : disque de l’hébergement presque plein. Action : libérer de l’espace ou agrandir l’hébergement, puis relancer.', 'g2rd-connector' ),
				'' === $detail ? '?' : $detail
			),
			self::WORK_DIR_UNAVAILABLE => __( 'La sauvegarde n’a pas été lancée : le dossier de travail wp-content/g2rd-backups n’a pas pu être créé, ou n’est pas inscriptible. Rien n’a été modifié. Cause probable : droits du dossier wp-content. Action : rendre wp-content inscriptible par PHP, puis relancer.', 'g2rd-connector' ),
			self::TOO_MANY_SEGMENTS => __( 'La sauvegarde a été arrêtée : le site dépasse la taille maximale d’une sauvegarde (4 095 morceaux, environ 256 Gio). Rien n’est gardé comme sauvegarde. Cause probable : site très volumineux, ou dossier de cache ou d’archives à exclure. Action : exclure les dossiers inutiles de la sauvegarde, ou contacter le support G2RD.', 'g2rd-connector' ),
			self::QUEUE_UNSUPPORTED => __( 'La commande de sauvegarde n’a pas été exécutée : elle est arrivée par la file des commandes, que les sauvegardes n’emploient pas encore. Rien n’a été modifié. Cause probable : plateforme plus récente que ce connecteur. Action : mettre le connecteur à jour ; la plateforme envoie les commandes de sauvegarde directement au site.', 'g2rd-connector' ),
			self::DB_CHARSET_MISMATCH => sprintf(
				/* translators: 1: jeu de caractères qu'annonce la sauvegarde, 2: jeux lus par la connexion (client, connexion, résultats). */
				__( 'La sauvegarde a été arrêtée : la base de données du site n’est pas lue dans le jeu de caractères qu’annonce la sauvegarde (annoncé : %1$s ; lu par la connexion : %2$s), et la copie pourrait ne pas se restaurer telle quelle. Rien n’est gardé comme sauvegarde. Cause probable : constante DB_CHARSET absente de wp-config.php, ou réglage de la connexion à la base modifié par une extension. Action : définir DB_CHARSET dans wp-config.php — au jeu que la connexion emploie déjà si les trois valeurs lues sont identiques, ce qui ne change rien à l’affichage du site — ou désactiver l’extension qui modifie la connexion, puis relancer la sauvegarde.', 'g2rd-connector' ),
				...self::charset_detail( $detail )
			),
			self::DB_NAME_UNSUPPORTED => sprintf(
				/* translators: %s: nom de la table (ou table.colonne), sous une forme affichable. */
				__( 'La sauvegarde a été arrêtée : le nom « %s » (table, ou table.colonne) ne peut pas s’écrire tel quel dans le jeu de caractères de la connexion à la base, et la copie ne se restaurerait pas telle quelle. Rien n’est gardé comme sauvegarde. Cause probable : caractère absent de ce jeu (DB_CHARSET de wp-config.php), par exemple une lettre accentuée avec un jeu japonais ou chinois. Action : faire renommer cette table ou cette colonne, ou faire passer la connexion en utf8mb4 (DB_CHARSET) après vérification par le développeur du site, puis relancer la sauvegarde.', 'g2rd-connector' ),
				'' === $detail ? '?' : $detail
			),
			self::DB_TABLES_NOT_FOUND => sprintf(
				/* translators: %s: nom de la table des réglages du site, par exemple « wp_options ». */
				__( 'La sauvegarde a été arrêtée : les tables du site n’ont pas été trouvées sur la base de données (table des réglages « %s » absente de la liste lue), et la copie aurait été enregistrée sans les données du site. Rien n’est gardé comme sauvegarde. Cause probable : préfixe des tables ($table_prefix de wp-config.php) qui n’est pas celui des tables de la base, ou qui contient un autre caractère que des lettres, des chiffres et _ ; ou utilisateur de la base qui ne peut pas lire la liste des tables. Action : vérifier $table_prefix dans wp-config.php, et les droits de l’utilisateur de la base auprès de l’hébergeur, puis relancer la sauvegarde.', 'g2rd-connector' ),
				'' === $detail ? '?' : $detail
			),
			self::DB_CURSOR_INVALID => __( 'La sauvegarde a été arrêtée : l’avancement de la copie de la base de données, gardé entre deux étapes, est illisible, et la reprendre pourrait copier des lignes deux fois ou en oublier. Rien n’est gardé comme sauvegarde. Cause probable : réglage g2rd_backup_state modifié hors du connecteur (extension, outil d’administration de la base), ou clé primaire d’une table changée pendant la sauvegarde. Action : relancer la sauvegarde, qui repartira du début ; si le refus revient, vérifier qu’aucune extension ne modifie les réglages du site.', 'g2rd-connector' ),
			default => throw new \InvalidArgumentException( 'Code de refus de sauvegarde sans message : ' . esc_html( $code ) ),
		};
	}

	/**
	 * Détail d'un refus DB_CHARSET_MISMATCH, « <jeu annoncé>|<client>, <connexion>, <résultats> » :
	 * les deux parties, « ? » pour une partie absente.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function charset_detail( string $detail ): array {
		$parts = explode( '|', $detail, 2 );

		return [ '' === $parts[0] ? '?' : $parts[0], ( $parts[1] ?? '' ) === '' ? '?' : $parts[1] ];
	}

	private static function interruption( string $reason ): string {
		return match ( $reason ) {
			'interrupted_by_update' => __( 'une mise à jour a changé le site pendant la copie, et une sauvegarde mélangée ne serait pas restaurable', 'g2rd-connector' ),
			'aborted_by_platform' => __( 'la plateforme l’a arrêtée', 'g2rd-connector' ),
			default => __( 'le site l’a arrêtée après un échec', 'g2rd-connector' ),
		};
	}
}
