<?php
/**
 * Job WP-Cron : heartbeat horaire vers le manager.
 *
 * Le hook 'g2rd_connector_heartbeat' est planifié à l'activation du plugin
 * et déclenché toutes les heures par WP-Cron.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Cron;

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Outbound\ManagerClient;
use G2RD\Connector\Security\QueueSignature;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Settings;

final class HeartbeatJob {

	public const HOOK = 'g2rd_connector_heartbeat';

	/**
	 * Horloge (secondes Unix). Injectable pour les tests ; `time()` sinon.
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * @param (\Closure(): int)|null $clock
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		if ( ! Settings::is_enrolled() ) {
			return;
		}

		$client = new ManagerClient();

		if ( Settings::get( 'heartbeat_enabled' ) ) {
			$client->heartbeat();
		}

		// Drain de la queue de commandes distantes (opt-in via remote_commands_enabled).
		// Le manager fait le claim atomique côté serveur (PENDING → RUNNING) ; ici on
		// se contente d'exécuter et de notifier le résultat.
		if ( Settings::get( 'remote_commands_enabled' ) ) {
			$commands = $client->poll_commands();
			if ( ! is_wp_error( $commands ) && is_array( $commands ) ) {
				// Deux phases : TOUTES les entrées sont jugées au même instant, AVANT
				// d'en exécuter aucune. Sinon une série de mises à jour (une minute et
				// plus chacune) ferait sortir les entrées suivantes de la fenêtre de
				// signature et produirait de faux écarts d'horloge.
				$plans = $this->triage( $commands, ( $this->clock )() );
				foreach ( $plans as $plan ) {
					$this->execute( $client, $plan );
				}
			}
		}
	}

	/**
	 * Décide du sort de chaque entrée de la file.
	 *
	 * - Commandes de CommandExecutor::SIGNED_ONLY (rollback, suppression de points,
	 *   politique de signature) : exécutées SEULEMENT avec une enveloppe signée
	 *   valide et jamais vue, QUELLE QUE SOIT la politique — exactement comme par
	 *   la route REST (cf. Rest\Auth). Le corps signé est alors la seule source :
	 *   le `payload` en clair n'est jamais lu.
	 * - Commandes historiques : chemin inchangé (`kind` et `payload` en clair, ce
	 *   que publie le manager aujourd'hui). Une enveloppe éventuelle n'est vérifiée
	 *   que pour rapport : un échec est compté, la commande s'exécute quand même.
	 *
	 * @param array<mixed> $commands Entrées brutes de la file (données externes non fiables).
	 * @return list<array{id:int, kind:string, payload:array<string,mixed>|null, refusal:array<string,mixed>|null}>
	 */
	private function triage( array $commands, int $now ): array {
		$token   = Settings::site_token();
		$site_id = (int) Settings::get( 'site_id' );
		$plans   = [];

		foreach ( $commands as $cmd ) {
			if ( ! is_array( $cmd ) || ! isset( $cmd['id'] ) ) {
				continue;
			}
			$has_kind   = isset( $cmd['kind'] ) && is_scalar( $cmd['kind'] );
			$has_signed = array_key_exists( 'signed', $cmd );
			// Comme avant : une entrée sans `kind` (et sans enveloppe) est ignorée.
			if ( ! $has_kind && ! $has_signed ) {
				continue;
			}

			$kind    = $has_kind ? (string) $cmd['kind'] : '';
			$claimed = QueueSignature::claimed_command( $cmd );
			$strict  = in_array( $kind, CommandExecutor::SIGNED_ONLY, true )
				|| in_array( $claimed, CommandExecutor::SIGNED_ONLY, true );
			$check   = $has_signed ? $this->verify( $token, $site_id, $cmd, $now ) : [ 'status' => RequestSignature::STATUS_ABSENT ];
			$plan    = [
				'id'      => (int) $cmd['id'],
				'kind'    => $kind,
				'payload' => isset( $cmd['payload'] ) && is_array( $cmd['payload'] ) ? $cmd['payload'] : null,
				'refusal' => null,
			];

			if ( RequestSignature::STATUS_OK === $check['status'] ) {
				// Corps signé : seule source de vérité, pour toutes les commandes.
				$plan['kind']    = (string) ( $check['command'] ?? '' );
				$plan['payload'] = $check['payload'] ?? null;
				$plans[]         = $plan;
				continue;
			}

			$code = RequestSignature::STATUS_ABSENT === $check['status']
				? QueueSignature::CODE_MISSING
				: (string) ( $check['code'] ?? RequestSignature::CODE_INVALID );

			if ( $strict || ! $has_kind ) {
				// Refus : signature exigée (SIGNED_ONLY), ou entrée sans `kind` dont
				// le seul contenu exploitable est un corps qu'on n'a pas pu vérifier.
				$this->record_failure( $code, $now );
				$plan['kind']    = '' !== $kind ? $kind : $claimed;
				$plan['refusal'] = [
					'code'            => $code,
					'signature_check' => self::public_check( $check, $code ),
				];
				$plans[]         = $plan;
				continue;
			}

			// Commande historique : rapport seul. Une absence de signature n'est PAS
			// comptée (le manager ne signe pas la file aujourd'hui) ; une enveloppe
			// présente mais ratée l'est.
			if ( RequestSignature::STATUS_FAILED === $check['status'] ) {
				$this->record_failure( $code, $now );
			}
			$plans[] = $plan;
		}

		return $plans;
	}

	/**
	 * Vérifie l'enveloppe et consulte le registre des nonces. Ne lève jamais.
	 *
	 * @param array<mixed> $cmd
	 * @return array{status:string,code?:string,server_time?:int,nonce?:string,command?:string,payload?:array<string,mixed>|null}
	 */
	private function verify( string $token, int $site_id, array $cmd, int $now ): array {
		try {
			$check = QueueSignature::verify_entry( $token, $site_id, $cmd, $now );
			if ( RequestSignature::STATUS_OK !== $check['status'] ) {
				return $check;
			}

			// Registre partagé avec la route REST. Plein, il refuse d'enregistrer
			// plutôt que d'évincer un nonce encore rejouable (K2).
			$outcome = SignatureState::register_nonce( (string) ( $check['nonce'] ?? '' ), $now );
			if ( SignatureState::NONCE_REPLAYED === $outcome ) {
				return RequestSignature::failed( RequestSignature::CODE_REPLAYED );
			}
			if ( SignatureState::NONCE_STORE_FULL === $outcome ) {
				return RequestSignature::failed( RequestSignature::CODE_NONCE_STORE_FULL );
			}
			return $check;
		} catch ( \Throwable ) {
			return RequestSignature::failed( QueueSignature::CODE_ERROR );
		}
	}

	/**
	 * Compte un échec sans jamais faire échouer le cron pour autant.
	 */
	private function record_failure( string $code, int $now ): void {
		try {
			SignatureState::record_failure( $code, $now );
		} catch ( \Throwable ) {
			// Le diagnostic ne doit pas empêcher de rendre compte au manager.
			return;
		}
	}

	/**
	 * @param array{id:int, kind:string, payload:array<string,mixed>|null, refusal:array<string,mixed>|null} $plan
	 */
	private function execute( ManagerClient $client, array $plan ): void {
		if ( null !== $plan['refusal'] ) {
			$client->send_command_result(
				$plan['id'],
				'failed',
				[
					'code'            => 'g2rd_connector_' . (string) $plan['refusal']['code'],
					'signature_check' => $plan['refusal']['signature_check'],
				],
				self::refusal_message( $plan['kind'], (array) $plan['refusal']['signature_check'] )
			);
			return;
		}

		// Le manager envoie un payload JSON pour les commandes qui en ont besoin
		// (update_plugin, update_theme). Les autres commandes l'ignorent
		// silencieusement (rétro-compat).
		$outcome = CommandExecutor::run( $plan['kind'], $plan['payload'] );
		if ( null === $outcome ) {
			$client->send_command_result(
				$plan['id'],
				'failed',
				null,
				sprintf( 'Commande inconnue : %s', $plan['kind'] )
			);
			return;
		}
		$client->send_command_result(
			$plan['id'],
			(string) $outcome['status'],
			$outcome['result'] ?? null,
			$outcome['error'] ?? null
		);
	}

	/**
	 * Ce qui est remonté au manager du contrôle de signature : statut, code et
	 * heure du site (écart d'horloge) — jamais le nonce ni le corps.
	 *
	 * @param array<string, mixed> $check
	 * @return array{status:string, code:string, server_time?:int}
	 */
	private static function public_check( array $check, string $code ): array {
		$public = [
			'status' => (string) ( $check['status'] ?? RequestSignature::STATUS_FAILED ),
			'code'   => $code,
		];
		if ( isset( $check['server_time'] ) ) {
			$public['server_time'] = (int) $check['server_time'];
		}
		return $public;
	}

	/**
	 * Message lisible par l'administrateur (affiché par la plateforme) : quoi,
	 * cause probable, action.
	 *
	 * @param array<string, mixed> $check
	 */
	private static function refusal_message( string $kind, array $check ): string {
		$kind = '' !== $kind ? $kind : '?';
		$code = (string) ( $check['code'] ?? '' );

		if ( QueueSignature::CODE_MISSING === $code ) {
			return sprintf(
				/* translators: %s: nom technique de la commande refusée, par exemple rollback_plugin. */
				__( 'La commande « %s » n’a pas été exécutée : elle exige une signature de la plateforme et la file des commandes l’a transmise sans signature. Cause probable : plateforme antérieure aux commandes signées par la file, ou réponse altérée en chemin. Action : relancer l’opération depuis la plateforme ; si le refus se répète, vérifier l’adresse du manager dans les réglages du connecteur.', 'g2rd-connector' ),
				$kind
			);
		}

		if ( RequestSignature::CODE_SKEW === $code ) {
			$site_time = isset( $check['server_time'] ) ? gmdate( 'Y-m-d H:i:s', (int) $check['server_time'] ) . ' UTC' : '?';
			return sprintf(
				/* translators: 1: nom technique de la commande refusée, 2: heure du serveur du site (UTC). */
				__( 'La commande « %1$s » n’a pas été exécutée : sa signature est datée de plus de 5 minutes d’écart avec l’horloge du site (heure du site : %2$s). Cause probable : horloge du serveur du site (ou de la plateforme) déréglée. Action : faire corriger l’heure du serveur par l’hébergeur, puis relancer l’opération.', 'g2rd-connector' ),
				$kind,
				$site_time
			);
		}

		if ( RequestSignature::CODE_REPLAYED === $code ) {
			return sprintf(
				/* translators: %s: nom technique de la commande refusée, par exemple rollback_plugin. */
				__( 'La commande « %s » n’a pas été exécutée : cette commande signée a déjà été reçue une première fois, rien n’a été fait. Cause probable : réponse de la plateforme rejouée. Action : si l’opération est toujours souhaitée, la relancer depuis la plateforme.', 'g2rd-connector' ),
				$kind
			);
		}

		if ( RequestSignature::CODE_NONCE_STORE_FULL === $code ) {
			return sprintf(
				/* translators: %s: nom technique de la commande refusée, par exemple rollback_plugin. */
				__( 'La commande « %s » n’a pas été exécutée : le site a reçu trop de commandes signées en quelques minutes et son registre anti-rejeu est plein ; la commande a été refusée par précaution. Action : la relancer depuis la plateforme dans quelques minutes ; si cela se répète, contacter le support G2RD.', 'g2rd-connector' ),
				$kind
			);
		}

		if ( QueueSignature::CODE_ERROR === $code ) {
			return sprintf(
				/* translators: %s: nom technique de la commande refusée, par exemple rollback_plugin. */
				__( 'La commande « %s » n’a pas été exécutée : le site n’a pas pu vérifier sa signature (erreur inattendue pendant la vérification). Action : réessayer depuis la plateforme ; si le refus se répète, consulter le journal d’erreurs PHP du site.', 'g2rd-connector' ),
				$kind
			);
		}

		return sprintf(
			/* translators: %s: nom technique de la commande refusée, par exemple rollback_plugin. */
			__( 'La commande « %s » n’a pas été exécutée : sa signature est invalide. Cause probable : jeton du connecteur désynchronisé avec la plateforme, ou commande altérée en chemin. Action : ré-enrôler le site depuis la plateforme, puis relancer l’opération.', 'g2rd-connector' ),
			$kind
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
