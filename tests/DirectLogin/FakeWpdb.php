<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

/**
 * Faux $wpdb, réduit à ce que UsedTickets demande à la table des options :
 *   - « INSERT IGNORE » : une ligne ajoutée si le nom est libre, aucune sinon
 *     (clé unique option_name) — c'est ce qui rend l'usage unique atomique ;
 *   - la recherche par préfixe de la purge, limitée par LIMIT.
 * Les lignes vivent dans la table d'options en mémoire du test (TestCase::$options),
 * lue et écrite à travers deux fonctions fournies par le test.
 */
final class FakeWpdb {

	public string $options = 'wp_options';

	/** @var list<string> */
	public array $queries = [];

	/** Quand vrai, la base refuse toute écriture (query() rend false). */
	public bool $fails = false;

	/** @var list<mixed> */
	private array $last_args = [];

	/**
	 * @param \Closure(): list<string>            $names  Noms des options présentes.
	 * @param \Closure(string, string, string): void $insert Ajoute une ligne (nom, valeur, autoload).
	 */
	public function __construct( private \Closure $names, private \Closure $insert ) {}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = array_values( $args );
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * @return int|false Lignes ajoutées, ou false si la base refuse.
	 */
	public function query( string $query ) {
		$this->queries[] = $query;
		if ( $this->fails ) {
			return false;
		}
		[ $name, $value, $autoload ] = $this->last_args;
		if ( in_array( $name, ( $this->names )(), true ) ) {
			return 0;
		}
		( $this->insert )( (string) $name, (string) $value, (string) $autoload );
		return 1;
	}

	/** @return list<string> */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		$limit           = (int) ( $this->last_args[1] ?? PHP_INT_MAX );
		return array_slice(
			array_values(
				array_filter(
					( $this->names )(),
					static fn ( string $name ): bool => str_starts_with( $name, 'g2rd_login_used_' )
				)
			),
			0,
			$limit
		);
	}
}
