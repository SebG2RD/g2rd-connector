<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use G2RD\Connector\Settings;

/**
 * Settings::sanitize() est le filtre que WordPress applique à CHAQUE écriture de
 * l'option (register_setting). Une clé qu'il ne connaît pas est jetée sans bruit :
 * c'est ce qui rendait la case « IPv4 vers le manager » impossible à enregistrer
 * dans la 1.12.0-rc.3 (cochée, enregistrée, décochée au rechargement).
 */
final class SettingsSanitizeTest extends TestCase {

	public function test_la_case_ipv4_vers_le_manager_survit_a_l_enregistrement(): void {
		self::assertTrue( Settings::sanitize( [ 'force_ipv4_to_manager' => true ] )['force_ipv4_to_manager'] );
	}

	public function test_la_case_ipv4_peut_etre_decochee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'force_ipv4_to_manager' => true ];

		self::assertFalse( Settings::sanitize( [ 'force_ipv4_to_manager' => false ] )['force_ipv4_to_manager'] );
	}

	/**
	 * Garde-fou pour les réglages à venir : toute option booléenne déclarée dans
	 * defaults() doit pouvoir passer à l'état inverse de son défaut.
	 */
	public function test_chaque_option_booleenne_des_valeurs_par_defaut_est_enregistrable(): void {
		// La lecture du journal ne s'active qu'avec le relevé (cf. Settings::align_php_errors()).
		$prerequisites = [ 'php_errors_log_tail' => 'php_errors_enabled' ];
		foreach ( Settings::defaults() as $key => $default ) {
			if ( ! is_bool( $default ) ) {
				continue;
			}
			unset( $this->options[ Settings::OPTION_KEY ] );
			if ( isset( $prerequisites[ $key ] ) ) {
				$this->options[ Settings::OPTION_KEY ] = [ $prerequisites[ $key ] => true ];
			}
			$saved = Settings::sanitize( [ $key => ! $default ] );

			self::assertSame( ! $default, $saved[ $key ], sprintf( 'L\'option « %s » est jetée par Settings::sanitize().', $key ) );
		}
	}

	/**
	 * K4 — l'enregistrement des réglages (filtre sanitize de register_setting, appliqué
	 * à chaque update_option) ne doit jamais altérer un jeton v2 : son alphabet est
	 * celui de base64 plus « : », que sanitize_text_field() laisse intact.
	 */
	public function test_l_enregistrement_des_reglages_conserve_un_jeton_v2(): void {
		// Réplique des transformations de sanitize_text_field() qui pourraient toucher
		// une chaîne ASCII : balises, octets %xx, blancs.
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->alias(
			static function ( string $text ): string {
				// Le bouchon reproduit wp_strip_all_tags(), qui appelle strip_tags() ; la fonction
				// WordPress n'est pas chargée sous PHPUnit.
				$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
				$text = (string) preg_replace( '/%[a-f0-9]{2}/i', '', $text );
				$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
				return trim( $text, " \n\r\t\v\0" );
			}
		);

		foreach ( [ 'sb', 'gcm' ] as $cipher ) {
			for ( $i = 0; $i < 20; $i++ ) {
				$v2 = Settings::encrypt_token( 'jeton-' . bin2hex( random_bytes( 24 ) ), $cipher );

				self::assertMatchesRegularExpression( '#^enc:v2:(sb|gcm):[A-Za-z0-9+/=]+$#', $v2 );
				self::assertSame( $v2, Settings::sanitize( [ 'site_token' => $v2 ] )['site_token'] );
			}
		}
	}

	// ── Connexion directe depuis G2RD (1.13, spec connexion WordPress §5.5 et §5.6) ──

	public function test_la_connexion_directe_est_autorisee_par_defaut(): void {
		self::assertTrue( Settings::get( 'allow_direct_login' ) );
	}

	/** Un site passé de la 1.12 à la 1.13 n'a pas la clé en base : « absente = cochée ». */
	public function test_une_installation_anterieure_sans_la_cle_reste_autorisee(): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'           => 7,
			'heartbeat_enabled' => false,
		];

		self::assertTrue( Settings::get( 'allow_direct_login' ) );
	}

	public function test_la_case_connexion_directe_se_decoche_et_se_recoche(): void {
		self::assertFalse( Settings::sanitize( [ 'allow_direct_login' => false ] )['allow_direct_login'] );

		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];
		self::assertTrue( Settings::sanitize( [ 'allow_direct_login' => true ] )['allow_direct_login'] );
	}

	public function test_un_enregistrement_qui_ne_porte_pas_la_case_la_laisse_telle_quelle(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];

		self::assertFalse( Settings::sanitize( [ 'heartbeat_enabled' => true ] )['allow_direct_login'] );
	}

	/** Relevé des erreurs PHP (1.16) : éteint par défaut, lecture du journal aussi. */
	public function test_le_releve_des_erreurs_php_est_desactive_par_defaut(): void {
		self::assertFalse( Settings::defaults()['php_errors_enabled'] );
		self::assertFalse( Settings::defaults()['php_errors_log_tail'] );
	}

	public function test_relever_coupe_coupe_aussi_la_lecture_du_journal(): void {
		$saved = Settings::sanitize(
			[
				'php_errors_enabled'  => false,
				'php_errors_log_tail' => true,
			]
		);

		self::assertFalse( $saved['php_errors_enabled'] );
		self::assertFalse( $saved['php_errors_log_tail'] );
	}

	public function test_les_deux_cases_du_releve_sont_enregistrables(): void {
		$saved = Settings::sanitize(
			[
				'php_errors_enabled'  => true,
				'php_errors_log_tail' => true,
			]
		);

		self::assertTrue( $saved['php_errors_enabled'] );
		self::assertTrue( $saved['php_errors_log_tail'] );
	}

	/** Valeur partielle qui ne porte que la lecture du journal, relevé coupé en base : coupée. */
	public function test_la_lecture_du_journal_seule_ne_s_active_pas_sans_le_releve(): void {
		self::assertFalse( Settings::sanitize( [ 'php_errors_log_tail' => true ] )['php_errors_log_tail'], 'Relevé absent de la base (défaut).' );

		$this->options[ Settings::OPTION_KEY ] = [ 'php_errors_enabled' => false ];
		$saved                                 = Settings::sanitize( [ 'php_errors_log_tail' => true ] );

		self::assertFalse( $saved['php_errors_enabled'] );
		self::assertFalse( $saved['php_errors_log_tail'] );
	}

	/** Relevé déjà actif en base : la lecture du journal s'active seule. */
	public function test_la_lecture_du_journal_seule_s_active_avec_le_releve_actif(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'php_errors_enabled' => true ];

		self::assertTrue( Settings::sanitize( [ 'php_errors_log_tail' => true ] )['php_errors_log_tail'] );
	}

	/**
	 * Lecture du journal restée active en base sans le relevé (valeur venue d'ailleurs, ou relevé
	 * non strictement `true`) : un enregistrement qui ne porte aucune des deux cases la coupe.
	 */
	public function test_une_lecture_du_journal_active_en_base_sans_releve_est_coupee(): void {
		foreach ( [
			'false' => false,
			"'1'"   => '1',
			'null'  => null,
		] as $label => $enabled ) {
			$this->options[ Settings::OPTION_KEY ] = [
				'php_errors_enabled'  => $enabled,
				'php_errors_log_tail' => true,
			];

			self::assertFalse( Settings::sanitize( [ 'heartbeat_enabled' => true ] )['php_errors_log_tail'], 'Relevé en base : ' . $label );
		}
	}

	/**
	 * Settings::update() (API REST, commandes : register_setting() n'y est pas posé, donc
	 * sanitize() ne passe pas) applique la même règle.
	 */
	public function test_une_ecriture_directe_des_reglages_applique_la_meme_regle(): void {
		Settings::update( [ 'php_errors_log_tail' => true ] );
		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['php_errors_log_tail'], 'Relevé coupé : lecture du journal coupée.' );

		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled'  => false,
			'php_errors_log_tail' => true,
		];
		Settings::update( [ 'last_heartbeat_at' => 'x' ] );
		self::assertFalse( $this->options[ Settings::OPTION_KEY ]['php_errors_log_tail'], 'État incohérent en base : corrigé à l\'écriture suivante.' );

		Settings::update(
			[
				'php_errors_enabled'  => true,
				'php_errors_log_tail' => true,
			]
		);
		self::assertTrue( $this->options[ Settings::OPTION_KEY ]['php_errors_log_tail'], 'Les deux actifs : gardés.' );
	}
}
