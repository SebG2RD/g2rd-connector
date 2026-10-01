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

	/** État du filtre `g2rd_connector_token_v2` simulé (null : filtre non simulé). */
	private ?bool $v2_enabled = null;

	protected function setUp(): void {
		parent::setUp();
		$json = file_get_contents( __DIR__ . '/fixtures/token-v1-vectors.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local, WordPress non chargé.
		self::assertIsString( $json );
		$this->vectors = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}

	// ── Écriture ────────────────────────────────────────────────────────────────

	public function test_le_jeton_est_ecrit_au_format_authentifie_v2(): void {
		$this->v2();
		$stored = Settings::encrypt_token( self::PLAIN );

		self::assertStringStartsWith( 'enc:v2:', $stored );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
	}

	/** Deux chiffrements du même jeton diffèrent (nonce aléatoire). */
	public function test_deux_chiffrements_du_meme_jeton_different(): void {
		$this->v2();
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
		$this->v2();
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'gcm' );

		self::assertStringStartsWith( 'enc:v2:gcm:', Settings::encrypt_token( self::PLAIN ) );
	}

	/** Une valeur de filtre inconnue est ignorée : on garde le choix automatique. */
	public function test_une_valeur_de_filtre_inconnue_est_ignoree(): void {
		$this->v2();
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
		$this->v2();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		self::assertSame( $this->vectors['v1_lisible']['clair'], Settings::site_token() );
		// Lisible, mais non authentifié : signalé pour le diagnostic, jamais refusé par défaut.
		self::assertSame( 'legacy', Settings::token_state() );
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
		$this->v2();
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
		$this->v2();
		$v2                                    = Settings::encrypt_token( self::PLAIN );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v2,
		];

		Settings::maybe_migrate_token();

		self::assertSame( $v2, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
	}

	/**
	 * Au démarrage, le clair historique est chiffré au format qu'écrivait la rc.4
	 * (v1) : un retour arrière automatique vers la rc.4 le relit toujours. Le passage
	 * en v2 se fait à la première écriture des réglages, avec copie de secours.
	 */
	public function test_le_jeton_en_clair_historique_reste_accepte_puis_chiffre_en_v2(): void {
		$this->v2();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::PLAIN,
		];

		Settings::maybe_migrate_token();

		$v1 = $this->options[ Settings::OPTION_KEY ]['site_token'];
		self::assertStringStartsWith( 'enc:v1:', $v1 );
		self::assertSame( self::PLAIN, $this->legacy_decrypt( $v1 ) );
		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertStringStartsWith( 'enc:v2:', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertSame( $v1, $this->options[ Settings::TOKEN_BACKUP_OPTION ] );
	}

	/** Un clair qui n'est pas passé par le démarrage migre aussi à la première écriture. */
	public function test_un_jeton_en_clair_migre_en_v2_a_la_premiere_ecriture(): void {
		$this->v2();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => self::PLAIN,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertStringStartsWith( 'enc:v2:', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertSame( self::PLAIN, $this->legacy_decrypt( $this->options[ Settings::TOKEN_BACKUP_OPTION ] ) );
	}

	// ── Migration à la première écriture ───────────────────────────────────────

	public function test_migration_v1_vers_v2_a_la_premiere_ecriture(): void {
		$this->v2();
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
		$this->v2();
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
		$this->v2();
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
		$this->v2();
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
		$this->v2();
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
		$this->v2();
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
		$this->v2();
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

	/**
	 * Après une migration, « Déconnecter du manager » ne laisse pas l'ancien jeton en
	 * base dans un format non authentifié : la copie est vidée, jamais supprimée.
	 */
	public function test_la_deconnexion_vide_aussi_la_copie_de_secours(): void {
		$this->v2();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];
		Settings::update( [ 'last_heartbeat_at' => 'x' ] );
		self::assertNotSame( '', $this->options[ Settings::TOKEN_BACKUP_OPTION ] );

		Settings::update(
			[
				'site_id'    => null,
				'site_token' => '',
			]
		);

		self::assertArrayHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );
		self::assertSame( '', $this->options[ Settings::TOKEN_BACKUP_OPTION ] );
	}

	/**
	 * Réenrôlement après une migration : la copie suit le jeton courant. Sinon la
	 * procédure de rétrogradation documentée remettrait l'ancien jeton, périmé.
	 */
	public function test_le_reenrolement_aligne_la_copie_de_secours(): void {
		$this->v2();
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];
		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		Settings::update(
			[
				'site_id'    => 8,
				'site_token' => 'jeton-du-reenrolement',
			]
		);

		self::assertStringStartsWith( 'enc:v2:', $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertSame( 'jeton-du-reenrolement', Settings::site_token() );
		$backup = $this->options[ Settings::TOKEN_BACKUP_OPTION ];
		self::assertStringStartsWith( 'enc:v1:', $backup );
		self::assertSame( 'jeton-du-reenrolement', $this->legacy_decrypt( $backup ) );
	}

	/** Déconnexion puis nouvel enrôlement : la copie, vidée, reprend le nouveau jeton. */
	public function test_la_copie_videe_reprend_le_jeton_du_nouvel_enrolement(): void {
		$this->v2();
		$this->options[ Settings::TOKEN_BACKUP_OPTION ] = '';

		Settings::update(
			[
				'site_id'    => 9,
				'site_token' => 'jeton-neuf',
			]
		);

		self::assertSame( 'jeton-neuf', $this->legacy_decrypt( $this->options[ Settings::TOKEN_BACKUP_OPTION ] ) );
	}

	// ── État et avis administrateur ─────────────────────────────────────────────

	public function test_etat_du_jeton(): void {
		$this->v2();
		self::assertSame( 'none', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ] = [ 'site_token' => 'jeton-historique' ];
		self::assertSame( 'legacy', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ] = [ 'site_token' => Settings::encrypt_token( self::PLAIN ) ];
		self::assertSame( 'ok', Settings::token_state() );

		// Un v1 qui enveloppe un v2 (connecteur rétrogradé) : contenu authentifié.
		$this->options[ Settings::OPTION_KEY ] = [ 'site_token' => Settings::encrypt_token( Settings::encrypt_token( self::PLAIN ), 'v1' ) ];
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
		self::assertStringContainsString( 'Déconnecter du manager', $html );
		self::assertStringContainsString( 'fiche dans le manager', $html );
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

	// ── Anciens formats : signalés, refusables sur demande ──────────────────────

	/**
	 * Preuve du LEAD DEV : quelqu'un qui peut écrire en base n'a pas besoin de toucher
	 * la valeur v2, il lui suffit de la remplacer par un ancien format. Par défaut ces
	 * valeurs restent acceptées (compatibilité), mais l'état n'est plus « ok ».
	 */
	public function test_une_valeur_v1_ou_claire_substituee_est_signalee(): void {
		$this->v2();
		$v1      = Settings::encrypt_token( self::PLAIN, 'v1' );
		$raw     = (string) base64_decode( substr( $v1, 7 ), true );
		$raw[0]  = chr( ord( $raw[0] ) ^ ( ord( 'j' ) ^ ord( 'k' ) ) );
		$altered = 'enc:v1:' . base64_encode( $raw );

		self::assertSame( 'keton-du-site-0123456789abcdef', Settings::decrypt_token( $altered ) );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $altered,
		];
		self::assertSame( 'legacy', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ]['site_token'] = 'jeton-choisi-par-attaquant';
		self::assertSame( 'jeton-choisi-par-attaquant', Settings::site_token() );
		self::assertSame( 'legacy', Settings::token_state() );
	}

	public function test_le_mode_strict_est_dormant_par_defaut(): void {
		self::assertFalse( Settings::strict_token_storage() );
		self::assertSame( self::PLAIN, Settings::decrypt_token( Settings::encrypt_token( self::PLAIN, 'v1' ) ) );
		self::assertSame( 'jeton-historique', Settings::decrypt_token( 'jeton-historique' ) );
	}

	/** Mode strict : le v1 seul et le clair sont refusés, jamais une valeur authentifiée. */
	public function test_le_mode_strict_refuse_le_v1_seul_et_le_clair(): void {
		$this->strict();
		$v2           = Settings::encrypt_token( self::PLAIN );
		$v1_contenant = Settings::encrypt_token( $v2, 'v1' );

		self::assertTrue( Settings::strict_token_storage() );
		self::assertSame( '', Settings::decrypt_token( $this->vectors['v1_lisible']['stocke'] ) );
		self::assertSame( '', Settings::decrypt_token( 'jeton-choisi-par-attaquant' ) );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $v2 ) );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $v1_contenant ) );

		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => 'jeton-choisi-par-attaquant',
		];
		self::assertFalse( Settings::token_matches( 'jeton-choisi-par-attaquant' ) );
		self::assertSame( 'refused', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ]['site_token'] = $this->vectors['v1_lisible']['stocke'];
		self::assertSame( 'refused', Settings::token_state() );

		$this->options[ Settings::OPTION_KEY ]['site_token'] = $v1_contenant;
		self::assertSame( 'ok', Settings::token_state() );
		self::assertTrue( Settings::token_matches( self::PLAIN ) );
	}

	/** Le mode strict ne blanchit jamais une valeur refusée : ni migration, ni chiffrement au démarrage. */
	public function test_le_mode_strict_ne_migre_pas_une_valeur_refusee(): void {
		$this->strict();
		$v1                                    = $this->vectors['v1_lisible']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( $v1, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );

		$this->options[ Settings::OPTION_KEY ]['site_token'] = 'jeton-choisi-par-attaquant';
		Settings::maybe_migrate_token();
		self::assertSame( 'jeton-choisi-par-attaquant', $this->options[ Settings::OPTION_KEY ]['site_token'] );
	}

	/** En mode strict, un enrôlement écrit en v2 : le site reste joignable. */
	public function test_le_mode_strict_accepte_un_nouvel_enrolement(): void {
		$this->strict();

		Settings::update(
			[
				'site_id'    => 7,
				'site_token' => self::PLAIN,
			]
		);

		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertSame( 'ok', Settings::token_state() );
	}

	/**
	 * Sans algorithme authentifié pour écrire (ici le repli d'urgence `v1`), le mode
	 * strict reste inactif : il couperait sinon le site sur ses propres écritures.
	 */
	public function test_le_mode_strict_est_inactif_sans_ecriture_authentifiee(): void {
		Filters\expectApplied( 'g2rd_connector_require_authenticated_token' )->andReturn( true );
		Filters\expectApplied( 'g2rd_connector_token_cipher' )->andReturn( 'v1' );

		self::assertFalse( Settings::strict_token_storage() );
		self::assertSame( self::PLAIN, Settings::decrypt_token( Settings::encrypt_token( self::PLAIN, 'v1' ) ) );
	}

	/** La constante de wp-config.php (hors base) active le mode strict. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_la_constante_de_wp_config_active_le_mode_strict(): void {
		define( 'G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN', true );
		define( 'G2RD_CONNECTOR_TOKEN_V2', true );

		self::assertTrue( Settings::strict_token_storage() );
		self::assertSame( '', Settings::decrypt_token( 'jeton-historique' ) );
	}

	public function test_l_avis_jeton_refuse_explique_la_cause_et_l_action(): void {
		$this->strict();
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		$html = $this->render_notice();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'ancien format', $html );
		self::assertStringContainsString( 'G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN', $html );
		self::assertStringContainsString( 'Déconnecter du manager', $html );
		self::assertStringContainsString( 'nouvelle invitation', $html );
	}

	/** Un format ancien mais accepté ne déclenche aucun avis (la migration suit). */
	public function test_aucun_avis_pour_un_format_ancien_accepte(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => 'jeton-historique',
		];

		self::assertSame( '', $this->render_notice() );
	}

	// ── Page du connecteur ──────────────────────────────────────────────────────

	/** Jeton illisible : plus de bandeau vert « Site enrôlé », mais l'état réel et l'action. */
	public function test_la_page_montre_l_etat_reel_quand_le_jeton_est_illisible(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_autre_cle']['stocke'],
		];

		$html = $this->render_page();

		self::assertStringNotContainsString( 'notice-success', $html );
		self::assertStringNotContainsString( 'Site enrôlé.', $html );
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Déconnecter du manager', $html );
	}

	public function test_la_page_garde_le_bandeau_vert_quand_le_jeton_est_lisible(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => Settings::encrypt_token( self::PLAIN ),
		];

		$html = $this->render_page();

		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'Site enrôlé.', $html );
	}

	// ── encrypt_token : jamais de clair par erreur ──────────────────────────────

	/** Un algorithme inconnu passé en argument retombe sur le choix automatique. */
	public function test_un_algorithme_inconnu_n_ecrit_jamais_en_clair(): void {
		$this->v2();
		foreach ( [ 'aes', '', 'SB', 'clair' ] as $cipher ) {
			$stored = Settings::encrypt_token( self::PLAIN, $cipher );

			self::assertStringStartsWith( 'enc:v2:', $stored, $cipher );
			self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
		}
	}

	// ── BootData ────────────────────────────────────────────────────────────────

	public function test_boot_data_expose_l_etat_et_le_mode_strict(): void {
		$this->v2();
		Functions\when( 'rest_url' )->justReturn( 'https://site.test/wp-json/g2rd/v1/' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'get_site_option' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => 'jeton-historique',
		];

		$data = \G2RD\Connector\BootData::build();

		self::assertSame( 'legacy', $data['tokenState'] );
		self::assertFalse( $data['tokenStrict'] );

		$this->strict();
		$data = \G2RD\Connector\BootData::build();
		self::assertSame( 'refused', $data['tokenState'] );
		self::assertTrue( $data['tokenStrict'] );
	}

	// ── Hébergeur sans sodium natif ─────────────────────────────────────────────

	/**
	 * Sans l'extension sodium, la bibliothèque sodium_compat de WordPress est chargée
	 * et sert à l'écriture comme à la lecture. Le bouchon de wp-includes/sodium_compat
	 * reproduit ce que fait WordPress : il définit les fonctions manquantes.
	 */
	public function test_sodium_compat_est_charge_et_utilise_sans_l_extension(): void {
		$this->v2();
		$this->sodium_functions( 'g2rd_test_compat_secretbox', 'g2rd_test_compat_secretbox_open' );
		$GLOBALS['g2rd_test_compat_calls'] = [];

		$stored = Settings::encrypt_token( self::PLAIN );

		self::assertTrue( function_exists( 'g2rd_test_compat_secretbox' ) );
		self::assertStringStartsWith( 'enc:v2:sb:', $stored );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
		self::assertContains( 'box', $GLOBALS['g2rd_test_compat_calls'] );
		self::assertContains( 'open', $GLOBALS['g2rd_test_compat_calls'] );

		// Interopérabilité : une valeur écrite avec sodium_compat se lit avec l'extension
		// (l'hébergeur qui l'installe plus tard ne perd pas le jeton).
		$autre = Settings::encrypt_token( 'jeton-ecrit-par-compat' );
		$this->sodium_functions( 'sodium_crypto_secretbox', 'sodium_crypto_secretbox_open' );
		$GLOBALS['g2rd_test_compat_calls'] = [];

		self::assertSame( 'jeton-ecrit-par-compat', Settings::decrypt_token( $autre ) );
		self::assertSame( [], $GLOBALS['g2rd_test_compat_calls'] );
	}

	/** Ni sodium ni sodium_compat : l'écriture passe en GCM, une valeur sb devient illisible et signalée. */
	public function test_sans_sodium_ni_compat_l_ecriture_passe_en_gcm(): void {
		$this->v2();
		$sb = Settings::encrypt_token( self::PLAIN, 'sb' );
		$this->sodium_functions( 'g2rd_test_sodium_absent', 'g2rd_test_sodium_absent_open' );

		$stored = Settings::encrypt_token( self::PLAIN );

		self::assertStringStartsWith( 'enc:v2:gcm:', $stored );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $stored ) );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $sb,
		];
		self::assertSame( 'unreadable', Settings::token_state() );
	}

	// ── Écriture v2 dormante et interrupteur de retour (constat de relecture) ───

	/**
	 * Par défaut, le site écrit toujours le format v1 que lit la 1.12.0-rc.4 : une
	 * version publiée sans le lecteur v2 ne couperait aucun site.
	 */
	public function test_par_defaut_le_jeton_est_ecrit_en_v1_lisible_par_la_rc4(): void {
		$stored = Settings::encrypt_token( self::PLAIN );

		self::assertStringStartsWith( 'enc:v1:', $stored );
		self::assertSame( self::PLAIN, $this->legacy_decrypt( $stored ) );
		self::assertFalse( Settings::token_v2_enabled() );
	}

	/** Par défaut, ni le battement de cœur ni un enrôlement n'écrivent du v2. */
	public function test_par_defaut_aucune_migration_vers_v2(): void {
		$v1                                    = $this->vectors['v1_lisible']['stocke'];
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( $v1, $this->options[ Settings::OPTION_KEY ]['site_token'] );
		self::assertArrayNotHasKey( Settings::TOKEN_BACKUP_OPTION, $this->options );

		Settings::update(
			[
				'site_id'    => 8,
				'site_token' => 'jeton-du-reenrolement',
			]
		);
		self::assertSame( 'jeton-du-reenrolement', $this->legacy_decrypt( $this->options[ Settings::OPTION_KEY ]['site_token'] ) );
	}

	/** Le format v1 écrit par choix n'est pas signalé comme suspect. */
	public function test_par_defaut_un_jeton_v1_est_dans_l_etat_ok(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $this->vectors['v1_lisible']['stocke'],
		];

		self::assertSame( 'ok', Settings::token_state() );
	}

	/** La constante de wp-config.php active l'écriture et la migration v2. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_la_constante_de_wp_config_active_l_ecriture_v2(): void {
		define( 'G2RD_CONNECTOR_TOKEN_V2', true );

		self::assertTrue( Settings::token_v2_enabled() );
		self::assertStringStartsWith( 'enc:v2:', Settings::encrypt_token( self::PLAIN ) );
	}

	/**
	 * Vrai interrupteur : une fois l'option retirée, la première écriture des réglages
	 * remet une valeur v2 au format v1, que relit une version ≤ 1.12.0-rc.4.
	 */
	public function test_sans_l_option_v2_une_valeur_v2_redevient_v1_a_la_premiere_ecriture(): void {
		$this->v2();
		$v2 = Settings::encrypt_token( self::PLAIN );
		self::assertStringStartsWith( 'enc:v2:', $v2 );
		$this->v2( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v2,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		$stored = $this->options[ Settings::OPTION_KEY ]['site_token'];
		self::assertStringStartsWith( 'enc:v1:', $stored );
		self::assertSame( self::PLAIN, $this->legacy_decrypt( $stored ) );
		self::assertSame( self::PLAIN, Settings::site_token() );
		self::assertSame( 'x', $this->options[ Settings::OPTION_KEY ]['last_heartbeat_at'] );
	}

	/** Le v1 qui enveloppe un v2 (rc.4 réinstallée à la main) redevient un v1 simple. */
	public function test_sans_l_option_v2_un_v1_qui_enveloppe_un_v2_redevient_un_v1_simple(): void {
		$this->v2();
		$v1_contenant = Settings::encrypt_token( Settings::encrypt_token( self::PLAIN ), 'v1' );
		$this->v2( false );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $v1_contenant,
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( self::PLAIN, $this->legacy_decrypt( $this->options[ Settings::OPTION_KEY ]['site_token'] ) );
	}

	/** Un v2 illisible n'est jamais réécrit (rien à « déchiffrer », rien à perdre). */
	public function test_sans_l_option_v2_un_v2_illisible_reste_intact(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => 'enc:v2:sb:illisible',
		];

		Settings::update( [ 'last_heartbeat_at' => 'x' ] );

		self::assertSame( 'enc:v2:sb:illisible', $this->options[ Settings::OPTION_KEY ]['site_token'] );
	}

	/**
	 * Le lecteur v2 ne dépend d'aucune option : toute version qui suit doit le garder
	 * (procédure de release). Sans lui, un site migré serait coupé du manager.
	 */
	public function test_le_lecteur_v2_reste_actif_sans_l_option(): void {
		$this->v2();
		$sb  = Settings::encrypt_token( self::PLAIN, 'sb' );
		$gcm = Settings::encrypt_token( self::PLAIN, 'gcm' );
		$this->v2( false );

		self::assertFalse( Settings::token_v2_enabled() );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $sb ) );
		self::assertSame( self::PLAIN, Settings::decrypt_token( $gcm ) );
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => 7,
			'site_token' => $sb,
		];
		self::assertSame( 'ok', Settings::token_state() );
		self::assertTrue( Settings::token_matches( self::PLAIN ) );
	}

	/** Sans l'option v2, le mode strict reste inactif même si sa constante est posée. */
	public function test_sans_l_option_v2_le_mode_strict_reste_inactif(): void {
		Filters\expectApplied( 'g2rd_connector_require_authenticated_token' )->andReturn( true );

		self::assertFalse( Settings::strict_token_storage() );
		self::assertSame( self::PLAIN, Settings::decrypt_token( Settings::encrypt_token( self::PLAIN ) ) );
	}

	// ── Outils ──────────────────────────────────────────────────────────────────

	/**
	 * Active (ou désactive) l'écriture v2 par son filtre (la constante ne peut pas être
	 * retirée d'un processus).
	 */
	private function v2( bool $enabled = true ): void {
		if ( null === $this->v2_enabled ) {
			Filters\expectApplied( 'g2rd_connector_token_v2' )->andReturnUsing( fn (): bool => (bool) $this->v2_enabled );
		}
		$this->v2_enabled = $enabled;
	}

	private function render_notice(): string {
		ob_start();
		( new Page() )->render_token_notice();
		return (string) ob_get_clean();
	}

	/** Page autonome du connecteur (sans formulaire soumis). */
	private function render_page(): string {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'get_site_option' )->justReturn( false );
		ob_start();
		( new Page() )->render_standalone_page();
		return (string) ob_get_clean();
	}

	/** Active le mode strict par le filtre (la constante ne peut pas être retirée d'un processus). */
	private function strict(): void {
		$this->v2();
		Filters\expectApplied( 'g2rd_connector_require_authenticated_token' )->andReturn( true );
	}

	/**
	 * Remplace les fonctions sodium utilisées par Settings, pour simuler un hébergeur
	 * sans l'extension. Un bouchon de wp-includes/sodium_compat/autoload.php, chargé
	 * par Settings quand ces fonctions manquent, définit les fonctions « compat »
	 * (enveloppes de l'extension qui notent leurs appels), comme le fait WordPress.
	 */
	private function sodium_functions( string $box, string $open ): void {
		if ( ! defined( 'WPINC' ) ) {
			define( 'WPINC', 'wp-includes' );
		}
		$autoload = ABSPATH . WPINC . '/sodium_compat/autoload.php';
		if ( ! is_file( $autoload ) ) {
			if ( ! is_dir( dirname( $autoload ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WordPress (WP_Filesystem) n'est pas chargé sous PHPUnit.
				mkdir( dirname( $autoload ), 0777, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- idem.
			file_put_contents(
				$autoload,
				"<?php\n"
				. "function g2rd_test_compat_secretbox( \$m, \$n, \$k ) { \$GLOBALS['g2rd_test_compat_calls'][] = 'box'; return sodium_crypto_secretbox( \$m, \$n, \$k ); }\n"
				. "function g2rd_test_compat_secretbox_open( \$c, \$n, \$k ) { \$GLOBALS['g2rd_test_compat_calls'][] = 'open'; return sodium_crypto_secretbox_open( \$c, \$n, \$k ); }\n"
			);
		}
		$property = new \ReflectionProperty( Settings::class, 'sodium' );
		$property->setValue(
			null,
			[
				'box'  => $box,
				'open' => $open,
			]
		);
	}

	protected function tearDown(): void {
		$property = new \ReflectionProperty( Settings::class, 'sodium' );
		$property->setValue(
			null,
			[
				'box'  => 'sodium_crypto_secretbox',
				'open' => 'sodium_crypto_secretbox_open',
			]
		);
		parent::tearDown();
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
