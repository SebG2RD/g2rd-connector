<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Contrat du bloc `php_errors` v1, partagé à l'octet avec le manager (lot E0, tâche 8) : même
 * empreinte, mêmes bornes, mêmes valeurs ; chaque exemple est conforme et le contrôle attrape
 * chaque écart.
 */
final class SnapshotPhpErrorsFixtureTest extends PHPUnitTestCase {

	/** Empreinte de backend/tests/fixtures/snapshot-php-errors-v1.json du manager (4 918 octets). */
	private const SHA256 = 'aea5dbdc7d82c69f9ce014ad1b4761b88c63454f19731abc230569b48c194191';

	public function test_la_fixture_est_celle_du_manager_a_l_octet(): void {
		$raw = (string) file_get_contents( PhpErrorsContract::FIXTURE );

		self::assertSame( self::SHA256, hash( 'sha256', $raw ), 'Recopier backend/tests/fixtures/snapshot-php-errors-v1.json du manager tel quel (tâche 2).' );
		self::assertStringNotContainsString( "\r", $raw, 'Fins de ligne LF.' );
		self::assertStringEndsWith( "}\n", $raw, 'Saut de ligne final.' );
	}

	public function test_les_bornes_et_les_valeurs_sont_celles_du_contrat(): void {
		$fixture = PhpErrorsContract::fixture();

		self::assertSame(
			[
				'max_bytes'         => PhpErrorsContract::MAX_BYTES,
				'max_groups'        => PhpErrorsContract::MAX_GROUPS,
				'message_max_chars' => PhpErrorsContract::MESSAGE_MAX_CHARS,
				'file_max_chars'    => PhpErrorsContract::FILE_MAX_CHARS,
			],
			$fixture['limits']
		);
		self::assertSame(
			[
				'level'          => PhpErrorsContract::LEVELS,
				'component_type' => PhpErrorsContract::COMPONENT_TYPES,
				'coverage'       => PhpErrorsContract::COVERAGES,
				'sources'        => PhpErrorsContract::SOURCES,
			],
			$fixture['values']
		);
	}

	public function test_chaque_exemple_respecte_le_contrat(): void {
		$examples = PhpErrorsContract::fixture()['examples'];
		self::assertSame( [ 'collecte_complete', 'collecte_desactivee', 'journal_lu_tronque' ], array_keys( $examples ) );

		$types = [];
		foreach ( $examples as $name => $block ) {
			self::assertSame( [], PhpErrorsContract::violations( $block ), $name );
			foreach ( (array) $block['groups'] as $group ) {
				$types[] = $group['component']['type'];
			}
		}
		self::assertEqualsCanonicalizing( PhpErrorsContract::COMPONENT_TYPES, array_values( array_unique( $types ) ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function ecarts(): iterable {
		yield 'version inconnue' => [ 'version', 'version différente de 1' ];
		yield 'chemin absolu' => [ 'chemin', 'file du groupe 0' ];
		yield 'adresse e-mail dans le message' => [ 'email', 'message du groupe 0' ];
		yield 'niveau inconnu' => [ 'niveau', 'level du groupe 0' ];
		yield 'collecte coupée avec des groupes' => [ 'coupee', 'collecte coupée' ];
		yield 'plus de 16 Ko' => [ 'taille', 'octets' ];
		yield 'champ manquant' => [ 'champ', 'clés du groupe 0' ];
		yield 'type de composant inconnu' => [ 'composant', 'type de composant du groupe 0' ];
		yield 'cinquante et un groupes' => [ 'groupes', '51 groupes' ];
		yield 'dates inversées' => [ 'dates', 'first_seen après last_seen' ];
		yield 'compte nul' => [ 'compte', 'count du groupe 0' ];
		// Propre au connecteur : json_encode() refuse le bloc, sa taille ne se mesure pas.
		yield 'texte qui n\'est pas en UTF-8 valide' => [ 'utf8', 'bloc non encodable en JSON' ];
	}

	#[DataProvider( 'ecarts' )]
	public function test_le_controle_attrape_chaque_ecart( string $cas, string $attendu ): void {
		$block = PhpErrorsContract::fixture()['examples']['collecte_complete'];
		$first = $block['groups'][0];

		$devie = match ( $cas ) {
			'version'   => array_replace( $block, [ 'version' => 2 ] ),
			'chemin'    => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'file' => '/home/u123456789/domains/exemple.fr/public_html/wp-content/plugins/x/x.php' ] ) ] ] ),
			'email'     => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'message' => 'Envoi refusé pour contact@exemple.fr' ] ) ] ] ),
			'niveau'    => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'level' => 'critical' ] ) ] ] ),
			'coupee'    => array_replace( $block, [ 'enabled' => false, 'coverage' => 'none', 'sources' => [], 'collected_at' => null ] ),
			'taille'    => array_replace( $block, [ 'groups' => array_fill( 0, 40, array_replace( $first, [ 'message' => str_repeat( 'a', 480 ) ] ) ) ] ),
			'champ'     => array_replace( $block, [ 'groups' => [ array_diff_key( $first, [ 'fp' => true ] ) ] ] ),
			'composant' => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'component' => [ 'type' => 'library', 'slug' => 'x', 'version' => null ] ] ) ] ] ),
			'groupes'   => array_replace( $block, [ 'groups' => array_fill( 0, PhpErrorsContract::MAX_GROUPS + 1, $first ) ] ),
			'dates'     => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'first_seen' => '2026-10-25T06:00:00+00:00', 'last_seen' => '2026-10-24T06:00:00+00:00' ] ) ] ] ),
			'compte'    => array_replace( $block, [ 'groups' => [ array_replace( $first, [ 'count' => 0 ] ) ] ] ),
			// Plus de 19 Ko d'octets invalides : sans ce contrôle, la taille comptait 0 octet.
			'utf8'      => array_replace( $block, [ 'groups' => array_fill( 0, 40, array_replace( $first, [ 'message' => str_repeat( "\xff", 480 ) ] ) ) ] ),
		};

		$violations = PhpErrorsContract::violations( $devie );

		self::assertNotSame( [], $violations, $cas );
		self::assertStringContainsString( $attendu, implode( ' | ', $violations ), $cas );
	}
}
