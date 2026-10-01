<?php
/**
 * Signature des entrées de la FILE des commandes (tirée par le cron).
 *
 * La route REST `/g2rd/v1/command` est protégée par les en-têtes de signature
 * (cf. RequestSignature et Rest\Auth). La file, elle, est TIRÉE par le site
 * (GET /api/agent/sites/{id}/commands) : il n'y a pas de requête entrante à
 * signer, donc la preuve voyage DANS l'entrée, sous une clé `signed` :
 *
 *   {
 *     "id": 42,
 *     "signed": {
 *       "body":      "{\"command\":\"rollback_plugin\",\"payload\":{…}}",
 *       "timestamp": "1789000000",
 *       "nonce":     "<32 hex>",
 *       "signature": "v1=<64 hex>"
 *     }
 *   }
 *
 * Même clé (dérivée du SiteToken), même chaîne canonique v1, même fenêtre de
 * ±300 s que la route REST — rien de nouveau à stocker. Deux choix séparent les
 * domaines pour qu'une preuve ne puisse pas être rejouée d'un canal à l'autre :
 *   - la méthode signée est `PULL`, qui n'est pas une méthode HTTP ;
 *   - la route signée est `/api/agent/sites/{site}/commands/{commande}`, ce qui
 *     lie la preuve au site ET à l'identifiant de la commande dans la file.
 *
 * On signe le corps BRUT (chaîne JSON) et on n'exécute que ce corps : aucune
 * re-sérialisation, donc aucun écart de canonisation JSON entre Symfony et PHP.
 *
 * Classe PURE (aucun appel WordPress), testée contre des vecteurs calculés par
 * une implémentation indépendante (tests/fixtures/queue-signature-vectors.json).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

use G2RD\Connector\Commands\CommandExecutor;

final class QueueSignature {

	/** Pseudo-méthode signée : jamais une méthode HTTP, donc jamais confondue avec la route REST. */
	public const METHOD = 'PULL';

	/** Aucune enveloppe alors que la commande en exige une. */
	public const CODE_MISSING = 'signature_missing';

	/** La vérification elle-même a échoué de façon inattendue. */
	public const CODE_ERROR = 'signature_error';

	/** Champs obligatoires de l'enveloppe `signed`. */
	private const FIELDS = [ 'body', 'timestamp', 'nonce', 'signature' ];

	/**
	 * Route signée pour une entrée de la file.
	 */
	public static function route( int $site_id, int $command_id ): string {
		return sprintf( '/api/agent/sites/%d/commands/%d', $site_id, $command_id );
	}

	/**
	 * Vérifie l'enveloppe `signed` d'une entrée de la file.
	 *
	 * Ne consulte PAS le registre des nonces (comme RequestSignature::verify) :
	 * l'appelant le fait seulement quand le statut est `ok`.
	 *
	 * @param array<mixed> $entry Entrée brute de la file (données externes non fiables).
	 * @return array{status:string,code?:string,server_time?:int,nonce?:string,command?:string,payload?:array<string,mixed>|null}
	 */
	public static function verify_entry( string $token, int $site_id, array $entry, int $now ): array {
		if ( ! array_key_exists( 'signed', $entry ) ) {
			return [ 'status' => RequestSignature::STATUS_ABSENT ];
		}

		// Jeton inutilisable (illisible, ou refusé par le mode strict) : la clé dérivée
		// de '' est publique. On refuse sans rien calculer, pour que cette classe soit
		// sûre par elle-même, quel que soit l'appelant.
		if ( '' === $token ) {
			return RequestSignature::failed( RequestSignature::CODE_TOKEN_UNAVAILABLE );
		}

		$signed = $entry['signed'];
		if ( ! is_array( $signed ) ) {
			return RequestSignature::failed( RequestSignature::CODE_INVALID );
		}
		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $signed[ $field ] ) || ! is_string( $signed[ $field ] ) || '' === $signed[ $field ] ) {
				return RequestSignature::failed( RequestSignature::CODE_INVALID );
			}
		}

		$command_id = self::command_id( $entry );
		if ( null === $command_id ) {
			return RequestSignature::failed( RequestSignature::CODE_INVALID );
		}

		$check = RequestSignature::verify(
			$token,
			self::METHOD,
			self::route( $site_id, $command_id ),
			$signed['body'],
			$signed['timestamp'],
			$signed['nonce'],
			$signed['signature'],
			$now
		);
		if ( RequestSignature::STATUS_OK !== $check['status'] ) {
			return $check;
		}

		// Signature valide : le corps doit encore être entièrement compris. On
		// n'exécute jamais un corps dont une partie serait ignorée.
		$body = json_decode( $signed['body'], true );
		if (
			! is_array( $body )
			|| ! isset( $body['command'] )
			|| ! is_string( $body['command'] )
			|| ! in_array( $body['command'], CommandExecutor::ALLOWED, true )
		) {
			return RequestSignature::failed( RequestSignature::CODE_INVALID );
		}

		$payload = $body['payload'] ?? null;
		if ( null !== $payload && ! is_array( $payload ) ) {
			return RequestSignature::failed( RequestSignature::CODE_INVALID );
		}

		// Un `kind` en clair qui contredit le corps signé : l'entrée a été altérée.
		if ( array_key_exists( 'kind', $entry ) && $entry['kind'] !== $body['command'] ) {
			return RequestSignature::failed( RequestSignature::CODE_INVALID );
		}

		/** @var array<string, mixed>|null $payload */
		return [
			'status'  => RequestSignature::STATUS_OK,
			'nonce'   => $signed['nonce'],
			'command' => $body['command'],
			'payload' => $payload,
		];
	}

	/**
	 * Commande ANNONCÉE par le corps signé, lue SANS vérification.
	 *
	 * Ne sert qu'à décider si une entrée relève des commandes à signature
	 * obligatoire, pour retenir le cas le plus strict : jamais à décider quoi
	 * exécuter.
	 *
	 * @param array<mixed> $entry
	 */
	public static function claimed_command( array $entry ): string {
		$signed = $entry['signed'] ?? null;
		if ( ! is_array( $signed ) || ! isset( $signed['body'] ) || ! is_string( $signed['body'] ) ) {
			return '';
		}
		$body = json_decode( $signed['body'], true );
		return is_array( $body ) && isset( $body['command'] ) && is_string( $body['command'] ) ? $body['command'] : '';
	}

	/**
	 * Identifiant de la commande, ou null s'il n'est pas un entier positif.
	 *
	 * @param array<mixed> $entry
	 */
	private static function command_id( array $entry ): ?int {
		$id = $entry['id'] ?? null;
		if ( is_int( $id ) ) {
			return $id > 0 ? $id : null;
		}
		if ( is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,18}$/', $id ) ) {
			return (int) $id;
		}
		return null;
	}
}
