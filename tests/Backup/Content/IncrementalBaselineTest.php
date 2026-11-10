<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Content\BaselineReader;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Tests\Backup\Support\LiensSurDisque;
use G2RD\Connector\Tests\TestCase;

final class IncrementalBaselineTest extends TestCase {

	use LiensSurDisque;

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

	// ── Jamais à travers un lien ──────────────────────────────────────────────

	/**
	 * Le dossier de l'état local (`baseline/`) est un lien vers un dossier extérieur : la promotion
	 * est refusée sans rien déplacer ni écrire dans la cible, et l'état local n'y est jamais lu.
	 */
	public function test_promotion_refusee_si_le_dossier_de_l_etat_local_est_un_lien(): void {
		$dehors = $this->dossier . '-dehors';
		$lien   = $this->dossier . '-lien';
		$source = $this->dossier . '/nouveau.jsonl';
		file_put_contents( $source, IncrementalBaseline::line( [ 'path' => 'a.txt', 'type' => 'file', 'size' => 3, 'mtime' => self::T0 ], null ) );
		mkdir( $dehors );
		try {
			self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
			self::assertFalse( ( new IncrementalBaseline( $lien ) )->promote( $source, self::COMPLETE, self::T0 ) );
			self::assertSame( [], self::contenu( $dehors ), 'rien déplacé ni écrit dans la cible' );
			self::assertFileExists( $source, 'futur état local gardé à sa place' );
			self::assertSame( 'baseline_unreadable', ( new IncrementalBaseline( $lien ) )->usable_for( self::COMPLETE, self::T0 )['reason'], 'jamais lu à travers le lien' );
		} finally {
			self::retirer_lien( $lien );
			foreach ( (array) glob( $dehors . '/*' ) as $f ) {
				unlink( (string) $f );
			}
			rmdir( $dehors );
		}
	}

	/**
	 * `current.json` est un lien cassé vers un fichier extérieur : la promotion n'écrit jamais à
	 * travers lui (la cible n'est pas créée) ; elle est refusée, le lien reste.
	 */
	public function test_la_promotion_n_ecrit_jamais_a_travers_un_fichier_qui_est_un_lien(): void {
		$cible  = $this->dossier . '-cible.json';
		$lien   = $this->dossier . '/current.json';
		$source = $this->dossier . '/nouveau.jsonl';
		file_put_contents( $source, IncrementalBaseline::line( [ 'path' => 'a.txt', 'type' => 'file', 'size' => 3, 'mtime' => self::T0 ], null ) );
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			self::assertFalse( ( new IncrementalBaseline( $this->dossier ) )->promote( $source, self::COMPLETE, self::T0 ) );
			clearstatcache( true );
			self::assertFileDoesNotExist( $cible, 'rien écrit à travers le lien' );
			self::assertTrue( self::est_un_lien( $lien ), 'le lien reste' );
			self::assertFileExists( $source, 'futur état local gardé à sa place' );
		} finally {
			self::retirer_lien( $lien );
			@unlink( $cible ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** Un ancien état local qui est un lien n'est pas retiré par la promotion ; sa cible non plus. */
	public function test_un_ancien_etat_local_qui_est_un_lien_reste_avec_sa_cible(): void {
		$cible = $this->dossier . '-ancien.jsonl';
		$lien  = $this->dossier . '/' . str_repeat( 'b', 32 ) . '.jsonl';
		file_put_contents( $cible, 'à garder' );
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			$this->promouvoir();
			clearstatcache( true );
			self::assertTrue( self::est_un_lien( $lien ), 'le lien reste' );
			self::assertSame( 'à garder', file_get_contents( $cible ), 'sa cible aussi' );
		} finally {
			self::retirer_lien( $lien );
			unlink( $cible );
		}
	}

	/**
	 * `current.json` ou `<id>.jsonl` remplacé par un lien vers une copie extérieure identique :
	 * l'état local n'est jamais lu à travers lui (baseline_unreadable : l'incrémentale devient une
	 * complète, et le dit).
	 */
	public function test_un_etat_local_qui_est_un_lien_n_est_jamais_lu(): void {
		$this->promouvoir();
		$base = new IncrementalBaseline( $this->dossier );
		self::assertNull( $base->usable_for( self::COMPLETE, self::T0 )['reason'], 'état local utilisable' );

		foreach ( [ 'current.json', self::COMPLETE . '.jsonl' ] as $nom ) {
			$fichier = $this->dossier . '/' . $nom;
			$copie   = $this->dossier . '-' . $nom;
			rename( $fichier, $copie );
			try {
				self::lien_vers_un_fichier_ou_ignorer( $copie, $fichier );
				self::assertSame( 'baseline_unreadable', $base->usable_for( self::COMPLETE, self::T0 )['reason'], $nom . ' : jamais lu à travers un lien' );
				if ( 'current.json' === $nom ) {
					self::assertNull( $base->current(), 'description jamais lue à travers un lien' );
				}
			} finally {
				self::retirer_lien( $fichier );
				rename( $copie, $fichier );
			}
		}
		self::assertNull( $base->usable_for( self::COMPLETE, self::T0 )['reason'], 'liens retirés : état local de nouveau utilisable' );
	}
}
