<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use Brain\Monkey\Functions;
use G2RD\Connector\ReleaseNotes\NotesAnswer;
use G2RD\Connector\ReleaseNotes\ReleaseNotesRefused;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Réponse de plugins_api() / themes_api() réduite à ce que rend `get_release_notes` : notes bornées
 * à 256 Kio (coupées avant d'être rendues valides, jamais au milieu d'un caractère ni d'une balise),
 * UTF-8 valide, version contrôlée, page d'accueil en https, sans requête ni fragment.
 */
final class NotesAnswerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
	}

	public function test_rend_les_notes_la_version_et_la_page_d_accueil(): void {
		$answer = (object) [
			'version'  => '6.8.11',
			'homepage' => 'https://www.advancedcustomfields.com/',
			'sections' => [
				'description' => '<p>ACF</p>',
				'changelog'   => '<h4>6.8.11</h4><ul><li>Fix</li></ul>',
			],
		];

		self::assertSame(
			[
				'notes_html' => '<h4>6.8.11</h4><ul><li>Fix</li></ul>',
				'truncated'  => false,
				'version'    => '6.8.11',
				'homepage'   => 'https://www.advancedcustomfields.com/',
			],
			NotesAnswer::from( $answer )
		);
	}

	public function test_tableau_sections_en_objet_et_nom_de_section_sans_casse(): void {
		$answer = [
			'version'  => '2.1',
			'sections' => (object) [ 'Changelog' => '<p>2.1</p>' ],
		];

		$result = NotesAnswer::from( $answer );

		self::assertSame( '<p>2.1</p>', $result['notes_html'] );
		self::assertNull( $result['homepage'] );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function reponses_sans_notes(): iterable {
		yield 'sans sections' => [ (object) [ 'version' => '1.0' ] ];
		yield 'sections sans notes' => [ (object) [ 'sections' => [ 'description' => '<p>x</p>' ] ] ];
		yield 'notes vides' => [ (object) [ 'sections' => [ 'changelog' => "  \n " ] ] ];
		yield 'notes qui ne sont pas un texte' => [ (object) [ 'sections' => [ 'changelog' => [ '1.0' => 'x' ] ] ] ];
		yield 'sections qui ne sont pas un tableau' => [ (object) [ 'sections' => 'changelog' ] ];
	}

	#[DataProvider( 'reponses_sans_notes' )]
	public function test_sans_notes_refus_not_provided( mixed $answer ): void {
		self::assertSame( ReleaseNotesRefused::NOT_PROVIDED, self::refusal_of( $answer ) );
	}

	/**
	 * @return iterable<string, array{mixed}>
	 */
	public static function reponses_illisibles(): iterable {
		yield 'null' => [ null ];
		yield 'texte' => [ 'Plugin not found.' ];
		yield 'entier' => [ 0 ];
		yield 'faux' => [ false ];
	}

	#[DataProvider( 'reponses_illisibles' )]
	public function test_reponse_illisible_refus_source_unavailable( mixed $answer ): void {
		self::assertSame( ReleaseNotesRefused::SOURCE_UNAVAILABLE, self::refusal_of( $answer ) );
	}

	/** `truncated` suit la longueur du texte d'origine, coupé avant d'être rendu valide. */
	public function test_truncated_suit_la_longueur_d_origine(): void {
		$juste = NotesAnswer::from( (object) [ 'sections' => [ 'changelog' => str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES ) ] ] );
		$long  = NotesAnswer::from( (object) [ 'sections' => [ 'changelog' => str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES + 2 ) ] ] );
		$tres  = NotesAnswer::from( (object) [ 'sections' => [ 'changelog' => str_repeat( "\xFF", 3 * NotesAnswer::NOTES_MAX_BYTES ) ] ] );

		self::assertFalse( $juste['truncated'] );
		self::assertTrue( $long['truncated'] );
		self::assertSame( NotesAnswer::NOTES_MAX_BYTES, strlen( $long['notes_html'] ) );
		self::assertTrue( $tres['truncated'] );
		self::assertLessThanOrEqual( NotesAnswer::NOTES_MAX_BYTES, strlen( $tres['notes_html'] ) );
		self::assertSame( 1, preg_match( '//u', $tres['notes_html'] ) );
	}

	public function test_exactement_la_borne_n_est_pas_coupe(): void {
		$html = str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES );

		self::assertSame( [ $html, false ], NotesAnswer::bounded( $html ) );
	}

	public function test_au_dela_de_la_borne_le_texte_est_coupe_et_le_dit(): void {
		[ $cut, $truncated ] = NotesAnswer::bounded( str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES + 1 ) );

		self::assertTrue( $truncated );
		self::assertSame( NotesAnswer::NOTES_MAX_BYTES, strlen( $cut ) );
	}

	public function test_un_caractere_n_est_jamais_coupe_en_deux(): void {
		// 1 + 2 × 131 072 octets : la borne tombe au milieu du dernier « é ».
		[ $cut, $truncated ] = NotesAnswer::bounded( 'a' . str_repeat( 'é', 131072 ) );

		self::assertTrue( $truncated );
		self::assertSame( 1, preg_match( '//u', $cut ) );
		self::assertSame( NotesAnswer::NOTES_MAX_BYTES - 1, strlen( $cut ) );
	}

	public function test_une_balise_coupee_part_avec_ce_qui_la_suit(): void {
		$debut = str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES - 10 );

		[ $cut, $truncated ] = NotesAnswer::bounded( $debut . '<a href="https://example.com/">lien</a>' );

		self::assertTrue( $truncated );
		self::assertSame( $debut, $cut );
	}

	public function test_une_balise_complete_avant_la_borne_reste(): void {
		$debut = str_repeat( 'a', NotesAnswer::NOTES_MAX_BYTES - 4 );

		[ $cut ] = NotesAnswer::bounded( $debut . '<b>x</b>' );

		self::assertSame( $debut . '<b>x', $cut );
	}

	public function test_un_utf8_invalide_devient_le_caractere_de_remplacement(): void {
		self::assertSame( "ok \u{FFFD}( fin &lt;b&gt; <b>x</b>", NotesAnswer::valid_utf8( "ok \xC3\x28 fin &lt;b&gt; <b>x</b>" ) );
		self::assertSame( 'déjà valide', NotesAnswer::valid_utf8( 'déjà valide' ) );
	}

	public function test_les_notes_rendues_sont_de_l_utf8_valide(): void {
		$result = NotesAnswer::from( (object) [ 'sections' => [ 'changelog' => "<p>\xFF</p>" ] ] );

		self::assertSame( "<p>\u{FFFD}</p>", $result['notes_html'] );
	}

	/**
	 * @return iterable<string, array{mixed, string|null}>
	 */
	public static function versions(): iterable {
		yield 'trois segments' => [ '6.8.11', '6.8.11' ];
		yield 'bêta' => [ '2.1.0-beta.1', '2.1.0-beta.1' ];
		yield 'espaces autour' => [ ' 6.8.11 ', '6.8.11' ];
		yield 'entier' => [ 7, '7' ];
		yield 'balise' => [ '<script>', null ];
		yield 'espace dedans' => [ '6.8 beta', null ];
		yield 'soixante-quatre caractères' => [ str_repeat( '1', 64 ), str_repeat( '1', 64 ) ];
		yield 'trop longue' => [ str_repeat( '1', 65 ), null ];
		yield 'vide' => [ '', null ];
		yield 'tableau' => [ [ '6.8' ], null ];
		yield 'absente' => [ null, null ];
	}

	#[DataProvider( 'versions' )]
	public function test_version( mixed $version, ?string $attendu ): void {
		self::assertSame( $attendu, NotesAnswer::version( $version ) );
	}

	/**
	 * @return iterable<string, array{mixed, string|null}>
	 */
	public static function pages(): iterable {
		yield 'https' => [ 'https://www.advancedcustomfields.com/', 'https://www.advancedcustomfields.com/' ];
		yield 'schéma et hôte en capitales' => [ 'HTTPS://Example.COM/notes', 'https://example.com/notes' ];
		yield 'espaces autour' => [ ' https://example.com/ ', 'https://example.com/' ];
		yield 'requête et fragment retirés' => [ 'https://vendor.example/changelog?licence=ABC-123#v2', 'https://vendor.example/changelog' ];
		yield 'port gardé' => [ 'https://vendor.example:8443/notes', 'https://vendor.example:8443/notes' ];
		yield 'sans chemin' => [ 'https://example.com', 'https://example.com' ];
		yield 'http' => [ 'http://example.com/', null ];
		yield 'javascript' => [ 'javascript:alert(1)', null ];
		yield 'data' => [ 'data:text/html;base64,PHNjcmlwdD4=', null ];
		yield 'sans schéma' => [ '//example.com/', null ];
		yield 'identifiants' => [ 'https://wordpress.org@evil.example/', null ];
		yield 'mot de passe' => [ 'https://user:pass@example.com/', null ];
		yield 'espace dedans' => [ 'https://example.com/a b', null ];
		yield 'guillemet' => [ 'https://example.com/"onmouseover=x', null ];
		yield 'non ASCII' => [ 'https://exemple.fr/café', null ];
		yield 'hôte encodé' => [ 'https://exa%41mple.com/', null ];
		yield 'hôte IPv6' => [ 'https://[::1]/notes', null ];
		yield 'hôte au libellé vide' => [ 'https://.example.com/', null ];
		yield 'trop longue' => [ 'https://example.com/' . str_repeat( 'a', 2049 ), null ];
		yield 'pas un texte' => [ [ 'https://example.com/' ], null ];
	}

	#[DataProvider( 'pages' )]
	public function test_page_d_accueil( mixed $url, ?string $attendu ): void {
		self::assertSame( $attendu, NotesAnswer::https_url( $url ) );
	}

	private static function refusal_of( mixed $answer ): string {
		try {
			NotesAnswer::from( $answer );
		} catch ( ReleaseNotesRefused $e ) {
			return $e->reason_code();
		}
		self::fail( 'Refus attendu.' );
	}
}
