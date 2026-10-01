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
		foreach ( Settings::defaults() as $key => $default ) {
			if ( ! is_bool( $default ) ) {
				continue;
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
				return trim( $text );
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
}
