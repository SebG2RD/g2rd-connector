<?php
/**
 * Réglages de journalisation de PHP et de WordPress (bloc `php_logging` v1 du snapshot), lus à
 * chaque snapshot pour quelques appels : `log_errors`, `display_errors`, `WP_DEBUG`,
 * `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, emplacement du journal PHP (`error_log`), et, s'il est rangé
 * dans WordPress, sa lisibilité et sa taille. C'est la mesure qui précède toute activation de la
 * lecture du journal (évolution stratégique, § 6, ligne LogTail).
 *
 * Confinement (correction du critique, § 12.2 n° 3) : le compte d'hébergement est commun aux
 * sites et au manager ; seul un journal rangé sous la racine de WordPress (ABSPATH) est lisible.
 * Refusés : chemin relatif, « .. », lien symbolique (le chemin réel doit être le chemin écrit),
 * autre chose qu'un fichier ordinaire. AUCUN chemin dans la sortie, jamais.
 *
 * Jamais d'avertissement non plus, qui porterait le chemin (dans le journal du site, ou avant
 * le JSON du snapshot si display_errors est actif). Un chemin écrit hors de la racine, telle
 * qu'écrite ou réelle, est hors de WordPress sans aucun appel au disque : sous open_basedir,
 * is_link() et realpath() avertiraient, et /dev/stderr (un lien sous Linux) serait annoncé
 * refusé. Sous la racine, ces deux appels sont muets ; un lien qui mène hors de la zone
 * d'open_basedir est hors de WordPress (jamais « absent »).
 *
 * Ce qui n'a pas pu être lu est inconnu (null) : `error_log_readable` n'est vrai ou faux que
 * pour un journal rangé dans WordPress (vérifié) ou absent (faux) ; il vaut null hors de
 * WordPress, non défini, refusé ou commun au réseau. Si la sonde lève, bloc minimal `{version}`.
 *
 * Multisite : le journal PHP est commun à tout le réseau. Seul le site principal le lit ; sur un
 * site secondaire, un journal rangé dans WordPress est annoncé `network_shared` et
 * readable_log_path() rend null (sinon chaque blog rangerait dans son tampon les erreurs de tout
 * le réseau, comme les siennes).
 *
 * Fermetures injectables (tests), aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

final class LoggingProbe {

	public const VERSION = 1;

	public const LOG_UNSET   = 'unset';
	public const LOG_INSIDE  = 'inside_wordpress';
	public const LOG_OUTSIDE = 'outside_wordpress';
	public const LOG_MISSING = 'missing';
	public const LOG_REFUSED = 'refused';
	public const LOG_NETWORK = 'network_shared';

	/** @var \Closure(string): (string|false) */
	private \Closure $ini_get;

	/** @var \Closure(string): mixed */
	private \Closure $constant;

	private string $root;

	/** @var \Closure(): bool */
	private \Closure $secondary_site;

	/**
	 * @param (\Closure(string): (string|false))|null $ini_get        ini_get() (tests).
	 * @param (\Closure(string): mixed)|null          $constant       Valeur d'une constante, null si absente (tests).
	 * @param string|null                             $root           Racine de WordPress (ABSPATH par défaut).
	 * @param (\Closure(): bool)|null                 $secondary_site Site secondaire d'un réseau multisite (tests).
	 */
	public function __construct( ?\Closure $ini_get = null, ?\Closure $constant = null, ?string $root = null, ?\Closure $secondary_site = null ) {
		$this->ini_get        = $ini_get ?? static fn ( string $option ): string|false => ini_get( $option );
		$this->constant       = $constant ?? static fn ( string $name ): mixed => defined( $name ) ? constant( $name ) : null;
		$this->root           = $root ?? ( defined( 'ABSPATH' ) ? (string) ABSPATH : '' );
		$this->secondary_site = $secondary_site ?? static fn (): bool => function_exists( 'is_multisite' ) && function_exists( 'is_main_site' ) && true === is_multisite() && true !== is_main_site();
	}

	/**
	 * Bloc `php_logging`, dans l'ordre des clés du contrat.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		try {
			$where    = $this->locate();
			$readable = null !== $where['path'] && is_readable( $where['path'] );
			$bytes    = $readable && null !== $where['path'] ? filesize( $where['path'] ) : false;
			// Vérifié seulement dans WordPress (vrai ou faux) ou absent (faux) ; inconnu sinon.
			$checked  = match ( $where['status'] ) {
				self::LOG_INSIDE  => $readable,
				self::LOG_MISSING => false,
				default           => null,
			};
			$display  = ( $this->constant )( 'WP_DEBUG_DISPLAY' );
			$log      = ( $this->constant )( 'WP_DEBUG_LOG' );

			return [
				'version'            => self::VERSION,
				'log_errors'         => $this->ini_flag( 'log_errors' ),
				'display_errors'     => $this->ini_flag( 'display_errors' ),
				'wp_debug'           => true === ( $this->constant )( 'WP_DEBUG' ),
				'wp_debug_log'       => true === $log || ( is_string( $log ) && '' !== $log && ! in_array( strtolower( $log ), [ 'false', '0' ], true ) ),
				'wp_debug_display'   => null === $display ? null : (bool) $display,
				'error_log'          => $where['status'],
				'error_log_readable' => $checked,
				'error_log_bytes'    => is_int( $bytes ) ? $bytes : null,
			];
		} catch ( \Throwable ) {
			return [ 'version' => self::VERSION ];
		}
	}

	/**
	 * Chemin réel du journal PHP s'il est rangé dans WordPress et lisible ; null sinon. Usage
	 * interne (lecture du journal) : jamais envoyé.
	 */
	public function readable_log_path(): ?string {
		try {
			$where = $this->locate();

			return null !== $where['path'] && is_readable( $where['path'] ) ? $where['path'] : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * @return array{status: string, path: string|null}
	 */
	private function locate(): array {
		$raw = ( $this->ini_get )( 'error_log' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return self::where( self::LOG_UNSET );
		}
		$raw = trim( $raw );
		if ( 'syslog' === strtolower( $raw ) ) {
			return self::where( self::LOG_OUTSIDE );
		}
		$written = self::normalize( $raw );
		if ( ! self::is_absolute( $written ) || 1 === preg_match( '#(?:\A|/)\.\.(?:/|\z)#', $written ) ) {
			return self::where( self::LOG_REFUSED );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bridé chez certains hébergeurs (open_basedir) : « hors de WordPress », jamais un avertissement qui porterait le chemin.
		$root = '' === $this->root ? false : @realpath( $this->root );
		if ( false === $root ) {
			return self::where( self::LOG_OUTSIDE );
		}
		$root = self::directory( $root );
		if ( ! str_starts_with( $written, $root ) && ! str_starts_with( $written, self::directory( $this->root ) ) ) {
			// Hors de la racine : aucun appel au disque sur ce chemin.
			return self::where( self::LOG_OUTSIDE );
		}
		// Vidée pour reconnaître un refus d'open_basedir (seule une erreur fatale compte à l'arrêt).
		error_clear_last();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bridé chez certains hébergeurs (open_basedir) : jamais un avertissement qui porterait le chemin.
		if ( @is_link( $raw ) ) {
			return self::where( self::LOG_REFUSED );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bridé chez certains hébergeurs (open_basedir) : jamais un avertissement qui porterait le chemin.
		$real = @realpath( $raw );
		if ( false === $real ) {
			// Refus d'open_basedir : un lien mène hors de la zone permise à PHP, donc hors de WordPress.
			return self::where( self::beyond_open_basedir() || ! str_starts_with( $written, $root ) ? self::LOG_OUTSIDE : self::LOG_MISSING );
		}
		$real = self::normalize( $real );
		if ( ! str_starts_with( $real, $root ) ) {
			return self::where( self::LOG_OUTSIDE );
		}
		if ( $real !== $written || ! is_file( $real ) ) {
			// Lien symbolique quelque part dans le chemin, ou pas un fichier ordinaire.
			return self::where( self::LOG_REFUSED );
		}
		if ( ( $this->secondary_site )() ) {
			// Journal commun au réseau : lu par le site principal seulement.
			return self::where( self::LOG_NETWORK );
		}

		return [
			'status' => self::LOG_INSIDE,
			'path'   => $real,
		];
	}

	/**
	 * @return array{status: string, path: null}
	 */
	private static function where( string $status ): array {
		return [
			'status' => $status,
			'path'   => null,
		];
	}

	private function ini_flag( string $option ): ?bool {
		$value = ( $this->ini_get )( $option );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		if ( in_array( $value, [ '1', 'on', 'yes', 'true', 'stderr', 'stdout' ], true ) ) {
			return true;
		}

		return in_array( $value, [ '0', 'off', 'no', 'false', '' ], true ) ? false : null;
	}

	/**
	 * L'erreur que is_link() ou realpath(), muets, viennent de lever est un refus
	 * d'open_basedir. Lue puis effacée : elle porte le chemin.
	 */
	private static function beyond_open_basedir(): bool {
		$last = error_get_last();
		error_clear_last();

		return null !== $last && str_contains( $last['message'], 'open_basedir' );
	}

	/** Dossier normalisé, avec un seul `/` final. */
	private static function directory( string $path ): string {
		return rtrim( self::normalize( $path ), '/' ) . '/';
	}

	private static function normalize( string $path ): string {
		return (string) preg_replace( '#/{2,}#', '/', str_replace( '\\', '/', $path ) );
	}

	private static function is_absolute( string $path ): bool {
		return str_starts_with( $path, '/' ) || 1 === preg_match( '#\A[A-Za-z]:/#', $path );
	}
}
