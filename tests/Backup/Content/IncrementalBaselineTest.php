<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Content;

use G2RD\Connector\Backup\Content\BaselineReader;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Tests\Backup\Support\DossierDeTravailTemporaire;
use G2RD\Connector\Tests\Backup\Support\LiensSurDisque;
use G2RD\Connector\Tests\TestCase;

/**
 * État local des incrémentales. Il vit dans `baseline/` du dossier privé des sauvegardes : il n'est
 * lu et écrit que là, jamais à travers un lien (Run\WorkDir::open_private(), replace_private()).
 */
final class IncrementalBaselineTest extends TestCase {

	use DossierDeTravailTemporaire;
	use LiensSurDisque;

	private const T0       = 1792843200;
	private const COMPLETE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private string $dossier;

	/** Dossier extérieur au dossier privé (sous le dossier de travail), visé par les liens. */
	private string $dehors;

	protected function setUp(): void {
		parent::setUp();
		$this->dossier = $this->preparer_dossier_de_travail() . '/baseline';
		$this->dehors  = $this->racine_de_travail . '/dehors';
		mkdir( $this->dehors );
	}

	protected function tearDown(): void {
		$this->retirer_dossier_de_travail();
		parent::tearDown();
	}

	/**
	 * État local ouvert sans lien et placé au décalage $offset, comme BackupJob le donne au lecteur.
	 *
	 * @return resource
	 */
	private static function ouvrir( string $fichier, int $offset ) {
		$handle = WorkDir::open_private( $fichier, 'rb' );
		self::assertIsResource( $handle );
		self::assertSame( 0, fseek( $handle, $offset ) );
		return $handle;
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
		$lecteur = new BaselineReader( self::ouvrir( (string) $etat['file'], 0 ) );
		self::assertSame( 'a.txt', $lecteur->peek()['path'] ?? null );
		self::assertSame( hash( 'sha256', 'abc' ), $lecteur->peek()['sha256'] ?? null );
		$lecteur->next();
		self::assertSame( "b/\xe9.txt", $lecteur->peek()['path'] ?? null, 'chemin non UTF-8 rendu à l\'identique' );
		$reprise = $lecteur->offset();
		$lecteur->next();
		self::assertNull( $lecteur->peek() );
		$lecteur->close();
		self::assertSame( "b/\xe9.txt", ( new BaselineReader( self::ouvrir( (string) $etat['file'], $reprise ) ) )->peek()['path'] ?? null, 'reprise au décalage' );
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
		$job = dirname( $this->dossier ) . '/job-' . self::COMPLETE;
		mkdir( $job );
		$source = $job . '/baseline-new.jsonl';
		file_put_contents( $source, IncrementalBaseline::line( [ 'path' => 'a.txt', 'type' => 'file', 'size' => 3, 'mtime' => self::T0 ], null ) );
		rmdir( $this->dossier );
		try {
			self::lien_vers_un_dossier_ou_ignorer( $this->dehors, $this->dossier );
			self::assertFalse( ( new IncrementalBaseline( $this->dossier ) )->promote( $source, self::COMPLETE, self::T0 ) );
			self::assertSame( [], self::contenu( $this->dehors ), 'rien déplacé ni écrit dans la cible' );
			self::assertFileExists( $source, 'futur état local gardé à sa place' );
			self::assertSame( 'baseline_unreadable', ( new IncrementalBaseline( $this->dossier ) )->usable_for( self::COMPLETE, self::T0 )['reason'], 'jamais lu à travers le lien' );
		} finally {
			self::retirer_lien( $this->dossier );
		}
	}

	/**
	 * `current.json` est un lien cassé vers un fichier extérieur : la promotion n'écrit jamais à
	 * travers lui, la cible n'est jamais créée. Lien symbolique : remplacé par la nouvelle
	 * description (rename() ne le suit pas). Jonction Windows : rename() la refuse, la promotion
	 * aussi, et la jonction reste.
	 */
	public function test_la_promotion_n_ecrit_jamais_a_travers_un_fichier_qui_est_un_lien(): void {
		$cible = $this->dehors . '/cible.json';
		$lien  = $this->dossier . '/current.json';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			$source = $this->dossier . '/nouveau.jsonl';
			file_put_contents( $source, IncrementalBaseline::line( [ 'path' => 'a.txt', 'type' => 'file', 'size' => 3, 'mtime' => self::T0 ], null ) );
			$promue = ( new IncrementalBaseline( $this->dossier ) )->promote( $source, self::COMPLETE, self::T0 );
			clearstatcache( true );
			self::assertFileDoesNotExist( $cible, 'rien écrit à travers le lien' );
			self::assertSame( ! $promue, self::est_un_lien( $lien ), 'promue : le lien est remplacé ; sinon il reste' );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Point ouvert b : `current.json` est un lien (symbolique) vers un fichier extérieur. Il n'est
	 * jamais lu (incrémentale refusée, baseline_unreadable) ; la complète suivante écrit sa
	 * description dans un fichier neuf mis à la place du lien, sans jamais le suivre : la cible reste
	 * intacte, et les incrémentales redeviennent possibles. Une jonction Windows ne se remplace pas
	 * par rename() : essai mené en CI Linux.
	 */
	public function test_une_description_qui_est_un_lien_est_remplacee_et_l_incrementale_redevient_possible(): void {
		$cible = $this->dehors . '/reglages.json';
		file_put_contents( $cible, 'à garder' );
		$lien = $this->dossier . '/current.json';
		self::lien_symbolique_ou_ignorer( $cible, $lien );
		$base = new IncrementalBaseline( $this->dossier );
		self::assertSame( 'baseline_unreadable', $base->usable_for( self::COMPLETE, self::T0 )['reason'], 'jamais lu à travers le lien' );

		$this->promouvoir();

		self::assertFalse( self::est_un_lien( $lien ), 'lien remplacé, jamais suivi' );
		self::assertSame( 'à garder', file_get_contents( $cible ), 'cible intacte' );
		self::assertNull( $base->usable_for( self::COMPLETE, self::T0 + 86400 )['reason'], 'incrémentale de nouveau possible' );
	}

	/**
	 * Fichier neuf de description laissé par une tranche tuée entre sa création et son rename()
	 * (`current.json.<16 hex>.tmp`) : retiré par la promotion suivante. Un nom qui n'est pas au
	 * format du module reste, et un lien à ce format aussi (jamais retiré, sa cible non plus).
	 */
	public function test_un_fichier_neuf_de_description_laisse_par_une_coupure_est_retire(): void {
		file_put_contents( $this->dossier . '/current.json.0123456789abcdef.tmp', '{' );
		file_put_contents( $this->dossier . '/current.json.autre.tmp', 'étranger' );
		$cible = $this->dehors . '/garde.json';
		file_put_contents( $cible, 'à garder' );
		$lien = $this->dossier . '/current.json.fedcba9876543210.tmp';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			$this->promouvoir();
			clearstatcache( true );
			self::assertFileDoesNotExist( $this->dossier . '/current.json.0123456789abcdef.tmp', 'fichier neuf laissé : retiré' );
			self::assertFileExists( $this->dossier . '/current.json.autre.tmp', 'nom étranger : gardé' );
			self::assertTrue( self::est_un_lien( $lien ), 'lien : gardé' );
			self::assertSame( 'à garder', file_get_contents( $cible ) );
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * `current.json` est un lien physique (un autre nom d'un fichier rangé hors de l'état local) :
	 * jamais lu, et l'état local est dit illisible, pas absent.
	 */
	public function test_une_description_en_lien_physique_est_illisible(): void {
		$this->promouvoir();
		$copie = $this->dehors . '/description.json';
		rename( $this->dossier . '/current.json', $copie );
		if ( ! @link( $copie, $this->dossier . '/current.json' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::markTestSkipped( 'Le système refuse de créer un lien physique.' );
		}

		self::assertSame( 'baseline_unreadable', ( new IncrementalBaseline( $this->dossier ) )->usable_for( self::COMPLETE, self::T0 )['reason'] );
	}

	/** Un ancien état local qui est un lien n'est pas retiré par la promotion ; sa cible non plus. */
	public function test_un_ancien_etat_local_qui_est_un_lien_reste_avec_sa_cible(): void {
		$cible = $this->dehors . '/ancien.jsonl';
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
			$copie   = $this->dehors . '/' . $nom;
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
