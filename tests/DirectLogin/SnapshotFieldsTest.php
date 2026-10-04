<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use Brain\Monkey\Functions;
use G2RD\Connector\DirectLogin\SnapshotFields;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Bloc `site` du snapshot, connecteur 1.13 (spec connexion WordPress §5.4 et §5.6),
 * tel que le lit le manager (RemoteSnapshot, plan partie B, tâche 3).
 */
final class SnapshotFieldsTest extends TestCase {

	/** @var list<mixed> */
	private array $rows = [];

	/** @var array<string, mixed> */
	private array $user_query = [];

	/** @var list<int> Comptes dont le rôle a perdu la capacité manage_options. */
	private array $sans_capacite = [];

	protected function setUp(): void {
		parent::setUp();
		// wp_login_url() est filtrée par les extensions qui déplacent la connexion :
		// c'est ainsi que le manager détecte l'adresse.
		Functions\when( 'wp_login_url' )->justReturn( 'https://site.test/acces-equipe' );
		Functions\when( 'get_users' )->alias(
			function ( array $args ): array {
				$this->user_query = $args;
				return $this->rows;
			}
		);
		// Critère du contrôle fait à l'usage (Gate::REQUIRED_CAPABILITY).
		Functions\when( 'user_can' )->alias(
			fn ( $user, string $capability ): bool => 'manage_options' === $capability && ! in_array( (int) $user, $this->sans_capacite, true )
		);
		$this->rows = [
			(object) [
				'ID'              => '1',
				'user_login'      => 'admin-g2rd',
				'user_email'      => 'admin@site.test',
				'user_registered' => '2019-03-01 10:00:00',
			],
			(object) [
				'ID'              => '3',
				'user_login'      => 'marie',
				'user_email'      => 'marie@site.test',
				'user_registered' => '2021-06-15 08:30:00',
			],
		];
	}

	public function test_les_champs_du_snapshot(): void {
		self::assertSame(
			[
				'login_url'            => 'https://site.test/acces-equipe',
				'direct_login_enabled' => true,
				'admins'               => [
					[
						'id'         => 1,
						'login'      => 'admin-g2rd',
						'email'      => 'admin@site.test',
						'registered' => '2019-03-01 10:00:00',
					],
					[
						'id'         => 3,
						'login'      => 'marie',
						'email'      => 'marie@site.test',
						'registered' => '2021-06-15 08:30:00',
					],
				],
			],
			SnapshotFields::site_fields()
		);
	}

	public function test_seuls_les_administrateurs_sont_lus_du_plus_ancien_au_plus_recent_et_50_au_plus(): void {
		SnapshotFields::admins();

		self::assertSame(
			[
				'role'    => 'administrator',
				'number'  => 50,
				'orderby' => 'registered',
				'order'   => 'ASC',
				'fields'  => [ 'ID', 'user_login', 'user_email', 'user_registered' ],
			],
			$this->user_query
		);
	}

	public function test_la_case_decochee_est_remontee(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'allow_direct_login' => false ];

		self::assertFalse( SnapshotFields::site_fields()['direct_login_enabled'] );
	}

	public function test_une_ligne_illisible_est_ignoree(): void {
		$this->rows = [ 'pas un compte', (object) [ 'user_login' => 'sans-identifiant' ], $this->rows[0] ];

		self::assertSame( [ 1 ], array_column( SnapshotFields::admins(), 'id' ) );
	}

	public function test_un_administrateur_prive_de_la_capacite_n_est_pas_propose(): void {
		// Une extension a retiré manage_options au rôle : le compte serait refusé à
		// l'usage (« not_admin »), il n'est donc pas proposé au manager.
		$this->sans_capacite = [ 3 ];

		self::assertSame( [ 1 ], array_column( SnapshotFields::admins(), 'id' ) );
	}

	public function test_un_site_sans_administrateur_remonte_une_liste_vide(): void {
		$this->rows = [];

		self::assertSame( [], SnapshotFields::site_fields()['admins'] );
	}
}
