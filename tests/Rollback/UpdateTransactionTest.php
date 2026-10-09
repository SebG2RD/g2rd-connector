<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Rollback\UpdateTransaction;
use G2RD\Connector\Tests\TestCase;

/**
 * Journal de transaction d'une mise à jour protégée, vu par la requête qui la mène
 * pendant qu'un autre processus (le contrôle de reprise du cron) peut la fermer.
 *
 * WordPress garde chaque option lue dans un cache (propre à la requête, ou partagé
 * avec un cache objet persistant) : ce cache est simulé ici au-dessus de la table
 * d'options ($this->options, « la base »), avec la même règle que delete_option()
 * (ligne absente en base : renvoie faux sans toucher au cache). Un autre processus
 * écrit dans la base sans passer par le cache de cette requête.
 */
final class UpdateTransactionTest extends TestCase {

	private const NOW = 1789000000;

	/** @var array<string, mixed> Cache d'options de la requête (groupe `options`). */
	private array $cache = [];

	protected function setUp(): void {
		// Aucune transaction tenue par « ce processus » au début du test : remise à zéro
		// dans TestCase::setUp().
		parent::setUp();
		$this->cache = [];

		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				if ( array_key_exists( $key, $this->cache ) ) {
					return $this->cache[ $key ];
				}
				if ( ! array_key_exists( $key, $this->options ) ) {
					return $fallback;
				}
				$this->cache[ $key ] = $this->options[ $key ];
				return $this->cache[ $key ];
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				$this->cache[ $key ]   = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				if ( ! array_key_exists( $key, $this->options ) ) {
					return false; // Comme WordPress : rien en base, le cache reste tel quel.
				}
				unset( $this->options[ $key ], $this->cache[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( string $key, string $group = '' ): bool {
				if ( 'options' !== $group || ! array_key_exists( $key, $this->cache ) ) {
					return false;
				}
				unset( $this->cache[ $key ] );
				return true;
			}
		);
	}

	// ── Cas nominal : inchangé ───────────────────────────────────────────────────

	public function test_step_avance_la_transaction_ouverte_par_la_requete(): void {
		self::assertTrue( UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW ) );
		$id = $this->stored()['id'];

		UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => 'p1' ], self::NOW + 10 );

		$stored = $this->stored();
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $stored['step'] );
		self::assertSame( 'p1', $stored['restore_point_id'] );
		self::assertSame( self::NOW + 10, $stored['updated_at'] );
		self::assertSame( self::NOW, $stored['started_at'] );
		self::assertSame( $id, $stored['id'], 'Même transaction : même identifiant.' );
	}

	public function test_touch_rafraichit_la_date_sans_changer_d_etape(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		UpdateTransaction::step( UpdateTransaction::STEP_HEALTH, [], self::NOW + 5 );

		UpdateTransaction::touch( self::NOW + 30 );

		$stored = $this->stored();
		self::assertSame( UpdateTransaction::STEP_HEALTH, $stored['step'] );
		self::assertSame( self::NOW + 30, $stored['updated_at'] );
	}

	/** L'identifiant distingue deux transactions de la même extension ouvertes à la même seconde. */
	public function test_chaque_ouverture_a_son_propre_identifiant(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		$first = $this->stored()['id'];
		UpdateTransaction::close();
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );

		self::assertIsString( $first );
		self::assertNotSame( '', $first );
		self::assertNotSame( $first, $this->stored()['id'] );
	}

	/** Transaction ouverte par la version d'avant (sans identifiant), pendant une mise à jour du connecteur : toujours suivie. */
	public function test_une_transaction_sans_identifiant_reste_rafraichie(): void {
		$this->options[ UpdateTransaction::OPTION_KEY ] = [
			'plugin_file' => 'a/a.php',
			'step'        => UpdateTransaction::STEP_HEALTH,
			'started_at'  => self::NOW,
			'updated_at'  => self::NOW,
		];

		UpdateTransaction::touch( self::NOW + 30 );

		self::assertSame( self::NOW + 30, $this->stored()['updated_at'] );
	}

	// ── Fermée par un autre processus : jamais recréée ───────────────────────────

	/**
	 * Le contrôle de reprise a fermé la transaction (et restauré l'extension) pendant
	 * que cette requête, encore vivante, la croit ouverte : son cache d'options en a
	 * gardé la copie. touch() ne doit pas la recréer avec une date fraîche.
	 */
	public function test_touch_ne_recree_pas_une_transaction_fermee_par_un_autre_processus(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::current() );
		$this->closed_by_another_process();

		UpdateTransaction::touch( self::NOW + 700 );

		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options, 'Jamais recréée : ses fichiers ont déjà été restaurés.' );
		self::assertNull( UpdateTransaction::current() );
	}

	public function test_step_ne_recree_pas_une_transaction_fermee_par_un_autre_processus(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::current() );
		$this->closed_by_another_process();

		UpdateTransaction::step( UpdateTransaction::STEP_HEALTH, [ 'restore_point_id' => 'p1' ], self::NOW + 700 );

		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
		self::assertNull( UpdateTransaction::current() );
	}

	/** Fermée puis remplacée par la transaction d'une autre mise à jour : celle-ci n'est pas touchée. */
	public function test_la_transaction_d_une_autre_mise_a_jour_n_est_pas_reecrite(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::current() );
		$other = [
			'id'          => 'autre-transaction',
			'plugin_file' => 'b/b.php',
			'step'        => UpdateTransaction::STEP_SNAPSHOT,
			'started_at'  => self::NOW + 650,
			'updated_at'  => self::NOW + 650,
		];

		$this->options[ UpdateTransaction::OPTION_KEY ] = $other; // Écrite par un autre processus, en base seulement.

		UpdateTransaction::touch( self::NOW + 700 );
		UpdateTransaction::step( UpdateTransaction::STEP_ROLLING_BACK, [], self::NOW + 701 );

		self::assertSame( $other, $this->options[ UpdateTransaction::OPTION_KEY ] );
	}

	/**
	 * Cache objet désynchronisé : la ligne a déjà disparu de la base, delete_option()
	 * renvoie faux sans vider le cache. close() le vide lui-même, la transaction n'est
	 * plus vue comme ouverte.
	 */
	public function test_close_vide_aussi_le_cache_quand_la_ligne_a_deja_disparu(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::current() );
		$this->closed_by_another_process();

		UpdateTransaction::close();

		self::assertNull( UpdateTransaction::current() );
	}

	// ── Seule la transaction du processus : step() le dit, close() s'y tient ─────

	public function test_step_signale_une_transaction_disparue_ou_remplacee(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertTrue( UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [], self::NOW + 10 ) );

		$this->options[ UpdateTransaction::OPTION_KEY ] = $this->other_transaction();
		self::assertFalse( UpdateTransaction::step( UpdateTransaction::STEP_HEALTH, [], self::NOW + 20 ), 'Remplacée par une autre mise à jour.' );

		$this->closed_by_another_process();
		self::assertFalse( UpdateTransaction::step( UpdateTransaction::STEP_HEALTH, [], self::NOW + 30 ), 'Fermée par un autre processus.' );
	}

	public function test_close_retire_la_transaction_ouverte_par_la_requete(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );

		UpdateTransaction::close();

		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
		self::assertNull( UpdateTransaction::current() );
	}

	/** Relue en base (le cache de la requête a encore la sienne) : celle d'une autre mise à jour reste. */
	public function test_close_ne_retire_pas_la_transaction_d_une_autre_mise_a_jour(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		$other = $this->other_transaction();
		$this->options[ UpdateTransaction::OPTION_KEY ] = $other; // En base seulement.

		UpdateTransaction::close();

		self::assertSame( $other, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null );
	}

	/**
	 * Le contrôle de reprise a pris la transaction de cette requête pour morte (étape
	 * de plus de 10 min) et l'a réservée : même identité, mais elle n'est plus celle
	 * de la requête. Ni avancée, ni rafraîchie, ni retirée sous la reprise.
	 */
	public function test_une_transaction_reservee_par_une_reprise_n_est_plus_celle_de_la_requete(): void {
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::held() );
		$reserved = $this->reserved_by_another_process( $this->stored(), self::NOW + 700 );

		self::assertFalse( UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => 'p1' ], self::NOW + 701 ) );
		UpdateTransaction::touch( self::NOW + 702 );
		self::assertNull( UpdateTransaction::held(), 'Le filet de shutdown ne la reprendra pas une seconde fois.' );
		UpdateTransaction::close();

		self::assertSame( $reserved, $this->options[ UpdateTransaction::OPTION_KEY ] ?? null );
	}

	// ── Reprise réservée avant toute restauration ────────────────────────────────

	public function test_une_reprise_reserve_la_transaction_morte_et_bloque_l_ouverture(): void {
		$dead = $this->dead_transaction();
		$this->options[ UpdateTransaction::OPTION_KEY ] = $dead;

		$reserved = UpdateTransaction::reserve( $dead, self::NOW );

		self::assertNotNull( $reserved );
		$stored = $this->stored();
		self::assertSame( $stored, $reserved );
		self::assertSame( UpdateTransaction::STEP_RECOVERING, $stored['step'] );
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $stored['recovering_from'], 'Ce que faisait la mise à jour quand elle est morte.' );
		self::assertSame( self::NOW, $stored['updated_at'], 'Fraîche : aucun autre processus ne la reprend.' );
		self::assertSame( UpdateTransaction::identity( $dead ), UpdateTransaction::identity( $stored ), 'Même transaction.' );
		self::assertSame( 'p1', $stored['restore_point_id'] );
		self::assertTrue( UpdateTransaction::files_may_be_dirty( $stored ) );

		self::assertFalse( UpdateTransaction::open( [ 'plugin_file' => 'b/b.php' ], self::NOW + 5 ), 'Pas de nouvelle mise à jour par-dessus une reprise en cours.' );
		self::assertSame( $stored, $this->stored() );

		UpdateTransaction::close();
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
		self::assertTrue( UpdateTransaction::open( [ 'plugin_file' => 'b/b.php' ], self::NOW + 6 ) );
	}

	/** Reprise en cours dans un autre processus (le cron) : ni ouverture, ni seconde reprise. */
	public function test_une_reprise_fraiche_d_un_autre_processus_refuse_l_ouverture_et_une_seconde_reprise(): void {
		$dead     = $this->dead_transaction();
		$reserved = $this->reserved_by_another_process( $dead, self::NOW - 30 );

		self::assertFalse( UpdateTransaction::open( [ 'plugin_file' => 'b/b.php' ], self::NOW ) );
		self::assertNull( UpdateTransaction::reserve( $reserved, self::NOW ), 'Fraîche : pas morte.' );
		self::assertNull( UpdateTransaction::reserve( $dead, self::NOW ), 'Lue avant la réservation : elle a changé depuis.' );
		self::assertSame( $reserved, $this->stored() );
		self::assertNull( UpdateTransaction::held() );
	}

	/** Le cache de la requête a encore la transaction morte : la réservation de l'autre processus est relue en base. */
	public function test_une_reprise_relit_la_base_avant_de_reserver(): void {
		$dead = $this->dead_transaction();
		$this->options[ UpdateTransaction::OPTION_KEY ] = $dead;
		self::assertSame( $dead, UpdateTransaction::current() ); // En cache.
		$reserved = $this->reserved_by_another_process( $dead, self::NOW - 5 );

		self::assertNull( UpdateTransaction::reserve( $dead, self::NOW ) );
		self::assertSame( $reserved, $this->stored() );
	}

	/**
	 * Deux reprises parties au même instant (le cron et une mise à jour) : chacune
	 * écrit son jeton ; l'écriture de l'autre arrive juste après celle de cette
	 * requête, avant sa relecture. Seule celle dont le jeton est resté en base continue.
	 */
	public function test_deux_reprises_simultanees_une_seule_continue(): void {
		$dead = $this->dead_transaction();
		$this->options[ UpdateTransaction::OPTION_KEY ] = $dead;
		$cron = $dead;
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) use ( &$cron ): bool {
				$this->options[ $key ] = $value;
				$this->cache[ $key ]   = $value;
				if ( UpdateTransaction::OPTION_KEY === $key ) {
					$cron = array_merge(
						$value,
						[
							'recovery_token' => 'jeton-du-cron',
							'updated_at'     => self::NOW + 1,
						]
					);
					$this->options[ $key ] = $cron; // L'autre processus, en base seulement.
				}
				return true;
			}
		);

		self::assertNull( UpdateTransaction::reserve( $dead, self::NOW ) );
		self::assertSame( $cron, $this->stored() );
		self::assertNull( UpdateTransaction::held() );
	}

	/** Reprise tuée en route (processus arrêté) : reprise à son tour, avec l'étape d'origine. */
	public function test_une_reprise_interrompue_est_reprise_avec_l_etape_d_origine(): void {
		$killed = $this->reserved_by_another_process( $this->dead_transaction(), self::NOW - UpdateTransaction::STALE_AFTER_SECONDS - 100 );

		self::assertNotNull( UpdateTransaction::reserve( $killed, self::NOW ) );

		$stored = $this->stored();
		self::assertSame( UpdateTransaction::STEP_UPGRADING, $stored['recovering_from'] );
		self::assertNotSame( $killed['recovery_token'], $stored['recovery_token'] );
		self::assertTrue( UpdateTransaction::files_may_be_dirty( $stored ), 'La restauration interrompue a pu laisser des fichiers à moitié remplacés.' );
	}

	public function test_une_reprise_compte_comme_fichiers_peut_etre_modifies_selon_l_etape_reprise(): void {
		$recovering = [
			'plugin_file' => 'a/a.php',
			'step'        => UpdateTransaction::STEP_RECOVERING,
		];

		self::assertTrue( UpdateTransaction::files_may_be_dirty( $recovering + [ 'recovering_from' => UpdateTransaction::STEP_UPGRADING ] ) );
		self::assertTrue( UpdateTransaction::files_may_be_dirty( $recovering + [ 'recovering_from' => UpdateTransaction::STEP_ROLLING_BACK ] ) );
		self::assertTrue( UpdateTransaction::files_may_be_dirty( $recovering ), 'Étape d\'origine inconnue : on suppose le pire.' );
		self::assertFalse( UpdateTransaction::files_may_be_dirty( $recovering + [ 'recovering_from' => UpdateTransaction::STEP_HEALTH ] ), 'Mise à jour terminée : la reprise ne touche pas aux fichiers.' );
		self::assertFalse( UpdateTransaction::files_may_be_dirty( $recovering + [ 'recovering_from' => UpdateTransaction::STEP_SNAPSHOT ] ) );
	}

	/** Transaction ouverte par une version d'avant l'identifiant : reconnue par son extension et sa date d'ouverture. */
	public function test_la_reprise_d_une_transaction_sans_identifiant_la_retire(): void {
		$old = [
			'plugin_file' => 'a/a.php',
			'step'        => UpdateTransaction::STEP_UPGRADING,
			'started_at'  => self::NOW - 1000,
			'updated_at'  => self::NOW - 900,
		];
		$this->options[ UpdateTransaction::OPTION_KEY ] = $old;

		self::assertNotNull( UpdateTransaction::reserve( $old, self::NOW ) );
		UpdateTransaction::close();

		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	/** Le filet de shutdown ne reprend de force que la transaction de ce processus. */
	public function test_une_reprise_forcee_ne_vaut_que_pour_la_transaction_du_processus(): void {
		$this->options[ UpdateTransaction::OPTION_KEY ] = $this->other_transaction();
		self::assertNull( UpdateTransaction::reserve( $this->other_transaction(), self::NOW, true ), 'Pas la sienne.' );

		unset( $this->options[ UpdateTransaction::OPTION_KEY ], $this->cache[ UpdateTransaction::OPTION_KEY ] );
		UpdateTransaction::open( [ 'plugin_file' => 'a/a.php' ], self::NOW );
		self::assertNotNull( UpdateTransaction::reserve( $this->stored(), self::NOW + 5, true ), 'La sienne, même fraîche.' );
	}

	/**
	 * Reprise faite, mais close() n'a pas pu retirer la transaction (trace de reprise) :
	 * elle ne protège plus rien, même réservée il y a moins de 10 minutes.
	 */
	public function test_une_transaction_deja_reprise_ne_bloque_plus_l_ouverture(): void {
		$reserved = $this->reserved_by_another_process( $this->dead_transaction(), self::NOW - 30 );
		$this->options[ UpdateTransaction::RECOVERY_TRACE_KEY ] = [ 'txn' => UpdateTransaction::identity( $reserved ) ];

		self::assertFalse( UpdateTransaction::is_live( $reserved, self::NOW ) );
		self::assertTrue( UpdateTransaction::open( [ 'plugin_file' => 'b/b.php' ], self::NOW ) );
	}

	public function test_discard_ne_retire_que_la_transaction_lue(): void {
		$dead  = $this->dead_transaction();
		$other = $this->other_transaction();
		$this->options[ UpdateTransaction::OPTION_KEY ] = $other;

		UpdateTransaction::discard( $dead );
		self::assertSame( $other, $this->stored() );

		$this->options[ UpdateTransaction::OPTION_KEY ] = $dead;
		UpdateTransaction::discard( $dead );
		self::assertArrayNotHasKey( UpdateTransaction::OPTION_KEY, $this->options );
	}

	// ── Outils ────────────────────────────────────────────────────────────────

	/**
	 * Transaction d'une mise à jour morte pendant l'étape « mise à jour ».
	 *
	 * @return array<string, mixed>
	 */
	private function dead_transaction(): array {
		return [
			'id'               => 'morte',
			'plugin_file'      => 'a/a.php',
			'was_active'       => true,
			'step'             => UpdateTransaction::STEP_UPGRADING,
			'restore_point_id' => 'p1',
			'started_at'       => self::NOW - 1000,
			'updated_at'       => self::NOW - 900,
		];
	}

	/**
	 * Transaction d'une autre mise à jour, vivante.
	 *
	 * @return array<string, mixed>
	 */
	private function other_transaction(): array {
		return [
			'id'          => 'autre-transaction',
			'plugin_file' => 'b/b.php',
			'step'        => UpdateTransaction::STEP_SNAPSHOT,
			'started_at'  => self::NOW + 650,
			'updated_at'  => self::NOW + 650,
		];
	}

	/**
	 * Un autre processus (le contrôle de reprise) réserve la transaction, en base seulement.
	 *
	 * @param array<string, mixed> $txn
	 * @return array<string, mixed> La transaction réservée.
	 */
	private function reserved_by_another_process( array $txn, int $at ): array {
		$reserved = array_merge(
			$txn,
			[
				'step'            => UpdateTransaction::STEP_RECOVERING,
				'recovering_from' => $txn['step'],
				'recovery_token'  => 'jeton-du-cron',
				'updated_at'      => $at,
			]
		);
		$this->options[ UpdateTransaction::OPTION_KEY ] = $reserved;
		return $reserved;
	}

	/** Un autre processus supprime la ligne en base ; le cache de cette requête n'en sait rien. */
	private function closed_by_another_process(): void {
		unset( $this->options[ UpdateTransaction::OPTION_KEY ] );
	}

	/**
	 * @return array<string, mixed> La transaction telle qu'elle est en base.
	 */
	private function stored(): array {
		self::assertArrayHasKey( UpdateTransaction::OPTION_KEY, $this->options );
		return $this->options[ UpdateTransaction::OPTION_KEY ];
	}
}
