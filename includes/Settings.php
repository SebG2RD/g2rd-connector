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
	 * Copie de la valeur v1 du site_token prise au moment de sa migration au format v2
	 * (K4). Jamais lue par le plugin, non autochargée : elle permet de remettre en
	 * service un site dont le connecteur aurait été rétrogradé à la main vers une
	 * version ≤ 1.12.0-rc.4, qui ne sait lire que le format v1.
	 */
	public const TOKEN_BACKUP_OPTION = 'g2rd_connector_site_token_v1';

	/** Préfixe du format v1 : AES-256-CBC, sans authentification (1.6.7 à 1.12.0-rc.4). */
	private const PREFIX_V1 = 'enc:v1:';

	/** Préfixe du format v2 : chiffrement authentifié, algorithme écrit dans la valeur. */
	private const PREFIX_V2 = 'enc:v2:';

	/** Données associées (GCM) et étiquette de dérivation des clés du format v2. */
	private const V2_AAD = 'g2rd-connector|site_token|v2';

	/** Ordre de préférence des algorithmes d'écriture (le plus sûr d'abord). */
	private const CIPHER_PREFERENCE = [ 'sb', 'gcm', 'v1' ];

	private const SB_NONCE_BYTES  = 24;
	private const GCM_NONCE_BYTES = 12;
	private const TAG_BYTES       = 16;

	/**
	 * Jetons déjà déchiffrés pendant la requête, indexés par la valeur stockée.
	 *
	 * @var array<string, string>
	 */
	private static array $plain_cache = [];

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
			// Forcer l'IPv4 pour les appels sortants VERS LE MANAGER uniquement. Le
			// 2026-09-25, le CDN de l'hébergeur refusait l'IPv6 sortante du serveur
			// (403) : battements de cœur, événements et enrôlement mouraient en route
			// alors que le site répondait à ses visiteurs. Dormant par défaut.
			'force_ipv4_to_manager'   => false,
			// `report` : signature vérifiée, échec remonté, requête acceptée.
			// `required` : toute requête non signée ou mal signée est refusée.
			'signature_policy'        => 'report',
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
		// Toute case à cocher déclarée dans defaults() doit figurer ici : une clé absente
		// est jetée à chaque enregistrement (défaut de la 1.12.0-rc.3 sur l'option IPv4).
		foreach ( [ 'heartbeat_enabled', 'events_enabled', 'remote_commands_enabled', 'force_ipv4_to_manager' ] as $flag ) {
			if ( isset( $value[ $flag ] ) ) {
				$clean[ $flag ] = (bool) $value[ $flag ];
			}
		}
		if ( isset( $value['signature_policy'] ) ) {
			// Toute valeur inconnue retombe sur la politique la plus permissive : une
			// saisie erronée ne doit jamais pouvoir couper le site du manager.
			$clean['signature_policy'] = 'required' === $value['signature_policy'] ? 'required' : 'report';
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
		} else {
			// K4 : un jeton encore au format v1 (non authentifié) passe au format v2
			// à la première écriture des réglages, jamais au démarrage.
			$migrated = self::migrate_v1_token();
			if ( null !== $migrated ) {
				$partial['site_token'] = $migrated;
			}
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
	 * État du site_token stocké :
	 *  - `none` : aucun jeton (site non enrôlé) ;
	 *  - `ok` : jeton lisible ;
	 *  - `unreadable` : valeur chiffrée qui ne se déchiffre pas (altérée en base, ou
	 *    clés de sécurité de wp-config.php changées). Le site ne peut plus parler au
	 *    manager ; l'administrateur en est averti (cf. Admin\Page::render_token_notice).
	 */
	public static function token_state(): string {
		$stored = (string) self::get( 'site_token' );
		if ( '' === $stored ) {
			return 'none';
		}
		return '' === self::decrypt_token( $stored ) ? 'unreadable' : 'ok';
	}

	/**
	 * Migration unique : chiffre un site_token legacy encore stocké en clair
	 * (installations antérieures au chiffrement au repos). Appelée au boot.
	 *
	 * Toute valeur `enc:` (v1, v2 ou format inconnu) est laissée intacte : la prendre
	 * pour du clair la rechiffrerait, et le jeton deviendrait la chaîne `enc:…`
	 * elle-même (site déconnecté). Le passage v1 → v2 n'a PAS lieu ici mais à la
	 * première écriture des réglages (cf. update) : le contrôle de bouclage de
	 * WordPress après une mise à jour automatique ne réécrit donc jamais la valeur,
	 * et un retour automatique à la version précédente la relit sans peine.
	 */
	public static function maybe_migrate_token(): void {
		$stored = (string) self::get( 'site_token' );
		if ( '' === $stored || str_starts_with( $stored, 'enc:' ) || null === self::write_cipher() ) {
			return;
		}
		self::update( [ 'site_token' => $stored ] );
	}

	/**
	 * Matériau de clé : les sels WordPress, définis dans wp-config.php donc HORS base
	 * de données. Un dump SQL seul ne permet pas de déchiffrer le token — il faut
	 * aussi wp-config.php.
	 */
	private static function key_material(): string {
		$material = '';
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $const ) {
			if ( defined( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		if ( '' === $material ) {
			$material = 'g2rd-connector-fallback-key-material';
		}
		return $material;
	}

	/**
	 * Clé du format v1 (32 octets). Dérivation inchangée depuis la 1.6.7 : les jetons
	 * déjà stockés doivent rester lisibles.
	 */
	private static function enc_key(): string {
		return hash( 'sha256', 'g2rd-connector|' . self::key_material(), true );
	}

	/**
	 * Clé du format v2 (32 octets), propre à chaque algorithme et indépendante de la
	 * clé v1. Même matériau (les sels) : aucun nouveau secret à gérer.
	 */
	private static function v2_key( string $cipher ): string {
		return hash_hmac( 'sha256', self::V2_AAD . '|' . $cipher, self::key_material(), true );
	}

	/**
	 * Chiffre le site_token pour stockage au repos.
	 *
	 * Format v2 (K4), authentifié : `enc:v2:<alg>:` + base64( nonce ‖ chiffré ‖ tag ),
	 * avec `<alg>` = `sb` (sodium secretbox, XSalsa20-Poly1305) ou `gcm`
	 * (AES-256-GCM). L'algorithme est écrit dans la valeur : la lecture ne dépend
	 * jamais de ce qui était disponible à l'écriture. Chaque valeur v2 est relue
	 * avant d'être rendue ; en cas d'échec on passe à l'algorithme suivant, jusqu'au
	 * format v1 (AES-256-CBC), puis au clair si l'hébergeur n'a ni sodium ni openssl
	 * (l'enrollment reste fonctionnel, comme avant).
	 *
	 * @param string      $plain  Jeton en clair.
	 * @param string|null $cipher `sb`, `gcm` ou `v1` pour imposer le premier essai
	 *                            (usage interne et tests) ; null = choix automatique.
	 */
	public static function encrypt_token( string $plain, ?string $cipher = null ): string {
		if ( '' === $plain ) {
			return $plain;
		}
		$cipher = $cipher ?? self::write_cipher();
		$rank   = null === $cipher ? false : array_search( $cipher, self::CIPHER_PREFERENCE, true );
		if ( false === $rank ) {
			return $plain;
		}
		$available = self::available_ciphers();
		foreach ( array_slice( self::CIPHER_PREFERENCE, (int) $rank ) as $candidate ) {
			if ( ! in_array( $candidate, $available, true ) ) {
				continue;
			}
			$stored = 'v1' === $candidate ? self::encrypt_v1( $plain ) : self::encrypt_v2( $plain, $candidate );
			if ( null === $stored ) {
				continue;
			}
			$check = 'v1' === $candidate ? self::decrypt_v1( $stored ) : self::decrypt_v2( $stored );
			if ( hash_equals( $plain, $check ) ) {
				return $stored;
			}
		}
		return $plain;
	}

	/**
	 * Déchiffre un site_token stocké.
	 *
	 *  - valeur vide ou sans préfixe `enc:` : jeton legacy EN CLAIR (installations
	 *    antérieures à la 1.6.7, hébergeurs sans openssl), rendu tel quel ;
	 *  - `enc:v2:` : déchiffrement authentifié ; une valeur altérée donne '' (jamais
	 *    des octets modifiés) ;
	 *  - `enc:v1:` : AES-256-CBC, chemin historique inchangé. Si le clair obtenu est
	 *    lui-même une valeur v2 — ce que produit un connecteur ≤ 1.12.0-rc.4 réinstallé
	 *    à la main par-dessus un jeton déjà migré —, il est déchiffré à son tour (un
	 *    seul niveau) ;
	 *  - tout autre préfixe `enc:` : '' (jamais pris pour du clair).
	 *
	 * Résultat mémorisé pour la requête : sodium_compat, en PHP pur, ne doit pas être
	 * rejoué à chaque appel.
	 */
	public static function decrypt_token( string $stored ): string {
		if ( '' === $stored || ! str_starts_with( $stored, 'enc:' ) ) {
			return $stored;
		}
		if ( isset( self::$plain_cache[ $stored ] ) ) {
			return self::$plain_cache[ $stored ];
		}
		if ( str_starts_with( $stored, self::PREFIX_V2 ) ) {
			$plain = self::decrypt_v2( $stored );
		} elseif ( str_starts_with( $stored, self::PREFIX_V1 ) ) {
			$plain = self::decrypt_v1( $stored );
			if ( str_starts_with( $plain, self::PREFIX_V2 ) ) {
				$plain = self::decrypt_v2( $plain );
			}
		} else {
			$plain = '';
		}
		if ( count( self::$plain_cache ) >= 8 ) {
			self::$plain_cache = [];
		}
		self::$plain_cache[ $stored ] = $plain;
		return $plain;
	}

	/**
	 * Passage v1 → v2 à la première écriture des réglages (en pratique, le battement
	 * de cœur que le manager vient d'accepter). Renvoie la nouvelle valeur, ou null
	 * pour laisser la valeur stockée intacte à l'octet près : rien à migrer, aucun
	 * algorithme authentifié (ou filtre ramené à `v1`), jeton illisible, aller-retour
	 * v2 raté, ou copie de secours impossible à écrire. Un jeton n'est jamais perdu.
	 */
	private static function migrate_v1_token(): ?string {
		$stored = (string) self::get( 'site_token' );
		if ( ! str_starts_with( $stored, self::PREFIX_V1 ) ) {
			return null;
		}
		$cipher = self::write_cipher();
		if ( 'sb' !== $cipher && 'gcm' !== $cipher ) {
			return null;
		}
		$plain = self::decrypt_token( $stored );
		if ( '' === $plain || str_starts_with( $plain, 'enc:' ) ) {
			return null;
		}
		$migrated = self::encrypt_token( $plain, $cipher );
		if ( ! str_starts_with( $migrated, self::PREFIX_V2 ) || ! hash_equals( $plain, self::decrypt_v2( $migrated ) ) ) {
			return null;
		}
		if ( ! self::save_v1_backup( $stored, $plain ) ) {
			return null;
		}
		return $migrated;
	}

	/**
	 * Conserve une valeur v1 du jeton dans TOKEN_BACKUP_OPTION (non autochargée) avant
	 * la migration : la valeur d'origine si elle se déchiffre directement en jeton,
	 * sinon un v1 neuf du même jeton — toujours lisible par un ancien connecteur.
	 * Une copie déjà présente pour le même jeton est gardée telle quelle.
	 */
	private static function save_v1_backup( string $stored, string $plain ): bool {
		$backup = hash_equals( $plain, self::decrypt_v1( $stored ) ) ? $stored : self::encrypt_v1( $plain );
		if ( null === $backup ) {
			return false;
		}
		$existing = get_option( self::TOKEN_BACKUP_OPTION, '' );
		if ( is_string( $existing ) && str_starts_with( $existing, self::PREFIX_V1 ) && hash_equals( $plain, self::decrypt_v1( $existing ) ) ) {
			return true;
		}
		update_option( self::TOKEN_BACKUP_OPTION, $backup, false );
		return get_option( self::TOKEN_BACKUP_OPTION, '' ) === $backup;
	}

	/**
	 * Algorithme d'écriture : le premier disponible dans l'ordre de préférence, sauf
	 * choix contraire du filtre `g2rd_connector_token_cipher`. null = aucun (ni
	 * sodium ni openssl : le jeton reste en clair, comme avant).
	 */
	private static function write_cipher(): ?string {
		$available = self::available_ciphers();
		if ( [] === $available ) {
			return null;
		}
		/**
		 * Filtre l'algorithme de chiffrement au repos du site_token : `sb` (sodium),
		 * `gcm` (AES-256-GCM) ou `v1` (AES-256-CBC, ancien format non authentifié :
		 * repli d'urgence qui suspend aussi la migration v1 → v2). Une valeur inconnue
		 * ou indisponible sur l'hébergeur est ignorée. La lecture n'en dépend jamais.
		 *
		 * @param string $cipher Algorithme choisi automatiquement.
		 */
		$wanted = apply_filters( 'g2rd_connector_token_cipher', $available[0] );
		return is_string( $wanted ) && in_array( $wanted, $available, true ) ? $wanted : $available[0];
	}

	/**
	 * Algorithmes utilisables sur cet hébergeur, dans l'ordre de préférence.
	 *
	 * @return list<string>
	 */
	private static function available_ciphers(): array {
		$available = [];
		if ( self::sodium_available() ) {
			$available[] = 'sb';
		}
		if ( self::gcm_available() ) {
			$available[] = 'gcm';
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$available[] = 'v1';
		}
		return $available;
	}

	/**
	 * Sodium natif, sinon la bibliothèque sodium_compat livrée avec WordPress (que
	 * WordPress charge déjà lui-même quand l'extension manque).
	 */
	private static function sodium_available(): bool {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) && defined( 'WPINC' ) ) {
			$compat = ABSPATH . WPINC . '/sodium_compat/autoload.php';
			if ( is_readable( $compat ) ) {
				require_once $compat;
			}
		}
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	private static function gcm_available(): bool {
		static $available = null;
		if ( null === $available ) {
			$available = function_exists( 'openssl_encrypt' )
				&& function_exists( 'openssl_get_cipher_methods' )
				&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
		}
		return $available;
	}

	/**
	 * Format v1 (AES-256-CBC + IV aléatoire, préfixe `enc:v1:`), sans authentification.
	 * Conservé pour les hébergeurs sans sodium ni GCM et pour la copie de secours.
	 */
	private static function encrypt_v1( string $plain ): ?string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return null;
		}
		return self::PREFIX_V1 . base64_encode( $iv . $cipher );
	}

	private static function decrypt_v1( string $stored ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX_V1 ) ), true );
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', self::enc_key(), OPENSSL_RAW_DATA, $iv );
		return is_string( $plain ) ? $plain : '';
	}

	private static function encrypt_v2( string $plain, string $cipher ): ?string {
		try {
			if ( 'sb' === $cipher ) {
				$nonce = random_bytes( self::SB_NONCE_BYTES );
				$box   = sodium_crypto_secretbox( $plain, $nonce, self::v2_key( 'sb' ) );
				return self::PREFIX_V2 . 'sb:' . base64_encode( $nonce . $box );
			}
			if ( 'gcm' === $cipher ) {
				$nonce = random_bytes( self::GCM_NONCE_BYTES );
				$tag   = '';
				$box   = openssl_encrypt( $plain, 'aes-256-gcm', self::v2_key( 'gcm' ), OPENSSL_RAW_DATA, $nonce, $tag, self::V2_AAD, self::TAG_BYTES );
				if ( false === $box || self::TAG_BYTES !== strlen( $tag ) ) {
					return null;
				}
				return self::PREFIX_V2 . 'gcm:' . base64_encode( $nonce . $box . $tag );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	private static function decrypt_v2( string $stored ): string {
		$rest = substr( $stored, strlen( self::PREFIX_V2 ) );
		$sep  = strpos( $rest, ':' );
		if ( false === $sep ) {
			return '';
		}
		$cipher = substr( $rest, 0, $sep );
		$raw    = base64_decode( substr( $rest, $sep + 1 ), true );
		if ( false === $raw ) {
			return '';
		}
		try {
			if ( 'sb' === $cipher ) {
				if ( strlen( $raw ) <= self::SB_NONCE_BYTES + self::TAG_BYTES || ! self::sodium_available() ) {
					return '';
				}
				$plain = sodium_crypto_secretbox_open(
					substr( $raw, self::SB_NONCE_BYTES ),
					substr( $raw, 0, self::SB_NONCE_BYTES ),
					self::v2_key( 'sb' )
				);
				return is_string( $plain ) ? $plain : '';
			}
			if ( 'gcm' === $cipher ) {
				if ( strlen( $raw ) <= self::GCM_NONCE_BYTES + self::TAG_BYTES || ! function_exists( 'openssl_decrypt' ) ) {
					return '';
				}
				$plain = openssl_decrypt(
					substr( $raw, self::GCM_NONCE_BYTES, -self::TAG_BYTES ),
					'aes-256-gcm',
					self::v2_key( 'gcm' ),
					OPENSSL_RAW_DATA,
					substr( $raw, 0, self::GCM_NONCE_BYTES ),
					substr( $raw, -self::TAG_BYTES ),
					self::V2_AAD
				);
				return is_string( $plain ) ? $plain : '';
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}
}
