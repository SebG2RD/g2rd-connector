<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

use G2RD\Connector\DirectLogin\Refusal;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Messages des refus de connexion directe (page wp_die en 403) : quoi, cause
 * probable, action. Jamais de code technique à l'écran.
 */
final class RefusalTest extends TestCase {

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function messages(): iterable {
		yield 'invalide' => [ Refusal::INVALID, 'Ce lien de connexion n\'est pas valide. Il a pu être tronqué lors d\'un copier-coller, ou le site a été reconnecté à G2RD WP Manager entre-temps : relancez la connexion depuis G2RD WP Manager.' ];
		yield 'autre site' => [ Refusal::WRONG_SITE, 'Ce lien de connexion a été préparé pour un autre site. Vérifiez dans G2RD WP Manager que la fiche ouverte correspond bien à ce site, puis relancez la connexion.' ];
		yield 'expiré' => [ Refusal::EXPIRED, 'Ce lien de connexion a expiré. Il n\'est valable qu\'une minute : relancez la connexion depuis G2RD WP Manager.' ];
		yield 'pas encore valable' => [ Refusal::FUTURE, 'Ce lien de connexion n\'est pas encore valable : l\'horloge de ce serveur semble en retard. Relancez la connexion depuis G2RD WP Manager ; si ce message revient, demandez à l\'hébergeur de remettre le serveur à l\'heure.' ];
		yield 'déjà utilisé' => [ Refusal::REPLAYED, 'Ce lien de connexion a déjà été utilisé. Chaque lien ne sert qu\'une fois : relancez la connexion depuis G2RD WP Manager.' ];
		yield 'désactivée' => [ Refusal::DISABLED, 'La connexion directe est désactivée sur ce site. Un administrateur peut la réactiver dans les réglages de G2RD Connector ; en attendant, connectez-vous avec votre identifiant et votre mot de passe.' ];
		yield 'plus administrateur' => [ Refusal::NOT_ADMIN, 'Ce compte n\'a plus les droits d\'administrateur sur ce site. Il a pu être supprimé ou rétrogradé : lancez une synchronisation du site dans G2RD WP Manager puis choisissez un autre compte, ou connectez-vous avec votre identifiant et votre mot de passe.' ];
	}

	#[DataProvider( 'messages' )]
	public function test_chaque_refus_a_son_message_explicite( string $code, string $expected ): void {
		self::assertSame( $expected, Refusal::message( $code ) );
	}

	#[DataProvider( 'messages' )]
	public function test_chaque_message_dit_quoi_faire( string $code ): void {
		self::assertMatchesRegularExpression( '/relancez|connectez-vous/i', Refusal::message( $code ) );
	}

	public function test_un_code_inconnu_retombe_sur_le_lien_non_valide(): void {
		self::assertSame( Refusal::message( Refusal::INVALID ), Refusal::message( 'inconnu' ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function ticket_statuses(): iterable {
		yield 'mal formé' => [ 'malformed', Refusal::INVALID ];
		yield 'mauvaise signature' => [ 'bad_signature', Refusal::INVALID ];
		yield 'autre site' => [ 'wrong_site', Refusal::WRONG_SITE ];
		yield 'expiré' => [ 'expired', Refusal::EXPIRED ];
		yield 'trop dans le futur' => [ 'future', Refusal::FUTURE ];
	}

	#[DataProvider( 'ticket_statuses' )]
	public function test_chaque_verdict_du_ticket_a_son_refus( string $status, string $code ): void {
		self::assertSame( $code, Refusal::from_ticket_status( $status ) );
	}

	/**
	 * Le lien vers la page de connexion (peut-être masquée par le client) n'est
	 * permis que pour un ticket authentique, c'est-à-dire à la signature valide.
	 *
	 * @return iterable<string, array{string, bool}>
	 */
	public static function login_links(): iterable {
		yield 'invalide' => [ Refusal::INVALID, false ];
		yield 'code inconnu' => [ 'inconnu', false ];
		yield 'autre site' => [ Refusal::WRONG_SITE, true ];
		yield 'expiré' => [ Refusal::EXPIRED, true ];
		yield 'pas encore valable' => [ Refusal::FUTURE, true ];
		yield 'déjà utilisé' => [ Refusal::REPLAYED, true ];
		yield 'désactivée' => [ Refusal::DISABLED, true ];
		yield 'plus administrateur' => [ Refusal::NOT_ADMIN, true ];
	}

	#[DataProvider( 'login_links' )]
	public function test_le_lien_de_connexion_n_est_permis_que_pour_un_ticket_authentique( string $code, bool $expected ): void {
		self::assertSame( $expected, Refusal::allows_login_link( $code ) );
	}

	public function test_le_titre_de_la_page(): void {
		self::assertSame( 'Connexion directe impossible', Refusal::title() );
	}
}
