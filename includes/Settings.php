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
		// Le site_token est chiffré au repos (cf encrypt_token) : on intercepte
		// toute écriture d'un token EN CLAIR (enrollment) pour ne jamais le
		// persister tel quel en base. Les autres champs passent inchangés.
		if ( array_key_exists( 'site_token', $partial ) ) {
			$token                 = (string) $partial['site_token'];
			$partial['site_token'] = '' === $token ? '' : self::encrypt_token( $token );
		}
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
		$stored = self::site_token();
		if ( '' === $stored ) {
			return false;
		}
		return hash_equals( $stored, $candidate );
	}

	/**
	 * Renvoie le site_token EN CLAIR (déchiffré). À utiliser pour la comparaison
	 * d'auth entrante (token_matches) et le Bearer sortant vers le manager.
	 */
	public static function site_token(): string {
		return self::decrypt_token( (string) self::get( 'site_token' ) );
	}

	/**
	 * Migration unique : ré-chiffre un site_token legacy encore stocké en clair
	 * (installations antérieures au chiffrement au repos). Appelée au boot ;
	 * no-op dès que la valeur est préfixée `enc:v1:` (ou si openssl absent).
	 */
	public static function maybe_migrate_token(): void {
		$stored = (string) self::get( 'site_token' );
		if ( '' === $stored || 0 === strpos( $stored, 'enc:v1:' ) || ! function_exists( 'openssl_encrypt' ) ) {
			return;
		}
		self::update( [ 'site_token' => $stored ] );
	}

	/**
	 * Clé de chiffrement (32 octets) dérivée des sels WordPress, définis dans
	 * wp-config.php donc HORS base de données : un dump SQL seul ne permet pas
	 * de déchiffrer le token — il faut aussi wp-config.php.
	 */
	private static function enc_key(): string {
		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $const ) {
			if ( defined( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		if ( '' === $material ) {
			$material = 'g2rd-connector-fallback-key-material';
		}
		return hash( 'sha256', 'g2rd-connector|' . $material, true );
	}

	/**
	 * Chiffre le site_token pour stockage au repos (AES-256-CBC + IV aléatoire),
	 * préfixé `enc:v1:`. Dégrade en clair si l'extension openssl est absente
	 * (l'enrollment reste fonctionnel).
	 */
	public static function encrypt_token( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return $plain;
		}
		return 'enc:v1:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Déchiffre un site_token stocké. Rétro-compatible : renvoie tel quel une
	 * valeur legacy non préfixée (plaintext) ou vide.
	 */
	public static function decrypt_token( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, 'enc:v1:' ) ) {
			return $stored;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 7 ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		return is_string( $plain ) ? $plain : '';
	}
}
