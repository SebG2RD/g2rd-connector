<?php
/**
 * Commande `set_php_errors_capture` (1.16) : active ou coupe, site par site, le relevé des
 * erreurs PHP et la lecture de la fin du journal (évolution stratégique, § 6 ; décision du
 * propriétaire : désactivé par défaut, activé par commande signée).
 *
 * Signature toujours exigée (CommandExecutor::SIGNED_ONLY), par la route comme par la file du
 * battement de cœur : sans elle, un tiers qui connaîtrait le jeton pourrait faire relever et
 * envoyer des erreurs. Instantanée : exécutée sur `/command` (AsyncCommands::EXCLUDED).
 *
 * Charge : `{"capture": bool, "log_tail": bool}`. Seul un booléen JSON vrai active ; toute autre
 * valeur, ou une clé absente, coupe (la valeur sûre). La lecture du journal n'est retenue
 * qu'avec le relevé.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

use G2RD\Connector\Settings;

final class CaptureCommand {

	public const NAME = 'set_php_errors_capture';

	/**
	 * @param LoggingProbe|null $probe Sonde du journal (tests) ; la vraie par défaut.
	 */
	public function __construct( private ?LoggingProbe $probe = null ) {}

	/**
	 * @param array<mixed> $payload Charge signée.
	 * @return array{php_errors_enabled: bool, php_errors_log_tail: bool, log_file: string}
	 */
	public function run( array $payload ): array {
		$capture  = true === ( $payload['capture'] ?? null );
		$log_tail = $capture && true === ( $payload['log_tail'] ?? null );

		Settings::update(
			[
				'php_errors_enabled'  => $capture,
				'php_errors_log_tail' => $log_tail,
			]
		);

		$where = ( $this->probe ?? new LoggingProbe() )->describe()['error_log'] ?? null;

		return [
			'php_errors_enabled'  => true === Settings::get( 'php_errors_enabled' ),
			'php_errors_log_tail' => true === Settings::get( 'php_errors_log_tail' ),
			// Où est le journal (jamais son chemin) : le manager sait si la lecture servira.
			'log_file'            => is_string( $where ) ? $where : LoggingProbe::LOG_UNSET,
		];
	}
}
