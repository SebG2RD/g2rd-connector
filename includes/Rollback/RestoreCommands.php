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

	/** Repli téléchargé : empreinte fournie par la plateforme et vérifiée. */
	public const SOURCE_VERIFIED = 'verified';

	/** Repli téléchargé : aucune empreinte fournie (managers antérieurs), installé comme avant. */
	public const SOURCE_UNVERIFIED = 'unverified';

	/**
	 * Deux empreintes distinctes, à ne jamais confondre :
	 *   - `expected_sha256` : empreinte du zip du POINT LOCAL (fait par Snapshotter) ;
	 *     ne s'applique qu'à la branche « point de restauration » ;
	 *   - `source_sha256`   : empreinte de l'archive à télécharger (`source_url`) ;
	 *     ne s'applique qu'à la branche « téléchargement ».
	 * Le manager envoie les deux dans la même commande quand le point a pu disparaître
	 * du site : appliquer la première à l'archive wordpress.org refuserait à tort
	 * toutes ces restaurations.
	 *
	 * @param array<string, mixed> $payload file, restore_point_id?, expected_sha256?, expected_version,
	 *                                      expected_current_version?, source_url?, source_sha256?
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

		// Une mise à jour protégée, ou une reprise menée par le cron du site (que la
		// plateforme ne sérialise pas avec ses commandes), peut restaurer cette même
		// extension en ce moment : deux restaurations déplaceraient le même dossier en
		// même temps. Refus avant tout changement, avec le message déjà connu de la
		// plateforme. Une transaction morte ne bloque pas (comportement d'avant).
		if ( UpdateTransaction::in_progress( time() ) ) {
			throw new \RuntimeException( UpdateTransaction::BUSY_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}

		$s        = $this->services;
		$point_id = (string) ( $payload['restore_point_id'] ?? '' );
		$point    = '' !== $point_id ? $s->store->get( $point_id ) : null;
		$source   = isset( $payload['source_url'] ) && is_string( $payload['source_url'] ) ? $payload['source_url'] : '';
		$was_active = is_plugin_active( $file );
		$network    = is_multisite() && is_plugin_active_for_network( $file );
		$health     = [ 'baseline' => $s->health->measure() ];
		$attempt    = null;
		$source_info = [];

		try {
			if ( null !== $point ) {
				$attempt = 'restore_point';
				if ( '' !== $expected_sha && ! hash_equals( $expected_sha, strtolower( (string) $point['sha256'] ) ) ) {
					// L'index du site et la plateforme ne racontent pas la même histoire : on ne touche à rien.
					throw RestoreException::integrity( 'restore point hash differs from the platform record' );
				}
				$hold_until = time() + (int) ( $payload['hold_max_seconds'] ?? RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
				try {
					// Rollback manuel : le dossier en place au démarrage de la commande est celui
					// qui tournait, il peut être rétabli tel quel (cf. ProtectedUpdate::restore_archive()).
					$restored = ( new ProtectedUpdate( $s ) )->restore( $file, $point, $expected_version, $expected_current, $was_active, $network, true );
				} catch ( \Throwable $e ) {
					// Rollback manqué : le point est retenu quand même, comme après un rollback
					// automatique ou une reprise manqués. Sinon il garderait sa date
					// d'expiration et la purge le supprimerait, alors qu'il reste le seul moyen
					// de réessayer. En silence : l'erreur de la restauration, qui dit ce qu'est
					// devenue l'extension, doit remonter, pas celle de la rétention.
					$s->store->hold_quietly( (string) $point['id'], $hold_until );
					throw $e;
				}
				$s->store->hold( $point['id'], $hold_until );
				$via = 'restore_point';
			} elseif ( '' !== $source ) {
				$attempt     = 'download';
				$source_sha  = self::source_sha256( $payload );
				$restored    = $this->restore_from_download( $file, $source, $source_sha, $expected_version, $expected_current, $was_active, $network );
				$via         = 'download';
				$source_info = [
					'source_integrity'     => '' !== $source_sha ? self::SOURCE_VERIFIED : self::SOURCE_UNVERIFIED,
					'source_sha256_actual' => $restored['sha256'],
				];
				if ( '' === $source_sha ) {
					// Comportement d'avant conservé, mais plus invisible : trace bornée + crochet.
					UnverifiedDownloads::record( $file, $source, $restored['sha256'], time() );
					do_action( 'g2rd_connector_rollback_source_unverified', $file, $source, $restored['sha256'] );
				}
			} else {
				return [
					'outcome'      => self::OUTCOME_SNAPSHOT_MISSING,
					'file'         => $file,
					'restore_point_id' => '' !== $point_id ? $point_id : null,
					'health'       => $health,
				];
			}
		} catch ( RestoreException $e ) {
			// `via` (ajout K3) : la plateforme distingue une copie locale altérée d'une
			// archive téléchargée différente ; les clés historiques sont inchangées.
			return [
				'outcome'    => $e->error_code(),
				'error_code' => $e->error_code(),
				'error'      => $e->getMessage(),
				'file'       => $file,
				'via'        => $attempt,
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
		] + $source_info;
	}

	/**
	 * Empreinte attendue de l'archive téléchargée (`source_sha256`), normalisée.
	 * Absente ou vide : chaîne vide (comportement d'avant). Présente mais mal formée :
	 * refus AVANT tout téléchargement — une empreinte illisible ne doit pas valoir
	 * « pas d'empreinte ».
	 *
	 * @param array<string, mixed> $payload
	 * @throws RestoreException
	 */
	private static function source_sha256( array $payload ): string {
		$raw = $payload['source_sha256'] ?? null;
		if ( null === $raw || '' === $raw ) {
			return '';
		}
		// Liste explicite : le jeu par défaut de trim() s'élargit en PHP 8.6 (saut de page).
		$sha = is_string( $raw ) ? strtolower( trim( $raw, " \n\r\t\v\0" ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			throw RestoreException::integrity( 'source_sha256 is not a valid SHA-256 (64 hexadecimal characters)' );
		}
		return $sha;
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
	 * Repli : archive téléchargée depuis un hôte autorisé (wordpress.org).
	 *
	 * Quand la plateforme fournit `$source_sha` (K3), l'empreinte de l'archive reçue
	 * doit lui être égale, sinon refus AVANT toute écriture (dossier ni déplacé ni
	 * désactivé). Sans empreinte (managers antérieurs), comportement d'avant : seules
	 * la version lue dans l'archive et le confinement des entrées sont vérifiés.
	 * L'empreinte calculée est toujours renvoyée, pour la trace et le résultat.
	 *
	 * Même restauration que depuis un point local (ProtectedUpdate::restore_archive()) :
	 * extension désactivée une fois son dossier mis de côté, jamais pendant un refus
	 * d'avant ; après un échec, état d'avant la commande rétabli si le dossier d'avant a
	 * été remis en place tel quel, sinon réactivation seulement si elle se charge, et
	 * l'erreur dit ce qu'est devenue l'extension.
	 *
	 * @param string $source_sha Empreinte attendue, normalisée (64 hex) ou chaîne vide.
	 * @return array{version_before:string, version_after:string, sha256:string}
	 * @throws RestoreException
	 */
	private function restore_from_download( string $file, string $url, string $source_sha, string $expected_version, ?string $expected_current, bool $was_active, bool $network ): array {
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
			$actual = (string) hash_file( 'sha256', (string) $tmp );
			if ( '' !== $source_sha && ! hash_equals( $source_sha, $actual ) ) {
				throw RestoreException::integrity( esc_html( 'downloaded archive hash does not match source_sha256 (got ' . $actual . ')' ) );
			}
			// Le restaurateur recompare l'empreinte (vide = non vérifiée) avant d'écrire. Même
			// suite que pour un point local : désactivation pendant l'extraction,
			// réactivation, garde, .maintenance, OPcache ; échec traité de la même façon
			// (rollback manuel : le dossier d'avant la commande est sain par définition).
			( new ProtectedUpdate( $this->services ) )->restore_archive( (string) $tmp, $file, $source_sha, $expected_version, $expected_current, $was_active, $network, true );
		} finally {
			if ( is_string( $tmp ) && file_exists( $tmp ) ) {
				unlink( $tmp );
			}
		}

		$plugins = get_plugins();
		return [
			'version_before' => (string) ( $expected_current ?? '' ),
			'version_after'  => (string) ( $plugins[ $file ]['Version'] ?? $expected_version ),
			'sha256'         => $actual,
		];
	}
}
