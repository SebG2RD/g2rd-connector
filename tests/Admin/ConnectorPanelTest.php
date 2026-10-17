<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Admin;

use G2RD\Connector\Admin\Page;
use G2RD\Connector\Security\QueueSignature;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

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
 * La base de test (Brain Monkey) fournit les fonctions de traduction dont a
 * besoin l'explication PHP du jeton (Page::token_problem), comparée au panneau.
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
			'Enrôlé',
			'Pas de signe de vie depuis',
			'Aucun signe de vie enregistré',
			'signe de vie horaire désactivé',
			'dossier inscriptible',
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
		foreach ( [ 'Heartbeat horaire', 'Webhook events', 'URL du manager', 'Token d', 'Commandes distantes' ] as $ancien ) {
			self::assertStringNotContainsString( $ancien, $source, "Ancien libellé « {$ancien} » encore présent dans le panneau." );
		}
	}

	/**
	 * Statut vrai : « Connecté » seulement si un échange avec le manager a réussi
	 * depuis moins de deux heures (signe de vie accepté, relevé du site par le
	 * manager, ou enrôlement), jamais sur la seule présence du jeton. Signe de vie
	 * désactivé sans échange récent : « Enrôlé », neutre. Signe de vie actif sans
	 * échange récent : alerte, avec causes probables et actions. Les cas eux-mêmes
	 * (seuil, dates absentes ou illisibles) sont vérifiés en exécutant connection.ts
	 * (banc du compte rendu de la tâche 3) : le dépôt n'a pas d'exécuteur JavaScript.
	 */
	public function test_la_pastille_d_etat_suit_le_dernier_echange_reussi(): void {
		$connexion = self::source( 'assets/admin/src/connection.ts' );
		$panneau   = self::panneau();

		self::assertMatchesRegularExpression( '/export const SILENCE_MS = 2 \* 60 \* 60 \* 1000;/', $connexion, 'Seuil du silence attendu : deux heures.' );
		self::assertMatchesRegularExpression( '/timeOf\(\s*facts\.lastHeartbeatAt\s*\),\s*timeOf\(\s*facts\.enrolledAt\s*\)/', $connexion, 'Le dernier échange se lit dans lastHeartbeatAt et enrolledAt.' );
		self::assertMatchesRegularExpression(
			"/if \\( null !== last && now - last < SILENCE_MS \\) \\{\\s*return \\{ kind: 'connected' \\};/",
			$connexion,
			'« connected » doit exiger un échange réussi depuis moins de SILENCE_MS.'
		);
		self::assertSame( 1, substr_count( $connexion, "return { kind: 'connected' };" ), '« connected » ne doit être rendu qu’à un seul endroit, après la preuve d’échange.' );
		self::assertMatchesRegularExpression( "/if \\( ! facts\\.heartbeatEnabled \\) \\{\\s*return \\{ kind: 'heartbeat-off' \\};/", $connexion );

		self::assertMatchesRegularExpression( "/'connected' === status\\.kind && \\(\\s*<Status cx=\\{ cx \\} tone=\"ok\">\\s*Connecté\\s*</", $panneau, '« Connecté » (ok) seulement pour l’état « connected ».' );
		self::assertMatchesRegularExpression( "/'heartbeat-off' === status\\.kind && \\(\\s*<Status cx=\\{ cx \\} tone=\"neutral\">\\s*Enrôlé\\s*</", $panneau );
		self::assertMatchesRegularExpression( '/null !== silence && \(\s*<Status cx=\{ cx \} tone="warn">/', $panneau );
		self::assertStringNotContainsString( 'state.enrolled && null === tokenProblem', $panneau, 'La pastille ne doit plus se déduire de la seule présence du jeton.' );
		foreach ( [ 'enrolled', 'heartbeatEnabled', 'lastHeartbeatAt', 'enrolledAt' ] as $champ ) {
			self::assertStringContainsString( "{$champ}: saved.{$champ}", $panneau, "L'état doit suivre « {$champ} » tel qu'enregistré, pas un interrupteur non enregistré." );
		}

		preg_match( "/export const SILENCE_HELP =\\s*'([^']+)'/", $connexion, $aide );
		self::assertArrayHasKey( 1, $aide, 'SILENCE_HELP introuvable dans connection.ts.' );
		foreach ( [ 'Causes probables', 'WP-Cron', 'Actions', '« IPv4 vers le manager »', '« Enregistrer »', 'support G2RD' ] as $attendu ) {
			self::assertStringContainsString( $attendu, $aide[1], "L'explication du silence ne mentionne pas « {$attendu} »." );
		}
		self::assertStringContainsString( '${ SILENCE_HELP }', $panneau, 'L’explication du silence n’est pas affichée par le panneau.' );
	}

	/**
	 * Échec d'enrôlement : une cause et une action par famille d'erreur. Problème
	 * de réseau ou blocage en chemin (CDN, pare-feu) : « IPv4 vers le manager »
	 * puis « Enregistrer », et jamais de nouvelle invitation, inutile tant que la
	 * requête n'atteint pas le manager. Invitation refusée par le manager : nouvelle
	 * invitation.
	 */
	public function test_chaque_echec_d_enrolement_a_sa_cause_et_son_action(): void {
		$connexion = self::source( 'assets/admin/src/connection.ts' );

		preg_match( '/export type EnrollFailure =((?:\s*\|\s*\'[a-z-]+\')+);/', $connexion, $union );
		self::assertArrayHasKey( 1, $union, 'Type EnrollFailure introuvable dans connection.ts.' );
		preg_match_all( "/'([a-z-]+)'/", $union[1], $familles );
		$familles = $familles[1];
		sort( $familles );

		preg_match( '/export function enrollFailureKind\b(.*?)\n\}\n/s', $connexion, $fonction );
		self::assertArrayHasKey( 1, $fonction, 'Fonction enrollFailureKind introuvable.' );
		preg_match_all( "/'([a-z-]+)'/", $fonction[1], $rendues );
		$rendues = array_values( array_unique( array_intersect( $rendues[1], $familles ) ) );
		sort( $rendues );
		self::assertSame( $familles, $rendues, 'Chaque famille d’échec doit pouvoir être rendue par enrollFailureKind.' );

		$messages = self::table( $connexion, 'ENROLL_FAILURES' );
		$cles     = array_keys( $messages );
		sort( $cles );
		self::assertSame( $familles, $cles, 'ENROLL_FAILURES doit avoir un message par famille, et rien d’autre.' );

		foreach ( [ 'unreachable', 'blocked' ] as $famille ) {
			self::assertStringContainsString( '« IPv4 vers le manager »', $messages[ $famille ], "Échec « {$famille} » : l'interrupteur IPv4 n'est pas proposé." );
			self::assertStringContainsString( '« Enregistrer »', $messages[ $famille ], "Échec « {$famille} » : le garde IPv4 n'agit qu'après l'enregistrement." );
			self::assertStringNotContainsString( 'Générez une nouvelle invitation', $messages[ $famille ], "Échec « {$famille} » : une nouvelle invitation ne servirait à rien." );
		}
		self::assertStringContainsString( 'Ne générez pas de nouvelle invitation', $messages['blocked'] );
		self::assertStringContainsString( 'nouvelle invitation', $messages['refused'] );
		self::assertStringContainsString( '15 minutes', $messages['refused'] );

		// « refused » (jeton expiré, nouvelle invitation) est réservé au 403 auquel
		// le manager a répondu lui-même. Ses autres refus (400 « Payload JSON
		// invalide », 422 pour une adresse en http://…) n'ont pas cette cause : le
		// panneau renvoie au message du manager, sans en supposer une. Un refus non
		// classé sans réponse du manager est une réponse incorrecte (« unreachable »).
		self::assertSame( 1, substr_count( $fonction[1], "'refused'" ), '« refused » ne doit être rendu qu’à un seul endroit.' );
		self::assertMatchesRegularExpression(
			"/if \\( 403 === status \\) \\{\\s*return true === rejected\\.data\\?\\.manager_answered \\? 'refused' : 'blocked';/",
			$fonction[1],
			'« refused » seulement pour un 403 auquel le manager a répondu.'
		);
		self::assertMatchesRegularExpression(
			"/if \\( status >= 400 \\) \\{\\s*return true === rejected\\.data\\?\\.manager_answered\\s*\\? 'manager-message'\\s*: 'unreachable';\\s*\\}\\s*return 'unknown';\\s*$/",
			$fonction[1],
			'Un refus non classé (400, 422…) renvoie au message du manager s’il a répondu, sinon à une réponse incorrecte.'
		);
		self::assertStringContainsString( 'Suivez l’indication du manager ci-dessus', $messages['manager-message'] );
		self::assertStringContainsString( 'entre parenthèses', $messages['manager-message'] );
		foreach ( [ 'Cause probable', 'expiré', 'déjà utilisé', 'Générez une nouvelle invitation' ] as $cause_supposee ) {
			self::assertStringNotContainsString( $cause_supposee, $messages['manager-message'], "Refus non classé : « {$cause_supposee} » suppose une cause que le manager n'a pas donnée." );
		}

		// Le panneau affiche ce message, et reprend les réglages joints à l'erreur.
		$panneau = self::panneau();
		self::assertStringContainsString( 'ENROLL_FAILURES[ enrollFailureKind( error ) ]', $panneau );
		self::assertMatchesRegularExpression( '/const kept = savedDataOf\( e \);\s*if \( null !== kept \) \{\s*accept\( kept \);/', $panneau );
	}

	/**
	 * Comparaison avec le serveur : les codes et données d'erreur que lit
	 * enrollFailureKind sont bien ceux que produisent ManagerClient::enroll et
	 * AdminController::handle_enroll, et les noms d'interrupteur et de bouton cités
	 * par les messages existent dans le panneau.
	 */
	public function test_les_messages_d_enrolement_correspondent_au_serveur_et_au_panneau(): void {
		$connexion = self::source( 'assets/admin/src/connection.ts' );
		$client    = self::source( 'includes/Outbound/ManagerClient.php' );
		$controle  = self::source( 'includes/Rest/AdminController.php' );

		self::assertStringContainsString( "'g2rd_connector_enroll_invalid_response'", $client );
		self::assertStringContainsString( "'g2rd_connector_enroll_invalid_response'", $connexion );
		foreach ( [ $client, $controle, $connexion ] as $source ) {
			self::assertStringContainsString( 'manager_answered', $source );
		}
		self::assertMatchesRegularExpression( "/'boot'\s*=>\s*BootData::build\(\)/", $controle, 'L’échec d’enrôlement doit porter les réglages enregistrés.' );

		$panneau = self::panneau();
		preg_match_all( '/«\s*([^»]+?)\s*»/u', self::sans_commentaires( $connexion ), $cites );
		self::assertNotEmpty( $cites[1] );
		foreach ( array_unique( $cites[1] ) as $nom ) {
			self::assertTrue(
				str_contains( $panneau, 'title="' . $nom . '"' ) || 1 === preg_match( '/>\s*' . preg_quote( $nom, '/' ) . '\s*</u', $panneau ),
				"« {$nom} », cité par connection.ts, n'est ni un réglage ni un bouton du panneau."
			);
		}
	}

	/**
	 * Chaque explication d'échec de signature se termine par une action (quoi,
	 * cause probable, action).
	 */
	public function test_chaque_echec_de_signature_propose_une_action(): void {
		foreach ( self::table( self::panneau(), 'SIGNATURE_FAILURES' ) as $code => $phrase ) {
			self::assertMatchesRegularExpression( '/contactez le support G2RD|Faites vérifier|Déconnectez/u', $phrase, "Le code « {$code} » n'indique aucune action." );
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

	/**
	 * Jeton stocké mais inutilisable : le panneau donne exactement l'explication de
	 * l'avis PHP (quoi, cause probable, action), apostrophes typographiques mises à
	 * part ; aucun texte pour un jeton utilisable ou absent.
	 */
	public function test_l_explication_du_jeton_est_celle_de_l_avis_php(): void {
		preg_match( '/const TOKEN_PROBLEMS\b[^=]*=\s*\{(.*?)\n\};/s', self::panneau(), $bloc );
		self::assertArrayHasKey( 1, $bloc, 'Table TOKEN_PROBLEMS introuvable dans le panneau.' );
		preg_match_all( "/^\s*(\w+):\s*'([^']*)'/m", $bloc[1], $entrees, PREG_SET_ORDER );
		$panneau = [];
		foreach ( $entrees as [ , $etat, $texte ] ) {
			$panneau[ $etat ] = str_replace( '’', "'", $texte );
		}

		$avis = new ReflectionMethod( Page::class, 'token_problem' );
		foreach ( [ 'none', 'ok', 'legacy', 'unreadable', 'refused' ] as $etat ) {
			$texte_php = $avis->invoke( null, $etat );
			if ( null === $texte_php ) {
				self::assertArrayNotHasKey( $etat, $panneau, "Le panneau signale un problème de jeton pour l'état « {$etat} », que l'avis PHP ne signale pas." );
				continue;
			}
			self::assertArrayHasKey( $etat, $panneau, "Explication du jeton « {$etat} » absente du panneau." );
			self::assertSame( $texte_php, $panneau[ $etat ], "L'explication du jeton « {$etat} » diffère de celle de l'avis PHP (Page::token_problem)." );
		}
	}

	/**
	 * Mode autonome : l'interrupteur dessiné fait 26 px de haut, mais sa case à
	 * cocher (transparente, posée sur lui par WordPress) offre une cible d'au moins
	 * 44 px de haut, centrée sur le dessin. Le sélecteur passe devant celui de
	 * WordPress (« .components-form-toggle input.components-form-toggle__input[type=checkbox] »).
	 */
	public function test_l_interrupteur_du_mode_autonome_offre_une_cible_de_44_px(): void {
		$css = self::sans_commentaires( self::source( 'assets/admin/src/style.css' ) );

		$hauteur_dessin = self::declaration( $css, '/\.g2rd-connector-panel--autonome \.g2rd-connector-toggle\.components-form-toggle$/', 'height' );
		$selecteur_case = '/\.g2rd-connector-panel--autonome \.g2rd-connector-toggle input\.components-form-toggle__input\[type="checkbox"\]$/';
		$hauteur_case   = self::declaration( $css, $selecteur_case, 'height' );
		$haut_case      = self::declaration( $css, $selecteur_case, 'top' );

		self::assertNotNull( $hauteur_dessin, 'Hauteur de l’interrupteur du mode autonome introuvable dans style.css.' );
		self::assertNotNull( $hauteur_case, 'Aucune règle ne donne sa hauteur à la case de l’interrupteur du mode autonome : la cible reste à la taille du dessin.' );
		self::assertGreaterThanOrEqual( 44, $hauteur_case, 'La cible de l’interrupteur du mode autonome fait moins de 44 px de haut.' );
		self::assertSame( ( $hauteur_dessin - $hauteur_case ) / 2, $haut_case, 'La cible de l’interrupteur n’est pas centrée sur son dessin.' );
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

	/**
	 * Valeur en pixels d'une propriété, dans la règle dont le sélecteur (unique,
	 * sans virgule) correspond au motif ; null si absente.
	 */
	private static function declaration( string $css, string $motif_selecteur, string $propriete ): ?float {
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $regles, PREG_SET_ORDER );
		foreach ( $regles as [ , $selecteur, $declarations ] ) {
			if ( 1 !== preg_match( $motif_selecteur, trim( $selecteur ) ) ) {
				continue;
			}
			if ( 1 === preg_match( '/(?<![\w-])' . preg_quote( $propriete, '/' ) . '\s*:\s*(-?[\d.]+)px\s*;/', $declarations, $valeur ) ) {
				return (float) $valeur[1];
			}
		}
		return null;
	}

	/**
	 * Entrées d'une table de textes du panneau (`const NOM: Record< …, string > =
	 * { clé: 'texte', … }`), clé => texte.
	 *
	 * @return array<string, string>
	 */
	private static function table( string $source, string $nom ): array {
		preg_match( '/const ' . $nom . '\b[^=]*=\s*\{(.*?)\n\};/s', $source, $bloc );
		self::assertArrayHasKey( 1, $bloc, "Table {$nom} introuvable." );
		preg_match_all( "/^\\s*'?([\\w-]+)'?:\\s*\\n?\\s*'([^']*)'/m", $bloc[1], $entrees, PREG_SET_ORDER );
		$table = [];
		foreach ( $entrees as [ , $cle, $texte ] ) {
			$table[ $cle ] = $texte;
		}
		self::assertNotEmpty( $table, "Table {$nom} vide." );
		return $table;
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
