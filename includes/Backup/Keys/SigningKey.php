<?php
/**
 * Clé Ed25519 de signature du site (spécification § 6.2 ; décision n° 4) : générée sur le site la
 * première fois qu'elle sert, case des sauvegardes cochée ; graine chiffrée au repos par les sels de
 * wp-config.php (Settings::encrypt_token(), refus si le chiffrement n'est pas disponible : jamais
 * gardée en clair) ; clé publique remontée par le snapshot et présentée par la plateforme au service
 * de clés, que le propriétaire approuve en comparant l'empreinte (Fingerprint) avec celle de la page
 * de réglages du connecteur. Option du site principal `g2rd_backup_signing_key`, non autochargée,
 * exclue du dump : une restauration régénère la clé et la fait réapprouver. De même quand
 * l'enregistrement ne se rouvre plus (sels de wp-config.php régénérés, option abîmée) : ensure() le
 * remplace par une nouvelle paire, dont l'empreinte change et doit être réapprouvée ; sans cela, la
 * clé resterait inutilisable tant que l'option n'est pas supprimée, et chaque sauvegarde serait
 * refusée sous une cause fausse.
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
	 * Clé publique (32 octets) d'une paire que le site a enregistrée et sait rouvrir : jamais une clé
	 * seulement générée, que le site ne détiendrait pas. Créée si l'option manque ; recréée si elle
	 * est présente mais ne se rouvre plus (graine que les sels actuels n'ouvrent pas, option abîmée).
	 *
	 * Écritures : add_option() pour une option absente, update_option() pour remplacer un
	 * enregistrement illisible, puis relecture par pair(). Une clé enregistrée n'est remplacée que si
	 * elle ne se rouvre pas : le relevé (snapshot) n'appelle ensure() que si public_key() est nul, et
	 * le remplacement d'une graine illisible n'a donc lieu que par backup_start, verrou des
	 * sauvegardes tenu. Option absente ou abîmée, un relevé et un backup_start au même instant
	 * peuvent écrire tous les deux (add_option() de WordPress écrit par « ON DUPLICATE KEY UPDATE ») :
	 * la dernière écriture est gardée, chacun rend ce qu'il relit, et le relevé suivant remonte la clé
	 * gardée.
	 *
	 * @throws BackupRefused CRYPTO_UNAVAILABLE : moteur Ed25519 absent, ou chiffrement au repos
	 *                       impossible (rien n'est écrit, la clé enregistrée reste en place) ;
	 *                       STORE_UNAVAILABLE (DETAIL_KEY) : écriture refusée par la base, ou clé
	 *                       relue absente ou qui ne se rouvre pas.
	 */
	public function ensure( int $now ): string {
		$present = null !== MainSite::run( static fn (): mixed => get_option( self::OPTION, null ) );
		if ( $present ) {
			$held = $this->pair();
			if ( null !== $held ) {
				return $held['public'];
			}
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
		if ( $present ) {
			MainSite::run( static fn (): bool => update_option( self::OPTION, $record, false ) );
		} else {
			MainSite::run( static fn (): bool => add_option( self::OPTION, $record, '', false ) );
		}

		$stored = $this->pair();
		if ( null === $stored ) {
			throw new BackupRefused( BackupRefused::STORE_UNAVAILABLE, null, BackupRefused::DETAIL_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return $stored['public'];
	}

	/** Clé publique (32 octets), ou null si aucune clé n'a été créée. */
	public static function public_key(): ?string {
		$stored = self::stored();
		return null === $stored ? null : (string) hex2bin( $stored['public_key'] );
	}

	/**
	 * Paire de signature, ou null si la clé manque ou ne se rouvre pas (sels de wp-config.php changés,
	 * option abîmée, moteur Ed25519 absent) : ensure() recrée alors la paire, sauf sans moteur.
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
