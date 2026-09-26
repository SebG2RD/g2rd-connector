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
}
