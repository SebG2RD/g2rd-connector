<?php
/**
 * Commande signée `rollback_core` (connecteur 1.18.0, spécification du 19/11/2026, § 4.2) : remet la
 * version de WordPress d'avant une mise à jour protégée, retéléchargée sur wordpress.org
 * (CoreReinstaller), d'après les informations gardées par ProtectedCoreUpdate. Signature exigée
 * quelle que soit la politique (CommandExecutor::SIGNED_ONLY) ; admise en arrière-plan
 * (AsyncCommands::ADMITTED) : le téléchargement et la réinstallation dépassent souvent une minute.
 *
 * Contrôles, dans cet ordre, avant tout changement : verrou des mises à jour protégées
 * (`core_rollback_busy`) ; informations gardées pour CETTE mise à jour (`core_rollback_expired`) ;
 * version installée (`core_rollback_version_changed`) ; version de la base
 * (`core_rollback_db_changed`). Puis référence de santé, transaction toujours tenue (sinon
 * `core_rollback_busy`, rien n'a changé : comme ProtectedUpdate::run(), pas de réinstallation sans
 * elle), version retirée bloquée pour les mises à jour automatiques de WordPress (AutoUpdateGuard,
 * AVANT la réinstallation, cf. ProtectedCoreUpdate), téléchargement, version installée (relue sur le
 * disque) et version de la base contrôlées de nouveau avant la copie (mêmes codes), réinstallation,
 * contrôle de santé, et effacement des informations gardées.
 *
 * Comme rollback_plugin, les cas prévus sont des RÉSULTATS (`outcome`), pas des exceptions : le
 * manager les lit sur le chemin direct comme en arrière-plan. Une exception imprévue aussi
 * (`core_rollback_failed`, avec sa classe seulement) : aucun texte tiers ne part au manager.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback\Core;

use G2RD\Connector\Commands\FilteredError;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\UpdateTransaction;

final class CoreRollbackCommand {

	public const NAME = 'rollback_core';

	/** Capacité annoncée par l'inventaire quand le site peut s'en servir (Services::supported()). */
	public const CAPABILITY = 'core_rollback';

	/** Même code de réussite que rollback_plugin (RestoreCommands::OUTCOME_SUCCESS). */
	public const OUTCOME_SUCCESS = 'rollback_success';

	public function __construct( private readonly HealthChecker $health ) {
	}

	/**
	 * @param array<string, mixed> $payload expected_current, target.
	 * @return array<string, mixed>
	 * @throws \RuntimeException Charge mal formée (erreur du manager) : échec sans code, comme rollback_plugin.
	 */
	public function run( array $payload ): array {
		$expected = is_string( $payload['expected_current'] ?? null ) ? $payload['expected_current'] : '';
		$target   = is_string( $payload['target'] ?? null ) ? $payload['target'] : '';
		if ( ! CorePackage::is_version( $expected ) || ! CorePackage::is_version( $target ) ) {
			throw new \RuntimeException( 'payload.expected_current and payload.target must be WordPress release versions such as 7.1.5 and 7.1.4' );
		}

		$installed = (string) get_bloginfo( 'version' );
		try {
			ProtectedUpdate::recover( time() );
			if ( ! UpdateTransaction::open( ProtectedCoreUpdate::TXN_CONTEXT + [ 'version_before' => $installed ], time() ) ) {
				throw CoreRollbackRefused::because( CoreRollbackRefused::BUSY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
			}
			try {
				return $this->roll_back( $expected, $target, $installed );
			} finally {
				UpdateTransaction::close();
			}
		} catch ( \Throwable $e ) {
			// Exception imprévue : code stable, et sa classe seulement (son texte peut porter un chemin du
			// serveur), filtrée : le nom d'une classe anonyme cite le fichier qui la déclare.
			$refused = $e instanceof CoreRollbackRefused ? $e : CoreRollbackRefused::because( CoreRollbackRefused::FAILED, FilteredError::text( get_class( $e ) ) );
			// Version changée : celle écrite sur le disque (un autre processus a mis WordPress à jour),
			// pas celle chargée au début de la requête ; celle-ci si le fichier est illisible.
			$on_disk = CoreRollbackRefused::VERSION_CHANGED === $refused->error_code() ? self::version_on_disk() : '';
			return [
				'outcome'        => $refused->error_code(),
				'error_code'     => $refused->error_code(),
				'error'          => $refused->getMessage(),
				'version_before' => $installed,
				'version_after'  => '' === $on_disk ? $installed : $on_disk,
			];
		}
	}

	/**
	 * @return array<string, mixed>
	 * @throws CoreRollbackRefused Refus à code stable (cf. docblock de la classe).
	 */
	private function roll_back( string $expected, string $target, string $installed ): array {
		$record = ProtectedCoreUpdate::kept( time() );
		if ( null === $record || $record['version_before'] !== $target ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::EXPIRED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		if ( $installed !== $expected || $installed !== $record['version_after'] ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::VERSION_CHANGED, 'installed ' . $installed ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		if ( ProtectedCoreUpdate::db_version() !== $record['db_version'] ) {
			throw CoreRollbackRefused::because( CoreRollbackRefused::DB_CHANGED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}

		$health = [ 'baseline' => $this->health->measure() ];
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_ROLLING_BACK ) ) {
			// Transaction reprise par un autre processus pendant la mesure : rien n'a changé, à redemander.
			throw CoreRollbackRefused::because( CoreRollbackRefused::BUSY, 'transaction taken over by another process' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
		}
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, $installed );
		// Le téléchargement peut durer six minutes : version installée (relue sur le disque) et version
		// de la base contrôlées de nouveau juste avant la copie. Une mise à jour faite entre-temps par
		// un autre processus n'est pas défaite ; rien n'est copié, le paquet est supprimé.
		$before_copy = static function () use ( $installed, $record ): void {
			$on_disk = self::version_on_disk();
			if ( $on_disk !== $installed ) {
				throw CoreRollbackRefused::because( CoreRollbackRefused::VERSION_CHANGED, 'installed ' . ( '' === $on_disk ? 'unreadable' : $on_disk ) . ' after the download' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
			}
			if ( ProtectedCoreUpdate::db_version() !== $record['db_version'] ) {
				throw CoreRollbackRefused::because( CoreRollbackRefused::DB_CHANGED, 'after the download' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- phrase fixe (CoreRollbackRefused), détail court, jamais affichée en HTML.
			}
		};
		$locale      = CoreReinstaller::reinstall( $target, $record['locale'], $before_copy );
		UpdateTransaction::touch();
		$health['after']   = $this->health->measure();
		$health['verdict'] = HealthChecker::verdict( $health['baseline'], $health['after'] );
		delete_option( ProtectedCoreUpdate::RECORD_OPTION );

		return [
			'outcome'        => self::OUTCOME_SUCCESS,
			'via'            => 'download',
			'version_before' => $installed,
			'version_after'  => $target,
			'locale'         => $locale,
			'health'         => $health,
		];
	}

	/**
	 * Version de WordPress écrite dans `wp-includes/version.php` sur le disque (get_bloginfo() rend
	 * celle chargée au début de la requête) ; chaîne vide si illisible. 8 Ko lus au plus.
	 */
	private static function version_on_disk(): string {
		$file = ABSPATH . 'wp-includes/version.php';
		clearstatcache( true, $file );
		$head = is_readable( $file ) ? file_get_contents( $file, false, null, 0, 8192 ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du cœur, lecture seule et bornée.
		return is_string( $head ) && 1 === preg_match( '/\$wp_version\s*=\s*\'([0-9A-Za-z.+_-]{1,32})\'/', $head, $found ) ? $found[1] : '';
	}
}
