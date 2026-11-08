<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Commands;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BackupRefusedTest extends TestCase {

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function codes(): iterable {
		foreach ( BackupRefused::CODES as $code ) {
			yield $code => [ $code ];
		}
	}

	#[DataProvider( 'codes' )]
	public function test_chaque_refus_dit_quoi_la_cause_et_l_action( string $code ): void {
		$message = ( new BackupRefused( $code, null, 'interrupted_by_update' ) )->getMessage();

		self::assertStringContainsString( ' Action : ', $message );
		self::assertStringEndsWith( '.', $message );
		self::assertStringNotContainsString( $code . ' ', $message, 'le code reste dans error_code, pas dans la phrase' );
		self::assertStringNotContainsString( '%', $message );
		self::assertStringNotContainsString( "\n", $message );
	}

	public function test_les_codes_sont_exactement_ceux_du_contrat(): void {
		self::assertSame(
			[
				'backup_slice_running',
				'update_in_progress',
				'backup_lock_unavailable',
				'backup_store_unavailable',
				'backup_db_read_failed',
				'backup_disabled',
				'backup_no_site_key',
				'backup_key_version_mismatch',
				'site_key_certificate_invalid',
				'site_key_version_downgrade',
				'backup_bad_site_key',
				'backup_invalid_payload',
				'backup_already_running',
				'backup_unknown',
				'backup_interrupted',
				'crypto_unavailable',
				'self_test_failed',
				'backup_disk_low',
				'backup_work_dir_unavailable',
				'backup_too_many_segments',
				'backup_queue_unsupported',
				'backup_db_charset_mismatch',
				'backup_db_name_unsupported',
				'backup_db_tables_not_found',
				'backup_db_cursor_invalid',
				'site_key_other_site',
				'backup_files_root_unreadable',
			],
			BackupRefused::CODES
		);
	}

	/**
	 * Décision n° 3 du point de contrôle n° 2 de la phase 4 : au départ, « n'a pas été lancée … rien
	 * n'a été modifié » ; sauvegarde déjà commencée, phrase « en cours », même code stable.
	 */
	public function test_une_sauvegarde_commencee_a_sa_propre_phrase(): void {
		foreach ( [ BackupRefused::WORK_DIR_UNAVAILABLE, BackupRefused::CRYPTO_UNAVAILABLE ] as $code ) {
			$depart   = new BackupRefused( $code );
			$en_cours = $depart->in_progress();

			self::assertStringStartsWith( 'La sauvegarde n’a pas été lancée : ', $depart->getMessage(), $code . ' : refus au départ inchangé' );
			self::assertStringContainsString( 'Rien n’a été modifié.', $depart->getMessage(), $code );
			self::assertSame( $code, $en_cours->outcome()['error_code'], $code . ' : code stable inchangé' );
			self::assertArrayNotHasKey( 'retry_after', $en_cours->outcome(), $code );
			self::assertStringStartsWith( 'La sauvegarde en cours a été arrêtée et ne sera pas terminée : ', $en_cours->getMessage(), $code );
			self::assertStringNotContainsString( 'n’a pas été lancée', $en_cours->getMessage(), $code );
			self::assertStringNotContainsString( 'Rien n’a été modifié', $en_cours->getMessage(), $code );
			self::assertStringContainsString( 'ce qu’elle avait déjà envoyé au stockage ne compte pas comme une sauvegarde', $en_cours->getMessage(), $code . ' : dit ce qui a été envoyé' );
			self::assertStringContainsString( ' Cause probable : ', $en_cours->getMessage(), $code );
			self::assertStringContainsString( ' Action : ', $en_cours->getMessage(), $code );
			self::assertSame( $en_cours->getMessage(), $en_cours->in_progress()->getMessage(), $code . ' : sans effet la seconde fois' );
		}
		self::assertStringContainsString( 'Aucun index n’a été envoyé', ( new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ) )->in_progress()->getMessage() );
		$autre = new BackupRefused( BackupRefused::DISK_LOW, null, '114 Mio' );
		self::assertSame( $autre, $autre->in_progress(), 'sans effet pour un autre code' );
	}

	public function test_une_cle_non_enregistree_a_sa_propre_phrase(): void {
		$cle   = ( new BackupRefused( BackupRefused::STORE_UNAVAILABLE, null, BackupRefused::DETAIL_KEY ) )->outcome();
		$etat  = ( new BackupRefused( BackupRefused::STORE_UNAVAILABLE ) )->outcome();
		$autre = ( new BackupRefused( BackupRefused::KEY_OTHER_SITE ) )->outcome();

		self::assertSame( 'backup_store_unavailable', $cle['error_code'], 'même code : la plateforme réessaie de même' );
		self::assertSame( 30, $cle['retry_after'] );
		self::assertStringContainsString( 'n’a pas pu être enregistrée', $cle['error'] );
		self::assertStringNotContainsString( 'clé', $etat['error'], 'phrase de l’état de sauvegarde inchangée' );
		self::assertArrayNotHasKey( 'retry_after', $autre );
		self::assertStringContainsString( 'autre site ou une autre agence', $autre['error'] );
	}

	public function test_le_resultat_porte_code_et_delai_bornes(): void {
		$resultat = ( new BackupRefused( BackupRefused::SLICE_RUNNING, 999 ) )->outcome();

		self::assertSame( 'failed', $resultat['status'] );
		self::assertSame( 'backup_slice_running', $resultat['error_code'] );
		self::assertSame( 60, $resultat['retry_after'], 'borné à 60 s' );
		self::assertStringStartsWith( 'L’opération n’a pas été lancée : ', $resultat['error'] );
	}

	public function test_un_refus_non_reessayable_n_a_pas_de_delai(): void {
		self::assertArrayNotHasKey( 'retry_after', ( new BackupRefused( BackupRefused::DISABLED ) )->outcome() );
	}

	public function test_l_etape_d_un_autotest_en_echec_est_portee(): void {
		$refus = ( new BackupRefused( BackupRefused::SELF_TEST_FAILED ) )->with_stage( 'index' );

		self::assertSame( 'index', $refus->stage() );
		self::assertSame( 'index', $refus->outcome()['stage'] );
		self::assertStringContainsString( 'Étape : signature de l’index', $refus->getMessage() );
		self::assertArrayNotHasKey( 'stage', ( new BackupRefused( BackupRefused::SELF_TEST_FAILED ) )->outcome(), 'étape inconnue : absente' );
		self::assertNull( ( new BackupRefused( BackupRefused::DISABLED, null, '', 'index' ) )->stage(), 'seul self_test_failed porte une étape' );
	}

	public function test_un_code_inconnu_est_une_erreur_de_programmation(): void {
		$this->expectException( \InvalidArgumentException::class );
		new BackupRefused( 'nimporte' );
	}
}
