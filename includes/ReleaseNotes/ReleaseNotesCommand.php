<?php
/**
 * Commande signée `get_release_notes` (1.18.0) : notes de version d'une extension ou d'un thème
 * installé, lues comme le lien « Afficher les détails de la version » de WordPress :
 * plugins_api( 'plugin_information' ) ou themes_api( 'theme_information' ), avec les sections,
 * auxquels l'éditeur répond par son propre filtre (spécification « Notes de version », § 5.3).
 *
 * Lecture seule : le code de la commande n'écrit ni option, ni transient, ni fichier (liste fermée
 * des appels : tests/ReleaseNotes/ReadOnlyTest.php). WordPress (cache d'objets, transient
 * `theme_roots`), le module de mise à jour du connecteur (cache de la dernière version publiée sur
 * GitHub, Updater\GitHubUpdater, quand la cible est le connecteur lui-même) et celui de l'éditeur
 * peuvent rafraîchir leurs caches pendant l'appel, comme sur l'écran des extensions.
 *
 * Provenance : celle de l'inventaire, transient lu tel quel puis complété, pour une entrée absente
 * de `response`, par la capture des mises à jour tierces (PremiumUpdatesBridge::replay_entry(),
 * lecture seule).
 *
 * Deux écarts voulus avec le lien de WordPress :
 *  - un composant dont la provenance n'est pas wordpress.org (Updates\UpdateSource : `vendor` ou
 *    inconnue) n'est jamais demandé à wordpress.org quand aucun éditeur n'a répondu : il rendrait
 *    « extension introuvable », ou les notes d'un homonyme. Refus NOT_PROVIDED, sans appel ;
 *  - les appels faits pendant la commande sont bornés (HttpLimits : 15 s en tout, redirections
 *    comprises, 8 Mio par réponse, adresses sûres).
 *
 * Signature toujours exigée (CommandExecutor::SIGNED_ONLY). Par `/command` seulement : soumise au
 * réglage « Commandes à distance » (la route n'existe pas sans lui), refusée par la file du
 * battement (HeartbeatJob::queue_outcome()) et par la route en arrière-plan (AsyncCommands::EXCLUDED).
 *
 * Charge : `{"kind": "plugin", "file": "dossier/fichier.php"}` ou `{"kind": "theme", "stylesheet":
 * "dossier"}`, cherchés parmi les extensions et thèmes installés : jamais un chemin libre.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\ReleaseNotes;

use G2RD\Connector\Updates\PremiumUpdatesBridge;
use G2RD\Connector\Updates\UpdateSource;

final class ReleaseNotesCommand {

	public const NAME = 'get_release_notes';

	/** Capacité annoncée par /snapshot (1.18.0) : le manager lit la capacité, jamais un numéro de version. */
	public const CAPABILITY = 'release_notes';

	/** Erreur posée par no_vendor_answer() ; jamais rendue telle quelle (NOT_PROVIDED). */
	public const NO_VENDOR_ANSWER = 'g2rd_release_notes_no_vendor_answer';

	/**
	 * @param HttpLimits|null $limits Bornes des appels (tests) ; HttpLimits par défaut.
	 */
	public function __construct( private ?HttpLimits $limits = null ) {}

	/**
	 * @param array<mixed> $payload Charge signée.
	 * @return array{notes_html: string, truncated: bool, version: string|null, homepage: string|null}
	 * @throws ReleaseNotesRefused Demande invalide, composant absent, notes absentes ou source injoignable.
	 */
	public function run( array $payload ): array {
		$kind = $payload['kind'] ?? null;
		$key  = 'plugin' === $kind ? ( $payload['file'] ?? null ) : ( $payload['stylesheet'] ?? null );
		if ( ( 'plugin' !== $kind && 'theme' !== $kind ) || ! is_string( $key ) || '' === $key ) {
			throw new ReleaseNotesRefused( ReleaseNotesRefused::INVALID_REQUEST ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		}
		if ( ! self::installed( $kind, $key ) ) {
			throw new ReleaseNotesRefused( ReleaseNotesRefused::NOT_INSTALLED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		}

		$transient  = self::as_inventory_sees_it( get_site_transient( 'plugin' === $kind ? 'update_plugins' : 'update_themes' ), $kind, $key );
		$slug       = 'plugin' === $kind ? self::plugin_slug( $transient, $key ) : $key;
		$from_wporg = UpdateSource::WPORG === UpdateSource::of( $transient, $key );

		return NotesAnswer::from( $this->ask( $kind, $slug, $from_wporg ) );
	}

	/**
	 * Slug passé à plugins_api(), pris comme WordPress le prend pour son lien « Afficher les
	 * détails » (wp_plugin_update_row(), WP_Plugins_List_Table) : celui de l'entrée de mise à jour,
	 * sinon le dossier de l'extension, sinon le nom de son fichier sans « .php ».
	 *
	 * @param mixed  $transient Transient `update_plugins` (tel que l'inventaire le voit).
	 * @param string $file      Fichier de l'extension, déjà trouvé parmi les extensions installées.
	 */
	public static function plugin_slug( $transient, string $file ): string {
		$entry = UpdateSource::entry( $transient, $file );
		$slug  = null !== $entry && isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? trim( $entry['slug'] ) : '';
		if ( '' !== $slug ) {
			return $slug;
		}

		return str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
	}

	/**
	 * Filtre `plugins_api` / `themes_api`, posé en dernier (PHP_INT_MAX) pour un composant qui ne
	 * vient pas de wordpress.org : quand aucun éditeur n'a répondu (réponse vide), l'appel à
	 * wordpress.org est remplacé par une erreur.
	 *
	 * @internal Callback WordPress — public par nécessité.
	 *
	 * @param mixed $result Réponse des filtres précédents (false : aucune).
	 * @return mixed
	 */
	public static function no_vendor_answer( $result ) {
		return $result ? $result : new \WP_Error( self::NO_VENDOR_ANSWER, 'No vendor answer.' );
	}

	private static function installed( string $kind, string $key ): bool {
		if ( 'theme' === $kind ) {
			return array_key_exists( $key, wp_get_themes() );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_key_exists( $key, get_plugins() );
	}

	/**
	 * Transient tel que l'inventaire le voit : la lecture brute, complétée, pour une entrée absente de
	 * `response`, par la capture des mises à jour tierces (PremiumUpdatesBridge::replay_entry(),
	 * lecture seule), comme le fait le rejeu de l'inventaire. Sans elle, un updater absent du
	 * contexte REST laisserait une entrée wordpress.org homonyme de `no_update` décider, et la
	 * commande lirait les notes de l'homonyme alors que l'inventaire annonce `vendor`. La copie reste
	 * locale : rien n'est écrit.
	 *
	 * @param mixed $transient Transient `update_plugins` ou `update_themes`.
	 * @return mixed
	 */
	private static function as_inventory_sees_it( $transient, string $kind, string $key ) {
		$fields   = is_object( $transient ) ? get_object_vars( $transient ) : [];
		$response = isset( $fields['response'] ) && is_array( $fields['response'] ) ? $fields['response'] : [];
		if ( array_key_exists( $key, $response ) ) {
			return $transient;
		}
		$entry = PremiumUpdatesBridge::replay_entry( 'plugin' === $kind ? 'plugins' : 'themes', $key );
		if ( null === $entry ) {
			return $transient;
		}
		$response[ $key ]   = 'plugin' === $kind ? (object) $entry : $entry;
		$fields['response'] = $response;

		return (object) $fields;
	}

	/**
	 * L'appel, borné, avec le garde quand le composant ne vient pas de wordpress.org (posé sur les
	 * deux crochets : un seul sert, et leurs noms restent écrits en toutes lettres pour
	 * ReadOnlyTest).
	 *
	 * @return mixed Réponse de plugins_api() ou themes_api() : objet, tableau toléré.
	 * @throws ReleaseNotesRefused NOT_PROVIDED (aucun éditeur n'a répondu) ou SOURCE_UNAVAILABLE.
	 */
	private function ask( string $kind, string $slug, bool $from_wporg ) {
		$guard = [ self::class, 'no_vendor_answer' ];
		if ( ! $from_wporg ) {
			add_filter( 'plugins_api', $guard, PHP_INT_MAX );
			add_filter( 'themes_api', $guard, PHP_INT_MAX );
		}

		try {
			$answer = ( $this->limits ?? new HttpLimits() )->around(
				static fn () => self::call_api( $kind, $slug )
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			throw new ReleaseNotesRefused( ReleaseNotesRefused::SOURCE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		} finally {
			if ( ! $from_wporg ) {
				remove_filter( 'plugins_api', $guard, PHP_INT_MAX );
				remove_filter( 'themes_api', $guard, PHP_INT_MAX );
			}
		}

		if ( $answer instanceof \WP_Error ) {
			throw new ReleaseNotesRefused( self::NO_VENDOR_ANSWER === $answer->get_error_code() ? ReleaseNotesRefused::NOT_PROVIDED : ReleaseNotesRefused::SOURCE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		}

		return $answer;
	}

	/**
	 * L'appel de WordPress, avec les sections ; fichiers d'administration chargés s'il le faut (une
	 * requête REST ne les charge pas).
	 *
	 * @return mixed
	 */
	private static function call_api( string $kind, string $slug ) {
		$args = [
			'slug'   => $slug,
			'fields' => [ 'sections' => true ],
		];
		if ( 'plugin' === $kind ) {
			if ( ! function_exists( 'plugins_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}
			return plugins_api( 'plugin_information', $args );
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		return themes_api( 'theme_information', $args );
	}
}
