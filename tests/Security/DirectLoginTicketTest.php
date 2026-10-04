<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\DirectLoginTicket;
use G2RD\Connector\Security\RequestSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Les vecteurs sont produits par le manager (DirectLoginTicketSigner, plan partie B,
 * tâche 9), dont les signatures ont été recalculées par une implémentation .NET
 * indépendante. Les rejouer ici prouve que les deux dépôts parlent le même ticket.
 */
final class DirectLoginTicketTest extends TestCase {

	private const VECTORS = __DIR__ . '/../fixtures/direct-login-ticket-vectors.json';

	/** Empreinte du fichier du manager : une copie retouchée ne doit jamais passer. */
	private const VECTORS_SHA256 = '58842421f4d5141199e488cec5001bb09ab553f52620722cf4cd0a456a70776c';

	private const TOKEN = 'test-site-token-0123456789abcdef';
	private const NOW   = 1789000000;

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function vectors(): array {
		/** @var list<array<string, mixed>> $vectors */
		$vectors = json_decode( (string) file_get_contents( self::VECTORS ), true, 16, JSON_THROW_ON_ERROR );
		return $vectors;
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function shared_vectors(): iterable {
		foreach ( self::vectors() as $vector ) {
			yield (string) $vector['name'] => [ $vector ];
		}
	}

	/**
	 * @param array<string, mixed> $vector
	 */
	#[DataProvider( 'shared_vectors' )]
	public function test_chaque_vecteur_du_manager_donne_le_verdict_annonce( array $vector ): void {
		$result = DirectLoginTicket::verify( (string) $vector['ticket'], (string) $vector['site_token'], (int) $vector['site_id'], (int) $vector['now'] );

		self::assertSame( $vector['expected'], $result['status'] );
		if ( 'ok' === $vector['expected'] ) {
			self::assertSame( $vector['payload'], $result['payload'] ?? null );
		} else {
			self::assertArrayNotHasKey( 'payload', $result, 'Une charge n\'est rendue que pour un ticket accepté.' );
		}
	}

	public function test_le_fichier_de_vecteurs_est_la_copie_exacte_de_celui_du_manager(): void {
		self::assertSame( self::VECTORS_SHA256, hash_file( 'sha256', self::VECTORS ) );
	}

	public function test_les_vecteurs_couvrent_chaque_verdict(): void {
		$outcomes = array_values( array_unique( array_column( self::vectors(), 'expected' ) ) );
		sort( $outcomes );

		self::assertSame( [ 'bad_signature', 'expired', 'future', 'malformed', 'ok', 'wrong_site' ], $outcomes );
	}

	public function test_la_signature_calculee_est_celle_du_manager(): void {
		$vector              = self::vectors()[0];
		[ , $charge, $sig ] = explode( '.', (string) $vector['ticket'] );

		self::assertSame( $sig, DirectLoginTicket::signature( (string) $vector['site_token'], $charge ) );
	}

	/** Contexte distinct : une signature de commande (g2rd-sign-v1) ne vaut jamais ticket. */
	public function test_une_signature_de_commande_ne_vaut_pas_ticket(): void {
		$charge = self::charge( '{"s":42,"u":1,"e":1789000060,"n":"000102030405060708090a0b0c0d0e0f","a":7}' );
		$sig    = self::base64url( hash_hmac( 'sha256', "v1\n" . $charge, RequestSignature::derive_key( self::TOKEN ), true ) );

		self::assertSame( 'bad_signature', DirectLoginTicket::verify( 'v1.' . $charge . '.' . $sig, self::TOKEN, 42, self::NOW )['status'] );
	}

	/** Sans jeton (site non enrôlé), n'importe qui saurait signer avec une clé vide. */
	public function test_un_site_sans_jeton_refuse_meme_un_ticket_signe_avec_une_cle_vide(): void {
		$charge = self::charge( '{"s":42,"u":1,"e":1789000060,"n":"000102030405060708090a0b0c0d0e0f","a":7}' );
		$ticket = 'v1.' . $charge . '.' . DirectLoginTicket::signature( '', $charge );

		self::assertSame( 'bad_signature', DirectLoginTicket::verify( $ticket, '', 42, self::NOW )['status'] );
	}

	public function test_un_ticket_demesurement_long_est_refuse_sans_calcul(): void {
		self::assertSame( 'malformed', DirectLoginTicket::verify( 'v1.' . str_repeat( 'a', 1100 ) . '.b', self::TOKEN, 42, self::NOW )['status'] );
	}

	public function test_les_bornes_d_horloge_sont_celles_de_la_spec(): void {
		self::assertSame( 30, DirectLoginTicket::CLOCK_TOLERANCE_SECONDS );
		self::assertSame( 90, DirectLoginTicket::MAX_FUTURE_SECONDS );
	}

	private static function charge( string $json ): string {
		return self::base64url( $json );
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
