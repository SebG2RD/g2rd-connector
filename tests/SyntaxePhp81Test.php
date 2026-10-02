<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Garde-fou du plancher PHP 8.1 (« Requires PHP: 8.1 », composer.json, matrice CI).
 *
 * De la 0.1.0 à la 1.12, ManagerClient déclarait `true|WP_Error` : le type `true`
 * n'existe qu'en PHP 8.2. Sous PHP 8.1, le simple chargement de la classe est une
 * erreur fatale de compilation, et le battement de cœur comme chaque événement
 * plantaient. Ni PHPStan (même réglé sur 8.1) ni PHPCompatibilityWP 9.x ne le voient.
 *
 * Deux contrôles, complémentaires :
 *  - une lecture des jetons, qui marche sous TOUTE version de PHP (donc aussi en
 *    local, en 8.4) et repère les syntaxes 8.2+ qu'on a déjà croisées ou qui sont
 *    les plus probables ;
 *  - un `php -l` de chaque fichier avec l'interpréteur qui exécute les tests : sur
 *    le job « PHP 8.1 » de la CI, il attrape tout le reste (hooks de propriété,
 *    visibilité asymétrique…), même dans les fichiers qu'aucun test ne charge.
 */
final class SyntaxePhp81Test extends PHPUnitTestCase {

	/**
	 * Fichiers PHP livrés dans le plugin (plus les tests, joués eux aussi en 8.1).
	 *
	 * @return list<string>
	 */
	private static function fichiers(): array {
		$racine   = dirname( __DIR__ );
		$fichiers = [ $racine . '/g2rd-connector.php', $racine . '/uninstall.php' ];

		foreach ( [ 'includes', 'tests' ] as $dossier ) {
			$iterateur = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $racine . '/' . $dossier, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterateur as $fichier ) {
				if ( $fichier instanceof \SplFileInfo && 'php' === $fichier->getExtension() ) {
					$fichiers[] = $fichier->getPathname();
				}
			}
		}

		sort( $fichiers );
		return $fichiers;
	}

	public function test_aucune_syntaxe_posterieure_a_php_8_1_dans_le_code(): void {
		$problemes = [];
		foreach ( self::fichiers() as $fichier ) {
			foreach ( self::syntaxes_php82_plus( (string) file_get_contents( $fichier ) ) as $probleme ) {
				$problemes[] = self::relatif( $fichier ) . ':' . $probleme;
			}
		}

		self::assertSame(
			[],
			$problemes,
			"Syntaxe absente de PHP 8.1, plancher du plugin : un site en 8.1 planterait au chargement du fichier.\n"
			. "Remplacer `true` par `bool` (et garder `@return true|…` en docblock pour PHPStan), retirer le type des constantes, etc."
		);
	}

	public function test_chaque_fichier_passe_le_controle_de_syntaxe_de_l_interpreteur_courant(): void {
		if ( ! function_exists( 'exec' ) ) {
			self::markTestSkipped( 'exec() est désactivée : impossible de lancer « php -l ».' );
		}

		$echecs = [];
		foreach ( self::fichiers() as $fichier ) {
			$sortie = [];
			$code   = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $fichier ) . ' 2>&1', $sortie, $code );
			if ( 0 !== $code ) {
				$echecs[] = self::relatif( $fichier ) . ' : ' . trim( implode( ' ', $sortie ), " \n\r\t\v\0" );
			}
		}

		self::assertSame( [], $echecs, sprintf( 'Erreur de syntaxe sous PHP %s.', PHP_VERSION ) );
	}

	/**
	 * Le détecteur lui-même : il doit voir ce qui a cassé la 8.1, et rien de plus.
	 */
	public function test_le_detecteur_reconnait_les_syntaxes_php_8_2_et_au_dela(): void {
		$cas = [
			'retour true en union'        => 'function f(): true|WP_Error {}',
			'retour true seul'            => 'function f(): true {}',
			'retour false seul'           => 'function f(): false {}',
			'retour null seul'            => 'function f(): null {}',
			'paramètre true'              => 'function f( true|int $a ) {}',
			'paramètre true variadique'   => 'function f( int $a, true ...$b ) {}',
			'paramètre DNF en 2e position' => 'function f( int $a, (A&B)|null $b ) {}',
			'propriété true'              => 'class A { private true|int $a; }',
			'type DNF'                    => 'function f( (A&B)|null $a ) {}',
			'classe en lecture seule'     => 'readonly class A {}',
			'classe finale lecture seule' => 'final readonly class A {}',
			'constante typée'             => 'class A { const string B = "b"; }',
			'constante typée publique'    => 'class A { public const int B = 1; }',
		];
		foreach ( $cas as $nom => $code ) {
			self::assertNotSame( [], self::syntaxes_php82_plus( '<?php ' . $code ), $nom );
		}

		$autorises = [
			'union avec false'      => 'function f(): false|string {}',
			'nullable'              => 'function f( ?int $a ): ?string {}',
			'union avec null'       => 'function f(): null|string {}',
			'bool'                  => 'function f(): bool|WP_Error {}',
			'propriété readonly'    => 'class A { public function __construct( private readonly int $a ) {} }',
			'constante simple'      => 'class A { const B = 1, C = 2; public const D = true; }',
			'use const'             => 'use const Foo\\BAR;',
			'valeur par défaut'     => 'function f( bool $a = true, $b = null ) { return $a ? true : false; }',
			'fermeture'             => '$f = fn ( int $a ): int => $a; $g = function () use ( $f ): ?int { return null; };',
			'type intersection 8.1' => 'function f( A&B $a ) {}',
			'référence, variadique' => 'function f( array &$a, int ...$b ) {}',
			'if alternatif, ternaire' => 'if ( $a ): $b = $c ? f( $d ) : g(); endif;',
			'appel en expression'   => '$x = [ f( $a ), true, null ]; foreach ( $x as $k => $v ) {} if ( $a ) $b = 1;',
			'catch multiple'        => 'try {} catch ( A|B $e ) {}',
			'parenthèses d’expression' => '$p = f( 1, ( $this->clock )() ); if ( ( $a || $b ) && ! c( $d ) ) {}',
		];
		foreach ( $autorises as $nom => $code ) {
			self::assertSame( [], self::syntaxes_php82_plus( '<?php ' . $code ), $nom );
		}
	}

	/**
	 * @return list<string> « ligne : explication » pour chaque syntaxe 8.2+ trouvée.
	 */
	private static function syntaxes_php82_plus( string $source ): array {
		$jetons = array_values(
			array_filter(
				\PhpToken::tokenize( $source ),
				static fn ( \PhpToken $j ): bool => ! $j->isIgnorable()
			)
		);
		$n         = count( $jetons );
		$problemes = [];

		for ( $i = 0; $i < $n; $i++ ) {
			$jeton = $jetons[ $i ];

			// readonly class / final readonly class / abstract readonly class (8.2).
			if ( $jeton->is( T_READONLY ) && isset( $jetons[ $i + 1 ] ) && $jetons[ $i + 1 ]->is( T_CLASS ) ) {
				$problemes[] = $jeton->line . ' : classe « readonly » (PHP 8.2)';
			}

			// Constante typée : `const string NOM =` (8.3). Hors `use const`.
			if ( $jeton->is( T_CONST ) && ! ( $i > 0 && $jetons[ $i - 1 ]->is( T_USE ) ) ) {
				$noms = 0;
				for ( $k = $i + 1; $k < $n && '=' !== $jetons[ $k ]->text && ';' !== $jetons[ $k ]->text; $k++ ) {
					if ( $jetons[ $k ]->is( [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_STATIC ] ) ) {
						++$noms;
					}
				}
				if ( $noms > 1 ) {
					$problemes[] = $jeton->line . ' : constante de classe typée (PHP 8.3)';
				}
			}

			foreach ( self::types_declares( $jetons, $i ) as $type ) {
				$explication = self::type_php82_plus( $type['texte'] );
				if ( null !== $explication ) {
					$problemes[] = $type['ligne'] . ' : type « ' . $type['texte'] . ' » — ' . $explication;
				}
			}
		}

		return $problemes;
	}

	/**
	 * Types déclarés à partir du jeton $i : retour d'une fonction (après `) :`),
	 * paramètre ou propriété typée (type juste avant une variable).
	 *
	 * @param list<\PhpToken> $jetons
	 * @return list<array{texte: string, ligne: int}>
	 */
	private static function types_declares( array $jetons, int $i ): array {
		$jeton = $jetons[ $i ];
		$types = [];

		// Type de retour : `)` `:` type… jusqu'à `{`, `;` ou `=>`.
		if ( ':' === $jeton->text && $i > 0 && ')' === $jetons[ $i - 1 ]->text && self::parenthese_de_signature( $jetons, $i - 1 ) ) {
			$texte = '';
			for ( $k = $i + 1; isset( $jetons[ $k ] ) && ! in_array( $jetons[ $k ]->text, [ '{', ';', '=>' ], true ); $k++ ) {
				$texte .= $jetons[ $k ]->text;
			}
			$types[] = [ 'texte' => $texte, 'ligne' => $jeton->line ];
		}

		// Type de paramètre ou de propriété : jetons de type collés avant la variable.
		if ( $jeton->is( T_VARIABLE ) ) {
			$k = $i - 1;
			// Passage par référence (`array &$a`) ou variadique (`int ...$a`).
			while ( $k >= 0 && ( '&' === $jetons[ $k ]->text || $jetons[ $k ]->is( T_ELLIPSIS ) ) ) {
				--$k;
			}
			$texte = '';
			for ( ; $k >= 0 && self::jeton_de_type( $jetons[ $k ] ); $k-- ) {
				// Une `(` n'appartient au type que si elle ouvre un groupe DNF :
				// `( (A&B)|null $a`, `, (A&B)|null $a`, `null|(A&B) $a`, `private (A&B)|null $a`.
				if ( '(' === $jetons[ $k ]->text ) {
					$avant = $jetons[ $k - 1 ] ?? null;
					$dnf   = null !== $avant && ( in_array( $avant->text, [ '(', ',', '|' ], true ) || $avant->is( [ T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR, T_STATIC ] ) );
					if ( ! $dnf ) {
						break;
					}
				}
				$texte = $jetons[ $k ]->text . $texte;
			}
			// Un type contient au moins un nom : `( $a )` ou `( $this->x )()` n'en sont pas.
			if ( 1 === preg_match( '/[A-Za-z_]/', $texte ) && $k >= 0 && ( in_array( $jetons[ $k ]->text, [ '(', ',' ], true ) || $jetons[ $k ]->is( [ T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_VAR, T_STATIC ] ) ) ) {
				$types[] = [ 'texte' => $texte, 'ligne' => $jeton->line ];
			}
		}

		return $types;
	}

	/**
	 * La parenthèse fermante en $fin ferme-t-elle la liste de paramètres d'une
	 * fonction, d'une méthode ou d'une fermeture (et pas un `if (…) :` de syntaxe
	 * alternative, ni un ternaire) ?
	 *
	 * @param list<\PhpToken> $jetons
	 */
	private static function parenthese_de_signature( array $jetons, int $fin ): bool {
		$profondeur = 0;
		for ( $k = $fin; $k >= 0; $k-- ) {
			if ( ')' === $jetons[ $k ]->text ) {
				++$profondeur;
			} elseif ( '(' === $jetons[ $k ]->text ) {
				--$profondeur;
				if ( 0 === $profondeur ) {
					$avant = $jetons[ $k - 1 ] ?? null;
					if ( null === $avant ) {
						return false;
					}
					if ( $avant->is( [ T_FUNCTION, T_FN ] ) ) {
						return true;
					}
					// `use (…)` d'une fermeture, ou `function nom(…)` / `function &nom(…)`.
					if ( $avant->is( T_USE ) ) {
						return true;
					}
					$encore_avant = $jetons[ $k - 2 ] ?? null;
					return $avant->is( T_STRING ) && null !== $encore_avant
						&& ( $encore_avant->is( T_FUNCTION ) || ( '&' === $encore_avant->text && ( $jetons[ $k - 3 ] ?? null )?->is( T_FUNCTION ) ) );
				}
			}
		}
		return false;
	}

	private static function jeton_de_type( \PhpToken $jeton ): bool {
		return $jeton->is( [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE, T_STATIC ] )
			|| in_array( $jeton->text, [ '|', '?', '(', ')', '&' ], true )
			|| ( defined( 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG' ) && $jeton->is( [ T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG ] ) );
	}

	private static function type_php82_plus( string $type ): ?string {
		if ( str_contains( $type, '(' ) ) {
			return 'type DNF (PHP 8.2)';
		}
		$parties = array_map( 'strtolower', explode( '|', ltrim( $type, '?' ) ) );
		if ( in_array( 'true', $parties, true ) ) {
			return 'type « true » (PHP 8.2) : utiliser « bool »';
		}
		if ( 1 === count( $parties ) && in_array( $parties[0], [ 'false', 'null' ], true ) ) {
			return 'type « ' . $parties[0] . ' » autonome (PHP 8.2)';
		}
		return null;
	}

	private static function relatif( string $fichier ): string {
		return str_replace( '\\', '/', substr( $fichier, strlen( dirname( __DIR__ ) ) + 1 ) );
	}
}
