<?php
/**
 * Machine à états d'une sauvegarde (spécification § 6.3 ; contrat « Commandes backup_* » du plan de
 * la phase 4) : backup_start, backup_step, backup_abort, appelés sous le verrou des tranches
 * (Gate\BackupLock) par Commands\BackupCommands.
 *
 * Une tranche (backup_step) : journaux ramenés à leur longueur validée (choix n° 15) ; segments
 * terminés par la plateforme retirés du disque ; parties envoyées vers les URL présignées reçues,
 * chacune avec un délai égal au reste de la tranche (5 à 60 s), jamais commencée sous 5 s ; segments
 * produits tant que la tranche dure et qu'il y a moins de MAX_PENDING segments en attente (un segment
 * s'écrit en entier dans la tranche : il se ferme plus tôt quand le temps manque) ; tout envoyé →
 * index signé et vérifié ; index rendu par morceaux ; `finished` → état local promu, dossier de la
 * sauvegarde nettoyé, état idle.
 *
 * Une mise à jour qui traverse la copie (phases db, files) passe la sauvegarde à abort (porte des
 * mises à jour, second filet) : la tranche suivante, ou l'écriture de fin de cette tranche, le voit,
 * retire ce qui a été préparé et refuse `backup_interrupted` ; la plateforme refait la sauvegarde
 * sous un nouveau numéro. Jamais une sauvegarde mélangée.
 *
 * Case décochée en pleine sauvegarde : la tranche suivante clôt la sauvegarde (aborted,
 * backup_disabled, dossier nettoyé) ; la porte redevient transparente.
 *
 * Le segment du manifeste n'est pas borné à la taille cible (un seul segment de manifeste par
 * sauvegarde, format § 7.2 ; borné par le format à 1 Gio, ≈ 7 millions de fichiers).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

use G2RD\Connector\Backup\BackupSwitch;
use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Content\BaselineReader;
use G2RD\Connector\Backup\Content\DatabaseDumper;
use G2RD\Connector\Backup\Content\FileArchiver;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Backup\Content\ManifestJournal;
use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Crypto\SelfTest;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Files\FileWalker;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\Payload\PayloadFormat;
use G2RD\Connector\Backup\Payload\PayloadWriter;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Backup\State\MainSite;
use G2RD\Connector\Backup\Upload\PartUploader;
use G2RD\Connector\Backup\Upload\PartUploadResult;
use G2RD\Connector\Settings;
use G2RD\Connector\Support\DiskSpace;

final class BackupJob {

	/**
	 * Durée d'une tranche par défaut (secondes) : valeur du point de contrôle n° 1 (tâche 2, décision
	 * P4-11), mesurée (sonde du 07/11 sur g2rd.fr : coupure entre 90 et 330 s ; spécification § 1.1).
	 */
	public const DEFAULT_SLICE_SECONDS = 20;

	public const MAX_PENDING = 2;

	public const MAX_PARTS_PER_STEP = 12;

	public const INDEX_CHUNK_BYTES = 16384;

	/** Rapport encodé, au plus (sous RunStore::MAX_RECORD_BYTES, 64 Kio). */
	public const REPORT_MAX_BYTES = 60000;

	/** Délai d'envoi d'une partie : au moins 5 s (minimum de PartUploader), au plus 60 s, jamais au-delà du reste de la tranche. */
	public const MIN_PART_SECONDS = 5;

	public const MAX_PART_SECONDS = 60;

	private const DISK_MARGIN_BYTES = 52428800;

	private const MIB = 1048576;

	private const SCOPES = [ 'db', 'files', 'both' ];

	private const TYPES = [ 'full', 'incremental' ];

	private const MAX_CONTENT_SEGMENTS = 4095;

	/** Lignes de la table des options du site principal jamais copiées dans le dump. */
	private const EXCLUDED_OPTIONS = [ BackupState::OPTION, UpdateWitness::OPTION, SiteKeyStore::OPTION, SigningKey::OPTION ];

	/** Dossiers d'autres sauvegardes et de caches, relatifs à la racine (filtre g2rd_connector_backup_excludes). */
	private const DEFAULT_EXCLUDES = [ 'wp-content/cache', 'wp-content/upgrade', 'wp-content/updraft', 'wp-content/ai1wm-backups', 'wp-content/backups-dup-pro', 'wp-content/backups-dup-lite', 'wp-content/wpvividbackups', 'wp-content/g2rd-snapshots' ];

	private Sodium $sodium;

	/** @var \Closure(): int */
	private \Closure $clock;

	/** @var \Closure(): int */
	private \Closure $ms;

	/** @var \Closure(string, string, int|null, string, int, int, int): PartUploadResult */
	private \Closure $upload;

	private string $root;

	/** @var \wpdb */
	private object $wpdb;

	/** @var (\Closure(): array{ok: bool, failed: list<string>, backend: string})|null */
	private ?\Closure $self_test;

	/**
	 * @param (\Closure(): int)|null                                                          $clock     Heure (secondes Unix).
	 * @param (\Closure(): int)|null                                                          $ms        Horloge monotone (millisecondes).
	 * @param (\Closure(string, string, int|null, string, int, int, int): PartUploadResult)|null $upload Envoi d'une partie, avec son délai en secondes (tests).
	 * @param string|null                                                                     $root      Racine copiée ; ABSPATH par défaut.
	 * @param \wpdb|null                                                                      $wpdb      $wpdb (un double en test).
	 * @param (\Closure(): array{ok: bool, failed: list<string>, backend: string})|null        $self_test Autotest ; SelfTest::run() par défaut (tests).
	 */
	public function __construct( Sodium $sodium, ?\Closure $clock = null, ?\Closure $ms = null, ?\Closure $upload = null, ?string $root = null, ?object $wpdb = null, ?\Closure $self_test = null ) {
		$this->sodium    = $sodium;
		$this->clock     = $clock ?? static fn (): int => time();
		$this->ms        = $ms ?? static fn (): int => (int) ( hrtime( true ) / 1000000 );
		$this->upload    = $upload ?? static function ( string $url, string $host, ?int $port, string $file, int $offset, int $length, int $timeout ): PartUploadResult {
			$bench = function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
			return ( new PartUploader( (bool) Settings::get( 'force_ipv4_to_storage' ), $bench ) )->upload( $url, $host, $port, $file, $offset, $length, $timeout );
		};
		$this->root      = rtrim( str_replace( '\\', '/', $root ?? ABSPATH ), '/' );
		$this->wpdb      = $wpdb ?? $GLOBALS['wpdb'];
		$this->self_test = $self_test;
	}

	/**
	 * @param array<mixed> $payload
	 * @return array<string, mixed> Rapport.
	 * @throws BackupRefused Voir le contrat.
	 */
	public function start( array $payload ): array {
		$now = ( $this->clock )();
		if ( ! BackupSwitch::enabled() ) {
			throw new BackupRefused( BackupRefused::DISABLED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$backup_id   = self::hex( $payload['backup_id'] ?? null, 32 );
		$scope       = $payload['scope'] ?? null;
		$type        = $payload['type'] ?? null;
		$parent      = $payload['parent_backup_id'] ?? null;
		$key_version = $payload['key_version'] ?? null;
		if ( null === $backup_id || ! in_array( $scope, self::SCOPES, true ) || ! in_array( $type, self::TYPES, true ) || ! is_int( $key_version ) ) {
			throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( 'incremental' === $type ) {
			$parent = self::hex( $parent, 32 );
			if ( null === $parent || $parent === $backup_id || 'db' === $scope ) {
				throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
		} elseif ( null !== $parent ) {
			throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$key = SiteKeyStore::current();
		if ( null === $key ) {
			throw new BackupRefused( BackupRefused::NO_SITE_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( $key['version'] !== $key_version ) {
			throw new BackupRefused( BackupRefused::KEY_VERSION_MISMATCH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		$loaded = self::load();
		$state  = $loaded['state'];
		if ( BackupState::is_active( $state ) && BackupState::ABORT !== $state['phase'] ) {
			if ( ( $state['backup_id'] ?? null ) === $backup_id ) {
				return $this->report( $state, [], null, 0 );
			}
			throw new BackupRefused( BackupRefused::ALREADY_RUNNING ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		// Même numéro que la dernière sauvegarde, terminée : refus avant toute écriture, son résultat
		// `done` reste (décision n° 4 du point de contrôle n° 2 de la phase 4). Arrêtée ou en échec :
		// elle repart du début, comme avant. Seule la dernière issue est connue du site.
		$last = is_array( $state['last'] ?? null ) ? $state['last'] : [];
		if ( ( $last['backup_id'] ?? null ) === $backup_id && 'done' === ( $last['outcome'] ?? null ) ) {
			throw new BackupRefused( BackupRefused::ALREADY_FINISHED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( BackupState::ABORT === $state['phase'] && is_string( $state['backup_id'] ?? null ) ) {
			WorkDir::clear_job( $state['backup_id'] );
		}
		if ( ! Aead::available() ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( true !== $this->self_test()['ok'] ) {
			throw new BackupRefused( BackupRefused::SELF_TEST_FAILED, null, '', 'start' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$signing = new SigningKey( $this->sodium );
		$signing->ensure( $now );
		if ( null === $signing->pair() ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		// Tables lues avant le dossier de la sauvegarde : une base mal réglée est refusée ici
		// (docs/backup-payload.md § 4, « Tables copiées ») sans rien laisser sur le disque. Noms en
		// hexadécimal minuscule, tels que lus dans le jeu du dump (§ 4, « Curseur du dump ») : un nom
		// qui n'est pas de l'UTF-8 valide ne passe pas par wp_json_encode sans être réécrit.
		$tables_hex = 'files' === $scope ? null : array_map( 'bin2hex', ( new DatabaseDumper( $this->wpdb ) )->tables( (string) $this->wpdb->base_prefix ) );
		if ( 'db' !== $scope ) {
			// Racine du site refusée : refusée ici, avant la copie de la base et sans rien laisser sur
			// le disque. Le premier pas du parcours suffit (rien n'est rendu après une racine refusée).
			$walker = new FileWalker( $this->root );
			$walker->walk()->valid();
			self::refuse_unlisted_root( $walker );
		}
		WorkDir::clear_job( $backup_id );
		// Rien à ce chemin juste avant job() : le dossier rendu est créé par ce départ, et retiré s'il
		// est refusé ensuite (disque insuffisant, journal impossible à écrire). Un dossier déjà là
		// n'est jamais touché.
		$created = ! WorkDir::job_exists( $backup_id );
		$job     = WorkDir::job( $backup_id );
		if ( null === $job ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		try {
			$this->assert_disk( $job );
		} catch ( BackupRefused $refusal ) {
			self::refuse_after_create( $refusal, $backup_id, $created );
		}

		$downgraded = null;
		if ( 'incremental' === $type ) {
			$usable = ( new IncrementalBaseline( WorkDir::baseline_dir() ) )->usable_for( (string) $parent, $now );
			if ( null !== $usable['reason'] ) {
				$downgraded = $usable['reason'];
				$type       = 'full';
				$parent     = null;
			}
		}

		$new = [
			'rev'          => is_int( $state['rev'] ?? null ) ? $state['rev'] : 0,
			'phase'        => 'files' === $scope ? BackupState::FILES : BackupState::DB,
			'backup_id'    => $backup_id,
			'type'         => $type,
			'scope'        => $scope,
			'parent'       => $parent,
			'downgraded'   => $downgraded,
			'key_version'  => $key['version'],
			'created_at'   => $now,
			'slice'        => null,
			'db'           => null === $tables_hex ? null : [
				'tables_hex'    => $tables_hex,
				'cursor'        => null,
				'table_segment' => null,
				'sql_offset'    => 0,
				'done'          => false,
			],
			'files'        => 'db' === $scope ? null : [
				'cursor_hex'      => null,
				'offset'          => 0,
				'current'         => null,
				'baseline_offset' => 'incremental' === $type ? 0 : null,
				'done'            => false,
			],
			'journals'     => [
				'manifest' => 0,
				'baseline' => 0,
				'segments' => 0,
			],
			'manifest_due' => false,
			'segments'     => 0,
			'pending'      => [],
			'reason'       => null,
			'aborted_at'   => null,
			'totals'       => [
				'db_tables'     => 0,
				'db_rows'       => 0,
				'files'         => 0,
				'payload_bytes' => 0,
				'unreadable'    => 0,
			],
			'last'         => $state['last'] ?? null,
		];
		try {
			( new ManifestJournal( $job . '/manifest.jsonl' ) )->append( $this->manifest_head( $new ) );
		} catch ( BackupRefused $refusal ) {
			self::refuse_after_create( $refusal, $backup_id, $created );
		}
		$new['journals'] = WorkDir::journal_sizes( $job );
		if ( null === BackupState::save( $loaded['raw'], $new ) ) {
			WorkDir::clear_job( $backup_id );
			throw new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return $this->report( $new, [], null, 0 );
	}

	/**
	 * Une tranche de la sauvegarde en cours. Un refus WORK_DIR_UNAVAILABLE ou CRYPTO_UNAVAILABLE y
	 * porte la phrase d'une sauvegarde déjà commencée (BackupRefused::in_progress()) : jamais « n'a
	 * pas été lancée » pour une sauvegarde arrêtée en route.
	 *
	 * @param array<mixed> $payload
	 * @return array<string, mixed> Rapport, ou le résultat de fin (`finished`).
	 * @throws BackupRefused Voir le contrat.
	 */
	public function step( array $payload ): array {
		try {
			return $this->run_step( $payload );
		} catch ( BackupRefused $refusal ) {
			throw $refusal->in_progress();
		}
	}

	/**
	 * @param array<mixed> $payload
	 * @return array<string, mixed> Rapport, ou le résultat de fin (`finished`).
	 * @throws BackupRefused Voir le contrat.
	 */
	private function run_step( array $payload ): array {
		$now     = ( $this->clock )();
		$started = ( $this->ms )();
		$opened  = $this->open_slice( $payload, $now, $started );
		$raw     = $opened['raw'];
		$state   = $opened['state'];
		$job     = $opened['job'];
		$copying = BackupState::is_capturing( $state );

		$completed        = array_values( array_filter( (array) ( $payload['completed'] ?? [] ), 'is_int' ) );
		$state['pending'] = $this->release( (array) $state['pending'], $completed, $job );
		[ $state['pending'], $failures ] = $this->upload_parts( (array) $state['pending'], (array) ( $payload['uploads'] ?? [] ), $opened['deadline'], $job );
		$state = $this->produce_or_keep( $raw, $state, $job, $opened['deadline'], $now );

		// Copie finie dans cette tranche : une mise à jour faite hors de la plateforme a pu commencer
		// pendant la tranche (entrée « sans verrou » posée, écriture d'abort perdue) ; revérifier le
		// témoin avant d'écrire le passage à l'envoi (décision n° 16 : jamais une sauvegarde mélangée).
		if ( $copying && ! BackupState::is_capturing( $state ) && UpdateWitness::clear_for_slice( $now ) ) {
			$this->close( $raw, $state, 'aborted', 'interrupted_by_update', $now );
			throw new BackupRefused( BackupRefused::INTERRUPTED, null, 'interrupted_by_update' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et raison connue).
		}
		if ( BackupState::UPLOAD === $state['phase'] && [] === $state['pending'] ) {
			try {
				$state = $this->finalize( $state, $job );
			} catch ( BackupRefused $refusal ) {
				$refusal = $refusal->with_stage( 'index' );
				$this->close( $raw, $state, 'failed', $refusal->reason_code(), $now, $refusal->stage() );
				throw $refusal;
			}
		}

		$index = null;
		if ( BackupState::FINALIZE === $state['phase'] ) {
			if ( true === ( $payload['finished'] ?? false ) ) {
				return $this->finish( $raw, $state, $job, $now );
			}
			$index = self::index_chunk( $job, is_int( $payload['index_offset'] ?? null ) ? $payload['index_offset'] : 0 );
		}

		$state['slice'] = null;
		$this->save_end( $raw, $state, $now );

		return $this->report( $state, $failures, $index, ( $this->ms )() - $started );
	}

	/**
	 * Production de la tranche. Base indisponible (lecture du dump, écriture de l'état) : rien de ce
	 * qui a été produit dans la tranche n'est compté — l'état d'avant la production (envois de la
	 * tranche compris) est enregistré et les journaux sont ramenés à sa longueur validée ; les
	 * segments produits seront refaits, sous une clé neuve. Autre refus : sauvegarde close (failed),
	 * autotest en échec dit avec son étape.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 * @throws BackupRefused Voir le contrat.
	 */
	private function produce_or_keep( string $raw, array $state, string $job, int $deadline, int $now ): array {
		$committed = $state;
		try {
			try {
				return $this->produce( $state, $job, $deadline, $now );
			} catch ( BackupRefused $refusal ) {
				throw $refusal->with_stage( 'segment' );
			}
		} catch ( BackupRefused $refusal ) {
			if ( in_array( $refusal->reason_code(), [ BackupRefused::DB_READ_FAILED, BackupRefused::STORE_UNAVAILABLE ], true ) ) {
				WorkDir::truncate_journals( $job, (array) ( $committed['journals'] ?? [] ) );
				$committed['slice'] = null;
				BackupState::save( $raw, $committed );
			} else {
				$this->close( $raw, $committed, 'failed', $refusal->reason_code(), $now, $refusal->stage() );
			}
			throw $refusal;
		}
	}

	/**
	 * @param array<mixed> $payload
	 * @return array{backup_id: string|null, aborted: bool, reason: string|null}
	 * @throws BackupRefused INVALID_PAYLOAD.
	 */
	public function abort( array $payload ): array {
		$backup_id = self::hex( $payload['backup_id'] ?? null, 32 );
		if ( null === $backup_id ) {
			throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$loaded = self::load();
		if ( ( $loaded['state']['backup_id'] ?? null ) !== $backup_id || ! BackupState::is_active( $loaded['state'] ) ) {
			return [
				'backup_id' => $backup_id,
				'aborted'   => false,
				'reason'    => null,
			];
		}
		$this->close( $loaded['raw'], $loaded['state'], 'aborted', 'aborted_by_platform', ( $this->clock )() );

		return [
			'backup_id' => $backup_id,
			'aborted'   => true,
			'reason'    => 'aborted_by_platform',
		];
	}

	/**
	 * Ouverture d'une tranche : sauvegarde connue et active, case cochée (sinon clôture), journaux
	 * ramenés à leur longueur validée, début de la tranche enregistré.
	 *
	 * @param array<mixed> $payload
	 * @return array{raw: string, state: array<string, mixed>, job: string, deadline: int}
	 * @throws BackupRefused Voir le contrat.
	 */
	private function open_slice( array $payload, int $now, int $started ): array {
		$backup_id = self::hex( $payload['backup_id'] ?? null, 32 );
		if ( null === $backup_id ) {
			throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$loaded = self::load();
		$state  = $loaded['state'];
		if ( ( $state['backup_id'] ?? null ) !== $backup_id || BackupState::IDLE === $state['phase'] ) {
			throw new BackupRefused( BackupRefused::UNKNOWN_BACKUP ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( BackupState::ABORT === $state['phase'] ) {
			$this->interrupted( $loaded['raw'], $state, $now );
		}
		if ( ! BackupSwitch::enabled() ) {
			// Case décochée en pleine sauvegarde : clôturée ici (dossier nettoyé, porte de nouveau
			// transparente), jamais laissée active jusqu'à un arrêt de la plateforme.
			$this->close( $loaded['raw'], $state, 'aborted', BackupRefused::DISABLED, $now );
			throw new BackupRefused( BackupRefused::DISABLED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$job = WorkDir::job( $backup_id );
		if ( null === $job ) {
			// Dossier de la sauvegarde perdu, ou impossible à recréer : ses segments et ses journaux
			// manquent, elle ne peut pas continuer. Close en échec, comme un journal perdu ci-dessous
			// (statut vrai : jamais laissée active derrière un refus non réessayable).
			$this->close( $loaded['raw'], $state, 'failed', BackupRefused::WORK_DIR_UNAVAILABLE, $now );
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		// Choix n° 15 : une ligne ajoutée par une tranche dont l'état n'a pas été enregistré disparaît
		// ici, avant toute production (sous le verrou des tranches).
		$journals = is_array( $state['journals'] ?? null ) ? $state['journals'] : WorkDir::journal_sizes( $job );
		if ( ! WorkDir::truncate_journals( $job, $journals ) ) {
			$this->close( $loaded['raw'], $state, 'failed', BackupRefused::WORK_DIR_UNAVAILABLE, $now );
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		$budget         = self::slice_seconds();
		$state['slice'] = [
			'started_at' => $now,
			'budget'     => $budget,
		];
		$raw            = BackupState::save( $loaded['raw'], $state );
		if ( null === $raw ) {
			throw new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return [
			'raw'      => $raw,
			'state'    => (array) json_decode( $raw, true ),
			'job'      => $job,
			'deadline' => $started + $budget * 1000,
		];
	}

	/**
	 * Écriture de fin de tranche ; refusée : abort posé entre-temps (mise à jour) → interruption,
	 * sinon base indisponible (les journaux seront ramenés à l'état enregistré à la tranche suivante).
	 *
	 * @param array<string, mixed> $state
	 * @throws BackupRefused INTERRUPTED ou STORE_UNAVAILABLE.
	 */
	private function save_end( string $raw, array $state, int $now ): void {
		if ( null !== BackupState::save( $raw, $state ) ) {
			return;
		}
		$again = self::load();
		if ( BackupState::ABORT === $again['state']['phase'] ) {
			$this->interrupted( $again['raw'], $again['state'], $now );
		}
		throw new BackupRefused( BackupRefused::STORE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
	}

	/**
	 * @return array{raw: string|null, state: array<string, mixed>}
	 * @throws BackupRefused STORE_UNAVAILABLE.
	 */
	private static function load(): array {
		try {
			return BackupState::load();
		} catch ( \Throwable ) {
			throw new BackupRefused( BackupRefused::STORE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}

	/**
	 * @return array{ok: bool, failed: list<string>, backend: string}
	 */
	private function self_test(): array {
		return null === $this->self_test ? SelfTest::run( $this->sodium ) : ( $this->self_test )();
	}

	/**
	 * Sauvegarde interrompue : préparation retirée, état idle avec son issue, refus explicite.
	 *
	 * @param array<string, mixed> $state
	 * @throws BackupRefused INTERRUPTED, toujours.
	 */
	private function interrupted( ?string $raw, array $state, int $now ): never {
		$reason = is_string( $state['reason'] ?? null ) ? $state['reason'] : 'aborted';
		$this->close( $raw, $state, 'aborted', $reason, $now );
		throw new BackupRefused( BackupRefused::INTERRUPTED, null, $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite et raison connue).
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function close( ?string $raw, array $state, string $outcome, ?string $code, int $now, ?string $stage = null ): void {
		if ( is_string( $state['backup_id'] ?? null ) ) {
			WorkDir::clear_job( $state['backup_id'] );
		}
		$idle        = BackupState::idle(
			[
				'backup_id' => $state['backup_id'] ?? null,
				'outcome'   => $outcome,
				'code'      => $code,
				'stage'     => $stage,
				'at'        => $now,
				'totals'    => $state['totals'] ?? null,
			]
		);
		$idle['rev'] = is_int( $state['rev'] ?? null ) ? $state['rev'] : 0;
		if ( null === BackupState::save( $raw, $idle ) ) {
			$again = BackupState::peek();
			if ( null !== $again && ( $again['backup_id'] ?? null ) === ( $state['backup_id'] ?? null ) ) {
				$retry = BackupState::load();
				BackupState::save( $retry['raw'], $idle );
			}
		}
	}

	/**
	 * Segments que la plateforme a terminés : retirés du disque.
	 *
	 * @param list<mixed> $pending
	 * @param list<int>   $completed
	 * @return list<array<string, mixed>>
	 */
	private function release( array $pending, array $completed, string $job ): array {
		$kept = [];
		foreach ( $pending as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}
			if ( in_array( $segment['segment'] ?? null, $completed, true ) ) {
				wp_delete_file( $job . '/seg-' . (int) $segment['segment'] . '.seg' );
				continue;
			}
			$kept[] = $segment;
		}

		return $kept;
	}

	/**
	 * Parties envoyées vers les URL reçues. Délai de chacune : le reste de la tranche, de
	 * MIN_PART_SECONDS à MAX_PART_SECONDS ; sous MIN_PART_SECONDS restantes, la partie n'est pas
	 * tentée (`not_attempted`, réessayable) : une tranche ne dépasse jamais sa durée de plus de
	 * quelques secondes (plus longue tranche ≤ tranche réglée + 5 s, essai du banc).
	 *
	 * @param list<array<string, mixed>> $pending
	 * @param list<mixed>                $uploads
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 * @throws BackupRefused INVALID_PAYLOAD.
	 */
	private function upload_parts( array $pending, array $uploads, int $deadline, string $job ): array {
		$failures = [];
		$count    = 0;
		$segments = array_column( $pending, 'segment' );
		foreach ( $uploads as $upload ) {
			if ( ! is_array( $upload ) || ! is_int( $upload['segment'] ?? null ) || ! is_array( $upload['parts'] ?? null ) ) {
				throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
			$at = array_search( $upload['segment'], $segments, true );
			if ( false === $at ) {
				continue;
			}
			foreach ( $upload['parts'] as $part ) {
				$number = is_array( $part ) ? ( $part['part_number'] ?? null ) : null;
				$url    = is_array( $part ) ? ( $part['url'] ?? null ) : null;
				$host   = is_array( $part ) ? ( $part['host'] ?? null ) : null;
				$port   = is_array( $part ) ? ( $part['port'] ?? null ) : null;
				$plan   = null;
				foreach ( (array) $pending[ $at ]['parts'] as $candidate ) {
					if ( is_array( $candidate ) && ( $candidate['part_number'] ?? null ) === $number ) {
						$plan = $candidate;
					}
				}
				if ( ++$count > self::MAX_PARTS_PER_STEP || ! is_int( $number ) || ! is_string( $url ) || strlen( $url ) > 1024 || ! is_string( $host ) || ( null !== $port && ! is_int( $port ) ) || null === $plan ) {
					throw new BackupRefused( BackupRefused::INVALID_PAYLOAD ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
				}
				$left = intdiv( $deadline - ( $this->ms )(), 1000 );
				if ( $left < self::MIN_PART_SECONDS ) {
					$failures[] = self::failure( $upload['segment'], $number, 'not_attempted', 0, true );
					continue;
				}
				$file   = $job . '/seg-' . $upload['segment'] . '.seg';
				$result = ( $this->upload )( $url, $host, $port, $file, (int) $plan['offset'], (int) $plan['length'], min( self::MAX_PART_SECONDS, $left ) );
				if ( PartUploadResult::UPLOADED === $result->outcome && null !== $result->etag ) {
					$pending[ $at ]['uploaded'][ (string) $number ] = $result->etag;
				} else {
					$failures[] = self::failure( $upload['segment'], $number, $result->outcome, $result->http_status, $result->is_retryable() );
				}
			}
		}

		return [ $pending, $failures ];
	}

	/**
	 * @return array{segment: int, part_number: int, outcome: string, http_status: int, retryable: bool}
	 */
	private static function failure( int $segment, int $part, string $outcome, int $status, bool $retryable ): array {
		return [
			'segment'     => $segment,
			'part_number' => $part,
			'outcome'     => $outcome,
			'http_status' => $status,
			'retryable'   => $retryable,
		];
	}

	/**
	 * Produit des segments tant que la tranche dure : base, fichiers, puis le segment du manifeste.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 * @throws BackupRefused Voir le contrat.
	 */
	private function produce( array $state, string $job, int $deadline, int $now ): array {
		unset( $now );
		if ( ! in_array( $state['phase'], [ BackupState::DB, BackupState::FILES ], true ) ) {
			return $state;
		}
		$producer = $this->producer( $state, $job );
		while ( in_array( $state['phase'], [ BackupState::DB, BackupState::FILES ], true ) && count( (array) $state['pending'] ) < self::MAX_PENDING && ( $this->ms )() < $deadline ) {
			$n = (int) $state['segments'];
			if ( $n >= self::MAX_CONTENT_SEGMENTS && ! $state['manifest_due'] ) {
				throw new BackupRefused( BackupRefused::TOO_MANY_SEGMENTS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
			$this->assert_disk( $job );
			if ( true === $state['manifest_due'] ) {
				$state = $this->manifest_segment( $producer, $state, $job, $n );
			} elseif ( BackupState::DB === $state['phase'] ) {
				$state = $this->db_segment( $producer, $state, $job, $n, $deadline );
			} else {
				$state = $this->files_segment( $producer, $state, $job, $n, $deadline );
			}
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @throws BackupRefused NO_SITE_KEY.
	 */
	private function producer( array $state, string $job ): SegmentProducer {
		$key = SiteKeyStore::current();
		if ( null === $key ) {
			throw new BackupRefused( BackupRefused::NO_SITE_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return new SegmentProducer( new Hpke( $this->sodium ), $this->sodium, $job, (string) $state['backup_id'], (int) $state['key_version'], (string) hex2bin( $key['public_key'] ), self::block_size(), $this->self_test );
	}

	/**
	 * Un segment de la base. Ligne `table` du manifeste à la fin de chaque table, avec le segment où
	 * elle COMMENCE (`db.table_segment` garde celui de la table en cours d'un segment à l'autre) ;
	 * nom écrit par ManifestJournal::name_field() (`name_b64` s'il n'est pas de l'UTF-8 valide,
	 * docs/backup-payload.md § 5). Tables relues de `db.tables_hex` (§ 4, « Curseur du dump »).
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 * @throws BackupRefused Voir le contrat.
	 */
	private function db_segment( SegmentProducer $producer, array $state, string $job, int $n, int $deadline ): array {
		$options = (string) MainSite::run( fn (): string => (string) $this->wpdb->options );
		$dumper  = new DatabaseDumper( $this->wpdb, [ $options => array_fill_keys( self::EXCLUDED_OPTIONS, true ) ] );
		$db      = (array) $state['db'];
		$tables  = DatabaseDumper::tables_from_hex( $db['tables_hex'] ?? null );
		$target  = self::segment_target();
		$carried = is_array( $db['cursor'] ?? null ) && true === ( $db['cursor']['started'] ?? false ) && is_int( $db['table_segment'] ?? null ) ? $db['table_segment'] : null;
		$result  = null;
		$entry   = $producer->produce(
			$n,
			SegmentHeader::KIND_DB,
			function ( PayloadWriter $writer ) use ( $dumper, $tables, &$db, &$result, $target, $deadline ): void {
				$emit   = static function ( string $sql ) use ( $writer, &$db ): void {
					if ( '' === $sql ) {
						return;
					}
					foreach ( str_split( $sql, PayloadFormat::MAX_RAW_BYTES ) as $chunk ) {
						$writer->sql( (int) $db['sql_offset'], $chunk );
						$db['sql_offset'] = (int) $db['sql_offset'] + strlen( $chunk );
					}
				};
				$stop   = fn (): bool => $writer->written() >= $target || ( $this->ms )() >= $deadline;
				$result = $dumper->advance( $tables, is_array( $db['cursor'] ) ? $db['cursor'] : null, $emit, $stop );
			}
		);
		if ( null === $result ) {
			return $state;
		}
		$db['cursor'] = $result['cursor'];
		$db['done']   = null === $result['cursor'];
		$journal      = new ManifestJournal( $job . '/manifest.jsonl' );
		foreach ( $result['tables_done'] as $i => $table ) {
			$journal->append(
				[
					't'             => 'table',
					...ManifestJournal::name_field( $table['name'] ),
					'rows'          => $table['rows'],
					'create_sha256' => $table['create_sha256'],
					'segment'       => 0 === $i && null !== $carried ? $carried : $n,
				]
			);
		}
		$open                          = null !== $result['cursor'] && $result['cursor']['started'];
		$db['table_segment']           = $open ? ( [] === $result['tables_done'] && null !== $carried ? $carried : $n ) : null;
		$state['totals']['db_tables'] += count( $result['tables_done'] );
		$state['totals']['db_rows']   += $result['rows'];
		$state['db']                   = $db;
		if ( $db['done'] ) {
			if ( 'both' === $state['scope'] ) {
				$state['phase'] = BackupState::FILES;
			} else {
				$state['manifest_due'] = true;
			}
		}

		return $this->record_segment( $state, $entry, $job );
	}

	/**
	 * Un segment des fichiers. Curseur gardé en hexadécimal (`files.cursor_hex`) : un nom qui n'est
	 * pas de l'UTF-8 valide revient octet pour octet à la tranche suivante. Fichier réparti :
	 * `files.current` garde sa taille, sa date, son mode et le segment où il commence ; disparu ou
	 * changé entre deux tranches, il est inscrit illisible (statut vrai), jamais tu.
	 *
	 * Dossiers dont le contenu est refusé (contrat de FileWalker) : un dossier rendu `listed` faux
	 * n'est pas archivé, il est inscrit illisible et absent du futur état local ; une entrée de l'état
	 * local sous un dossier refusé n'est jamais dite supprimée (absent_line()) ; un dossier refusé
	 * que le parcours ne rend pas (reprise dans un dossier devenu illisible, droits retirés pendant le
	 * parcours), dont la ligne éventuelle est déjà écrite, est compté illisible une fois, par
	 * FileWalker::unlisted(). Racine refusée ('' dans unlisted(), droits retirés depuis le départ) :
	 * la suite des fichiers manque, la sauvegarde est arrêtée (FILES_ROOT_UNREADABLE), jamais dite
	 * finie avec un illisible de plus.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 * @throws BackupRefused Voir le contrat.
	 */
	private function files_segment( SegmentProducer $producer, array $state, string $job, int $n, int $deadline ): array {
		$walker   = new FileWalker( $this->root, $this->excludes() );
		$archiver = new FileArchiver( $this->root );
		$journal  = new ManifestJournal( $job . '/manifest.jsonl' );
		$files    = (array) $state['files'];
		$totals   = (array) $state['totals'];
		$baseline = 'full' === $state['type'] ? $job . '/baseline-new.jsonl' : null;
		$reader   = 'incremental' === $state['type'] ? new BaselineReader( WorkDir::baseline_dir() . '/' . $state['parent'] . '.jsonl', (int) $files['baseline_offset'] ) : null;
		$target   = self::segment_target();
		$verify   = (bool) apply_filters( 'g2rd_connector_backup_verify_unchanged_hash', false );
		$cursor   = self::cursor_of( $files );
		/** @var array<string, true> $refused Dossiers rendus `listed` faux par ce parcours, déjà inscrits illisibles. */
		$refused = [];

		$entry = $producer->produce(
			$n,
			SegmentHeader::KIND_FILES,
			function ( PayloadWriter $writer ) use ( $walker, $archiver, $journal, &$files, &$totals, &$cursor, &$refused, $baseline, $reader, $target, $deadline, $n, $verify ): void {
				$stop = fn (): bool => $writer->written() >= $target || ( $this->ms )() >= $deadline;
				if ( (int) $files['offset'] > 0 && null !== $cursor && is_array( $files['current'] ?? null ) ) {
					$known   = (array) $files['current'];
					$current = $walker->entry( $cursor );
					if ( null === $current || 'file' !== $current['type'] || $current['size'] !== (int) $known['size'] || $current['mtime'] !== (int) $known['mtime'] ) {
						$this->file_line( $journal, null, self::known_entry( $cursor, $known ), null, 'unreadable', null, $totals );
					} else {
						$done = $archiver->archive( $current, (int) $files['offset'], $writer, $stop );
						if ( ! $done['done'] ) {
							$files['offset'] = $done['offset'];
							return;
						}
						$this->file_line( $journal, $baseline, $current, null, $done['unreadable'] ? 'unreadable' : 'stored', (int) $known['segment'], $totals );
					}
					$files['offset']  = 0;
					$files['current'] = null;
					if ( $stop() ) {
						return;
					}
				}
				foreach ( $walker->walk( $cursor ) as $item ) {
					$base = null;
					while ( null !== $reader ) {
						$peeked = $reader->peek();
						if ( null === $peeked ) {
							break;
						}
						$order = FileWalker::compare( $peeked['path'], $item['path'] );
						if ( $order > 0 ) {
							break;
						}
						$reader->next();
						if ( 0 === $order ) {
							$base = $peeked;
							break;
						}
						$this->absent_line( $writer, $journal, $walker, $peeked, $totals );
					}
					$cursor                   = $item['path'];
					$files['cursor_hex']      = bin2hex( $item['path'] );
					$files['baseline_offset'] = null === $reader ? null : $reader->offset();
					if ( false === $item['listed'] ) {
						// Contenu refusé : rien d'archivé, inscrit illisible. Jamais d'arrêt sur lui : le
						// curseur ne reste pas sur un dossier refusé, que le parcours suivant retrouverait
						// refusé (FileWalker::unlisted()) et compterait une seconde fois.
						$refused[ $item['path'] ] = true;
						$this->file_line( $journal, null, $item, null, 'unreadable', null, $totals );
						continue;
					}
					if ( null !== $base && 'dir' !== $item['type'] && IncrementalBaseline::same( $item, $base ) && ! self::hash_changed( $this->root, $item, $base, $verify ) ) {
						$this->file_line( $journal, null, $item, $base['sha256'], 'unchanged', null, $totals );
						if ( $stop() ) {
							return;
						}
						continue;
					}
					$done = $archiver->archive( $item, 0, $writer, $stop );
					if ( ! $done['done'] ) {
						$files['offset']  = $done['offset'];
						$files['current'] = [
							'size'    => $item['size'],
							'mtime'   => $item['mtime'],
							'mode'    => $item['mode'],
							'segment' => $n,
						];
						return;
					}
					$this->file_line( $journal, $baseline, $item, $done['sha256'], $done['unreadable'] ? 'unreadable' : 'stored', $n, $totals );
					if ( $stop() ) {
						return;
					}
				}
				while ( null !== $reader ) {
					$peeked = $reader->peek();
					if ( null === $peeked ) {
						break;
					}
					$this->absent_line( $writer, $journal, $walker, $peeked, $totals );
					$reader->next();
				}
				$files['done'] = true;
			}
		);
		if ( null !== $reader ) {
			$reader->close();
		}
		// Après la production, quel que soit le chemin de sortie du remplissage : le segment produit
		// n'est pas enregistré, close() retire le dossier de la sauvegarde.
		self::refuse_unlisted_root( $walker );
		// Dossiers refusés que ce parcours n'a pas rendus : chacun est découvert par un seul parcours
		// (le curseur passe ensuite au-delà de lui), donc compté une seule fois.
		$totals['unreadable'] = (int) $totals['unreadable'] + count( array_diff_key( array_flip( $walker->unlisted() ), $refused ) );
		$state['files']       = $files;
		$state['totals']      = $totals;
		if ( true === $files['done'] ) {
			$state['manifest_due'] = true;
		}

		return $this->record_segment( $state, $entry, $job );
	}

	/**
	 * Racine du parcours refusée ('' dans FileWalker::unlisted() : liste ou traversée refusée au
	 * premier pas, ou perdue pendant le parcours) : les fichiers, ou leur suite, manquent. Jamais une
	 * partie fichiers dite finie sans eux (principe n° 6).
	 *
	 * @throws BackupRefused FILES_ROOT_UNREADABLE (non réessayable).
	 */
	private static function refuse_unlisted_root( FileWalker $walker ): void {
		if ( in_array( '', $walker->unlisted(), true ) ) {
			throw new BackupRefused( BackupRefused::FILES_ROOT_UNREADABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
	}

	/**
	 * Curseur du parcours relu de l'état : octets du chemin, décodés de l'hexadécimal.
	 *
	 * @param array<string, mixed> $files
	 */
	private static function cursor_of( array $files ): ?string {
		$hex = $files['cursor_hex'] ?? null;
		if ( ! is_string( $hex ) || '' === $hex || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		$path = hex2bin( $hex );

		return false === $path ? null : $path;
	}

	/**
	 * Entrée d'un fichier réparti telle que lue à son début (pour l'inscrire illisible).
	 *
	 * @param array<mixed> $known
	 * @return array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null}
	 */
	private static function known_entry( string $path, array $known ): array {
		return [
			'path'   => $path,
			'type'   => 'file',
			'size'   => (int) ( $known['size'] ?? 0 ),
			'mtime'  => (int) ( $known['mtime'] ?? 0 ),
			'mode'   => (int) ( $known['mode'] ?? 0 ),
			'target' => null,
		];
	}

	/**
	 * @param array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null} $entry
	 * @param array<string, mixed>                                                                     $totals
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE.
	 */
	private function file_line( ManifestJournal $journal, ?string $baseline, array $entry, ?string $sha256, string $status, ?int $segment, array &$totals ): void {
		$journal->append(
			[ 't' => 'file' ] + ManifestJournal::path_field( $entry['path'] ) + [
				'kind'    => $entry['type'],
				'size'    => $entry['size'],
				'mtime'   => $entry['mtime'],
				'mode'    => $entry['mode'],
				'sha256'  => $sha256,
				'state'   => $status,
				'segment' => 'stored' === $status ? $segment : null,
			]
		);
		$totals['files'] = (int) $totals['files'] + 1;
		if ( 'unreadable' === $status ) {
			$totals['unreadable'] = (int) $totals['unreadable'] + 1;
			return;
		}
		if ( null !== $baseline ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- futur état local des incrémentales, dans le dossier de travail (journal tronqué à sa longueur validée à chaque tranche).
			if ( false === file_put_contents( $baseline, IncrementalBaseline::line( $entry, $sha256 ), FILE_APPEND ) ) {
				throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
			}
		}
	}

	/**
	 * Entrée de l'état local absente du parcours (les deux boucles de fusion de files_segment()).
	 * Sous un dossier refusé (FileWalker::within_unlisted(), interrogé quand l'entrée suivante du
	 * parcours arrive ou après sa fin : le dossier a déjà été essayé, à la reprise comme au premier
	 * passage), son absence ne prouve rien : inscrite illisible d'après l'état local (nature,
	 * taille, date ; mode inconnu de l'état local, 0), rien n'est écrit dans le segment. Sinon
	 * supprimée : enregistrement `deleted` et ligne `deleted`.
	 *
	 * @param array{path: string, type: string, size: int, mtime: int, sha256: string|null} $base
	 * @param array<string, mixed>                                                          $totals
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE.
	 */
	private function absent_line( PayloadWriter $writer, ManifestJournal $journal, FileWalker $walker, array $base, array &$totals ): void {
		if ( $walker->within_unlisted( $base['path'] ) ) {
			$entry = [
				'path'   => $base['path'],
				'type'   => $base['type'],
				'size'   => $base['size'],
				'mtime'  => $base['mtime'],
				'mode'   => 0,
				'target' => null,
			];
			$this->file_line( $journal, null, $entry, null, 'unreadable', null, $totals );
			return;
		}
		$writer->deleted( $base['path'] );
		$this->deleted_line( $journal, $base['path'], $totals );
	}

	/**
	 * @param array<string, mixed> $totals
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE.
	 */
	private function deleted_line( ManifestJournal $journal, string $path, array &$totals ): void {
		$journal->append(
			[ 't' => 'file' ] + ManifestJournal::path_field( $path ) + [
				'kind'    => 'file',
				'size'    => 0,
				'mtime'   => 0,
				'mode'    => 0,
				'sha256'  => null,
				'state'   => 'deleted',
				'segment' => null,
			]
		);
		$totals['files'] = (int) $totals['files'] + 1;
	}

	/**
	 * @param array{path: string, type: string, size: int, mtime: int, mode: int, target: string|null} $entry
	 * @param array{path: string, type: string, size: int, mtime: int, sha256: string|null}            $base
	 */
	private static function hash_changed( string $root, array $entry, array $base, bool $verify ): bool {
		if ( ! $verify || 'file' !== $entry['type'] || ! is_string( $base['sha256'] ) ) {
			return false;
		}
		$hash = hash_file( 'sha256', $root . '/' . $entry['path'] );

		return false === $hash || ! hash_equals( $base['sha256'], $hash );
	}

	/**
	 * @return list<string>
	 */
	private function excludes(): array {
		$excludes = self::DEFAULT_EXCLUDES;
		$work     = WorkDir::relative_to( $this->root );
		if ( null !== $work ) {
			$excludes[] = $work;
		}
		$filtered = apply_filters( 'g2rd_connector_backup_excludes', $excludes );

		return array_values( array_filter( is_array( $filtered ) ? $filtered : $excludes, 'is_string' ) );
	}

	/**
	 * Segment du manifeste : ligne de fin ajoutée au journal (retirée par la troncature si la
	 * tranche n'est pas enregistrée), journal rendu par morceaux, copie finie.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function manifest_segment( SegmentProducer $producer, array $state, string $job, int $n ): array {
		$journal = new ManifestJournal( $job . '/manifest.jsonl' );
		$journal->append(
			[
				't'          => 'end',
				'tables'     => $state['totals']['db_tables'],
				'files'      => $state['totals']['files'],
				'unreadable' => $state['totals']['unreadable'],
			]
		);
		$entry = $producer->produce(
			$n,
			SegmentHeader::KIND_MANIFEST,
			static function ( PayloadWriter $writer ) use ( $journal ): void {
				$journal->stream(
					static function ( int $offset, string $chunk ) use ( $writer ): void {
						$writer->manifest( $offset, $chunk );
					}
				);
			}
		);
		$state['manifest_due'] = false;
		$state['phase']        = BackupState::UPLOAD;

		return $this->record_segment( $state, $entry, $job );
	}

	/**
	 * Index signé, vérifié par le site, gardé dans le dossier de la sauvegarde.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 * @throws BackupRefused SELF_TEST_FAILED, CRYPTO_UNAVAILABLE, WORK_DIR_UNAVAILABLE.
	 */
	private function finalize( array $state, string $job ): array {
		$key  = SiteKeyStore::current();
		$pair = ( new SigningKey( $this->sodium ) )->pair();
		if ( null === $key || null === $pair ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$segments = [];
		$manifest = 0;
		foreach ( (array) file( $job . '/segments.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$segment = json_decode( (string) $line, true );
			if ( is_array( $segment ) ) {
				$segments[ (int) $segment['index'] ] = $segment;
				if ( 'manifest' === $segment['kind'] ) {
					$manifest = (int) $segment['index'];
				}
			}
		}
		ksort( $segments );
		$fields = [
			'format'           => 'g2bk-index',
			'version'          => 1,
			'suite'            => 1,
			'backup_id'        => $state['backup_id'],
			'site_ref'         => $key['site_ref'],
			'site_key_version' => (int) $state['key_version'],
			'signing_key_id'   => BackupIndex::signing_key_id( $pair['public'] ),
			'created_at'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $state['created_at'] ),
			'type'             => $state['type'],
			'parent_backup_id' => $state['parent'],
			'payload_format'   => 1,
			'segments'         => array_values( $segments ),
			'manifest_segment' => $manifest,
		];
		$signed = ( new Finalizer( $this->sodium, $this->self_test ) )->finalize( $fields, $pair['secret'], $pair['public'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- index signé de la sauvegarde, dans son dossier de travail, en attente de la plateforme.
		if ( false === file_put_contents( $job . '/index.bin', $signed['index'] ) || false === file_put_contents( $job . '/index.sig', $signed['signature'] ) ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$state['phase'] = BackupState::FINALIZE;

		return $state;
	}

	/**
	 * @return array{bytes: int, sha256: string, signature: string, offset: int, chunk: string}
	 */
	private static function index_chunk( string $job, int $offset ): array {
		$index     = (string) file_get_contents( $job . '/index.bin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- index signé gardé dans le dossier de travail.
		$signature = (string) file_get_contents( $job . '/index.sig' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- signature de l'index gardée dans le dossier de travail.
		$offset    = max( 0, min( $offset, strlen( $index ) ) );

		return [
			'bytes'     => strlen( $index ),
			'sha256'    => hash( 'sha256', $index ),
			'signature' => bin2hex( $signature ),
			'offset'    => $offset,
			'chunk'     => bin2hex( (string) substr( $index, $offset, self::INDEX_CHUNK_BYTES ) ),
		];
	}

	/**
	 * La plateforme a déposé l'index : état local promu (complète qui porte les fichiers), dossier
	 * retiré, état idle.
	 *
	 * @param array<string, mixed> $state
	 * @return array{backup_id: string, phase: string, finished: bool, totals: array<string, mixed>}
	 */
	private function finish( string $raw, array $state, string $job, int $now ): array {
		if ( 'full' === $state['type'] && 'db' !== $state['scope'] && is_file( $job . '/baseline-new.jsonl' ) ) {
			( new IncrementalBaseline( WorkDir::baseline_dir() ) )->promote( $job . '/baseline-new.jsonl', (string) $state['backup_id'], (int) $state['created_at'] );
		}
		$this->close( $raw, $state, 'done', null, $now );

		return [
			'backup_id' => (string) $state['backup_id'],
			'phase'     => BackupState::IDLE,
			'finished'  => true,
			'totals'    => (array) $state['totals'],
		];
	}

	/**
	 * Segment inscrit : liste des segments, parties annoncées, longueurs validées des journaux (elles
	 * deviennent celles de l'état à son écriture de fin de tranche).
	 *
	 * @param array<string, mixed>                                                                         $state
	 * @param array{segment: int, kind: string, bytes: int, blocks: int, sha256: string, header: string, file: string} $entry
	 * @return array<string, mixed>
	 * @throws BackupRefused WORK_DIR_UNAVAILABLE.
	 */
	private function record_segment( array $state, array $entry, string $job ): array {
		$line = [
			'index'  => $entry['segment'],
			'kind'   => $entry['kind'],
			'blocks' => $entry['blocks'],
			'bytes'  => $entry['bytes'],
			'sha256' => $entry['sha256'],
		];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- liste des segments de la sauvegarde, dans son dossier de travail.
		if ( false === file_put_contents( $job . '/segments.jsonl', (string) json_encode( $line ) . "\n", FILE_APPEND ) ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$limit                             = self::memory_limit();
		$state['pending'][]                = [
			'segment'  => $entry['segment'],
			'kind'     => $entry['kind'],
			'bytes'    => $entry['bytes'],
			'blocks'   => $entry['blocks'],
			'sha256'   => $entry['sha256'],
			'header'   => $entry['header'],
			'parts'    => PartPlanner::plan( $entry['bytes'], PartPlanner::part_size( $limit, memory_get_usage( true ) ) ),
			'uploaded' => [],
		];
		$state['segments']                 = $entry['segment'] + 1;
		$state['totals']['payload_bytes'] += $entry['bytes'];
		$state['journals']                 = WorkDir::journal_sizes( $job );

		return $state;
	}

	private static function block_size(): int {
		return self::bounded( apply_filters( 'g2rd_connector_backup_block_kib', 8192 ), 8192, 64, 65536 ) * 1024;
	}

	private static function memory_limit(): ?int {
		$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : -1;
		return $limit > 0 ? $limit : null;
	}

	/**
	 * @param array<string, mixed>                                                                     $state
	 * @param list<array<string, mixed>>                                                               $failures
	 * @param array{bytes: int, sha256: string, signature: string, offset: int, chunk: string}|null     $index
	 * @return array<string, mixed>
	 */
	private function report( array $state, array $failures, ?array $index, int $slice_ms ): array {
		$public  = SigningKey::public_key();
		$pending = [];
		foreach ( (array) ( $state['pending'] ?? [] ) as $segment ) {
			$uploaded = [];
			foreach ( (array) $segment['uploaded'] as $number => $etag ) {
				$uploaded[] = [
					'part_number' => (int) $number,
					'etag'        => $etag,
				];
			}
			$pending[] = [
				'segment'  => $segment['segment'],
				'kind'     => $segment['kind'],
				'bytes'    => $segment['bytes'],
				'blocks'   => $segment['blocks'],
				'sha256'   => $segment['sha256'],
				'header'   => $segment['header'],
				'parts'    => $segment['parts'],
				'uploaded' => $uploaded,
			];
		}
		$totals = (array) ( $state['totals'] ?? [] );

		return [
			'backup_id'        => $state['backup_id'] ?? null,
			'phase'            => $state['phase'] ?? BackupState::IDLE,
			'type'             => $state['type'] ?? null,
			'scope'            => $state['scope'] ?? null,
			'parent_backup_id' => $state['parent'] ?? null,
			'downgraded'       => $state['downgraded'] ?? null,
			'key_version'      => $state['key_version'] ?? null,
			'signing_key_id'   => null === $public ? null : BackupIndex::signing_key_id( $public ),
			'segments_written' => $state['segments'] ?? 0,
			'pending'          => $pending,
			'part_failures'    => $failures,
			'progress'         => [
				'db_tables_done'  => $totals['db_tables'] ?? 0,
				'db_tables_total' => is_array( $state['db'] ?? null ) ? count( (array) ( $state['db']['tables_hex'] ?? [] ) ) : 0,
				'db_rows'         => $totals['db_rows'] ?? 0,
				'files_done'      => $totals['files'] ?? 0,
				'payload_bytes'   => $totals['payload_bytes'] ?? 0,
				'unreadable'      => $totals['unreadable'] ?? 0,
			],
			'index'            => $index,
			'slice_ms'         => $slice_ms,
		];
	}

	/**
	 * Ligne `head` du manifeste, clés dans l'ordre du contrat (docs/backup-payload.md § 5).
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function manifest_head( array $state ): array {
		$content = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' );

		return [
			't'                       => 'head',
			'format'                  => 'g2bk-manifest',
			'version'                 => 1,
			'backup_id'               => $state['backup_id'],
			'type'                    => $state['type'],
			'parent_backup_id'        => $state['parent'],
			'scope'                   => $state['scope'],
			'created_at'              => gmdate( 'Y-m-d\TH:i:s\Z', (int) $state['created_at'] ),
			'site_key_version'        => $state['key_version'],
			'wordpress'               => [
				'version'      => (string) get_bloginfo( 'version' ),
				'table_prefix' => (string) $this->wpdb->base_prefix,
				'multisite'    => function_exists( 'is_multisite' ) && is_multisite(),
			],
			'php'                     => PHP_VERSION,
			'connector'               => G2RD_CONNECTOR_VERSION,
			'db_charset'              => DatabaseDumper::charset_of( $this->wpdb ),
			'wp_config_outside_root'  => ! is_file( $this->root . '/wp-config.php' ) && is_file( dirname( $this->root ) . '/wp-config.php' ),
			'wp_content_outside_root' => ! str_starts_with( $content . '/', $this->root . '/' ),
		];
	}

	/**
	 * Départ refusé après job() : le dossier que ce départ a créé est retiré (rien d'autre, cf.
	 * WorkDir::discard_new_job()), puis le refus d'origine est rendu tel quel, même si ce retrait
	 * échoue.
	 *
	 * @throws BackupRefused Toujours : le refus d'origine.
	 */
	private static function refuse_after_create( BackupRefused $refusal, string $backup_id, bool $created ): never {
		if ( $created ) {
			WorkDir::discard_new_job( $backup_id );
		}
		throw $refusal;
	}

	/**
	 * @throws BackupRefused DISK_LOW.
	 */
	private function assert_disk( string $job ): void {
		$needed = self::segment_target() + self::DISK_MARGIN_BYTES;
		$free   = DiskSpace::free_bytes( $job );
		if ( null !== $free && $free < $needed ) {
			throw new BackupRefused( BackupRefused::DISK_LOW, null, intdiv( $needed, self::MIB ) . ' Mio' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, taille calculée).
		}
	}

	private static function slice_seconds(): int {
		return self::bounded( apply_filters( 'g2rd_connector_backup_slice_seconds', self::DEFAULT_SLICE_SECONDS ), self::DEFAULT_SLICE_SECONDS, 5, 50 );
	}

	private static function segment_target(): int {
		return self::bounded( apply_filters( 'g2rd_connector_backup_segment_mib', 64 ), 64, 16, 256 ) * self::MIB;
	}

	private static function bounded( mixed $value, int $fallback, int $min, int $max ): int {
		return is_int( $value ) ? max( $min, min( $max, $value ) ) : $fallback;
	}

	private static function hex( mixed $value, int $length ): ?string {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{' . $length . '}\z/', $value ) ? $value : null;
	}
}
