<?php
/**
 * Commandes de la plateforme liées aux points de restauration. Toutes exigent
 * une signature valide (CommandExecutor::SIGNED_ONLY).
 *
 *   - rollback_plugin       : restaure un plugin depuis un point local, ou depuis une
 *                             archive téléchargée (repli wordpress.org) ;
 *   - delete_restore_point  : « Confirmer et nettoyer » (un, plusieurs ou tous) ;
 *   - set_signature_policy  : `report` ↔ `required` (cf Rest\Auth).
 *
 * Les cas prévus sont des RÉSULTATS (`outcome`), pas des exceptions : la plateforme
 * les lit et décide (repli wordpress.org sur `snapshot_missing`, par exemple).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Settings;

// phpcs:disable WordPress.WP.AlternativeFunctions -- système de fichiers direct, voir RestorePointStore.

final class RestoreCommands {

	public const OUTCOME_SUCCESS          = 'rollback_success';
	public const OUTCOME_SNAPSHOT_MISSING = 'snapshot_missing';

	/**
	 * Hôtes autorisés pour une archive de repli. Filtrable, jamais élargi par la
	 * plateforme elle-même : un connecteur ne télécharge que depuis ces hôtes.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_SOURCE_HOSTS = [ 'downloads.wordpress.org' ];

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @param array<string, mixed> $payload file, restore_point_id?, expected_sha256?, expected_version,
	 *                                      expected_current_version?, source_url?
	 * @return array<string, mixed>
	 */
	public function rollback_plugin( array $payload ): array {
		$file = isset( $payload['file'] ) && is_string( $payload['file'] ) ? $payload['file'] : '';
		if ( '' === $file ) {
			throw new \RuntimeException( 'payload.file required' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			throw new \RuntimeException( esc_html( 'plugin not installed: ' . $file ) );
		}
		if ( plugin_basename( G2RD_CONNECTOR_FILE ) === $file ) {
			throw new \RuntimeException( 'the connector cannot roll itself back' );
		}

		$expected_version = (string) ( $payload['expected_version'] ?? '' );
		$expected_current = isset( $payload['expected_current_version'] ) ? (string) $payload['expected_current_version'] : null;
		$expected_sha     = strtolower( (string) ( $payload['expected_sha256'] ?? '' ) );
		if ( '' === $expected_version ) {
			throw new \RuntimeException( 'payload.expected_version required' );
		}

		$s        = $this->services;
		$point_id = (string) ( $payload['restore_point_id'] ?? '' );
		$point    = '' !== $point_id ? $s->store->get( $point_id ) : null;
		$source   = isset( $payload['source_url'] ) && is_string( $payload['source_url'] ) ? $payload['source_url'] : '';
		$was_active = is_plugin_active( $file );
		$network    = is_multisite() && is_plugin_active_for_network( $file );
		$health     = [ 'baseline' => $s->health->measure() ];

		try {
			if ( null !== $point ) {
				if ( '' !== $expected_sha && ! hash_equals( $expected_sha, strtolower( (string) $point['sha256'] ) ) ) {
					// L'index du site et la plateforme ne racontent pas la même histoire : on ne touche à rien.
					throw RestoreException::integrity( 'restore point hash differs from the platform record' );
				}
				$restored = ( new ProtectedUpdate( $s ) )->restore( $file, $point, $expected_version, $expected_current, $was_active, $network );
				$s->store->hold( $point['id'], time() + (int) ( $payload['hold_max_seconds'] ?? RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS ) );
				$via = 'restore_point';
			} elseif ( '' !== $source ) {
				$restored = $this->restore_from_download( $file, $source, $expected_version, $expected_current, $was_active, $network );
				$via      = 'download';
			} else {
				return [
					'outcome'      => self::OUTCOME_SNAPSHOT_MISSING,
					'file'         => $file,
					'restore_point_id' => '' !== $point_id ? $point_id : null,
					'health'       => $health,
				];
			}
		} catch ( RestoreException $e ) {
			return [
				'outcome'    => $e->error_code(),
				'error_code' => $e->error_code(),
				'error'      => $e->getMessage(),
				'file'       => $file,
				'health'     => $health,
			];
		}

		$health['after']   = $s->health->measure();
		$health['verdict'] = HealthChecker::verdict( $health['baseline'], $health['after'] );

		return [
			'outcome'        => self::OUTCOME_SUCCESS,
			'file'           => $file,
			'via'            => $via,
			'version_before' => $restored['version_before'],
			'version_after'  => $restored['version_after'],
			'was_active'     => $was_active,
			'health'         => $health,
		];
	}

	/**
	 * @param array<string, mixed> $payload ids (list<string>) ou all (bool)
	 * @return array<string, mixed>
	 */
	public function delete_restore_point( array $payload ): array {
		$store   = $this->services->store;
		$ids     = ! empty( $payload['all'] ) ? array_keys( $store->all() ) : (array) ( $payload['ids'] ?? [] );
		$deleted = [];
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && $store->remove( $id ) ) {
				$deleted[] = $id;
			}
		}
		return [
			'deleted'     => $deleted,
			'remaining'   => count( $store->all() ),
			'total_bytes' => $store->total_bytes(),
		];
	}

	/**
	 * @param array<string, mixed> $payload policy : report | required
	 * @return array<string, mixed>
	 */
	public function set_signature_policy( array $payload ): array {
		$policy = 'required' === ( $payload['policy'] ?? '' ) ? 'required' : 'report';
		Settings::update( [ 'signature_policy' => $policy ] );
		return [ 'signature_policy' => (string) Settings::get( 'signature_policy' ) ];
	}

	/**
	 * Repli : archive téléchargée depuis un hôte autorisé (wordpress.org), sans hash
	 * connu — la version lue dans l'archive et le confinement des entrées restent vérifiés.
	 *
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	private function restore_from_download( string $file, string $url, string $expected_version, ?string $expected_current, bool $was_active, bool $network ): array {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts = (array) apply_filters( 'g2rd_connector_restore_source_hosts', self::DEFAULT_SOURCE_HOSTS );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! in_array( $host, $hosts, true ) ) {
			throw RestoreException::integrity( esc_html( 'archive source not allowed: ' . $host ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			throw RestoreException::failed( esc_html( 'download failed: ' . $tmp->get_error_message() ) );
		}

		try {
			$this->services->restorer->restore_from_zip( (string) $tmp, $file, '', $expected_version, $expected_current );
		} finally {
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}

		// Même suite que pour un point local : réactivation, garde, .maintenance, OPcache.
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( $was_active ) {
			\G2RD\Connector\Commands\CommandExecutor::force_reactivate( $file, $network );
		}
		if ( null !== $expected_current && '' !== $expected_current ) {
			AutoUpdateGuard::block( $file, $expected_current );
		}

		$plugins = get_plugins();
		return [
			'version_before' => (string) ( $expected_current ?? '' ),
			'version_after'  => (string) ( $plugins[ $file ]['Version'] ?? $expected_version ),
		];
	}
}
