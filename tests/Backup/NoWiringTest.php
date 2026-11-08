<?php
/**
 * Garde-fous du module des sauvegardes chiffrées : rien de branché en phase 2.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

use G2RD\Connector\Commands\CommandExecutor;
use PHPUnit\Framework\TestCase;

/**
 * La phase 2 livre une bibliothèque (includes/Backup/Crypto, includes/Backup/Format) que rien
 * n'appelle : aucune commande, aucun crochet, aucune option, aucune route, aucune tâche planifiée,
 * aucun appel réseau, aucun effet au chargement (plan de la phase 2, rappel transverse n° 2,
 * tâche 22). Le connecteur peut donc être publié avec ce module dormant sans que rien ne change
 * pour un site : ces tests le prouvent sur le code, pas sur une promesse.
 *
 * Les sources sont lues par les jetons de PHP (PhpToken), pas comme du texte : un nom cité dans un
 * commentaire ne compte pas, un appel écrit `\add_action(` ou une fonction passée par son nom
 * (`'add_action'`) comptent.
 *
 * La phase 4 modifie trois de ces tests, et rien d'autre (exceptions écrites dans la vue
 * d'ensemble des phases 2 à 5, § 6, lot 4-A point 5 et lot 4-C) :
 * - lot 4-A : test_aucune_classe_de_sauvegarde_n_est_referencee_hors_du_dossier passe d'une
 *   interdiction totale à une liste fermée de fichiers autorisés à citer le module, écrite dans le
 *   test ; test_aucune_fonction_wordpress_dans_le_module est restreint à includes/Backup/Crypto/
 *   et includes/Backup/Format/ (la bibliothèque reste pure, le module parle à WordPress) ;
 * - lot 4-C : test_les_listes_de_commandes_sont_exactement_celles_ecrites_ici passe à 19 et
 *   8 commandes (valeurs écrites dans le test), et la liste fermée du premier test est complétée.
 * Les deux listes relevées sont recalées sur la 1.16.0-rc.1 (set_php_errors_capture) dans le
 * commit qui fusionne main dans le module (plan de la phase 4, « Piste concurrente C1 ») : d'où
 * 19 et 8 au lieu de 18 et 7.
 * test_aucun_effet_au_chargement reste tel quel.
 */
final class NoWiringTest extends TestCase {

	/**
	 * CommandExecutor::ALLOWED relevée le 24/10/2026 sur 64560ac (1.15.0), dans cet ordre ;
	 * recalée le 07/11/2026 sur 666f084 (1.16.0-rc.1 : set_php_errors_capture) ; relevée le
	 * 08/11/2026 sur `ajout/sauvegardes-module` (phase 4 : les quatre commandes backup_*).
	 */
	private const COMMANDES_ADMISES = [
		'clear_cache',
		'check_updates',
		'update_core',
		'delete_spam_comments',
		'delete_post_revisions',
		'empty_trashed_posts',
		'delete_expired_transients',
		'optimize_database',
		'update_plugin',
		'update_theme',
		'update_translations',
		'rollback_plugin',
		'delete_restore_point',
		'set_signature_policy',
		'set_php_errors_capture',
		'backup_set_key',
		'backup_start',
		'backup_step',
		'backup_abort',
	];

	/**
	 * CommandExecutor::SIGNED_ONLY relevée le 24/10/2026 sur 64560ac (1.15.0), dans cet ordre ;
	 * recalée le 07/11/2026 sur 666f084 (1.16.0-rc.1 : set_php_errors_capture) ; relevée le
	 * 08/11/2026 sur `ajout/sauvegardes-module` (phase 4 : les quatre commandes backup_*).
	 */
	private const COMMANDES_SIGNEES_SEULEMENT = [
		'rollback_plugin',
		'delete_restore_point',
		'set_signature_policy',
		'set_php_errors_capture',
		'backup_set_key',
		'backup_start',
		'backup_step',
		'backup_abort',
	];

	/**
	 * Fichiers autorisés à citer le module (liste fermée, phase 4) : chacun est ajouté dans le commit
	 * même de son branchement (plan de la phase 4 : tâches 10, 11, 13, 30 à 34). Chaque fichier
	 * listé doit vraiment le citer : un fichier qui ne le cite plus sort de la liste.
	 */
	private const FICHIERS_AUTORISES = [
		'includes/Commands/CommandExecutor.php',
		'includes/Rest/CommandRunsController.php',
		'includes/CommandRuns/AfterResponseRunner.php',
		'includes/Plugin.php',
		'includes/Cron/HeartbeatJob.php',
		'includes/Rest/SnapshotController.php',
		'includes/Rest/AdminController.php',
		'includes/BootData.php',
		'includes/Admin/Page.php',
		'includes/Environment/SizeReport.php',
		'uninstall.php',
	];

	/**
	 * Fonctions WordPress interdites dans le module, nom exact (casse ignorée, comme PHP).
	 *
	 * Les cinq premières, avec le préfixe wp_remote_, sont la liste du plan (tâche 22) ; les
	 * autres sont des mêmes familles (crochets, options, transients, tâches planifiées, crochets
	 * d'activation, appels réseau), interdites par le même rappel transverse n° 2.
	 */
	private const FONCTIONS_INTERDITES = [
		'add_action',
		'add_filter',
		'get_option',
		'update_option',
		'register_rest_route',
		'do_action',
		'do_action_ref_array',
		'apply_filters',
		'apply_filters_ref_array',
		'remove_action',
		'remove_filter',
		'add_option',
		'delete_option',
		'get_site_option',
		'update_site_option',
		'add_site_option',
		'delete_site_option',
		'get_network_option',
		'update_network_option',
		'add_network_option',
		'delete_network_option',
		'get_transient',
		'set_transient',
		'delete_transient',
		'get_site_transient',
		'set_site_transient',
		'delete_site_transient',
		'wp_schedule_event',
		'wp_schedule_single_event',
		'wp_unschedule_event',
		'wp_clear_scheduled_hook',
		'register_activation_hook',
		'register_deactivation_hook',
		'register_uninstall_hook',
	];

	/**
	 * Préfixes interdits : les appels réseau de WordPress (wp_remote_get, wp_safe_remote_post…).
	 */
	private const PREFIXES_INTERDITS = [
		'wp_remote_',
		'wp_safe_remote_',
	];

	public function test_aucune_classe_de_sauvegarde_n_est_referencee_hors_du_dossier(): void {
		$fichiers = [ 'g2rd-connector.php', 'uninstall.php' ];
		foreach ( self::fichiers_php( 'includes' ) as $relatif ) {
			if ( ! str_starts_with( $relatif, 'includes/Backup/' ) ) {
				$fichiers[] = $relatif;
			}
		}
		self::assertContains( 'includes/Commands/CommandExecutor.php', $fichiers );

		$citations = [];
		foreach ( $fichiers as $relatif ) {
			if ( in_array( $relatif, self::FICHIERS_AUTORISES, true ) ) {
				continue;
			}
			foreach ( self::citations_du_module( $relatif ) as $citation ) {
				$citations[] = $citation;
			}
		}
		foreach ( self::FICHIERS_AUTORISES as $autorise ) {
			self::assertNotSame( [], self::citations_du_module( $autorise ), $autorise . ' est dans la liste fermée mais ne cite plus le module : le retirer de la liste.' );
		}

		self::assertSame(
			[],
			$citations,
			"Le module des sauvegardes (G2RD\\Connector\\Backup\\…) est cité hors de includes/Backup/ par un fichier qui n'est pas dans la liste fermée.\n"
			. "Cause probable : branchement non prévu par le plan de la phase 4.\n"
			. 'Action : retirer cette référence, ou, si le branchement est voulu par le plan, ajouter le '
			. 'fichier à FICHIERS_AUTORISES dans le même commit.'
		);
	}

	public function test_aucune_fonction_wordpress_dans_le_module(): void {
		$appels = [];
		foreach ( self::fichiers_de_la_bibliotheque() as $relatif ) {
			foreach ( self::appels_interdits( $relatif ) as $appel ) {
				$appels[] = $appel;
			}
		}

		self::assertSame(
			[],
			$appels,
			"Fonction WordPress de branchement appelée dans includes/Backup/ (crochet, option, transient, "
			. "tâche planifiée, route REST ou appel réseau).\n"
			. "Cause probable : du code de la phase 4 ajouté à la bibliothèque de la phase 2.\n"
			. 'Action : garder includes/Backup/ sans fonction WordPress (seules les constantes ABSPATH et '
			. 'WPINC servent, pour charger sodium_compat) ; le branchement vient en phase 4, hors de '
			. 'Backup/Crypto et de Backup/Format.'
		);
	}

	public function test_aucun_effet_au_chargement(): void {
		$problemes = [];
		foreach ( self::fichiers_du_module() as $relatif ) {
			foreach ( self::code_hors_de_la_classe( $relatif ) as $probleme ) {
				$problemes[] = $probleme;
			}
		}

		self::assertSame(
			[],
			$problemes,
			"Un fichier de includes/Backup/ contient, hors de sa classe, autre chose que declare, namespace et use.\n"
			. "Cause probable : du code exécuté au chargement du fichier (appel, définition de constante ou de "
			. "fonction, sortie), ou une seconde classe dans le même fichier.\n"
			. "Action : déplacer ce code dans une méthode de la classe, ou la seconde classe dans son propre "
			. 'fichier ; charger un fichier du module ne doit rien faire.'
		);
	}

	public function test_les_listes_de_commandes_sont_exactement_celles_ecrites_ici(): void {
		self::assertCount( 19, self::COMMANDES_ADMISES );
		self::assertCount( 8, self::COMMANDES_SIGNEES_SEULEMENT );

		self::assertSame(
			self::COMMANDES_ADMISES,
			CommandExecutor::ALLOWED,
			"CommandExecutor::ALLOWED n'est plus la liste relevée sur ajout/sauvegardes-module (19 commandes).\n"
			. "Cause probable : une commande ajoutée, retirée ou déplacée (les quatre commandes backup_* de la "
			. "phase 4 y sont déjà, à la fin).\n"
			. 'Action : si le changement est voulu par un plan, mettre la liste écrite dans ce test à jour '
			. 'dans le même commit ; sinon, rétablir CommandExecutor::ALLOWED.'
		);
		self::assertSame(
			self::COMMANDES_SIGNEES_SEULEMENT,
			CommandExecutor::SIGNED_ONLY,
			"CommandExecutor::SIGNED_ONLY n'est plus la liste relevée sur ajout/sauvegardes-module (8 commandes).\n"
			. "Cause probable : une commande signée ajoutée, retirée ou déplacée (les quatre commandes "
			. "backup_* de la phase 4 y sont déjà, à la fin).\n"
			. 'Action : si le changement est voulu par un plan, mettre la liste écrite dans ce test à jour '
			. 'dans le même commit ; sinon, rétablir CommandExecutor::SIGNED_ONLY.'
		);
	}

	/**
	 * Racine du dépôt (copie de travail comprise).
	 */
	private static function racine(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Fichiers PHP d'un dossier, chemins relatifs à la racine avec des « / », triés.
	 *
	 * @param string $dossier Dossier relatif à la racine.
	 * @return list<string>
	 */
	private static function fichiers_php( string $dossier ): array {
		$racine    = self::racine();
		$fichiers  = [];
		$iterateur = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $racine . '/' . $dossier, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterateur as $fichier ) {
			if ( $fichier instanceof \SplFileInfo && 'php' === $fichier->getExtension() ) {
				$fichiers[] = str_replace( '\\', '/', substr( $fichier->getPathname(), strlen( $racine ) + 1 ) );
			}
		}
		sort( $fichiers );
		return $fichiers;
	}

	/**
	 * Fichiers du module ; échoue si le module a quitté son dossier (le test ne prouverait rien).
	 *
	 * @return list<string>
	 */
	private static function fichiers_du_module(): array {
		self::assertDirectoryExists( self::racine() . '/includes/Backup/Crypto' );
		self::assertDirectoryExists( self::racine() . '/includes/Backup/Format' );
		$fichiers = self::fichiers_php( 'includes/Backup' );
		self::assertContains( 'includes/Backup/Crypto/Sodium.php', $fichiers );
		self::assertContains( 'includes/Backup/Format/SegmentWriter.php', $fichiers );
		return $fichiers;
	}

	/**
	 * Fichiers de la bibliothèque pure de la phase 2 : includes/Backup/Crypto/ et
	 * includes/Backup/Format/. Le reste du module parle à WordPress (envoi d'une partie depuis la
	 * phase 3, module de sauvegarde depuis la phase 4) : exception bornée du lot 4-A, annoncée par
	 * le docblock de ce fichier.
	 *
	 * @return list<string>
	 */
	private static function fichiers_de_la_bibliotheque(): array {
		$bibliotheque = array_values(
			array_filter(
				self::fichiers_du_module(),
				static fn ( string $relatif ): bool => str_starts_with( $relatif, 'includes/Backup/Crypto/' )
					|| str_starts_with( $relatif, 'includes/Backup/Format/' )
			)
		);
		self::assertNotEmpty( $bibliotheque, 'Aucun fichier sous includes/Backup/Crypto/ ni includes/Backup/Format/ : chemin des fichiers du module changé ?' );

		return $bibliotheque;
	}

	/**
	 * Jetons du code d'un fichier, sans espaces, commentaires ni balise d'ouverture.
	 *
	 * @param string $relatif Fichier relatif à la racine.
	 * @return list<\PhpToken>
	 */
	private static function jetons_du_code( string $relatif ): array {
		$source = file_get_contents( self::racine() . '/' . $relatif );
		self::assertIsString( $source, 'Lecture impossible de ' . $relatif . '.' );

		$jetons = [];
		foreach ( \PhpToken::tokenize( $source ) as $jeton ) {
			if ( ! $jeton->isIgnorable() ) {
				$jetons[] = $jeton;
			}
		}
		return $jetons;
	}

	/**
	 * Citations du module dans le code d'un fichier : noms qualifiés (résolus contre l'espace de
	 * noms du fichier, groupes de `use` compris) et chaînes (nom de classe passé en texte).
	 *
	 * @param string $relatif Fichier relatif à la racine.
	 * @return list<string> « fichier:ligne texte ».
	 */
	private static function citations_du_module( string $relatif ): array {
		$jetons        = self::jetons_du_code( $relatif );
		$espace        = '';
		$prefixe       = '';
		$dans_groupe   = false;
		$citations     = [];
		$noms_qualifes = [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ];

		foreach ( $jetons as $i => $jeton ) {
			if ( $jeton->is( T_NAMESPACE ) && isset( $jetons[ $i + 1 ] ) && $jetons[ $i + 1 ]->is( [ T_STRING, T_NAME_QUALIFIED ] ) ) {
				$espace = $jetons[ $i + 1 ]->text;
			}

			// Groupe de use : « use G2RD\Connector\{ Backup\Format\X, … }; ».
			if ( $jeton->is( '{' ) && $i >= 2 && $jetons[ $i - 1 ]->is( T_NS_SEPARATOR ) && $jetons[ $i - 2 ]->is( array_merge( [ T_STRING ], $noms_qualifes ) ) ) {
				$prefixe     = ltrim( $jetons[ $i - 2 ]->text, '\\' );
				$dans_groupe = true;
				continue;
			}
			if ( $dans_groupe && $jeton->is( '}' ) ) {
				$dans_groupe = false;
				continue;
			}

			$candidats = [];
			if ( $jeton->is( [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ] ) ) {
				$candidats[] = str_replace( '\\\\', '\\', $jeton->text );
			} elseif ( $jeton->is( array_merge( [ T_STRING ], $noms_qualifes ) ) ) {
				$nom         = $jeton->text;
				$candidats[] = $nom;
				if ( $dans_groupe ) {
					$candidats[] = $prefixe . '\\' . $nom;
				} elseif ( $jeton->is( T_NAME_RELATIVE ) ) {
					$candidats[] = $espace . '\\' . substr( $nom, strlen( 'namespace\\' ) );
				} elseif ( $jeton->is( T_NAME_QUALIFIED ) ) {
					$candidats[] = $espace . '\\' . $nom;
				}
			}

			foreach ( $candidats as $candidat ) {
				if ( 1 === preg_match( '/(?<![a-z0-9_])connector\\\\backup(?![a-z0-9_])/i', $candidat ) ) {
					$citations[] = $relatif . ':' . $jeton->line . ' ' . $jeton->text;
					break;
				}
			}
		}
		return $citations;
	}

	/**
	 * Appels de fonctions interdites dans le code d'un fichier : appel direct (`get_option(`,
	 * `\get_option(`) ou fonction passée par son nom (`'get_option'`).
	 *
	 * @param string $relatif Fichier relatif à la racine.
	 * @return list<string> « fichier:ligne fonction ».
	 */
	private static function appels_interdits( string $relatif ): array {
		$jetons = self::jetons_du_code( $relatif );
		$appels = [];

		foreach ( $jetons as $i => $jeton ) {
			$nom = null;
			if ( $jeton->is( [ T_STRING, T_NAME_FULLY_QUALIFIED ] ) && isset( $jetons[ $i + 1 ] ) && $jetons[ $i + 1 ]->is( '(' ) ) {
				// Méthode, déclaration ou instanciation : pas un appel de fonction globale.
				if ( $i > 0 && $jetons[ $i - 1 ]->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ] ) ) {
					continue;
				}
				$nom = ltrim( $jeton->text, '\\' );
			} elseif ( $jeton->is( T_CONSTANT_ENCAPSED_STRING ) ) {
				$nom = substr( $jeton->text, 1, -1 );
			}

			if ( null !== $nom && self::est_interdite( $nom ) ) {
				$appels[] = $relatif . ':' . $jeton->line . ' ' . $nom;
			}
		}
		return $appels;
	}

	/**
	 * Vrai si le nom est celui d'une fonction interdite (casse ignorée, comme PHP).
	 *
	 * @param string $nom Nom de fonction, sans « \ » initial.
	 */
	private static function est_interdite( string $nom ): bool {
		$nom = strtolower( $nom );
		if ( in_array( $nom, self::FONCTIONS_INTERDITES, true ) ) {
			return true;
		}
		foreach ( self::PREFIXES_INTERDITS as $prefixe ) {
			if ( str_starts_with( $nom, $prefixe ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ce qu'un fichier du module contient hors de sa classe, en dehors de declare, namespace et
	 * use ; exige aussi exactement une classe (ou interface, trait, enum) par fichier.
	 *
	 * @param string $relatif Fichier relatif à la racine.
	 * @return list<string> « fichier:ligne texte ».
	 */
	private static function code_hors_de_la_classe( string $relatif ): array {
		$jetons    = self::jetons_du_code( $relatif );
		$problemes = [];
		$classes   = 0;
		$total     = count( $jetons );

		for ( $i = 0; $i < $total; ) {
			$jeton = $jetons[ $i ];

			if ( $jeton->is( [ T_DECLARE, T_NAMESPACE, T_USE ] ) ) {
				$fin = self::fin_d_instruction( $jetons, $i );
				if ( null === $fin ) {
					$problemes[] = $relatif . ':' . $jeton->line . ' ' . $jeton->text . ' en bloc (forme « { … } » non admise)';
					break;
				}
				$i = $fin + 1;
				continue;
			}

			if ( $jeton->is( [ T_ATTRIBUTE, T_FINAL, T_ABSTRACT, T_READONLY, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ] ) ) {
				$fin = self::fin_de_classe( $jetons, $i );
				if ( null === $fin ) {
					$problemes[] = $relatif . ':' . $jeton->line . ' ' . $jeton->text . ' (déclaration de classe illisible)';
					break;
				}
				++$classes;
				if ( $classes > 1 ) {
					$problemes[] = $relatif . ':' . $jeton->line . ' seconde classe dans le fichier';
				}
				$i = $fin + 1;
				continue;
			}

			$problemes[] = $relatif . ':' . $jeton->line . ' ' . $jeton->text;
			$fin         = self::fin_d_instruction( $jetons, $i, true );
			$i           = null === $fin ? $total : $fin + 1;
		}

		if ( 0 === $classes ) {
			$problemes[] = $relatif . ' : aucune classe';
		}
		return $problemes;
	}

	/**
	 * Indice du « ; » qui termine l'instruction commencée en $debut (parenthèses, crochets et
	 * accolades équilibrés) ; null pour un declare ou un namespace en bloc, ou sans « ; ».
	 *
	 * @param list<\PhpToken> $jetons       Jetons du code.
	 * @param int             $debut        Indice du premier jeton de l'instruction.
	 * @param bool            $bloc_termine Vrai pour une instruction déjà refusée : son bloc
	 *                                      (fonction, if…) la termine aussi, pour que la
	 *                                      classe qui suit soit encore lue.
	 */
	private static function fin_d_instruction( array $jetons, int $debut, bool $bloc_termine = false ): ?int {
		$en_bloc    = $jetons[ $debut ]->is( [ T_DECLARE, T_NAMESPACE ] );
		$profondeur = 0;
		$total      = count( $jetons );

		for ( $i = $debut; $i < $total; ++$i ) {
			$jeton = $jetons[ $i ];
			if ( $jeton->is( [ '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE ] ) ) {
				if ( $en_bloc && 0 === $profondeur && $jeton->is( '{' ) ) {
					return null;
				}
				++$profondeur;
			} elseif ( $jeton->is( [ ')', ']', '}' ] ) ) {
				--$profondeur;
				if ( $bloc_termine && 0 === $profondeur && $jeton->is( '}' ) ) {
					return $i;
				}
			} elseif ( 0 === $profondeur && $jeton->is( ';' ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Indice de l'accolade qui ferme la classe déclarée à partir de $debut (attributs et
	 * modificateurs compris) ; null si la déclaration n'est pas une classe complète.
	 *
	 * @param list<\PhpToken> $jetons Jetons du code.
	 * @param int             $debut  Indice du premier jeton de la déclaration.
	 */
	private static function fin_de_classe( array $jetons, int $debut ): ?int {
		$total = count( $jetons );
		$i     = $debut;

		// Attributs (#[…]) puis modificateurs, avant le mot-clé.
		while ( $i < $total && $jetons[ $i ]->is( T_ATTRIBUTE ) ) {
			$profondeur = 0;
			for ( ; $i < $total; ++$i ) {
				if ( $jetons[ $i ]->is( [ '[', T_ATTRIBUTE ] ) ) {
					++$profondeur;
				} elseif ( $jetons[ $i ]->is( ']' ) && 0 === --$profondeur ) {
					++$i;
					break;
				}
			}
		}
		while ( $i < $total && $jetons[ $i ]->is( [ T_FINAL, T_ABSTRACT, T_READONLY ] ) ) {
			++$i;
		}
		if ( $i >= $total || ! $jetons[ $i ]->is( [ T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ] ) ) {
			return null;
		}

		$profondeur = 0;
		for ( ; $i < $total; ++$i ) {
			if ( $jetons[ $i ]->is( [ '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ] ) ) {
				++$profondeur;
			} elseif ( $jetons[ $i ]->is( '}' ) ) {
				--$profondeur;
				if ( 0 === $profondeur ) {
					return $i;
				}
			} elseif ( 0 === $profondeur && $jetons[ $i ]->is( ';' ) ) {
				return null;
			}
		}
		return null;
	}
}
