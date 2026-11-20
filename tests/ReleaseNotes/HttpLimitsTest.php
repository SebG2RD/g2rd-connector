<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\ReleaseNotes;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use G2RD\Connector\ReleaseNotes\HttpLimits;
use G2RD\Connector\Tests\TestCase;

/**
 * Bornes des appels faits pendant `get_release_notes` : 15 s en tout, redirections comprises (le
 * délai de chaque appel et de chaque saut est ramené au temps qui reste, puis plus rien ne part),
 * 3 redirections au plus, adresses sûres, 8 Mio par réponse ; crochets posés au début et retirés à
 * la fin, même sur une exception.
 */
final class HttpLimitsTest extends TestCase {

	private float $now = 1000.0;

	public function test_les_bornes_sont_celles_de_la_specification(): void {
		self::assertSame( 15, HttpLimits::BUDGET_SECONDS );
		self::assertSame( 8388608, HttpLimits::RESPONSE_MAX_BYTES );
		self::assertSame( 3, HttpLimits::MAX_REDIRECTS );
		self::assertSame( 'g2rd-time-budget-spent:', HttpLimits::BUDGET_SPENT_LOCATION );
	}

	/** Horloge monotone : un recalage de l'heure du système (NTP) ne rend ni ne retire de temps au budget. */
	public function test_l_horloge_par_defaut_est_monotone(): void {
		$clock = ( new \ReflectionProperty( HttpLimits::class, 'clock' ) )->getValue( new HttpLimits() );

		self::assertEqualsWithDelta( hrtime( true ) / 1e9, $clock(), 1.0 );
	}

	/** Chaque crochet reçoit tous ses arguments (2, 3 et 5), en dernier. */
	public function test_les_crochets_recoivent_tous_leurs_arguments(): void {
		$limits = $this->limits();
		Filters\expectAdded( 'http_request_args' )->once()->with( [ $limits, 'bound' ], PHP_INT_MAX, 2 );
		Filters\expectAdded( 'pre_http_request' )->once()->with( [ $limits, 'stop_when_spent' ], PHP_INT_MAX, 3 );
		Actions\expectAdded( 'requests-requests.before_redirect' )->once()->with( [ $limits, 'before_redirect' ], PHP_INT_MAX, 5 );

		self::assertTrue( $limits->around( static fn (): bool => true ) );
	}

	public function test_le_delai_est_ramene_au_temps_qui_reste(): void {
		$limits = $this->limits();
		$limits->around(
			function () use ( $limits ): void {
				self::assertSame( 15.0, $limits->bound( [ 'timeout' => 30 ], 'https://x.example/' )['timeout'] );
				self::assertSame( 5.0, $limits->bound( [ 'timeout' => 5 ], 'https://x.example/' )['timeout'] );
				$this->now += 10.0;
				self::assertSame( 5.0, $limits->bound( [ 'timeout' => 30 ], 'https://x.example/' )['timeout'] );
				$this->now += 4.5;
				self::assertSame( 1.0, $limits->bound( [ 'timeout' => 30 ], 'https://x.example/' )['timeout'] );
			}
		);
	}

	public function test_un_delai_nul_ou_absent_prend_le_temps_qui_reste(): void {
		$limits = $this->limits();
		$limits->around(
			static function () use ( $limits ): void {
				self::assertSame( 15.0, $limits->bound( [ 'timeout' => 0 ], '' )['timeout'] );
				self::assertSame( 15.0, $limits->bound( [], '' )['timeout'] );
			}
		);
	}

	public function test_la_taille_de_la_reponse_est_bornee(): void {
		$limits = $this->limits();
		$limits->around(
			static function () use ( $limits ): void {
				self::assertSame( HttpLimits::RESPONSE_MAX_BYTES, $limits->bound( [], '' )['limit_response_size'] );
				self::assertSame( 1048576, $limits->bound( [ 'limit_response_size' => 1048576 ], '' )['limit_response_size'] );
				self::assertSame( HttpLimits::RESPONSE_MAX_BYTES, $limits->bound( [ 'limit_response_size' => 104857600 ], '' )['limit_response_size'] );
				self::assertSame( HttpLimits::RESPONSE_MAX_BYTES, $limits->bound( [ 'limit_response_size' => null ], '' )['limit_response_size'] );
			}
		);
	}

	public function test_redirections_plafonnees_et_adresses_sures(): void {
		$limits = $this->limits();
		$limits->around(
			static function () use ( $limits ): void {
				self::assertSame( 3, $limits->bound( [], '' )['redirection'] );
				self::assertSame( 3, $limits->bound( [ 'redirection' => 5 ], '' )['redirection'] );
				self::assertSame( 1, $limits->bound( [ 'redirection' => 1 ], '' )['redirection'] );
				self::assertSame( 0, $limits->bound( [ 'redirection' => 0 ], '' )['redirection'] );
				self::assertTrue( $limits->bound( [ 'reject_unsafe_urls' => false ], '' )['reject_unsafe_urls'] );
			}
		);
	}

	public function test_des_arguments_illisibles_passent_tels_quels(): void {
		self::assertSame( 'x', $this->limits()->bound( 'x', '' ) );
	}

	public function test_budget_epuise_plus_aucun_appel_ne_part(): void {
		$limits = $this->limits();
		$limits->around(
			function () use ( $limits ): void {
				self::assertFalse( $limits->stop_when_spent( false, [], 'https://x.example/' ) );
				$this->now += 14.5;
				$refus = $limits->stop_when_spent( false, [], 'https://x.example/' );
				self::assertInstanceOf( \WP_Error::class, $refus );
				self::assertSame( 'g2rd_release_notes_time_budget', $refus->get_error_code() );
			}
		);
	}

	public function test_une_reponse_deja_fournie_n_est_pas_remplacee(): void {
		$limits = $this->limits();
		$limits->around(
			function () use ( $limits ): void {
				$this->now += 20.0;
				$deja = [ 'body' => 'x' ];
				self::assertSame( $deja, $limits->stop_when_spent( $deja, [], '' ) );
			}
		);
	}

	/** Requests suit les redirections lui-même : chaque saut reçoit le temps qui reste. */
	public function test_une_redirection_recoit_le_temps_qui_reste(): void {
		$limits = $this->limits();
		$limits->around(
			function () use ( $limits ): void {
				$this->now += 10.0;
				$location = 'https://x.example/suite';
				$headers  = [];
				$data     = [];
				$options  = [ 'timeout' => 60 ];
				$limits->before_redirect( $location, $headers, $data, $options );
				self::assertSame( 5.0, $options['timeout'] );
			}
		);
	}

	/**
	 * Budget épuisé : aucune exception dans l'action (do_action_ref_array() ne retirerait pas son nom
	 * de $wp_current_filter) ; la redirection est détournée vers une adresse non HTTP, que
	 * Requests::request() refuse lui-même (`nonhttp`, set_defaults()) après la fin du crochet.
	 */
	public function test_budget_epuise_la_redirection_est_detournee_sans_exception(): void {
		$limits = $this->limits();
		$limits->around(
			function () use ( $limits ): void {
				$this->now += 14.5;
				$location = 'https://x.example/suite';
				$headers  = [];
				$data     = [];
				$options  = [ 'timeout' => 60 ];
				$limits->before_redirect( $location, $headers, $data, $options );
				self::assertSame( HttpLimits::BUDGET_SPENT_LOCATION, $location );
				// Le contrôle de Requests::set_defaults() : seule une adresse http(s):// passe.
				self::assertSame( 0, preg_match( '/^http(s)?:\/\//i', $location ) );
			}
		);

		self::assertFalse( has_action( 'requests-requests.before_redirect' ) );
	}

	public function test_les_crochets_sont_poses_pendant_le_travail_et_retires_apres(): void {
		$limits  = $this->limits();
		$pendant = $limits->around(
			static fn (): array => [
				has_filter( 'http_request_args', [ $limits, 'bound' ] ),
				has_filter( 'pre_http_request', [ $limits, 'stop_when_spent' ] ),
				has_action( 'requests-requests.before_redirect', [ $limits, 'before_redirect' ] ),
			]
		);

		self::assertSame( [ PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX ], $pendant );
		self::assertFalse( has_filter( 'http_request_args' ) );
		self::assertFalse( has_filter( 'pre_http_request' ) );
		self::assertFalse( has_action( 'requests-requests.before_redirect' ) );
	}

	public function test_les_crochets_sont_retires_meme_sur_une_exception(): void {
		$limits = $this->limits();
		try {
			$limits->around(
				static function (): void {
					throw new \RuntimeException( 'appel en échec' );
				}
			);
			self::fail( 'L’exception doit remonter.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'appel en échec', $e->getMessage() );
		}

		self::assertFalse( has_filter( 'http_request_args' ) );
		self::assertFalse( has_filter( 'pre_http_request' ) );
		self::assertFalse( has_action( 'requests-requests.before_redirect' ) );
	}

	private function limits(): HttpLimits {
		return new HttpLimits( fn (): float => $this->now );
	}
}
