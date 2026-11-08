<?php
/**
 * Clé publique X25519 du site, reçue par backup_set_key (ce que rend `PUT /v1/tenants/{t}/sites/{s}/key`
 * du service de clés) et certifiée par lui (décision n° 15) : certificat vérifié avec les clés du
 * service épinglées dans le code, jamais avec une clé reçue. Refus : aucune clé enregistrée. Une clé
 * plus ancienne que celle détenue, ou une autre clé de même version, est refusée (réponse rejouée).
 * Gardée dans l'option du site principal `g2rd_backup_site_key` (non autochargée) : rien de secret
 * (clé publique et certificat), mais exclue du dump.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Keys;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\SiteKeyCertificate;
use G2RD\Connector\Backup\State\MainSite;

/**
 * @phpstan-type SiteKey array{tenant_ref: string, site_ref: string, version: int, public_key: string, certificate: string, service_key_id: string, received_at: int}
 */
final class SiteKeyStore {

	public const OPTION = 'g2rd_backup_site_key';

	private const MAX_VERSION = 4294967295;

	private Sodium $sodium;

	/** @var list<string>|null */
	private ?array $pinned;

	/**
	 * @param list<string>|null $pinned Clés du service (32 octets) ; PinnedServiceKeys::all() par défaut.
	 */
	public function __construct( Sodium $sodium, ?array $pinned = null ) {
		$this->sodium = $sodium;
		$this->pinned = $pinned;
	}

	/**
	 * @param array<mixed> $payload Charge de backup_set_key.
	 * @return array{key_version: int, fingerprint: string, received_at: string}
	 * @throws BackupRefused INVALID_PAYLOAD, KEY_DOWNGRADE, CERTIFICATE_INVALID, BAD_SITE_KEY, CRYPTO_UNAVAILABLE.
	 */
	public function receive( array $payload, int $now ): array {
		$tenant  = self::hex( $payload['tenant_ref'] ?? null, 32 );
		$site    = self::hex( $payload['site_ref'] ?? null, 32 );
		$public  = self::hex( $payload['public_key'] ?? null, 64 );
		$cert    = self::hex( $payload['certificate'] ?? null, 128 );
		$service = self::hex( $payload['service_key_id'] ?? null, 16 );
		$version = $payload['version'] ?? null;
		if ( null === $tenant || null === $site || null === $public || null === $cert || null === $service || ! is_int( $version ) || $version < 1 || $version > self::MAX_VERSION ) {
			throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		$current = self::current();
		if ( null !== $current && ( $version < $current['version'] || ( $version === $current['version'] && ! hash_equals( $current['public_key'], $public ) ) ) ) {
			throw new BackupRefused( BackupRefused::KEY_DOWNGRADE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		$raw = (string) hex2bin( $public );
		try {
			$valid = SiteKeyCertificate::verify( (string) hex2bin( $tenant ), (string) hex2bin( $site ), $version, $raw, (string) hex2bin( $cert ), $service, $this->pinned ?? PinnedServiceKeys::all(), $this->sodium );
		} catch ( FormatError ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( ! $valid ) {
			throw new BackupRefused( BackupRefused::CERTIFICATE_INVALID ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		try {
			$this->sodium->x25519( random_bytes( 32 ), $raw );
		} catch ( FormatError $e ) {
			throw new BackupRefused( FormatError::BAD_PUBLIC_KEY === $e->reason() ? BackupRefused::BAD_SITE_KEY : BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		$record = [
			'tenant_ref'     => $tenant,
			'site_ref'       => $site,
			'version'        => $version,
			'public_key'     => $public,
			'certificate'    => $cert,
			'service_key_id' => $service,
			'received_at'    => $now,
		];
		MainSite::run( static fn (): bool => update_option( self::OPTION, $record, false ) );

		return [
			'key_version' => $version,
			'fingerprint' => Fingerprint::comparison_fingerprint( $raw ),
			'received_at' => gmdate( 'c', $now ),
		];
	}

	/**
	 * Clé reçue, ou null.
	 *
	 * @return SiteKey|null
	 */
	public static function current(): ?array {
		$stored = MainSite::run( static fn (): mixed => get_option( self::OPTION, null ) );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$fields = [
			'tenant_ref'     => self::hex( $stored['tenant_ref'] ?? null, 32 ),
			'site_ref'       => self::hex( $stored['site_ref'] ?? null, 32 ),
			'public_key'     => self::hex( $stored['public_key'] ?? null, 64 ),
			'certificate'    => self::hex( $stored['certificate'] ?? null, 128 ),
			'service_key_id' => self::hex( $stored['service_key_id'] ?? null, 16 ),
		];
		$version  = $stored['version'] ?? null;
		$received = $stored['received_at'] ?? null;
		if ( in_array( null, $fields, true ) || ! is_int( $version ) || ! is_int( $received ) ) {
			return null;
		}

		return [
			'tenant_ref'     => (string) $fields['tenant_ref'],
			'site_ref'       => (string) $fields['site_ref'],
			'version'        => $version,
			'public_key'     => (string) $fields['public_key'],
			'certificate'    => (string) $fields['certificate'],
			'service_key_id' => (string) $fields['service_key_id'],
			'received_at'    => $received,
		];
	}

	private static function hex( mixed $value, int $length ): ?string {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{' . $length . '}\z/', $value ) ? $value : null;
	}
}
