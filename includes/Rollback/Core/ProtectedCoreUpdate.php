<?php
/**
 * Mise à jour PROTÉGÉE du cœur de WordPress (connecteur 1.18.0, spécification du 19/11/2026, § 4.1) :
 *
 *   version, langue du paquet et version de la base notées → référence de santé → mise à jour
 *   (chemin historique) → contrôle de santé → retour automatique à la version d'avant, retéléchargée
 *   sur wordpress.org (CoreReinstaller), si la mise à jour s'est arrêtée en cours de copie ou si le
 *   site ne répond plus normalement, ET si la version de la base n'a pas changé.
 *
 * Aucune copie de fichiers : seules la version d'avant, sa langue et la version de la base d'avant
 * sont gardées, avec leur échéance, dans une option non autochargée (`g2rd_core_rollback`), pour le
 * bouton du manager (rollback_core) ; seulement si le manager le demande (`keep_on_success`, plans
 * payants) et que la base n'a pas changé ; sinon l'option est effacée.
 *
 * Même verrou que les mises à jour protégées d'extensions (UpdateTransaction) : une seule à la fois
 * par site. La transaction porte `plugin_file` = '' : une reprise du cron (ProtectedUpdate::recover())
 * ne restaure rien (aucun point) et consigne `interrupted_unverified` sans fichier, que le manager
 * ignore. step() et touch() donnent signe de vie à une exécution en arrière-plan. Comme
 * ProtectedUpdate::run(), rien sans la transaction : si step() rend faux (transaction reprise par
 * un autre processus après plus de 10 minutes sans signe de vie), ni mise à jour, ni mesure, ni
 * réinstallation — une autre mise à jour protégée peut tourner.
 *
 * Quand un retour automatique est nécessaire, la version retirée est bloquée pour les mises à jour
 * automatiques de WordPress (AutoUpdateGuard, clé CORE), AVANT la réinstallation : un processus
 * arrêté entre la fin de la copie et le blocage (délai PHP, erreur fatale, arrêt du travailleur) la
 * laisserait sinon débloquée, et WordPress la réinstallerait au passage suivant du cron (sous 12 h) ;
 * le blocage est sans effet tant qu'elle est installée. Rien à craindre dans la même requête : le
 * verrou `core_updater`, tenu jusqu'après wp_version_check( $stats ) (class-core-upgrader.php,
 * WordPress 6.4.0 l. 259/262), y fait échouer la mise à jour automatique en `locked`, sautée sans
 * nouvel essai (class-wp-automatic-updater.php 6.4.0 l. 468-477). Le blocage reste si la réinstallation
 * échoue : il ne vise que la version proposée (sans effet tant qu'elle est installée), et une version
 * à moitié remplacée ne doit pas être reprise sans contrôle ; il tombe dès qu'une version plus récente
 * de la même branche est proposée (AutoUpdateGuard::filter_core()).
 *
 * Résultat : la forme historique d'update_core, plus `protected`, `health`, `rolled_back`,
 * `rollback_reason`, `db_version_before`, `db_version_after`, `restore_until` ; `error_code` et
 * `error` quand un retour automatique était nécessaire et n'a pas eu lieu ; `locale` après un retour ;
 * `upgrade_error` quand une exception a été levée une fois les fichiers copiés : la mise à jour est
 * alors faite, contrôlée et retirée si besoin (CommandExecutor::perform_core_upgrade()).
 * Jamais `outcome` : le manager le lirait comme l'issue d'une extension.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback\Core;

use G2RD\Connector\CommandRuns\OptionRow;
use G2RD\Connector\CommandRuns\StoreUnavailable;
use G2RD\Connector\Rollback\AutoUpdateGuard;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Rollback\ProtectedUpdate;
use G2RD\Connector\Rollback\UpdateTransaction;

final class ProtectedCoreUpdate {

	/** Informations gardées pour le bouton (non autochargée, retirée à la désinstallation). */
	public const RECORD_OPTION = 'g2rd_core_rollback';

	public const REASON_UPDATE_FAILED       = 'update_failed';
	public const REASON_HEALTH_CHECK_FAILED = 'health_check_failed';

	/** Contexte de la transaction du cœur : aucun fichier d'extension (cf. docblock). */
	public const TXN_CONTEXT = [
		'plugin_file' => '',
		'kind'        => 'core',
	];

	public function __construct( private readonly HealthChecker $health ) {
	}

	/** Plafond de `grace_seconds` (365 jours) : au-delà, l'échéance déborderait en nombre décimal. */
	public const GRACE_MAX_SECONDS = 31536000;

	/**
	 * @param array<string, mixed>                                                $payload        keep_on_success, grace_seconds.
	 * @param callable(bool): array{0: array<string, mixed>, 1: \WP_Error|null} $perform_update Mise à jour historique (CommandExecutor),
	 *                                                                                           protégée ou non : une exception levée après
	 *                                                                                           la copie ne l'arrête que si elle ne l'est pas.
	 * @return array<string, mixed>
	 * @throws \RuntimeException Une autre mise à jour protégée tourne, ou a repris la transaction
	 *                           avant la mise à jour : rien n'a changé
	 *                           (UpdateTransaction::BUSY_MESSAGE, déjà connu du manager).
	 */
	public function run( array $payload, callable $perform_update ): array {
		$before  = (string) get_bloginfo( 'version' );
		$offered = CorePackage::is_version( $before ) ? self::offered_version() : null;
		if ( null === $offered ) {
			// Version de développement : aucun paquet publié à retélécharger. Rien à mettre à jour :
			// rien à protéger. Chemin historique dans les deux cas, sans contrôle ni verrou ; une
			// exception levée après la copie remonte comme sans `snapshot` (aucun contrôle de santé ne
			// la suivrait).
			return $perform_update( false )[0];
		}

		// Reprise d'une mise à jour d'extension morte, AVANT d'ouvrir (cf. ProtectedUpdate::run()) :
		// open() écraserait sinon sa transaction, et l'extension resterait cassée.
		ProtectedUpdate::recover( time() );
		$now = time();
		if ( ! UpdateTransaction::open( self::TXN_CONTEXT + [ 'version_before' => $before ], $now ) ) {
			throw new \RuntimeException( UpdateTransaction::BUSY_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}
		try {
			return $this->protect( $payload, $perform_update, $before, $offered, $now );
		} finally {
			UpdateTransaction::close();
		}
	}

	/**
	 * Informations gardées, bien formées et pas échues ; null sinon.
	 *
	 * @return array{version_before: string, version_after: string, locale: string, db_version: int, until: int}|null
	 */
	public static function kept( int $now ): ?array {
		wp_cache_delete( self::RECORD_OPTION, 'options' );
		$record = get_option( self::RECORD_OPTION, null );
		if ( ! is_array( $record ) ) {
			return null;
		}
		$before = $record['version_before'] ?? null;
		$after  = $record['version_after'] ?? null;
		$locale = $record['locale'] ?? null;
		$db     = $record['db_version'] ?? null;
		$until  = $record['until'] ?? null;
		if (
			! is_string( $before ) || ! CorePackage::is_version( $before )
			|| ! is_string( $after ) || ! CorePackage::is_version( $after )
			|| ! is_string( $locale ) || ! CorePackage::is_locale( $locale )
			|| ! is_int( $db ) || ! is_int( $until ) || $until <= $now
		) {
			return null;
		}
		return [
			'version_before' => $before,
			'version_after'  => $after,
			'locale'         => $locale,
			'db_version'     => $db,
			'until'          => $until,
		];
	}

	/** Version de la base WordPress (`db_version`), lue en base sans cache ; null si illisible. */
	public static function db_version(): ?int {
		try {
			$raw = OptionRow::read_raw( 'db_version' );
		} catch ( StoreUnavailable $e ) {
			unset( $e );
			return null;
		}
		$raw = null === $raw ? '' : trim( $raw );
		return 1 === preg_match( '/\A\d{1,10}\z/', $raw ) ? (int) $raw : null;
	}

	/**
	 * @param array<string, mixed>                                                $payload
	 * @param callable(bool): array{0: array<string, mixed>, 1: \WP_Error|null} $perform_update
	 * @return array<string, mixed>
	 */
	private function protect( array $payload, callable $perform_update, string $before, string $offered, int $now ): array {
		$locale    = CorePackage::installed_locale();
		$db_before = self::db_version();
		$health    = [ 'baseline' => $this->health->measure() ];
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING ) ) {
			// Transaction reprise par un autre processus pendant la mesure : pas de mise à jour sans
			// transaction (cf. ProtectedUpdate::run()). Rien n'a changé : refus déjà connu du manager.
			throw new \RuntimeException( UpdateTransaction::BUSY_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}

		[ $result, $error ] = $perform_update( true );
		$updated = true === ( $result['updated'] ?? false );
		$reason  = null;
		if ( $updated && UpdateTransaction::step( UpdateTransaction::STEP_HEALTH ) ) {
			$health['after']   = $this->health->measure();
			$health['verdict'] = HealthChecker::verdict( $health['baseline'], $health['after'] );
			if ( HealthChecker::BROKEN === $health['verdict'] ) {
				$reason = self::REASON_HEALTH_CHECK_FAILED;
			}
		} elseif ( $updated ) {
			// Transaction reprise pendant la mise à jour (étape de plus de 10 minutes) : une autre mise
			// à jour protégée peut tourner. Ni mesure (elle croiserait la sienne) ni retour automatique
			// sans transaction : mise à jour faite, site non contrôlé ; le bouton reste possible
			// (rollback_core prend sa propre transaction et refait ses contrôles).
			$health['verdict'] = HealthChecker::UNVERIFIABLE;
		} elseif ( null !== $error && self::files_may_have_changed( $error ) ) {
			$reason = self::REASON_UPDATE_FAILED;
		}
		$db_after  = self::db_version();
		$same_base = null !== $db_before && $db_before === $db_after;
		$protected = [
			'protected'         => true,
			'health'            => $health,
			'rolled_back'       => false,
			'rollback_reason'   => $reason,
			'db_version_before' => $db_before,
			'db_version_after'  => $db_after,
			'restore_until'     => null,
		];

		if ( null === $reason ) {
			if ( $updated ) {
				$protected['restore_until'] = self::keep_if_asked( $payload, $before, (string) ( $result['version_after'] ?? '' ), $locale, $same_base ? $db_before : null, $now );
			}
			return $result + $protected;
		}

		// Retour automatique nécessaire : les informations d'une mise à jour précédente ne valent plus.
		delete_option( self::RECORD_OPTION );
		if ( ! $same_base ) {
			return $result + $protected + self::refusal( CoreRollbackRefused::because( CoreRollbackRefused::DB_CHANGED ) );
		}
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_ROLLING_BACK ) ) {
			// Transaction reprise par un autre processus : pas de réinstallation sans transaction (une
			// autre mise à jour peut copier ses fichiers en ce moment), cf. ProtectedUpdate::run().
			return $result + $protected + self::refusal( CoreRollbackRefused::because( CoreRollbackRefused::BUSY, 'transaction taken over by another process' ) );
		}
		// AVANT la réinstallation : un arrêt du processus entre la copie et le blocage laisserait la
		// version retirée débloquée ; sans effet tant qu'elle est installée (cf. docblock).
		AutoUpdateGuard::block( AutoUpdateGuard::CORE, $offered );
		try {
			$used_locale = CoreReinstaller::reinstall( $before, $locale );
		} catch ( CoreRollbackRefused $e ) {
			return $result + $protected + self::refusal( $e );
		} catch ( \Throwable $e ) {
			return $result + $protected + self::refusal( CoreRollbackRefused::because( CoreRollbackRefused::FAILED, get_class( $e ) ) );
		}
		UpdateTransaction::touch();
		$health['after_rollback'] = $this->health->measure();

		return array_replace(
			$result,
			$protected,
			[
				'updated'       => false,
				'version_after' => $before,
				'health'        => $health,
				'rolled_back'   => true,
				'locale'        => $used_locale,
			]
		);
	}

	/**
	 * Erreur de update_core() survenue une fois la copie commencée : codes pour lesquels WordPress
	 * tente lui-même son retour arrière (Core_Upgrader::upgrade(), wp-admin/includes/class-core-upgrader.php,
	 * WordPress 6.4.0 l. 186-190, 7.1.5 l. 190-194). Les autres (téléchargement, système de fichiers,
	 * PHP ou MySQL trop anciens, fichiers non inscriptibles, verrou) arrêtent la mise à jour avant
	 * toute copie : rien à défaire.
	 */
	private static function files_may_have_changed( \WP_Error $error ): bool {
		$code = (string) $error->get_error_code();
		return str_contains( $code, 'do_rollback' ) || str_contains( $code, '__copy_dir' ) || 'disk_full' === $code;
	}

	/**
	 * Version proposée par la première offre (celle qu'installe la mise à jour historique, même
	 * lecture), ou null s'il n'y a rien à mettre à jour.
	 */
	private static function offered_version(): ?string {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$offers = get_core_updates();
		if ( ! is_array( $offers ) || ! isset( $offers[0]->response, $offers[0]->version ) || 'latest' === $offers[0]->response ) {
			return null;
		}
		return (string) $offers[0]->version;
	}

	/**
	 * Garde les informations du bouton si le manager le demande et que la base n'a pas changé (sinon
	 * `$db_version` est null) ; sinon efface celles d'une mise à jour précédente. `grace_seconds`
	 * ramené entre 0 et GRACE_MAX_SECONDS.
	 *
	 * @param array<string, mixed> $payload
	 * @return int|null Échéance (horodatage Unix), ou null si rien n'est gardé.
	 */
	private static function keep_if_asked( array $payload, string $before, string $after, string $locale, ?int $db_version, int $now ): ?int {
		if ( empty( $payload['keep_on_success'] ) || null === $db_version || ! CorePackage::is_version( $after ) ) {
			delete_option( self::RECORD_OPTION );
			return null;
		}
		$until = $now + min( self::GRACE_MAX_SECONDS, max( 0, (int) ( $payload['grace_seconds'] ?? 0 ) ) );
		update_option(
			self::RECORD_OPTION,
			[
				'version_before' => $before,
				'version_after'  => $after,
				'locale'         => $locale,
				'db_version'     => $db_version,
				'until'          => $until,
			],
			false
		);
		return $until;
	}

	/**
	 * @return array{error_code: string, error: string}
	 */
	private static function refusal( CoreRollbackRefused $refused ): array {
		return [
			'error_code' => $refused->error_code(),
			'error'      => $refused->getMessage(),
		];
	}
}
