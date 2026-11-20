<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Rollback;

use Brain\Monkey\Functions;
use G2RD\Connector\Rollback\HealthChecker;
use G2RD\Connector\Tests\TestCase;

final class HealthCheckerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test' . $path );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://site.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value = null, $url = null ): string {
				if ( is_array( $key ) ) {
					return $value . ( str_contains( $value, '?' ) ? '&' : '?' ) . http_build_query( $key );
				}
				return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $key . '=' . $value;
			}
		);
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
	}

	/**
	 * Transport simulé : renvoie une réponse par URL selon qu'elle contient `admin-ajax`.
	 *
	 * @param array{code:int, body:string, error?:string|null}      $home
	 * @param array{code:int, body:string|callable, error?:string|null} $admin
	 */
	private function checker( array $home, array $admin, ?array &$seen = null ): HealthChecker {
		return new HealthChecker(
			function ( string $url, array $args ) use ( $home, $admin, &$seen ): array {
				$seen[] = [ $url, $args ];
				if ( str_contains( $url, 'admin-ajax' ) ) {
					parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
					$body = $admin['body'];
					if ( is_callable( $body ) ) {
						$body = $body( (string) ( $query['token'] ?? '' ) );
					}
					return [
						'code'  => $admin['code'],
						'body'  => $body,
						'error' => $admin['error'] ?? null,
					];
				}
				return [
					'code'  => $home['code'],
					'body'  => $home['body'],
					'error' => $home['error'] ?? null,
				];
			}
		);
	}

	/** Réponse admin-ajax légitime : renvoie le jeton reçu. */
	private static function ajax_ok(): callable {
		return static fn ( string $token ): string => json_encode( [ 'ok' => true, 'token' => $token ] );
	}

	public function test_healthy_site(): void {
		$seen  = [];
		$probe = $this->checker( [ 'code' => 200, 'body' => '<html>ok</html>' ], [ 'code' => 200, 'body' => self::ajax_ok() ], $seen )->measure();

		self::assertSame( 'ok', $probe['home']['state'] );
		self::assertSame( 'ok', $probe['admin']['state'] );

		// Cache-busting + jeton à usage unique + pas de vérification SSL locale forcée.
		self::assertStringContainsString( 'g2rd_hc=', $seen[0][0] );
		self::assertStringContainsString( 'action=g2rd_health', $seen[1][0] );
		self::assertSame( 'no-cache', $seen[0][1]['headers']['Cache-Control'] );
		self::assertSame( [], $this->transients, 'le jeton est retiré après la mesure' );
	}

	public function test_http_500_is_broken(): void {
		$probe = $this->checker( [ 'code' => 500, 'body' => '' ], [ 'code' => 500, 'body' => '' ] )->measure();
		self::assertSame( 'broken', $probe['home']['state'] );
		self::assertSame( 'broken', $probe['admin']['state'] );
	}

	public function test_fatal_error_in_a_200_body_is_broken(): void {
		$probe = $this->checker( [ 'code' => 200, 'body' => "<br />\n<b>Fatal error</b>:  Uncaught Error: Call to undefined function" ], [ 'code' => 200, 'body' => self::ajax_ok() ] )->measure();
		self::assertSame( 'broken', $probe['home']['state'] );
	}

	public function test_critical_error_screen_is_broken(): void {
		$probe = $this->checker( [ 'code' => 200, 'body' => '<p class="wp-die-message">There has been a critical error on this website.</p>' ], [ 'code' => 200, 'body' => self::ajax_ok() ] )->measure();
		self::assertSame( 'broken', $probe['home']['state'] );
	}

	public function test_white_screen_is_broken(): void {
		$probe = $this->checker( [ 'code' => 200, 'body' => "  \n" ], [ 'code' => 200, 'body' => self::ajax_ok() ] )->measure();
		self::assertSame( 'broken', $probe['home']['state'] );
	}

	public function test_blocked_loopback_is_unverifiable_not_broken(): void {
		$probe = $this->checker( [ 'code' => 0, 'body' => '', 'error' => 'cURL error 7' ], [ 'code' => 403, 'body' => 'Forbidden' ] )->measure();
		self::assertSame( 'unverifiable', $probe['home']['state'] );
		self::assertSame( 'unverifiable', $probe['admin']['state'] );
	}

	/**
	 * `reason` part au manager dans `health` : le texte d'une erreur de transport (WordPress, cURL, ou
	 * une extension branchée sur `pre_http_request`) y est filtré ; jamais « [unreadable] » pour un vide.
	 */
	public function test_the_reason_of_a_transport_error_is_filtered(): void {
		$path  = '/home/u123456789/domains/exemple.fr/public_html/wp-includes/certificates/ca-bundle.crt';
		$probe = $this->checker( [ 'code' => 0, 'body' => '', 'error' => 'cURL error 77: error setting certificate verify locations: CAfile: ' . $path ], [ 'code' => 0, 'body' => '', 'error' => '' ] )->measure();
		self::assertSame( 'cURL error 77: error setting certificate verify locations: CAfile: wp-includes/certificates/ca-bundle.crt', $probe['home']['reason'] );
		self::assertSame( '', $probe['admin']['reason'] );

		$throws = new HealthChecker(
			static function () use ( $path ): array {
				throw new \RuntimeException( 'pre_http_request: ' . $path . ' for contact@exemple.fr' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte d'essai.
			}
		);
		self::assertSame( 'transport: pre_http_request: wp-includes/certificates/ca-bundle.crt for [email]', $throws->measure()['home']['reason'] );
	}

	/** Erreur de transport sans message (extension sur `pre_http_request`) : son code, jamais un `reason` vide. */
	public function test_a_transport_error_without_message_gives_its_code(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'http_request_failed', '' ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );

		self::assertSame( 'http_request_failed', HealthChecker::wp_transport( 'https://site.test/', [] )['error'] );
	}

	public function test_admin_answer_without_our_token_is_unverifiable(): void {
		$probe = $this->checker( [ 'code' => 200, 'body' => 'ok' ], [ 'code' => 200, 'body' => '{"ok":true,"token":"autre"}' ] )->measure();
		self::assertSame( 'unverifiable', $probe['admin']['state'], 'une réponse de cache ou de pare-feu ne prouve rien' );
	}

	// ── Verdict : régression par rapport à la référence ─────────────────────────

	/**
	 * @return iterable<string, array{array<string,string>, array<string,string>, string}>
	 */
	public static function verdicts(): iterable {
		yield 'sain avant, sain après' => [ [ 'ok', 'ok' ], [ 'ok', 'ok' ], 'healthy' ];
		yield 'sain avant, accueil cassé après (auto-rollback)' => [ [ 'ok', 'ok' ], [ 'broken', 'ok' ], 'broken' ];
		yield 'sain avant, admin cassé après (fatale admin)' => [ [ 'ok', 'ok' ], [ 'ok', 'broken' ], 'broken' ];
		yield 'déjà cassé avant : pas imputé à la MAJ' => [ [ 'broken', 'broken' ], [ 'broken', 'broken' ], 'unverifiable' ];
		yield 'invérifiable avant et après' => [ [ 'unverifiable', 'unverifiable' ], [ 'unverifiable', 'unverifiable' ], 'unverifiable' ];
		yield 'sain avant, invérifiable après (loopback coupé entre-temps)' => [ [ 'ok', 'ok' ], [ 'unverifiable', 'unverifiable' ], 'unverifiable' ];
		yield 'seul l\'accueil est vérifiable, et il reste sain' => [ [ 'ok', 'unverifiable' ], [ 'ok', 'unverifiable' ], 'healthy' ];
		yield 'accueil sain, admin cassé avant ET après' => [ [ 'ok', 'broken' ], [ 'ok', 'broken' ], 'healthy' ];
	}

	/**
	 * @param array{0:string,1:string} $before [home, admin]
	 * @param array{0:string,1:string} $after  [home, admin]
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'verdicts' )]
	public function test_verdict( array $before, array $after, string $expected ): void {
		$shape = static fn ( array $s ): array => [
			'home'  => [ 'state' => $s[0] ],
			'admin' => [ 'state' => $s[1] ],
		];
		self::assertSame( $expected, HealthChecker::verdict( $shape( $before ), $shape( $after ) ) );
	}
}
