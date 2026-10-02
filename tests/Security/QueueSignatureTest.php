<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\QueueSignature;
use G2RD\Connector\Security\RequestSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Signature des entrées de la FILE des commandes (cron). Classe pure : pas de
 * Brain Monkey ici.
 */
final class QueueSignatureTest extends TestCase {

	private const TOKEN   = 'test-site-token-0123456789abcdef';
	private const SITE_ID = 7;
	private const CMD_ID  = 42;
	private const NONCE   = '000102030405060708090a0b0c0d0e0f';
	private const BODY    = '{"command":"set_signature_policy","payload":{"policy":"required"}}';
	private const NOW     = 1789000000;

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function queue_vectors(): iterable {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/queue-signature-vectors.json' ), true, 16, JSON_THROW_ON_ERROR );
		foreach ( $json['vectors'] as $vector ) {
			yield $vector['name'] => [ $vector ];
		}
	}

	/**
	 * Vecteurs calculés par une implémentation indépendante (Python) : la route et
	 * la méthode de la file produisent exactement la même signature que la
	 * spécification v1, et une entrée ainsi signée est acceptée.
	 *
	 * @param array<string, mixed> $vector
	 */
	#[DataProvider( 'queue_vectors' )]
	public function test_matches_independent_vectors( array $vector ): void {
		self::assertSame( $vector['route'], QueueSignature::route( (int) $vector['site_id'], (int) $vector['command_id'] ) );
		self::assertSame( $vector['body_sha256'], hash( 'sha256', (string) $vector['body'] ) );
		self::assertSame(
			$vector['signature'],
			'v1=' . RequestSignature::sign( (string) $vector['site_token'], QueueSignature::METHOD, (string) $vector['route'], (string) $vector['timestamp'], (string) $vector['nonce'], (string) $vector['body'] )
		);

		$entry = [
			'id'     => $vector['command_id'],
			'signed' => [
				'body'      => $vector['body'],
				'timestamp' => $vector['timestamp'],
				'nonce'     => $vector['nonce'],
				'signature' => $vector['signature'],
			],
		];
		$check = QueueSignature::verify_entry( (string) $vector['site_token'], (int) $vector['site_id'], $entry, (int) $vector['timestamp'] );

		self::assertSame( 'ok', $check['status'] );
		$body = json_decode( (string) $vector['body'], true );
		self::assertSame( $body['command'], $check['command'] );
		self::assertSame( $body['payload'] ?? null, $check['payload'] );
		self::assertSame( $vector['nonce'], $check['nonce'] );
	}

	public function test_valid_entry_is_ok(): void {
		$check = QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $this->entry(), self::NOW );

		self::assertSame( 'ok', $check['status'] );
		self::assertSame( 'set_signature_policy', $check['command'] );
		self::assertSame( [ 'policy' => 'required' ], $check['payload'] );
	}

	public function test_entry_without_signed_is_absent(): void {
		$check = QueueSignature::verify_entry(
			self::TOKEN,
			self::SITE_ID,
			[
				'id'   => self::CMD_ID,
				'kind' => 'set_signature_policy',
			],
			self::NOW
		);
		self::assertSame( [ 'status' => 'absent' ], $check );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function malformed_envelopes(): iterable {
		yield 'pas un tableau' => [ 'v1=abc' ];
		yield 'tableau vide' => [ [] ];
		yield 'signature manquante' => [
			[
				'body'      => self::BODY,
				'timestamp' => (string) self::NOW,
				'nonce'     => self::NONCE,
			],
		];
		yield 'champs vides' => [
			[
				'body'      => '',
				'timestamp' => '',
				'nonce'     => '',
				'signature' => '',
			],
		];
		yield 'horodatage numérique au lieu de chaîne' => [
			[
				'body'      => self::BODY,
				'timestamp' => self::NOW,
				'nonce'     => self::NONCE,
				'signature' => 'v1=' . str_repeat( 'a', 64 ),
			],
		];
	}

	#[DataProvider( 'malformed_envelopes' )]
	public function test_partial_or_malformed_envelope_is_invalid( mixed $signed ): void {
		$check = QueueSignature::verify_entry(
			self::TOKEN,
			self::SITE_ID,
			[
				'id'     => self::CMD_ID,
				'signed' => $signed,
			],
			self::NOW
		);
		self::assertSame( 'failed', $check['status'] );
		self::assertSame( 'signature_invalid', $check['code'] );
	}

	public function test_entry_without_id_is_invalid(): void {
		$entry = $this->entry();
		unset( $entry['id'] );
		self::assertSame( 'signature_invalid', QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $entry, self::NOW )['code'] );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function bad_bodies(): iterable {
		yield 'pas du JSON' => [ 'ceci n\'est pas du JSON' ];
		yield 'JSON scalaire' => [ '"set_signature_policy"' ];
		yield 'commande absente' => [ '{"payload":{"policy":"required"}}' ];
		yield 'commande hors liste' => [ '{"command":"drop_database"}' ];
		yield 'commande non textuelle' => [ '{"command":["rollback_plugin"]}' ];
		yield 'payload non tableau' => [ '{"command":"set_signature_policy","payload":"required"}' ];
	}

	/**
	 * Une signature VALIDE sur un corps inexploitable reste un refus : le connecteur
	 * n'exécute que ce qu'il comprend entièrement.
	 */
	#[DataProvider( 'bad_bodies' )]
	public function test_signed_but_unusable_body_is_invalid( string $body ): void {
		$check = QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $this->entry( $body ), self::NOW );
		self::assertSame( 'failed', $check['status'] );
		self::assertSame( 'signature_invalid', $check['code'] );
	}

	public function test_payload_may_be_absent_or_null(): void {
		$check = QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $this->entry( '{"command":"delete_restore_point","payload":null}' ), self::NOW );
		self::assertSame( 'ok', $check['status'] );
		self::assertNull( $check['payload'] );
	}

	public function test_top_level_kind_must_match_signed_command(): void {
		$entry         = $this->entry();
		$entry['kind'] = 'clear_cache';
		self::assertSame( 'signature_invalid', QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $entry, self::NOW )['code'] );

		$entry['kind'] = 'set_signature_policy';
		self::assertSame( 'ok', QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $entry, self::NOW )['status'] );
	}

	/**
	 * Séparation des domaines : une signature de la route REST (méthode POST, route
	 * `/g2rd/v1/command`) ne vaut rien pour la file, même corps, même jeton.
	 *
	 * @return iterable<string, array{string, string}>
	 */
	public static function foreign_domains(): iterable {
		yield 'méthode POST' => [ 'POST', '/api/agent/sites/7/commands/42' ];
		yield 'route REST' => [ 'PULL', '/g2rd/v1/command' ];
		yield 'requête REST complète' => [ 'POST', '/g2rd/v1/command' ];
		yield 'autre commande' => [ 'PULL', '/api/agent/sites/7/commands/41' ];
		yield 'autre site' => [ 'PULL', '/api/agent/sites/8/commands/42' ];
	}

	#[DataProvider( 'foreign_domains' )]
	public function test_signature_from_another_domain_is_invalid( string $method, string $route ): void {
		$entry                        = $this->entry();
		$entry['signed']['signature'] = 'v1=' . RequestSignature::sign( self::TOKEN, $method, $route, (string) self::NOW, self::NONCE, self::BODY );

		self::assertSame( 'signature_invalid', QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $entry, self::NOW )['code'] );
	}

	public function test_other_token_is_invalid(): void {
		self::assertSame( 'signature_invalid', QueueSignature::verify_entry( 'un-autre-jeton', self::SITE_ID, $this->entry(), self::NOW )['code'] );
	}

	/**
	 * Constat de relecture : un jeton illisible ou refusé vaut '' et la clé dérivée de
	 * '' est publique. Une entrée `rollback_plugin` signée avec cette clé était jugée
	 * `ok` ; elle doit être refusée, quel que soit son contenu.
	 */
	public function test_empty_token_never_validates_an_entry(): void {
		$body  = '{"command":"rollback_plugin","payload":{"file":"x/x.php","expected_version":"1.0.0"}}';
		$route = '/api/agent/sites/' . self::SITE_ID . '/commands/' . self::CMD_ID;
		$entry = [
			'id'     => self::CMD_ID,
			'signed' => [
				'body'      => $body,
				'timestamp' => (string) self::NOW,
				'nonce'     => self::NONCE,
				'signature' => 'v1=' . RequestSignature::sign( '', 'PULL', $route, (string) self::NOW, self::NONCE, $body ),
			],
		];

		$check = QueueSignature::verify_entry( '', self::SITE_ID, $entry, self::NOW );

		self::assertSame(
			[
				'status' => 'failed',
				'code'   => 'token_unavailable',
			],
			$check
		);
	}

	/** Une entrée sans enveloppe reste « absente » : le chemin des commandes historiques ne change pas. */
	public function test_empty_token_keeps_absent_for_unsigned_entries(): void {
		self::assertSame( [ 'status' => 'absent' ], QueueSignature::verify_entry( '', self::SITE_ID, [ 'id' => 1 ], self::NOW ) );
	}

	public function test_out_of_window_is_clock_skew_with_server_time(): void {
		$check = QueueSignature::verify_entry( self::TOKEN, self::SITE_ID, $this->entry(), self::NOW + 301 );
		self::assertSame( 'failed', $check['status'] );
		self::assertSame( 'clock_skew', $check['code'] );
		self::assertSame( self::NOW + 301, $check['server_time'] );
	}

	public function test_claimed_command_reads_the_body_without_trusting_it(): void {
		$entry                        = $this->entry();
		$entry['signed']['signature'] = 'v1=' . str_repeat( '0', 64 );
		self::assertSame( 'set_signature_policy', QueueSignature::claimed_command( $entry ) );
		self::assertSame( '', QueueSignature::claimed_command( [ 'id' => 1 ] ) );
		self::assertSame( '', QueueSignature::claimed_command( [ 'signed' => [ 'body' => 'pas du JSON' ] ] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function entry( string $body = self::BODY ): array {
		$route = '/api/agent/sites/' . self::SITE_ID . '/commands/' . self::CMD_ID;
		return [
			'id'     => self::CMD_ID,
			'signed' => [
				'body'      => $body,
				'timestamp' => (string) self::NOW,
				'nonce'     => self::NONCE,
				'signature' => 'v1=' . RequestSignature::sign( self::TOKEN, 'PULL', $route, (string) self::NOW, self::NONCE, $body ),
			],
		];
	}
}
