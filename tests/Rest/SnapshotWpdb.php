<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

/**
 * Faux $wpdb, réduit à ce que lit l'inventaire : la version du serveur et les
 * compteurs de santé de la base (db_health). Chaque requête est notée ; une requête
 * peut être mise en échec (last_error renseigné, comme wpdb après une erreur SQL).
 */
final class SnapshotWpdb {

	public string $posts = 'wp_posts';

	public string $options = 'wp_options';

	public string $prefix = 'wp_';

	public string $last_error = '';

	/** @var list<string> */
	public array $queries = [];

	/** Fragment de requête qui échoue (null : aucune). */
	public ?string $fail_on = null;

	/** Valeurs rendues, modifiables par le test. */
	public int $revisions = 12;

	public int $trashed = 4;

	public int $expired_transients = 7;

	public int $autoload_bytes = 2097152;

	/** @var list<array{Name: string, Data_free: string}> */
	public array $table_status = [
		[
			'Name'      => 'wp_posts',
			'Data_free' => '1048576',
		],
		[
			'Name'      => 'wp_options',
			'Data_free' => '524288',
		],
	];

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/** @return string|null */
	public function get_var( string $query ) {
		if ( $this->failed( $query ) ) {
			return null;
		}
		if ( str_contains( $query, 'VERSION()' ) ) {
			return '10.11.6-MariaDB';
		}
		if ( str_contains( $query, "post_type = 'revision'" ) ) {
			return (string) $this->revisions;
		}
		if ( str_contains( $query, "post_status = 'trash'" ) ) {
			return (string) $this->trashed;
		}
		if ( str_contains( $query, '_transient_timeout_' ) ) {
			return (string) $this->expired_transients;
		}
		if ( str_contains( $query, 'SUM(LENGTH(option_value))' ) ) {
			return (string) $this->autoload_bytes;
		}
		return null;
	}

	/**
	 * @param mixed $output
	 * @return list<array<string, string>>
	 */
	public function get_results( string $query, $output = null ): array {
		unset( $output );
		if ( $this->failed( $query ) ) {
			return [];
		}
		return $this->table_status;
	}

	/** Requêtes de santé de la base (tout sauf la version du serveur). */
	public function health_queries(): int {
		return count( array_filter( $this->queries, static fn ( string $q ): bool => ! str_contains( $q, 'VERSION()' ) ) );
	}

	private function failed( string $query ): bool {
		$this->queries[]  = $query;
		$this->last_error = '';
		if ( null !== $this->fail_on && str_contains( $query, $this->fail_on ) ) {
			$this->last_error = 'Table \'wp_posts\' is marked as crashed and should be repaired';
			return true;
		}
		return false;
	}
}
