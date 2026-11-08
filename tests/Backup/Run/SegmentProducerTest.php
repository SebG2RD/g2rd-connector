<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Backup\Format\SegmentReader;
use G2RD\Connector\Backup\Payload\PayloadReader;
use G2RD\Connector\Backup\Payload\PayloadWriter;
use G2RD\Connector\Backup\Run\SegmentProducer;
use G2RD\Connector\Tests\TestCase;

final class SegmentProducerTest extends TestCase {

	private const BACKUP = '8985695e536befd8b249c869f62ee42b';

	private string $dossier;

	private string $secrete;

	private string $publique;

	protected function setUp(): void {
		parent::setUp();
		$this->dossier  = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-prod-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dossier );
		$this->secrete  = random_bytes( 32 );
		$this->publique = ( new Sodium() )->x25519_base( $this->secrete );
		Functions\when( 'wp_delete_file' )->alias( static function ( string $f ): void {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		} );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dossier . '/*' ) as $f ) {
			unlink( (string) $f );
		}
		rmdir( $this->dossier );
		parent::tearDown();
	}

	private function producteur( ?\Closure $autotest = null ): SegmentProducer {
		$sodium = new Sodium();
		return new SegmentProducer( new Hpke( $sodium ), $sodium, $this->dossier, self::BACKUP, 1, $this->publique, 65536, $autotest );
	}

	public function test_le_segment_se_dechiffre_et_porte_sa_charge_utile(): void {
		$segment = $this->producteur()->produce(
			3,
			SegmentHeader::KIND_FILES,
			static function ( PayloadWriter $w ): void {
				$w->file_chunk( 'a.txt', 0, 7, 1, 0644, 'bonjour', true );
			}
		);

		self::assertSame( 3, $segment['segment'] );
		self::assertSame( 'files', $segment['kind'] );
		self::assertFileExists( $segment['file'] );
		self::assertStringEndsWith( '/seg-3.seg', $segment['file'] );
		self::assertSame( hash_file( 'sha256', $segment['file'] ), $segment['sha256'] );
		self::assertSame( filesize( $segment['file'] ), $segment['bytes'] );
		self::assertSame( 296, strlen( $segment['header'] ) );

		$clair = '';
		$in    = fopen( $segment['file'], 'rb' );
		( new SegmentReader( new Hpke( new Sodium() ) ) )->decrypt( $in, $this->secrete, static function ( string $b ) use ( &$clair ): void {
			$clair .= $b;
		} );
		fclose( $in );
		self::assertSame( 'bonjour', PayloadReader::parse( $clair )[0]['data'] );
	}

	public function test_deux_segments_deux_cles_de_donnees(): void {
		$p = $this->producteur();
		$a = $p->produce( 0, SegmentHeader::KIND_DB, static fn ( PayloadWriter $w ): int => $w->sql( 0, 'x' ) );
		$b = $p->produce( 1, SegmentHeader::KIND_DB, static fn ( PayloadWriter $w ): int => $w->sql( 1, 'x' ) );

		self::assertNotSame( substr( $a['header'], 72, 64 ), substr( $b['header'], 72, 64 ), 'clé éphémère HPKE différente' );
	}

	public function test_un_echec_pendant_le_remplissage_jette_le_segment(): void {
		try {
			$this->producteur()->produce( 0, SegmentHeader::KIND_FILES, static function (): void {
				throw new BackupRefused( BackupRefused::DB_READ_FAILED, null, 'wp_posts' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			} );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		}
		self::assertSame( [], (array) glob( $this->dossier . '/seg-0.*' ), 'rien ne reste' );
	}

	public function test_autotest_en_echec_apres_le_segment_arrete_et_jette(): void {
		$p = $this->producteur(
			static fn (): array => [
				'ok'      => false,
				'failed'  => [ 'ed25519' ],
				'backend' => 'compat',
			]
		);

		try {
			$p->produce( 0, SegmentHeader::KIND_FILES, static fn ( PayloadWriter $w ): int => $w->deleted( 'a' ) );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::SELF_TEST_FAILED, $refus->reason_code() );
		}
		self::assertSame( [], (array) glob( $this->dossier . '/seg-0.*' ) );
	}
}
