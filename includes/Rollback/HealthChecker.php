<?php
/**
 * Contrôle de santé du site par requêtes loopback, avant et après une mise à
 * jour de plugin.
 *
 * Deux sondes :
 *   - la page d'accueil, avec un paramètre unique qui contourne les caches de page ;
 *   - `admin-ajax.php?action=g2rd_health`, qui charge le contexte d'administration
 *     (une erreur fatale propre à l'admin y apparaît) et répond avec un jeton à
 *     usage unique prouvant que la réponse vient bien du site (cf HealthEndpoint).
 *
 * « Non vérifiable » n'est pas « cassé » : un loopback bloqué, une authentification
 * HTTP ou un pare-feu donnent un verdict `unverifiable`, jamais un rollback. Le
 * rollback automatique n'est déclenché que sur une RÉGRESSION prouvée : une sonde
 * saine avant la mise à jour, cassée après.
 *
 * Le transport HTTP est injectable pour les tests ; par défaut wp_remote_get().
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class HealthChecker {

	public const HEALTHY      = 'healthy';
	public const BROKEN       = 'broken';
	public const UNVERIFIABLE = 'unverifiable';

	public const PROBE_OK           = 'ok';
	public const PROBE_BROKEN       = 'broken';
	public const PROBE_UNVERIFIABLE = 'unverifiable';

	public const TOKEN_TRANSIENT = 'g2rd_health_token';
	private const TIMEOUT        = 15;

	/** Marqueurs d'une erreur fatale PHP ou de l'écran « erreur critique » de WordPress. */
	private const FATAL_PATTERN = '/(<b>Fatal error<\/b>|Fatal error:|Parse error:|Uncaught (Error|Exception|[A-Za-z\\\\]+Error|[A-Za-z\\\\]+Exception)|class="wp-die-message"|There has been a critical error on this website)/i';

	/** @var callable(string, array<string, mixed>): array{code:int, body:string, error:?string} */
	private $transport;

	/**
	 * @param callable|null $transport fn( string $url, array $args ): array{code:int, body:string, error:?string}
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport ?? [ self::class, 'wp_transport' ];
	}

	/**
	 * Sonde les deux URL et renvoie l'état de chacune.
	 *
	 * @return array{home: array<string, mixed>, admin: array<string, mixed>}
	 */
	public function measure(): array {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::TOKEN_TRANSIENT, $token, 120 );

		$home  = $this->probe( add_query_arg( 'g2rd_hc', $token, home_url( '/' ) ), null );
		$admin = $this->probe(
			add_query_arg(
				[
					'action' => 'g2rd_health',
					'token'  => $token,
				],
				admin_url( 'admin-ajax.php' )
			),
			$token
		);

		delete_transient( self::TOKEN_TRANSIENT );

		return [
			'home'  => $home,
			'admin' => $admin,
		];
	}

	/**
	 * Verdict d'un contrôle d'après, relatif à la référence mesurée avant.
	 *
	 * @param array{home: array<string, mixed>, admin: array<string, mixed>} $baseline
	 * @param array{home: array<string, mixed>, admin: array<string, mixed>} $after
	 */
	public static function verdict( array $baseline, array $after ): string {
		$trusted = 0;
		foreach ( [ 'home', 'admin' ] as $probe ) {
			$before_state = $baseline[ $probe ]['state'] ?? self::PROBE_UNVERIFIABLE;
			$after_state  = $after[ $probe ]['state'] ?? self::PROBE_UNVERIFIABLE;

			if ( self::PROBE_OK !== $before_state ) {
				// Déjà cassée ou invérifiable avant : cette sonde ne prouve rien.
				continue;
			}
			if ( self::PROBE_BROKEN === $after_state ) {
				return self::BROKEN;
			}
			if ( self::PROBE_OK === $after_state ) {
				++$trusted;
			}
		}

		return $trusted > 0 ? self::HEALTHY : self::UNVERIFIABLE;
	}

	/**
	 * @return array{state:string, code:int|null, reason:string}
	 */
	private function probe( string $url, ?string $expected_token ): array {
		$args = [
			'timeout'     => self::TIMEOUT,
			'redirection' => 3,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false, $url ),
			'headers'     => [
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			],
			'user-agent'  => 'G2RD-Connector-HealthCheck/' . G2RD_CONNECTOR_VERSION,
		];

		try {
			$response = ( $this->transport )( $url, $args );
		} catch ( \Throwable $e ) {
			return self::result( self::PROBE_UNVERIFIABLE, null, 'transport: ' . $e->getMessage() );
		}

		if ( null !== ( $response['error'] ?? null ) ) {
			return self::result( self::PROBE_UNVERIFIABLE, null, (string) $response['error'] );
		}

		$code = (int) $response['code'];
		$body = (string) $response['body'];

		if ( $code >= 500 ) {
			return self::result( self::PROBE_BROKEN, $code, 'http ' . $code );
		}
		if ( 1 === preg_match( self::FATAL_PATTERN, $body ) ) {
			return self::result( self::PROBE_BROKEN, $code, 'fatal error in body' );
		}
		if ( $code >= 400 || 0 === $code ) {
			// 401/403 (auth HTTP, pare-feu), 404 (site en sous-dossier mal joint), 0 (pas de réponse).
			return self::result( self::PROBE_UNVERIFIABLE, $code, 'http ' . $code );
		}
		if ( '' === trim( $body ) ) {
			// 200 sans corps : écran blanc.
			return self::result( self::PROBE_BROKEN, $code, 'empty body' );
		}

		if ( null !== $expected_token ) {
			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) || ( $decoded['token'] ?? null ) !== $expected_token ) {
				// Réponse 200 mais pas la nôtre (cache, pare-feu, page de garde) : pas de preuve.
				return self::result( self::PROBE_UNVERIFIABLE, $code, 'unexpected body' );
			}
		}

		return self::result( self::PROBE_OK, $code, '' );
	}

	/**
	 * @return array{state:string, code:int|null, reason:string}
	 */
	private static function result( string $state, ?int $code, string $reason ): array {
		return [
			'state'  => $state,
			'code'   => $code,
			'reason' => $reason,
		];
	}

	/**
	 * Transport par défaut : wp_remote_get().
	 *
	 * @param array<string, mixed> $args
	 * @return array{code:int, body:string, error:?string}
	 */
	public static function wp_transport( string $url, array $args ): array {
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [
				'code'  => 0,
				'body'  => '',
				'error' => $response->get_error_message(),
			];
		}
		return [
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'  => (string) wp_remote_retrieve_body( $response ),
			'error' => null,
		];
	}
}
