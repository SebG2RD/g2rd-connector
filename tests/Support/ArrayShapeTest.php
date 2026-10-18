<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Support;

use G2RD\Connector\Support\ArrayShape;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ArrayShape::is_list() remplace array_is_list() (refusée à tort par Plugin Check avec
 * WordPress 6.4 minimum) : elle doit rendre la même réponse que la fonction native
 * dans tous les cas, sinon un objet JSON serait pris pour une liste ou l'inverse.
 */
final class ArrayShapeTest extends TestCase {

	/**
	 * @return array<string, array{0: array<mixed>}>
	 */
	public static function shapes(): array {
		return [
			'vide'                         => [ [] ],
			'liste'                        => [ [ 'a', 'b', 'c' ] ],
			'liste de tableaux'            => [ [ [ 'x' => 1 ], [ 'y' => 2 ] ] ],
			'clés numériques en chaîne'    => [ json_decode( '{"0":"a","1":"b"}', true ) ],
			'commence à 1'                 => [ [ 1 => 'a', 2 => 'b' ] ],
			'trou dans les clés'           => [ [ 0 => 'a', 2 => 'b' ] ],
			'clés dans le désordre'        => [ [ 1 => 'a', 0 => 'b' ] ],
			'clés texte'                   => [ [ 'command_id' => 'x', 'state' => 'done' ] ],
			'mélange'                      => [ [ 0 => 'a', 'b' => 'c' ] ],
			'objet JSON décodé'            => [ json_decode( '{"seconds":5}', true ) ],
			'liste JSON décodée'           => [ json_decode( '[1,2,3]', true ) ],
			'clé négative'                 => [ [ -1 => 'a', 0 => 'b' ] ],
			'clé « 01 » (reste du texte)'  => [ json_decode( '{"01":"a"}', true ) ],
			'désordre venu du JSON'        => [ json_decode( '{"1":"a","0":"b"}', true ) ],
			'liste dont un élément retiré' => [ (static function (): array {
				$values = [ 'a', 'b', 'c' ];
				unset( $values[1] );
				return $values;
			} )() ],
		];
	}

	/**
	 * @param array<mixed> $value Tableau examiné.
	 */
	#[DataProvider( 'shapes' )]
	public function test_meme_reponse_que_la_fonction_native( array $value ): void {
		self::assertSame( array_is_list( $value ), ArrayShape::is_list( $value ) );
	}

	/**
	 * Fichiers PHP livrés (ceux que Plugin Check analyse) : un appel à la fonction native
	 * ferait échouer la publication. Les commentaires ne comptent pas : seul le code est lu.
	 */
	public function test_le_code_livre_n_appelle_plus_la_fonction_native(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = [ $root . '/g2rd-connector.php', $root . '/uninstall.php' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = $file->getPathname();
			}
		}

		$found = [];
		foreach ( $paths as $path ) {
			foreach ( self::native_calls( (string) file_get_contents( $path ) ) as $line ) {
				$found[] = substr( $path, strlen( $root ) + 1 ) . ':' . $line;
			}
		}

		self::assertSame( [], $found, 'Plugin Check refuse array_is_list() avec « Requires at least: 6.4 » : utiliser Support\ArrayShape::is_list().' );
	}

	/** La garde voit un appel, avec ou sans « \ » et espace, et ignore commentaires et méthodes homonymes. */
	public function test_la_garde_reconnait_un_appel_et_ignore_le_reste(): void {
		$code = "<?php\n"
			. "// array_is_list( \$a ) en commentaire\n"
			. "/** array_is_list( \$a ) dans un docblock */\n"
			. "\$x = ArrayShape::is_list( \$a );\n"
			. "\$y = \$objet->array_is_list( \$a );\n"
			. "\$z = Autre::array_is_list( \$a );\n"
			. "\$u = \\array_is_list ( \$b );\n"
			. "\$v = array_is_list( \$c );\n";

		self::assertSame( [ 7, 8 ], self::native_calls( $code ) );
	}

	/**
	 * Lignes où le code appelle la fonction native array_is_list (lecture par jetons PHP :
	 * commentaires, chaînes, méthodes et fonctions homonymes d'une classe ignorés).
	 *
	 * @return list<int>
	 */
	private static function native_calls( string $code ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static fn ( $token ): bool => ! is_array( $token ) || ! in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true )
			)
		);

		$lines = [];
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) {
				continue;
			}
			if ( 'array_is_list' !== strtolower( ltrim( $token[1], '\\' ) ) || '(' !== ( $tokens[ $index + 1 ] ?? null ) ) {
				continue;
			}
			$previous = $tokens[ $index - 1 ] ?? null;
			if ( is_array( $previous ) && in_array( $previous[0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true ) ) {
				continue;
			}
			$lines[] = $token[2];
		}

		return $lines;
	}
}
