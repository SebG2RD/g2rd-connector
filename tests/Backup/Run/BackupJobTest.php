<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Files\FileWalker;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentReader;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\Payload\PayloadReader;
use G2RD\Connector\Backup\Run\BackupJob;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Backup\Upload\PartUploadResult;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use G2RD\Connector\Tests\Backup\Content\FakeDumpWpdb;

/**
 * La machine à états, la plateforme jouée par le test. Tâche 28 : départ, arrêt, tranche sans
 * production. Les tâches 29-A à 29-C ajoutent leurs tests à ce fichier.
 */
final class BackupJobTest extends BackupTestCase {

	private const NOW = 1792843200;
	private const B1  = '11111111111111111111111111111111';
	private const B2  = '22222222222222222222222222222222';

	/** Clés de la ligne `head` du manifeste, dans l'ordre du contrat (docs/backup-payload.md § 5). */
	private const CLES_HEAD = [ 't', 'format', 'version', 'backup_id', 'type', 'parent_backup_id', 'scope', 'created_at', 'site_key_version', 'wordpress', 'php', 'connector', 'db_charset', 'wp_config_outside_root', 'wp_content_outside_root' ];

	private string $racine;

	private string $travail;

	private string $secrete;

	/** @var list<array{url: string, offset: int, length: int, timeout: int}> */
	private array $envois = [];

	/** Autotest faussé (tâche 29-C : échec juste avant la signature). */
	private bool $autotest_faux = false;

	/** @var list<string> Dossiers dont les droits ont été retirés : rendus avant le nettoyage. */
	private array $droits_retires = [];

	protected function setUp(): void {
		parent::setUp();
		$base          = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-job-' . bin2hex( random_bytes( 4 ) );
		$this->racine  = $base . '/site';
		$this->travail = $base . '/travail';
		mkdir( $this->racine . '/wp-content/uploads', 0777, true );
		file_put_contents( $this->racine . '/index.php', "<?php // accueil\n" );
		file_put_contents( $this->racine . '/wp-content/a.txt', 'bonjour' );
		file_put_contents( $this->racine . '/wp-content/uploads/b.jpg', random_bytes( 3000 ) );
		$this->secrete       = random_bytes( 32 );
		$this->envois         = [];
		$this->autotest_faux  = false;
		$this->droits_retires = [];
		$this->settings( [ 'backup_enabled' => true ] );
		$this->options[ SiteKeyStore::OPTION ] = [
			'tenant_ref'     => str_repeat( 'a', 32 ),
			'site_ref'       => str_repeat( 'b', 32 ),
			'version'        => 1,
			'public_key'     => bin2hex( ( new Sodium() )->x25519_base( $this->secrete ) ),
			'certificate'    => str_repeat( 'c', 128 ),
			'service_key_id' => str_repeat( 'd', 16 ),
			'received_at'    => self::NOW,
		];
		$travail = $this->travail;
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $nom, $valeur ) => match ( $nom ) {
				'g2rd_connector_backup_dir'         => $travail,
				'g2rd_connector_backup_segment_mib' => 16,
				default                             => $valeur,
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $nom, $valeur ): bool {
				$this->options[ $nom ] = $valeur;
				return true;
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $d ): bool => is_dir( $d ) || mkdir( $d, 0777, true ) );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $f ): void {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 268435456 );
	}

	protected function tearDown(): void {
		foreach ( $this->droits_retires as $dossier ) {
			chmod( $dossier, 0755 );
		}
		$base = dirname( $this->racine );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $e ) {
			$e->isDir() ? rmdir( $e->getPathname() ) : unlink( $e->getPathname() );
		}
		rmdir( $base );
		parent::tearDown();
	}

	/**
	 * @param (\Closure(): int)|null $ms   Horloge monotone (millisecondes) ; l'horloge réelle par défaut.
	 * @param string|null            $root Racine copiée ; $this->racine par défaut.
	 */
	private function job( ?object $wpdb = null, ?\Closure $ms = null, ?string $root = null ): BackupJob {
		return new BackupJob(
			new Sodium(),
			static fn (): int => self::NOW,
			$ms,
			function ( string $url, string $host, ?int $port, string $file, int $offset, int $length, int $timeout ): PartUploadResult {
				unset( $host, $port, $file );
				$this->envois[] = [
					'url'     => $url,
					'offset'  => $offset,
					'length'  => $length,
					'timeout' => $timeout,
				];
				return new PartUploadResult( PartUploadResult::UPLOADED, '"etag-' . count( $this->envois ) . '"', 200, null, 'md5', 1 );
			},
			$root ?? $this->racine,
			$wpdb ?? $this->wpdb,
			fn (): array => [
				'ok'      => ! $this->autotest_faux,
				'failed'  => $this->autotest_faux ? [ 'ed25519' ] : [],
				'backend' => 'native',
			]
		);
	}

	/**
	 * Base du site pour une sauvegarde qui copie la base : la table des options y est (filet
	 * `<préfixe>options` de DatabaseDumper::tables(), docs/backup-payload.md § 4).
	 */
	private static function base_du_site(): FakeDumpWpdb {
		$wpdb                       = new FakeDumpWpdb();
		$wpdb->tables['wp_options'] = [
			'columns' => [ 'option_id' => 'bigint(20) unsigned' ],
			'pk'      => [ 'option_id' ],
			'rows'    => [],
		];

		return $wpdb;
	}

	/** Dossier de la sauvegarde $id (sans le créer). */
	private function dossier( string $id ): string {
		return WorkDir::private_dir() . '/job-' . $id;
	}

	/**
	 * Remplace des champs de l'état enregistré (la plateforme ne fait jamais cela : outil de test).
	 *
	 * @param array<string, mixed> $champs
	 */
	private function poser_etat( array $champs ): void {
		$charge = BackupState::load();
		self::assertNotNull( BackupState::save( $charge['raw'], array_replace( $charge['state'], $champs ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function depart( string $id, string $scope = 'files', string $type = 'full', ?string $parent = null ): array {
		return [
			'backup_id'        => $id,
			'scope'            => $scope,
			'type'             => $type,
			'parent_backup_id' => $parent,
			'key_version'      => 1,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function tete( string $id ): array {
		$lignes = file( $this->dossier( $id ) . '/manifest.jsonl', FILE_IGNORE_NEW_LINES );
		self::assertCount( 1, (array) $lignes );

		return (array) json_decode( (string) $lignes[0], true );
	}

	public function test_incrementale_sans_etat_local_devient_complete_et_le_dit(): void {
		$rapport = $this->job()->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		self::assertSame( 'full', $rapport['type'] );
		self::assertSame( 'baseline_missing', $rapport['downgraded'] );
		self::assertNull( $rapport['parent_backup_id'] );
	}

	public function test_refus_au_depart(): void {
		$job    = $this->job();
		$depart = self::depart( self::B1 );

		self::assertSame( $job->start( $depart )['phase'], $job->start( $depart )['phase'], 'même numéro : rien refait' );
		foreach (
			[
				[ [ 'backup_id' => self::B2 ] + $depart, BackupRefused::ALREADY_RUNNING ],
				[ [ 'key_version' => 2 ] + $depart, BackupRefused::KEY_VERSION_MISMATCH ],
				[ [ 'scope' => 'tout' ] + $depart, BackupRefused::INVALID_PAYLOAD ],
				[ [ 'type' => 'incremental', 'parent_backup_id' => self::B1 ] + $depart, BackupRefused::INVALID_PAYLOAD ],
			] as [ $charge, $code ]
		) {
			try {
				$job->start( $charge );
				self::fail( 'Refus attendu : ' . $code );
			} catch ( BackupRefused $refus ) {
				self::assertSame( $code, $refus->reason_code() );
			}
		}
	}

	public function test_autotest_en_echec_au_depart_porte_l_etape(): void {
		$this->autotest_faux = true;

		try {
			$this->job()->start( self::depart( self::B1 ) );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( [ BackupRefused::SELF_TEST_FAILED, 'start' ], [ $refus->reason_code(), $refus->stage() ] );
		}
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ) );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	public function test_base_mal_reglee_refusee_au_depart_sans_rien_laisser(): void {
		// Sans <préfixe>options dans la liste lue (filet de DatabaseDumper::tables()).
		try {
			$this->job( new FakeDumpWpdb() )->start( self::depart( self::B1, 'db' ) );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::DB_TABLES_NOT_FOUND, $refus->reason_code() );
		}
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'aucun dossier de sauvegarde laissé' );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	public function test_la_ligne_head_suit_le_contrat_et_les_journaux_sont_valides(): void {
		// Racine qui contient WP_CONTENT_DIR (tests/bootstrap.php : <temp>/g2rd-connector-tests-content).
		$rapport = $this->job( self::base_du_site(), null, dirname( (string) WP_CONTENT_DIR ) )->start( self::depart( self::B1, 'both' ) );

		$lignes = file( $this->dossier( self::B1 ) . '/manifest.jsonl', FILE_IGNORE_NEW_LINES );
		self::assertCount( 1, (array) $lignes );
		$head = json_decode( (string) $lignes[0], true );
		self::assertSame( self::CLES_HEAD, array_keys( $head ) );
		self::assertSame( 'utf8mb4', $head['db_charset'] );
		self::assertFalse( $head['wp_content_outside_root'] );
		$etat = BackupState::load()['state'];
		self::assertSame( [ 'manifest' => strlen( (string) $lignes[0] ) + 1, 'baseline' => 0, 'segments' => 0 ], $etat['journals'] );
		self::assertNull( $etat['files']['cursor_hex'] );
		self::assertNull( $etat['db']['table_segment'] );
		self::assertSame( [ bin2hex( 'wp_options' ) ], $etat['db']['tables_hex'], 'noms des tables gardés en hexadécimal (docs/backup-payload.md § 4)' );
		self::assertSame( 1, $rapport['progress']['db_tables_total'] );
	}

	public function test_wp_content_et_wp_config_hors_de_la_racine_sont_dits(): void {
		// WP_CONTENT_DIR (tests/bootstrap.php) n'est pas sous la racine copiée ; wp-config.php au-dessus d'elle.
		file_put_contents( dirname( $this->racine ) . '/wp-config.php', "<?php // réglages\n" );

		$this->job()->start( self::depart( self::B1 ) );

		$head = $this->tete( self::B1 );
		self::assertTrue( $head['wp_content_outside_root'] );
		self::assertTrue( $head['wp_config_outside_root'] );
	}

	public function test_arret_par_la_plateforme(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );

		self::assertSame( [ 'backup_id' => self::B2, 'aborted' => false, 'reason' => null ], $job->abort( [ 'backup_id' => self::B2 ] ) );
		self::assertTrue( $job->abort( [ 'backup_id' => self::B1 ] )['aborted'] );
		self::assertSame( 'aborted_by_platform', BackupState::load()['state']['last']['code'] );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ) );
	}

	public function test_tranche_case_decochee_clot_la_sauvegarde(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$this->settings( [ 'backup_enabled' => false ] );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::DISABLED, $refus->reason_code() );
			self::assertStringContainsString( 'désactivées', $refus->getMessage() );
		}
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::IDLE, 'aborted', 'backup_disabled' ], [ $etat['phase'], $etat['last']['outcome'], $etat['last']['code'] ], 'la porte redevient transparente' );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'segments et journaux retirés' );
	}

	public function test_segment_termine_retire_et_parties_envoyees_sous_le_reste_de_la_tranche(): void {
		$horloge = 0;
		$delais  = [];
		$job     = new BackupJob(
			new Sodium(),
			static fn (): int => self::NOW,
			static function () use ( &$horloge ): int {
				return $horloge;
			},
			// Chaque envoi dure tout son délai : le premier prend le reste de la tranche (20 s).
			static function ( string $url, string $host, ?int $port, string $file, int $offset, int $length, int $timeout ) use ( &$horloge, &$delais ): PartUploadResult {
				unset( $url, $host, $port, $file, $offset, $length );
				$delais[] = $timeout;
				$horloge += $timeout * 1000;
				return new PartUploadResult( PartUploadResult::UPLOADED, '"lent"', 200, null, 'md5', $timeout * 1000 );
			},
			$this->racine,
			$this->wpdb
		);
		$job->start( self::depart( self::B1 ) );
		$dossier = $this->dossier( self::B1 );
		file_put_contents( $dossier . '/seg-0.seg', random_bytes( 10 ) );
		file_put_contents( $dossier . '/seg-1.seg', random_bytes( 3000 ) );
		$parties = [];
		for ( $n = 1; $n <= 3; $n++ ) {
			$parties[] = [ 'part_number' => $n, 'offset' => ( $n - 1 ) * 1000, 'length' => 1000 ];
		}
		$segment = static fn ( int $n, array $parts ): array => [ 'segment' => $n, 'kind' => 'files', 'bytes' => 3000, 'blocks' => 1, 'sha256' => str_repeat( '0', 64 ), 'header' => str_repeat( '0', 296 ), 'parts' => $parts, 'uploaded' => [] ];
		$this->poser_etat( [ 'phase' => BackupState::UPLOAD, 'segments' => 2, 'pending' => [ $segment( 0, [ $parties[0] ] ), $segment( 1, $parties ) ] ] );
		$url = static fn ( int $n ): array => [ 'part_number' => $n, 'url' => 'https://stockage.test/b/k?partNumber=' . $n . '&X-Amz-Signature=x', 'host' => 'stockage.test', 'port' => null ];

		$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => [ 0 ], 'uploads' => [ [ 'segment' => 1, 'parts' => [ $url( 1 ), $url( 2 ), $url( 3 ) ] ] ] ] );

		self::assertFileDoesNotExist( $dossier . '/seg-0.seg', 'segment terminé par la plateforme : retiré' );
		self::assertSame( [ 20 ], $delais, 'délai = reste de la tranche, jamais 60 s fixes' );
		self::assertSame( [ 'not_attempted', 'not_attempted' ], array_column( $rapport['part_failures'], 'outcome' ) );
		self::assertCount( 1, $rapport['pending'][0]['uploaded'] );
		self::assertLessThanOrEqual( 25000, $rapport['slice_ms'], 'tranche réglée + 5 s au plus' );
	}

	public function test_les_journaux_sont_ramenes_a_leur_longueur_validee(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$manifeste = $this->dossier( self::B1 ) . '/manifest.jsonl';
		$valide    = (string) file_get_contents( $manifeste );
		file_put_contents( $manifeste, "{\"t\":\"file\",\"path\":\"fantome\"}\n", FILE_APPEND );
		file_put_contents( $this->dossier( self::B1 ) . '/baseline-new.jsonl', "{\"p\":\"fantome\"}\n" );

		$job->step( [ 'backup_id' => self::B1 ] );

		self::assertStringStartsWith( $valide, (string) file_get_contents( $manifeste ) );
		self::assertStringNotContainsString( 'fantome', (string) file_get_contents( $manifeste ), 'ligne d\'une tranche non enregistrée retirée' );
		self::assertStringNotContainsString( 'fantome', (string) file_get_contents( $this->dossier( self::B1 ) . '/baseline-new.jsonl' ) );
	}

	public function test_un_journal_plus_court_que_sa_longueur_validee_arrete_la_sauvegarde(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		file_put_contents( $this->dossier( self::B1 ) . '/manifest.jsonl', '' );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
		}
		self::assertSame( [ 'failed', 'backup_work_dir_unavailable' ], [ BackupState::load()['state']['last']['outcome'], BackupState::load()['state']['last']['code'] ] );
	}

	/**
	 * Joue des tranches sans envoi jusqu'à la fin de la copie (phase sortie de db et files, ou
	 * segment du manifeste dû) ; chaque segment est copié avant d'être libéré.
	 *
	 * @return array<int, string> Segments produits (numéro → octets chiffrés).
	 */
	private function copier( BackupJob $job, string $id ): array {
		$segments = [];
		$lire     = function () use ( &$segments, $id ): array {
			$numeros = [];
			foreach ( (array) BackupState::load()['state']['pending'] as $p ) {
				$segments[ $p['segment'] ] = (string) file_get_contents( $this->dossier( $id ) . '/seg-' . $p['segment'] . '.seg' );
				$numeros[]                 = $p['segment'];
			}
			return $numeros;
		};
		for ( $tour = 0; $tour < 50; $tour++ ) {
			$etat = BackupState::load()['state'];
			if ( ! BackupState::is_capturing( $etat ) || true === ( $etat['manifest_due'] ?? false ) ) {
				break;
			}
			$job->step( [ 'backup_id' => $id, 'completed' => $lire() ] );
		}
		$lire();

		return $segments;
	}

	/**
	 * Base du test : `wp_a` de lignes de 2,3 Mio aléatoires (une table qui remplit un segment de
	 * 16 Mio), `wp_b` petite, et la table des options vide de base_du_site() (filet `<préfixe>options`
	 * de DatabaseDumper::tables()), copiée après elles (tri octet par octet).
	 */
	private static function base_lourde( int $lignes_a ): FakeDumpWpdb {
		$base = self::base_du_site();
		$rows = [];
		for ( $i = 1; $i <= $lignes_a; $i++ ) {
			$rows[] = [ 'id' => (string) $i, 'v' => random_bytes( 2411724 ) ];
		}
		$base->tables['wp_a'] = [ 'columns' => [ 'id' => 'bigint(20)', 'v' => 'longtext' ], 'pk' => [ 'id' ], 'rows' => $rows ];
		$base->tables['wp_b'] = [ 'columns' => [ 'id' => 'bigint(20)', 'n' => 'varchar(20)' ], 'pk' => [ 'id' ], 'rows' => [ [ 'id' => '1', 'n' => 'petite' ] ] ];

		return $base;
	}

	/**
	 * @return list<array<string, mixed>> Lignes `table` du journal du manifeste.
	 */
	private function lignes_table( string $id ): array {
		$lignes = array_map( static fn ( string $l ): array => (array) json_decode( $l, true ), (array) file( $this->dossier( $id ) . '/manifest.jsonl', FILE_IGNORE_NEW_LINES ) );

		return array_values( array_filter( $lignes, static fn ( array $l ): bool => 'table' === ( $l['t'] ?? null ) ) );
	}

	public function test_table_repartie_le_manifeste_dit_le_segment_ou_elle_commence(): void {
		$base = self::base_lourde( 9 );
		$job  = $this->job( $base );
		$job->start( self::depart( self::B1, 'db' ) );

		$segments = $this->copier( $job, self::B1 );

		self::assertGreaterThanOrEqual( 2, count( $segments ) );
		self::assertSame( [ [ 'wp_a', 0 ], [ 'wp_b', 1 ], [ 'wp_options', 1 ] ], array_map( static fn ( array $l ): array => [ $l['name'], $l['segment'] ], $this->lignes_table( self::B1 ) ), 'wp_a commence au segment 0 et finit au 1' );
		$etat = BackupState::load()['state'];
		self::assertTrue( true === $etat['manifest_due'] || BackupState::UPLOAD === $etat['phase'], 'copie de la base finie' );
	}

	public function test_base_indisponible_en_cours_de_tranche_aucune_ligne_en_double(): void {
		$base = self::base_lourde( 7 );
		// Petite table copiée avant wp_a (tri octet par octet) : sa ligne `table` est écrite au
		// segment 0 de la tranche refusée ; wp_a, ouverte à la fin du segment 0, ne se termine
		// qu'au segment 1, où wp_b est illisible.
		$base->tables['wp_0'] = [ 'columns' => [ 'id' => 'bigint(20)' ], 'pk' => [ 'id' ], 'rows' => [ [ 'id' => '1' ] ] ];
		$base->fails_on       = 'wp_b';
		$job                  = $this->job( $base );
		$job->start( self::depart( self::B1, 'db' ) );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : wp_b illisible après un premier segment produit dans la tranche.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::DB_READ_FAILED, $refus->reason_code() );
		}
		$etat = BackupState::load()['state'];
		self::assertSame( [ 0, [] ], [ $etat['segments'], $etat['pending'] ], 'rien de la production de la tranche n\'est compté' );
		self::assertSame( [], $this->lignes_table( self::B1 ), 'journal ramené à sa longueur validée' );
		self::assertSame( '', (string) file_get_contents( $this->dossier( self::B1 ) . '/segments.jsonl' ), 'segment 0 de la tranche refusée retiré de la liste des segments' );

		$base->fails_on = null;
		$this->copier( $job, self::B1 );

		self::assertSame( [ 'wp_0', 'wp_a', 'wp_b', 'wp_options' ], array_column( $this->lignes_table( self::B1 ), 'name' ), 'une ligne par table, jamais deux' );
		self::assertSame( [ 0, 0, 1, 1 ], array_column( $this->lignes_table( self::B1 ), 'segment' ), 'wp_a commence au segment 0 refait' );
		self::assertSame( 4, BackupState::load()['state']['totals']['db_tables'] );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function enregistrements( string $segment ): array {
		$clair = '';
		$flux  = fopen( 'php://memory', 'w+b' );
		fwrite( $flux, $segment );
		rewind( $flux );
		( new SegmentReader( new Hpke( new Sodium() ) ) )->decrypt(
			$flux,
			$this->secrete,
			static function ( string $bloc ) use ( &$clair ): void {
				$clair .= $bloc;
			}
		);
		fclose( $flux );
		return PayloadReader::parse( $clair );
	}

	/**
	 * Lignes `file` du journal du manifeste de la sauvegarde $id, chemin → ligne.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function lignes_fichier( string $id ): array {
		$lignes = [];
		foreach ( (array) file( $this->dossier( $id ) . '/manifest.jsonl', FILE_IGNORE_NEW_LINES ) as $texte ) {
			$l = (array) json_decode( (string) $texte, true );
			if ( 'file' === ( $l['t'] ?? null ) ) {
				$chemin = $l['path'] ?? base64_decode( (string) $l['path_b64'] );
				self::assertArrayNotHasKey( $chemin, $lignes, 'ligne de manifeste en double : ' . $chemin );
				$lignes[ $chemin ] = $l;
			}
		}
		return $lignes;
	}

	/** État local d'une complète B1 écrit d'après l'arbre actuel (comme l'aurait promu la tâche 29-C). */
	private function etat_local_de_l_arbre(): void {
		WorkDir::ensure();
		$source = WorkDir::baseline_dir() . '/source.jsonl';
		$lignes = '';
		foreach ( ( new FileWalker( $this->racine, [] ) )->walk( null ) as $entree ) {
			$lignes .= IncrementalBaseline::line( $entree, 'file' === $entree['type'] ? hash_file( 'sha256', $this->racine . '/' . $entree['path'] ) : null );
		}
		file_put_contents( $source, $lignes );
		self::assertTrue( ( new IncrementalBaseline( WorkDir::baseline_dir() ) )->promote( $source, self::B1, self::NOW ) );
	}

	public function test_fichiers_copies_curseur_en_hexadecimal_et_etat_local_prepare(): void {
		file_put_contents( $this->racine . '/wp-content/été.txt', 'accents' );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );

		$segments = $this->copier( $job, self::B1 );

		$fichiers = [];
		foreach ( $segments as $segment ) {
			foreach ( $this->enregistrements( $segment ) as $e ) {
				if ( 'file' === $e['type'] ) {
					$fichiers[ $e['path'] ] = ( $fichiers[ $e['path'] ] ?? '' ) . $e['data'];
				}
			}
		}
		self::assertSame( 'bonjour', $fichiers['wp-content/a.txt'] );
		self::assertSame( 'accents', $fichiers['wp-content/été.txt'] );
		$etat = BackupState::load()['state'];
		self::assertMatchesRegularExpression( '/\A[0-9a-f]+\z/', (string) $etat['files']['cursor_hex'], 'jamais le chemin brut dans l\'état JSON' );
		self::assertTrue( $etat['files']['done'] );
		self::assertSame( count( $this->lignes_fichier( self::B1 ) ), count( (array) file( $this->dossier( self::B1 ) . '/baseline-new.jsonl' ) ), 'une ligne d\'état local par entrée copiée' );
	}

	public function test_fin_de_tranche_perdue_la_tranche_refaite_ne_laisse_aucun_doublon(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		// Première écriture (début de tranche) passe ; la seconde (fin de tranche) échoue :
		// « MySQL server has gone away » après la production.
		$this->wpdb->before_write = function (): void {
			$this->wpdb->before_write = function (): void {
				$this->wpdb->write_fails = true;
			};
		};

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		}
		$this->wpdb->write_fails = false;
		$this->copier( $job, self::B1 );

		$lignes = $this->lignes_fichier( self::B1 );
		self::assertArrayHasKey( 'wp-content/a.txt', $lignes );
		$etat_local = array_map( static fn ( string $l ): ?array => IncrementalBaseline::decode_line( $l ), (array) file( $this->dossier( self::B1 ) . '/baseline-new.jsonl' ) );
		$chemins    = array_column( $etat_local, 'path' );
		self::assertSame( array_values( array_unique( $chemins ) ), $chemins, 'aucun doublon dans l\'état local (sinon un « deleted » faux à l\'incrémentale suivante)' );
		self::assertSame( count( $lignes ), BackupState::load()['state']['totals']['files'] );
	}

	public function test_fichier_reparti_disparu_entre_deux_tranches_dit_illisible(): void {
		file_put_contents( $this->racine . '/wp-content/gros.bin', random_bytes( 40 * 1048576 ) );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$premiere = $job->step( [ 'backup_id' => self::B1 ] );
		self::assertCount( 2, $premiere['pending'], 'deux segments de 16 Mio, gros.bin pas fini' );
		self::assertSame( 0, BackupState::load()['state']['files']['current']['segment'] );
		unlink( $this->racine . '/wp-content/gros.bin' );

		$job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $premiere['pending'], 'segment' ) ] );
		$this->copier( $job, self::B1 );

		$ligne = $this->lignes_fichier( self::B1 )['wp-content/gros.bin'];
		self::assertSame( [ 'unreadable', null, 41943040 ], [ $ligne['state'], $ligne['segment'], $ligne['size'] ], 'jamais tu : compté illisible avec sa taille lue au début' );
		self::assertSame( 1, BackupState::load()['state']['totals']['unreadable'] );
	}

	public function test_nom_non_utf8_reparti_puis_repris_relu_exactement(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Windows refuse un nom de fichier qui n\'est pas de l\'UTF-8 : essai mené sur Linux (CI, banc).' );
		}
		$nom = "wp-content/\xe9t\xe9.bin";
		file_put_contents( $this->racine . '/' . $nom, random_bytes( 40 * 1048576 ) );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );

		$segments = $this->copier( $job, self::B1 );

		$morceaux = '';
		foreach ( $segments as $segment ) {
			foreach ( $this->enregistrements( $segment ) as $e ) {
				if ( 'file' === $e['type'] && $nom === $e['path'] ) {
					$morceaux .= $e['data'];
				}
			}
		}
		self::assertSame( (string) file_get_contents( $this->racine . '/' . $nom ), $morceaux, 'relu exactement, aucun morceau en double' );
		self::assertSame( 'stored', $this->lignes_fichier( self::B1 )[ $nom ]['state'] );
	}

	public function test_incrementale_ne_porte_que_les_changements(): void {
		$this->etat_local_de_l_arbre();
		file_put_contents( $this->racine . '/wp-content/a.txt', 'bonsoir !' );
		unlink( $this->racine . '/index.php' );
		file_put_contents( $this->racine . '/wp-content/nouveau.css', 'body{}' );
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		$segments = $this->copier( $job, self::B2 );

		$types = [];
		foreach ( $segments as $segment ) {
			foreach ( $this->enregistrements( $segment ) as $e ) {
				$types[ $e['path'] ] = $e['type'];
			}
		}
		self::assertSame( 'file', $types['wp-content/a.txt'] ?? null );
		self::assertSame( 'file', $types['wp-content/nouveau.css'] ?? null );
		self::assertSame( 'deleted', $types['index.php'] ?? null );
		self::assertArrayNotHasKey( 'wp-content/uploads/b.jpg', $types, 'inchangé : pas recopié' );
		$lignes = $this->lignes_fichier( self::B2 );
		self::assertSame( 'unchanged', $lignes['wp-content/uploads/b.jpg']['state'] );
		self::assertSame( 'deleted', $lignes['index.php']['state'] );
		self::assertSame( [ 'index.php' ], array_keys( array_filter( $lignes, static fn ( array $l ): bool => 'deleted' === $l['state'] ) ), 'un seul « deleted » : le fichier supprimé' );
	}

	/** Dossier `wp-content/prive` (deux fichiers et un sous-dossier) des essais de droits retirés. */
	private function dossier_prive(): void {
		mkdir( $this->racine . '/wp-content/prive/sous', 0777, true );
		file_put_contents( $this->racine . '/wp-content/prive/un.txt', 'premier' );
		file_put_contents( $this->racine . '/wp-content/prive/deux.txt', 'second' );
		file_put_contents( $this->racine . '/wp-content/prive/sous/trois.txt', 'troisième' );
	}

	/** Saute l'essai là où les droits POSIX d'un dossier n'ont pas d'effet (avant tout travail). */
	private static function sauter_sans_droits_posix(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'Droits POSIX sans effet sous Windows (chmod n\'y retire ni la liste ni la traversée d\'un dossier) : essai mené en CI Linux et sur le banc (tâche 37, E1).' );
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			self::markTestSkipped( 'Essai lancé en root, qui passe outre les droits des dossiers : à mener sous un utilisateur ordinaire (CI Linux, banc, tâche 37, E1).' );
		}
	}

	/** Passe un dossier du site d'essai en 0000 ; saute l'essai si ce retrait reste sans effet. */
	private function retirer_droits( string $relatif ): void {
		self::sauter_sans_droits_posix();
		$dossier                = $this->racine . '/' . $relatif;
		$this->droits_retires[] = $dossier;
		chmod( $dossier, 0000 );
		clearstatcache();
		if ( false !== @scandir( $dossier ) ) {
			self::markTestSkipped( 'Les droits retirés n\'ont pas d\'effet pour cet utilisateur (root sans l\'extension posix ?) : essai à mener sous un utilisateur ordinaire (CI Linux, banc, tâche 37, E1).' );
		}
	}

	/**
	 * Chemins de tous les enregistrements des segments (morceaux, dossiers, liens, supprimés).
	 *
	 * @param array<int, string> $segments
	 * @return list<string>
	 */
	private function chemins_ecrits( array $segments ): array {
		$chemins = [];
		foreach ( $segments as $segment ) {
			foreach ( $this->enregistrements( $segment ) as $e ) {
				if ( '' !== $e['path'] ) {
					$chemins[] = $e['type'] . ' ' . $e['path'];
				}
			}
		}
		return $chemins;
	}

	/**
	 * @param array<string, array<string, mixed>> $lignes
	 * @return list<string>
	 */
	private static function supprimes( array $lignes ): array {
		return array_keys( array_filter( $lignes, static fn ( array $l ): bool => 'deleted' === $l['state'] ) );
	}

	public function test_complete_un_dossier_illisible_est_inscrit_illisible_sans_rien_archiver(): void {
		self::sauter_sans_droits_posix();
		$this->dossier_prive();
		$this->retirer_droits( 'wp-content/prive' );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );

		$segments = $this->copier( $job, self::B1 );

		$lignes = $this->lignes_fichier( self::B1 );
		self::assertArrayHasKey( 'wp-content/prive', $lignes, 'jamais tu' );
		self::assertSame( [ 'dir', 'unreadable', null ], [ $lignes['wp-content/prive']['kind'], $lignes['wp-content/prive']['state'], $lignes['wp-content/prive']['segment'] ] );
		self::assertSame( [], preg_grep( '#^\S+ wp-content/prive(/|$)#', $this->chemins_ecrits( $segments ) ), 'rien du dossier refusé dans les segments, pas même son enregistrement de dossier' );
		self::assertSame( 1, BackupState::load()['state']['totals']['unreadable'] );
		self::assertStringNotContainsString( 'prive', (string) file_get_contents( $this->dossier( self::B1 ) . '/baseline-new.jsonl' ), 'absent du futur état local : la prochaine incrémentale le recopiera' );
	}

	public function test_incrementale_un_dossier_devenu_illisible_n_est_jamais_dit_supprime(): void {
		self::sauter_sans_droits_posix();
		$this->dossier_prive();
		file_put_contents( $this->racine . '/wp-content/uploads/c.txt', 'vrai' );
		$this->etat_local_de_l_arbre();
		unlink( $this->racine . '/wp-content/uploads/c.txt' );
		$this->retirer_droits( 'wp-content/prive' );
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		$segments = $this->copier( $job, self::B2 );

		$lignes = $this->lignes_fichier( self::B2 );
		self::assertSame( [ 'wp-content/uploads/c.txt' ], self::supprimes( $lignes ), 'seule la vraie disparition, dans un dossier lisible, est dite supprimée' );
		self::assertSame( [], preg_grep( '#^\S+ wp-content/prive(/|$)#', $this->chemins_ecrits( $segments ) ), 'rien d\'écrit pour le dossier refusé, ni « deleted » ni contenu' );
		self::assertContains( 'deleted wp-content/uploads/c.txt', $this->chemins_ecrits( $segments ) );
		$prive = array_filter( $lignes, static fn ( string $chemin ): bool => str_starts_with( $chemin, 'wp-content/prive' ), ARRAY_FILTER_USE_KEY );
		self::assertSame( [ 'wp-content/prive', 'wp-content/prive/deux.txt', 'wp-content/prive/sous', 'wp-content/prive/sous/trois.txt', 'wp-content/prive/un.txt' ], array_keys( $prive ) );
		self::assertSame( [ 'unreadable' ], array_values( array_unique( array_column( $prive, 'state' ) ) ), 'le dossier et les entrées de l\'état local sous lui : illisibles' );
		self::assertSame( [ 'file', 7, null, null ], [ $prive['wp-content/prive/un.txt']['kind'], $prive['wp-content/prive/un.txt']['size'], $prive['wp-content/prive/un.txt']['sha256'], $prive['wp-content/prive/un.txt']['segment'] ], 'entrée rebâtie depuis l\'état local' );
		self::assertSame( 'dir', $prive['wp-content/prive/sous']['kind'] );
		self::assertSame( 5, BackupState::load()['state']['totals']['unreadable'], 'les quatre entrées de l\'état local sous le dossier, plus le dossier' );
	}

	public function test_incrementale_reprise_dans_un_dossier_devenu_illisible_jamais_dite_supprimee(): void {
		self::sauter_sans_droits_posix();
		$this->dossier_prive();
		$this->etat_local_de_l_arbre();
		// Nouveau depuis la complète : copié, réparti sur trois segments de 16 Mio ; la première
		// tranche (deux segments au plus en attente) s'arrête dedans.
		file_put_contents( $this->racine . '/wp-content/prive/deux.bin', random_bytes( 40 * 1048576 ) );
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		$premiere = $job->step( [ 'backup_id' => self::B2 ] );
		self::assertCount( 2, $premiere['pending'] );
		self::assertSame( 'wp-content/prive/deux.bin', hex2bin( (string) BackupState::load()['state']['files']['cursor_hex'] ), 'tranche arrêtée dans le dossier' );
		$this->retirer_droits( 'wp-content/prive' );

		$job->step( [ 'backup_id' => self::B2, 'completed' => array_column( $premiere['pending'], 'segment' ) ] );
		$this->copier( $job, self::B2 );

		$lignes = $this->lignes_fichier( self::B2 );
		self::assertSame( [], self::supprimes( $lignes ), 'rien n\'est dit supprimé sous le dossier devenu illisible' );
		self::assertSame( 'stored', $lignes['wp-content/prive']['state'], 'lisible à la première tranche : sa ligne est déjà écrite, jamais une seconde' );
		self::assertSame( [ 'unreadable', 41943040 ], [ $lignes['wp-content/prive/deux.bin']['state'], $lignes['wp-content/prive/deux.bin']['size'] ], 'fichier réparti devenu illisible' );
		foreach ( [ 'wp-content/prive/deux.txt', 'wp-content/prive/sous', 'wp-content/prive/sous/trois.txt', 'wp-content/prive/un.txt' ] as $chemin ) {
			self::assertSame( 'unreadable', $lignes[ $chemin ]['state'] ?? null, $chemin . ' : suite du dossier, inscrite d\'après l\'état local' );
		}
		self::assertSame( 'unchanged', $lignes['wp-content/uploads/b.jpg']['state'], 'le parcours continue après le dossier' );
		self::assertSame( 6, BackupState::load()['state']['totals']['unreadable'], 'deux.bin, les quatre entrées de l\'état local après lui, et le dossier (compté par FileWalker::unlisted())' );
	}
}
