<?php
/**
 * Helper d'authentification Bearer SiteToken pour les endpoints REST.
 *
 * Le manager présente le token long-lived (généré côté Symfony) dans
 * l'en-tête Authorization. On compare en hash_equals contre l'option stockée.
 *
 * En plus du Bearer, le manager SIGNE ses requêtes (cf. Security\RequestSignature).
 * La politique par défaut est `report` : la signature est vérifiée, un échec est
 * compté et remonté au manager, mais la requête est ACCEPTÉE — aucune commande
 * existante ne peut être refusée à cause de la signature tant que la politique
 * `required` n'a pas été activée explicitement. Seules les commandes listées dans
 * CommandExecutor::SIGNED_ONLY exigent une signature valide quelle que soit la
 * politique.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Settings;
use WP_Error;
use WP_REST_Request;

final class Auth {

	/**
	 * Résultat de la dernière vérification de signature de la requête courante,
	 * relu par les contrôleurs pour le remonter au manager.
	 *
	 * @var array{status:string,code?:string,server_time?:int}|null
	 */
	private static ?array $last_signature_check = null;

	/**
	 * Résultat déjà calculé, par objet requête.
	 *
	 * WordPress appelle le permission_callback DEUX fois pour une même requête :
	 * au dispatch, puis dans rest_send_allow_header() pour bâtir l'en-tête Allow.
	 * Sans cette mémoire, le second passage retrouve le nonce que le premier vient
	 * d'enregistrer et compte un faux rejeu — mesuré sur le banc de test : 9 échecs
	 * comptés pour 3 réels. Un WeakMap ne retient pas la requête en mémoire et ne
	 * peut pas confondre deux requêtes distinctes.
	 *
	 * @var \WeakMap<WP_REST_Request, array{status:string,code?:string,server_time?:int}>|null
	 */
	private static ?\WeakMap $checked = null;

	/**
	 * Permission callback à brancher sur tous les endpoints sécurisés.
	 *
	 * @return true|WP_Error
	 */
	public static function require_site_token( WP_REST_Request $request ): bool|WP_Error {
		self::$last_signature_check = null;

		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header || stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error(
				'g2rd_connector_missing_token',
				__( 'Authorization Bearer token requis.', 'g2rd-connector' ),
				[ 'status' => 401 ]
			);
		}

		$token = trim( substr( $header, 7 ) );
		if ( ! Settings::token_matches( $token ) ) {
			return new WP_Error(
				'g2rd_connector_invalid_token',
				__( 'Token site invalide.', 'g2rd-connector' ),
				[ 'status' => 403 ]
			);
		}

		self::$checked ??= new \WeakMap();
		if ( ! isset( self::$checked[ $request ] ) ) {
			self::$checked[ $request ] = self::check_signature( $request, $token );
		}
		$check                      = self::$checked[ $request ];
		self::$last_signature_check = $check;

		if ( RequestSignature::STATUS_OK === $check['status'] || ! self::signature_is_mandatory( $request ) ) {
			return true;
		}

		$code = $check['code'] ?? 'signature_missing';
		$data = [ 'status' => 401 ];
		if ( isset( $check['server_time'] ) ) {
			$data['server_time'] = $check['server_time'];
		}

		return new WP_Error(
			'g2rd_connector_' . $code,
			__( 'Signature de la requête absente ou invalide.', 'g2rd-connector' ),
			$data
		);
	}

	/**
	 * Résultat de la vérification de signature de la requête courante, ou null si
	 * aucune requête authentifiée n'a encore été traitée.
	 *
	 * @return array{status:string,code?:string,server_time?:int}|null
	 */
	public static function last_signature_check(): ?array {
		return self::$last_signature_check;
	}

	/**
	 * Vérifie la signature, consulte le registre des nonces, et compte les échecs.
	 *
	 * Ne lève JAMAIS : une erreur inattendue pendant la vérification ne doit pas
	 * pouvoir faire échouer une requête en politique `report`.
	 *
	 * @return array{status:string,code?:string,server_time?:int}
	 */
	private static function check_signature( WP_REST_Request $request, string $token ): array {
		$now = time();

		try {
			$nonce = (string) $request->get_header( RequestSignature::HEADER_NONCE );
			$check = RequestSignature::verify(
				$token,
				(string) $request->get_method(),
				(string) $request->get_route(),
				(string) $request->get_body(),
				(string) $request->get_header( RequestSignature::HEADER_TIMESTAMP ),
				$nonce,
				(string) $request->get_header( RequestSignature::HEADER_SIGNATURE ),
				$now
			);

			if ( RequestSignature::STATUS_OK === $check['status'] && ! SignatureState::remember_nonce( $nonce, $now ) ) {
				$check = RequestSignature::failed( RequestSignature::CODE_REPLAYED );
			}

			if ( RequestSignature::STATUS_FAILED === $check['status'] ) {
				SignatureState::record_failure( (string) ( $check['code'] ?? RequestSignature::CODE_INVALID ), $now );
			}

			return $check;
		} catch ( \Throwable ) {
			return RequestSignature::failed( 'signature_error' );
		}
	}

	/**
	 * La signature est-elle exigée pour cette requête ? Oui en politique `required`,
	 * et toujours pour les commandes de CommandExecutor::SIGNED_ONLY (rollback…),
	 * quelle que soit la politique : elles n'existaient pas avant la signature, aucun
	 * manager légitime ne les envoie sans signer.
	 */
	private static function signature_is_mandatory( WP_REST_Request $request ): bool {
		if ( 'required' === Settings::get( 'signature_policy' ) ) {
			return true;
		}
		$command = $request->get_param( 'command' );
		return is_string( $command ) && in_array( $command, CommandExecutor::SIGNED_ONLY, true );
	}
}
