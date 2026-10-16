<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

/**
 * Faux $wpdb : la table des options en mémoire, avec sa clé unique sur option_name,
 * réduite aux cinq requêtes des exécutions en arrière-plan (RunStore, puis le créneau
 * et la purge) :
 *   - « INSERT IGNORE » : une ligne ajoutée si le nom est libre, aucune sinon ;
 *   - « SELECT option_value … WHERE option_name = %s LIMIT 1 » ;
 *   - « UPDATE … WHERE option_name = %s AND option_value = %s » : une ligne modifiée
 *     seulement si la valeur en base est encore celle attendue ;
 *   - « DELETE … WHERE option_name = %s AND option_value = %s », sur le même principe ;
 *   - « SELECT option_name, option_value … WHERE option_name LIKE %s LIMIT %d ».
 *
 * Toute autre requête, ou une requête qui n'est pas passée par prepare(), lève une
 * exception : le test échoue au lieu de valider une requête que la vraie base lirait
 * autrement. Modèle : tests/DirectLogin/FakeWpdb.php.
 *
 * Écarts assumés avec MySQL :
 *   - comparaison exacte des valeurs (MySQL compare selon la collation de la colonne,
 *     souvent insensible à la casse) : les valeurs du connecteur sont du JSON en ASCII,
 *     wp_json_encode() échappant tout le reste ;
 *   - un UPDATE qui écrit la valeur déjà en place ne compte aucune ligne, comme MySQL,
 *     qui compte les lignes changées et non les lignes trouvées.
 */
final class FakeOptionsTable {

	public string $options = 'wp_options';

	/**
	 * Lignes de la table, par nom d'option.
	 *
	 * @var array<string, array{value: string, autoload: string}>
	 */
	public array $rows = [];

	/**
	 * Requêtes reçues, dans l'ordre, telles que prepare() les a rendues.
	 *
	 * @var list<string>
	 */
	public array $queries = [];

	/** Quand vrai, la base refuse toute écriture (query() rend false) ; les lectures marchent. */
	public bool $fails = false;

	/**
	 * Appelée une fois, juste avant la prochaine écriture, puis oubliée : un autre
	 * processus qui écrit entre la lecture et l'écriture de celui qu'on observe.
	 */
	public ?\Closure $before_write = null;

	/**
	 * Requête préparée → [ modèle, valeurs ].
	 *
	 * @var array<string, array{0: string, 1: list<mixed>}>
	 */
	private array $prepared = [];

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$args = array_values( $args );
		$sql  = vsprintf(
			str_replace( '%s', "'%s'", $query ),
			array_map( static fn ( $arg ) => is_string( $arg ) ? addslashes( $arg ) : $arg, $args )
		);

		$this->prepared[ $sql ] = [ $query, $args ];
		return $sql;
	}

	/**
	 * Écritures : INSERT IGNORE, UPDATE et DELETE conditionnels.
	 *
	 * @return int|false Lignes ajoutées, modifiées ou supprimées ; false si la base refuse.
	 */
	public function query( string $sql ) {
		[ $template, $args ] = $this->receive( $sql );

		if ( null !== $this->before_write ) {
			$other              = $this->before_write;
			$this->before_write = null;
			$other();
		}
		if ( $this->fails ) {
			return false;
		}

		if ( $this->is( 'INSERT IGNORE INTO {options} (option_name, option_value, autoload) VALUES (%s, %s, %s)', $template ) ) {
			[ $name, $value, $autoload ] = $args;
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = [
				'value'    => (string) $value,
				'autoload' => (string) $autoload,
			];
			return 1;
		}

		if ( $this->is( 'UPDATE {options} SET option_value = %s WHERE option_name = %s AND option_value = %s', $template ) ) {
			[ $value, $name, $expected ] = $args;
			if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ]['value'] !== $expected || $expected === $value ) {
				return 0;
			}
			$this->rows[ $name ]['value'] = (string) $value;
			return 1;
		}

		if ( $this->is( 'DELETE FROM {options} WHERE option_name = %s AND option_value = %s', $template ) ) {
			[ $name, $expected ] = $args;
			if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ]['value'] !== $expected ) {
				return 0;
			}
			unset( $this->rows[ $name ] );
			return 1;
		}

		throw new \LogicException( 'Écriture non prévue par le faux $wpdb : ' . $sql );
	}

	/**
	 * Lecture d'une valeur : null si l'option est absente.
	 */
	public function get_var( string $sql ): ?string {
		[ $template, $args ] = $this->receive( $sql );

		if ( $this->is( 'SELECT option_value FROM {options} WHERE option_name = %s LIMIT 1', $template ) ) {
			return $this->rows[ $args[0] ]['value'] ?? null;
		}

		throw new \LogicException( 'Lecture non prévue par le faux $wpdb : ' . $sql );
	}

	/**
	 * Recherche par préfixe, dans l'ordre des noms (parcours de l'index option_name).
	 *
	 * @return list<array{option_name: string, option_value: string}>
	 */
	public function get_results( string $sql, string $output = 'OBJECT' ): array {
		[ $template, $args ] = $this->receive( $sql );

		if ( ARRAY_A !== $output || ! $this->is( 'SELECT option_name, option_value FROM {options} WHERE option_name LIKE %s LIMIT %d', $template ) ) {
			throw new \LogicException( 'Recherche non prévue par le faux $wpdb : ' . $sql );
		}

		[ $pattern, $limit ] = $args;
		$names               = array_keys( $this->rows );
		sort( $names, SORT_STRING );

		$found = [];
		foreach ( $names as $name ) {
			if ( count( $found ) >= (int) $limit ) {
				break;
			}
			if ( 1 === preg_match( self::like_to_regex( (string) $pattern ), $name ) ) {
				$found[] = [
					'option_name'  => $name,
					'option_value' => $this->rows[ $name ]['value'],
				];
			}
		}

		return $found;
	}

	/**
	 * Journalise la requête et rend le modèle et les valeurs passés à prepare().
	 *
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function receive( string $sql ): array {
		$this->queries[] = $sql;
		if ( ! isset( $this->prepared[ $sql ] ) ) {
			throw new \LogicException( 'Requête non préparée : ' . $sql );
		}
		return $this->prepared[ $sql ];
	}

	private function is( string $expected, string $template ): bool {
		return str_replace( '{options}', $this->options, $expected ) === $template;
	}

	/**
	 * Motif LIKE (« % », « _ », échappés par « \ » comme esc_like()) → expression régulière.
	 */
	private static function like_to_regex( string $pattern ): string {
		$regex  = '';
		$length = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $char ) {
				$regex .= '.*';
			} elseif ( '_' === $char ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $char, '/' );
			}
		}
		return '/^' . $regex . '$/sD';
	}
}
