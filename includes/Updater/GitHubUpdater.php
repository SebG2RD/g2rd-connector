<?php
/**
 * GitHub Updater pour le plugin G2RD Connector.
 *
 * Permet à WordPress de détecter automatiquement les nouvelles releases publiées
 * sur https://github.com/SebG2RD/g2rd-connector via l'API GitHub, et de les
 * proposer comme mises à jour dans le tableau de bord WP (Plugins → Mises à jour).
 *
 * Adapté du pattern utilisé par g2rd-theme (cf class-github-updater.php du thème) :
 *  - mêmes filtres mais sur la branche `plugins` du système de mise à jour WP :
 *    pre_set_site_transient_update_plugins / plugins_api / upgrader_source_selection
 *  - même stratégie de download : on préfère le ZIP asset uploadé par release.yml
 *    (g2rd-connector-X.Y.Z.zip avec dossier racine `g2rd-connector/`) au zipball
 *    auto-généré GitHub (dont le dossier racine est `{owner}-{repo}-{sha}/`).
 *  - même rename post-extraction pour garantir que le dossier final s'appelle
 *    `g2rd-connector/` quel que soit le nom temporaire du ZIP extrait.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Updater;

use stdClass;
use WP_Error;
use WP_Filesystem_Base;
use WP_Upgrader;

final class GitHubUpdater {

	private const GITHUB_REPO_URL = 'https://github.com/SebG2RD/g2rd-connector';
	private const GITHUB_API_URL  = 'https://api.github.com/repos/SebG2RD/g2rd-connector/releases/latest';
	private const PLUGIN_SLUG     = 'g2rd-connector';
	private const REQUIRES_WP     = '6.4';
	private const REQUIRES_PHP    = '8.1';

	/**
	 * Args communs pour wp_remote_get vers l'API GitHub.
	 *
	 * @var array<string, mixed>
	 */
	private const REQUEST_ARGS = [
		'timeout' => 10,
		'headers' => [
			'Accept'     => 'application/vnd.github.v3+json',
			'User-Agent' => 'WordPress/G2RD-Connector-Updater',
		],
	];

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_updates' ] );
		add_filter( 'plugins_api', [ $this, 'get_plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalize_extracted_folder' ], 10, 4 );
	}

	/**
	 * Hook pre_set_site_transient_update_plugins : injecte une entrée
	 * `response[plugin_basename]` dans le transient si une release GitHub
	 * est plus récente que la version installée.
	 *
	 * @param mixed $transient Objet transient ou false si non encore initialisé.
	 * @return mixed
	 */
	public function check_for_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// WordPress n'a pas encore initialisé le transient (premier check) — on ne
		// touche pas, sinon on perdrait `checked` qui doit être rempli par WP avant.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$plugin_file = self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php';
		$plugin_data = $this->get_plugin_data( $plugin_file );
		if ( ! $plugin_data ) {
			return $transient;
		}
		$current_version = (string) $plugin_data['Version'];

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = $this->extract_version( $release );
		if ( '' === $latest_version ) {
			return $transient;
		}

		if ( version_compare( $current_version, $latest_version, '<' ) ) {
			$update                              = new stdClass();
			$update->id                          = self::GITHUB_REPO_URL;
			$update->slug                        = self::PLUGIN_SLUG;
			$update->plugin                      = $plugin_file;
			$update->new_version                 = $latest_version;
			$update->url                         = self::GITHUB_REPO_URL;
			$update->package                     = $this->get_download_url( $release );
			$update->requires                    = self::REQUIRES_WP;
			$update->requires_php                = self::REQUIRES_PHP;
			$update->tested                      = '6.6';
			$update->icons                       = [];
			$update->banners                     = [];
			$update->banners_rtl                 = [];
			$update->compatibility               = new stdClass();
			$transient->response[ $plugin_file ] = $update;
		} else {
			// Version installée == ou > release GitHub : pas de notif. On nettoie
			// au cas où une entrée stale traînerait dans `response`.
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	/**
	 * Hook plugins_api : alimente le modal "Voir les détails" (changelog,
	 * description, screenshots) avec les infos GitHub Release.
	 *
	 * @param false|object|array<string,mixed> $result
	 * @param string                           $action
	 * @param object                           $args
	 * @return false|stdClass
	 */
	public function get_plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$latest_version = $this->extract_version( $release );
		if ( '' === $latest_version ) {
			return $result;
		}

		$info                 = new stdClass();
		$info->name           = 'G2RD Connector';
		$info->slug           = self::PLUGIN_SLUG;
		$info->version        = $latest_version;
		$info->author         = '<a href="https://g2rd.fr">G2RD Web Agency</a>';
		$info->author_profile = 'https://github.com/SebG2RD';
		$info->homepage       = self::GITHUB_REPO_URL;
		$info->requires       = self::REQUIRES_WP;
		$info->requires_php   = self::REQUIRES_PHP;
		$info->tested         = '6.6';
		$info->last_updated   = (string) ( $release['published_at'] ?? '' );
		$info->download_link  = $this->get_download_url( $release );
		$info->trunk          = $info->download_link;
		$info->sections       = [
			'description' => '<p>'
				. esc_html__(
					'Plugin agent qui connecte ce site WordPress au tableau de bord G2RD WP Manager : inventaire, télémétrie, événements et commandes distantes.',
					'g2rd-connector'
				)
				. '</p>',
			'changelog'   => $this->format_changelog( (string) ( $release['body'] ?? '' ) ),
		];

		return $info;
	}

	/**
	 * Hook upgrader_source_selection : normalise le dossier extrait du ZIP
	 * vers `g2rd-connector/`. GitHub livre les ZIP avec un nom temporaire
	 * (zipball : `{owner}-{repo}-{sha}` / asset uploaded : déjà bon, géré par release.yml).
	 *
	 * @param string                $source
	 * @param string                $remote_source
	 * @param WP_Upgrader           $upgrader
	 * @param array<string, mixed>  $args
	 * @return string|WP_Error
	 */
	public function normalize_extracted_folder( string $source, string $remote_source, WP_Upgrader $upgrader, array $args ): string|WP_Error {
		unset( $upgrader, $remote_source ); // params imposés par le filter, non utilisés

		if ( ! isset( $args['plugin'] ) ) {
			return $source;
		}
		$expected_basename = self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php';
		if ( $args['plugin'] !== $expected_basename ) {
			return $source;
		}

		$wp_filesystem = $this->get_filesystem();
		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'g2rd_connector_filesystem_unavailable',
				__( 'Le système de fichiers WordPress est indisponible pour le renommage du plugin.', 'g2rd-connector' )
			);
		}

		$source = trailingslashit( $source );

		// Cas 1 : $source contient directement g2rd-connector.php → ZIP correctement
		// nommé (asset release.yml), il suffit d'aligner le dossier sur le slug.
		if ( $wp_filesystem->is_file( $source . self::PLUGIN_SLUG . '.php' ) ) {
			$source_dir = basename( untrailingslashit( $source ) );
			if ( $source_dir === self::PLUGIN_SLUG ) {
				return $source;
			}
			return $this->move_to_slug( $source, $wp_filesystem );
		}

		// Cas 2 : $source est un dossier wrapper (zipball GitHub) → chercher
		// un sous-dossier qui contient g2rd-connector.php.
		$entries = $wp_filesystem->dirlist( $source );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $name => $filedata ) {
				if ( 'd' !== $filedata['type'] ) {
					continue;
				}
				$inner = trailingslashit( $source . $name );
				if ( $wp_filesystem->is_file( $inner . self::PLUGIN_SLUG . '.php' ) ) {
					if ( $name === self::PLUGIN_SLUG ) {
						return $inner;
					}
					return $this->move_to_slug( $inner, $wp_filesystem );
				}
			}
		}

		return $source;
	}

	/**
	 * Renomme un dossier source vers le slug du plugin.
	 *
	 * @return string|WP_Error Nouveau chemin ou WP_Error.
	 */
	private function move_to_slug( string $source, WP_Filesystem_Base $filesystem ): string|WP_Error {
		$new_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . self::PLUGIN_SLUG;

		if ( $filesystem->is_dir( $new_source ) ) {
			$filesystem->delete( $new_source, true );
		}

		if ( ! $filesystem->move( $source, $new_source ) ) {
			return new WP_Error(
				'g2rd_connector_rename_failed',
				sprintf(
					/* translators: 1: ancien chemin, 2: nouveau chemin */
					__( 'Impossible de renommer le dossier du plugin de %1$s vers %2$s.', 'g2rd-connector' ),
					esc_html( $source ),
					esc_html( $new_source )
				)
			);
		}

		return trailingslashit( $new_source );
	}

	/**
	 * Récupère la dernière release GitHub.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetch_latest_release(): ?array {
		$response = wp_remote_get( self::GITHUB_API_URL, self::REQUEST_ARGS );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function extract_version( array $release ): string {
		if ( ! isset( $release['tag_name'] ) ) {
			return '';
		}
		$tag = ltrim( (string) $release['tag_name'], 'v' );
		if ( ! preg_match( '/^\d+\.\d+/', $tag ) ) {
			return '';
		}
		return $tag;
	}

	/**
	 * Préfère le ZIP asset (release.yml uploadé) au zipball auto-généré.
	 *
	 * @param array<string, mixed> $release
	 */
	private function get_download_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if (
					! empty( $asset['browser_download_url'] )
					&& ! empty( $asset['name'] )
					&& str_ends_with( (string) $asset['name'], '.zip' )
					&& ! str_contains( (string) $asset['name'], '.sha256' )
				) {
					return (string) $asset['browser_download_url'];
				}
			}
		}
		return (string) ( $release['zipball_url'] ?? '' );
	}

	/**
	 * Lit le header du plugin pour récupérer la version installée. Retourne
	 * null si get_plugins() n'est pas dispo (contexte précoce).
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_plugin_data( string $plugin_file ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $plugin_file ] ) ? (array) $all[ $plugin_file ] : null;
	}

	private function get_filesystem(): ?WP_Filesystem_Base {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		return $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Convertit le corps Markdown d'une release GitHub en HTML pour le modal
	 * Thickbox. Identique au pattern utilisé par g2rd-theme.
	 */
	private function format_changelog( string $markdown ): string {
		$lines   = explode( "\n", $markdown );
		$html    = '';
		$in_list = false;

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			if ( preg_match( '/^### (.+)$/', $line, $m ) ) {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
				$html .= '<h4>' . esc_html( $m[1] ) . '</h4>';
			} elseif ( preg_match( '/^## (.+)$/', $line, $m ) ) {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
				$html .= '<h3>' . esc_html( $m[1] ) . '</h3>';
			} elseif ( preg_match( '/^[-*] (.+)$/', $line, $m ) ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$item  = esc_html( $m[1] );
				$item  = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item );
				$html .= '<li>' . $item . '</li>';
			} elseif ( '' === $line ) {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
			} else {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
				$para  = esc_html( $line );
				$para  = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $para );
				$html .= '<p>' . $para . '</p>';
			}
		}

		if ( $in_list ) {
			$html .= '</ul>';
		}

		return $html;
	}
}
