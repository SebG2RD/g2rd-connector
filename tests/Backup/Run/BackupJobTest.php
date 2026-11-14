<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Run;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Backup\Content\ManifestJournal;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Files\FileWalker;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentReader;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\Payload\PayloadReader;
use G2RD\Connector\Backup\Run\BackupJob;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Backup\Upload\PartUploadResult;
use G2RD\Connector\Support\DiskSpace;
use G2RD\Connector\Tests\Backup\BackupTestCase;
use G2RD\Connector\Tests\Backup\Content\FakeDumpWpdb;
use G2RD\Connector\Tests\Backup\Support\LiensSurDisque;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La machine à états, la plateforme jouée par le test. Tâche 28 : départ, arrêt, tranche sans
 * production. Les tâches 29-A à 29-C ajoutent leurs tests à ce fichier.
 */
final class BackupJobTest extends BackupTestCase {

	use LiensSurDisque;

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
		// Fichiers du site d'avant la complète : dates antérieures au départ figé des sauvegardes
		// (NOW), comme sur un vrai site ; un fichier écrit par un test, lui, porte la date du jour.
		foreach ( [ '/index.php', '/wp-content/a.txt', '/wp-content/uploads/b.jpg' ] as $fichier ) {
			touch( $this->racine . $fichier, self::NOW - 3600 );
		}
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
	 * @param (\Closure(): int)|null $ms     Horloge monotone (millisecondes) ; l'horloge réelle par défaut.
	 * @param string|null            $root   Racine copiée ; $this->racine par défaut.
	 * @param Sodium|null            $sodium Moteur ; celui du poste par défaut.
	 */
	private function job( ?object $wpdb = null, ?\Closure $ms = null, ?string $root = null, ?Sodium $sodium = null ): BackupJob {
		return new BackupJob(
			$sodium ?? new Sodium(),
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
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		}
		self::assertSame( [ 'failed', 'backup_work_dir_unavailable' ], [ BackupState::load()['state']['last']['outcome'], BackupState::load()['state']['last']['code'] ] );
	}

	/**
	 * Refus d'une sauvegarde déjà commencée (décision n° 3 du point de contrôle n° 2 de la phase 4) :
	 * même code stable, phrase qui dit qu'elle est arrêtée et ce qui a été envoyé, jamais « n'a pas
	 * été lancée … rien n'a été modifié ».
	 */
	private static function assert_arretee_en_cours( BackupRefused $refus, string $code ): void {
		self::assertSame( $code, $refus->reason_code() );
		self::assertNull( $refus->retry_after() );
		self::assertStringStartsWith( 'La sauvegarde en cours a été arrêtée et ne sera pas terminée : ', $refus->getMessage() );
		self::assertStringNotContainsString( 'n’a pas été lancée', $refus->getMessage() );
		self::assertStringNotContainsString( 'Rien n’a été modifié', $refus->getMessage() );
		self::assertStringContainsString( 'ce qu’elle avait déjà envoyé au stockage ne compte pas comme une sauvegarde', $refus->getMessage() );
		self::assertStringContainsString( ' Action : ', $refus->getMessage() );
	}

	/** Sauvegarde close en échec sous $code : état au repos, issue vraie. */
	private static function assert_close_en_echec( string $code ): void {
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::IDLE, 'failed', $code ], [ $etat['phase'], $etat['last']['outcome'], $etat['last']['code'] ] );
	}

	/** Moteur sans sodium natif ni copie de WordPress : X25519 et Ed25519 indisponibles. */
	private static function sans_sodium(): Sodium {
		return new Sodium( static fn ( string $extension ): bool => false, static fn (): bool => false );
	}

	public function test_dossier_de_travail_impossible_au_depart_la_sauvegarde_n_a_pas_ete_lancee(): void {
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		try {
			$this->job()->start( self::depart( self::B1 ) );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
			self::assertStringStartsWith( 'La sauvegarde n’a pas été lancée : ', $refus->getMessage(), 'refus au départ : phrase inchangée' );
		}
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	// ── Lien dans le dossier de travail : refus qui le dit ───────────────────

	/** Le refus parle du lien et du refus répété ; jamais des droits de wp-content. */
	private static function assert_refus_pour_un_lien( BackupRefused $refus, string $debut ): void {
		self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
		self::assertStringStartsWith( $debut . 'un raccourci vers un autre fichier ou dossier (un lien) a été trouvé', $refus->getMessage() );
		self::assertStringContainsString( 'Tant que ce raccourci reste en place, la sauvegarde sera refusée de la même façon à chaque nouvelle tentative.', $refus->getMessage() );
		self::assertStringNotContainsString( 'droits du dossier wp-content', $refus->getMessage() );
	}

	/**
	 * Le dossier privé est un lien vers un dossier extérieur vide : le départ est refusé avec la
	 * phrase du lien, à chaque tentative du même numéro, et rien n'est écrit dehors.
	 */
	public function test_dossier_prive_qui_est_un_lien_refuse_le_depart_et_le_dit_a_chaque_tentative(): void {
		$dehors = dirname( $this->racine ) . '/dehors';
		mkdir( $dehors );
		mkdir( $this->travail );
		$lien = WorkDir::private_dir();
		try {
			self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
			foreach ( [ 'première tentative', 'seconde tentative' ] as $cas ) {
				self::assert_refus_pour_un_lien( $this->refus_au_depart( self::B1 ), 'La sauvegarde n’a pas été lancée : ' );
				self::assertSame( [], self::contenu( $dehors ), $cas . ' : rien écrit à travers le lien' );
				self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'], $cas );
			}
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * Le dossier de travail (`wp-content/g2rd-backups`) est lui-même un lien vers les médias du
	 * site : le départ est refusé avec la phrase du lien, et rien n'est écrit dans les médias.
	 */
	public function test_dossier_de_travail_qui_est_un_lien_refuse_le_depart_et_le_dit(): void {
		$medias = $this->racine . '/wp-content/uploads';
		try {
			self::lien_vers_un_dossier_ou_ignorer( $medias, $this->travail );
			$refus = $this->refus_au_depart( self::B1 );
			self::assert_refus_pour_un_lien( $refus, 'La sauvegarde n’a pas été lancée : ' );
			self::assertStringContainsString( 'a été trouvé dans le dossier de travail des sauvegardes (' . WorkDir::display_name() . '), ou à sa place,', $refus->getMessage(), 'le lien est le dossier lui-même' );
			self::assertStringContainsString( 'le connecteur recréera alors un vrai dossier', $refus->getMessage() );
			self::assertSame( [ 'b.jpg' ], self::contenu( $medias ), 'rien écrit dans les médias' );
			self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
		} finally {
			self::retirer_lien( $this->travail );
		}
	}

	/**
	 * Un fichier du module laissé dans le dossier de la sauvegarde est un lien (le nettoyage ne
	 * touche jamais un lien) : le même numéro est refusé à chaque tentative, et la phrase le dit.
	 * La cible du lien n'est jamais écrite. Sous Windows, une jonction vers le fichier.
	 */
	public function test_fichier_du_module_qui_est_un_lien_refuse_la_meme_sauvegarde_et_le_dit(): void {
		self::assertTrue( WorkDir::ensure() );
		mkdir( $this->dossier( self::B1 ) );
		$cible = dirname( $this->racine ) . '/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		$lien = $this->dossier( self::B1 ) . '/manifest.jsonl';
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			foreach ( [ 'première tentative', 'seconde tentative' ] as $cas ) {
				self::assert_refus_pour_un_lien( $this->refus_au_depart( self::B1 ), 'La sauvegarde n’a pas été lancée : ' );
				self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), $cas . ' : cible jamais écrite' );
				self::assertTrue( self::est_un_lien( $lien ), $cas . ' : le lien reste' );
			}
		} finally {
			self::retirer_lien( $lien );
		}
	}

	/**
	 * @return iterable<string, array{string, string}> Fichier écrit par la tranche, nature du lien.
	 */
	public static function fichiers_ecrits_par_une_tranche(): iterable {
		yield 'segment en cours, lien'          => [ 'seg-0.part', 'lien' ];
		yield 'journal du manifeste, lien'      => [ 'manifest.jsonl', 'lien' ];
		yield 'futur état local, lien'          => [ 'baseline-new.jsonl', 'lien' ];
		yield 'journal du manifeste, physique'  => [ 'manifest.jsonl', 'physique' ];
		yield 'futur état local, physique'      => [ 'baseline-new.jsonl', 'physique' ];
	}

	/**
	 * Course : un lien vers wp-config.php est posé à la place d'un fichier que la tranche va écrire,
	 * APRÈS le contrôle de son début (job(), puis journaux ramenés à leur longueur validée : point
	 * d'injection) et avant la production, 20 à 50 s plus tôt sur un vrai site. Lien symbolique (ou
	 * jonction Windows) ou lien physique : rien n'est écrit dans wp-config.php, la tranche est
	 * refusée avec la phrase du lien, la sauvegarde close en échec.
	 */
	#[DataProvider( 'fichiers_ecrits_par_une_tranche' )]
	public function test_lien_pose_apres_le_controle_de_la_tranche_ne_recoit_rien( string $nom, string $nature ): void {
		$cible = dirname( $this->racine ) . '/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		$sonde = dirname( $this->racine ) . '/sonde';
		$pose  = 'lien' === $nature ? self::lien_vers_un_fichier( $cible, $sonde ) : @link( $cible, $sonde ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $pose ) {
			self::markTestSkipped( 'Le système refuse ce lien.' );
		}
		self::retirer_lien( $sonde );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$lien = $this->dossier( self::B1 ) . '/' . $nom;
		\Patchwork\redefine(
			[ WorkDir::class, 'truncate_journals' ],
			// Fermeture liée par Patchwork : jamais statique.
			function () use ( $lien, $cible, $nature ) {
				$fait = \Patchwork\relay();
				if ( file_exists( $lien ) ) {
					unlink( $lien );
				}
				'lien' === $nature ? self::lien_vers_un_fichier( $cible, $lien ) : link( $cible, $lien );
				return $fait;
			}
		);

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : ' . $nom . ' remplacé pendant la tranche.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
			self::assertStringContainsString( 'raccourci', $refus->getMessage(), 'la phrase dit le lien' );
		} finally {
			self::retirer_lien( $lien );
		}
		self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), 'rien écrit dans la cible' );
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	/**
	 * `baseline/` remplacé par un lien entre deux tranches : la sauvegarde en cours est arrêtée,
	 * close en échec, avec la phrase « en cours » du lien ; rien n'est écrit dehors.
	 */
	public function test_lien_pose_entre_deux_tranches_arrete_la_sauvegarde_et_le_dit(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$dehors = dirname( $this->racine ) . '/dehors';
		mkdir( $dehors );
		$lien = WorkDir::baseline_dir();
		rmdir( $lien );
		try {
			self::lien_vers_un_dossier_ou_ignorer( $dehors, $lien );
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
			self::assert_refus_pour_un_lien( $refus, 'La sauvegarde en cours a été arrêtée et ne sera pas terminée : ' );
		} finally {
			self::retirer_lien( $lien );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
		self::assertSame( [], self::contenu( $dehors ), 'rien écrit à travers le lien' );
	}

	/**
	 * Un autre processus pose un lien à la place de `baseline/` juste après la création du dossier
	 * de la sauvegarde par job() (doublure de wp_mkdir_p) : job() refuse après avoir créé ce
	 * dossier ; le départ le retire (vide, créé par lui), sans toucher au lien ni à sa cible.
	 */
	public function test_dossier_cree_par_le_depart_puis_refuse_est_retire(): void {
		self::assertTrue( WorkDir::ensure() );
		$dehors = dirname( $this->racine ) . '/dehors';
		mkdir( $dehors );
		$sonde = dirname( $this->racine ) . '/sonde';
		self::lien_vers_un_dossier_ou_ignorer( $dehors, $sonde );
		self::retirer_lien( $sonde );
		$baseline = WorkDir::baseline_dir();
		$cree     = $this->dossier( self::B1 );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( string $d ) use ( $cree, $baseline, $dehors ): bool {
				$fait = is_dir( $d ) || mkdir( $d, 0777, true );
				if ( $d === $cree ) {
					rmdir( $baseline );
					self::lien_vers_un_dossier( $dehors, $baseline );
				}
				return $fait;
			}
		);
		try {
			self::assert_refus_pour_un_lien( $this->refus_au_depart( self::B1 ), 'La sauvegarde n’a pas été lancée : ' );
			clearstatcache( true );
			self::assertDirectoryDoesNotExist( $cree, 'dossier créé par ce départ retiré' );
			self::assertTrue( is_link( $baseline ) || file_exists( $baseline ), 'le lien reste' );
			self::assertSame( [], self::contenu( $dehors ), 'rien écrit ni retiré à travers le lien' );
			self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
		} finally {
			self::retirer_lien( $baseline );
		}
	}

	/**
	 * Même course, mais c'est le dossier privé qui devient un lien (vers son ancien contenu) juste
	 * après la création du dossier de la sauvegarde : ce dossier ne s'atteint plus qu'à travers le
	 * lien, et le départ refusé ne le retire pas (jamais à travers un lien), sans avertissement.
	 */
	public function test_dossier_cree_puis_refuse_derriere_un_lien_n_est_jamais_retire_a_travers_lui(): void {
		self::assertTrue( WorkDir::ensure() );
		$prive = WorkDir::private_dir();
		$vrai  = dirname( $this->racine ) . '/prive-deplace';
		$sonde = dirname( $this->racine ) . '/sonde';
		mkdir( $vrai );
		self::lien_vers_un_dossier_ou_ignorer( $vrai, $sonde );
		self::retirer_lien( $sonde );
		rmdir( $vrai );
		$cree = $this->dossier( self::B1 );
		Functions\when( 'wp_mkdir_p' )->alias(
			static function ( string $d ) use ( $cree, $prive, $vrai ): bool {
				$fait = is_dir( $d ) || mkdir( $d, 0777, true );
				if ( $d === $cree ) {
					rename( $prive, $vrai );
					self::lien_vers_un_dossier( $vrai, $prive );
				}
				return $fait;
			}
		);
		try {
			self::assert_refus_pour_un_lien( $this->refus_au_depart( self::B1 ), 'La sauvegarde n’a pas été lancée : ' );
			clearstatcache( true );
			self::assertDirectoryExists( $vrai . '/job-' . self::B1, 'jamais retiré à travers le lien' );
			self::assertTrue( is_link( $prive ) || file_exists( $prive ), 'le lien reste' );
		} finally {
			self::retirer_lien( $prive );
		}
	}

	public function test_dossier_de_la_sauvegarde_perdu_entre_deux_tranches_l_arrete_et_le_dit(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		WorkDir::clear_job( self::B1 );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ) );
		Functions\when( 'wp_mkdir_p' )->justReturn( false );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	public function test_segment_impossible_a_ecrire_en_cours_de_sauvegarde_le_dit(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		mkdir( $this->dossier( self::B1 ) . '/seg-0.part' );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : le segment 0 ne s\'ouvre pas en écriture (SegmentProducer).' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	public function test_journal_du_manifeste_impossible_a_ecrire_en_cours_de_sauvegarde_le_dit(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$manifeste = $this->dossier( self::B1 ) . '/manifest.jsonl';
		unlink( $manifeste );
		mkdir( $manifeste );
		$this->poser_etat(
			[
				'journals' => [
					'manifest' => 0,
					'baseline' => 0,
					'segments' => 0,
				],
			]
		);
		// file_put_contents() sur un dossier avertit avant de rendre faux (ManifestJournal::append()).
		set_error_handler( static fn (): bool => true, E_WARNING );
		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : le journal du manifeste ne s\'écrit plus.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		} finally {
			restore_error_handler();
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	public function test_chiffrement_perdu_en_cours_de_copie_le_dit(): void {
		$this->job()->start( self::depart( self::B1 ) );

		try {
			$this->job( null, null, null, self::sans_sodium() )->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : segment impossible à sceller (SegmentProducer).' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::CRYPTO_UNAVAILABLE );
			self::assertStringContainsString( 'Aucun index n’a été envoyé', $refus->getMessage() );
		}
		self::assert_close_en_echec( 'crypto_unavailable' );
	}

	public function test_signature_de_l_index_impossible_le_dit(): void {
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}

		try {
			$this->job( null, null, null, self::sans_sodium() )->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
			self::fail( 'Refus attendu : index impossible à signer.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::CRYPTO_UNAVAILABLE );
			self::assertStringContainsString( 'Aucun index n’a été envoyé', $refus->getMessage() );
		}
		self::assert_close_en_echec( 'crypto_unavailable' );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'aucun index gardé' );
	}

	/**
	 * Le journal du manifeste est remplacé par un lien juste après le contrôle de job() (doublure de
	 * job(), qui rend le dossier comme s'il avait été vérifié avant la pose) : la troncature des
	 * journaux échoue, et le refus le dit avec la phrase du lien, jamais « droits de wp-content ».
	 */
	public function test_journal_remplace_par_un_lien_avant_la_troncature_le_dit(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$cible = dirname( $this->racine ) . '/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		$dossier = $this->dossier( self::B1 );
		$lien    = $dossier . '/manifest.jsonl';
		unlink( $lien );
		try {
			self::lien_vers_un_fichier_ou_ignorer( $cible, $lien );
			\Patchwork\redefine( [ WorkDir::class, 'job' ], static fn (): string => $dossier );
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : journal remplacé par un lien.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
			self::assert_refus_pour_un_lien( $refus, 'La sauvegarde en cours a été arrêtée et ne sera pas terminée : ' );
		} finally {
			self::retirer_lien( $lien );
		}
		self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), 'rien écrit dans la cible' );
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	/**
	 * L'index signé ne se relit plus (retiré entre deux tranches, après sa signature) : jamais un
	 * index vide rendu à la plateforme ; la sauvegarde s'arrête en échec et le dit.
	 */
	public function test_index_illisible_n_est_jamais_rendu_vide(): void {
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::FINALIZE !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		self::assertSame( BackupState::FINALIZE, $rapport['phase'] );
		unlink( $this->dossier( self::B1 ) . '/index.bin' );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : index illisible.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	/**
	 * Course à la signature de l'index : `index.bin` et `index.sig` sont posés comme liens vers
	 * wp-config.php après le contrôle du début de la tranche qui signe. Chacun est écrit dans un
	 * fichier neuf mis à sa place par rename(), qui remplace le lien sans le suivre : wp-config.php
	 * reste intact, et l'index rendu est celui écrit. Une jonction Windows ne se remplace pas par
	 * rename() (la tranche serait alors refusée) : essai mené en CI Linux.
	 */
	public function test_index_signe_ecrit_sans_jamais_suivre_un_lien_pose_pendant_la_tranche(): void {
		$cible = dirname( $this->racine ) . '/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		$sonde = dirname( $this->racine ) . '/sonde';
		self::lien_symbolique_ou_ignorer( $cible, $sonde );
		unlink( $sonde );
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		$dossier = $this->dossier( self::B1 );
		\Patchwork\redefine(
			[ WorkDir::class, 'truncate_journals' ],
			// Fermeture liée par Patchwork : jamais statique.
			function () use ( $dossier, $cible ) {
				$fait = \Patchwork\relay();
				foreach ( [ 'index.bin', 'index.sig' ] as $nom ) {
					symlink( $cible, $dossier . '/' . $nom );
				}
				return $fait;
			}
		);

		$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );

		self::assertSame( BackupState::FINALIZE, $rapport['phase'] );
		self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), 'rien écrit dans la cible' );
		foreach ( [ 'index.bin', 'index.sig' ] as $nom ) {
			self::assertFalse( self::est_un_lien( $dossier . '/' . $nom ), $nom . ' : lien remplacé, jamais suivi' );
		}
		self::assertSame( hash( 'sha256', (string) file_get_contents( $dossier . '/index.bin' ) ), $rapport['index']['sha256'] ?? null, 'index rendu : celui écrit' );
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

	/**
	 * Envoi des parties d'une sauvegarde, tel que BackupJob le monte hors des tests : une partie du
	 * dossier privé est lue et envoyée ; un fichier hors de lui n'est jamais lu (le chemin vient de
	 * la tranche, et une partie remplacée entre-temps par autre chose n'y est jamais cherchée).
	 */
	public function test_l_envoi_par_defaut_ne_lit_une_partie_que_dans_le_dossier_prive(): void {
		$requetes = [];
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			static function ( string $url, array $args ) use ( &$requetes ): array {
				$requetes[] = $args['body'];
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '"etag-1"' );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		$envoi = ( new \ReflectionProperty( BackupJob::class, 'upload' ) )->getValue( new BackupJob( new Sodium(), null, null, null, $this->racine, $this->wpdb ) );
		self::assertInstanceOf( \Closure::class, $envoi );
		self::assertTrue( WorkDir::ensure() );
		mkdir( $this->dossier( self::B1 ) );
		file_put_contents( $this->dossier( self::B1 ) . '/seg-0.seg', 'abcdefghij' );
		$dehors = dirname( $this->racine ) . '/seg-0.seg';
		file_put_contents( $dehors, 'secret!!!!' );
		$url = 'https://stockage.example/g2rd/t/0?X-Amz-Date=20261110T000000Z&X-Amz-Expires=900&X-Amz-Signature=abc';

		self::assertSame( PartUploadResult::UPLOADED, $envoi( $url, 'stockage.example', null, $this->dossier( self::B1 ) . '/seg-0.seg', 0, 10, 30 )->outcome );
		self::assertSame( PartUploadResult::READ_FAILED, $envoi( $url, 'stockage.example', null, $dehors, 0, 10, 30 )->outcome, 'hors du dossier privé : jamais lu' );
		self::assertSame( [ 'abcdefghij' ], $requetes, 'seule la partie du dossier privé est envoyée' );
	}

	/**
	 * Angle mort de la « même seconde » : la complète a lu `a.txt` (« bonjour », 7 octets) à la
	 * seconde M, juste avant son départ, puis le fichier a été réécrit (« BONJOUR », même taille)
	 * dans la même seconde : sa date reste M. État local construit comme la complète l'aurait écrit
	 * (taille 7, date M, empreinte de « bonjour »). La date de l'état local n'est pas antérieure au
	 * départ de la complète moins la marge : l'incrémentale recopie le fichier (stored), sans
	 * dépendre du filtre de vérification des empreintes. Un fichier ancien, lui, reste inchangé.
	 */
	public function test_fichier_reecrit_dans_la_meme_seconde_que_sa_lecture_est_recopie(): void {
		$fichier = $this->racine . '/wp-content/a.txt';
		file_put_contents( $fichier, 'BONJOUR' );
		touch( $fichier, self::NOW - 1 );
		clearstatcache();
		WorkDir::ensure();
		$source = WorkDir::baseline_dir() . '/source.jsonl';
		$lignes = '';
		foreach ( ( new FileWalker( $this->racine, [] ) )->walk( null ) as $entree ) {
			$contenu = 'wp-content/a.txt' === $entree['path'] ? 'bonjour' : null;
			$lignes .= IncrementalBaseline::line( $entree, 'file' === $entree['type'] ? hash( 'sha256', $contenu ?? (string) file_get_contents( $this->racine . '/' . $entree['path'] ) ) : null );
		}
		file_put_contents( $source, $lignes );
		self::assertTrue( ( new IncrementalBaseline( WorkDir::baseline_dir() ) )->promote( $source, self::B1, self::NOW ) );

		$r2     = $this->jouer( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		$lignes = array_column( array_filter( $this->manifeste( $r2['segments'] ), static fn ( array $l ): bool => 'file' === $l['t'] ), null, 'path' );

		self::assertSame( 'stored', $lignes['wp-content/a.txt']['state'], 'même taille, même date, autre contenu : recopié' );
		self::assertSame( hash( 'sha256', 'BONJOUR' ), $lignes['wp-content/a.txt']['sha256'], 'empreinte du contenu réel' );
		self::assertSame( 'unchanged', $lignes['wp-content/uploads/b.jpg']['state'], 'fichier ancien : inchangé' );
	}

	/**
	 * Incrémentale en cours : l'état local de la complète parente disparaît entre deux tranches
	 * (extension de nettoyage, par exemple). Sans lui, l'incrémentale oublierait les suppressions
	 * (une restauration ressusciterait un fichier supprimé) : elle est arrêtée en échec, avec une
	 * phrase vraie, qui ne parle pas de lien.
	 */
	public function test_etat_local_disparu_en_cours_d_incrementale_l_arrete_en_echec(): void {
		$this->etat_local_de_l_arbre();
		unlink( $this->racine . '/index.php' );
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', BackupState::load()['state']['type'] );
		unlink( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl' );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );
	}

	/** Même arrêt quand l'état local a changé de taille depuis le départ (tronqué, réécrit). */
	public function test_etat_local_change_en_cours_d_incrementale_l_arrete_en_echec(): void {
		$this->etat_local_de_l_arbre();
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		file_put_contents( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl', IncrementalBaseline::line( [ 'path' => 'zz.txt', 'type' => 'file', 'size' => 1, 'mtime' => 1 ], null ), FILE_APPEND );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );
	}

	/**
	 * Même arrêt quand l'état local a été réécrit à l'identique de taille : date changée (touché),
	 * ou fichier remplacé par une copie de même taille et même date (autre inode).
	 */
	public function test_etat_local_reecrit_a_taille_egale_en_cours_d_incrementale_l_arrete(): void {
		foreach ( [ 'date changée', 'autre fichier, même date' ] as $cas ) {
			$this->etat_local_de_l_arbre();
			$job = $this->job();
			$id  = 'date changée' === $cas ? self::B2 : str_repeat( '3', 32 );
			$job->start( self::depart( $id, 'files', 'incremental', self::B1 ) );
			$etat_local = WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl';
			clearstatcache();
			$date = (int) filemtime( $etat_local );
			if ( 'date changée' === $cas ) {
				touch( $etat_local, $date - 100 );
			} else {
				copy( $etat_local, $etat_local . '.copie' );
				touch( $etat_local . '.copie', $date );
				rename( $etat_local . '.copie', $etat_local );
			}
			try {
				$job->step( [ 'backup_id' => $id ] );
				self::fail( $cas . ' : refus attendu' );
			} catch ( BackupRefused $refus ) {
				self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
				self::assertStringContainsString( 'la liste des fichiers de la dernière sauvegarde complète, gardée sur le site pour préparer les sauvegardes incrémentales, a disparu ou changé pendant la sauvegarde', $refus->getMessage(), $cas );
			}
			self::assert_close_en_echec( 'backup_work_dir_unavailable' );
		}
	}

	/** Même arrêt quand le décalage de reprise gardé dans l'état dépasse la fin de l'état local. */
	public function test_decalage_de_l_etat_local_au_dela_de_sa_fin_arrete_l_incrementale(): void {
		$this->etat_local_de_l_arbre();
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		$files                    = (array) BackupState::load()['state']['files'];
		$files['baseline_offset'] = 1000000000;
		$this->poser_etat( [ 'files' => $files ] );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'état' );
	}

	/**
	 * Arbre de $fichiers fichiers aux noms longs (pour 300, un état local d'environ 40 Kio, bien plus
	 * grand que le tampon de lecture de PHP, 8 Kio) et `wp-content/zzz-supprime.txt` ; état local de
	 * la complète B1 écrit d'après cet arbre, puis ce fichier supprimé : l'incrémentale doit le dire
	 * `deleted`. Rend le chemin de l'état local.
	 */
	private function etat_local_long_avec_un_fichier_supprime( int $fichiers = 300 ): string {
		for ( $i = 0; $i < $fichiers; $i++ ) {
			$f = $this->racine . sprintf( '/wp-content/f%03d-un-nom-assez-long-pour-grossir-chaque-ligne-de-l-etat-local.txt', $i );
			file_put_contents( $f, 'x' );
			touch( $f, self::NOW - 3600 );
		}
		$supprime = $this->racine . '/wp-content/zzz-supprime.txt';
		file_put_contents( $supprime, 'à supprimer' );
		touch( $supprime, self::NOW - 3600 );
		$this->etat_local_de_l_arbre();
		unlink( $supprime );
		clearstatcache();

		return WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl';
	}

	/**
	 * Horloge monotone réelle qui, aux lectures dont le numéro est une clé de $actions (pendant le
	 * parcours du segment de fichiers : l'horloge est lue après chaque entrée), touche à l'état
	 * local par l'action correspondante.
	 *
	 * @param array<int, \Closure(): void> $actions Numéro de lecture → action.
	 * @param int                          $appels  Lectures faites (rendu pour vérifier que l'action a eu lieu).
	 * @return \Closure(): int
	 */
	private static function horloge_qui_touche_l_etat_local( array $actions, int &$appels ): \Closure {
		$appels = 0;

		return static function () use ( $actions, &$appels ): int {
			++$appels;
			if ( isset( $actions[ $appels ] ) ) {
				$actions[ $appels ]();
			}
			return (int) ( hrtime( true ) / 1000000 );
		};
	}

	/**
	 * État local de la complète parente tronqué sur place (même fichier, même inode) pendant le
	 * dernier segment de fichiers d'une incrémentale — pour une petite incrémentale, presque toute la
	 * sauvegarde. Le lecteur prenait la fin du fichier pour une fin normale : les lignes restantes
	 * n'étaient jamais comparées, le fichier supprimé qu'elles décrivent n'était pas noté, et
	 * l'incrémentale finissait « réussie » (une restauration l'aurait fait revenir). Revérifié après
	 * le segment : arrêtée en échec, phrase de l'état local perdu, jamais `done`.
	 */
	public function test_etat_local_tronque_sur_place_pendant_le_dernier_segment_arrete_l_incrementale(): void {
		$etat_local = $this->etat_local_long_avec_un_fichier_supprime();
		$appels     = 0;
		$ms         = self::horloge_qui_touche_l_etat_local(
			[
				20 => static function () use ( $etat_local ): void {
					$h = fopen( $etat_local, 'r+b' );
					ftruncate( $h, 0 );
					fclose( $h );
				},
			],
			$appels
		);
		$job = $this->job( null, $ms );
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', BackupState::load()['state']['type'], 'état local utilisable au départ' );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );
		self::assertGreaterThanOrEqual( 20, $appels, 'état local tronqué pendant le segment' );
	}

	/**
	 * Même arrêt quand l'état local est réécrit sur place à taille égale pendant le segment (même
	 * inode, même taille, date changée) : la fin du fichier, rendue illisible, était sautée sans être
	 * comparée, et le fichier supprimé qu'elle décrit oublié.
	 */
	public function test_etat_local_reecrit_sur_place_a_taille_egale_pendant_le_segment_arrete_l_incrementale(): void {
		$etat_local = $this->etat_local_long_avec_un_fichier_supprime();
		// Date reculée avant le départ (contenu intact, l'empreinte est la même) : la réécriture ne
		// peut pas tomber dans la même seconde qu'elle.
		touch( $etat_local, self::NOW - 7200 );
		clearstatcache();
		$taille = (int) filesize( $etat_local );
		$appels = 0;
		$ms     = self::horloge_qui_touche_l_etat_local(
			[
				20 => static function () use ( $etat_local, $taille ): void {
					$h = fopen( $etat_local, 'r+b' );
					fseek( $h, 16384 );
					fwrite( $h, str_repeat( ' ', $taille - 16384 - 1 ) );
					fclose( $h );
				},
			],
			$appels
		);
		$job = $this->job( null, $ms );
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', BackupState::load()['state']['type'], 'état local utilisable au départ' );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );
		self::assertGreaterThanOrEqual( 20, $appels, 'état local réécrit pendant le segment' );
		clearstatcache();
		self::assertSame( $taille, filesize( $etat_local ), 'taille égale' );
	}

	/**
	 * Tronqué puis remis à l'identique pendant le segment (même contenu, même taille, même date,
	 * même inode) : à la fin du segment, rien ne le distingue plus du fichier vérifié au départ,
	 * mais le lecteur a pris la troncature pour la fin du fichier, avant sa taille. Arrêt, phrase de
	 * l'état local perdu.
	 */
	public function test_etat_local_tronque_puis_remis_en_etat_pendant_le_segment_arrete_l_incrementale(): void {
		$etat_local = $this->etat_local_long_avec_un_fichier_supprime();
		$contenu    = (string) file_get_contents( $etat_local );
		// Date posée par touch() avant le départ comme à la remise en état : la même des deux côtés,
		// quelle que soit la façon dont la plateforme arrondit une date posée puis relue.
		touch( $etat_local, self::NOW - 7200 );
		clearstatcache();
		$date   = (int) filemtime( $etat_local );
		$appels = 0;
		$ms         = self::horloge_qui_touche_l_etat_local(
			[
				20  => static function () use ( $etat_local ): void {
					$h = fopen( $etat_local, 'r+b' );
					ftruncate( $h, 0 );
					fclose( $h );
				},
				// Bien après la fin du tampon déjà lu (8 Kio, une soixantaine de lignes) : le lecteur a
				// déjà buté sur la fin du fichier tronqué.
				200 => static function () use ( $etat_local, $contenu ): void {
					$h = fopen( $etat_local, 'r+b' );
					fwrite( $h, $contenu );
					fclose( $h );
					touch( $etat_local, self::NOW - 7200 );
				},
			],
			$appels
		);
		$job = $this->job( null, $ms );
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );
		self::assertGreaterThanOrEqual( 200, $appels, 'état local remis en état pendant le segment' );
		clearstatcache();
		self::assertSame( [ $contenu, $date ], [ (string) file_get_contents( $etat_local ), (int) filemtime( $etat_local ) ], 'remis à l’identique' );
	}

	/**
	 * La revérification de l'état local après chaque segment n'arrête jamais une incrémentale
	 * normale : le lire ne change ni sa taille, ni sa date, ni son inode. Incrémentale de plusieurs
	 * segments de fichiers (chaque lecture de l'horloge avance de 1,5 s : une tranche de 20 s ne
	 * parcourt que quelques entrées), état local rouvert à chaque segment et lu jusqu'à sa fin :
	 * finie, suppression notée, date de l'état local inchangée.
	 */
	public function test_incrementale_sur_plusieurs_segments_jamais_arretee_par_la_reverification_de_l_etat_local(): void {
		$etat_local = $this->etat_local_long_avec_un_fichier_supprime( 40 );
		$date       = (int) filemtime( $etat_local );
		file_put_contents( $this->racine . '/wp-content/a.txt', 'bonsoir !' );
		$horloge = 0;
		$job     = $this->job(
			null,
			static function () use ( &$horloge ): int {
				$horloge += 1500;
				return $horloge;
			}
		);
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		$r      = $this->jouer_la_suite( $job, self::B2 );
		$champs = $this->assert_index_valide( $r );

		self::assertSame( 'incremental', $champs['type'] );
		self::assertGreaterThan( 1, count( array_filter( $champs['segments'], static fn ( array $s ): bool => 'files' === $s['kind'] ) ), 'plusieurs segments de fichiers' );
		self::assertTrue( $r['fin']['finished'] );
		self::assertSame( 'done', BackupState::load()['state']['last']['outcome'] );
		$lignes = array_column( array_filter( $this->manifeste( $r['segments'] ), static fn ( array $l ): bool => 'file' === $l['t'] ), null, 'path' );
		self::assertSame( [ 'wp-content/zzz-supprime.txt' ], array_keys( array_filter( $lignes, static fn ( array $l ): bool => 'deleted' === $l['state'] ) ), 'le fichier supprimé, et lui seul' );
		self::assertSame( 'stored', $lignes['wp-content/a.txt']['state'] );
		self::assertSame( 'unchanged', $lignes['wp-content/f039-un-nom-assez-long-pour-grossir-chaque-ligne-de-l-etat-local.txt']['state'] );
		clearstatcache();
		self::assertSame( $date, filemtime( $etat_local ), 'lu, jamais réécrit : date inchangée' );
	}

	/**
	 * Incrémentale commencée par une version précédente du connecteur (état sans la taille, la date
	 * ni l'inode de l'état local, ni le départ de la complète) : arrêtée avec la phrase de l'état ;
	 * l'état local, intact, sert encore : l'incrémentale suivante sur la même complète reste une
	 * incrémentale, comme l'annonce la phrase (« repartira du début »).
	 */
	public function test_incrementale_d_une_version_precedente_arretee_puis_reprise_du_debut(): void {
		$this->etat_local_de_l_arbre();
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		$files = (array) BackupState::load()['state']['files'];
		unset( $files['baseline_size'], $files['baseline_mtime'], $files['baseline_ino'], $files['parent_created_at'] );
		$this->poser_etat( [ 'files' => $files ] );

		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'état' );

		$r3 = $this->jouer( self::depart( str_repeat( '3', 32 ), 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', $this->assert_index_valide( $r3 )['type'] );
		self::assertNull( $r3['rapports'][0]['downgraded'] );
	}

	/**
	 * Ce qu'annonce la phrase de l'état local perdu (« la prochaine sauvegarde revérifiera cette
	 * liste et sera complète si elle n'est plus intacte ») : disparu ou changé de contenu, la
	 * suivante, demandée incrémentale sur la même complète, est une complète et le dit, puis une
	 * incrémentale repart d'elle ; seulement touché (date changée, contenu intact), la suivante
	 * reste une incrémentale.
	 */
	public function test_apres_un_etat_local_perdu_la_suivante_repart_d_une_base_verifiee(): void {
		$b3 = str_repeat( '3', 32 );
		$b4 = str_repeat( '4', 32 );
		$this->etat_local_de_l_arbre();
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		unlink( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl' );
		$this->assert_incrementale_arretee_sans_son_etat_local( $job, 'perdu' );

		$r3 = $this->jouer( self::depart( $b3, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'full', $this->assert_index_valide( $r3 )['type'], 'disparu : complète' );
		self::assertSame( 'baseline_unreadable', $r3['rapports'][0]['downgraded'], 'et le dit' );
		$r4 = $this->jouer( self::depart( $b4, 'files', 'incremental', $b3 ) );
		self::assertSame( 'incremental', $this->assert_index_valide( $r4 )['type'], 'puis incrémentale sur la nouvelle complète' );

		$b5 = str_repeat( '5', 32 );
		$this->job()->start( self::depart( $b5, 'files', 'incremental', $b3 ) );
		$etat_local = WorkDir::baseline_dir() . '/' . $b3 . '.jsonl';
		clearstatcache();
		touch( $etat_local, (int) filemtime( $etat_local ) - 100 );
		try {
			$this->job()->step( [ 'backup_id' => $b5 ] );
			self::fail( 'Refus attendu : état local touché.' );
		} catch ( BackupRefused $refus ) {
			self::assertStringContainsString( 'la liste des fichiers de la dernière sauvegarde complète, gardée sur le site pour préparer les sauvegardes incrémentales, a disparu ou changé pendant la sauvegarde', $refus->getMessage() );
		}
		$r6 = $this->jouer( self::depart( str_repeat( '6', 32 ), 'files', 'incremental', $b3 ) );
		self::assertSame( 'incremental', $this->assert_index_valide( $r6 )['type'], 'touché, contenu intact : incrémentale' );
	}

	/**
	 * Tranche suivante refusée avec la phrase vraie (« perdu » : liste de la dernière complète
	 * disparue ou changée ; « état » : avancement incomplet ou incohérent), jamais celle d'un lien ni
	 * celle des droits du dossier ; sauvegarde close en échec, jamais finie.
	 */
	private function assert_incrementale_arretee_sans_son_etat_local( BackupJob $job, string $cause ): void {
		try {
			$job->step( [ 'backup_id' => (string) BackupState::load()['state']['backup_id'] ] );
			self::fail( 'Refus attendu : une incrémentale sans son état local oublierait les suppressions.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
			self::assertStringContainsString( 'perdu' === $cause ? 'la liste des fichiers de la dernière sauvegarde complète, gardée sur le site pour préparer les sauvegardes incrémentales, a disparu ou changé pendant la sauvegarde' : 'l’avancement de sa comparaison avec la dernière sauvegarde complète, gardé sur le site entre deux étapes, est incomplet ou incohérent', $refus->getMessage() );
			self::assertStringNotContainsString( 'raccourci', $refus->getMessage(), 'cause qui n’est pas un lien : la phrase n’en parle pas' );
			self::assertStringNotContainsString( 'droits du dossier', $refus->getMessage(), 'jamais la cause des droits' );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}

	/**
	 * Incrémentale en cours : l'état local de la complète parente est remplacé, entre deux tranches,
	 * par un lien vers une copie extérieure identique. Il n'est jamais lu à travers le lien : la
	 * sauvegarde est arrêtée et le dit, close en échec.
	 */
	public function test_etat_local_remplace_par_un_lien_en_cours_d_incrementale_l_arrete_sans_le_lire(): void {
		$this->etat_local_de_l_arbre();
		$job = $this->job();
		$job->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', BackupState::load()['state']['type'], 'état local utilisable au départ' );
		$etat_local = WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl';
		$copie      = dirname( $this->racine ) . '/etat-local-dehors.jsonl';
		rename( $etat_local, $copie );
		try {
			self::lien_vers_un_fichier_ou_ignorer( $copie, $etat_local );
			$job->step( [ 'backup_id' => self::B2 ] );
			self::fail( 'Refus attendu : état local lu à travers un lien.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
		} finally {
			self::retirer_lien( $etat_local );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
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

	/** Passe un dossier du site d'essai ('' : sa racine) en 0000 ; saute l'essai si ce retrait reste sans effet. */
	private function retirer_droits( string $relatif ): void {
		self::sauter_sans_droits_posix();
		$dossier                = '' === $relatif ? $this->racine : $this->racine . '/' . $relatif;
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

	public function test_racine_refusee_au_depart_sans_rien_laisser(): void {
		self::sauter_sans_droits_posix();
		$this->retirer_droits( '' );

		try {
			$this->job( self::base_du_site() )->start( self::depart( self::B1, 'both' ) );
			self::fail( 'Refus attendu : racine du site en 0000.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::FILES_ROOT_UNREADABLE, $refus->reason_code() );
			self::assertNull( $refus->retry_after(), 'non réessayable : les droits ne reviennent pas seuls' );
			self::assertStringContainsString( 'dossier racine du site', $refus->getMessage() );
		}
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'refusée avant la copie de la base : aucun dossier de sauvegarde laissé' );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );

		$this->job( self::base_du_site() )->start( self::depart( self::B2, 'db' ) );
		self::assertSame( BackupState::DB, BackupState::load()['state']['phase'], 'la base seule ne lit pas la racine : pas refusée' );
	}

	public function test_racine_devenue_illisible_en_cours_de_sauvegarde_l_arrete_sans_la_dire_finie(): void {
		self::sauter_sans_droits_posix();
		// Réparti sur trois segments de 16 Mio : la première tranche (deux segments au plus en
		// attente) copie déjà index.php, wp-content et a.txt, et s'arrête dans gros.bin.
		file_put_contents( $this->racine . '/wp-content/gros.bin', random_bytes( 40 * 1048576 ) );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$premiere = $job->step( [ 'backup_id' => self::B1 ] );
		self::assertCount( 2, $premiere['pending'] );
		$this->retirer_droits( '' );

		try {
			$job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $premiere['pending'], 'segment' ) ] );
			self::fail( 'Refus attendu : racine du site passée en 0000 entre deux tranches.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::FILES_ROOT_UNREADABLE, $refus->reason_code() );
			self::assertNull( $refus->retry_after() );
		}
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::IDLE, 'failed', 'backup_files_root_unreadable' ], [ $etat['phase'], $etat['last']['outcome'], $etat['last']['code'] ], 'arrêtée en échec, jamais dite finie avec un illisible de plus' );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'segments et journaux retirés' );
	}

	/**
	 * Joue la plateforme jusqu'à la fin.
	 *
	 * @param array<string, mixed> $depart
	 * @return array{segments: array<int, string>, index: array<string, mixed>, fin: array<string, mixed>, rapports: list<array<string, mixed>>}
	 */
	private function jouer( array $depart, ?object $wpdb = null ): array {
		$job      = $this->job( $wpdb );
		$rapport  = $job->start( $depart );
		$rapports = [ $rapport ];
		$segments = [];
		$termines = [];
		for ( $tour = 0; 'finalize' !== $rapport['phase'] && $tour < 50; $tour++ ) {
			$envois = [];
			foreach ( $rapport['pending'] as $p ) {
				if ( count( $p['uploaded'] ) === count( $p['parts'] ) ) {
					$termines[] = $p['segment'];
					continue;
				}
				$segments[ $p['segment'] ] = (string) file_get_contents( $this->dossier( $depart['backup_id'] ) . '/seg-' . $p['segment'] . '.seg' );
				$envois[]                  = [
					'segment' => $p['segment'],
					'parts'   => array_map(
						static fn ( array $partie ): array => [
							'part_number' => $partie['part_number'],
							'url'         => 'https://stockage.test/b/k?partNumber=' . $partie['part_number'] . '&X-Amz-Signature=x',
							'host'        => 'stockage.test',
							'port'        => null,
						],
						$p['parts']
					),
				];
			}
			$rapport    = $job->step(
				[
					'backup_id' => $depart['backup_id'],
					'uploads'   => $envois,
					'completed' => $termines,
				]
			);
			$rapports[] = $rapport;
			$termines   = [];
		}
		self::assertSame( 'finalize', $rapport['phase'] );
		$fin = $job->step(
			[
				'backup_id' => $depart['backup_id'],
				'finished'  => true,
			]
		);

		return [
			'segments' => $segments,
			'index'    => (array) $rapport['index'],
			'fin'      => $fin,
			'rapports' => $rapports,
		];
	}

	/**
	 * @param array<int, string> $segments
	 * @return list<array<string, mixed>>
	 */
	private function manifeste( array $segments ): array {
		$texte = '';
		foreach ( $segments as $segment ) {
			foreach ( $this->enregistrements( $segment ) as $e ) {
				if ( 'manifest' === $e['type'] ) {
					$texte .= $e['data'];
				}
			}
		}
		return array_map( static fn ( string $l ): array => json_decode( $l, true ), array_values( array_filter( explode( "\n", $texte ) ) ) );
	}

	public function test_sauvegarde_complete_des_fichiers_de_bout_en_bout(): void {
		$r = $this->jouer( self::depart( self::B1 ) );

		$index = (string) hex2bin( $r['index']['chunk'] );
		self::assertSame( $r['index']['bytes'], strlen( $index ), 'index entier en un morceau' );
		self::assertTrue( BackupIndex::verify( $index, (string) hex2bin( $r['index']['signature'] ), (string) SigningKey::public_key(), new Sodium() ) );
		$champs = BackupIndex::parse( $index );
		self::assertSame( [ 'files', 'manifest' ], array_column( $champs['segments'], 'kind' ) );
		foreach ( $champs['segments'] as $s ) {
			self::assertSame( hash( 'sha256', $r['segments'][ $s['index'] ] ), $s['sha256'], 'empreinte de l\'objet entier' );
		}
		$fichiers = array_column( array_filter( $this->enregistrements( $r['segments'][0] ), static fn ( array $e ): bool => 'file' === $e['type'] ), 'data', 'path' );
		self::assertSame( 'bonjour', $fichiers['wp-content/a.txt'] );
		self::assertSame( "<?php // accueil\n", $fichiers['index.php'] );
		$manifeste = $this->manifeste( $r['segments'] );
		self::assertSame( self::CLES_HEAD, array_keys( $manifeste[0] ), 'ligne head du contrat' );
		self::assertSame( 'end', end( $manifeste )['t'] );
		self::assertTrue( $r['fin']['finished'] );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
		self::assertSame( 'done', BackupState::load()['state']['last']['outcome'] );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'dossier de la sauvegarde retiré' );
		self::assertFileExists( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl', 'état local promu' );
		self::assertNotEmpty( $this->envois );
	}

	/**
	 * Décision n° 4 du point de contrôle n° 2 de la phase 4 : un backup_start rejoué avec
	 * l'identifiant de la sauvegarde terminée est refusé ; son résultat `done` reste, rien n'est créé
	 * ni écrit (avant : repartie de zéro, et le `done` écrasé par l'issue suivante).
	 */
	public function test_backup_start_d_une_sauvegarde_terminee_est_refuse_sans_rien_ecrire(): void {
		$this->jouer( self::depart( self::B1 ) );
		$avant               = BackupState::load()['raw'];
		$options             = $this->options;
		$lignes              = $this->wpdb->tables;
		$this->wpdb->queries = [];

		try {
			$this->job()->start( self::depart( self::B1 ) );
			self::fail( 'Refus attendu : sauvegarde déjà terminée sous ce numéro.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::ALREADY_FINISHED, $refus->reason_code() );
			self::assertNull( $refus->retry_after() );
		}
		self::assertSame( $avant, BackupState::load()['raw'], 'état intact' );
		self::assertSame( [ BackupState::IDLE, 'done', self::B1 ], [ BackupState::load()['state']['phase'], BackupState::load()['state']['last']['outcome'], BackupState::load()['state']['last']['backup_id'] ] );
		self::assertSame( $options, $this->options, 'aucune option écrite (clés comprises)' );
		self::assertSame( $lignes, $this->wpdb->tables, 'aucune ligne écrite' );
		self::assertSame( [], $this->wpdb->locks_and_writes(), 'ni verrou ni écriture' );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'aucun dossier de sauvegarde créé' );
	}

	/** Arrêtée ou en échec : le même numéro repart du début, comme avant (choix de la décision n° 4). */
	public function test_backup_start_d_une_sauvegarde_arretee_repart_comme_avant(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$job->abort( [ 'backup_id' => self::B1 ] );

		$rapport = $job->start( self::depart( self::B1 ) );

		self::assertSame( BackupState::FILES, $rapport['phase'] );
		self::assertSame( [ 'aborted', self::B1 ], [ BackupState::load()['state']['last']['outcome'], BackupState::load()['state']['last']['backup_id'] ] );
		self::assertFileExists( $this->dossier( self::B1 ) . '/manifest.jsonl', 'repartie du début' );
	}

	/**
	 * Décision n° 4 du point de contrôle n° 2 de la phase 4 (comportement gardé) : close en échec
	 * après une tranche (journal du manifeste perdu), la sauvegarde repart du début sous le même
	 * numéro ; jamais refusée backup_already_finished.
	 */
	public function test_backup_start_d_une_sauvegarde_en_echec_repart_du_debut(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$job->step( [ 'backup_id' => self::B1 ] );
		self::assertGreaterThan( 0, BackupState::load()['state']['segments'], 'la sauvegarde a avancé avant son échec' );
		file_put_contents( $this->dossier( self::B1 ) . '/manifest.jsonl', '' );
		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu : journal du manifeste perdu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
		}
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );

		$rapport = $job->start( self::depart( self::B1 ) );

		$this->assert_repartie_du_debut( $rapport, 'failed' );
	}

	/** Même décision : arrêtée par la plateforme après une tranche, elle repart du début aussi. */
	public function test_backup_start_d_une_sauvegarde_arretee_apres_une_tranche_repart_du_debut(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$job->step( [ 'backup_id' => self::B1 ] );
		self::assertGreaterThan( 0, BackupState::load()['state']['segments'], 'la sauvegarde a avancé avant son arrêt' );
		self::assertTrue( $job->abort( [ 'backup_id' => self::B1 ] )['aborted'] );
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::IDLE, 'aborted', 'aborted_by_platform' ], [ $etat['phase'], $etat['last']['outcome'], $etat['last']['code'] ] );

		$rapport = $job->start( self::depart( self::B1 ) );

		$this->assert_repartie_du_debut( $rapport, 'aborted' );
	}

	/**
	 * Même numéro reparti du début : état neuf (aucun segment ni envoi en attente, curseur au début,
	 * totaux à zéro), manifeste réduit à sa ligne head, aucun segment de la tentative d'avant ;
	 * l'issue de celle-ci reste la dernière connue.
	 *
	 * @param array<string, mixed> $rapport
	 */
	private function assert_repartie_du_debut( array $rapport, string $issue ): void {
		self::assertSame( BackupState::FILES, $rapport['phase'] );
		$etat = BackupState::load()['state'];
		self::assertSame( [ BackupState::FILES, self::B1, 0, [] ], [ $etat['phase'], $etat['backup_id'], $etat['segments'], $etat['pending'] ] );
		self::assertSame(
			[
				'cursor_hex'      => null,
				'offset'          => 0,
				'current'         => null,
				'baseline_offset'   => null,
				'baseline_size'     => null,
				'baseline_mtime'    => null,
				'baseline_ino'      => null,
				'parent_created_at' => null,
				'done'              => false,
			],
			$etat['files']
		);
		self::assertSame(
			[
				'db_tables'     => 0,
				'db_rows'       => 0,
				'files'         => 0,
				'payload_bytes' => 0,
				'unreadable'    => 0,
			],
			$etat['totals']
		);
		self::assertSame( self::B1, $this->tete( self::B1 )['backup_id'], 'manifeste réduit à sa ligne head' );
		self::assertSame( [ 'manifest.jsonl' ], array_values( array_diff( (array) scandir( $this->dossier( self::B1 ) ), [ '.', '..' ] ) ), 'aucun segment ni journal de la tentative d’avant' );
		self::assertSame( [ $issue, self::B1 ], [ $etat['last']['outcome'], $etat['last']['backup_id'] ], 'issue de la tentative d’avant gardée' );
	}

	/**
	 * Départ refusé ; rend le refus.
	 */
	private function refus_au_depart( string $id ): BackupRefused {
		try {
			$this->job()->start( self::depart( $id ) );
		} catch ( BackupRefused $refus ) {
			return $refus;
		}
		self::fail( 'Refus attendu au départ.' );
	}

	/** Disque insuffisant au départ : le dossier que ce départ vient de créer est retiré. */
	public function test_disque_insuffisant_au_depart_retire_le_dossier_cree_par_ce_depart(): void {
		\Patchwork\redefine( [ DiskSpace::class, 'free_bytes' ], static fn (): int => 1 );

		self::assertSame( BackupRefused::DISK_LOW, $this->refus_au_depart( self::B1 )->reason_code() );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'dossier créé par ce départ retiré' );
		self::assertDirectoryExists( WorkDir::private_dir(), 'dossier de travail du module gardé' );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	/** Journal du manifeste impossible à écrire au départ : le dossier créé (journal entamé compris) est retiré. */
	public function test_journal_impossible_a_ecrire_au_depart_retire_le_dossier_cree_par_ce_depart(): void {
		$manifeste = $this->dossier( self::B1 ) . '/manifest.jsonl';
		\Patchwork\redefine(
			[ ManifestJournal::class, 'append' ],
			// Fermeture liée par Patchwork au journal appelé : jamais statique.
			function () use ( $manifeste ): void {
				file_put_contents( $manifeste, '{"t":"he' );
				throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE );
			}
		);

		$refus = $this->refus_au_depart( self::B1 );

		self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
		self::assertStringStartsWith( 'La sauvegarde n’a pas été lancée : ', $refus->getMessage(), 'refus d’origine rendu tel quel' );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'dossier créé par ce départ retiré' );
		self::assertSame( BackupState::IDLE, BackupState::load()['state']['phase'] );
	}

	/**
	 * Refus au départ : jamais un dossier déjà là n'est touché, ni celui d'une autre sauvegarde
	 * (ses fichiers du module compris), ni celui du même numéro que le nettoyage n'a pas pu vider
	 * (fichier étranger).
	 */
	public function test_refus_au_depart_ne_touche_jamais_un_dossier_deja_la(): void {
		self::assertTrue( WorkDir::ensure() );
		$autre = $this->dossier( self::B2 );
		mkdir( $autre );
		file_put_contents( $autre . '/manifest.jsonl', "{\"t\":\"head\"}\n" );
		file_put_contents( $autre . '/seg-0.seg', 'segment' );
		mkdir( $this->dossier( self::B1 ) );
		file_put_contents( $this->dossier( self::B1 ) . '/etranger.txt', 'pas au module' );
		\Patchwork\redefine( [ DiskSpace::class, 'free_bytes' ], static fn (): int => 1 );

		self::assertSame( BackupRefused::DISK_LOW, $this->refus_au_depart( self::B1 )->reason_code() );
		self::assertSame( [ 'manifest.jsonl', 'seg-0.seg' ], array_values( array_diff( (array) scandir( $autre ), [ '.', '..' ] ) ), 'dossier d’une autre sauvegarde intact' );
		self::assertSame( 'segment', file_get_contents( $autre . '/seg-0.seg' ) );
		self::assertSame( [ 'etranger.txt' ], array_values( array_diff( (array) scandir( $this->dossier( self::B1 ) ), [ '.', '..' ] ) ), 'dossier déjà là gardé, fichier étranger compris' );
	}

	/** Retrait impossible : le refus d'origine reste le résultat, sans autre erreur ni avertissement. */
	public function test_retrait_impossible_au_depart_le_refus_d_origine_reste(): void {
		$manifeste = $this->dossier( self::B1 ) . '/manifest.jsonl';
		\Patchwork\redefine(
			[ ManifestJournal::class, 'append' ],
			// Fermeture liée par Patchwork au journal appelé : jamais statique.
			function () use ( $manifeste ): void {
				file_put_contents( $manifeste, '{"t":"he' );
				throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE );
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function (): void {
				throw new \RuntimeException( 'Retrait refusé (doublure du test).' );
			}
		);

		$refus = $this->refus_au_depart( self::B1 );

		self::assertSame( BackupRefused::WORK_DIR_UNAVAILABLE, $refus->reason_code() );
		self::assertStringStartsWith( 'La sauvegarde n’a pas été lancée : ', $refus->getMessage(), 'refus d’origine rendu tel quel' );
		self::assertFileExists( $manifeste, 'retrait impossible : le dossier reste, sans autre erreur' );
	}

	public function test_complete_a_la_fin_de_tranche_perdue_puis_incrementale_sans_suppression_fausse(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$this->wpdb->before_write = function (): void {
			$this->wpdb->before_write = function (): void {
				$this->wpdb->write_fails = true;
			};
		};
		try {
			$job->step( [ 'backup_id' => self::B1 ] );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		}
		$this->wpdb->write_fails = false;
		$this->abandonner_puis_jouer_la_suite( $job, self::B1 );

		$r = $this->jouer( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		self::assertSame( 'incremental', BackupIndex::parse( (string) hex2bin( $r['index']['chunk'] ) )['type'], 'état local de la complète utilisable' );
		$supprimes = array_filter( $this->manifeste( $r['segments'] ), static fn ( array $l ): bool => 'file' === $l['t'] && 'deleted' === $l['state'] );
		self::assertSame( [], array_values( $supprimes ), 'rien n\'a été supprimé du site : aucun « deleted »' );
		foreach ( $r['segments'] as $segment ) {
			self::assertSame( [], array_filter( $this->enregistrements( $segment ), static fn ( array $e ): bool => 'deleted' === $e['type'] ) );
		}
	}

	/**
	 * Termine une sauvegarde déjà commencée, la plateforme jouée comme dans jouer().
	 */
	private function abandonner_puis_jouer_la_suite( BackupJob $job, string $id ): void {
		$rapport = $job->step( [ 'backup_id' => $id ] );
		for ( $tour = 0; 'finalize' !== $rapport['phase'] && $tour < 50; $tour++ ) {
			$envois   = [];
			$termines = [];
			foreach ( $rapport['pending'] as $p ) {
				if ( count( $p['uploaded'] ) === count( $p['parts'] ) ) {
					$termines[] = $p['segment'];
					continue;
				}
				$envois[] = [ 'segment' => $p['segment'], 'parts' => array_map( static fn ( array $partie ): array => [ 'part_number' => $partie['part_number'], 'url' => 'https://stockage.test/x', 'host' => 'stockage.test', 'port' => null ], $p['parts'] ) ];
			}
			$rapport = $job->step( [ 'backup_id' => $id, 'uploads' => $envois, 'completed' => $termines ] );
		}
		self::assertTrue( $job->step( [ 'backup_id' => $id, 'finished' => true ] )['finished'] );
	}

	public function test_sauvegarde_de_la_base_seule(): void {
		$base                       = new FakeDumpWpdb();
		$base->tables['wp_options'] = [
			'columns' => [ 'option_id' => 'bigint(20)', 'option_name' => 'varchar(191)', 'option_value' => 'longtext' ],
			'pk'      => [ 'option_id' ],
			'rows'    => [ [ 'option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'https://exemple.test' ] ],
		];

		$r = $this->jouer( self::depart( self::B1, 'db' ), $base );

		self::assertSame( [ 'db', 'manifest' ], array_column( BackupIndex::parse( (string) hex2bin( $r['index']['chunk'] ) )['segments'], 'kind' ) );
		$sql = implode( '', array_column( $this->enregistrements( $r['segments'][0] ), 'data' ) );
		self::assertStringContainsString( "('1','siteurl','https://exemple.test')", $sql );
		self::assertSame( [], array_filter( $this->manifeste( $r['segments'] ), static fn ( array $l ): bool => 'file' === $l['t'] ), 'aucune ligne de fichier' );
		self::assertFileDoesNotExist( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl', 'base seule : état local inchangé' );
	}

	public function test_une_mise_a_jour_pendant_la_copie_interrompt_et_nettoie(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		BackupState::abort_if_capturing( 'interrupted_by_update', self::NOW );

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::INTERRUPTED, $refus->reason_code() );
			self::assertStringContainsString( 'une mise à jour a changé le site pendant la copie', $refus->getMessage() );
		}
		$etat = BackupState::load()['state'];
		self::assertSame( BackupState::IDLE, $etat['phase'] );
		self::assertSame( [ 'aborted', 'interrupted_by_update' ], [ $etat['last']['outcome'], $etat['last']['code'] ] );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ) );
	}

	public function test_une_mise_a_jour_sans_verrou_vivante_a_la_fin_de_la_copie_interrompt(): void {
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		// Une mise à jour de l'administration commence pendant la tranche : son entrée « sans
		// verrou » est posée, mais son écriture d'abort n'a pas pu passer.
		$this->wpdb->before_write = static function (): void {
			UpdateWitness::add( 'upgrader', false, time() );
		};

		try {
			$job->step( [ 'backup_id' => self::B1 ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::INTERRUPTED, $refus->reason_code() );
		}
		self::assertSame( 'interrupted_by_update', BackupState::load()['state']['last']['code'], 'jamais une copie finie pendant une mise à jour' );
	}

	public function test_autotest_en_echec_juste_avant_la_signature_porte_l_etape_index(): void {
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		$this->autotest_faux = true;

		try {
			$job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
			self::fail( 'Refus attendu.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( [ BackupRefused::SELF_TEST_FAILED, 'index' ], [ $refus->reason_code(), $refus->stage() ] );
			self::assertSame( 'index', $refus->outcome()['stage'] );
		}
		$dernier = BackupState::load()['state']['last'];
		self::assertSame( [ 'failed', 'self_test_failed', 'index' ], [ $dernier['outcome'], $dernier['code'], $dernier['stage'] ] );
		self::assertDirectoryDoesNotExist( $this->dossier( self::B1 ), 'aucun index gardé' );
	}

	public function test_le_rapport_reste_sous_la_borne_d_un_compte_rendu(): void {
		file_put_contents( $this->racine . '/wp-content/gros.bin', random_bytes( 40 * 1048576 ) );

		$r = $this->jouer( self::depart( self::B1 ) );

		foreach ( $r['rapports'] as $rapport ) {
			self::assertLessThanOrEqual( BackupJob::REPORT_MAX_BYTES, strlen( (string) json_encode( $rapport ) ) );
			self::assertLessThanOrEqual( BackupJob::MAX_PENDING, count( $rapport['pending'] ) );
		}
		self::assertGreaterThan( 2, count( $r['segments'] ), 'un fichier de 40 Mio réparti sur plusieurs segments de 16 Mio' );
	}

	public function test_complete_puis_incrementale_un_dossier_passe_en_0000_n_est_jamais_dit_supprime(): void {
		self::sauter_sans_droits_posix();
		$this->dossier_prive();
		// Complète jouée de bout en bout : son état local est celui que finish() promeut.
		$this->jouer( self::depart( self::B1 ) );
		$this->retirer_droits( 'wp-content/prive' );

		$r = $this->jouer( self::depart( self::B2, 'files', 'incremental', self::B1 ) );

		self::assertSame( 'incremental', BackupIndex::parse( (string) hex2bin( $r['index']['chunk'] ) )['type'], 'état local promu par la complète' );
		$manifeste = $this->manifeste( $r['segments'] );
		$fichiers  = array_values( array_filter( $manifeste, static fn ( array $l ): bool => 'file' === $l['t'] ) );
		self::assertSame( [], array_values( array_filter( $fichiers, static fn ( array $l ): bool => 'deleted' === $l['state'] ) ), 'rien n\'est dit supprimé sous le dossier passé en 0000' );
		self::assertSame( [], preg_grep( '#^\S+ wp-content/prive(/|$)#', $this->chemins_ecrits( $r['segments'] ) ), 'rien d\'écrit pour le dossier refusé, ni « deleted » ni contenu' );
		$prive = array_values( array_filter( $fichiers, static fn ( array $l ): bool => str_starts_with( (string) ( $l['path'] ?? '' ), 'wp-content/prive' ) ) );
		self::assertSame( [ 'wp-content/prive', 'wp-content/prive/deux.txt', 'wp-content/prive/sous', 'wp-content/prive/sous/trois.txt', 'wp-content/prive/un.txt' ], array_column( $prive, 'path' ) );
		self::assertSame( [ 'unreadable' ], array_values( array_unique( array_column( $prive, 'state' ) ) ), 'le dossier et les entrées de l\'état local sous lui : illisibles' );
		self::assertSame( 5, end( $manifeste )['unreadable'], 'ligne de fin du manifeste : le dossier et les quatre entrées de l\'état local sous lui' );
		self::assertSame( 5, $r['fin']['totals']['unreadable'] );
	}

	// ── Reprises après une coupure (scénarios de la relecture du tour 2) ──────

	/**
	 * Joue la plateforme à partir d'une sauvegarde déjà commencée, jusqu'à la fin.
	 *
	 * @return array{segments: array<int, string>, index: array<string, mixed>, fin: array<string, mixed>}
	 */
	private function jouer_la_suite( BackupJob $job, string $id ): array {
		$rapport  = $job->step( [ 'backup_id' => $id ] );
		$segments = [];
		for ( $tour = 0; 'finalize' !== $rapport['phase'] && $tour < 50; $tour++ ) {
			$envois   = [];
			$termines = [];
			foreach ( $rapport['pending'] as $p ) {
				if ( count( $p['uploaded'] ) === count( $p['parts'] ) ) {
					$termines[] = $p['segment'];
					continue;
				}
				$segments[ $p['segment'] ] = (string) file_get_contents( $this->dossier( $id ) . '/seg-' . $p['segment'] . '.seg' );
				$envois[]                  = [
					'segment' => $p['segment'],
					'parts'   => array_map(
						static fn ( array $partie ): array => [
							'part_number' => $partie['part_number'],
							'url'         => 'https://stockage.test/x',
							'host'        => 'stockage.test',
							'port'        => null,
						],
						$p['parts']
					),
				];
			}
			$rapport = $job->step( [ 'backup_id' => $id, 'uploads' => $envois, 'completed' => $termines ] );
		}
		self::assertSame( 'finalize', $rapport['phase'] );
		$fin = $job->step( [ 'backup_id' => $id, 'finished' => true ] );

		return [
			'segments' => $segments,
			'index'    => (array) $rapport['index'],
			'fin'      => $fin,
		];
	}

	/**
	 * Index rendu signé et valide, et chaque segment qu'il cite a l'empreinte annoncée.
	 *
	 * @param array{segments: array<int, string>, index: array<string, mixed>} $r
	 * @return array<string, mixed> Champs de l'index.
	 */
	private function assert_index_valide( array $r ): array {
		$index = (string) hex2bin( (string) $r['index']['chunk'] );
		self::assertSame( $r['index']['bytes'], strlen( $index ) );
		self::assertTrue( BackupIndex::verify( $index, (string) hex2bin( (string) $r['index']['signature'] ), (string) SigningKey::public_key(), new Sodium() ), 'index signé valide' );
		$champs = BackupIndex::parse( $index );
		foreach ( $champs['segments'] as $s ) {
			self::assertSame( hash( 'sha256', $r['segments'][ $s['index'] ] ), $s['sha256'], 'segment ' . $s['index'] . ' : empreinte' );
		}

		return $champs;
	}

	/**
	 * Tranche tuée par le serveur pendant la production : `seg-0.seg` déjà renommé, `seg-1.part`
	 * ordinaire laissé, lignes non validées au bout des trois journaux. La tranche suivante reprend :
	 * le `.part` laissé est retiré (fichier ordinaire), la sauvegarde finit, index valide, aucune
	 * ligne fantôme, état local promu, incrémentale possible.
	 */
	public function test_tranche_tuee_puis_reprise_jusqu_a_la_fin_puis_incrementale(): void {
		// Écrit juste avant la complète : lu par elle dans la même seconde, ou presque.
		file_put_contents( $this->racine . '/wp-content/a.txt', 'bonjour' );
		$job = $this->job();
		$job->start( self::depart( self::B1 ) );
		$d = $this->dossier( self::B1 );
		file_put_contents( $d . '/seg-0.seg', random_bytes( 2048 ) );
		file_put_contents( $d . '/seg-1.part', random_bytes( 4096 ) );
		file_put_contents( $d . '/manifest.jsonl', "{\"t\":\"file\",\"path\":\"fantome\"}\n", FILE_APPEND );
		file_put_contents( $d . '/baseline-new.jsonl', "{\"p\":\"fantome\",\"k\":\"file\",\"s\":1,\"m\":1,\"h\":null}\n", FILE_APPEND );
		file_put_contents( $d . '/segments.jsonl', "{\"index\":0,\"kind\":\"files\",\"blocks\":1,\"bytes\":1,\"sha256\":\"00\"}\n", FILE_APPEND );

		$r      = $this->jouer_la_suite( $job, self::B1 );
		$champs = $this->assert_index_valide( $r );

		self::assertSame( [ 'files', 'manifest' ], array_column( $champs['segments'], 'kind' ) );
		self::assertTrue( $r['fin']['finished'] );
		self::assertSame( 'done', BackupState::load()['state']['last']['outcome'] );
		foreach ( $this->manifeste( $r['segments'] ) as $l ) {
			self::assertNotSame( 'fantome', $l['path'] ?? null, 'aucune ligne non validée dans le manifeste' );
		}
		self::assertDirectoryDoesNotExist( $d, 'dossier retiré' );
		$etat_local = (string) file_get_contents( WorkDir::baseline_dir() . '/' . self::B1 . '.jsonl' );
		self::assertStringNotContainsString( 'fantome', $etat_local );
		$courant = (array) json_decode( (string) file_get_contents( WorkDir::baseline_dir() . '/current.json' ), true );
		self::assertSame( [ self::B1, substr_count( $etat_local, "\n" ), hash( 'sha256', $etat_local ) ], [ $courant['backup_id'], $courant['entries'], $courant['sha256'] ] );

		// Même taille (« bonjour » et « changé » font 7 octets), souvent même seconde, donc même
		// date : recopié quand même, sa date n'étant pas antérieure au départ de la complète.
		file_put_contents( $this->racine . '/wp-content/a.txt', 'changé' );
		$r2 = $this->jouer( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		$c2 = $this->assert_index_valide( $r2 );
		self::assertSame( 'incremental', $c2['type'] );
		$lignes = array_column( array_filter( $this->manifeste( $r2['segments'] ), static fn ( array $l ): bool => 'file' === $l['t'] ), 'state', 'path' );
		self::assertSame( 'stored', $lignes['wp-content/a.txt'] );
		self::assertSame( 'unchanged', $lignes['wp-content/uploads/b.jpg'] );
		self::assertNotContains( 'deleted', $lignes );
	}

	/**
	 * Tranche tuée entre la création d'un fichier neuf (replace_private()) et son rename() :
	 * `index.bin.<hex>.tmp`, `index.sig.<hex>.tmp` dans le dossier de la sauvegarde,
	 * `current.json.<hex>.tmp` dans baseline/. La sauvegarde se finit, le dossier est retiré avec
	 * ses fichiers neufs, celui de baseline/ aussi, et l'incrémentale suivante reste possible.
	 */
	public function test_fichiers_neufs_laisses_par_une_tranche_tuee_sont_retires(): void {
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		$d = $this->dossier( self::B1 );
		file_put_contents( $d . '/index.bin.0123456789abcdef.tmp', 'moitie' );
		file_put_contents( $d . '/index.sig.0123456789abcdef.tmp', 'moitie' );
		file_put_contents( WorkDir::baseline_dir() . '/current.json.0123456789abcdef.tmp', '{' );

		$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		self::assertSame( BackupState::FINALIZE, $rapport['phase'] );
		self::assertTrue( $job->step( [ 'backup_id' => self::B1, 'finished' => true ] )['finished'] );

		self::assertDirectoryDoesNotExist( $d, 'fichiers neufs laissés retirés avec le dossier' );
		self::assertSame( [ self::B1 . '.jsonl', 'current.json' ], self::contenu( WorkDir::baseline_dir() ), 'fichier neuf de description laissé : retiré' );
		$this->job()->start( self::depart( self::B2, 'files', 'incremental', self::B1 ) );
		self::assertSame( 'incremental', BackupState::load()['state']['type'] );
	}

	/**
	 * Fin de la tranche qui signe perdue (état non enregistré après l'écriture de l'index) : la
	 * tranche suivante refait la signature par-dessus l'index déjà écrit ; index valide.
	 */
	public function test_signature_refaite_apres_fin_de_tranche_perdue(): void {
		$job      = $this->job();
		$rapport  = $job->start( self::depart( self::B1 ) );
		$segments = [];
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			foreach ( (array) BackupState::load()['state']['pending'] as $p ) {
				$segments[ $p['segment'] ] = (string) file_get_contents( $this->dossier( self::B1 ) . '/seg-' . $p['segment'] . '.seg' );
			}
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		foreach ( (array) BackupState::load()['state']['pending'] as $p ) {
			$segments[ $p['segment'] ] = (string) file_get_contents( $this->dossier( self::B1 ) . '/seg-' . $p['segment'] . '.seg' );
		}
		// Première écriture (début de tranche) passe ; la seconde (fin de tranche) échoue.
		$this->wpdb->before_write = function (): void {
			$this->wpdb->before_write = function (): void {
				$this->wpdb->write_fails = true;
			};
		};
		try {
			$job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
			self::fail( 'Refus attendu : fin de tranche perdue.' );
		} catch ( BackupRefused $refus ) {
			self::assertSame( BackupRefused::STORE_UNAVAILABLE, $refus->reason_code() );
		}
		self::assertFileExists( $this->dossier( self::B1 ) . '/index.bin', 'index écrit par la tranche perdue' );
		$this->wpdb->write_fails  = false;
		$this->wpdb->before_write = null;

		$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );

		self::assertSame( BackupState::FINALIZE, $rapport['phase'] );
		$this->assert_index_valide(
			[
				'index'    => (array) $rapport['index'],
				'segments' => $segments,
			]
		);
		self::assertSame( [], preg_grep( '/\.tmp\z/', self::contenu( $this->dossier( self::B1 ) ) ), 'aucun fichier neuf laissé' );
	}

	/**
	 * Sous Windows : `index.bin` posé comme jonction vers wp-config.php pendant la tranche qui
	 * signe. rename() ne remplace pas une jonction : refus avec la phrase du lien, sauvegarde close
	 * en échec, aucun fichier neuf laissé, wp-config.php intact. (Ailleurs, un lien symbolique est
	 * remplacé sans être suivi : test_index_signe_ecrit_sans_jamais_suivre_un_lien_pose_pendant_la_tranche.)
	 */
	public function test_jonction_sur_l_index_pendant_la_signature_est_refusee(): void {
		$cible = dirname( $this->racine ) . '/wp-config.php';
		file_put_contents( $cible, "<?php // réglages\n" );
		$sonde = dirname( $this->racine ) . '/sonde';
		self::jonction_vers_un_fichier_ou_ignorer( $cible, $sonde );
		self::retirer_lien( $sonde );
		$job     = $this->job();
		$rapport = $job->start( self::depart( self::B1 ) );
		for ( $tour = 0; BackupState::UPLOAD !== $rapport['phase'] && $tour < 20; $tour++ ) {
			$rapport = $job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
		}
		$dossier = $this->dossier( self::B1 );
		\Patchwork\redefine(
			[ WorkDir::class, 'truncate_journals' ],
			// Fermeture liée par Patchwork : jamais statique.
			function () use ( $dossier, $cible ) {
				$fait = \Patchwork\relay();
				self::jonction_vers_un_fichier_ou_ignorer( $cible, $dossier . '/index.bin' );
				return $fait;
			}
		);
		try {
			$job->step( [ 'backup_id' => self::B1, 'completed' => array_column( $rapport['pending'], 'segment' ) ] );
			self::fail( 'Refus attendu : jonction à la place de l\'index.' );
		} catch ( BackupRefused $refus ) {
			self::assert_arretee_en_cours( $refus, BackupRefused::WORK_DIR_UNAVAILABLE );
			self::assertStringContainsString( 'raccourci', $refus->getMessage(), 'la phrase dit le lien' );
			self::assertSame( [], preg_grep( '/\.tmp\z/', self::contenu( $dossier ) ), 'aucun fichier neuf laissé' );
		} finally {
			self::retirer_lien( $dossier . '/index.bin' );
		}
		self::assertSame( "<?php // réglages\n", file_get_contents( $cible ), 'cible intacte' );
		self::assert_close_en_echec( 'backup_work_dir_unavailable' );
	}
}
