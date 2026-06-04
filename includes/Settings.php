<?php
/**
 * Gestion centralisée des options du plugin (URL manager, token site, statut).
 *
 * Toutes les valeurs sont stockées dans une seule clé wp_options
 * (`g2rd_connector_settings`) pour faciliter export/import et purge.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector;

final class Settings {

	public const OPTION_KEY = 'g2rd_connector_settings';

	/**
	 * Schéma + valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'manager_url'             => 'https://wp-manager.g2rd.fr',
			'site_id'                 => null,        // attribué après enrollment
			'site_token'              => '',          // Bearer présenté par le manager
			'enrolled_at'             => null,        // ISO8601
			'last_heartbeat_at'       => null,        // ISO8601
			'heartbeat_enabled'       => true,
			'events_enabled'          => true,
			'remote_commands_enabled' => true,
		];
	}

	/**
	 * Sanitize callback pour register_setting() : nettoie chaque champ selon
	 * son type avant stockage. Les cles non soumises conservent leur valeur
	 * courante (merge sur self::all()).
	 *
	 * @param mixed $value Valeur brute soumise via l'API Settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return self::all();
		}

		$clean = [];
		if ( isset( $value['manager_url'] ) ) {
			$clean['manager_url'] = esc_url_raw( (string) $value['manager_url'] );
		}
		if ( array_key_exists( 'site_id', $value ) ) {
			$clean['site_id'] = ( null === $value['site_id'] || '' === $value['site_id'] )
				? null
				: absint( $value['site_id'] );
		}
		foreach ( [ 'site_token', 'enrolled_at', 'last_heartbeat_at' ] as $text_key ) {
			if ( isset( $value[ $text_key ] ) ) {
				$clean[ $text_key ] = sanitize_text_field( (string) $value[ $text_key ] );
			}
		}
		foreach ( [ 'heartbeat_enabled', 'events_enabled', 'remote_commands_enabled' ] as $flag ) {
			if ( isset( $value[ $flag ] ) ) {
				$clean[ $flag ] = (bool) $value[ $flag ];
			}
		}

		return array_replace_recursive( self::all(), $clean );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_replace_recursive( self::defaults(), $stored );
	}

	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $partial
	 */
	public static function update( array $partial ): void {
		$current = self::all();
		$merged  = array_replace_recursive( $current, $partial );
		update_option( self::OPTION_KEY, $merged, false );
	}

	public static function ensure_defaults(): void {
		if ( get_option( self::OPTION_KEY, null ) === null ) {
			update_option( self::OPTION_KEY, self::defaults(), false );
		}
	}

	public static function is_enrolled(): bool {
		return ! empty( self::get( 'site_token' ) ) && ! empty( self::get( 'site_id' ) );
	}

	public static function token_matches( string $candidate ): bool {
		$stored = (string) self::get( 'site_token' );
		if ( '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $candidate );
	}
}
