<?php
/**
 * Refus du format chiffré des sauvegardes G2BK v1, par un code stable.
 *
 * Les codes sont ceux du contrat (docs/backup-format.md § 12 du dépôt de la plateforme), les mêmes
 * que ceux que rendent la plateforme et le service de clés pour le même refus. Les phrases FR/EN
 * (quoi, cause probable, action) viennent en phase 4, à partir de ce code.
 *
 * Le message ne porte que le code : jamais un octet ni une valeur reçus (un en-tête, un index ou
 * une clé publique peuvent venir d'ailleurs), jamais une clé ni un texte clair, jamais le message
 * d'une exception de la bibliothèque (aucune exception chaînée).
 *
 * Classe pure, sans effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

final class FormatError extends \RuntimeException {

	public const BAD_MAGIC                    = 'bad_magic';
	public const UNSUPPORTED_VERSION          = 'unsupported_version';
	public const UNSUPPORTED_SUITE            = 'unsupported_suite';
	public const UNKNOWN_KIND                 = 'unknown_kind';
	public const RESERVED_FLAGS               = 'reserved_flags';
	public const BAD_BLOCK_SIZE               = 'bad_block_size';
	public const BAD_KEY_VERSION              = 'bad_key_version';
	public const BAD_SEGMENT_INDEX            = 'bad_segment_index';
	public const HEADER_TRUNCATED             = 'header_truncated';
	public const BAD_PUBLIC_KEY               = 'bad_public_key';
	public const SEAL_OPEN_FAILED             = 'seal_open_failed';
	public const KEY_COMMITMENT_MISMATCH      = 'key_commitment_mismatch';
	public const BLOCK_AUTH_FAILED            = 'block_auth_failed';
	public const TRUNCATED                    = 'truncated';
	public const WRITE_FAILED                 = 'write_failed';
	public const INDEX_MALFORMED              = 'index_malformed';
	public const INDEX_TOO_LARGE              = 'index_too_large';
	public const INDEX_SIGNATURE_INVALID      = 'index_signature_invalid';
	public const CRYPTO_UNAVAILABLE           = 'crypto_unavailable';
	public const SELF_TEST_FAILED             = 'self_test_failed';
	public const SITE_KEY_CERTIFICATE_INVALID = 'site_key_certificate_invalid';

	/** Tous les codes stables, dans l'ordre du contrat. */
	public const CODES = [
		self::BAD_MAGIC,
		self::UNSUPPORTED_VERSION,
		self::UNSUPPORTED_SUITE,
		self::UNKNOWN_KIND,
		self::RESERVED_FLAGS,
		self::BAD_BLOCK_SIZE,
		self::BAD_KEY_VERSION,
		self::BAD_SEGMENT_INDEX,
		self::HEADER_TRUNCATED,
		self::BAD_PUBLIC_KEY,
		self::SEAL_OPEN_FAILED,
		self::KEY_COMMITMENT_MISMATCH,
		self::BLOCK_AUTH_FAILED,
		self::TRUNCATED,
		self::WRITE_FAILED,
		self::INDEX_MALFORMED,
		self::INDEX_TOO_LARGE,
		self::INDEX_SIGNATURE_INVALID,
		self::CRYPTO_UNAVAILABLE,
		self::SELF_TEST_FAILED,
		self::SITE_KEY_CERTIFICATE_INVALID,
	];

	/** Code stable du refus, un de CODES. */
	private string $reason;

	/**
	 * @param string $reason Un des codes de CODES.
	 *
	 * @throws \InvalidArgumentException Code absent de CODES : erreur de programmation.
	 */
	public function __construct( string $reason ) {
		if ( ! in_array( $reason, self::CODES, true ) ) {
			throw new \InvalidArgumentException( 'Refus du format G2BK impossible à signaler : le code demandé n\'est pas un code stable du format. Cause probable : erreur de programmation (code écrit à la main, ou nouveau code non déclaré). Action : passer une des constantes de FormatError, ou déclarer d\'abord le nouveau code dans le contrat (docs/backup-format.md, plateforme), puis dans les trois implémentations.' );
		}

		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Code stable du refus.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
