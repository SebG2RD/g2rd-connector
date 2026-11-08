<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Content\BaselineReader;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Tests\TestCase;

final class IncrementalBaselineTest extends TestCase {

	private const T0       = 1792843200;
	private const COMPLETE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private string $dossier;

	protected function setUp(): void {
		parent::setUp();
		$this->dossier = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-base-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dossier );
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

	private function promouvoir(): void {
		$source = $this->dossier . '/nouveau.jsonl';
		file_put_contents(
			$source,
			IncrementalBaseline::line( [ 'path' => 'a.txt', 'type' => 'file', 'size' => 3, 'mtime' => self::T0 ], hash( 'sha256', 'abc' ) )
			. IncrementalBaseline::line( [ 'path' => "b/\xe9.txt", 'type' => 'file', 'size' => 1, 'mtime' => self::T0 ], null )
		);
		self::assertTrue( ( new IncrementalBaseline( $this->dossier ) )->promote( $source, self::COMPLETE, self::T0 ) );
	}

	public function test_promotion_puis_etat_utilisable_par_l_incrementale(): void {
		$this->promouvoir();
		$etat = ( new IncrementalBaseline( $this->dossier ) )->usable_for( self::COMPLETE, self::T0 + 86400 );

		self::assertNull( $etat['reason'] );
		self::assertIsString( $etat['file'] );
		$lecteur = new BaselineReader( (string) $etat['file'], 0 );
		self::assertSame( 'a.txt', $lecteur->peek()['path'] ?? null );
		self::assertSame( hash( 'sha256', 'abc' ), $lecteur->peek()['sha256'] ?? null );
		$lecteur->next();
		self::assertSame( "b/\xe9.txt", $lecteur->peek()['path'] ?? null, 'chemin non UTF-8 rendu à l\'identique' );
		$reprise = $lecteur->offset();
		$lecteur->next();
		self::assertNull( $lecteur->peek() );
		$lecteur->close();
		self::assertSame( "b/\xe9.txt", ( new BaselineReader( (string) $etat['file'], $reprise ) )->peek()['path'] ?? null, 'reprise au décalage' );
	}

	public function test_raisons_d_une_complete_a_la_place(): void {
		$base = new IncrementalBaseline( $this->dossier );
		self::assertSame( 'baseline_missing', $base->usable_for( self::COMPLETE, self::T0 )['reason'] );

		$this->promouvoir();
		self::assertSame( 'baseline_other_backup', $base->usable_for( str_repeat( 'b', 32 ), self::T0 )['reason'] );
		self::assertSame( 'baseline_too_old', $base->usable_for( self::COMPLETE, self::T0 + IncrementalBaseline::MAX_AGE_SECONDS + 1 )['reason'] );

		file_put_contents( $this->dossier . '/' . self::COMPLETE . '.jsonl', 'altéré', FILE_APPEND );
		self::assertSame( 'baseline_unreadable', $base->usable_for( self::COMPLETE, self::T0 )['reason'] );
	}

	public function test_une_nouvelle_complete_remplace_la_precedente(): void {
		$this->promouvoir();
		$source = $this->dossier . '/nouveau.jsonl';
		file_put_contents( $source, IncrementalBaseline::line( [ 'path' => 'c.txt', 'type' => 'file', 'size' => 0, 'mtime' => 1 ], null ) );
		( new IncrementalBaseline( $this->dossier ) )->promote( $source, str_repeat( 'c', 32 ), self::T0 + 10 );

		self::assertFileDoesNotExist( $this->dossier . '/' . self::COMPLETE . '.jsonl', 'état local périmé retiré (fichier du module)' );
		self::assertSame( str_repeat( 'c', 32 ), ( new IncrementalBaseline( $this->dossier ) )->current()['backup_id'] ?? null );
	}

	public function test_meme_fichier_par_nature_taille_et_date(): void {
		$base = [ 'path' => 'a', 'type' => 'file', 'size' => 3, 'mtime' => 5, 'sha256' => null ];
		self::assertTrue( IncrementalBaseline::same( [ 'path' => 'a', 'type' => 'file', 'size' => 3, 'mtime' => 5, 'mode' => 0644, 'target' => null ], $base ) );
		self::assertFalse( IncrementalBaseline::same( [ 'path' => 'a', 'type' => 'file', 'size' => 3, 'mtime' => 6, 'mode' => 0644, 'target' => null ], $base ) );
		self::assertFalse( IncrementalBaseline::same( [ 'path' => 'a', 'type' => 'link', 'size' => 0, 'mtime' => 5, 'mode' => 0777, 'target' => 'x' ], $base ) );
	}
}
