<?php
/**
 * Bloc `php_errors` du snapshot (GET /g2rd/v1/snapshot), version 1 de la forme, partagée à
 * l'octet avec le manager (tests/fixtures/snapshot-php-errors-v1.json, lot E0 du manager).
 *
 * Rien n'est calculé ici : une lecture du tampon (Buffer::read()), puis une mise en forme bornée
 * à 16 Ko (mesurés sur wp_json_encode() sans option). Collecte coupée : forme « collecte
 * coupée », sans lire le tampon, et les groupes déjà gardés ne partent pas. Lecture du tampon
 * qui lève (filtre, drop-in de base) : null, et le snapshot omet le bloc ; le reste du snapshot
 * tient. Valeur abîmée ou d'un autre format : lue comme un tampon vide (cf. Buffer::load()),
 * donc `groups: []`.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

use G2RD\Connector\Settings;

/**
 * @phpstan-import-type Group from Buffer
 */
final class SnapshotBlock {

	public const VERSION    = 1;
	public const CAPABILITY = 'php_errors';
	public const MAX_BYTES  = 16384;

	public const COVERAGE_AFTER_LOAD = 'after_connector_load';
	public const COVERAGE_NONE       = 'none';

	/**
	 * @param int               $now   Horodatage Unix du snapshot.
	 * @param LoggingProbe|null $probe Sonde du journal (pour la source `log_tail`).
	 * @return array<string, mixed>|null Null : la lecture du tampon a levé, bloc à omettre.
	 */
	public static function build( int $now, ?LoggingProbe $probe = null ): ?array {
		try {
			if ( true !== Settings::get( 'php_errors_enabled' ) ) {
				return self::disabled();
			}
			$sources = [ Normalizer::SOURCE_FATAL ];
			if ( true === Settings::get( 'php_errors_log_tail' ) && null !== ( $probe ?? new LoggingProbe() )->readable_log_path() ) {
				$sources[] = Normalizer::SOURCE_LOG;
			}
			$read  = Buffer::read( $now );
			$block = [
				'version'      => self::VERSION,
				'enabled'      => true,
				'coverage'     => self::COVERAGE_AFTER_LOAD,
				'sources'      => $sources,
				'collected_at' => gmdate( 'c', $now ),
				'truncated'    => $read['truncated'],
				'groups'       => [],
			];
			$encoded = wp_json_encode( $block );
			if ( ! is_string( $encoded ) ) {
				return null;
			}
			$size = strlen( $encoded );
			foreach ( $read['groups'] as $fp => $group ) {
				$out  = self::group( (string) $fp, $group );
				$part = wp_json_encode( $out );
				if ( ! is_string( $part ) ) {
					$block['truncated'] = true;
					continue;
				}
				$added = strlen( $part ) + ( [] === $block['groups'] ? 0 : 1 );
				if ( $size + $added > self::MAX_BYTES ) {
					$block['truncated'] = true;
					break;
				}
				$block['groups'][] = $out;
				$size             += $added;
			}

			return $block;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Forme « collecte coupée » du contrat.
	 *
	 * @return array<string, mixed>
	 */
	public static function disabled(): array {
		return [
			'version'      => self::VERSION,
			'enabled'      => false,
			'coverage'     => self::COVERAGE_NONE,
			'sources'      => [],
			'collected_at' => null,
			'truncated'    => false,
			'groups'       => [],
		];
	}

	/**
	 * @param Group $group
	 * @return array<string, mixed>
	 */
	private static function group( string $fp, array $group ): array {
		return [
			'fp'         => $fp,
			'level'      => $group['level'],
			'component'  => [
				'type'    => $group['type'],
				'slug'    => $group['slug'],
				'version' => $group['version'],
			],
			'file'       => $group['file'],
			'line'       => $group['line'],
			'message'    => $group['message'],
			'first_seen' => gmdate( 'c', $group['first_seen'] ),
			'last_seen'  => gmdate( 'c', $group['last_seen'] ),
			'count'      => $group['count'],
		];
	}
}
