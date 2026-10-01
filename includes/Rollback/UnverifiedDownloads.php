<?php
/**
 * Trace locale des restaurations installées depuis wordpress.org SANS contrôle
 * d'empreinte (K3) : la plateforme n'a pas envoyé `source_sha256`, ce qui est le
 * cas de tous les managers antérieurs à cette version.
 *
 * Bornée par construction, sur le modèle du compteur d'échecs de signature :
 * un compteur et le dernier cas, jamais une liste qui grossit. Option dédiée, sans
 * autoload. Écrire la trace n'est jamais bloquant : une base indisponible ne doit
 * pas faire échouer une restauration qui, elle, a réussi.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class UnverifiedDownloads {

	public const OPTION_KEY = 'g2rd_connector_unverified_downloads';

	/**
	 * Compte une installation non vérifiée. Ne lève jamais d'exception.
	 */
	public static function record( string $file, string $url, string $sha256, int $now ): void {
		try {
			$stats = self::stats();
			update_option(
				self::OPTION_KEY,
				[
					'count'       => $stats['count'] + 1,
					'last_at'     => $now,
					'last_file'   => $file,
					'last_url'    => $url,
					'last_sha256' => $sha256,
				],
				false
			);
		} catch ( \Throwable $e ) {
			// Trace de diagnostic uniquement : son échec ne remonte pas.
			unset( $e );
		}
	}

	/**
	 * Lecture tolérante : option absente ou abîmée = aucune installation non vérifiée.
	 *
	 * @return array{count:int,last_at:int|null,last_file:string|null,last_url:string|null,last_sha256:string|null}
	 */
	public static function stats(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'count'       => isset( $stored['count'] ) ? max( 0, (int) $stored['count'] ) : 0,
			'last_at'     => isset( $stored['last_at'] ) ? (int) $stored['last_at'] : null,
			'last_file'   => isset( $stored['last_file'] ) ? (string) $stored['last_file'] : null,
			'last_url'    => isset( $stored['last_url'] ) ? (string) $stored['last_url'] : null,
			'last_sha256' => isset( $stored['last_sha256'] ) ? (string) $stored['last_sha256'] : null,
		];
	}
}
