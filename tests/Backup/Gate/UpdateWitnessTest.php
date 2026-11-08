<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class UpdateWitnessTest extends BackupTestCase {

	private const NOW = 1800000000;

	public function test_chaque_mise_a_jour_ajoute_et_ne_retire_que_son_entree(): void {
		$premiere = UpdateWitness::add( 'update_core', true, self::NOW );
		$seconde  = UpdateWitness::add( 'update_plugin', false, self::NOW + 5 );

		self::assertIsString( $premiere );
		self::assertIsString( $seconde );
		self::assertCount( 2, UpdateWitness::load()['entries'] );

		UpdateWitness::remove( $premiere );
		$restantes = UpdateWitness::load()['entries'];
		self::assertCount( 1, $restantes, 'la fin de la première ne retire pas la seconde' );
		self::assertSame( $seconde, $restantes[0]['id'] );
		self::assertFalse( $restantes[0]['held'] );
		self::assertSame( self::NOW + 5 + UpdateWitness::UNLOCKED_TTL_SECONDS, $restantes[0]['until'] );

		UpdateWitness::remove( $seconde );
		self::assertArrayNotHasKey( UpdateWitness::OPTION, $this->wpdb->tables['wp_options'], 'liste vide : ligne retirée' );
	}

	public function test_une_entree_tenant_le_verrou_n_a_pas_d_echeance(): void {
		UpdateWitness::add( 'update_theme', true, self::NOW );

		self::assertNull( UpdateWitness::load()['entries'][0]['until'] );
	}

	public function test_la_tranche_efface_les_entrees_mortes_et_les_echues(): void {
		UpdateWitness::add( 'update_core', true, self::NOW - 30 );
		UpdateWitness::add( 'update_plugin', false, self::NOW - UpdateWitness::UNLOCKED_TTL_SECONDS - 1 );

		self::assertFalse( UpdateWitness::clear_for_slice( self::NOW ), 'plus aucune mise à jour vivante' );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_une_entree_sans_verrou_non_echue_bloque_la_tranche(): void {
		UpdateWitness::add( 'update_plugin', false, self::NOW - 10 );

		self::assertTrue( UpdateWitness::clear_for_slice( self::NOW ) );
		self::assertCount( 1, UpdateWitness::load()['entries'], 'gardée' );
	}

	public function test_temoin_illisible_compte_comme_present(): void {
		$this->wpdb->read_fails = true;

		self::assertTrue( UpdateWitness::has_entries() );
		self::assertTrue( UpdateWitness::clear_for_slice( self::NOW ) );
		self::assertNull( UpdateWitness::add( 'update_core', true, self::NOW ), 'écriture impossible : null, jamais d\'exception' );
	}

	public function test_au_plus_vingt_entrees_les_plus_recentes_gardees(): void {
		$ids = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$ids[] = UpdateWitness::add( 'update_plugin', false, self::NOW + $i );
		}

		self::assertCount( UpdateWitness::MAX_ENTRIES, UpdateWitness::load()['entries'] );
		self::assertSame( array_slice( $ids, 5 ), array_column( UpdateWitness::load()['entries'], 'id' ), 'les cinq plus anciennes sont évincées, dans l\'ordre' );
	}

	public function test_la_fin_d_une_mise_a_jour_garde_l_entree_ajoutee_entre_temps(): void {
		$premiere = (string) UpdateWitness::add( 'update_core', true, self::NOW );
		$autre    = null;
		// Un autre processus ajoute son entrée entre la relecture et l'écriture conditionnelle.
		$this->wpdb->before_write = static function () use ( &$autre ): void {
			$autre = UpdateWitness::add( 'update_plugin', false, self::NOW + 1 );
		};

		UpdateWitness::remove( $premiere );

		self::assertIsString( $autre );
		self::assertSame( [ $autre ], array_column( UpdateWitness::load()['entries'], 'id' ), 'seule l\'entrée de l\'autre mise à jour reste' );
	}

	public function test_deux_ajouts_simultanes_gardent_les_deux_entrees(): void {
		$autre = null;
		// Un autre processus crée l'option entre la relecture (vide) et l'insertion.
		$this->wpdb->before_write = static function () use ( &$autre ): void {
			$autre = UpdateWitness::add( 'update_plugin', false, self::NOW );
		};

		$mienne = UpdateWitness::add( 'update_core', true, self::NOW );

		self::assertIsString( $mienne );
		self::assertIsString( $autre );
		self::assertSame( [ $autre, $mienne ], array_column( UpdateWitness::load()['entries'], 'id' ) );
	}

	public function test_la_tranche_voit_une_mise_a_jour_sans_verrou_ajoutee_pendant_son_nettoyage(): void {
		UpdateWitness::add( 'update_core', true, self::NOW - 30 );
		// Une mise à jour sans verrou s'inscrit pendant que la tranche efface l'entrée morte.
		$this->wpdb->before_write = static function (): void {
			UpdateWitness::add( 'update_plugin', false, self::NOW );
		};

		self::assertTrue( UpdateWitness::clear_for_slice( self::NOW ), 'une mise à jour sans verrou vivante bloque la tranche' );
		$restantes = UpdateWitness::load()['entries'];
		self::assertCount( 1, $restantes );
		self::assertFalse( $restantes[0]['held'] );
	}

	public function test_conflit_permanent_trois_essais_puis_null(): void {
		UpdateWitness::add( 'update_core', true, self::NOW );
		$wpdb   = $this->wpdb;
		$essais = 0;
		$rearme = null;

		// Avant chaque écriture, un autre processus modifie la valeur : l'écriture conditionnelle échoue toujours.
		$rearme = static function () use ( $wpdb, &$rearme, &$essais ): void {
			++$essais;
			$wpdb->tables['wp_options'][ UpdateWitness::OPTION ] .= ' ';

			$wpdb->before_write = $rearme;
		};

		$wpdb->before_write = $rearme;

		self::assertNull( UpdateWitness::add( 'update_plugin', false, self::NOW ), 'jamais d\'exception : null' );
		self::assertSame( 3, $essais, 'exactement trois essais' );
		$wpdb->before_write = null;
	}

	public function test_ecriture_refusee_par_la_base(): void {
		UpdateWitness::add( 'update_core', true, self::NOW - 30 );
		$this->wpdb->write_fails = true;

		self::assertNull( UpdateWitness::add( 'update_plugin', false, self::NOW ), 'écriture refusée : null, jamais d\'exception' );
		UpdateWitness::remove( UpdateWitness::load()['entries'][0]['id'] );
		self::assertTrue( UpdateWitness::clear_for_slice( self::NOW ), 'nettoyage impossible : la tranche refuse' );
		self::assertCount( 1, UpdateWitness::load()['entries'], 'l\'entrée reste' );
	}
}
