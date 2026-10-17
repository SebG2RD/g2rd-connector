<?php
/**
 * Faux $wpdb pour les verrous consultatifs MySQL (Support\MysqlLock).
 *
 * Il ne connaît que deux requêtes, `SELECT GET_LOCK(...)` et `SELECT RELEASE_LOCK(...)` :
 *  - GET_LOCK rend la valeur programmée (`'1'` pris, `'0'` tenu ailleurs, `null`
 *    fonction indisponible) ou lève l'exception programmée ;
 *  - RELEASE_LOCK rend toujours `'1'`.
 *
 * Tout est journalisé dans l'ordre (`events`, que le test complète avec ses propres
 * étapes), avec l'état de masquage des erreurs SQL au moment de chaque requête.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Support;

final class FakeLockWpdb {

	/** Table des options du blog courant (portée « blog » du verrou). */
	public string $options = 'wp_options';

	/** Préfixe commun à tout le réseau (portée « network » du verrou). */
	public string $base_prefix = 'wp_';

	/**
	 * Journal commun, dans l'ordre : `GET_LOCK`, `RELEASE_LOCK`, et ce que le test y ajoute.
	 *
	 * @var list<string>
	 */
	public array $events = [];

	/**
	 * Requêtes de verrou reçues par get_var(), décodées.
	 *
	 * @var list<array{function: string, name: string, timeout: int|null}>
	 */
	public array $lock_calls = [];

	/**
	 * Arguments successifs de suppress_errors().
	 *
	 * @var list<bool>
	 */
	public array $suppress_calls = [];

	/**
	 * Erreurs SQL masquées ou non au moment de chaque requête reçue par get_var().
	 *
	 * @var list<bool>
	 */
	public array $suppressed_during_queries = [];

	/** État courant du masquage des erreurs SQL (celui du site avant le verrou). */
	public bool $suppress = false;

	/**
	 * @param string|null|\Throwable $get_lock_result Réponse programmée de GET_LOCK.
	 */
	public function __construct( private string|null|\Throwable $get_lock_result = '1' ) {
	}

	/**
	 * Remplace `%s` (entre apostrophes) et `%d` (entier) dans l'ordre, comme $wpdb->prepare().
	 */
	public function prepare( string $query, mixed ...$args ): string {
		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $placeholder ) use ( &$args ): string {
				$value = array_shift( $args );
				return '%d' === $placeholder[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function get_var( string $query ): ?string {
		$this->suppressed_during_queries[] = $this->suppress;

		if ( 1 !== preg_match( "/^SELECT (GET_LOCK|RELEASE_LOCK)\('([^']*)'(?:, (-?\d+))?\)$/", $query, $m ) ) {
			throw new \LogicException( 'requête inattendue pour un verrou : ' . $query );
		}

		$this->events[]     = $m[1];
		$this->lock_calls[] = [
			'function' => $m[1],
			'name'     => $m[2],
			'timeout'  => isset( $m[3] ) ? (int) $m[3] : null,
		];

		if ( 'RELEASE_LOCK' === $m[1] ) {
			return '1';
		}
		if ( $this->get_lock_result instanceof \Throwable ) {
			throw $this->get_lock_result;
		}
		return $this->get_lock_result;
	}

	public function suppress_errors( bool $suppress = true ): bool {
		$this->suppress_calls[] = $suppress;
		$previous               = $this->suppress;
		$this->suppress         = $suppress;
		return $previous;
	}

	/**
	 * Noms passés à GET_LOCK puis à RELEASE_LOCK, dans l'ordre.
	 *
	 * @return list<string>
	 */
	public function lock_names(): array {
		return array_column( $this->lock_calls, 'name' );
	}
}
