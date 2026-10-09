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
		parent::setUp();
		$this->cache = [];
		// Aucune transaction ouverte par « ce processus » au début du test : l'identité
		// retenue par open() est statique, un test précédent a pu la laisser.
		( new \ReflectionProperty( UpdateTransaction::class, 'opened' ) )->setValue( null, null );

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

	// ── Outils ────────────────────────────────────────────────────────────────

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
