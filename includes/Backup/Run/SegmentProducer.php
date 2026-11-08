<?php
/**
 * Production d'un segment entier : SegmentWriter (clé de données neuve, en-tête, blocs AES-256-GCM)
 * dans `seg-<n>.part` du dossier de la sauvegarde, rempli par la charge utile ($fill), puis
 * autotest SelfTest relancé ; seulement alors le fichier devient `seg-<n>.seg`. Tout échec jette le
 * fichier commencé. Rien de ce que garde le producteur ne rouvre la clé de données (§ 9 de la
 * spécification) : il ne reçoit que la clé publique du site.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\SelfTest;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Backup\Format\SegmentWriter;
use G2RD\Connector\Backup\Payload\PayloadWriter;

/**
 * @phpstan-type Produced array{segment: int, kind: string, bytes: int, blocks: int, sha256: string, header: string, file: string}
 */
final class SegmentProducer {

	private const KINDS = [
		SegmentHeader::KIND_DB       => 'db',
		SegmentHeader::KIND_FILES    => 'files',
		SegmentHeader::KIND_MANIFEST => 'manifest',
	];

	private Hpke $hpke;

	private Sodium $sodium;

	private string $dir;

	private string $backup_id;

	private int $key_version;

	private string $site_public_key;

	private int $block_size;

	/** @var \Closure(): array{ok: bool, failed: list<string>, backend: string} */
	private \Closure $self_test;

	/**
	 * @param string                                                                   $backup_id Identifiant de la sauvegarde, 32 hex.
	 * @param (\Closure(): array{ok: bool, failed: list<string>, backend: string})|null $self_test Autotest ; SelfTest::run() par défaut.
	 */
	public function __construct( Hpke $hpke, Sodium $sodium, string $dir, string $backup_id, int $key_version, string $site_public_key, int $block_size, ?\Closure $self_test = null ) {
		$this->hpke            = $hpke;
		$this->sodium          = $sodium;
		$this->dir             = rtrim( $dir, '/' );
		$this->backup_id       = $backup_id;
		$this->key_version     = $key_version;
		$this->site_public_key = $site_public_key;
		$this->block_size      = $block_size;
		$this->self_test       = $self_test ?? fn (): array => SelfTest::run( $this->sodium );
	}

	/**
	 * @param \Closure(PayloadWriter): mixed $fill Remplit la charge utile (sans la fin, ajoutée ici).
	 * @return Produced
	 * @throws BackupRefused Remplissage refusé, cryptographie absente, clé inutilisable, écriture
	 *                       impossible, autotest en échec.
	 */
	public function produce( int $segment, int $kind, \Closure $fill ): array {
		$part  = $this->dir . '/seg-' . $segment . '.part';
		$final = $this->dir . '/seg-' . $segment . '.seg';
		$out   = @fopen( $part, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- segment chiffré écrit en flux dans le dossier de travail protégé.
		if ( false === $out ) {
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		try {
			$writer = new SegmentWriter( $this->hpke );
			$writer->begin( $out, SegmentHeader::SUITE_GCM, (string) hex2bin( $this->backup_id ), $segment, $kind, $this->key_version, $this->block_size, $this->site_public_key );
			$payload = new PayloadWriter(
				static function ( string $bytes ) use ( $writer ): void {
					$writer->write( $bytes );
				}
			);
			$fill( $payload );
			$payload->end();
			$entry = $writer->finish();
		} catch ( FormatError $e ) {
			self::discard( $out, $part );
			throw new BackupRefused( self::refusal_for( $e->reason() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable choisi parmi des constantes.
		} catch ( \Throwable $e ) {
			self::discard( $out, $part );
			throw $e;
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- fermeture du segment écrit.

		$check = ( $this->self_test )();
		if ( true !== $check['ok'] ) {
			wp_delete_file( $part );
			throw new BackupRefused( BackupRefused::SELF_TEST_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		$header = (string) file_get_contents( $part, false, null, 0, SegmentHeader::BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- en-tête du segment écrit (148 octets), pour seal-checks.
		if ( SegmentHeader::BYTES !== strlen( $header ) || ! @rename( $part, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- segment terminé renommé dans son dossier.
			wp_delete_file( $part );
			throw new BackupRefused( BackupRefused::WORK_DIR_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return [
			'segment' => $segment,
			'kind'    => self::KINDS[ $kind ] ?? 'files',
			'bytes'   => $entry['bytes'],
			'blocks'  => $entry['blocks'],
			'sha256'  => bin2hex( $entry['sha256'] ),
			'header'  => bin2hex( $header ),
			'file'    => $final,
		];
	}

	/**
	 * @param resource $out
	 */
	private static function discard( $out, string $part ): void {
		if ( is_resource( $out ) ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- segment interrompu, jeté.
		}
		wp_delete_file( $part );
	}

	private static function refusal_for( string $reason ): string {
		return match ( $reason ) {
			FormatError::BAD_PUBLIC_KEY     => BackupRefused::BAD_SITE_KEY,
			FormatError::CRYPTO_UNAVAILABLE => BackupRefused::CRYPTO_UNAVAILABLE,
			default                         => BackupRefused::WORK_DIR_UNAVAILABLE,
		};
	}
}
