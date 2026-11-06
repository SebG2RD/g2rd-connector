<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use Brain\Monkey\Functions;
use G2RD\Connector\PhpErrors\Buffer;
use G2RD\Connector\PhpErrors\LoggingProbe;
use G2RD\Connector\PhpErrors\SnapshotBlock;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Bloc `php_errors` v1 : conforme au contrat partagé dans tous les cas, collecte coupée sans
 * groupe, sources vraies, 16 Ko au plus, tampon illisible = bloc omis, et reproduction exacte
 * des exemples de la fixture du manager.
 */
final class SnapshotBlockTest extends TestCase {

	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wpdb'] );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $options = 0, int $depth = 512 ) => json_encode( $data, $options, $depth )
		);
		$this->root = str_replace( '\\', '/', (string) realpath( sys_get_temp_dir() ) ) . '/g2rd-bloc-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/wp-content', 0777, true );
		file_put_contents( $this->root . '/wp-content/debug.log', "ligne\n" );
	}

	protected function tearDown(): void {
		unlink( $this->root . '/wp-content/debug.log' );
		rmdir( $this->root . '/wp-content' );
		rmdir( $this->root );
		parent::tearDown();
	}

	public function test_collecte_coupee_bloc_conforme_sans_groupe(): void {
		$block = SnapshotBlock::build( 1793491200, $this->probe( false ) );

		self::assertSame( PhpErrorsContract::fixture()['examples']['collecte_desactivee'], $block );
	}

	public function test_collecte_coupee_les_groupes_gardes_ne_partent_pas(): void {
		$this->fill( 3, 1793491200 );

		$block = SnapshotBlock::build( 1793491200, $this->probe( false ) );

		self::assertIsArray( $block );
		self::assertSame( [], $block['groups'] );
		self::assertSame( [], PhpErrorsContract::violations( $block ) );
	}

	public function test_collecte_active_bloc_conforme(): void {
		$this->settings( true, false );
		$this->fill( 3, 1793491200 );

		$block = SnapshotBlock::build( 1793491300, $this->probe( true ) );

		self::assertIsArray( $block );
		self::assertSame( [], PhpErrorsContract::violations( $block ) );
		self::assertSame( [ 'fatal_catcher' ], $block['sources'] );
		self::assertSame( 'after_connector_load', $block['coverage'] );
		self::assertSame( gmdate( 'c', 1793491300 ), $block['collected_at'] );
		self::assertCount( 3, $block['groups'] );
	}

	public function test_journal_actif_et_lisible_ajoute_sa_source(): void {
		$this->settings( true, true );

		self::assertSame( [ 'fatal_catcher', 'log_tail' ], SnapshotBlock::build( 1793491200, $this->probe( true ) )['sources'] ?? null );
	}

	public function test_journal_actif_mais_illisible_n_est_pas_annonce(): void {
		$this->settings( true, true );

		self::assertSame( [ 'fatal_catcher' ], SnapshotBlock::build( 1793491200, $this->probe( false ) )['sources'] ?? null );
	}

	public function test_16_ko_au_plus_et_tronque(): void {
		$this->settings( true, false );
		// 10 groupes : le tampon les garde tous (loin de ses 32 Ko sérialisés) et ne se dit pas
		// coupé ; seule la borne des 16 Ko du bloc peut donc le marquer tronqué (« é » s'écrit
		// é en JSON, près de 3 Ko par groupe). Le cas « tampon déjà coupé, donc bloc
		// tronqué » est l'exemple journal_lu_tronque de test_reproduit_les_exemples_de_la_fixture.
		$this->fill( 10, 1793491200, str_repeat( 'é', 480 ) );
		self::assertCount( 10, Buffer::read( 1793491300 )['groups'] );
		self::assertFalse( Buffer::read( 1793491300 )['truncated'] );

		$block = SnapshotBlock::build( 1793491300, $this->probe( false ) );

		self::assertIsArray( $block );
		self::assertLessThanOrEqual( SnapshotBlock::MAX_BYTES, strlen( (string) json_encode( $block ) ) );
		self::assertLessThan( 10, count( $block['groups'] ) );
		self::assertTrue( $block['truncated'], 'Liste coupée à 16 Ko : jamais présentée comme complète.' );
		self::assertSame( [], PhpErrorsContract::violations( $block ) );
	}

	public function test_tampon_illisible_rend_null(): void {
		$this->settings( true, false );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				if ( Buffer::OPTION === $key ) {
					throw new \RuntimeException( 'Lost connection to MySQL server during query' );
				}
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $fallback;
			}
		);

		self::assertNull( SnapshotBlock::build( 1793491200, $this->probe( false ) ) );
	}

	public function test_reproduit_les_exemples_de_la_fixture(): void {
		$examples = PhpErrorsContract::fixture()['examples'];
		foreach ( [ 'collecte_complete' => false, 'journal_lu_tronque' => true ] as $name => $journal ) {
			$example = $examples[ $name ];
			$now     = (int) strtotime( (string) $example['collected_at'] );
			$this->settings( true, $journal );
			$groups = [];
			foreach ( $example['groups'] as $group ) {
				$groups[ $group['fp'] ] = [
					'level'      => $group['level'],
					'type'       => $group['component']['type'],
					'slug'       => $group['component']['slug'],
					'version'    => $group['component']['version'],
					'file'       => $group['file'],
					'line'       => $group['line'],
					'message'    => $group['message'],
					'first_seen' => (int) strtotime( $group['first_seen'] ),
					'last_seen'  => (int) strtotime( $group['last_seen'] ),
					'count'      => $group['count'],
					'source'     => 'fatal_catcher',
					'written_at' => (int) strtotime( $group['last_seen'] ),
				];
			}
			$this->options[ Buffer::OPTION ] = [
				'format'       => 1,
				'groups'       => $groups,
				'truncated_at' => $example['truncated'] ? $now : null,
			];

			$expected                 = $example;
			$expected['collected_at'] = gmdate( 'c', $now );
			foreach ( $expected['groups'] as $i => $group ) {
				$expected['groups'][ $i ]['first_seen'] = gmdate( 'c', (int) strtotime( $group['first_seen'] ) );
				$expected['groups'][ $i ]['last_seen']  = gmdate( 'c', (int) strtotime( $group['last_seen'] ) );
			}
			usort( $expected['groups'], static fn ( array $a, array $b ): int => strcmp( $b['last_seen'], $a['last_seen'] ) );

			self::assertSame( $expected, SnapshotBlock::build( $now, $this->probe( true ) ), $name );
		}
	}

	public function test_la_capacite_est_php_errors(): void {
		self::assertSame( 'php_errors', SnapshotBlock::CAPABILITY );
	}

	private function settings( bool $enabled, bool $log_tail ): void {
		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled'  => $enabled,
			'php_errors_log_tail' => $log_tail,
		];
	}

	private function probe( bool $readable ): LoggingProbe {
		$log = $this->root . '/wp-content/debug.log';

		return new LoggingProbe(
			static fn ( string $option ): string => 'error_log' === $option && $readable ? $log : '',
			static fn (): mixed => null,
			$this->root
		);
	}

	private function fill( int $count, int $now, string $message = 'Call to undefined function x()' ): void {
		$entries = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = [
				'fp'         => hash( 'sha256', 'g' . $i ),
				'level'      => 'fatal',
				'type'       => 'plugin',
				'slug'       => 'exemple',
				'version'    => '2.4.1',
				'file'       => 'wp-content/plugins/exemple/x.php',
				'line'       => 87,
				'message'    => $message,
				'source'     => 'fatal_catcher',
				'first_seen' => $now - 100 + $i,
				'last_seen'  => $now - 100 + $i,
				'count'      => 1,
			];
		}
		Buffer::record_many( $entries, $now, false );
	}
}
