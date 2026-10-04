<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use Brain\Monkey\Functions;
use G2RD\Connector\DirectLogin\UsedTickets;
use G2RD\Connector\Tests\TestCase;

/**
 * Usage unique des tickets (spec §5.5, contrôle 4) et purge des entrées de plus
 * de 10 minutes par le cron horaire local.
 */
final class UsedTicketsTest extends TestCase {

	private const NOW   = 1789000000;
	private const NONCE = '000102030405060708090a0b0c0d0e0f';

	/** @var array<string, mixed> */
	private array $autoload = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new FakeWpdb(
			fn (): array => array_map( 'strval', array_keys( $this->options ) ),
			function ( string $name, string $value, string $autoload ): void {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_un_nonce_neuf_est_consomme_et_date(): void {
		self::assertTrue( UsedTickets::claim( self::NONCE, self::NOW ) );
		self::assertSame( (string) self::NOW, $this->options[ 'g2rd_login_used_' . self::NONCE ] );
		self::assertSame( 'no', $this->autoload[ 'g2rd_login_used_' . self::NONCE ], 'Jamais autochargée : elle ne sert qu\'au point d\'entrée.' );
	}

	public function test_un_nonce_deja_consomme_est_refuse(): void {
		UsedTickets::claim( self::NONCE, self::NOW );

		self::assertFalse( UsedTickets::claim( self::NONCE, self::NOW + 5 ) );
		self::assertSame( (string) self::NOW, $this->options[ 'g2rd_login_used_' . self::NONCE ], 'La date du premier usage est conservée.' );
	}

	/**
	 * Deux requêtes simultanées portant le même ticket, à cheval sur un changement
	 * de seconde : add_option() lisait (option absente pour les deux) puis écrivait
	 * avec « ON DUPLICATE KEY UPDATE » — la seconde mettait la ligne à jour avec une
	 * heure différente et obtenait vrai elle aussi, donc deux sessions pour un ticket.
	 * L'usage unique repose désormais sur la clé unique de la table : un seul
	 * INSERT, sans lecture préalable ni mise à jour d'une ligne existante.
	 */
	public function test_la_consommation_est_un_insert_atomique_sans_lecture_ni_mise_a_jour(): void {
		$lectures = 0;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$lectures ): bool {
				++$lectures;
				return false;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function (): bool {
				throw new \LogicException( 'add_option() n\'est pas atomique : il ne doit plus servir à consommer un ticket.' );
			}
		);

		self::assertTrue( UsedTickets::claim( self::NONCE, self::NOW ), 'La première requête obtient le ticket.' );
		self::assertFalse( UsedTickets::claim( self::NONCE, self::NOW + 1 ), 'La seconde, une seconde plus tard, est refusée.' );

		self::assertSame( 0, $lectures, 'Aucune lecture avant écriture : rien ne sépare le contrôle de l\'écriture.' );
		$requete = $GLOBALS['wpdb']->queries[0];
		self::assertStringStartsWith( 'INSERT IGNORE INTO wp_options (option_name, option_value, autoload)', $requete );
		self::assertStringNotContainsString( 'ON DUPLICATE KEY', $requete, 'Une ligne existante n\'est jamais mise à jour.' );
	}

	public function test_une_base_qui_refuse_l_ecriture_n_ouvre_pas_de_session(): void {
		$GLOBALS['wpdb']->fails = true;

		self::assertFalse( UsedTickets::claim( self::NONCE, self::NOW ), 'Dans le doute, le ticket est refusé.' );
	}

	public function test_la_purge_complete_retire_tous_les_tickets_lot_apres_lot(): void {
		for ( $i = 0; $i < 1203; $i++ ) {
			$this->options[ 'g2rd_login_used_' . sprintf( '%032x', $i ) ] = (string) self::NOW;
		}
		$this->options['g2rd_connector_settings'] = [ 'site_id' => 7 ];

		self::assertSame( 1203, UsedTickets::purge_all(), 'Trois lots : 500, 500, puis 203.' );
		self::assertSame( [ 'g2rd_connector_settings' ], array_keys( $this->options ), 'Seules les options des tickets sont retirées.' );
	}

	public function test_la_purge_ne_retire_que_les_entrees_de_plus_de_dix_minutes(): void {
		$this->options['g2rd_login_used_aaaa']    = self::NOW - 601;
		$this->options['g2rd_login_used_bbbb']    = self::NOW - 600;
		$this->options['g2rd_login_used_cccc']    = self::NOW;
		$this->options['g2rd_connector_settings'] = [ 'site_id' => 7 ];

		self::assertSame( 1, UsedTickets::purge( self::NOW ) );
		self::assertArrayNotHasKey( 'g2rd_login_used_aaaa', $this->options );
		self::assertArrayHasKey( 'g2rd_login_used_bbbb', $this->options );
		self::assertArrayHasKey( 'g2rd_login_used_cccc', $this->options );
		self::assertArrayHasKey( 'g2rd_connector_settings', $this->options, 'Seules les options des tickets sont concernées.' );
	}

	public function test_la_purge_cherche_le_prefixe_echappe(): void {
		UsedTickets::purge( self::NOW );

		self::assertStringContainsString( "LIKE 'g2rd\\_login\\_used\\_%'", $GLOBALS['wpdb']->queries[0] );
		self::assertStringContainsString( 'LIMIT 500', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_sans_ticket_consomme_la_purge_ne_fait_rien(): void {
		self::assertSame( 0, UsedTickets::purge( self::NOW ) );
	}
}
