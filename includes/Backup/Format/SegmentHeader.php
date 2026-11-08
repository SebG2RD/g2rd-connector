<?php
/**
 * En-tête d'un segment du format chiffré des sauvegardes (G2BK v1) : 148 octets, entiers non
 * signés gros-boutistes (docs/backup-format.md § 3 et § 4, dépôt de la plateforme).
 *
 *   0   4  magic             « G2BK »
 *   4   1  format_version    01
 *   5   1  suite             01 = AES-256-GCM par blocs et scellement HPKE ; 02 réservée
 *   6   1  kind              01 base, 02 fichiers, 03 manifeste
 *   7   1  flags             00
 *   8  16  backup_id         16 octets aléatoires
 *  24   4  segment_index     0 à 4 095 en v1
 *  28   4  site_key_version  ≥ 1
 *  32   4  block_size        16 à 67 108 864
 *  36  32  hpke_enc          clé publique éphémère X25519 (HPKE enc)
 *  68  48  sealed_dek        clé de données scellée par HPKE, étiquette comprise
 * 116  32  key_commitment    HMAC-SHA256( k_engagement, préfixe )
 *
 * Préfixe = octets 0 à 35 : données associées du scellement HPKE et message de l'engagement.
 * Empreinte de l'en-tête = SHA-256 des 148 octets : données associées de chaque bloc.
 *
 * Lecture (parse) : un en-tête vient d'ailleurs (stockage, plateforme) ; rien n'y est cru avant
 * les contrôles, faits dans l'ordre du contrat (§ 12), chacun rendu par son code stable
 * (FormatError) : header_truncated en premier (moins de 148 octets : rien d'autre n'est lu), puis
 * magie, version, suite (la suite 2, secretstream, est réservée : refusée tant que la variante
 * D4-b n'est pas faite), nature, drapeaux, taille de bloc, version de clé, index de segment
 * (bad_segment_index à partir de 4 096, contrôlé après la version de clé pour qu'aucun code attendu
 * d'un vecteur existant ne change). L'ouverture de la clé scellée, l'engagement et les blocs se
 * contrôlent plus loin, chez le lecteur du segment. Seuls les 148 premiers octets sont lus :
 * parse() accepte aussi un segment entier, comme la plateforme et le service de clés.
 *
 * Écriture : prefix_bytes() construit le préfixe et refuse tout champ hors du contrat par une
 * \InvalidArgumentException (erreur de programmation : l'écrivain ne produit jamais un en-tête que
 * la lecture refuserait, ni un segment au-delà du 4 096e) ; le constructeur assemble l'en-tête à
 * partir du préfixe et des sorties du scellement et de l'engagement, et refuse de même ce que
 * parse() refuserait. Les champs fixes (magie, version, drapeaux) étant contrôlés, encode() redonne
 * exactement les octets lus.
 *
 * Sous-clés (§ 4) : la clé de données (32 octets aléatoires par segment) sert directement de PRK,
 * sans extraction ; elle ne sert qu'à ces deux dérivations et au scellement :
 * - k_bloc = HKDF-Expand( clé de données, « g2bk-v1/blocks », 32 ) chiffre les blocs. block_key()
 *   la rend : son effacement revient à l'appelant (écrivain, lecteur) ;
 * - k_engagement = HKDF-Expand( clé de données, « g2bk-v1/commit », 32 ) calcule l'engagement.
 *   commitment() l'efface lui-même avant de rendre.
 * L'engagement lie l'en-tête à une seule clé de données ; il ne prouve pas l'auteur du segment
 * (§ 4, « Auteur d'un segment », et § 16) : seul le sha256 de l'index signé l'authentifie.
 *
 * PHP 32 bits : unpack( 'N' ) y rend négatifs les entiers à partir de 2³¹. Les contrôles les
 * refusent tous (taille de bloc, version de clé, index de segment hors bornes) : refus sûr, et
 * l'autotest y échoue déjà (Sodium, self_test_failed), si bien qu'aucune sauvegarde n'y tourne.
 *
 * Aucune clé ni aucun octet reçu dans un message ; aucune exception chaînée.
 *
 * Classe pure, sans effet au chargement, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Hkdf;

final class SegmentHeader {

	/** Taille de l'en-tête, en octets. */
	public const BYTES = 148;

	/** Taille du préfixe (octets 0 à 35). */
	public const PREFIX_BYTES = 36;

	public const MAGIC = 'G2BK';

	public const FORMAT_VERSION = 1;

	/** Seule suite de la v1 : contenu AES-256-GCM par blocs et scellement HPKE (décision n° 1). */
	public const SUITE_GCM = 1;

	/** Suite réservée (secretstream, variante D4-b non retenue) : refusée à l'écriture comme à la lecture. */
	public const SUITE_SECRETSTREAM = 2;

	public const KIND_DB = 1;

	public const KIND_FILES = 2;

	public const KIND_MANIFEST = 3;

	/** Taille en clair d'un bloc, au moins (octets). */
	public const MIN_BLOCK_SIZE = 16;

	/** Taille en clair d'un bloc, au plus (64 Mio). */
	public const MAX_BLOCK_SIZE = 67108864;

	/** Info de HKDF-Expand pour k_engagement. */
	public const COMMIT_LABEL = 'g2bk-v1/commit';

	/** Info de HKDF-Expand pour k_bloc. */
	public const BLOCK_LABEL = 'g2bk-v1/blocks';

	/** Segments par sauvegarde, au plus : segment_index de 0 à 4 095 (le champ garde 4 octets). */
	public const MAX_SEGMENTS = 4096;

	private const FLAGS = 0;

	private const KINDS = [ self::KIND_DB, self::KIND_FILES, self::KIND_MANIFEST ];

	private const BACKUP_ID_BYTES = 16;

	/** Clé publique éphémère X25519 (HPKE enc). */
	private const ENC_BYTES = 32;

	/** Clé de données (32 octets) chiffrée par HPKE, étiquette (16) comprise. */
	private const SEALED_DEK_BYTES = 48;

	/** Engagement de clé, HMAC-SHA256. */
	private const COMMITMENT_BYTES = 32;

	/** Clé de données, k_bloc et k_engagement. */
	private const KEY_BYTES = 32;

	/** Le plus grand entier sur 4 octets (2³² − 1). */
	private const MAX_UINT32 = 4294967295;

	/** Octets 0 à 35, contrôlés. */
	private string $prefix;

	private string $enc;

	private string $sealed_dek;

	private string $key_commitment;

	private int $suite;

	private int $kind;

	/** 16 octets bruts. */
	private string $backup_id;

	private int $segment_index;

	private int $site_key_version;

	private int $block_size;

	/**
	 * Assemble un en-tête.
	 *
	 * @param string $prefix         Préfixe de 36 octets, de prefix_bytes().
	 * @param string $enc            Clé publique éphémère X25519 du scellement, 32 octets.
	 * @param string $sealed_dek     Clé de données scellée, 48 octets.
	 * @param string $key_commitment Engagement de clé, de commitment(), 32 octets.
	 *
	 * @throws \InvalidArgumentException Longueur fausse, ou préfixe que parse() refuserait : erreur
	 *                                   de programmation.
	 */
	public function __construct( string $prefix, string $enc, string $sealed_dek, string $key_commitment ) {
		if ( self::PREFIX_BYTES !== strlen( $prefix ) || self::ENC_BYTES !== strlen( $enc ) || self::SEALED_DEK_BYTES !== strlen( $sealed_dek ) || self::COMMITMENT_BYTES !== strlen( $key_commitment ) ) {
			throw new \InvalidArgumentException( 'En-tête G2BK impossible à assembler : il faut un préfixe de 36 octets, une clé éphémère (enc) de 32 octets, une clé de données scellée de 48 octets et un engagement de 32 octets. Cause probable : erreur de programmation (sortie du scellement ou de l\'engagement mal passée). Action : passer prefix_bytes(), puis ce que rendent le scellement HPKE et SegmentHeader::commitment().' );
		}
		if ( null !== self::prefix_error( $prefix ) ) {
			throw new \InvalidArgumentException( 'En-tête G2BK impossible à assembler : le préfixe ne respecte pas le format (magie, version, suite, nature, drapeaux, taille de bloc, version de clé ou index de segment), et la lecture le refuserait. Cause probable : préfixe écrit à la main. Action : le construire par SegmentHeader::prefix_bytes().' );
		}

		$this->prefix           = $prefix;
		$this->enc              = $enc;
		$this->sealed_dek       = $sealed_dek;
		$this->key_commitment   = $key_commitment;
		$this->suite            = ord( $prefix[5] );
		$this->kind             = ord( $prefix[6] );
		$this->backup_id        = substr( $prefix, 8, self::BACKUP_ID_BYTES );
		$this->segment_index    = self::uint32( $prefix, 24 );
		$this->site_key_version = self::uint32( $prefix, 28 );
		$this->block_size       = self::uint32( $prefix, 32 );
	}

	/**
	 * Préfixe d'un en-tête (octets 0 à 35), chaque champ contrôlé.
	 *
	 * @param int    $suite            SUITE_GCM (seule suite de la v1).
	 * @param int    $kind             KIND_DB, KIND_FILES ou KIND_MANIFEST.
	 * @param string $backup_id        Identifiant de la sauvegarde, 16 octets bruts.
	 * @param int    $segment_index    Index du segment, de 0 à MAX_SEGMENTS − 1.
	 * @param int    $site_key_version Version de la clé X25519 du site, de 1 à 2³² − 1.
	 * @param int    $block_size       Taille en clair d'un bloc, de MIN_BLOCK_SIZE à MAX_BLOCK_SIZE.
	 *
	 * @return string 36 octets.
	 *
	 * @throws \InvalidArgumentException Champ hors du contrat : erreur de programmation.
	 */
	public static function prefix_bytes( int $suite, int $kind, string $backup_id, int $segment_index, int $site_key_version, int $block_size ): string {
		if ( self::SUITE_GCM !== $suite ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : la suite doit être SegmentHeader::SUITE_GCM (1) ; la suite 2 (secretstream) est réservée et non retenue en v1. Cause probable : erreur de programmation. Action : passer SegmentHeader::SUITE_GCM.' );
		}
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : la nature du segment doit être KIND_DB (1), KIND_FILES (2) ou KIND_MANIFEST (3). Cause probable : erreur de programmation. Action : passer une des constantes KIND_* de SegmentHeader.' );
		}
		if ( self::BACKUP_ID_BYTES !== strlen( $backup_id ) ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : l\'identifiant de la sauvegarde doit faire 16 octets bruts. Cause probable : identifiant passé en hexadécimal (32 caractères), ou mal tiré. Action : passer les 16 octets tirés pour la sauvegarde (hex2bin() de l\'identifiant de l\'index).' );
		}
		if ( $block_size < self::MIN_BLOCK_SIZE || $block_size > self::MAX_BLOCK_SIZE ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : la taille de bloc doit être de 16 à 67 108 864 octets (64 Mio). Cause probable : réglage hors bornes. Action : passer une taille comprise dans ces bornes.' );
		}
		if ( $site_key_version < 1 || $site_key_version > self::MAX_UINT32 ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : la version de la clé du site doit être de 1 à 4 294 967 295. Cause probable : version absente (0) ou mal lue. Action : passer la version rendue avec la clé publique du site.' );
		}
		if ( $segment_index < 0 || $segment_index >= self::MAX_SEGMENTS ) {
			throw new \InvalidArgumentException( 'Préfixe d\'en-tête G2BK impossible à écrire : l\'index du segment doit être de 0 à 4 095 (au plus 4 096 segments par sauvegarde), sinon la lecture refuserait l\'en-tête (bad_segment_index). Cause probable : sauvegarde trop grande pour la v1, ou compteur faux. Action : arrêter la sauvegarde avant le 4 097e segment.' );
		}

		return self::MAGIC
			. chr( self::FORMAT_VERSION ) . chr( $suite ) . chr( $kind ) . chr( self::FLAGS )
			. $backup_id
			. pack( 'NNN', $segment_index, $site_key_version, $block_size );
	}

	/**
	 * Lit et contrôle les 148 premiers octets de $bytes (un en-tête, ou un segment entier).
	 *
	 * @throws FormatError Premier contrôle refusé, dans l'ordre du contrat : HEADER_TRUNCATED,
	 *                     BAD_MAGIC, UNSUPPORTED_VERSION, UNSUPPORTED_SUITE, UNKNOWN_KIND,
	 *                     RESERVED_FLAGS, BAD_BLOCK_SIZE, BAD_KEY_VERSION, BAD_SEGMENT_INDEX.
	 */
	public static function parse( string $bytes ): self {
		if ( strlen( $bytes ) < self::BYTES ) {
			throw new FormatError( FormatError::HEADER_TRUNCATED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}

		$prefix = substr( $bytes, 0, self::PREFIX_BYTES );
		$reason = self::prefix_error( $prefix );
		if ( null !== $reason ) {
			throw new FormatError( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (une constante de FormatError rendue par prefix_error()), aucune donnée reçue.
		}

		return new self(
			$prefix,
			substr( $bytes, 36, self::ENC_BYTES ),
			substr( $bytes, 68, self::SEALED_DEK_BYTES ),
			substr( $bytes, 116, self::COMMITMENT_BYTES )
		);
	}

	/** Les 148 octets de l'en-tête. */
	public function encode(): string {
		return $this->prefix . $this->enc . $this->sealed_dek . $this->key_commitment;
	}

	/** Empreinte de l'en-tête : SHA-256 brut (32 octets) des 148 octets. */
	public function hash(): string {
		return hash( 'sha256', $this->encode(), true );
	}

	public function suite(): int {
		return $this->suite;
	}

	public function kind(): int {
		return $this->kind;
	}

	/** Identifiant de la sauvegarde, 16 octets bruts (32 caractères hexadécimaux dans l'index). */
	public function backup_id(): string {
		return $this->backup_id;
	}

	public function segment_index(): int {
		return $this->segment_index;
	}

	public function site_key_version(): int {
		return $this->site_key_version;
	}

	public function block_size(): int {
		return $this->block_size;
	}

	/** Clé publique éphémère X25519 du scellement (HPKE enc), 32 octets. */
	public function enc(): string {
		return $this->enc;
	}

	/** Clé de données scellée par HPKE, étiquette comprise, 48 octets. */
	public function sealed_dek(): string {
		return $this->sealed_dek;
	}

	/** Engagement de clé, 32 octets. */
	public function key_commitment(): string {
		return $this->key_commitment;
	}

	/** Préfixe : octets 0 à 35. */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Sous-clé des blocs : k_bloc = HKDF-Expand( clé de données, BLOCK_LABEL, 32 ). Les blocs sont
	 * chiffrés sous k_bloc, jamais sous la clé de données. L'appelant l'efface après usage.
	 *
	 * @param string $dek Clé de données du segment, 32 octets.
	 *
	 * @throws \InvalidArgumentException Clé de données qui n'a pas 32 octets : erreur de programmation.
	 */
	public static function block_key( string $dek ): string {
		self::check_dek( $dek );
		return Hkdf::expand( $dek, self::BLOCK_LABEL, self::KEY_BYTES );
	}

	/**
	 * Engagement de clé : HMAC-SHA256( clé = k_engagement, message = préfixe ), avec
	 * k_engagement = HKDF-Expand( clé de données, COMMIT_LABEL, 32 ), effacée avant de rendre.
	 * Le lecteur compare le résultat à key_commitment() en temps constant (hash_equals).
	 *
	 * @param string $dek    Clé de données du segment, 32 octets.
	 * @param string $prefix Préfixe de l'en-tête, 36 octets.
	 *
	 * @return string 32 octets.
	 *
	 * @throws \InvalidArgumentException Clé de données ou préfixe de longueur fausse : erreur de
	 *                                   programmation.
	 */
	public static function commitment( string $dek, string $prefix ): string {
		self::check_dek( $dek );
		if ( self::PREFIX_BYTES !== strlen( $prefix ) ) {
			throw new \InvalidArgumentException( 'Engagement de clé G2BK impossible à calculer : le préfixe doit faire 36 octets (octets 0 à 35 de l\'en-tête). Cause probable : en-tête entier passé à la place du préfixe. Action : passer prefix_bytes() ou SegmentHeader::prefix().' );
		}

		$commit_key = Hkdf::expand( $dek, self::COMMIT_LABEL, self::KEY_BYTES );
		try {
			return hash_hmac( 'sha256', $prefix, $commit_key, true );
		} finally {
			self::wipe( $commit_key );
		}
	}

	/**
	 * Premier contrôle refusé d'un préfixe de 36 octets, dans l'ordre du contrat (§ 12), ou null.
	 *
	 * @return string|null Une constante de FormatError, ou null si le préfixe est valide.
	 */
	private static function prefix_error( string $prefix ): ?string {
		if ( self::MAGIC !== substr( $prefix, 0, 4 ) ) {
			return FormatError::BAD_MAGIC;
		}
		if ( self::FORMAT_VERSION !== ord( $prefix[4] ) ) {
			return FormatError::UNSUPPORTED_VERSION;
		}
		if ( self::SUITE_GCM !== ord( $prefix[5] ) ) {
			return FormatError::UNSUPPORTED_SUITE;
		}
		if ( ! in_array( ord( $prefix[6] ), self::KINDS, true ) ) {
			return FormatError::UNKNOWN_KIND;
		}
		if ( self::FLAGS !== ord( $prefix[7] ) ) {
			return FormatError::RESERVED_FLAGS;
		}
		$block_size = self::uint32( $prefix, 32 );
		if ( $block_size < self::MIN_BLOCK_SIZE || $block_size > self::MAX_BLOCK_SIZE ) {
			return FormatError::BAD_BLOCK_SIZE;
		}
		if ( self::uint32( $prefix, 28 ) < 1 ) {
			return FormatError::BAD_KEY_VERSION;
		}
		$segment_index = self::uint32( $prefix, 24 );
		if ( $segment_index < 0 || $segment_index >= self::MAX_SEGMENTS ) {
			return FormatError::BAD_SEGMENT_INDEX;
		}
		return null;
	}

	/**
	 * Entier non signé de 4 octets, gros-boutiste, au décalage donné. Rend −1 si la lecture
	 * échoue (impossible : la longueur est contrôlée avant), valeur que chaque contrôle refuse.
	 */
	private static function uint32( string $bytes, int $offset ): int {
		$value = unpack( 'N', $bytes, $offset );
		if ( ! is_array( $value ) || ! is_int( $value[1] ?? null ) ) {
			return -1;
		}
		return $value[1];
	}

	/**
	 * @throws \InvalidArgumentException Clé de données qui n'a pas 32 octets.
	 */
	private static function check_dek( string $dek ): void {
		if ( self::KEY_BYTES !== strlen( $dek ) ) {
			throw new \InvalidArgumentException( 'Sous-clé G2BK impossible à dériver : la clé de données doit faire 32 octets. Cause probable : erreur de programmation (clé tronquée, ou passée en hexadécimal). Action : passer les 32 octets tirés pour ce segment, ou ceux que rend l\'ouverture du scellement.' );
		}
	}

	/**
	 * Efface k_engagement (rappel transverse n° 3 du plan de la phase 2) ; ne lève jamais.
	 *
	 * Même règle que Sodium::wipe(), mais commitment() est statique (API du plan) et ne reçoit pas
	 * d'instance de Sodium : sodium_memzero() si l'extension sodium est chargée, sinon écrasement
	 * par des zéros de même longueur (PHP ne garantit pas l'effacement : l'ancienne chaîne peut
	 * rester en mémoire jusqu'à sa réutilisation). Choix par extension_loaded( 'sodium' ), jamais
	 * par function_exists() : sans l'extension, le sodium_memzero() global que définit sodium_compat
	 * lève une SodiumException.
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	private static function wipe( string &$bytes ): void {
		if ( extension_loaded( 'sodium' ) ) {
			self::wipe_native( $bytes );
			return;
		}
		$bytes = str_repeat( "\0", strlen( $bytes ) );
	}

	/**
	 * Effacement natif, seule méthode de la classe qui appelle sodium_memzero(). sodium_memzero()
	 * laisse la variable à null : elle est remise à une chaîne vide, pour garder le type.
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	private static function wipe_native( string &$bytes ): void {
		sodium_memzero( $bytes );
		$bytes = '';
	}
}
