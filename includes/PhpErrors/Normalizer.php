<?php
/**
 * Normalisation d'une erreur PHP relevée sur le site (évolution stratégique, § 6) : niveau,
 * chemin relatif à WordPress, composant (extension, thème, cœur, mu-plugin, inconnu), message
 * filtré (Redactor), empreinte stable et version du composant au moment du relevé.
 *
 * Classe pure, sans effet au chargement. Seule component_version() lit WordPress (en-têtes
 * de l'extension ou du thème), et seulement au moment d'enregistrer.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

/**
 * @phpstan-type Occurrence array{fp: string, level: string, type: string, slug: string|null, file: string, line: int, message: string, source: string}
 */
final class Normalizer {

	public const LEVEL_FATAL      = 'fatal';
	public const LEVEL_ERROR      = 'error';
	public const LEVEL_WARNING    = 'warning';
	public const LEVEL_DEPRECATED = 'deprecated';
	public const LEVEL_NOTICE     = 'notice';

	/** Niveaux du contrat, dans son ordre. */
	public const LEVELS = [ self::LEVEL_FATAL, self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_DEPRECATED, self::LEVEL_NOTICE ];

	public const TYPE_PLUGIN  = 'plugin';
	public const TYPE_THEME   = 'theme';
	public const TYPE_CORE    = 'core';
	public const TYPE_MU      = 'mu';
	public const TYPE_UNKNOWN = 'unknown';

	/** Types de composant du contrat, dans son ordre. */
	public const TYPES = [ self::TYPE_PLUGIN, self::TYPE_THEME, self::TYPE_CORE, self::TYPE_MU, self::TYPE_UNKNOWN ];

	public const SOURCE_FATAL = 'fatal_catcher';
	public const SOURCE_LOG   = 'log_tail';

	/** Sources du contrat. */
	public const SOURCES = [ self::SOURCE_FATAL, self::SOURCE_LOG ];

	public const FILE_MAX_CHARS    = 255;
	public const VERSION_MAX_CHARS = 50;

	/**
	 * Octets du message brut lus, la borne de Redactor (16 000, qui ne lit rien au-delà). Le
	 * message d'une exception non rattrapée n'a aucune borne : coupé avant les retraits de la
	 * pile d'appels et du suffixe, il n'est jamais recopié en entier, même dans une fonction
	 * d'arrêt (mémoire et temps constants, comme le filtre).
	 */
	public const MESSAGE_MAX_BYTES = 16000;

	/**
	 * Pile d'appels d'une exception non rattrapée, du saut de ligne qui précède « Stack trace: »
	 * jusqu'à la fin du message : elle porte des chemins et les arguments des appels quand PHP les
	 * affiche (zend.exception_ignore_args à Off). Retirée du bloc php_errors (occurrence()) et de
	 * la cause d'interruption d'une commande (CommandRuns\AfterResponseRunner) avant le filtre.
	 */
	public const STACK_TRACE = '/\R\s*+Stack trace:.*\z/s';

	/**
	 * Types d'erreur qui arrêtent le script : `fatal`. E_USER_ERROR en fait partie (trigger_error()
	 * l'arrête aussi, et CommandRuns\AfterResponseRunner le compte déjà comme fatal).
	 */
	public const FATAL_TYPES = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];

	/** Niveau d'un type d'erreur relevé à l'arrêt ; null pour une erreur sans gravité. */
	public static function level_of_error_type( int $type ): ?string {
		if ( in_array( $type, self::FATAL_TYPES, true ) ) {
			return self::LEVEL_FATAL;
		}

		return E_RECOVERABLE_ERROR === $type ? self::LEVEL_ERROR : null;
	}

	/**
	 * Chemin relatif à WordPress, borné à 255 caractères (la fin est gardée), sans « .. ».
	 *
	 * @param array<string, string>|null $roots Racines du site (null : Redactor::default_roots()).
	 */
	public static function relative_file( string $file, ?array $roots = null ): string {
		return self::bounded( self::relative_path( $file, $roots ) );
	}

	/**
	 * Composant d'un chemin relatif à WordPress.
	 *
	 * @return array{type: string, slug: string|null}
	 */
	public static function component( string $relative ): array {
		$prefixes = [
			'wp-content/plugins/'    => self::TYPE_PLUGIN,
			'wp-content/mu-plugins/' => self::TYPE_MU,
			'wp-content/themes/'     => self::TYPE_THEME,
		];
		foreach ( $prefixes as $prefix => $type ) {
			if ( ! str_starts_with( $relative, $prefix ) ) {
				continue;
			}
			$rest    = substr( $relative, strlen( $prefix ) );
			$segment = explode( '/', $rest, 2 )[0];
			if ( self::TYPE_THEME !== $type && ! str_contains( $rest, '/' ) ) {
				// Extension ou mu-plugin d'un seul fichier : « hello.php » donne « hello ».
				$segment = (string) preg_replace( '/\.php\z/i', '', $segment );
			}
			if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._ -]{0,99}\z/', $segment ) ) {
				break;
			}

			return [
				'type' => $type,
				'slug' => $segment,
			];
		}
		if ( str_starts_with( $relative, 'wp-includes/' ) || str_starts_with( $relative, 'wp-admin/' ) ) {
			return [
				'type' => self::TYPE_CORE,
				'slug' => null,
			];
		}

		return [
			'type' => self::TYPE_UNKNOWN,
			'slug' => null,
		];
	}

	/**
	 * Empreinte stable : SHA-256 du niveau, du composant, du fichier et du message normalisé
	 * (nombres, identifiants hexadécimaux et chaînes entre guillemets remplacés). La ligne n'y
	 * entre pas : une erreur qui se déplace dans le fichier reste la même erreur. Le message
	 * reçu est celui d'occurrence() : sans pile d'appels ni suffixe « in fichier:ligne », le
	 * même pour le relevé à l'arrêt et pour le journal.
	 */
	public static function fingerprint( string $level, string $type, ?string $slug, string $file, string $message ): string {
		return hash( 'sha256', implode( "\n", [ $level, $type, $slug ?? '', $file, self::normalized_message( $message ) ] ) );
	}

	/**
	 * Occurrence d'une erreur relevée par error_get_last() ; null si elle n'est pas fatale.
	 *
	 * @param array<mixed> $error Forme de error_get_last() : type, message, file, line.
	 * @return Occurrence|null
	 */
	public static function occurrence_from_error( array $error ): ?array {
		$type  = $error['type'] ?? null;
		$level = is_int( $type ) ? self::level_of_error_type( $type ) : null;
		if ( null === $level ) {
			return null;
		}
		$file    = $error['file'] ?? '';
		$line    = $error['line'] ?? 0;
		$message = $error['message'] ?? '';

		return self::occurrence( $level, is_string( $file ) ? $file : '', is_int( $line ) ? $line : 0, is_string( $message ) ? $message : '', self::SOURCE_FATAL );
	}

	/**
	 * Occurrence normalisée, filtrée, avec son empreinte.
	 *
	 * @return Occurrence
	 */
	public static function occurrence( string $level, string $file, int $line, string $message, string $source ): array {
		$relative  = self::relative_path( $file, null );
		$component = self::component( $relative );
		$bounded   = self::bounded( $relative );
		// Message coupé à la borne du filtre (MESSAGE_MAX_BYTES). Pile d'appels d'une exception non
		// rattrapée : retirée (chemins, arguments, et un groupe par appelant sinon). Puis suffixe
		// final « in <fichier>:<ligne> » ou « in <fichier> on line <n> » retiré :
		// error_get_last() le garde pour une exception non rattrapée, la ligne
		// du journal le perd au découpage (LogTail::LINE) ; sans ce retrait, une même erreur vue
		// par les deux sources ferait deux groupes. Le fichier et la ligne ont leurs champs.
		$message       = substr( $message, 0, self::MESSAGE_MAX_BYTES );
		$without_trace = (string) preg_replace( self::STACK_TRACE, '', $message );
		$without_where = (string) preg_replace( '/\s++in\s++\S+?(?::\d++|\s++on\s++line\s++\d++)\s*+\z/', '', $without_trace );
		$clean         = Redactor::redact( '' === trim( $without_where ) ? $without_trace : $without_where );

		return [
			'fp'      => self::fingerprint( $level, $component['type'], $component['slug'], $bounded, $clean ),
			'level'   => $level,
			'type'    => $component['type'],
			'slug'    => $component['slug'],
			'file'    => $bounded,
			'line'    => max( 0, $line ),
			'message' => $clean,
			'source'  => $source,
		];
	}

	/**
	 * Version du composant au moment du relevé ; null si inconnue ou illisible. Ne lève jamais.
	 */
	public static function component_version( string $type, ?string $slug ): ?string {
		try {
			$version = match ( $type ) {
				self::TYPE_CORE   => $GLOBALS['wp_version'] ?? null,
				self::TYPE_PLUGIN => null === $slug ? null : self::plugin_version( $slug ),
				self::TYPE_THEME  => null === $slug ? null : self::theme_version( $slug ),
				default           => null,
			};
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_string( $version ) ) {
			return null;
		}
		$version = trim( $version );

		return '' !== $version && mb_strlen( $version ) <= self::VERSION_MAX_CHARS ? $version : null;
	}

	/**
	 * Message normalisé pour l'empreinte.
	 */
	private static function normalized_message( string $message ): string {
		$normalized = preg_replace(
			[ '/"[^"]{0,200}+"/u', "/'[^']{0,200}+'/u", '/\b0x[0-9a-f]++\b/i', '/\b[0-9a-f]{8,}+\b/i', '/\d++/' ],
			[ '"S"', '"S"', 'H', 'H', 'N' ],
			$message
		);

		return null === $normalized ? $message : $normalized;
	}

	/**
	 * Chemin relatif à WordPress, non borné ; `[path]/<fichier>` hors de WordPress.
	 *
	 * @param array<string, string>|null $roots
	 */
	private static function relative_path( string $file, ?array $roots ): string {
		$path  = str_replace( '\\', '/', trim( $file ) );
		$roots = $roots ?? Redactor::default_roots();
		uksort( $roots, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$relative = null;
		foreach ( $roots as $root => $replacement ) {
			$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
			if ( strlen( $root ) > 1 && str_starts_with( $path, $root ) ) {
				$relative = $replacement . substr( $path, strlen( $root ) );
				break;
			}
		}
		if ( null === $relative && 1 === preg_match( '#/((?:wp-content|wp-admin|wp-includes)/.+)\z#', $path, $matches ) ) {
			$relative = $matches[1];
		}

		if ( null === $relative || '' === $relative || str_contains( $relative, '..' ) || str_starts_with( $relative, '/' ) || 1 === preg_match( '#\A[A-Za-z]:#', $relative ) ) {
			$base = str_replace( '..', '.', basename( $path ) );

			return Redactor::MASK_PATH . '/' . ( '' === $base ? 'unknown' : $base );
		}

		return $relative;
	}

	/** Borne de 255 caractères : la fin (le nom du fichier) est gardée. */
	private static function bounded( string $relative ): string {
		if ( mb_strlen( $relative ) <= self::FILE_MAX_CHARS ) {
			return $relative;
		}

		return '…' . mb_substr( $relative, - ( self::FILE_MAX_CHARS - 1 ) );
	}

	private static function plugin_version( string $slug ): mixed {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return null;
		}
		$found = get_plugins( '/' . $slug );
		if ( is_array( $found ) && [] !== $found ) {
			$first = reset( $found );
			if ( ! is_array( $first ) ) {
				return null;
			}

			return $first['Version'] ?? null;
		}
		// Extension d'un seul fichier (hello.php) : son en-tête.
		$single = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $slug . '.php' : '';
		if ( '' !== $single && is_file( $single ) && function_exists( 'get_file_data' ) ) {
			return get_file_data( $single, [ 'Version' => 'Version' ] )['Version'] ?? null;
		}

		return null;
	}

	private static function theme_version( string $slug ): mixed {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return null;
		}
		$theme = wp_get_theme( $slug );

		return $theme->exists() ? $theme->get( 'Version' ) : null;
	}
}
