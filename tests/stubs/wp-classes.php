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

if ( ! class_exists( 'WP_User' ) ) {
	/** Doublure : seules les propriétés lues par le connecteur. */
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $user_login = '';
		/** @var string */
		public $user_email = '';
		/** @var string */
		public $user_registered = '';
		/** @var list<string> */
		public $roles = [];
	}
}

if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	class Automatic_Upgrader_Skin {}
}

if ( ! class_exists( 'Plugin_Upgrader' ) ) {
	/**
	 * Doublure : le résultat de upgrade() est fixé par le test ; des callables
	 * permettent de simuler le téléchargement (sa durée) et l'effet de bord réel
	 * (remplacement des fichiers du plugin). Entre les deux, comme
	 * WP_Upgrader::install_package(), le filtre `upgrader_pre_install` : une WP_Error
	 * arrête la mise à jour avant tout changement des fichiers.
	 */
	class Plugin_Upgrader {
		/** @var mixed */
		public static $next_result = true;
		/** @var callable|null Téléchargement et décompression : avant le filtre `upgrader_pre_install`. */
		public static $on_download = null;
		/** @var callable|null Remplacement des fichiers : après le filtre, s'il n'a pas rendu de WP_Error. */
		public static $on_upgrade = null;
		/**
		 * @var callable|null Crochets de `upgrader_process_complete` (traductions, extensions) :
		 *                    après la copie et le filtre `upgrader_post_install`, encore dans
		 *                    upgrade(), comme WP_Upgrader::run().
		 */
		public static $on_complete = null;
		/**
		 * @var mixed Retour de upgrade() quand le filtre rend une WP_Error. Null : la
		 *            WP_Error elle-même. WordPress ne garde le résultat d'install_package()
		 *            qu'après une installation réussie : selon la version, upgrade() peut
		 *            rendre autre chose (tableau vide).
		 */
		public static $result_on_pre_install_error = null;
		/** @var list<string> */
		public static array $upgraded = [];

		public function __construct( public object $skin ) {}

		/** @return mixed */
		public function upgrade( string $file ) {
			self::$upgraded[] = $file;
			if ( null !== self::$on_download ) {
				( self::$on_download )( $file );
			}
			$hook_extra  = [
				'plugin' => $file,
				'type'   => 'plugin',
				'action' => 'update',
			];
			$pre_install = apply_filters( 'upgrader_pre_install', true, $hook_extra );
			if ( $pre_install instanceof WP_Error ) {
				return self::$result_on_pre_install_error ?? $pre_install;
			}
			if ( null !== self::$on_upgrade ) {
				( self::$on_upgrade )( $file );
			}
			if ( self::$next_result instanceof WP_Error ) {
				// Copie en échec : install_package() rend l'erreur sans passer par `upgrader_post_install`.
				return self::$next_result;
			}
			// Copie terminée : WP_Upgrader::install_package() applique ce filtre, puis
			// run() déclenche `upgrader_process_complete`, même si le filtre a rendu une erreur.
			$post_install = apply_filters( 'upgrader_post_install', true, $hook_extra, [] );
			if ( null !== self::$on_complete ) {
				( self::$on_complete )( $file );
			}
			return $post_install instanceof WP_Error ? $post_install : self::$next_result;
		}

		public static function reset(): void {
			self::$next_result                 = true;
			self::$on_download                 = null;
			self::$on_upgrade                  = null;
			self::$on_complete                 = null;
			self::$result_on_pre_install_error = null;
			self::$upgraded                    = [];
		}
	}
}
