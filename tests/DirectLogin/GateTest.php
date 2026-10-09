<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use Brain\Monkey\Functions;
use G2RD\Connector\DirectLogin\Gate;
use G2RD\Connector\DirectLogin\Refusal;
use G2RD\Connector\DirectLogin\UsedTickets;
use G2RD\Connector\Security\DirectLoginTicket;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Les six contrôles de la spec (§5.5), dans l'ordre : forme et signature, site,
 * expiration, usage unique, case du réglage, compte administrateur.
 */
final class GateTest extends TestCase {

	private const NOW = 1789000000;

	/** @var array<int, \WP_User> */
	private array $users = [];

	private bool $is_admin = true;

	protected function setUp(): void {
		parent::setUp();
		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = [
			'site_id'    => TicketFactory::SITE_ID,
			'site_token' => TicketFactory::TOKEN,
		];

		$admin             = new \WP_User();
		$admin->ID         = 1;
		$admin->user_login = 'admin-g2rd';
		$this->users[1]    = $admin;

		// Usage unique : UsedTickets écrit le nonce par un INSERT atomique ($wpdb).
		$GLOBALS['wpdb'] = new FakeWpdb(
			fn (): array => array_map( 'strval', array_keys( $this->options ) ),
			function ( string $name, string $value ): void {
				$this->options[ $name ] = $value;
			}
		);
		Functions\when( 'get_userdata' )->alias( fn ( int $id ) => $this->users[ $id ] ?? false );
		Functions\when( 'user_can' )->alias( fn ( $user, string $capability ): bool => 'manage_options' === $capability && $this->is_admin );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_un_ticket_valide_ouvre_le_compte_demande(): void {
		$decision = ( new Gate() )->check( TicketFactory::make( self::NOW + 60 ), self::NOW );

		self::assertSame( 'ok', $decision['status'] );
		self::assertSame( 1, $decision['user']->ID );
		self::assertSame( 7, $decision['payload']['a'], 'L\'utilisateur du manager part dans l\'événement du site.' );
		self::assertSame( (string) self::NOW, $this->options[ UsedTickets::OPTION_PREFIX . TicketFactory::NONCE ] );
	}

	public function test_un_ticket_mal_forme_n_est_pas_valide(): void {
		self::assertSame( Refusal::INVALID, $this->refusal( 'v2.abc.def' ) );
	}

	public function test_un_ticket_signe_avec_un_autre_jeton_n_est_pas_valide(): void {
		self::assertSame( Refusal::INVALID, $this->refusal( TicketFactory::make( self::NOW + 60, [], 'ancien-jeton' ) ) );
	}

	public function test_un_ticket_d_un_autre_site_est_refuse(): void {
		self::assertSame( Refusal::WRONG_SITE, $this->refusal( TicketFactory::make( self::NOW + 60, [ 's' => 43 ] ) ) );
	}

	public function test_l_expiration_tolere_trente_secondes_d_ecart_d_horloge(): void {
		self::assertSame( 'ok', ( new Gate() )->check( TicketFactory::make( self::NOW - 30 ), self::NOW )['status'] );
		self::assertSame( Refusal::EXPIRED, $this->refusal( TicketFactory::make( self::NOW - 31, [ 'n' => str_repeat( 'b', 32 ) ] ) ) );
	}

	public function test_un_ticket_a_plus_de_quatre_vingt_dix_secondes_dans_le_futur_est_refuse(): void {
		self::assertSame( 'ok', ( new Gate() )->check( TicketFactory::make( self::NOW + 90 ), self::NOW )['status'] );
		self::assertSame( Refusal::FUTURE, $this->refusal( TicketFactory::make( self::NOW + 91, [ 'n' => str_repeat( 'c', 32 ) ] ) ) );
	}

	public function test_un_ticket_rejoue_est_refuse(): void {
		$ticket = TicketFactory::make( self::NOW + 60 );
		( new Gate() )->check( $ticket, self::NOW );

		self::assertSame( Refusal::REPLAYED, $this->refusal( $ticket ) );
	}

	public function test_un_ticket_refuse_avant_l_usage_unique_ne_consomme_pas_son_nonce(): void {
		$this->refusal( TicketFactory::make( self::NOW - 31 ) );
		$this->refusal( TicketFactory::make( self::NOW + 60, [], 'ancien-jeton' ) );

		self::assertArrayNotHasKey( UsedTickets::OPTION_PREFIX . TicketFactory::NONCE, $this->options );
	}

	public function test_la_case_decochee_refuse_la_connexion(): void {
		$this->options[ Settings::OPTION_KEY ]['allow_direct_login'] = false;

		self::assertSame( Refusal::DISABLED, $this->refusal( TicketFactory::make( self::NOW + 60 ) ) );
		self::assertArrayHasKey( UsedTickets::OPTION_PREFIX . TicketFactory::NONCE, $this->options, 'Ordre de la spec : l\'usage unique (4) précède la case (5).' );
	}

	public function test_un_compte_retrograde_est_refuse(): void {
		$this->is_admin = false;

		self::assertSame( Refusal::NOT_ADMIN, $this->refusal( TicketFactory::make( self::NOW + 60 ) ) );
	}

	public function test_un_compte_supprime_est_refuse(): void {
		self::assertSame( Refusal::NOT_ADMIN, $this->refusal( TicketFactory::make( self::NOW + 60, [ 'u' => 99 ] ) ) );
	}

	/**
	 * La purge des tickets consommés (cron local) n'est qu'un ménage : un ticket est
	 * refusé s'il a déjà servi tant que son nonce est stocké, et refusé comme expiré
	 * dès que sa fenêtre est passée, que la purge ait eu lieu ou non. C'est ce qui
	 * permet d'espacer la purge (deux fois par jour au lieu de toutes les heures)
	 * sans rien changer à la sécurité.
	 */
	public function test_la_validation_ne_depend_pas_de_la_purge_des_tickets_consommes(): void {
		$ticket = TicketFactory::make( self::NOW + 60 );
		$nonce  = UsedTickets::OPTION_PREFIX . TicketFactory::NONCE;
		self::assertSame( 'ok', ( new Gate() )->check( $ticket, self::NOW )['status'] );

		// Encore dans sa fenêtre, nonce stocké : déjà utilisé.
		self::assertSame( Refusal::REPLAYED, $this->refusal( $ticket ) );

		// Fenêtre passée, purge pas encore faite (nonce toujours stocké) : expiré.
		$later = self::NOW + UsedTickets::RETENTION_SECONDS + 1;
		self::assertArrayHasKey( $nonce, $this->options );
		self::assertSame( Refusal::EXPIRED, ( new Gate() )->check( $ticket, $later )['code'] ?? null );

		// Purge faite (nonce retiré) : toujours expiré, et le nonce n'est pas reconsommé.
		self::assertSame( 1, UsedTickets::purge( $later ) );
		self::assertArrayNotHasKey( $nonce, $this->options );
		self::assertSame( Refusal::EXPIRED, ( new Gate() )->check( $ticket, $later )['code'] ?? null );
		self::assertArrayNotHasKey( $nonce, $this->options );
	}

	/**
	 * Un ticket accepté à l'instant t expire au plus tard à t + 90 s (avance maximale)
	 * et passe le contrôle d'expiration jusqu'à t + 120 s (tolérance d'horloge). La
	 * purge ne retire qu'un nonce consommé depuis plus de RETENTION_SECONDS : jamais
	 * celui d'un ticket qui pourrait encore passer, quelle que soit sa fréquence.
	 */
	public function test_un_nonce_n_est_jamais_purge_tant_que_son_ticket_peut_encore_passer(): void {
		self::assertGreaterThan(
			DirectLoginTicket::MAX_FUTURE_SECONDS + DirectLoginTicket::CLOCK_TOLERANCE_SECONDS,
			UsedTickets::RETENTION_SECONDS
		);
	}

	private function refusal( string $ticket ): string {
		$decision = ( new Gate() )->check( $ticket, self::NOW );
		self::assertSame( 'refused', $decision['status'] );
		self::assertArrayNotHasKey( 'user', $decision );

		return (string) $decision['code'];
	}
}
