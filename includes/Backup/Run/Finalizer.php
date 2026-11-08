<?php
/**
 * Finalisation d'une sauvegarde (lot 4-B, point 6) : index encodé (BackupIndex::encode()), autotest
 * relancé juste avant la signature, signature Ed25519 par la clé du site, puis vérification de cette
 * signature par le site lui-même avec sa clé publique. Un calcul Ed25519 faux du site
 * (`sodium_compat` sous JIT) se conclut ici par `self_test_failed`, jamais par une signature fausse
 * que la plateforme prendrait pour une altération.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Run;

use G2RD\Connector\Backup\Commands\BackupRefused;
use G2RD\Connector\Backup\Crypto\SelfTest;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Format\FormatError;

final class Finalizer {

	private Sodium $sodium;

	/** @var \Closure(): array{ok: bool, failed: list<string>, backend: string} */
	private \Closure $self_test;

	/** @var \Closure(string, string, Sodium): string */
	private \Closure $signer;

	/**
	 * @param (\Closure(): array{ok: bool, failed: list<string>, backend: string})|null $self_test Autotest (tests).
	 * @param (\Closure(string, string, Sodium): string)|null                           $signer    Signature (tests) ; BackupIndex::sign() par défaut.
	 */
	public function __construct( Sodium $sodium, ?\Closure $self_test = null, ?\Closure $signer = null ) {
		$this->sodium    = $sodium;
		$this->self_test = $self_test ?? fn (): array => SelfTest::run( $this->sodium );
		$this->signer    = $signer ?? static fn ( string $index, string $secret, Sodium $engine ): string => BackupIndex::sign( $index, $secret, $engine );
	}

	/**
	 * @param array<mixed> $fields Champs de l'index (docs/backup-format.md § 7.1).
	 * @return array{index: string, signature: string}
	 * @throws BackupRefused SELF_TEST_FAILED, CRYPTO_UNAVAILABLE.
	 */
	public function finalize( array $fields, string $secret_key, string $public_key ): array {
		$index = BackupIndex::encode( $fields );
		$check = ( $this->self_test )();
		if ( true !== $check['ok'] ) {
			throw new BackupRefused( BackupRefused::SELF_TEST_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		try {
			$signature = ( $this->signer )( $index, $secret_key, $this->sodium );
			$verified  = BackupIndex::verify( $index, $signature, $public_key, $this->sodium );
		} catch ( FormatError ) {
			throw new BackupRefused( BackupRefused::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}
		if ( ! $verified ) {
			throw new BackupRefused( BackupRefused::SELF_TEST_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par BackupRefused (phrase traduite, code constant).
		}

		return [
			'index'     => $index,
			'signature' => $signature,
		];
	}
}
