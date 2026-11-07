<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rest;

use Brain\Monkey\Functions;
use G2RD\Connector\PhpErrors\Buffer;

/**
 * Blocs `php_errors` et `php_logging` dans la réponse de GET /snapshot (1.16) : après
 * `environment`, collecte coupée sans lecture du tampon, tampon illisible sans faire échouer le
 * snapshot.
 */
final class SnapshotPhpErrorsTest extends SnapshotTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->update_transients_checked( 3600 );
	}

	public function test_les_deux_blocs_suivent_environment(): void {
		$keys = array_keys( $this->snapshot() );

		self::assertSame( [ 'environment', 'php_errors', 'php_logging' ], array_slice( $keys, -3 ) );
	}

	public function test_collecte_coupee_le_tampon_n_est_pas_lu(): void {
		$lus = [];
		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) use ( &$lus ) {
				$lus[] = $key;
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
			}
		);

		$snapshot = $this->snapshot();

		self::assertFalse( $snapshot['php_errors']['enabled'] );
		self::assertNotContains( Buffer::OPTION, $lus );
	}

	public function test_tampon_illisible_le_snapshot_tient_sans_le_bloc(): void {
		$this->options['g2rd_connector_settings'] = array_replace( (array) ( $this->options['g2rd_connector_settings'] ?? [] ), [ 'php_errors_enabled' => true ] );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				if ( Buffer::OPTION === $key ) {
					throw new \RuntimeException( 'Lost connection to MySQL server during query' );
				}
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
			}
		);

		$snapshot = $this->snapshot();

		self::assertArrayNotHasKey( 'php_errors', $snapshot, 'Inconnu : bloc omis, jamais « aucune erreur ».' );
		self::assertArrayHasKey( 'environment', $snapshot );
		self::assertArrayHasKey( 'php_logging', $snapshot );
	}
}
