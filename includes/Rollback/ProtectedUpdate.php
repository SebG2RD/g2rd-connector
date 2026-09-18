<?php
/**
 * Mise à jour d'un plugin PROTÉGÉE par un point de restauration :
 *
 *   référence de santé → point de restauration → mise à jour → contrôle de santé
 *   → rollback automatique si le site a régressé.
 *
 * Tout tient dans la même requête : le processus PHP qui l'exécute a chargé
 * l'ancien code avant le remplacement des fichiers, il survit donc à un plugin
 * cassé — seul le loopback, qui est un autre processus, plante. C'est ce qui rend
 * le rollback automatique fiable sans dépendre de la plateforme, dont l'API REST
 * du site serait de toute façon morte.
 *
 * Un journal de transaction (UpdateTransaction) permet de rejouer une requête
 * morte en route : par le filet de shutdown, puis par le cron (recover()).
 *
 * Résultat : la forme historique d'update_plugin, plus `outcome`, `health`,
 * `restore_point` (et `error_code` / `error` quand pertinent). Les cas prévus
 * (espace disque, rollback automatique…) sont des RÉSULTATS, pas des exceptions :
 * la plateforme les lit dans `outcome`.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\RestorePointPurgeJob;

final class ProtectedUpdate {

	public const OUTCOME_UPDATED              = 'updated';
	public const OUTCOME_NOT_UPDATED          = 'not_updated';
	public const OUTCOME_AUTO_ROLLED_BACK     = 'auto_rolled_back';
	public const OUTCOME_AUTO_ROLLBACK_FAILED = 'auto_rollback_failed';
	public const OUTCOME_UPDATE_FAILED        = 'update_failed';

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @param string               $plugin_file Plugin déjà validé par CommandExecutor (installé).
	 * @param array<string, mixed> $payload     Options envoyées par la plateforme (kind, grace_seconds, keep_on_success, hold_max_seconds, max_total_bytes, disk_margin_bytes).
	 * @param callable(): array<string, mixed> $perform_upgrade La mise à jour historique (Plugin_Upgrader + réactivation).
	 * @return array<string, mixed>
	 * @throws \RuntimeException Échec de la mise à jour elle-même (comportement historique conservé).
	 */
	public function run( string $plugin_file, array $payload, callable $perform_upgrade ): array {
		$now     = time();
		$s       = $this->services;
		$version = $this->installed_version( $plugin_file );

		if ( ! UpdateTransaction::open(
			[
				'plugin_file'    => $plugin_file,
				'version_before' => $version,
				'was_active'     => is_plugin_active( $plugin_file ),
				'network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
			],
			$now
		) ) {
			throw new \RuntimeException( 'another protected update is still running on this site' );
		}
		register_shutdown_function( [ self::class, 'recover_on_shutdown' ] );

		// ── Référence de santé, AVANT tout changement ────────────────────────────
		$baseline = $s->health->measure();

		// ── Point de restauration ────────────────────────────────────────────────
		$keep    = ! empty( $payload['keep_on_success'] );
		$grace   = max( 0, (int) ( $payload['grace_seconds'] ?? 0 ) );
		// Un point retenu (échec, rollback) est supprimé par le cron au-delà de ce plafond.
		$hold_until = $now + (int) ( $payload['hold_max_seconds'] ?? RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
		$meta    = [
			'version'           => $version,
			'kind'              => (string) ( $payload['kind'] ?? RestorePointStore::KIND_CUSTOM ),
			'expires_at'        => $keep ? $now + $grace : $now,
			'hold'              => false,
			'max_total_bytes'   => (int) ( $payload['max_total_bytes'] ?? Snapshotter::DEFAULT_MAX_TOTAL_BYTES ),
			'disk_margin_bytes' => (int) ( $payload['disk_margin_bytes'] ?? Snapshotter::DEFAULT_DISK_MARGIN_BYTES ),
		];
		try {
			$point = $s->snapshotter->create( $plugin_file, $meta, $now );
		} catch ( RestorePointException $e ) {
			UpdateTransaction::close();
			// Refus AVANT toute mise à jour : rien n'a changé sur le site.
			return $this->legacy_shape( $plugin_file, $version ) + [
				'outcome'       => $e->error_code(),
				'error_code'    => $e->error_code(),
				'error'         => $e->getMessage(),
				'health'        => [ 'baseline' => $baseline ],
				'restore_point' => null,
			];
		}
		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => $point['id'] ] );

		// ── Mise à jour (chemin historique, inchangé) ────────────────────────────
		try {
			$result = $perform_upgrade();
		} catch ( \Throwable $e ) {
			// WordPress ≥ 6.3 remet lui-même les fichiers d'origine quand l'upgrade échoue ;
			// le point est retenu jusqu'à résolution (design §5.6), l'erreur remonte comme avant.
			$s->store->hold( $point['id'], $hold_until );
			UpdateTransaction::close();
			throw $e;
		}

		if ( true !== ( $result['updated'] ?? false ) ) {
			// Rien n'a changé (version inchangée, licence premium…) : le point n'a pas de raison d'être.
			$s->store->remove( $point['id'] );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_NOT_UPDATED,
				'health'        => [ 'baseline' => $baseline ],
				'restore_point' => null,
			];
		}

		// ── Contrôle de santé, sur le NOUVEAU code ───────────────────────────────
		UpdateTransaction::step( UpdateTransaction::STEP_HEALTH );
		$this->invalidate_opcache( $plugin_file );
		$after   = $s->health->measure();
		$verdict = HealthChecker::verdict( $baseline, $after );
		$health  = [
			'baseline' => $baseline,
			'after'    => $after,
			'verdict'  => $verdict,
		];

		if ( HealthChecker::BROKEN !== $verdict ) {
			if ( HealthChecker::HEALTHY === $verdict && ! $keep ) {
				// Plan sans délai de grâce : le site est sain, le point n'a plus d'utilité.
				$s->store->remove( $point['id'] );
				$point = null;
			} elseif ( HealthChecker::UNVERIFIABLE === $verdict && ! $keep ) {
				// Personne n'a pu vérifier : on garde le point le temps de la grâce, même sans plan (design D3).
				$s->store->update( $point['id'], [ 'expires_at' => $now + max( $grace, 72 * 3600 ) ] );
				$point = $s->store->get( $point['id'] );
			}
			// Un point plus ancien du même plugin n'a plus de raison d'être : un seul par plugin.
			$this->drop_older_points( $plugin_file, $point['id'] ?? null );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_UPDATED,
				'health'        => $health,
				'restore_point' => $this->public_point( $point ),
			];
		}

		// ── Régression prouvée : rollback automatique ────────────────────────────
		UpdateTransaction::step( UpdateTransaction::STEP_ROLLING_BACK );
		$version_after = (string) ( $result['version_after'] ?? '' );
		try {
			$this->restore( $plugin_file, $point, $version, $version_after, (bool) ( $result['was_active'] ?? false ), (bool) ( $result['network_active'] ?? false ) );
			$s->store->hold( $point['id'], $hold_until );
			$health['after_rollback'] = $s->health->measure();
			UpdateTransaction::close();
			// array_replace (pas `+`) : `updated` et `version_after` doivent refléter l'état
			// APRÈS rollback, pas celui de la mise à jour annulée.
			return array_replace(
				$result,
				[
					'updated'       => false,
					'version_after' => $version,
					'outcome'       => self::OUTCOME_AUTO_ROLLED_BACK,
					'reason'        => 'health_check_failed',
					'health'        => $health,
					'restore_point' => $this->public_point( $s->store->get( $point['id'] ) ),
					'rolled_back'   => [
						'from' => $version_after,
						'to'   => $version,
					],
				]
			);
		} catch ( \Throwable $e ) {
			$s->store->hold( $point['id'], $hold_until );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_AUTO_ROLLBACK_FAILED,
				'error_code'    => $e instanceof RestoreException ? $e->error_code() : RestoreException::FAILED,
				'error'         => $e->getMessage(),
				'health'        => $health,
				'restore_point' => $this->public_point( $s->store->get( $point['id'] ) ),
			];
		}
	}

	/**
	 * Restaure un plugin depuis un point : désactivation, fichiers, réactivation,
	 * garde anti-réinstallation, `.maintenance`. Partagé avec le rollback manuel.
	 *
	 * @param array<string, mixed> $point
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	public function restore( string $plugin_file, array $point, string $expected_version, ?string $expected_current_version, bool $reactivate, bool $network = false ): array {
		$s    = $this->services;
		$path = $s->store->path_for( $point );
		if ( null === $path ) {
			throw RestoreException::integrity( 'restore point has no usable archive' );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $plugin_file, true );

		$restored = $s->restorer->restore_from_zip( $path, $plugin_file, (string) $point['sha256'], $expected_version, $expected_current_version );

		if ( $reactivate ) {
			CommandExecutor::force_reactivate( $plugin_file, $network );
		}
		if ( null !== $expected_current_version && '' !== $expected_current_version ) {
			AutoUpdateGuard::block( $plugin_file, $expected_current_version );
		}
		$this->clear_maintenance_flag();
		$this->invalidate_opcache( $plugin_file );

		return $restored;
	}

	/**
	 * Reprise d'une transaction interrompue (shutdown ou cron) : si les fichiers
	 * peuvent être dans un état intermédiaire, on restaure le point ; sinon on
	 * consigne seulement ce qui s'est passé. Toujours silencieuse.
	 */
	public static function recover( int $now, bool $force = false ): void {
		$txn = UpdateTransaction::current();
		if ( null === $txn || ( ! $force && ! UpdateTransaction::is_stale( $txn, $now ) ) ) {
			return;
		}

		$plugin_file = (string) $txn['plugin_file'];
		$point_id    = (string) ( $txn['restore_point_id'] ?? '' );
		$outcome     = [
			'plugin_file'      => $plugin_file,
			'restore_point_id' => '' !== $point_id ? $point_id : null,
			'step'             => $txn['step'],
		];

		try {
			if ( UpdateTransaction::files_may_be_dirty( $txn ) && '' !== $point_id ) {
				$services = Services::make();
				$point    = $services->store->get( $point_id );
				if ( null !== $point ) {
					( new self( $services ) )->restore( $plugin_file, $point, (string) $point['version'], null, ! empty( $txn['was_active'] ), ! empty( $txn['network_active'] ) );
					$services->store->hold( $point_id, $now + RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
					$outcome['outcome'] = 'recovered_rolled_back';
				} else {
					$outcome['outcome'] = 'recovered_no_restore_point';
				}
			} else {
				// Mise à jour terminée mais santé non contrôlée : la plateforme le saura.
				$outcome['outcome'] = 'interrupted_unverified';
			}
		} catch ( \Throwable $e ) {
			$outcome['outcome'] = 'recovery_failed';
			$outcome['detail']  = $e->getMessage();
		}

		PendingOutcomes::add( $outcome, $now );
		UpdateTransaction::close();
	}

	/**
	 * Filet de shutdown : si la transaction est encore ouverte à la fin du cycle PHP,
	 * la requête est morte en route (fatale, délai dépassé). On rejoue tout de suite.
	 */
	public static function recover_on_shutdown(): void {
		if ( null === UpdateTransaction::current() ) {
			return;
		}
		self::recover( time(), true );
	}

	private function installed_version( string $plugin_file ): string {
		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function legacy_shape( string $plugin_file, string $version ): array {
		return [
			'updated'        => false,
			'file'           => $plugin_file,
			'version_before' => $version,
			'version_after'  => $version,
			'was_active'     => is_plugin_active( $plugin_file ),
			'reactivated'    => is_plugin_active( $plugin_file ),
			'reason'         => 'snapshot_refused',
		];
	}

	private function drop_older_points( string $plugin_file, ?string $keep_id ): void {
		foreach ( $this->services->store->for_plugin( $plugin_file ) as $id => $record ) {
			if ( $id !== $keep_id && empty( $record['hold'] ) ) {
				$this->services->store->remove( $id );
			}
		}
	}

	/**
	 * @param array<string, mixed>|null $point
	 * @return array<string, mixed>|null Ce que la plateforme a besoin de connaître.
	 */
	private function public_point( ?array $point ): ?array {
		if ( null === $point ) {
			return null;
		}
		return [
			'id'         => $point['id'],
			'version'    => $point['version'],
			'sha256'     => $point['sha256'],
			'size'       => $point['size'],
			'created_at' => $point['created_at'],
			'expires_at' => $point['expires_at'],
			'hold'       => (bool) $point['hold'],
		];
	}

	private function invalidate_opcache( string $plugin_file ): void {
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			$relative = '.' !== dirname( $plugin_file ) ? dirname( $plugin_file ) : '';
			wp_opcache_invalidate_directory( rtrim( $this->services->plugins_root . '/' . $relative, '/' ) );
		}
	}

	private function clear_maintenance_flag(): void {
		$flag = rtrim( (string) ABSPATH, '/\\' ) . '/.maintenance';
		if ( file_exists( $flag ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fichier posé par WP_Upgrader, retiré comme il le ferait lui-même.
			unlink( $flag );
		}
	}
}
