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
		// add_option() de WordPress refuse (false) une option qui existe déjà.
		Functions\when( 'add_option' )->alias(
			function ( string $key, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
				unset( $deprecated );
				if ( array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->options[ $key ]  = $value;
				$this->autoload[ $key ] = $autoload;
				return true;
			}
		);
		$GLOBALS['wpdb'] = new class( fn (): array => array_keys( $this->options ) ) {
			public string $options = 'wp_options';
			/** @var list<string> */
			public array $queries = [];

			public function __construct( private \Closure $option_names ) {}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				$this->queries[] = $query;
				return array_values(
					array_filter(
						( $this->option_names )(),
						static fn ( string $name ): bool => str_starts_with( $name, 'g2rd_login_used_' )
					)
				);
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_un_nonce_neuf_est_consomme_et_date(): void {
		self::assertTrue( UsedTickets::claim( self::NONCE, self::NOW ) );
		self::assertSame( self::NOW, $this->options[ 'g2rd_login_used_' . self::NONCE ] );
		self::assertFalse( $this->autoload[ 'g2rd_login_used_' . self::NONCE ], 'Jamais autochargée : elle ne sert qu\'au point d\'entrée.' );
	}

	public function test_un_nonce_deja_consomme_est_refuse(): void {
		UsedTickets::claim( self::NONCE, self::NOW );

		self::assertFalse( UsedTickets::claim( self::NONCE, self::NOW + 5 ) );
		self::assertSame( self::NOW, $this->options[ 'g2rd_login_used_' . self::NONCE ], 'La date du premier usage est conservée.' );
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
