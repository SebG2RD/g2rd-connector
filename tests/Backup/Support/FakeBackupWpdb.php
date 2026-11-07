<?php
/**
 * Faux $wpdb des tests du module de sauvegarde : tables des options (les requêtes de
 * CommandRuns\OptionRow, une table par blog, nom lu dans $wpdb->options) et verrous consultatifs
 * (GET_LOCK, RELEASE_LOCK de Support\MysqlLock). Toute autre requête, ou une requête non préparée,
 * lève une \LogicException : un test ne valide jamais une requête que la vraie base lirait
 * autrement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Support;

final class FakeBackupWpdb {

	public string $options = 'wp_options';

	public string $base_prefix = 'wp_';

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var array<string, array<string, string>> Lignes, par table puis par nom d'option. */
	public array $tables = [];

	/** @var list<string> Requêtes reçues, telles que prepare() les a rendues. */
	public array $queries = [];

	/** @var array<string, true> Verrous tenus par un autre processus : GET_LOCK rend '0'. */
	public array $held_elsewhere = [];

	/** Vrai : GET_LOCK rend NULL (fonction refusée par l'hébergeur). */
	public bool $locks_unavailable = false;

	/** @var array<string, int> Verrous tenus par ce processus, avec leur compte. */
	public array $held_here = [];

	/** Vrai : toute lecture d'option échoue, `last_error` renseignée. */
	public bool $read_fails = false;

	/** Vrai : toute écriture d'option échoue (query() rend false). */
	public bool $write_fails = false;

	/** Appelée une fois juste avant la prochaine écriture : un autre processus écrit entre-temps. */
	public ?\Closure $before_write = null;

	/** @var array<string, array{0: string, 1: list<mixed>}> Requête préparée → modèle et valeurs. */
	private array $prepared = [];

	private bool $suppress = false;

	public function prepare( string $query, mixed ...$args ): string {
		$args   = array_values( $args );
		$values = $args;
		$sql    = (string) preg_replace_callback(
			'/%[sdi]/',
			static function ( array $placeholder ) use ( &$values ): string {
				$value = array_shift( $values );
				if ( '%d' === $placeholder[0] ) {
					return (string) (int) $value;
				}
				if ( '%i' === $placeholder[0] ) {
					return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}
				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);

		$this->prepared[ $sql ] = [ $query, $args ];
		return $sql;
	}

	public function suppress_errors( bool $suppress = true ): bool {
		$previous       = $this->suppress;
		$this->suppress = $suppress;
		return $previous;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( string $sql ): ?string {
		[ $template, $args ] = $this->receive( $sql );

		if ( 'SELECT GET_LOCK(%s, %d)' === $template ) {
			if ( $this->locks_unavailable ) {
				return null;
			}
			$name = (string) $args[0];
			if ( isset( $this->held_elsewhere[ $name ] ) ) {
				return '0';
			}
			$this->held_here[ $name ] = ( $this->held_here[ $name ] ?? 0 ) + 1;
			return '1';
		}
		if ( 'SELECT RELEASE_LOCK(%s)' === $template ) {
			$name = (string) $args[0];
			if ( ! isset( $this->held_here[ $name ] ) ) {
				return '0';
			}
			--$this->held_here[ $name ];
			if ( 0 === $this->held_here[ $name ] ) {
				unset( $this->held_here[ $name ] );
			}
			return '1';
		}
		if ( 1 === preg_match( '/^SELECT option_value FROM (\S+) WHERE option_name = %s LIMIT 1$/', $template, $match ) ) {
			if ( $this->read_fails ) {
				$this->last_error = 'MySQL server has gone away';
				return null;
			}
			return $this->tables[ $match[1] ][ (string) $args[0] ] ?? null;
		}

		throw new \LogicException( 'Lecture non prévue par le faux $wpdb : ' . $sql );
	}

	/**
	 * @return int|false Lignes touchées ; false si la base refuse.
	 */
	public function query( string $sql ) {
		[ $template, $args ] = $this->receive( $sql );

		if ( null !== $this->before_write ) {
			$other              = $this->before_write;
			$this->before_write = null;
			$other();
		}
		if ( $this->write_fails ) {
			$this->last_error = 'Lock wait timeout exceeded; try restarting transaction';
			return false;
		}

		if ( 1 === preg_match( '/^INSERT IGNORE INTO (\S+) \(option_name, option_value, autoload\) VALUES \(%s, %s, %s\)$/', $template, $match ) ) {
			$name = (string) $args[0];
			if ( isset( $this->tables[ $match[1] ][ $name ] ) ) {
				return 0;
			}
			$this->tables[ $match[1] ][ $name ] = (string) $args[1];
			return 1;
		}
		if ( 1 === preg_match( '/^UPDATE (\S+) SET option_value = %s WHERE option_name = %s AND option_value = %s$/', $template, $match ) ) {
			[ $value, $name, $expected ] = [ (string) $args[0], (string) $args[1], (string) $args[2] ];
			if ( ( $this->tables[ $match[1] ][ $name ] ?? null ) !== $expected || $expected === $value ) {
				return 0;
			}
			$this->tables[ $match[1] ][ $name ] = $value;
			return 1;
		}
		if ( 1 === preg_match( '/^DELETE FROM (\S+) WHERE option_name = %s AND option_value = %s$/', $template, $match ) ) {
			[ $name, $expected ] = [ (string) $args[0], (string) $args[1] ];
			if ( ( $this->tables[ $match[1] ][ $name ] ?? null ) !== $expected ) {
				return 0;
			}
			unset( $this->tables[ $match[1] ][ $name ] );
			return 1;
		}

		throw new \LogicException( 'Écriture non prévue par le faux $wpdb : ' . $sql );
	}

	/** Valeur JSON décodée d'une option, ou null si absente. */
	public function option( string $name, string $table = 'wp_options' ): mixed {
		$raw = $this->tables[ $table ][ $name ] ?? null;
		return null === $raw ? null : json_decode( $raw, true );
	}

	/**
	 * Requêtes autres que les lectures d'option : verrous et écritures.
	 *
	 * @return list<string>
	 */
	public function locks_and_writes(): array {
		return array_values( array_filter( $this->queries, static fn ( string $sql ): bool => ! str_starts_with( $sql, 'SELECT option_value' ) ) );
	}

	/**
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function receive( string $sql ): array {
		$this->queries[]  = $sql;
		$this->last_error = '';
		if ( ! isset( $this->prepared[ $sql ] ) ) {
			throw new \LogicException( 'Requête non préparée reçue par le faux $wpdb : ' . $sql );
		}
		return $this->prepared[ $sql ];
	}
}
