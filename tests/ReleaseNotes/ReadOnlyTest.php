<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use PHPUnit\Framework\TestCase;

/**
 * Le code de la commande des notes de version n'écrit rien (spécification « Notes de version »,
 * § 5.3 et § 9) : le code de includes/ReleaseNotes/ et de Updates\UpdateSource n'appelle que des
 * fonctions de cette liste fermée, toutes en lecture (ou des crochets posés puis retirés dans la
 * commande, dont les noms sont écrits en toutes lettres et listés ici), n'instancie et n'appelle en
 * statique que les classes et méthodes listées (PremiumUpdatesBridge::replay_entry(), en lecture
 * seule, a son propre test), n'appelle dynamiquement que les deux fermetures connues de HttpLimits,
 * ne touche jamais à $wpdb ni à $GLOBALS et n'écrit rien en sortie. Toute fonction, classe,
 * méthode, crochet ou appel dynamique de plus fait échouer le test tant qu'il n'est pas ajouté ici,
 * sciemment, après avoir vérifié qu'il n'écrit rien.
 *
 * Les sources sont lues par les jetons de PHP (PhpToken), comme tests/Backup/NoWiringTest.php : un
 * nom cité dans un commentaire ne compte pas.
 */
final class ReadOnlyTest extends TestCase {

	/** Fichiers lus, relatifs à la racine. */
	private const FICHIERS = [
		'includes/ReleaseNotes/HttpLimits.php',
		'includes/ReleaseNotes/NotesAnswer.php',
		'includes/ReleaseNotes/ReleaseNotesCommand.php',
		'includes/ReleaseNotes/ReleaseNotesRefused.php',
		'includes/Updates/UpdateSource.php',
	];

	/** Fonctions permises (noms en minuscules) : lectures, calculs, traduction, crochets posés puis retirés. */
	private const FONCTIONS_PERMISES = [
		'__',
		'add_action',
		'add_filter',
		'array_key_exists',
		'basename',
		'dirname',
		'esc_html',
		'function_exists',
		'get_object_vars',
		'get_plugins',
		'get_site_transient',
		'htmlspecialchars',
		'htmlspecialchars_decode',
		'in_array',
		'is_array',
		'is_int',
		'is_numeric',
		'is_object',
		'is_string',
		'max',
		'microtime',
		'min',
		'plugins_api',
		'preg_match',
		'remove_action',
		'remove_filter',
		'str_contains',
		'str_ends_with',
		'str_starts_with',
		'strlen',
		'strpos',
		'strrpos',
		'strtolower',
		'substr',
		'themes_api',
		'trim',
		'wp_get_themes',
		'wp_parse_url',
	];

	/** Crochets que le code a le droit de poser et de retirer (premier argument, écrit en toutes lettres). */
	private const CROCHETS_PERMIS = [ 'http_request_args', 'plugins_api', 'pre_http_request', 'requests-requests.before_redirect', 'themes_api' ];

	/** Classes instanciées permises. */
	private const CLASSES_INSTANCIEES = [ 'HttpLimits', 'InvalidArgumentException', 'ReleaseNotesRefused', 'WP_Error' ];

	/** Appels statiques permis : méthodes nommées des autres classes, et `self::` / `parent::`. */
	private const APPELS_STATIQUES = [ 'NotesAnswer::from', 'PremiumUpdatesBridge::replay_entry', 'UpdateSource::entry', 'UpdateSource::of', 'parent', 'self' ];

	/** Appels dynamiques permis : `$work()` et l'horloge `( $this->clock )()`, dans HttpLimits. */
	private const APPELS_DYNAMIQUES = [ 'includes/ReleaseNotes/HttpLimits.php:$work', 'includes/ReleaseNotes/HttpLimits.php:)(' ];

	public function test_la_liste_des_fichiers_couvre_tout_le_dossier(): void {
		$dossier = glob( self::racine() . '/includes/ReleaseNotes/*.php' );
		self::assertIsArray( $dossier );
		$relatifs = array_map( static fn ( string $chemin ): string => 'includes/ReleaseNotes/' . basename( $chemin ), $dossier );
		sort( $relatifs );

		self::assertSame(
			array_values( array_filter( self::FICHIERS, static fn ( string $f ): bool => str_starts_with( $f, 'includes/ReleaseNotes/' ) ) ),
			$relatifs,
			'Un fichier ajouté à includes/ReleaseNotes/ doit entrer dans FICHIERS (et donc dans la liste fermée).'
		);
	}

	public function test_seules_des_fonctions_de_la_liste_fermee_sont_appelees(): void {
		$hors_liste = [];
		$vues       = [];
		foreach ( self::FICHIERS as $relatif ) {
			foreach ( self::appels( $relatif )['fonctions'] as [ $nom, $ligne ] ) {
				$vues[ strtolower( $nom ) ] = true;
				if ( ! in_array( strtolower( $nom ), self::FONCTIONS_PERMISES, true ) ) {
					$hors_liste[] = $relatif . ':' . $ligne . ' ' . $nom;
				}
			}
		}

		self::assertSame(
			[],
			$hors_liste,
			"Fonction hors de la liste fermée dans le code des notes de version.\n"
			. "Cause probable : une écriture (option, transient, fichier, cache, tâche planifiée) ou un appel réseau ajouté.\n"
			. 'Action : la retirer ; si elle ne fait que lire, l’ajouter à FONCTIONS_PERMISES dans le même commit.'
		);
		// La lecture marche : elle voit les deux appels qui comptent.
		self::assertArrayHasKey( 'plugins_api', $vues );
		self::assertArrayHasKey( 'themes_api', $vues );
	}

	public function test_seuls_les_crochets_connus_sont_poses_ou_retires(): void {
		$ecarts = [];
		$vus    = [];
		foreach ( self::FICHIERS as $relatif ) {
			foreach ( self::appels( $relatif )['crochets'] as [ $crochet, $ligne ] ) {
				$vus[ $crochet ] = true;
				if ( ! in_array( $crochet, self::CROCHETS_PERMIS, true ) ) {
					$ecarts[] = $relatif . ':' . $ligne . ' ' . $crochet;
				}
			}
		}

		self::assertSame( [], $ecarts, 'Crochet non listé, ou nom de crochet qui n’est pas écrit en toutes lettres. Action : écrire le nom en toutes lettres, et l’ajouter à CROCHETS_PERMIS après vérification.' );
		self::assertArrayHasKey( 'requests-requests.before_redirect', $vus );
		self::assertArrayHasKey( 'plugins_api', $vus );
	}

	public function test_aucune_autre_classe_n_est_instanciee_ni_appelee(): void {
		$ecarts = [];
		foreach ( self::FICHIERS as $relatif ) {
			$appels = self::appels( $relatif );
			foreach ( $appels['instanciations'] as [ $classe, $ligne ] ) {
				if ( ! in_array( $classe, self::CLASSES_INSTANCIEES, true ) ) {
					$ecarts[] = $relatif . ':' . $ligne . ' new ' . $classe;
				}
			}
			foreach ( $appels['statiques'] as [ $appel, $ligne ] ) {
				if ( ! in_array( $appel, self::APPELS_STATIQUES, true ) ) {
					$ecarts[] = $relatif . ':' . $ligne . ' ' . $appel;
				}
			}
		}

		self::assertSame( [], $ecarts, 'Classe ou méthode hors de la liste fermée : une autre classe peut écrire (Settings::update…). Action : la retirer, ou l’ajouter ici après vérification.' );
	}

	public function test_seuls_les_appels_dynamiques_connus(): void {
		$ecarts = [];
		$vus    = 0;
		foreach ( self::FICHIERS as $relatif ) {
			foreach ( self::appels( $relatif )['dynamiques'] as [ $texte, $ligne ] ) {
				++$vus;
				if ( ! in_array( $relatif . ':' . $texte, self::APPELS_DYNAMIQUES, true ) ) {
					$ecarts[] = $relatif . ':' . $ligne . ' ' . $texte;
				}
			}
		}

		self::assertSame( [], $ecarts, 'Appel dynamique ($variable() ou ( … )()) hors des deux fermetures connues de HttpLimits.' );
		self::assertGreaterThan( 0, $vus, 'La lecture ne voit plus les fermetures de HttpLimits : détection cassée ?' );
	}

	public function test_ni_base_ni_globales_ni_sortie(): void {
		$interdits = [];
		foreach ( self::FICHIERS as $relatif ) {
			foreach ( self::appels( $relatif )['interdits'] as [ $texte, $ligne ] ) {
				$interdits[] = $relatif . ':' . $ligne . ' ' . $texte;
			}
		}

		self::assertSame( [], $interdits, '$wpdb, $GLOBALS, global, eval, echo, print, exit, sortie HTML ou new $classe dans le code des notes de version.' );
	}

	private static function racine(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Appels du code d'un fichier : fonctions globales, crochets (premier argument des fonctions de
	 * crochet), instanciations, appels statiques, appels dynamiques, et jetons interdits.
	 *
	 * @return array{fonctions: list<array{string, int}>, crochets: list<array{string, int}>, instanciations: list<array{string, int}>, statiques: list<array{string, int}>, dynamiques: list<array{string, int}>, interdits: list<array{string, int}>}
	 */
	private static function appels( string $relatif ): array {
		$source = file_get_contents( self::racine() . '/' . $relatif );
		self::assertIsString( $source, 'Lecture impossible de ' . $relatif . '.' );
		$jetons = array_values( array_filter( \PhpToken::tokenize( $source ), static fn ( \PhpToken $j ): bool => ! $j->isIgnorable() ) );
		$out    = [
			'fonctions'      => [],
			'crochets'       => [],
			'instanciations' => [],
			'statiques'      => [],
			'dynamiques'     => [],
			'interdits'      => [],
		];

		foreach ( $jetons as $i => $jeton ) {
			$suivant = $jetons[ $i + 1 ] ?? null;
			if ( $jeton->is( T_VARIABLE ) && in_array( $jeton->text, [ '$wpdb', '$GLOBALS' ], true ) ) {
				$out['interdits'][] = [ $jeton->text, $jeton->line ];
				continue;
			}
			if ( $jeton->is( [ T_GLOBAL, T_EVAL, T_ECHO, T_PRINT, T_EXIT, T_INLINE_HTML ] ) || $jeton->is( '`' ) ) {
				$out['interdits'][] = [ $jeton->text, $jeton->line ];
				continue;
			}
			if ( $jeton->is( T_NEW ) && null !== $suivant && $suivant->is( T_VARIABLE ) ) {
				$out['interdits'][] = [ 'new ' . $suivant->text, $jeton->line ];
				continue;
			}
			if ( null !== $suivant && $suivant->is( '(' ) && ( $jeton->is( T_VARIABLE ) || $jeton->is( ')' ) ) ) {
				$out['dynamiques'][] = [ $jeton->is( T_VARIABLE ) ? $jeton->text : ')(', $jeton->line ];
				continue;
			}
			if ( ! $jeton->is( [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ] ) || null === $suivant || ! $suivant->is( '(' ) ) {
				continue;
			}
			$nom       = ltrim( $jeton->text, '\\' );
			$precedent = $jetons[ $i - 1 ] ?? null;
			if ( null !== $precedent && $precedent->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION ] ) ) {
				continue; // Méthode d'un objet, ou déclaration.
			}
			if ( null !== $precedent && $precedent->is( T_NEW ) ) {
				$out['instanciations'][] = [ $nom, $jeton->line ];
				continue;
			}
			if ( null !== $precedent && $precedent->is( T_DOUBLE_COLON ) ) {
				$classe              = ltrim( $jetons[ $i - 2 ]->text, '\\' );
				$out['statiques'][] = [ in_array( strtolower( $classe ), [ 'self', 'parent', 'static' ], true ) ? strtolower( $classe ) : $classe . '::' . $nom, $jeton->line ];
				continue;
			}
			$out['fonctions'][] = [ $nom, $jeton->line ];
			if ( in_array( strtolower( $nom ), [ 'add_filter', 'remove_filter', 'add_action', 'remove_action' ], true ) ) {
				$argument          = $jetons[ $i + 2 ] ?? null;
				$out['crochets'][] = [ null !== $argument && $argument->is( T_CONSTANT_ENCAPSED_STRING ) ? substr( $argument->text, 1, -1 ) : '(nom non littéral)', $jeton->line ];
			}
		}

		return $out;
	}
}
