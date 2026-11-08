<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Run\Finalizer;
use G2RD\Connector\Tests\TestCase;

final class FinalizerTest extends TestCase {

	/** @return array{public: string, secret: string} */
	private static function paire(): array {
		return ( new Sodium() )->ed25519_keypair_from_seed( str_repeat( "\x07", 32 ) );
	}

	/** @return array<string, mixed> */
	private static function champs( string $public ): array {
		return [
			'format'           => 'g2bk-index',
			'version'          => 1,
			'suite'            => 1,
			'backup_id'        => '8985695e536befd8b249c869f62ee42b',
			'site_ref'         => 'a5249f70814246f98358a6f092664dcb',
			'site_key_version' => 1,
			'signing_key_id'   => BackupIndex::signing_key_id( $public ),
			'created_at'       => '2026-10-30T01:00:00Z',
			'type'             => 'full',
			'parent_backup_id' => null,
			'payload_format'   => 1,
			'segments'         => [
				[ 'index' => 0, 'kind' => 'db', 'blocks' => 1, 'bytes' => 164, 'sha256' => str_repeat( 'a', 64 ) ],
				[ 'index' => 1, 'kind' => 'manifest', 'blocks' => 1, 'bytes' => 200, 'sha256' => str_repeat( 'b', 64 ) ],
			],
			'manifest_segment' => 1,
		];
	}

	public function test_index_signe_et_verifiable(): void {
		$p   = self::paire();
		$res = ( new Finalizer( new Sodium() ) )->finalize( self::champs( $p['public'] ), $p['secret'], $p['public'] );

		self::assertTrue( BackupIndex::verify( $res['index'], $res['signature'], $p['public'], new Sodium() ) );
		self::assertSame( 'db', BackupIndex::parse( $res['index'] )['segments'][0]['kind'] );
	}

	public function test_signature_fausse_du_moteur_aucun_index(): void {
		$p         = self::paire();
		$faussaire = static fn ( string $index, string $secret, Sodium $sodium ): string => substr_replace( BackupIndex::sign( $index, $secret, $sodium ), "\x00", 0, 1 );

		$this->expectException( BackupRefused::class );
		$this->expectExceptionMessage( 'Aucun index n’a été envoyé' );
		( new Finalizer( new Sodium(), null, $faussaire ) )->finalize( self::champs( $p['public'] ), $p['secret'], $p['public'] );
	}

	public function test_autotest_en_echec_juste_avant_la_signature(): void {
		$p = self::paire();

		try {
			( new Finalizer( new Sodium(), static fn (): array => [ 'ok' => false, 'failed' => [ 'ed25519' ], 'backend' => 'compat' ] ) )->finalize( self::champs( $p['public'] ), $p['secret'], $p['public'] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::SELF_TEST_FAILED, $refus->reason_code() );
		}
	}
}
