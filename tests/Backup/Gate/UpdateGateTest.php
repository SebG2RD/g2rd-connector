<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Gate;

use G2RD\Connector\Backup\Gate\UpdateGate;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class UpdateGateTest extends BackupTestCase {

	private const DONE = [
		'status' => 'done',
		'result' => [],
	];

	public function test_la_liste_fermee_des_commandes(): void {
		self::assertSame( [ 'update_plugin', 'update_theme', 'update_core', 'update_translations', 'rollback_plugin' ], UpdateGate::COMMANDS );
		self::assertFalse( UpdateGate::applies_to( 'optimize_database' ) );
		self::assertFalse( UpdateGate::applies_to( 'clear_cache' ) );
	}

	public function test_a_porte_transparente_sauvegardes_eteintes_aucun_verrou_aucune_ecriture(): void {
		$resultat = UpdateGate::around( 'update_core', static fn (): array => self::DONE );

		self::assertSame( self::DONE, $resultat );
		self::assertSame( [], $this->wpdb->locks_and_writes(), 'ni verrou, ni témoin, ni écriture' );
		self::assertCount( 1, $this->wpdb->queries, 'une seule lecture : l\'état' );
	}

	public function test_a_etat_illisible_et_case_decochee_porte_transparente(): void {
		$this->wpdb->read_fails = true;

		self::assertSame( self::DONE, UpdateGate::around( 'update_core', static fn (): array => self::DONE ) );
		self::assertSame( [], $this->wpdb->locks_and_writes() );
	}

	public function test_case_decochee_en_pleine_copie_la_porte_interrompt(): void {
		$this->put_state( [ 'phase' => BackupState::FILES ] );

		UpdateGate::around( 'update_plugin', static fn (): array => self::DONE );

		self::assertSame( BackupState::ABORT, BackupState::load()['state']['phase'] );
		self::assertSame( 'interrupted_by_update', BackupState::load()['state']['reason'] );
	}

	public function test_verrou_pris_l_entree_existe_pendant_la_commande_et_disparait_apres(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$vu = null;

		UpdateGate::around(
			'update_core',
			function () use ( &$vu ): array {
				$vu = [ UpdateWitness::load()['entries'], $this->wpdb->held_here ];
				return self::DONE;
			}
		);

		self::assertCount( 1, $vu[0] );
		self::assertTrue( $vu[0][0]['held'] );
		self::assertSame( [ $this->lock_name() => 1 ], $vu[1], 'verrou tenu pendant toute la commande' );
		self::assertSame( [], UpdateWitness::load()['entries'] );
		self::assertSame( [], $this->wpdb->held_here, 'verrou rendu' );
	}

	public function test_d_verrou_tenu_sans_temoin_seul_cas_de_refus(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state(
			[
				'phase' => BackupState::FILES,
				'slice' => [
					'started_at' => time() - 5,
					'budget'     => 20,
				],
			]
		);
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;
		$appelee = false;

		$resultat = UpdateGate::around(
			'update_plugin',
			static function () use ( &$appelee ): array {
				$appelee = true;
				return self::DONE;
			}
		);

		self::assertFalse( $appelee, 'rien de modifié' );
		self::assertSame( 'failed', $resultat['status'] );
		self::assertSame( 'backup_slice_running', $resultat['error_code'] );
		self::assertGreaterThanOrEqual( 19, $resultat['retry_after'] );
		self::assertLessThanOrEqual( 21, $resultat['retry_after'] );
		self::assertSame( BackupState::FILES, BackupState::load()['state']['phase'], 'la sauvegarde n\'est pas interrompue par un refus' );
	}

	public function test_c_verrou_tenu_avec_temoin_les_deux_mises_a_jour_passent(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$premiere = (string) UpdateWitness::add( 'update_core', true, time() );
		$this->wpdb->held_elsewhere[ $this->lock_name() ] = true;
		$pendant = [];

		$resultat = UpdateGate::around(
			'update_plugin',
			static function () use ( &$pendant ): array {
				$pendant = UpdateWitness::load()['entries'];
				return self::DONE;
			}
		);

		self::assertSame( self::DONE, $resultat, 'jamais refusée à cause d\'une autre mise à jour' );
		self::assertCount( 2, $pendant );
		self::assertFalse( $pendant[1]['held'] );
		self::assertIsInt( $pendant[1]['until'] );
		self::assertSame( [ $premiere ], array_column( UpdateWitness::load()['entries'], 'id' ), 'seule la sienne est retirée' );
	}

	public function test_b_verrou_impossible_la_mise_a_jour_passe_et_la_copie_cede(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::DB ] );
		$this->wpdb->locks_unavailable = true;
		$entrees = [];

		$resultat = UpdateGate::around(
			'update_theme',
			static function () use ( &$entrees ): array {
				$entrees = UpdateWitness::load()['entries'];
				return self::DONE;
			}
		);

		self::assertSame( self::DONE, $resultat );
		self::assertSame( BackupState::ABORT, BackupState::load()['state']['phase'] );
		self::assertCount( 1, $entrees );
		self::assertFalse( $entrees[0]['held'], 'entrée « sans verrou »' );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_une_mise_a_jour_pendant_l_envoi_n_interrompt_pas(): void {
		$this->settings( [ 'backup_enabled' => true ] );
		$this->put_state( [ 'phase' => BackupState::UPLOAD ] );

		UpdateGate::around( 'update_core', static fn (): array => self::DONE );

		self::assertSame( BackupState::UPLOAD, BackupState::load()['state']['phase'], 'tout était copié (choix n° 3)' );
	}

	public function test_passage_ouvert_par_la_route_en_arriere_plan_non_repris(): void {
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertNull( UpdateGate::enter( 'update_plugin', time() ) );
		self::assertTrue( UpdateGate::holding() );
		$verrous = count( $this->wpdb->locks_and_writes() );

		UpdateGate::around( 'update_plugin', static fn (): array => self::DONE );
		self::assertSame( $verrous, count( $this->wpdb->locks_and_writes() ), 'aucun second GET_LOCK, aucune seconde entrée' );
		self::assertTrue( UpdateGate::holding(), 'le passage reste à la requête qui l\'a ouvert' );

		UpdateGate::leave();
		self::assertFalse( UpdateGate::holding() );
		self::assertSame( [], $this->wpdb->held_here );
		self::assertSame( [], UpdateWitness::load()['entries'] );
		UpdateGate::leave();
		self::assertSame( [], $this->wpdb->held_here, 'leave() idempotent' );
	}

	public function test_passage_transparent_ouvert_par_la_route_en_arriere_plan_non_repris(): void {
		self::assertNull( UpdateGate::enter( 'update_plugin', time() ) );
		self::assertTrue( UpdateGate::transparent() );
		$requetes = count( $this->wpdb->queries );

		self::assertSame( self::DONE, UpdateGate::around( 'update_plugin', static fn (): array => self::DONE ) );
		self::assertSame( $requetes, count( $this->wpdb->queries ), 'aucune seconde lecture de l\'état (au plus deux par mise à jour, P4-2)' );
		self::assertTrue( UpdateGate::transparent(), 'le passage reste à la requête qui l\'a ouvert' );
		self::assertSame( [], $this->wpdb->locks_and_writes(), 'ni verrou, ni témoin, ni écriture' );

		UpdateGate::leave();
		self::assertFalse( UpdateGate::transparent() );
	}

	public function test_une_commande_qui_leve_rend_le_verrou_et_retire_son_entree(): void {
		$this->settings( [ 'backup_enabled' => true ] );

		try {
			UpdateGate::around( 'update_core', static fn (): never => throw new \RuntimeException( 'panne' ) );
		} catch ( \RuntimeException ) {
			// Attendu.
		}

		self::assertSame( [], $this->wpdb->held_here );
		self::assertSame( [], UpdateWitness::load()['entries'] );
	}

	public function test_a_wpdb_incomplet_porte_transparente_sans_avertissement(): void {
		$GLOBALS['wpdb'] = new \stdClass();

		self::assertSame( self::DONE, UpdateGate::around( 'update_core', static fn (): array => self::DONE ) );
		self::assertFalse( UpdateGate::holding() );
	}

	public function test_le_passage_transparent_est_retenu_le_temps_de_la_commande(): void {
		$vu = null;

		UpdateGate::around(
			'update_core',
			static function () use ( &$vu ): array {
				$vu = UpdateGate::transparent();
				return self::DONE;
			}
		);

		self::assertTrue( $vu, 'le second filet ne relit pas l\'état au début de la mise à jour (tâche 13)' );
		self::assertFalse( UpdateGate::transparent(), 'oublié à la fermeture' );
	}
}
