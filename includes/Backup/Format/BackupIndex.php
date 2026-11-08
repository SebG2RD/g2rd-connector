<?php
/**
 * Index signé d'une sauvegarde G2BK v1 (docs/backup-format.md § 7, dépôt de la plateforme) :
 * écriture des octets exacts, signature Ed25519, vérification stricte, lecture stricte.
 *
 * L'index est un objet JSON en clair, sans nom ni chemin, qui liste les segments d'une sauvegarde
 * et leurs empreintes ; le site le signe en Ed25519 sur `"g2bk-v1/index"` ‖ `00` ‖ octets de
 * l'index, tels quels (aucune canonicalisation à la vérification). Seule l'empreinte `sha256` d'un
 * segment dans l'index vérifié authentifie ce segment (§ 16 : le chiffrement n'authentifie pas son
 * auteur).
 *
 * Ordre des contrôles d'un index (§ 7.5), le même pour tout lecteur (plateforme, connecteur,
 * service de clés, outil hors ligne) :
 *  1. taille : plus de MAX_BYTES octets → `index_too_large`, sans lancer la vérification ;
 *  2. signature → `index_signature_invalid` ;
 *  3. lecture → `index_malformed`.
 * Ici, vérification et lecture sont deux fonctions. La taille n'est pas contrôlée par verify(),
 * qui ne rend qu'un booléen : l'appelant (phases 4 et 8) refuse d'abord un index de plus de
 * MAX_BYTES octets par `index_too_large`, sans appeler verify() ; puis il vérifie, puis il lit
 * par parse(), qui refait le contrôle de taille en premier. S'il tient plusieurs clés de
 * signature pour le site, il les essaie une à une par verify(), sans rien décoder (aucun champ,
 * pas même `signing_key_id`, n'est lu avant la signature), puis il exige que `signing_key_id` de
 * l'index lu soit celui de la clé qui a vérifié (signing_key_id()), sinon
 * `index_signature_invalid`. Pour une restauration, la règle « Empreinte avant le clair » (§ 7.5)
 * suit (phase 8) : aucun clair livré avant que le SHA-256 de l'objet de stockage entier soit égal
 * au `sha256` de l'entrée attendue.
 *
 * Vérification Ed25519 stricte (§ 8) : verify() passe par Sodium::ed25519_verify() et ne refait
 * aucun des cinq contrôles. Le contrôle n° 5 (sous-groupe principal) y est un seul calcul, le même
 * en natif et en compat (relecture de la tâche 13) : la clé d'ordre 2·L du vecteur
 * `cle_publique_d_ordre_2l` est refusée dans les deux moteurs ; la clé d'ordre mixte
 * (`cle_publique_d_ordre_mixte`), que la copie de sodium_compat du parc (1.24.0, avis
 * PKSA-32g2-byr9-drtw) accepte d'elle-même, est refusée en compat par ce calcul
 * (ge_double_scalarmult_vartime() avec L). Aucun appel à pk_to_curve25519() ni à ge_mul_l() ici.
 * Comportements de sodium_compat 1.24.0 que verify() ne laisse jamais sortir (relevés le
 * 24/10/2026) : \SodiumException « S >= L - Invalid signature » et « Signature is on too small of
 * an order » ; \RangeException « Internal check failed. » pour une clé hors de la courbe. Sans
 * moteur, Sodium lève FormatError::CRYPTO_UNAVAILABLE : une panne n'est pas une signature
 * invalide.
 *
 * Classe pure, sans état : rien au chargement, aucune fonction WordPress, aucune politique (la
 * bibliothèque n'enchaîne pas autotest, signature et vérification : c'est l'appelant).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Support\ArrayShape;

/**
 * @phpstan-type Segment array{index: int, kind: string, blocks: int, bytes: int, sha256: string}
 * @phpstan-type Fields array{format: string, version: int, suite: int, backup_id: string, site_ref: string, site_key_version: int, signing_key_id: string, created_at: string, type: string, parent_backup_id: string|null, payload_format: int, segments: list<Segment>, manifest_segment: int}
 */
final class BackupIndex {

	/** Préfixe du message signé : `"g2bk-v1/index"` ‖ `00` (§ 7.5). */
	public const SIGNATURE_DOMAIN = "g2bk-v1/index\x00";

	/** Taille d'un index, au plus (1 Mio). */
	public const MAX_BYTES = 1048576;

	/** Segments par sauvegarde, au plus (`index` de 0 à 4 095). */
	public const MAX_SEGMENTS = 4096;

	/** Taille d'un segment (objet de stockage, en-tête compris), au plus (1 Gio). */
	public const MAX_SEGMENT_BYTES = 1073741824;

	/** Taille d'un segment, au moins : en-tête de 148 octets et étiquette du bloc final vide. */
	private const MIN_SEGMENT_BYTES = 164;

	/** Plus grand entier lu à l'identique en JavaScript (2⁵³ − 1) : borne de tout entier de l'index. */
	private const MAX_JSON_INTEGER = 9007199254740991;

	/** `site_key_version` au plus : ce que portent les 4 octets de l'en-tête (2³² − 1). */
	private const MAX_SITE_KEY_VERSION = 4294967295;

	/** Profondeur pour json_decode() : racine, liste des segments, segment, valeur. */
	private const JSON_DEPTH = 4;

	/** Écriture et lecture de `created_at` : `AAAA-MM-JJTHH:MM:SSZ`, en UTC. */
	private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

	/** Forme de `created_at` : 20 caractères, chiffres ASCII seuls (pas `\d`). */
	private const DATE_PATTERN = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/';

	/** Première année permise (`Date.UTC()` lit une année de 0 à 99 comme 1900 + année, § 7.2). */
	private const MIN_YEAR = 1970;

	private const FORMAT = 'g2bk-index';

	private const VERSION = 1;

	private const SUITE = 1;

	private const PAYLOAD_FORMAT = 1;

	private const TYPE_FULL = 'full';

	private const TYPE_INCREMENTAL = 'incremental';

	private const KIND_DB = 'db';

	private const KIND_FILES = 'files';

	private const KIND_MANIFEST = 'manifest';

	/** Clés de la racine, dans l'ordre d'écriture du contrat (§ 7.1). */
	private const ROOT_KEYS = [
		'format',
		'version',
		'suite',
		'backup_id',
		'site_ref',
		'site_key_version',
		'signing_key_id',
		'created_at',
		'type',
		'parent_backup_id',
		'payload_format',
		'segments',
		'manifest_segment',
	];

	/** Clés d'une entrée de segment, dans l'ordre d'écriture du contrat. */
	private const SEGMENT_KEYS = [ 'index', 'kind', 'blocks', 'bytes', 'sha256' ];

	private const PUBLIC_KEY_BYTES = 32;

	/**
	 * Écrit les octets exacts d'un index (§ 7.1) : chaque champ est contrôlé par les règles de
	 * lecture du § 7.2, puis écrit dans l'ordre du contrat (quel que soit l'ordre reçu), sans
	 * espace, sans échappement de « / » ni de l'Unicode. Ce que parse() refuserait est refusé ici,
	 * et parse() relit ces octets à l'identique : encode( parse( $octets ) ) === $octets.
	 *
	 * Les champs sont ceux que rend parse() : les treize clés de la racine (valeurs fixes
	 * comprises : `format`, `version`, `suite`, `payload_format`), identifiants et empreintes en
	 * hexadécimal minuscule. L'empreinte d'une entrée de segment est donc bin2hex() du SHA-256 brut
	 * que rend SegmentWriter::finish(). Avec 4 096 segments aux valeurs maximales, l'index fait
	 * moins de 600 000 octets (§ 11) : il ne peut pas dépasser MAX_BYTES.
	 *
	 * @param array<mixed> $fields Champs de l'index.
	 *
	 * @throws \InvalidArgumentException Champ hors du contrat : erreur de programmation, le message
	 *                                   dit la règle enfreinte (jamais la valeur reçue).
	 */
	public static function encode( array $fields ): string {
		$checked = self::check( $fields );
		if ( is_string( $checked ) ) {
			throw new \InvalidArgumentException( 'Index de sauvegarde impossible à écrire : ' . $checked . '. La lecture le refuserait (index_malformed). Cause probable : erreur de programmation (champ mal construit, ou valeur hors des bornes du contrat). Action : construire les champs selon docs/backup-format.md § 7.1 et § 7.2 (dépôt de la plateforme), chaque entrée de segment à partir de ce que rend SegmentWriter::finish() (empreinte en 64 caractères hexadécimaux).' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte constant de la règle enfreinte (rendu par check()), aucune valeur reçue.
		}
		return self::to_json( $checked );
	}

	/**
	 * Lit un index dont la signature a été vérifiée (§ 7.2) : taille d'abord, puis décodage, jeu de
	 * clés exact, types et valeurs, bornes, date contrôlée par aller-retour, puis **réencodage
	 * exact** : les champs lus, réencodés par l'encodeur d'encode(), doivent redonner les octets reçus (refuse
	 * une clé en double, un espace, un ordre de clés différent, un échappement, un nombre écrit
	 * autrement : `1.0`, `1e3`, `-0`).
	 *
	 * @param string $bytes Octets de l'index, tels que reçus.
	 *
	 * @return Fields Champs lus, dans l'ordre du contrat.
	 *
	 * @throws FormatError INDEX_TOO_LARGE (plus de MAX_BYTES octets, avant tout décodage) ;
	 *                     INDEX_MALFORMED.
	 */
	public static function parse( string $bytes ): array {
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new FormatError( FormatError::INDEX_TOO_LARGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}

		try {
			$fields = json_decode( $bytes, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new FormatError( FormatError::INDEX_MALFORMED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}

		$checked = is_array( $fields ) ? self::check( $fields ) : 'racine qui n\'est pas un objet';
		if ( is_string( $checked ) || self::to_json( $checked ) !== $bytes ) {
			throw new FormatError( FormatError::INDEX_MALFORMED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		return $checked;
	}

	/**
	 * Signature Ed25519 détachée (64 octets) d'un index, sur SIGNATURE_DOMAIN ‖ octets de l'index.
	 *
	 * La phase 4 relance SelfTest::run() **juste avant** d'appeler sign(), puis vérifie la
	 * signature produite par verify() avec la clé publique du site **avant tout envoi** ; un échec
	 * de l'un ou de l'autre se conclut chez le site par `self_test_failed`, sans index envoyé, pour
	 * qu'un calcul Ed25519 faux de sodium_compat ne soit jamais pris par la plateforme pour une
	 * altération (vue d'ensemble, lots 4-B et 5-B). La bibliothèque n'enchaîne pas elle-même ces
	 * appels (elle reste sans état ni politique) ; un test le montre :
	 * test_une_signature_fausse_ne_se_verifie_pas_avec_la_cle_du_site (moteur doublé dont la
	 * signature est altérée d'un bit : verify() par le vrai moteur rend faux).
	 *
	 * Les octets signés sont ceux que rend encode() : sign() ne les relit pas.
	 *
	 * @param string $index_bytes Octets de l'index (encode()).
	 * @param string $secret_key  Clé secrète Ed25519 du site au format de sodium (graine ‖ clé
	 *                            publique, 64 octets).
	 * @param Sodium $sodium      Moteur Ed25519.
	 *
	 * @throws FormatError               CRYPTO_UNAVAILABLE sans moteur ou si le moteur échoue.
	 * @throws \InvalidArgumentException Clé secrète qui n'a pas 64 octets : erreur de programmation.
	 */
	public static function sign( string $index_bytes, string $secret_key, Sodium $sodium ): string {
		return $sodium->ed25519_sign( self::SIGNATURE_DOMAIN . $index_bytes, $secret_key );
	}

	/**
	 * Vérification Ed25519 stricte de la signature d'un index (§ 7.5 et § 8), par
	 * Sodium::ed25519_verify() (les cinq contrôles stricts, puis le vérificateur du moteur).
	 *
	 * La taille n'est pas contrôlée ici. L'appelant (phases 4 et 8) refuse d'abord un index de plus
	 * de MAX_BYTES octets par `index_too_large`, sans appeler verify() ; puis il vérifie, puis il lit
	 * par parse(), qui refait le contrôle de taille en premier. S'il tient plusieurs clés, il les
	 * essaie une à une par verify() sans rien décoder, puis il exige que `signing_key_id` de l'index
	 * lu soit celui de la clé qui a vérifié. Pour une restauration, la règle « Empreinte avant le
	 * clair » suit (phase 8).
	 *
	 * Ne lève jamais pour des octets reçus : signature ou clé de longueur fausse, contrôle strict
	 * refusé ou exception du moteur rendent faux.
	 *
	 * @param string $index_bytes Octets de l'index, tels que reçus.
	 * @param string $signature   Signature détachée, 64 octets.
	 * @param string $public_key  Clé publique Ed25519 du site, 32 octets.
	 * @param Sodium $sodium      Moteur Ed25519.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur : une panne n'est pas une signature
	 *                     invalide.
	 */
	public static function verify( string $index_bytes, string $signature, string $public_key, Sodium $sodium ): bool {
		return $sodium->ed25519_verify( $signature, self::SIGNATURE_DOMAIN . $index_bytes, $public_key );
	}

	/**
	 * `signing_key_id` d'une clé publique Ed25519 (§ 7.4) : 8 premiers octets de SHA-256, en
	 * 16 caractères hexadécimaux minuscules. Identifiant technique : jamais seul pour une
	 * comparaison humaine (empreinte de comparaison de 16 octets, phase 4).
	 *
	 * @param string $public_key Clé publique Ed25519, 32 octets.
	 *
	 * @throws \InvalidArgumentException Clé qui n'a pas 32 octets : erreur de programmation.
	 */
	public static function signing_key_id( string $public_key ): string {
		if ( self::PUBLIC_KEY_BYTES !== strlen( $public_key ) ) {
			throw new \InvalidArgumentException( 'Identifiant de clé de signature impossible à calculer : la clé publique Ed25519 doit faire 32 octets bruts. Cause probable : clé passée en hexadécimal (64 caractères) ou tronquée (erreur de programmation). Action : passer les 32 octets de la clé publique, ceux que rend Sodium::ed25519_keypair_from_seed().' );
		}
		return substr( hash( 'sha256', $public_key ), 0, 16 );
	}

	/**
	 * Encodeur du contrat (§ 7.1) : clés dans l'ordre du tableau (celui de check()), sans espace,
	 * sans échappement de « / » ni de l'Unicode. Les champs ayant été contrôlés (chaînes
	 * hexadécimales ou fixes en ASCII, entiers, null), l'encodage ne peut pas échouer.
	 *
	 * @param Fields $fields Champs contrôlés par check().
	 */
	private static function to_json( array $fields ): string {
		return json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- octets exacts de l'index ; wp_json_encode n'est pas disponible hors WordPress et n'apporte rien ici.
	}

	/**
	 * Règles de lecture du § 7.2 (le réencodage exact mis à part, fait par parse()). Rend les champs
	 * dans l'ordre du contrat, ou, au premier écart, la règle enfreinte : texte constant, jamais une
	 * valeur reçue.
	 *
	 * @param array<mixed> $fields Champs reçus.
	 *
	 * @return Fields|string
	 */
	private static function check( array $fields ): array|string {
		if ( ! self::has_exact_keys( $fields, self::ROOT_KEYS ) ) {
			return 'la racine doit avoir exactement les clés format, version, suite, backup_id, site_ref, site_key_version, signing_key_id, created_at, type, parent_backup_id, payload_format, segments et manifest_segment';
		}
		if ( self::FORMAT !== $fields['format'] ) {
			return '« format » doit valoir "g2bk-index"';
		}
		if ( self::VERSION !== $fields['version'] || self::SUITE !== $fields['suite'] || self::PAYLOAD_FORMAT !== $fields['payload_format'] ) {
			return '« version », « suite » et « payload_format » doivent être l\'entier 1';
		}

		$backup_id = self::hex( $fields['backup_id'], 32 );
		if ( null === $backup_id ) {
			return '« backup_id » doit faire 32 caractères hexadécimaux minuscules';
		}
		$site_ref = self::hex( $fields['site_ref'], 32 );
		if ( null === $site_ref ) {
			return '« site_ref » doit faire 32 caractères hexadécimaux minuscules';
		}
		$site_key_version = self::integer( $fields['site_key_version'], 1, self::MAX_SITE_KEY_VERSION );
		if ( null === $site_key_version ) {
			return '« site_key_version » doit être un entier de 1 à 4 294 967 295';
		}
		$signing_key_id = self::hex( $fields['signing_key_id'], 16 );
		if ( null === $signing_key_id ) {
			return '« signing_key_id » doit faire 16 caractères hexadécimaux minuscules';
		}
		$created_at = self::date( $fields['created_at'] );
		if ( null === $created_at ) {
			return '« created_at » doit être une date réelle du calendrier en UTC, écrite AAAA-MM-JJTHH:MM:SSZ, de l\'année 1970 à 9999';
		}

		if ( self::TYPE_FULL === $fields['type'] ) {
			$type = self::TYPE_FULL;
			if ( null !== $fields['parent_backup_id'] ) {
				return '« parent_backup_id » doit être null pour une sauvegarde complète (full)';
			}
			$parent_backup_id = null;
		} elseif ( self::TYPE_INCREMENTAL === $fields['type'] ) {
			$type             = self::TYPE_INCREMENTAL;
			$parent_backup_id = self::hex( $fields['parent_backup_id'], 32 );
			if ( null === $parent_backup_id || $parent_backup_id === $backup_id ) {
				return '« parent_backup_id » d\'une incrémentale doit faire 32 caractères hexadécimaux minuscules et différer de « backup_id »';
			}
		} else {
			return '« type » doit valoir "full" ou "incremental"';
		}

		$segments = self::segments( $fields['segments'] );
		if ( is_string( $segments ) ) {
			return $segments;
		}
		$manifest_segment = self::integer( $fields['manifest_segment'], 0, count( $segments ) - 1 );
		if ( null === $manifest_segment ) {
			return '« manifest_segment » doit être un entier, l\'index d\'un des segments';
		}
		$kinds     = array_column( $segments, 'kind' );
		$manifests = array_keys( $kinds, self::KIND_MANIFEST, true );
		if ( [ $manifest_segment ] !== $manifests ) {
			return 'il faut exactement un segment « manifest », celui que désigne « manifest_segment »';
		}
		$has_files = in_array( self::KIND_FILES, $kinds, true );
		if ( ! $has_files && ! in_array( self::KIND_DB, $kinds, true ) ) {
			return 'il faut au moins un segment « db » ou « files » (périmètre de la sauvegarde, § 7.3)';
		}
		if ( self::TYPE_INCREMENTAL === $type && ! $has_files ) {
			return 'une incrémentale doit avoir au moins un segment « files »';
		}

		return [
			'format'           => self::FORMAT,
			'version'          => self::VERSION,
			'suite'            => self::SUITE,
			'backup_id'        => $backup_id,
			'site_ref'         => $site_ref,
			'site_key_version' => $site_key_version,
			'signing_key_id'   => $signing_key_id,
			'created_at'       => $created_at,
			'type'             => $type,
			'parent_backup_id' => $parent_backup_id,
			'payload_format'   => self::PAYLOAD_FORMAT,
			'segments'         => $segments,
			'manifest_segment' => $manifest_segment,
		];
	}

	/**
	 * Liste des segments : non vide, au plus MAX_SEGMENTS, `index` contigus de 0 à n − 1, chaque
	 * entrée aux clés exactes et dans ses bornes. Rend les entrées dans l'ordre du contrat, ou la
	 * règle enfreinte.
	 *
	 * @param mixed $value Valeur reçue pour `segments`.
	 *
	 * @return list<Segment>|string
	 */
	private static function segments( mixed $value ): array|string {
		if ( ! is_array( $value ) || [] === $value || count( $value ) > self::MAX_SEGMENTS || ! ArrayShape::is_list( $value ) ) {
			return '« segments » doit être une liste de 1 à 4 096 entrées';
		}

		$segments = [];
		$position = 0;
		foreach ( $value as $segment ) {
			if ( ! is_array( $segment ) || ! self::has_exact_keys( $segment, self::SEGMENT_KEYS ) ) {
				return 'chaque segment doit être un objet aux clés exactes index, kind, blocks, bytes et sha256';
			}
			$index = self::integer( $segment['index'], $position, $position );
			if ( null === $index ) {
				return 'les « index » des segments doivent se suivre de 0 à n − 1, dans l\'ordre de la liste';
			}
			if ( ! in_array( $segment['kind'], [ self::KIND_DB, self::KIND_FILES, self::KIND_MANIFEST ], true ) ) {
				return '« kind » d\'un segment doit valoir "db", "files" ou "manifest"';
			}
			$blocks = self::integer( $segment['blocks'], 1, self::MAX_JSON_INTEGER );
			if ( null === $blocks ) {
				return '« blocks » d\'un segment doit être un entier de 1 à 2⁵³ − 1 (bloc final compris)';
			}
			$bytes = self::integer( $segment['bytes'], self::MIN_SEGMENT_BYTES, self::MAX_SEGMENT_BYTES );
			if ( null === $bytes ) {
				return '« bytes » d\'un segment doit être un entier de 164 à 1 073 741 824 (en-tête compris)';
			}
			$sha256 = self::hex( $segment['sha256'], 64 );
			if ( null === $sha256 ) {
				return '« sha256 » d\'un segment doit faire 64 caractères hexadécimaux minuscules (SHA-256 de tout l\'objet de stockage)';
			}
			$segments[] = [
				'index'  => $index,
				'kind'   => (string) $segment['kind'],
				'blocks' => $blocks,
				'bytes'  => $bytes,
				'sha256' => $sha256,
			];
			++$position;
		}
		return $segments;
	}

	/**
	 * Entier JSON écrit sans signe (0 à 2⁵³ − 1), dans les bornes du champ, sinon null. `true` et
	 * `false` ne sont pas des entiers ; un nombre décimal (`1.0`, `1e3`) ou trop grand pour PHP
	 * arrive en flottant et n'en est pas un non plus.
	 *
	 * @param mixed $value   Valeur reçue.
	 * @param int   $minimum Borne basse incluse.
	 * @param int   $maximum Borne haute incluse.
	 */
	private static function integer( mixed $value, int $minimum, int $maximum ): ?int {
		if ( ! is_int( $value ) || $value < 0 || $value > self::MAX_JSON_INTEGER || $value < $minimum || $value > $maximum ) {
			return null;
		}
		return $value;
	}

	/**
	 * Hexadécimal minuscule de la longueur donnée, sinon null.
	 *
	 * @param mixed $value  Valeur reçue.
	 * @param int   $length Nombre de caractères attendu.
	 */
	private static function hex( mixed $value, int $length ): ?string {
		if ( ! is_string( $value ) || strlen( $value ) !== $length || 1 !== preg_match( '/\A[0-9a-f]+\z/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * `created_at` : 20 caractères `AAAA-MM-JJTHH:MM:SSZ` en chiffres ASCII, date réelle du
	 * calendrier grégorien en UTC, année de 1970 à 9999 (quatre chiffres : 9999 au plus), sinon
	 * null. createFromFormat() reporte une date impossible au mois ou à la minute suivants
	 * (2026-02-29 → 1er mars, seconde 60 → minute suivante) : seul l'aller-retour (date réécrite
	 * au même format, comparée à la chaîne lue) la refuse.
	 *
	 * @param mixed $value Valeur reçue.
	 */
	private static function date( mixed $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( self::DATE_PATTERN, $value ) || (int) substr( $value, 0, 4 ) < self::MIN_YEAR ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!' . self::DATE_FORMAT, $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( self::DATE_FORMAT ) !== $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * Jeu de clés exact : ni manquante ni en trop.
	 *
	 * @param array<mixed> $value Objet reçu.
	 * @param list<string> $keys  Clés attendues.
	 */
	private static function has_exact_keys( array $value, array $keys ): bool {
		if ( count( $value ) !== count( $keys ) ) {
			return false;
		}
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		}
		return true;
	}
}
