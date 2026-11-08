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
use G2RD\Connector\Backup\Content\DatabaseDumper;
use G2RD\Connector\Backup\Content\IncrementalBaseline;
use G2RD\Connector\Backup\Content\ManifestJournal;
use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Crypto\SelfTest;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\State\BackupState;
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
		WorkDir::clear_job( $backup_id );
		$job = WorkDir::job( $backup_id );
		if ( null === $job ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$this->assert_disk( $job );

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
		( new ManifestJournal( $job . '/manifest.jsonl' ) )->append( $this->manifest_head( $new ) );
		$new['journals'] = WorkDir::journal_sizes( $job );
		if ( null === BackupState::save( $loaded['raw'], $new ) ) {
			WorkDir::clear_job( $backup_id );
			throw new BackupRefused( BackupRefused::UPDATE_IN_PROGRESS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return $this->report( $new, [], null, 0 );
	}

	/**
	 * Tranche (première version : sans production ; remplacée en entier aux tâches 29-A et 29-C).
	 *
	 * @param array<mixed> $payload
	 * @return array<string, mixed> Rapport.
	 * @throws BackupRefused Voir le contrat.
	 */
	public function step( array $payload ): array {
		$now     = ( $this->clock )();
		$started = ( $this->ms )();
		$opened  = $this->open_slice( $payload, $now, $started );
		$raw     = $opened['raw'];
		$state   = $opened['state'];
		$job     = $opened['job'];

		$completed        = array_values( array_filter( (array) ( $payload['completed'] ?? [] ), 'is_int' ) );
		$state['pending'] = $this->release( (array) $state['pending'], $completed, $job );
		[ $state['pending'], $failures ] = $this->upload_parts( (array) $state['pending'], (array) ( $payload['uploads'] ?? [] ), $opened['deadline'], $job );

		$state['slice'] = null;
		$this->save_end( $raw, $state, $now );

		return $this->report( $state, $failures, null, ( $this->ms )() - $started );
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
