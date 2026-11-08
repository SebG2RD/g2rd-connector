<?php
/**
 * Codes d'erreur stables du format G2BK v1.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use G2RD\Connector\Backup\Format\FormatError;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * La liste est écrite dans le test, recopiée du contrat (section « Codes d'erreur stables » du
 * plan de la phase 2, docs/backup-format.md § 12 du dépôt de la plateforme) : la plateforme et le
 * service de clés rendent les mêmes codes pour les mêmes refus. Un code renommé, oublié ou ajouté
 * sans passer par le contrat fait échouer ce test.
 */
final class FormatErrorTest extends TestCase {

	private const CODES_DU_CONTRAT = [
		'bad_magic',
		'unsupported_version',
		'unsupported_suite',
		'unknown_kind',
		'reserved_flags',
		'bad_block_size',
		'bad_key_version',
		'bad_segment_index',
		'header_truncated',
		'bad_public_key',
		'seal_open_failed',
		'key_commitment_mismatch',
		'block_auth_failed',
		'truncated',
		'write_failed',
		'index_malformed',
		'index_too_large',
		'index_signature_invalid',
		'crypto_unavailable',
		'self_test_failed',
		'site_key_certificate_invalid',
	];

	public function test_chaque_code_du_contrat_existe(): void {
		self::assertSame( self::CODES_DU_CONTRAT, FormatError::CODES );

		foreach ( self::CODES_DU_CONTRAT as $code ) {
			$constante = FormatError::class . '::' . strtoupper( $code );
			self::assertTrue( defined( $constante ), 'Constante absente : ' . $constante );
			self::assertSame( $code, constant( $constante ) );
		}

		// Les constantes citées par le plan, écrites telles quelles.
		self::assertSame( 'bad_magic', FormatError::BAD_MAGIC );
		self::assertSame( 'bad_public_key', FormatError::BAD_PUBLIC_KEY );
		self::assertSame( 'bad_segment_index', FormatError::BAD_SEGMENT_INDEX );
		self::assertSame( 'crypto_unavailable', FormatError::CRYPTO_UNAVAILABLE );
		self::assertSame( 'self_test_failed', FormatError::SELF_TEST_FAILED );
		self::assertSame( 'site_key_certificate_invalid', FormatError::SITE_KEY_CERTIFICATE_INVALID );
	}

	public function test_code_inconnu_est_une_erreur_de_programmation(): void {
		foreach ( [ 'code_inconnu', '', 'BAD_MAGIC', 'bad_magic ', 'G2BK: bad_magic' ] as $code ) {
			try {
				new FormatError( $code );
				self::fail( 'Code accepté à tort : « ' . $code . ' »' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertNotInstanceOf( FormatError::class, $e );
				// Message constant : le code refusé n'y est pas recopié.
				if ( '' !== trim( $code ) ) {
					self::assertStringNotContainsString( trim( $code ), $e->getMessage() );
				}
			}
		}
	}

	public function test_le_message_ne_contient_que_le_code(): void {
		foreach ( self::CODES_DU_CONTRAT as $code ) {
			$erreur = new FormatError( $code );

			self::assertInstanceOf( \RuntimeException::class, $erreur );
			self::assertSame( $code, $erreur->reason() );
			self::assertSame( $code, $erreur->getMessage() );
			self::assertSame( 0, $erreur->getCode() );
			self::assertNull( $erreur->getPrevious() );
		}
	}
}
