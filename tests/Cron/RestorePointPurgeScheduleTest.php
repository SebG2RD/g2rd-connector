<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Cron;

use Brain\Monkey\Functions;
use G2RD\Connector\Cron\RestorePointPurgeJob;
use G2RD\Connector\Plugin;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\TestCase;

/**
 * Purge locale des points de restauration et des tickets consommés : deux fois par
 * jour au lieu de toutes les heures (un démarrage de WordPress par heure et par
 * site en moins). L'ancienne planification horaire est migrée au démarrage et à
 * l'activation, une seule fois.
 *
 * Le même job reprend une mise à jour protégée morte en route sans être passée par
 * le filet de shutdown (processus tué) : un contrôle ponctuel est programmé dès
 * qu'une telle mise à jour s'ouvre, pour ne pas attendre le passage biquotidien.
 * Ce contrôle a son propre hook (RestorePointPurgeJob::RECOVERY_HOOK) : il ne lance
 * pas la purge, et ne se mêle pas à la planification biquotidienne.
 *
 * Le planificateur de WordPress est simulé en mémoire (un tableau d'événements,
 * tous hooks confondus, comme l'option `cron`).
 */
final class RestorePointPurgeScheduleTest extends TestCase {

	private const T = 1789000000;

	/** @var list<array{hook: string, timestamp: int, schedule: string|false}> Événements planifiés, triés par date. */
	private array $events = [];

	private bool $unschedule_fails = false;

	protected function setUp(): void {
		parent::setUp();
		$this->events           = [];
		$this->unschedule_fails = false;

		Functions\when( 'wp_get_scheduled_event' )->alias(
			function ( string $hook ) {
				$next = $this->next( $hook );
				if ( null === $next ) {
					return false;
				}
				return (object) [
					'hook'      => $hook,
					'timestamp' => $next['timestamp'],
					'schedule'  => $next['schedule'],
					'args'      => [],
				];
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			fn ( string $hook ) => $this->next( $hook )['timestamp'] ?? false
		);
		Functions\when( 'wp_schedule_event' )->alias(
			function ( int $timestamp, string $recurrence, string $hook ): bool {
				$this->add( $timestamp, $recurrence, $hook );
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $timestamp, string $hook ): bool {
				$this->add( $timestamp, false, $hook );
				return true;
			}
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			function ( int $timestamp, string $hook ): bool {
				if ( $this->unschedule_fails ) {
					return false;
				}
				$before       = count( $this->events );
				$this->events = array_values( array_filter( $this->events, static fn ( array $e ): bool => ! ( $e['hook'] === $hook && $e['timestamp'] === $timestamp ) ) );
				return count( $this->events ) < $before;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( string $hook ): int {
				$before       = count( $this->events );
				$this->events = array_values( array_filter( $this->events, static fn ( array $e ): bool => $e['hook'] !== $hook ) );
				return $before - count( $this->events );
			}
		);
	}

	public function test_la_purge_et_le_controle_de_reprise_ont_chacun_leur_hook(): void {
		$job = new RestorePointPurgeJob();
		$job->register();

		self::assertNotSame( RestorePointPurgeJob::HOOK, RestorePointPurgeJob::RECOVERY_HOOK, 'Sinon WordPress écarte un contrôle à moins de 10 min de la purge, et le contrôle lancerait la purge.' );
		self::assertNotFalse( has_action( RestorePointPurgeJob::HOOK, [ $job, 'run' ] ) );
		self::assertNotFalse( has_action( RestorePointPurgeJob::RECOVERY_HOOK, [ $job, 'run_recovery_check' ] ) );
	}

	public function test_une_installation_neuve_est_planifiee_deux_fois_par_jour(): void {
		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
		self::assertSame( 'twicedaily', RestorePointPurgeJob::RECURRENCE );
	}

	public function test_l_ancienne_planification_horaire_est_migree_au_demarrage(): void {
		$this->add( self::T, 'hourly' );

		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules(), 'L\'événement horaire est retiré, remplacé par un seul événement biquotidien.' );
		self::assertSame( self::T, $this->events[0]['timestamp'], 'La prochaine purge garde la date prévue.' );
	}

	public function test_la_migration_est_idempotente(): void {
		$this->add( self::T, 'hourly' );

		RestorePointPurgeJob::schedule();
		RestorePointPurgeJob::schedule();
		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
	}

	public function test_une_planification_deja_biquotidienne_n_est_pas_touchee(): void {
		$this->add( self::T, 'twicedaily' );
		Functions\expect( 'wp_unschedule_event' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
	}

	/** Si WordPress refuse de retirer l'ancien événement, on ne crée pas de doublon : nouvel essai au prochain démarrage. */
	public function test_un_echec_du_retrait_ne_cree_pas_de_doublon(): void {
		$this->add( self::T, 'hourly' );
		$this->unschedule_fails = true;

		self::assertFalse( RestorePointPurgeJob::migrate_legacy_schedule() );
		self::assertSame( [ 'hourly' ], $this->schedules() );

		$this->unschedule_fails = false;
		self::assertTrue( RestorePointPurgeJob::migrate_legacy_schedule() );
		self::assertSame( [ 'twicedaily' ], $this->schedules() );
	}

	public function test_l_activation_migre_l_ancienne_planification(): void {
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		$this->add( self::T, 'hourly' );

		Plugin::activate();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
	}

	/** L'activation ne planifie toujours rien d'elle-même : la planification suit l'enrôlement (démarrage). */
	public function test_l_activation_ne_cree_pas_d_evenement(): void {
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );

		Plugin::activate();

		self::assertSame( [], $this->events );
	}

	/** La désactivation retire aussi un contrôle de reprise en attente, pas seulement la purge. */
	public function test_la_desactivation_retire_la_purge_et_le_controle_de_reprise(): void {
		$this->add( self::T, 'twicedaily' );
		$this->add( self::T + 600, false, RestorePointPurgeJob::RECOVERY_HOOK );
		$this->add( self::T + 900, 'hourly', 'g2rd_un_autre_hook' );

		RestorePointPurgeJob::unschedule();

		self::assertSame( [ 'g2rd_un_autre_hook' ], array_column( $this->events, 'hook' ), 'Les événements des autres hooks restent.' );
	}

	public function test_le_controle_de_reprise_passe_juste_apres_le_delai_de_transaction_morte(): void {
		RestorePointPurgeJob::schedule_recovery_check( self::T );

		self::assertSame(
			[
				[
					'hook'      => RestorePointPurgeJob::RECOVERY_HOOK,
					'timestamp' => self::T + RestorePointPurgeJob::RECOVERY_CHECK_DELAY,
					'schedule'  => false,
				],
			],
			$this->events,
			'Un événement ponctuel, sur le hook du contrôle (jamais sur celui de la purge).'
		);
		self::assertGreaterThan( UpdateTransaction::STALE_AFTER_SECONDS, RestorePointPurgeJob::RECOVERY_CHECK_DELAY, 'Avant ce délai, la transaction ne serait pas encore déclarée morte.' );
		self::assertLessThan( 3600, RestorePointPurgeJob::RECOVERY_CHECK_DELAY, 'Jamais plus tard que l\'ancien passage horaire.' );
	}

	/** Un contrôle de reprise en attente ne retarde pas la migration de l'événement horaire. */
	public function test_un_controle_de_reprise_en_attente_ne_retarde_pas_la_migration(): void {
		$this->add( self::T, 'hourly' );
		RestorePointPurgeJob::schedule_recovery_check( self::T - 3600 ); // Plus proche que l'événement horaire.

		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
		self::assertSame( [ false ], $this->schedules( RestorePointPurgeJob::RECOVERY_HOOK ), 'Le contrôle en attente reste programmé.' );
	}

	/**
	 * Événement biquotidien disparu (désynchronisation, migration interrompue) alors
	 * qu'un contrôle de reprise attend : la purge est replanifiée dès le démarrage
	 * suivant, sans attendre le passage du contrôle.
	 */
	public function test_un_controle_de_reprise_en_attente_n_empeche_pas_de_replanifier_la_purge(): void {
		RestorePointPurgeJob::schedule_recovery_check( self::T );

		RestorePointPurgeJob::schedule();

		self::assertSame( [ 'twicedaily' ], $this->schedules() );
		self::assertSame( [ false ], $this->schedules( RestorePointPurgeJob::RECOVERY_HOOK ) );
	}

	private function add( int $timestamp, string|false $schedule, string $hook = RestorePointPurgeJob::HOOK ): void {
		$this->events[] = [
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'schedule'  => $schedule,
		];
		usort( $this->events, static fn ( array $a, array $b ): int => $a['timestamp'] <=> $b['timestamp'] );
	}

	/**
	 * Prochain événement d'un hook.
	 *
	 * @return array{hook: string, timestamp: int, schedule: string|false}|null
	 */
	private function next( string $hook ): ?array {
		foreach ( $this->events as $event ) {
			if ( $event['hook'] === $hook ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Rythmes des événements d'un hook (false : ponctuel), par date.
	 *
	 * @return list<string|false>
	 */
	private function schedules( string $hook = RestorePointPurgeJob::HOOK ): array {
		return array_column( array_values( array_filter( $this->events, static fn ( array $e ): bool => $e['hook'] === $hook ) ), 'schedule' );
	}
}
