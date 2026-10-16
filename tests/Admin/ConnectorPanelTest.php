<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Admin;

use G2RD\Connector\Security\QueueSignature;
use G2RD\Connector\Security\RequestSignature;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Panneau React du connecteur (assets/admin/src) : deux modes de dessin.
 *
 * - Mode console : le thème G2RD a posé window.G2RDOptions.console.version ≥ 2 ;
 *   le panneau n'emploie alors que les classes du contrat de la console et sa
 *   feuille n'y ajoute que de la mise en page.
 * - Mode autonome : console plus ancienne (thème 1.39 de g2rd.fr) ; mêmes
 *   éléments, classes « g2rd-connector-… » dessinées par la feuille du connecteur.
 *
 * Le dépôt n'a pas d'exécuteur de tests JavaScript (aucune dépendance npm n'est
 * ajoutée pour cela) : ces tests lisent les sources du panneau et de sa feuille.
 */
final class ConnectorPanelTest extends TestCase {

	/**
	 * Classes stables du contrat de la console du thème G2RD, version 2 (thème
	 * 1.41.0 et plus, docs/plugin-wordpress.md du thème), sans le préfixe « g2rd- ».
	 */
	private const CONTRAT_CONSOLE_V2 = [
		'section',
		'section__head',
		'section__title',
		'section__desc',
		'section__footnote',
		'setting-list',
		'setting-row',
		'setting-row__icon',
		'setting-row__main',
		'setting-row__title',
		'setting-row__desc',
		'setting-row__control',
		'toggle',
		'chip',
		'chip--muted',
		'status',
		'status--ok',
		'status--warn',
		'status__dot',
		'field__label',
		'field__input',
		'field__hint',
		'notice-inline',
	];

	/**
	 * Propriétés permises aux règles qui s'appliquent aussi en mode console : de la
	 * mise en page, jamais de couleur, de police, de bordure ni d'ombre (le thème
	 * dessine le panneau).
	 */
	private const PROPRIETES_DE_MISE_EN_PAGE = [
		'display',
		'flex',
		'flex-direction',
		'flex-wrap',
		'flex-shrink',
		'align-items',
		'justify-content',
		'gap',
		'grid-template-columns',
		'min-width',
		'max-width',
		'margin',
		'position',
		'bottom',
		'z-index',
	];

	public function test_le_panneau_suit_l_ordre_des_quatre_sections(): void {
		preg_match_all( "/cx\(\s*'section__title'\s*\)\s*}[^>]*>\s*([^<{]+?)\s*</", self::panneau(), $titres );

		self::assertSame(
			[ 'Connexion au manager', 'Échanges avec le manager', 'Sécurité des commandes', 'Diagnostic' ],
			$titres[1],
			'Les titres de section du panneau (cx( \'section__title\' )) ne sont pas, dans cet ordre, ceux attendus.'
		);
	}

	public function test_les_reglages_portent_leurs_libelles_francais_dans_l_ordre(): void {
		preg_match_all( '/<SettingRow\b.*?\btitle="([^"]+)"/s', self::panneau(), $lignes );

		self::assertSame(
			[
				'Signe de vie horaire',
				'Événements en temps réel',
				'Commandes à distance',
				'IPv4 vers le manager',
				'Connexion directe depuis G2RD',
				'Exiger des commandes signées',
			],
			$lignes[1],
			'Les lignes de réglage (<SettingRow title="…">) ne sont pas, dans cet ordre, celles attendues.'
		);
	}

	public function test_le_panneau_affiche_les_etats_les_champs_et_le_diagnostic(): void {
		$source = self::panneau();
		foreach ( [
			'Connecté',
			'Connexion interrompue',
			'Pas encore enrôlé',
			'Adresse du manager',
			'Jeton d’invitation',
			'Dernier signe de vie',
			'Tester la connexion',
			'Enrôler le site',
			'Déconnecter du manager',
			'Enregistrer',
			'Version du connecteur',
			'Points de restauration',
			'Dernière capture des mises à jour tierces',
		] as $texte ) {
			self::assertStringContainsString( $texte, $source, "Texte « {$texte} » absent du panneau." );
		}
		foreach ( [ 'Heartbeat horaire', 'Webhook events', 'URL du manager', 'Token d' ] as $ancien ) {
			self::assertStringNotContainsString( $ancien, $source, "Ancien libellé « {$ancien} » encore présent dans le panneau." );
		}
	}

	/**
	 * Chaque code d'échec de signature que le connecteur peut compter (constantes
	 * CODE_* de RequestSignature et QueueSignature) a sa phrase dans le panneau :
	 * un code ajouté côté PHP sans traduction fait échouer ce test.
	 */
	public function test_chaque_code_d_echec_de_signature_a_sa_phrase(): void {
		$codes = array_merge(
			( new ReflectionClass( RequestSignature::class ) )->getConstants(),
			( new ReflectionClass( QueueSignature::class ) )->getConstants()
		);
		$codes = array_filter( $codes, static fn ( string $nom ): bool => str_starts_with( $nom, 'CODE_' ), ARRAY_FILTER_USE_KEY );
		self::assertNotEmpty( $codes );

		$source = self::panneau();
		foreach ( $codes as $code ) {
			self::assertMatchesRegularExpression(
				'/\b' . preg_quote( (string) $code, '/' ) . ":\s*\n?\s*'[^']{20,}'/",
				$source,
				"Le code d'échec de signature « {$code} » n'a pas de phrase claire dans le panneau."
			);
		}
	}

	public function test_le_mode_console_suit_la_version_du_contrat(): void {
		$ui = self::source( 'assets/admin/src/ui.ts' );

		self::assertMatchesRegularExpression( '/CONSOLE_CONTRACT_MIN\s*=\s*2\s*;/', $ui, 'Version minimale du contrat de la console attendue : 2.' );
		self::assertMatchesRegularExpression(
			'/window\.G2RDOptions\?\.console\?\.version.*?>=\s*CONSOLE_CONTRACT_MIN/s',
			$ui,
			'Le mode console doit dépendre de window.G2RDOptions.console.version ≥ CONSOLE_CONTRACT_MIN.'
		);
		self::assertMatchesRegularExpression( '/`g2rd-\$\{\s*part\s*\}`/', $ui, 'En mode console, cx() doit rendre la classe du thème « g2rd-<partie> ».' );
		self::assertMatchesRegularExpression( '/`g2rd-connector-\$\{\s*part\s*\}`/', $ui, 'En mode autonome, cx() doit rendre la classe propre « g2rd-connector-<partie> ».' );
	}

	/**
	 * En mode console, toutes les classes du panneau viennent du contrat (cx()),
	 * aucune n'est écrite en dur avec le préfixe du thème.
	 */
	public function test_en_mode_console_le_panneau_n_emploie_que_les_classes_du_contrat(): void {
		$parties = self::parties_employees();
		self::assertNotEmpty( $parties );
		self::assertSame( [], array_values( array_diff( $parties, self::CONTRAT_CONSOLE_V2 ) ), 'Classes hors du contrat de la console v2.' );

		self::assertSame(
			0,
			preg_match_all( '/(?<![\w-])g2rd-(?!connector-)[a-z]/', self::sans_commentaires( self::panneau() ) ),
			'Classe du thème écrite en dur dans le panneau : passer par cx(), sinon le mode autonome la perd.'
		);
	}

	public function test_en_mode_autonome_chaque_classe_est_dessinee_par_la_feuille(): void {
		$css = self::sans_commentaires( self::source( 'assets/admin/src/style.css' ) );
		foreach ( self::parties_employees() as $partie ) {
			self::assertMatchesRegularExpression(
				'/\.g2rd-connector-panel--autonome[^{]*\.g2rd-connector-' . preg_quote( $partie, '/' ) . '(?![\w-])/',
				$css,
				"Classe « g2rd-connector-{$partie} » du mode autonome non dessinée dans style.css."
			);
		}
	}

	/**
	 * Hors du mode autonome et du correctif des cases de la page PHP, la feuille ne
	 * fait que de la mise en page, et ne redessine jamais une classe du thème.
	 */
	public function test_en_mode_console_la_feuille_ne_fait_que_la_mise_en_page(): void {
		$css = self::sans_commentaires( self::source( 'assets/admin/src/style.css' ) );
		self::assertSame( 0, preg_match( '/\.g2rd-(?!connector-)/', $css ), 'La feuille du connecteur vise une classe du thème.' );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $regles, PREG_SET_ORDER );
		self::assertNotEmpty( $regles );
		foreach ( $regles as [ , $selecteurs, $declarations ] ) {
			$selecteurs = trim( $selecteurs );
			if ( str_contains( $selecteurs, '.g2rd-connector-wrap' ) ) {
				continue;
			}
			$hors_autonome = array_filter(
				array_map( 'trim', explode( ',', $selecteurs ) ),
				static fn ( string $s ): bool => ! str_contains( $s, '.g2rd-connector-panel--autonome' )
			);
			if ( [] === $hors_autonome ) {
				continue;
			}
			preg_match_all( '/([a-z-]+)\s*:/', $declarations, $proprietes );
			self::assertSame(
				[],
				array_values( array_diff( $proprietes[1], self::PROPRIETES_DE_MISE_EN_PAGE ) ),
				"La règle « {$selecteurs} » s'applique aussi en mode console : mise en page seulement."
			);
		}
	}

	public function test_les_actions_et_messages_restent(): void {
		$source = self::panneau();
		foreach ( [ "'g2rd/v1/admin/save'", "'g2rd/v1/admin/enroll'", "'g2rd/v1/admin/unenroll'", "'g2rd/v1/health'", 'aria-live="polite"', 'window.confirm(' ] as $attendu ) {
			self::assertStringContainsString( $attendu, $source, "« {$attendu} » absent du panneau." );
		}
	}

	public function test_la_feuille_garde_le_correctif_des_cases_cochees(): void {
		$css = self::source( 'assets/admin/src/style.css' );
		self::assertStringContainsString( 'body.wp-admin .g2rd-connector-wrap.g2rd-connector-wrap--wp7 input[type="checkbox"]:checked {', $css );
		self::assertStringContainsString( 'body.wp-admin .g2rd-connector-wrap.g2rd-connector-wrap--wp7 input[type="checkbox"]:checked:not(:focus) {', $css );
	}

	/**
	 * Parties de classe passées à cx() par le panneau (chaînes littérales).
	 *
	 * @return list<string>
	 */
	private static function parties_employees(): array {
		preg_match_all( '/\bcx\(([^)]*)\)/', self::sans_commentaires( self::panneau() ), $appels );
		preg_match_all( "/'([^']+)'/", implode( ' ', $appels[1] ), $parties );
		$parties = array_values( array_unique( $parties[1] ) );
		sort( $parties );
		return $parties;
	}

	private static function panneau(): string {
		return self::source( 'assets/admin/src/ConnectorPanel.tsx' );
	}

	private static function source( string $chemin ): string {
		$source = is_readable( dirname( __DIR__, 2 ) . '/' . $chemin ) ? file_get_contents( dirname( __DIR__, 2 ) . '/' . $chemin ) : false;
		self::assertIsString( $source, "{$chemin} est absent ou illisible." );
		return $source;
	}

	private static function sans_commentaires( string $source ): string {
		return (string) preg_replace( [ '#/\*.*?\*/#s', '#(?<![:\'"])//[^\n]*#' ], '', $source );
	}
}
