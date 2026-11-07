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

	public function test_au_plus_vingt_entrees(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			UpdateWitness::add( 'update_plugin', false, self::NOW + $i );
		}

		self::assertCount( UpdateWitness::MAX_ENTRIES, UpdateWitness::load()['entries'] );
	}
}
