<?php
/**
 * Clé Ed25519 de signature du site (spécification § 6.2 ; décision n° 4) : générée sur le site la
 * première fois qu'elle sert, case des sauvegardes cochée ; graine chiffrée au repos par les sels de
 * wp-config.php (Settings::encrypt_token(), refus si le chiffrement n'est pas disponible : jamais
 * gardée en clair) ; clé publique remontée par le snapshot et présentée par la plateforme au service
 * de clés, que le propriétaire approuve en comparant l'empreinte (Fingerprint) avec celle de la page
 * de réglages du connecteur. Option du site principal `g2rd_backup_signing_key`, non autochargée,
 * exclue du dump : une restauration régénère la clé et la fait réapprouver.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Keys;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\State\MainSite;
use G2RD\Connector\Settings;

final class SigningKey {

	public const OPTION = 'g2rd_backup_signing_key';

	private Sodium $sodium;

	public function __construct( Sodium $sodium ) {
		$this->sodium = $sodium;
	}

	/**
	 * Clé publique (32 octets), créée si elle manque.
	 *
	 * @throws BackupRefused CRYPTO_UNAVAILABLE : moteur Ed25519 absent, ou chiffrement au repos impossible.
	 */
	public function ensure( int $now ): string {
		$public = self::public_key();
		if ( null !== $public ) {
			return $public;
		}
		$seed = random_bytes( 32 );
		try {
			$pair = $this->sodium->ed25519_keypair_from_seed( $seed );
		} catch ( FormatError ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$sealed = Settings::encrypt_token( bin2hex( $seed ) );
		if ( ! str_starts_with( $sealed, 'enc:' ) ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$record = [
			'seed'       => $sealed,
			'public_key' => bin2hex( $pair['public'] ),
			'created_at' => $now,
		];
		// add_option() ne remplace jamais : deux processus qui créent en même temps gardent la première.
		MainSite::run( static fn (): bool => add_option( self::OPTION, $record, '', false ) );

		return self::public_key() ?? $pair['public'];
	}

	/** Clé publique (32 octets), ou null si aucune clé n'a été créée. */
	public static function public_key(): ?string {
		$stored = self::stored();
		return null === $stored ? null : (string) hex2bin( $stored['public_key'] );
	}

	/**
	 * Paire de signature, ou null si la clé manque ou ne se rouvre pas (sels de wp-config.php changés).
	 *
	 * @return array{public: string, secret: string}|null
	 */
	public function pair(): ?array {
		$stored = self::stored();
		if ( null === $stored ) {
			return null;
		}
		$hex = Settings::decrypt_token( $stored['seed'] );
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $hex ) ) {
			return null;
		}
		try {
			$pair = $this->sodium->ed25519_keypair_from_seed( (string) hex2bin( $hex ) );
		} catch ( FormatError ) {
			return null;
		}

		return hash_equals( $stored['public_key'], bin2hex( $pair['public'] ) ) ? $pair : null;
	}

	/**
	 * @return array{seed: string, public_key: string, created_at: int}|null
	 */
	private static function stored(): ?array {
		$stored = MainSite::run( static fn (): mixed => get_option( self::OPTION, null ) );
		if ( ! is_array( $stored ) || ! is_string( $stored['seed'] ?? null ) || ! is_string( $stored['public_key'] ?? null )
			|| 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $stored['public_key'] ) || ! is_int( $stored['created_at'] ?? null ) ) {
			return null;
		}

		return [
			'seed'       => $stored['seed'],
			'public_key' => $stored['public_key'],
			'created_at' => $stored['created_at'],
		];
	}
}
