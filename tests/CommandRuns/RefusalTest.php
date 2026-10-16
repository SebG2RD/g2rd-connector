<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use G2RD\Connector\CommandRuns\Refusal;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * Refus des deux routes d'exécution en arrière-plan : le code que la plateforme lit
 * (lot 2, client des routes), le statut HTTP, et un message lisible au format de
 * HeartbeatJob::refusal_message() (quoi, cause probable, action).
 */
final class RefusalTest extends TestCase {

	private const NUMERO = '0123456789abcdef0123456789abcdef';

	/** 16/10/2026 14:00:00 UTC. */
	private const DEPUIS = 1792159200;

	/**
	 * Le contrat : code WordPress et statut HTTP de chaque refus, écrits en toutes lettres.
	 *
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function refus(): iterable {
		yield 'site_busy' => [ Refusal::SITE_BUSY, 'g2rd_connector_site_busy', 409 ];
		yield 'command_id_conflict' => [ Refusal::COMMAND_ID_CONFLICT, 'g2rd_connector_command_id_conflict', 409 ];
		yield 'invalid_command_id' => [ Refusal::INVALID_COMMAND_ID, 'g2rd_connector_invalid_command_id', 400 ];
		yield 'command_not_async' => [ Refusal::COMMAND_NOT_ASYNC, 'g2rd_connector_command_not_async', 400 ];
		yield 'invalid_payload' => [ Refusal::INVALID_PAYLOAD, 'g2rd_connector_invalid_payload', 400 ];
		yield 'body_too_large' => [ Refusal::BODY_TOO_LARGE, 'g2rd_connector_body_too_large', 413 ];
		yield 'async_disabled' => [ Refusal::ASYNC_DISABLED, 'g2rd_connector_async_disabled', 503 ];
		yield 'run_unknown' => [ Refusal::RUN_UNKNOWN, 'g2rd_connector_run_unknown', 404 ];
	}

	#[DataProvider( 'refus' )]
	public function test_chaque_code_a_son_statut_et_son_code_wordpress( string $code, string $code_wordpress, int $statut ): void {
		$erreur = Refusal::error( $code );

		self::assertInstanceOf( WP_Error::class, $erreur );
		self::assertSame( $code_wordpress, $erreur->get_error_code() );
		self::assertSame( $statut, $erreur->get_error_data()['status'] );
	}

	/**
	 * Un code ajouté à la classe sans entrer dans le contrat ci-dessus fait échouer ce test.
	 */
	public function test_les_codes_sont_exactement_ceux_du_contrat(): void {
		$attendus = array_map( static fn ( array $ligne ): string => $ligne[0], iterator_to_array( self::refus(), false ) );
		$declares = array_values( ( new \ReflectionClass( Refusal::class ) )->getConstants( \ReflectionClassConstant::IS_PUBLIC ) );
		sort( $attendus );
		sort( $declares );

		self::assertSame( $attendus, $declares );
	}

	#[DataProvider( 'refus' )]
	public function test_chaque_message_dit_quoi_et_quoi_faire( string $code ): void {
		$message = Refusal::error( $code )->get_error_message();

		// Quoi : la demande n'a rien lancé, ou l'exécution est inconnue du site.
		$quoi = Refusal::RUN_UNKNOWN === $code ? 'Opération inconnue du site : ' : 'L’opération n’a pas été lancée : ';
		self::assertStringStartsWith( $quoi, $message );
		// Quoi faire : toujours une action, après le constat.
		self::assertStringContainsString( ' Action : ', $message );
		self::assertGreaterThan( strlen( $quoi ), strpos( $message, ' Action : ' ) );
		self::assertStringEndsWith( '.', $message );

		// Rien de technique ni de gabarit oublié.
		self::assertStringNotContainsString( 'g2rd_connector_', $message );
		self::assertStringNotContainsString( '%', $message );
		self::assertStringNotContainsString( '{', $message );
		self::assertStringNotContainsString( "\n", $message );
	}

	/**
	 * Refus rendus par la route d'acceptation : tous, sauf `run_unknown` (lecture).
	 *
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function refus_a_l_acceptation(): iterable {
		foreach ( self::refus() as $nom => $ligne ) {
			if ( Refusal::RUN_UNKNOWN !== $ligne[0] ) {
				yield $nom => $ligne;
			}
		}
	}

	/**
	 * Un statut dit la vérité : un refus à l'acceptation n'a jamais rien exécuté, et le
	 * message le dit.
	 */
	#[DataProvider( 'refus_a_l_acceptation' )]
	public function test_un_refus_a_l_acceptation_dit_que_rien_n_a_ete_modifie( string $code ): void {
		self::assertStringContainsString( ' Rien n’a été modifié', Refusal::error( $code )->get_error_message() );
	}

	/**
	 * Une exécution inconnue n'est ni dite échouée ni dite sans effet : le site ne sait
	 * plus ce qu'elle a fait.
	 */
	public function test_une_execution_inconnue_n_est_dite_ni_echouee_ni_sans_effet(): void {
		$message = Refusal::error( Refusal::RUN_UNKNOWN )->get_error_message();

		self::assertDoesNotMatchRegularExpression( '/échou|échec/iu', $message );
		self::assertStringNotContainsString( 'Rien n’a été modifié', $message );
	}

	public function test_site_busy_porte_le_numero_et_la_date(): void {
		$erreur = Refusal::error(
			Refusal::SITE_BUSY,
			[
				'busy_with'  => self::NUMERO,
				'busy_since' => self::DEPUIS,
			]
		);

		self::assertSame(
			[
				'status'     => 409,
				'busy_with'  => self::NUMERO,
				'busy_since' => '2026-10-16T14:00:00+00:00',
			],
			$erreur->get_error_data()
		);
		self::assertSame(
			'L’opération n’a pas été lancée : une autre opération est en cours sur ce site depuis 2026-10-16 14:00:00 UTC. Rien n’a été modifié. Action : aucune, la plateforme réessaie seule.',
			$erreur->get_error_message()
		);
	}

	/**
	 * Le refus d'ExecutionSlot::acquire() se passe tel quel : ses clés internes (`acquired`,
	 * `reason`) ne sortent pas.
	 */
	public function test_site_busy_accepte_le_refus_du_creneau_tel_quel_sans_ses_cles_internes(): void {
		$erreur = Refusal::error(
			Refusal::SITE_BUSY,
			[
				'acquired'   => false,
				'busy_with'  => self::NUMERO,
				'busy_since' => self::DEPUIS,
				'reason'     => 'run_in_progress',
			]
		);

		self::assertSame( [ 'status', 'busy_with', 'busy_since' ], array_keys( $erreur->get_error_data() ) );
	}

	/**
	 * Mise à jour protégée (ou sa reprise par le cron) en cours : pas d'exécution en
	 * arrière-plan à nommer, seulement l'ouverture de la transaction.
	 */
	public function test_site_busy_par_une_mise_a_jour_protegee_n_a_pas_de_numero(): void {
		$erreur = Refusal::error( Refusal::SITE_BUSY, [ 'busy_since' => self::DEPUIS ] );

		self::assertSame(
			[
				'status'     => 409,
				'busy_with'  => null,
				'busy_since' => '2026-10-16T14:00:00+00:00',
			],
			$erreur->get_error_data()
		);
		self::assertStringContainsString( 'depuis 2026-10-16 14:00:00 UTC.', $erreur->get_error_message() );
	}

	public function test_site_busy_sans_date_connue_ne_l_invente_pas(): void {
		$erreur = Refusal::error( Refusal::SITE_BUSY );

		self::assertSame(
			[
				'status'     => 409,
				'busy_with'  => null,
				'busy_since' => null,
			],
			$erreur->get_error_data()
		);
		self::assertSame(
			'L’opération n’a pas été lancée : une autre opération est en cours sur ce site. Rien n’a été modifié. Action : aucune, la plateforme réessaie seule.',
			$erreur->get_error_message()
		);
	}

	/**
	 * Valeurs d'un autre type que prévu (jamais le cas depuis ExecutionSlot) : écartées,
	 * jamais converties ni relayées.
	 */
	public function test_site_busy_ecarte_des_valeurs_d_un_autre_type(): void {
		$erreur = Refusal::error(
			Refusal::SITE_BUSY,
			[
				'busy_with'  => [ 'pas un numéro' ],
				'busy_since' => '2026-10-16',
			]
		);

		self::assertNull( $erreur->get_error_data()['busy_with'] );
		self::assertNull( $erreur->get_error_data()['busy_since'] );
		self::assertStringNotContainsString( 'depuis', $erreur->get_error_message() );
	}

	/**
	 * Tous les refus, sauf `site_busy`.
	 *
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function refus_sans_donnees(): iterable {
		foreach ( self::refus() as $nom => $ligne ) {
			if ( Refusal::SITE_BUSY !== $ligne[0] ) {
				yield $nom => $ligne;
			}
		}
	}

	/**
	 * Seul `site_busy` rend des données en plus du statut : rien d'interne ne sort par
	 * les autres refus, quoi que l'appelant passe.
	 */
	#[DataProvider( 'refus_sans_donnees' )]
	public function test_les_autres_refus_ne_rendent_que_leur_statut( string $code, string $code_wordpress, int $statut ): void {
		$erreur = Refusal::error(
			$code,
			[
				'busy_with'  => self::NUMERO,
				'busy_since' => self::DEPUIS,
				'db_error'   => 'Table wp_options is marked as crashed',
			]
		);

		self::assertSame( $code_wordpress, $erreur->get_error_code() );
		self::assertSame( [ 'status' => $statut ], $erreur->get_error_data() );
		self::assertStringNotContainsString( 'crashed', $erreur->get_error_message() );
	}

	/**
	 * Erreur de programmation (les appelants passent les constantes) : signalée, jamais
	 * changée en une réponse que la plateforme interpréterait.
	 */
	public function test_un_code_inconnu_est_une_erreur_de_programmation(): void {
		$this->expectException( \InvalidArgumentException::class );

		Refusal::error( 'site_on_fire' );
	}
}
