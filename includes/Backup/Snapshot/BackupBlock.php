<?php
/**
 * Bloc `backup` du snapshot, version 1 (contrat du plan de la phase 4, fixture
 * snapshot-backup-v1.json) : interrupteur, capacité, moteur sodium, clé publique de signature et son
 * empreinte de comparaison, clé de site reçue, état de la sauvegarde en cours, issue de la dernière.
 * Lecture seule, sauf la création de la clé de signature quand la case est cochée et qu'elle manque
 * (choix n° 11). Ne lève jamais : une lecture impossible donne les valeurs de repos.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Snapshot;

use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Keys\Fingerprint;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\State\BackupState;

final class BackupBlock {

	public const VERSION = 1;

	/**
	 * Bloc du snapshot ; une lecture qui lève (option abîmée, base indisponible, moteur absent)
	 * donne les valeurs de repos : jamais tout le snapshot en échec à cause de ce bloc.
	 *
	 * @return array<string, mixed>
	 */
	public static function build( int $now, ?Sodium $sodium = null ): array {
		try {
			return self::read( $now, $sodium ?? new Sodium() );
		} catch ( \Throwable ) {
			return self::at_rest();
		}
	}

	/**
	 * Valeurs de repos : éteint, aucune clé, état idle, aucune issue.
	 *
	 * @return array<string, mixed>
	 */
	private static function at_rest(): array {
		return [
			'version'              => self::VERSION,
			'enabled'              => false,
			'available'            => false,
			'disabled_by_constant' => false,
			'engine'               => Sodium::BACKEND_NONE,
			'signing_public_key'   => null,
			'signing_key_id'       => null,
			'fingerprint'          => null,
			'site_key'             => null,
			'state'                => [
				'phase'            => BackupState::IDLE,
				'backup_id'        => null,
				'type'             => null,
				'scope'            => null,
				'segments_written' => 0,
				'pending'          => 0,
				'slice_started_at' => null,
			],
			'last'                 => null,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function read( int $now, Sodium $sodium ): array {
		$enabled = BackupSwitch::enabled();
		$public  = SigningKey::public_key();
		if ( $enabled && null === $public ) {
			try {
				$public = ( new SigningKey( $sodium ) )->ensure( $now );
			} catch ( \Throwable ) {
				$public = null;
			}
		}
		try {
			$engine = $sodium->backend();
		} catch ( \Throwable ) {
			$engine = Sodium::BACKEND_NONE;
		}
		$key   = SiteKeyStore::current();
		$state = BackupState::peek() ?? BackupState::idle();
		$slice = is_array( $state['slice'] ?? null ) ? $state['slice'] : [];
		$last  = is_array( $state['last'] ?? null ) ? $state['last'] : null;

		return [
			'version'              => self::VERSION,
			'enabled'              => $enabled,
			'available'            => BackupSwitch::capability_available(),
			'disabled_by_constant' => BackupSwitch::disabled_by_constant(),
			'engine'               => $engine,
			'signing_public_key'   => null === $public ? null : bin2hex( $public ),
			'signing_key_id'       => null === $public ? null : BackupIndex::signing_key_id( $public ),
			'fingerprint'          => null === $public ? null : Fingerprint::comparison_fingerprint( $public ),
			'site_key'             => null === $key ? null : [
				'version'        => $key['version'],
				'service_key_id' => $key['service_key_id'],
				'received_at'    => gmdate( 'c', $key['received_at'] ),
			],
			'state'                => [
				'phase'            => is_string( $state['phase'] ?? null ) ? $state['phase'] : BackupState::IDLE,
				'backup_id'        => is_string( $state['backup_id'] ?? null ) ? $state['backup_id'] : null,
				'type'             => is_string( $state['type'] ?? null ) ? $state['type'] : null,
				'scope'            => is_string( $state['scope'] ?? null ) ? $state['scope'] : null,
				'segments_written' => is_int( $state['segments'] ?? null ) ? $state['segments'] : 0,
				'pending'          => count( (array) ( $state['pending'] ?? [] ) ),
				'slice_started_at' => is_int( $slice['started_at'] ?? null ) ? gmdate( 'c', $slice['started_at'] ) : null,
			],
			'last'                 => null === $last ? null : [
				'backup_id' => is_string( $last['backup_id'] ?? null ) ? $last['backup_id'] : null,
				'outcome'   => is_string( $last['outcome'] ?? null ) ? $last['outcome'] : null,
				'code'      => is_string( $last['code'] ?? null ) ? $last['code'] : null,
				'stage'     => is_string( $last['stage'] ?? null ) ? $last['stage'] : null,
				'at'        => is_int( $last['at'] ?? null ) ? gmdate( 'c', $last['at'] ) : null,
				'totals'    => is_array( $last['totals'] ?? null ) ? $last['totals'] : null,
			],
		];
	}
}
