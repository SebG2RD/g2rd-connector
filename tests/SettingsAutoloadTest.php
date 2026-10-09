<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use Brain\Monkey\Functions;
use G2RD\Connector\Plugin;
use G2RD\Connector\Settings;

/**
 * L'option `g2rd_connector_settings` est lue à chaque page (démarrage du plugin).
 * Écrite sans autoload jusqu'à la 1.13.0-rc.1, elle coûtait une requête SQL par
 * page vue ; autochargée, elle arrive avec les autres options de WordPress.
 *
 * Nouvelles écritures : autoload. Installations existantes : migrées une seule fois
 * par wp_set_option_autoload() (WordPress 6.4, version minimale du plugin).
 */
final class SettingsAutoloadTest extends TestCase {

	/** @var array<string, mixed> Dernier drapeau d'autoload passé à update_option(), par option. */
	private array $autoload = [];

	/** @var array<string, mixed> Options autochargées simulées (wp_load_alloptions). */
	private array $alloptions = [];

	/** @var list<array{0: string, 1: mixed}> Appels à wp_set_option_autoload(). */
	private array $autoload_changes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->autoload         = [];
		$this->alloptions       = [];
		$this->autoload_changes = [];

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value, $autoload_flag = null ): bool {
				$this->options[ $key ]  = $value;
				$this->autoload[ $key ] = $autoload_flag;
				return true;
			}
		);
		Functions\when( 'wp_load_alloptions' )->alias( fn (): array => $this->alloptions );
		Functions\when( 'wp_set_option_autoload' )->alias(
			function ( string $option, $autoload ): bool {
				$this->autoload_changes[] = [ $option, $autoload ];
				// Ce que fait WordPress : l'option rejoint les options autochargées.
				$this->alloptions[ $option ] = $this->options[ $option ] ?? '';
				return true;
			}
		);
	}

	public function test_une_ecriture_des_reglages_est_autochargee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7 ];

		Settings::update( [ 'last_heartbeat_at' => '2026-10-09T08:00:00+00:00' ] );

		self::assertTrue( $this->autoload[ Settings::OPTION_KEY ] );
	}

	public function test_les_reglages_par_defaut_sont_autocharges(): void {
		Settings::ensure_defaults();

		self::assertSame( Settings::defaults(), $this->options[ Settings::OPTION_KEY ] );
		self::assertTrue( $this->autoload[ Settings::OPTION_KEY ] );
	}

	public function test_une_installation_existante_est_migree_une_seule_fois(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7 ];

		self::assertTrue( Settings::maybe_autoload() );
		self::assertFalse( Settings::maybe_autoload() );
		self::assertFalse( Settings::maybe_autoload() );

		self::assertSame( [ [ Settings::OPTION_KEY, true ] ], $this->autoload_changes );
		self::assertSame( [ 'site_id' => 7 ], $this->options[ Settings::OPTION_KEY ], 'La valeur n\'est jamais réécrite.' );
	}

	public function test_une_option_deja_autochargee_n_est_pas_touchee(): void {
		$this->options[ Settings::OPTION_KEY ]    = [ 'site_id' => 7 ];
		$this->alloptions[ Settings::OPTION_KEY ] = [ 'site_id' => 7 ];

		self::assertFalse( Settings::maybe_autoload() );
		self::assertSame( [], $this->autoload_changes );
	}

	/** Rien n'est créé : sans réglages en base, il n'y a rien à migrer. */
	public function test_sans_reglages_en_base_rien_n_est_fait(): void {
		self::assertFalse( Settings::maybe_autoload() );
		self::assertSame( [], $this->autoload_changes );
		self::assertArrayNotHasKey( Settings::OPTION_KEY, $this->options );
	}

	/** Un refus de la base n'est pas pris pour une migration faite : nouvel essai au démarrage suivant. */
	public function test_un_echec_de_la_base_sera_retente(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'site_id' => 7 ];
		Functions\when( 'wp_set_option_autoload' )->justReturn( false );

		self::assertFalse( Settings::maybe_autoload() );
		self::assertFalse( Settings::maybe_autoload() );
	}

	/** La migration a lieu au démarrage, avant la barrière d'enrôlement (un site non enrôlé lit aussi ses réglages). */
	public function test_le_demarrage_migre_les_reglages(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->options[ Settings::OPTION_KEY ] = [ 'manager_url' => 'https://manager.invalid' ];

		Plugin::instance()->boot();

		self::assertSame( [ [ Settings::OPTION_KEY, true ] ], $this->autoload_changes );
	}

	/**
	 * Autochargée, l'option est chargée à chaque page avec les autres : elle doit
	 * rester petite. Pire cas réaliste : jeton au format v1 qui enveloppe un v2
	 * (connecteur rétrogradé à la main), jeton deux fois plus long qu'un jeton réel,
	 * adresse de plateforme longue.
	 */
	public function test_l_option_reste_petite(): void {
		$v2    = Settings::encrypt_token( str_repeat( 'a1b2', 32 ), 'gcm' );
		$token = Settings::encrypt_token( $v2, 'v1' );
		self::assertStringStartsWith( 'enc:v1:', $token );

		$settings = array_merge(
			Settings::defaults(),
			[
				'manager_url'       => 'https://' . str_repeat( 'm', 120 ) . '.example',
				'site_id'           => PHP_INT_MAX,
				'site_token'        => $token,
				'enrolled_at'       => '2026-10-09T08:00:00+00:00',
				'last_heartbeat_at' => '2026-10-09T08:00:00+00:00',
				'signature_policy'  => 'required',
			]
		);

		self::assertLessThan( 2048, strlen( serialize( $settings ) ) );
	}
}
