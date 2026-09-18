<?php
/**
 * Doublures minimales des classes WordPress utilisées par le code testé.
 * Volontairement réduites à ce que les tests exercent : ce ne sont pas des
 * réimplémentations de WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @param mixed $data */
		public function __construct( private string $code = '', private string $message = '', private $data = null ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/** @return mixed */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, string> */
		private array $headers = [];
		/** @var array<string, mixed> */
		private array $params = [];
		private string $body  = '';

		public function __construct( private string $method = 'GET', private string $route = '' ) {}

		public function set_header( string $name, string $value ): void {
			$this->headers[ self::canonical( $name ) ] = $value;
		}

		public function get_header( string $name ): ?string {
			return $this->headers[ self::canonical( $name ) ] ?? null;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
		}

		/** @param mixed $value */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/** @return mixed */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		/** Même canonicalisation que WordPress : minuscules, tirets → underscores. */
		private static function canonical( string $name ): string {
			return str_replace( '-', '_', strtolower( $name ) );
		}
	}
}

if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	class Automatic_Upgrader_Skin {}
}

if ( ! class_exists( 'Plugin_Upgrader' ) ) {
	/**
	 * Doublure : le résultat de upgrade() est fixé par le test ; un callable permet
	 * de simuler l'effet de bord réel (remplacement des fichiers du plugin).
	 */
	class Plugin_Upgrader {
		/** @var mixed */
		public static $next_result = true;
		/** @var callable|null */
		public static $on_upgrade = null;
		/** @var list<string> */
		public static array $upgraded = [];

		public function __construct( public object $skin ) {}

		/** @return mixed */
		public function upgrade( string $file ) {
			self::$upgraded[] = $file;
			if ( null !== self::$on_upgrade ) {
				( self::$on_upgrade )( $file );
			}
			return self::$next_result;
		}

		public static function reset(): void {
			self::$next_result = true;
			self::$on_upgrade  = null;
			self::$upgraded    = [];
		}
	}
}
