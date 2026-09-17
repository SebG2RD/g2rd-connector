<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\RequestSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 */
final class RequestSignatureTest extends TestCase {

	private const TOKEN = 'test-site-token-0123456789abcdef';
	private const ROUTE = '/g2rd/v1/command';
	private const NONCE = '000102030405060708090a0b0c0d0e0f';
	private const BODY  = '{"command":"clear_cache"}';
	private const NOW   = 1789000000;

	/**
	 * @return iterable<string, array{array<string, string>}>
	 */
	public static function shared_vectors(): iterable {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/signature-vectors.json' ), true, 16, JSON_THROW_ON_ERROR );
		foreach ( $json['vectors'] as $vector ) {
			yield $vector['name'] => [ $vector ];
		}
	}

	/**
	 * Les vecteurs ont été calculés par une implémentation indépendante (.NET) :
	 * ce test prouve que l'implémentation PHP produit exactement la même chose,
	 * et le manager rejoue le même fichier.
	 *
	 * @param array<string, string> $vector
	 */
	#[DataProvider( 'shared_vectors' )]
	public function test_matches_shared_vectors( array $vector ): void {
		self::assertSame( $vector['body_sha256'], hash( 'sha256', $vector['body'] ) );
		self::assertSame(
			$vector['signature'],
			RequestSignature::sign( $vector['site_token'], $vector['method'], $vector['route'], $vector['timestamp'], $vector['nonce'], $vector['body'] )
		);
	}

	public function test_valid_signature_is_ok(): void {
		$result = $this->verify( $this->signature() );
		self::assertSame( [ 'status' => 'ok' ], $result );
	}

	public function test_method_case_does_not_matter(): void {
		$signature = 'v1=' . RequestSignature::sign( self::TOKEN, 'post', self::ROUTE, (string) self::NOW, self::NONCE, self::BODY );
		self::assertSame( 'ok', $this->verify( $signature )['status'] );
	}

	public function test_no_headers_is_absent(): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, self::BODY, '', '', '', self::NOW );
		self::assertSame( [ 'status' => 'absent' ], $result );
	}

	public function test_partial_headers_are_invalid(): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, self::BODY, (string) self::NOW, '', '', self::NOW );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'signature_invalid', $result['code'] );
	}

	public function test_tampered_body_is_invalid(): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, '{"command":"update_core"}', (string) self::NOW, self::NONCE, $this->signature(), self::NOW );
		self::assertSame( 'signature_invalid', $result['code'] );
	}

	public function test_other_route_is_invalid(): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', '/g2rd/v1/snapshot', self::BODY, (string) self::NOW, self::NONCE, $this->signature(), self::NOW );
		self::assertSame( 'signature_invalid', $result['code'] );
	}

	public function test_other_token_is_invalid(): void {
		$result = RequestSignature::verify( 'un-autre-jeton', 'POST', self::ROUTE, self::BODY, (string) self::NOW, self::NONCE, $this->signature(), self::NOW );
		self::assertSame( 'signature_invalid', $result['code'] );
	}

	/**
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function malformed_headers(): iterable {
		yield 'timestamp non numérique' => [ 'demain', self::NONCE, 'v1=' . str_repeat( 'a', 64 ) ];
		yield 'nonce trop court' => [ (string) self::NOW, 'abcd', 'v1=' . str_repeat( 'a', 64 ) ];
		yield 'nonce en majuscules' => [ (string) self::NOW, strtoupper( self::NONCE ), 'v1=' . str_repeat( 'a', 64 ) ];
		yield 'signature sans préfixe' => [ (string) self::NOW, self::NONCE, str_repeat( 'a', 64 ) ];
		yield 'version inconnue' => [ (string) self::NOW, self::NONCE, 'v2=' . str_repeat( 'a', 64 ) ];
	}

	#[DataProvider( 'malformed_headers' )]
	public function test_malformed_headers_are_invalid( string $timestamp, string $nonce, string $signature ): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, self::BODY, $timestamp, $nonce, $signature, self::NOW );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 'signature_invalid', $result['code'] );
	}

	public function test_window_boundaries(): void {
		self::assertSame( 'ok', $this->verify( $this->signature(), self::NOW + 300 )['status'] );
		self::assertSame( 'ok', $this->verify( $this->signature(), self::NOW - 300 )['status'] );
	}

	public function test_clock_skew_reports_server_time(): void {
		$result = $this->verify( $this->signature(), self::NOW + 301 );
		self::assertSame(
			[
				'status'      => 'failed',
				'code'        => 'clock_skew',
				'server_time' => self::NOW + 301,
			],
			$result
		);
	}

	/**
	 * L'heure du serveur n'est révélée qu'à un appelant qui détient la clé.
	 */
	public function test_skew_with_bad_signature_does_not_leak_server_time(): void {
		$result = RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, self::BODY, (string) ( self::NOW - 9999 ), self::NONCE, 'v1=' . str_repeat( '0', 64 ), self::NOW );
		self::assertSame( 'signature_invalid', $result['code'] );
		self::assertArrayNotHasKey( 'server_time', $result );
	}

	private function signature(): string {
		return 'v1=' . RequestSignature::sign( self::TOKEN, 'POST', self::ROUTE, (string) self::NOW, self::NONCE, self::BODY );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function verify( string $signature, int $now = self::NOW ): array {
		return RequestSignature::verify( self::TOKEN, 'POST', self::ROUTE, self::BODY, (string) self::NOW, self::NONCE, $signature, $now );
	}
}
