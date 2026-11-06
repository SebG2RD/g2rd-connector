<?php
/**
 * Contrôle du contrat du bloc `php_errors` v1 du snapshot (évolution stratégique, § 6), recopié
 * du test App\Tests\Contract\SnapshotPhpErrorsContractTest du manager (lot E0, tâche 8) : mêmes
 * règles, mêmes messages. Sert au test de la fixture partagée et à celui du bloc que produit le
 * connecteur (PhpErrors\SnapshotBlock). La rigueur (clés exactes, ordre compris) vaut pour ce
 * que le connecteur écrit. Seule différence de forme : un bloc que json_encode() refuse (texte
 * qui n'est pas en UTF-8 valide) fait lever une exception au manager, il donne ici un écart ;
 * dans les deux cas il est refusé, et la borne des 16 Ko ne peut plus être contournée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\Support\ArrayShape;

final class PhpErrorsContract {

	public const FIXTURE           = __DIR__ . '/../fixtures/snapshot-php-errors-v1.json';
	public const MAX_BYTES         = 16384;
	public const MAX_GROUPS        = 50;
	public const MESSAGE_MAX_CHARS = 500;
	public const FILE_MAX_CHARS    = 255;
	public const LEVELS            = [ 'fatal', 'error', 'warning', 'deprecated', 'notice' ];
	public const COMPONENT_TYPES   = [ 'plugin', 'theme', 'core', 'mu', 'unknown' ];
	public const TYPES_WITH_SLUG   = [ 'plugin', 'theme', 'mu' ];
	public const COVERAGES         = [ 'after_connector_load', 'none' ];
	public const SOURCES           = [ 'fatal_catcher', 'log_tail' ];
	public const BLOCK_KEYS        = [ 'version', 'enabled', 'coverage', 'sources', 'collected_at', 'truncated', 'groups' ];
	public const GROUP_KEYS        = [ 'fp', 'level', 'component', 'file', 'line', 'message', 'first_seen', 'last_seen', 'count' ];
	public const COMPONENT_KEYS    = [ 'type', 'slug', 'version' ];

	/**
	 * Écarts au contrat v1 ; liste vide = conforme.
	 *
	 * @param array<mixed> $block Bloc à contrôler.
	 * @return list<string>
	 */
	public static function violations( array $block ): array {
		$errors = [];
		try {
			$bytes = strlen( json_encode( $block, JSON_THROW_ON_ERROR ) );
			if ( $bytes > self::MAX_BYTES ) {
				$errors[] = sprintf( 'bloc de %d octets, au-delà de %d', $bytes, self::MAX_BYTES );
			}
		} catch ( \JsonException $e ) {
			// Le manager lève ici l'exception (JSON_THROW_ON_ERROR) : même règle, rendue en écart.
			$errors[] = 'bloc non encodable en JSON (texte qui n\'est pas en UTF-8 valide) : ' . $e->getMessage();
		}
		if ( array_keys( $block ) !== self::BLOCK_KEYS ) {
			$errors[] = 'clés du bloc : ' . implode( ', ', array_map( 'strval', array_keys( $block ) ) );
		}
		if ( 1 !== ( $block['version'] ?? null ) ) {
			$errors[] = 'version différente de 1';
		}
		if ( ! is_bool( $block['truncated'] ?? null ) ) {
			$errors[] = 'truncated n\'est pas un booléen';
		}
		$sources = $block['sources'] ?? null;
		if ( ! is_array( $sources ) || ! ArrayShape::is_list( $sources ) || count( array_unique( $sources, SORT_REGULAR ) ) !== count( $sources ) ) {
			$errors[] = 'sources n\'est pas une liste sans doublon';
			$sources  = [];
		}
		foreach ( $sources as $source ) {
			if ( ! in_array( $source, self::SOURCES, true ) ) {
				$errors[] = 'source inconnue';
			}
		}
		if ( ! in_array( $block['coverage'] ?? null, self::COVERAGES, true ) ) {
			$errors[] = 'coverage inconnue';
		}
		$groups = $block['groups'] ?? null;
		if ( ! is_array( $groups ) || ! ArrayShape::is_list( $groups ) ) {
			return array_merge( $errors, [ 'groups n\'est pas une liste' ] );
		}
		if ( count( $groups ) > self::MAX_GROUPS ) {
			$errors[] = sprintf( '%d groupes, au-delà de %d', count( $groups ), self::MAX_GROUPS );
		}

		$enabled = $block['enabled'] ?? null;
		if ( true === $enabled ) {
			if ( 'none' === ( $block['coverage'] ?? null ) || [] === $sources || ! self::is_date( $block['collected_at'] ?? null ) ) {
				$errors[] = 'collecte active sans coverage, sources ou collected_at';
			}
		} elseif ( false === $enabled ) {
			if ( 'none' !== ( $block['coverage'] ?? null ) || [] !== $sources || null !== ( $block['collected_at'] ?? null ) || [] !== $groups || false !== ( $block['truncated'] ?? null ) ) {
				$errors[] = 'collecte coupée : coverage none, sources vides, collected_at null, aucun groupe, truncated faux';
			}
		} else {
			$errors[] = 'enabled n\'est pas un booléen';
		}

		foreach ( $groups as $index => $group ) {
			$errors = array_merge( $errors, self::group_violations( (int) $index, $group ) );
		}

		return $errors;
	}

	/**
	 * @return list<string>
	 */
	private static function group_violations( int $index, mixed $group ): array {
		if ( ! is_array( $group ) ) {
			return [ sprintf( 'groupe %d : pas un objet', $index ) ];
		}
		$errors = [];
		if ( array_keys( $group ) !== self::GROUP_KEYS ) {
			$errors[] = sprintf( 'clés du groupe %d : %s', $index, implode( ', ', array_map( 'strval', array_keys( $group ) ) ) );
		}
		$fp = $group['fp'] ?? null;
		if ( ! is_string( $fp ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $fp ) ) {
			$errors[] = sprintf( 'fp du groupe %d : 64 caractères hexadécimaux en minuscules attendus', $index );
		}
		if ( ! in_array( $group['level'] ?? null, self::LEVELS, true ) ) {
			$errors[] = sprintf( 'level du groupe %d inconnu', $index );
		}

		$component = $group['component'] ?? null;
		$type      = is_array( $component ) ? ( $component['type'] ?? null ) : null;
		if ( ! is_array( $component ) || array_keys( $component ) !== self::COMPONENT_KEYS || ! in_array( $type, self::COMPONENT_TYPES, true ) ) {
			$errors[] = sprintf( 'type de composant du groupe %d inconnu, ou clés autres que type, slug, version', $index );
		} else {
			$slug          = $component['slug'] ?? null;
			$slug_expected = in_array( $type, self::TYPES_WITH_SLUG, true );
			if ( $slug_expected ? ! ( is_string( $slug ) && '' !== $slug && mb_strlen( $slug ) <= 100 ) : null !== $slug ) {
				$errors[] = sprintf( 'slug du groupe %d : texte pour plugin, theme et mu, null sinon', $index );
			}
			$version = $component['version'] ?? null;
			if ( ! ( null === $version || ( is_string( $version ) && '' !== $version && mb_strlen( $version ) <= 50 ) ) ) {
				$errors[] = sprintf( 'version du composant du groupe %d : texte court ou null', $index );
			}
		}

		$file = $group['file'] ?? null;
		if ( ! is_string( $file ) || '' === $file || mb_strlen( $file ) > self::FILE_MAX_CHARS || 1 === preg_match( '#^(/|[A-Za-z]:)|\\\\|\.\.#', $file ) ) {
			$errors[] = sprintf( 'file du groupe %d : chemin relatif à ABSPATH, sans « .. », %d caractères au plus', $index, self::FILE_MAX_CHARS );
		}
		$line = $group['line'] ?? null;
		if ( ! is_int( $line ) || $line < 0 ) {
			$errors[] = sprintf( 'line du groupe %d : entier positif ou nul', $index );
		}
		$message  = $group['message'] ?? null;
		$readable = is_string( $message ) && '' !== $message && mb_strlen( $message ) <= self::MESSAGE_MAX_CHARS;
		$leaks    = is_string( $message ) && ( str_contains( $message, '/home/' ) || 1 === preg_match( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $message ) );
		if ( ! $readable || $leaks ) {
			$errors[] = sprintf( 'message du groupe %d : texte filtré (ni e-mail ni chemin du serveur), %d caractères au plus', $index, self::MESSAGE_MAX_CHARS );
		}
		$first_seen = $group['first_seen'] ?? null;
		$last_seen  = $group['last_seen'] ?? null;
		if ( ! self::is_date( $first_seen ) || ! self::is_date( $last_seen ) ) {
			$errors[] = sprintf( 'first_seen ou last_seen du groupe %d : date ISO 8601 avec fuseau attendue', $index );
		} elseif ( new \DateTimeImmutable( (string) $first_seen ) > new \DateTimeImmutable( (string) $last_seen ) ) {
			$errors[] = sprintf( 'groupe %d : first_seen après last_seen', $index );
		}
		$count = $group['count'] ?? null;
		if ( ! is_int( $count ) || $count < 1 ) {
			$errors[] = sprintf( 'count du groupe %d : entier supérieur ou égal à 1', $index );
		}

		return $errors;
	}

	/** Date au format `DATE_ATOM` (« 2026-10-25T06:00:12+00:00 »), relue à l'identique. */
	public static function is_date( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( DATE_ATOM, $value );

		return false !== $date && $date->format( DATE_ATOM ) === $value;
	}

	/**
	 * @return array{limits: mixed, values: mixed, examples: array<string, array<mixed>>}
	 */
	public static function fixture(): array {
		$decoded = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['examples'] ?? null ) ) {
			throw new \RuntimeException( 'Fixture php_errors v1 illisible : ' . self::FIXTURE );
		}
		$examples = [];
		foreach ( $decoded['examples'] as $name => $block ) {
			if ( is_string( $name ) && is_array( $block ) ) {
				$examples[ $name ] = $block;
			}
		}

		return [
			'limits'   => $decoded['limits'] ?? null,
			'values'   => $decoded['values'] ?? null,
			'examples' => $examples,
		];
	}
}
