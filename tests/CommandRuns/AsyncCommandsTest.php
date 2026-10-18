<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use G2RD\Connector\CommandRuns\AsyncCommands;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Commandes que la route `POST /g2rd/v1/command-runs` accepte d'exécuter en arrière-plan
 * (décision n° 5 du propriétaire : toutes, sauf les quatre instantanées qui restent sur
 * `/command`), et sonde de diagnostic dormante (décision n° 4).
 */
final class AsyncCommandsTest extends TestCase {

	/**
	 * Toute commande du site est classée : admise en arrière-plan OU exclue, jamais les
	 * deux. Une commande ajoutée plus tard à CommandExecutor::ALLOWED fait échouer ce test
	 * tant qu'elle n'est pas rangée dans l'une des deux listes.
	 */
	public function test_les_commandes_admises_et_exclues_couvrent_exactement_les_commandes_du_site(): void {
		$classees = array_merge( AsyncCommands::ADMITTED, AsyncCommands::EXCLUDED );
		$du_site  = CommandExecutor::ALLOWED;
		sort( $classees );
		sort( $du_site );

		self::assertSame( $du_site, $classees );
		self::assertSame( [], array_values( array_intersect( AsyncCommands::ADMITTED, AsyncCommands::EXCLUDED ) ) );
		self::assertSame( AsyncCommands::ADMITTED, array_values( array_unique( AsyncCommands::ADMITTED ) ) );
		self::assertSame( AsyncCommands::EXCLUDED, array_values( array_unique( AsyncCommands::EXCLUDED ) ) );
	}

	public function test_les_dix_commandes_longues_passent_en_arriere_plan_et_les_quatre_instantanees_restent_sur_command(): void {
		$admises = AsyncCommands::ADMITTED;
		$exclues = AsyncCommands::EXCLUDED;
		sort( $admises );
		sort( $exclues );

		self::assertSame(
			[
				'delete_expired_transients',
				'delete_post_revisions',
				'delete_spam_comments',
				'empty_trashed_posts',
				'optimize_database',
				'rollback_plugin',
				'update_core',
				'update_plugin',
				'update_theme',
				'update_translations',
			],
			$admises
		);
		self::assertSame( [ 'check_updates', 'clear_cache', 'delete_restore_point', 'set_signature_policy' ], $exclues );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function admitted_commands(): iterable {
		foreach ( AsyncCommands::ADMITTED as $command ) {
			yield $command => [ $command ];
		}
	}

	#[DataProvider( 'admitted_commands' )]
	public function test_une_commande_admise_l_est_que_la_sonde_soit_allumee_ou_non( string $command ): void {
		self::assertTrue( AsyncCommands::is_admitted( $command, false ) );
		self::assertTrue( AsyncCommands::is_admitted( $command, true ) );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function refused_commands(): iterable {
		foreach ( AsyncCommands::EXCLUDED as $command ) {
			yield 'exclue ' . $command => [ $command ];
		}
		yield 'inconnue' => [ 'drop_database' ];
		yield 'vide' => [ '' ];
		yield 'casse différente' => [ 'Update_Plugin' ];
		yield 'espace en trop' => [ 'update_plugin ' ];
	}

	#[DataProvider( 'refused_commands' )]
	public function test_une_commande_exclue_ou_inconnue_n_est_jamais_admise( string $command ): void {
		self::assertFalse( AsyncCommands::is_admitted( $command, false ) );
		self::assertFalse( AsyncCommands::is_admitted( $command, true ) );
	}

	public function test_la_sonde_n_est_admise_qu_avec_sa_constante(): void {
		self::assertSame( 'async_probe', AsyncCommands::PROBE );
		self::assertFalse( AsyncCommands::is_admitted( AsyncCommands::PROBE, false ) );
		self::assertTrue( AsyncCommands::is_admitted( AsyncCommands::PROBE, true ) );
	}

	/**
	 * La sonde n'existe que sur la route asynchrone : ni `/command` ni la file tirée par le
	 * battement de cœur ne doivent pouvoir la lancer.
	 */
	public function test_la_sonde_n_est_pas_une_commande_du_site(): void {
		self::assertNotContains( AsyncCommands::PROBE, CommandExecutor::ALLOWED );
		self::assertNotContains( AsyncCommands::PROBE, AsyncCommands::ADMITTED );
		self::assertNotContains( AsyncCommands::PROBE, AsyncCommands::EXCLUDED );
	}

	/**
	 * Ces commandes sont refusées pendant une mise à jour protégée vivante (§ 3.4) : elles
	 * doivent donc d'abord être admises en arrière-plan.
	 */
	public function test_les_commandes_qui_touchent_aux_fichiers_d_extension_sont_admises(): void {
		self::assertSame( [ 'update_plugin', 'rollback_plugin' ], AsyncCommands::TOUCHES_PLUGIN_FILES );
		foreach ( AsyncCommands::TOUCHES_PLUGIN_FILES as $command ) {
			self::assertContains( $command, AsyncCommands::ADMITTED );
		}
	}
}
