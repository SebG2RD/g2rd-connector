<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use G2RD\Connector\Admin\Page;
use G2RD\Connector\Settings;

/**
 * K4 — chiffrement au repos du site_token.
 *
 * Jusqu'à la 1.12.0-rc.4, le jeton était chiffré en AES-256-CBC sans aucune
 * authentification (`enc:v1:`) : une valeur altérée en base se déchiffrait sans
 * erreur en un « jeton » modifié (preuve : inverser un octet de l'IV changeait la
 * première lettre du jeton, au choix). Le format v2 est authentifié : toute
 * altération donne un jeton vide, jamais des octets modifiés. Les anciens formats
 * restent lisibles, à l'octet près.
 */
final class SettingsTokenEncryptionTest extends TestCase {

	private const PLAIN = 'jeton-du-site-0123456789abcdef';

	/** @var array{v1_lisible: array{clair: string, stocke: string}, v1_autre_cle: array{stocke: string}} */
	private array $vectors;

	protected function setUp(): void {
		parent::setUp();
		$json = file_get_contents( __DIR__ . '/fixtures/token-v1-vectors.json' );
		self::assertIsString( $json );
		$this->vectors = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	// ── Écriture ────────────────────────────────────────────────────────────────

	public function test_le_jeton_est_ecrit_au_format_authentifie_v2(): void {
		$stored = Settings::encrypt_token( self::PLAIN );

		self::assertStringStartsWith( 'enc:v2:', $stored );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
	}

	/** Deux chiffrements du même jeton diffèrent (nonce aléatoire). */
	public function test_deux_chiffrements_du_meme_jeton_different(): void {
		self::assertNotSame( Settings::encrypt_token( self::PLAIN ), Settings::encrypt_token( self::PLAIN ) );
	}

	/** Repli sans régression : un hébergeur sans sodium ni GCM garde l'écriture v1. */
	public function test_sans_algorithme_authentifie_on_garde_v1(): void {
		$stored = Settings::encrypt_token( self::PLAIN, 'v1' );

		self::assertStringStartsWith( 'enc:v1:', $stored );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
	}

	/** Le filtre de repli permet de forcer un algorithme disponible (GCM ici). */
	public function test_le_filtre_peut_imposer_gcm(): void {
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'gcm' );

		self::assertStringStartsWith( 'enc:v2:gcm:', Settings::encrypt_token( self::PLAIN ) );
	}

	/** Une valeur de filtre inconnue est ignorée : on garde le choix automatique. */
	public function test_une_valeur_de_filtre_inconnue_est_ignoree(): void {
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'rot13' );

		self::assertStringStartsWith( 'enc:v2:', Settings::encrypt_token( self::PLAIN ) );
	}

	// ── Intégrité ───────────────────────────────────────────────────────────────

	/**
	 * Pendant de la preuve CBC : avec v1, un IV altéré donnait « Xeton-… » sans
	 * erreur. En v2, chaque octet altéré (nonce, chiffré ou tag) donne ''.
	 */
	public function test_un_jeton_v2_altere_n_est_jamais_accepte(): void {
		foreach ( [ 'sb', 'gcm' ] as $cipher ) {
			$stored = Settings::encrypt_token( self::PLAIN, $cipher );
			self::assertStringStartsWith( 'enc:v2:' . $cipher . ':', $stored );

			$length = strlen( $this->raw( $stored ) );
			// Premier octet (nonce), milieu (chiffré ou MAC), dernier octet (tag ou chiffré).
			foreach ( [ 0, intdiv( $length, 2 ), $length - 1 ] as $offset ) {
				$tampered = $this->flip( $stored, $offset );

				self::assertSame( '', Settings::decrypt_token( $tampered ), sprintf( '%s, octet %d', $cipher, $offset ) );

				$this->options[ Settings::OPTION_KEY ] = [
					'site_id'    => 7,
					'site_token' => $tampered,
				];
				self::assertFalse( Settings::token_matches( self::PLAIN ) );
				self::assertSame( 'unreadable', Settings::token_state() );
			}
		}
	}

	public function test_un_jeton_v2_tronque_ou_mal_encode_est_illisible(): void {
		$stored = Settings::encrypt_token( self::PLAIN, 'gcm' );

		self::assertSame( '', Settings::decrypt_token( substr( $stored, 0, 30 ) ) );
		self::assertSame( '', Settings::decrypt_token( 'enc:v2:gcm:@@@pas-du-base64@@@' ) );
		self::assertSame( '', Settings::decrypt_token( 'enc:v2:sb:' ) );
	}

	/** Changer l'étiquette d'algorithme ne permet pas de lire la valeur avec l'autre. */
	public function test_echanger_l_etiquette_d_algorithme_rend_la_valeur_illisible(): void {
		$stored = Settings::encrypt_token( self::PLAIN, 'gcm' );

		self::assertSame( '', Settings::decrypt_token( str_replace( 'enc:v2:gcm:', 'enc:v2:sb:', $stored ) ) );
	}

	// ── Lecture des formats ─────────────────────────────────────────────────────

	public function test_un_jeton_gcm_se_lit_meme_quand_sodium_est_prefere(): void {
		$stored = Settings::encrypt_token( self::PLAIN, 'gcm' );

		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
	}

	public function test_un_jeton_sb_se_lit_quel_que_soit_l_algorithme_d_ecriture(): void {
		$stored = Settings::encrypt_token( self::PLAIN, 'sb' );
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'gcm' );

		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
	}

	public function test_un_jeton_v1_existant_se_lit_toujours(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		self::assertSame( $this->vectors['v1_lisible']['clair'], Settings::site_token() );
		self::assertSame( 'ok', Settings::token_state() );
	}

	public function test_le_jeton_en_clair_historique_reste_lisible(): void {
		self::assertSame( 'jeton-historique', Settings::decrypt_token( 'jeton-historique' ) );
	}

	/** Piège de Settings.php : une valeur `enc:` inconnue n'est jamais du clair. */
	public function test_un_prefixe_enc_inconnu_n_est_pas_un_jeton_en_clair(): void {
		self::assertSame( '', Settings::decrypt_token( 'enc:v9:abc' ) );
		self::assertSame( '', Settings::decrypt_token( 'enc:v2:aes:abc' ) );
	}

	/**
	 * Un connecteur rétrogradé à la main (≤ 1.12.0-rc.4) prend une valeur v2 pour
	 * un jeton en clair et la rechiffre en v1. À la remise à jour, on retrouve le
	 * jeton d'origine.
	 */
	public function test_auto_reparation_apres_retrogradation(): void {
		$v2           = Settings::encrypt_token( self::PLAIN );
		$v1_contenant = Settings::encrypt_token( $v2, 'v1' );

		self::assertSame( self::PLAIN, Settings::decrypt_token( $v1_contenant ) );
	}

	/** L'auto-réparation ne descend pas plus d'un niveau. */
	public function test_l_auto_reparation_est_bornee(): void {
		$v1_v1 = Settings::encrypt_token( Settings::encrypt_token( self::PLAIN, 'v1' ), 'v1' );

		self::assertStringStartsWith( 'enc:v1:', Settings::decrypt_token( $v1_v1 ) );
	}

	// ── Démarrage ───────────────────────────────────────────────────────────────

	/** Pas de réécriture au démarrage : le retour arrière automatique de WordPress reste sain. */
	public function test_le_demarrage_ne_migre_pas_le_v1(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		Settings::maybe_migrate_token();

		self::assertSame( $this->vectors['v1_lisible']['stocke'], $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );
	}

	/** Non-régression du piège : une valeur v2 n'est jamais prise pour du clair. */
	public function test_le_demarrage_ne_rechiffre_jamais_une_valeur_v2(): void {
		$v2                                    = Settings::encrypt_token( self::PLAIN );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v2,
		];

		Settings::maybe_migrate_token();

		self::assertSame( $v2, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
	}

	public function test_le_jeton_en_clair_historique_reste_accepte_puis_chiffre_en_v2(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::PLAIN,
		];

		Settings::maybe_migrate_token();

		self::assertStringStartsWith( 'enc:v2:', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
	}

	// ── Migration à la première écriture ───────────────────────────────────────

	public function test_migration_v1_vers_v2_a_la_premiere_ecriture(): void {
		$v1                                    = $this->vectors['v1_lisible']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		$stored = $this->options[ Settings::OPTION_KEY ]['site_token'];
		self::assertStringStartsWith( 'enc:v2:', $stored );
		self::assertSame( $this->vectors['v1_lisible']['clair'], Settings::site_token() );
		self::assertSame( 'x', $this->options[ Settings::OPTION_KEY ]['last_heartbeat_at'] );
		// La valeur v1 d'origine est conservée, à l'octet près.
		self::assertSame( $v1, $this->options[ Settings::TOKEN_BACKUP_OPTION ] );

		// Un second enregistrement ne touche plus ni le jeton ni la copie.
		Settings::update( [ 'last_heartbeat_at' => 'y' ] );
		self::assertSame( $stored, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( $v1, $this->options[ Settings::TOKEN_BACKUP_OPTION ] );
	}

	/** La copie de secours n'est pas chargée à chaque page (autoload désactivé). */
	public function test_la_copie_de_secours_n_est_pas_autochargee(): void {
		$autoload = [];
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload_flag = null ) use ( &$autoload ): bool {
				$this->options[ $key ] = $value;
				$autoload[ $key ]      = $autoload_flag;
				return true;
			}
		);
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertFalse( $autoload[ Settings::TOKEN_BACKUP_OPTION ] );
	}

	public function test_un_v1_illisible_n_est_jamais_ecrase(): void {
		$v1                                    = $this->vectors['v1_autre_cle']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( $v1, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( 'unreadable', Settings::token_state() );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );
	}

	/** Si la copie de secours ne peut pas être écrite, on ne migre pas. */
	public function test_sans_copie_de_secours_le_v1_reste_en_place(): void {
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				if ( Settings::TOKEN_BACKUP_OPTION === $key ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
		$v1                                    = $this->vectors['v1_lisible']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( $v1, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( 'x', $this->options[ Settings::OPTION_KEY ]['last_heartbeat_at'] );
	}

	/** Le filtre ramené à « v1 » coupe la migration (repli d'urgence). */
	public function test_le_filtre_v1_suspend_la_migration(): void {
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'v1' );
		$v1                                    = $this->vectors['v1_lisible']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( $v1, $this->options[ Settings::OPTION_KEY ]['site_token'] );
	}

	/**
	 * Après une rétrogradation puis une remise à jour, la copie de secours reste
	 * lisible par un ancien connecteur : jamais un v1 qui contiendrait du v2.
	 */
	public function test_la_copie_de_secours_reste_lisible_par_un_ancien_connecteur(): void {
		$v1_contenant                          = Settings::encrypt_token( Settings::encrypt_token( self::PLAIN ), 'v1' );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1_contenant,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( self::PLAIN, Settings::site_token() );
		$backup = $this->options[ Settings::TOKEN_BACKUP_OPTION ];
		self::assertStringStartsWith( 'enc:v1:', $backup );
		self::assertSame( self::PLAIN, $this->legacy_decrypt( $backup ) );
	}

	/** Un enrôlement écrit directement en v2 (sans copie de secours : rien à sauver). */
	public function test_un_enrolement_ecrit_directement_en_v2(): void {
		Settings::update(
			[
				'site_id'    => 7,
				'site_token' => self::PLAIN,
			]
		);

		self::assertStringStartsWith( 'enc:v2:', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );
	}

	public function test_la_deconnexion_vide_toujours_le_jeton(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		Settings::update(
			[
				'site_id'    => null,
				'site_token' => '',
			]
		);

		self::assertSame( '', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( 'none', Settings::token_state() );
	}

	// ── État et avis administrateur ─────────────────────────────────────────────

	public function test_etat_du_jeton(): void {
		self::assertSame( 'none', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ] = [ 'site_token' => 'jeton-historique' ];
		self::assertSame( 'ok', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ] = [ 'site_token' => 'enc:v9:abc' ];
		self::assertSame( 'unreadable', Settings::token_state() );
	}

	public function test_l_avis_jeton_illisible_s_affiche_aux_administrateurs(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_autre_cle']['stocke'],
		];

		$html = $this->render_notice();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'ne peut pas être lu', $html );
		self::assertStringContainsString( 'AUTH_KEY', $html );
		self::assertStringContainsString( 'nouvelle invitation', $html );
	}

	public function test_l_avis_n_est_pas_montre_sans_droit_manage_options(): void {
		Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_autre_cle']['stocke'],
		];

		self::assertSame( '', $this->render_notice() );
	}

	public function test_aucun_avis_quand_le_jeton_est_lisible(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		self::assertSame( '', $this->render_notice() );
	}

	// ── Outils ──────────────────────────────────────────────────────────────────

	private function render_notice(): string {
		ob_start();
		( new Page() )->render_token_notice();
		return (string) ob_get_clean();
	}

	/** Octets bruts d'une valeur v2 (après `enc:v2:<alg>:`). */
	private function raw( string $stored ): string {
		$payload = substr( $stored, strpos( $stored, ':', 7 ) + 1 );
		$raw     = base64_decode( $payload, true );
		self::assertIsString( $raw );
		return $raw;
	}

	private function flip( string $stored, int $offset ): string {
		$prefix         = substr( $stored, 0, strpos( $stored, ':', 7 ) + 1 );
		$raw            = $this->raw( $stored );
		$raw[ $offset ] = chr( ord( $raw[ $offset ] ) ^ 0x01 );
		return $prefix . base64_encode( $raw );
	}

	/** Déchiffrement v1 exactement comme le fait un connecteur ≤ 1.12.0-rc.4. */
	private function legacy_decrypt( string $stored ): string {
		$raw   = (string) base64_decode( substr( $stored, 7 ), true );
		$plain = openssl_decrypt(
			substr( $raw, 16 ),
			'aes-256-cbc',
			hash( 'sha256', 'g2rd-connector|g2rd-connector-fallback-key-material', true ),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, 16 )
		);
		return is_string( $plain ) ? $plain : '';
	}
}
