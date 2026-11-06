<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\PhpErrors\LoggingProbe;
use G2RD\Connector\Tests\TestCase;

/**
 * Sonde de journalisation (bloc `php_logging` v1) : réglages de PHP et de WordPress, emplacement
 * du journal PHP sans jamais son chemin, confinement à la racine de WordPress (ni « .. », ni lien
 * symbolique, ni chemin relatif), aucun avertissement sous open_basedir, bloc minimal si la
 * sonde lève.
 */
final class LoggingProbeTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../fixtures/snapshot-php-logging-v1.json';

	private const OPEN_BASEDIR_SCRIPT = __DIR__ . '/../fixtures/php-errors/logging-probe-open-basedir.php';

	private string $root = '';

	private string $outside = '';

	/**
	 * Liens créés par le test, retirés avant les dossiers (une jonction Windows, que is_link()
	 * ne voit pas, ferait vider sa cible).
	 *
	 * @var list<string>
	 */
	private array $links = [];

	protected function setUp(): void {
		parent::setUp();
		$base          = str_replace( '\\', '/', (string) realpath( sys_get_temp_dir() ) );
		$this->root    = $base . '/g2rd-logprobe-' . bin2hex( random_bytes( 4 ) );
		$this->outside = $base . '/g2rd-logprobe-hors-' . bin2hex( random_bytes( 4 ) );
		$this->links   = [];
		mkdir( $this->root . '/wp-content', 0777, true );
		mkdir( $this->outside, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( $this->links as $link ) {
			// Le lien seul, jamais sa cible : rmdir() pour une jonction Windows, unlink() ailleurs.
			if ( ! ( '\\' === DIRECTORY_SEPARATOR && is_dir( $link ) ? rmdir( $link ) : unlink( $link ) ) ) {
				self::fail( 'Lien de test impossible à retirer : ' . $link );
			}
		}
		foreach ( [ $this->root, $this->outside ] as $dir ) {
			self::remove( $dir );
		}
		parent::tearDown();
	}

	public function test_journal_dans_wordpress_comme_l_exemple(): void {
		$log    = $this->root . '/wp-content/debug.log';
		$handle = fopen( $log, 'wb' );
		self::assertNotFalse( $handle );
		ftruncate( $handle, 1048576 );
		fclose( $handle );

		$probe = $this->probe(
			[
				'error_log'      => $log,
				'log_errors'     => '1',
				'display_errors' => '0',
			],
			[
				'WP_DEBUG'         => true,
				'WP_DEBUG_LOG'     => true,
				'WP_DEBUG_DISPLAY' => false,
			]
		);

		self::assertSame( self::example( 'journal_dans_wordpress' ), $probe->describe() );
		self::assertSame( $log, $probe->readable_log_path() );
	}

	public function test_journal_hors_wordpress_ni_lu_ni_mesure(): void {
		$log = $this->outside . '/php.log';
		file_put_contents( $log, "ligne\n" );

		$probe = $this->probe(
			[
				'error_log'      => $log,
				'log_errors'     => 'On',
				'display_errors' => 'Off',
			],
			[ 'WP_DEBUG_DISPLAY' => true ]
		);

		self::assertSame( self::example( 'journal_hors_wordpress' ), $probe->describe() );
		self::assertNull( $probe->readable_log_path() );
	}

	public function test_journal_non_defini(): void {
		$probe = $this->probe( [ 'log_errors' => '0' ], [] );

		self::assertSame( self::example( 'journal_non_defini' ), $probe->describe() );
	}

	public function test_syslog_est_hors_de_wordpress(): void {
		self::assertSame( LoggingProbe::LOG_OUTSIDE, $this->probe( [ 'error_log' => 'syslog' ], [] )->describe()['error_log'] );
	}

	/**
	 * Linux : /dev/stderr est un lien (vers /proc/self/fd/2) rangé hors de WordPress. Hors de
	 * WordPress, pas « refusé » : le chemin écrit est comparé à la racine avant tout appel au
	 * disque, sinon le manager (lot E1) afficherait une fausse cause.
	 */
	public function test_dev_stderr_est_hors_de_wordpress(): void {
		if ( 'Linux' !== PHP_OS_FAMILY || ! is_link( '/dev/stderr' ) ) {
			self::markTestSkipped( '/dev/stderr n\'est un lien que sous Linux.' );
		}

		$probe = $this->probe( [ 'error_log' => '/dev/stderr' ], [] );

		self::assertSame( LoggingProbe::LOG_OUTSIDE, $probe->describe()['error_log'] );
		self::assertNull( $probe->readable_log_path() );
	}

	public function test_remontee_refusee(): void {
		file_put_contents( $this->root . '/debug.log', "ligne\n" );

		self::assertSame( LoggingProbe::LOG_REFUSED, $this->probe( [ 'error_log' => $this->root . '/wp-content/../debug.log' ], [] )->describe()['error_log'] );
	}

	public function test_chemin_relatif_refuse(): void {
		self::assertSame( LoggingProbe::LOG_REFUSED, $this->probe( [ 'error_log' => 'debug.log' ], [] )->describe()['error_log'] );
	}

	public function test_lien_symbolique_refuse(): void {
		$target = $this->root . '/wp-content/vrai.log';
		file_put_contents( $target, "ligne\n" );
		$link = $this->root . '/wp-content/lien.log';
		if ( ! @symlink( $target, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Windows sans droit de lien : test sauté.
			self::markTestSkipped( 'Impossible de créer un lien symbolique sur ce poste.' );
		}

		$probe = $this->probe( [ 'error_log' => $link ], [] );

		self::assertSame( LoggingProbe::LOG_REFUSED, $probe->describe()['error_log'] );
		self::assertNull( $probe->readable_log_path() );
	}

	public function test_fichier_absent(): void {
		$out = $this->probe( [ 'error_log' => $this->root . '/wp-content/absent.log' ], [] )->describe();

		self::assertSame( LoggingProbe::LOG_MISSING, $out['error_log'] );
		self::assertFalse( $out['error_log_readable'], 'Absent : vérifié, donc faux.' );
	}

	/** Ce qui n'a pas été vérifié est inconnu (null), jamais « illisible » (statuts vrais). */
	public function test_lisibilite_inconnue_quand_elle_n_est_pas_verifiee(): void {
		$outside = $this->outside . '/php.log';
		file_put_contents( $outside, "ligne\n" );

		$cas = [
			'hors de WordPress' => [ 'error_log' => $outside ],
			'non défini'        => [],
			'refusé'            => [ 'error_log' => 'debug.log' ],
		];
		foreach ( $cas as $nom => $ini ) {
			self::assertNull( $this->probe( $ini, [] )->describe()['error_log_readable'], $nom );
		}
	}

	/**
	 * Site secondaire d'un réseau multisite : le journal PHP est commun au réseau, seul le site
	 * principal le lit (sinon chaque blog rangerait les erreurs de tout le réseau).
	 */
	public function test_site_secondaire_d_un_reseau_le_journal_n_est_pas_lu(): void {
		$log = $this->root . '/wp-content/debug.log';
		file_put_contents( $log, "ligne\n" );

		$secondaire = $this->probe( [ 'error_log' => $log ], [], true );
		$principal  = $this->probe( [ 'error_log' => $log ], [], false );

		self::assertSame( LoggingProbe::LOG_NETWORK, $secondaire->describe()['error_log'] );
		self::assertNull( $secondaire->describe()['error_log_readable'] );
		self::assertNull( $secondaire->describe()['error_log_bytes'] );
		self::assertNull( $secondaire->readable_log_path() );
		self::assertSame( LoggingProbe::LOG_INSIDE, $principal->describe()['error_log'] );
		self::assertSame( $log, $principal->readable_log_path() );
	}

	public function test_aucun_chemin_dans_la_sortie(): void {
		file_put_contents( $this->root . '/wp-content/debug.log', "ligne\n" );
		$out = $this->probe( [ 'error_log' => $this->root . '/wp-content/debug.log' ], [] )->describe();

		foreach ( $out as $value ) {
			if ( is_string( $value ) ) {
				self::assertMatchesRegularExpression( '/\A[a-z_]{1,32}\z/', $value );
			}
		}
	}

	/**
	 * Sous open_basedir (processus PHP à part) : un journal hors de la zone, présent ou absent,
	 * est hors de WordPress, sans aucun avertissement. Un avertissement porterait le chemin du
	 * journal et partirait dans le journal du site à chaque snapshot, ou avant son JSON si
	 * display_errors est actif.
	 */
	public function test_sous_open_basedir_un_journal_hors_de_la_zone_n_avertit_pas(): void {
		file_put_contents( $this->outside . '/php.log', "ligne\n" );

		foreach ( [ 'présent' => $this->outside . '/php.log', 'absent' => $this->outside . '/absent.log' ] as $nom => $log ) {
			self::assertSame(
				[
					'error_log'         => LoggingProbe::LOG_OUTSIDE,
					'readable_log_path' => null,
				],
				$this->under_open_basedir( $log ),
				$nom
			);
		}
	}

	/**
	 * Sous open_basedir : un lien rangé dans WordPress qui mène hors de la zone. is_link() et
	 * realpath() avertiraient (chemin compris) : muets, et le journal est hors de WordPress,
	 * jamais « absent » (statuts vrais).
	 */
	public function test_sous_open_basedir_un_lien_vers_l_exterieur_n_avertit_pas(): void {
		file_put_contents( $this->outside . '/php.log', "ligne\n" );
		$link = $this->root . '/wp-content/dehors';
		if ( ! $this->link_directory( $this->outside, $link ) ) {
			self::markTestSkipped( 'Impossible de créer un lien vers un dossier sur ce poste.' );
		}

		foreach ( [ 'présent' => $link . '/php.log', 'absent' => $link . '/absent.log' ] as $nom => $log ) {
			self::assertSame(
				[
					'error_log'         => LoggingProbe::LOG_OUTSIDE,
					'readable_log_path' => null,
				],
				$this->under_open_basedir( $log ),
				$nom
			);
		}
	}

	/** Témoin : sous open_basedir, un journal rangé dans WordPress reste lu, sans avertissement. */
	public function test_sous_open_basedir_un_journal_dans_wordpress_reste_lu(): void {
		$log = $this->root . '/wp-content/debug.log';
		file_put_contents( $log, "ligne\n" );

		self::assertSame(
			[
				'error_log'         => LoggingProbe::LOG_INSIDE,
				'readable_log_path' => $log,
			],
			$this->under_open_basedir( $log )
		);
		self::assertSame(
			[
				'error_log'         => LoggingProbe::LOG_MISSING,
				'readable_log_path' => null,
			],
			$this->under_open_basedir( $this->root . '/wp-content/absent.log' )
		);
	}

	public function test_une_sonde_qui_leve_rend_le_bloc_minimal(): void {
		$probe = new LoggingProbe(
			static function (): never {
				throw new \RuntimeException( 'ini_get désactivée' );
			},
			static fn (): mixed => null,
			$this->root
		);

		self::assertSame( self::example( 'illisible' ), $probe->describe() );
		self::assertNull( $probe->readable_log_path() );
	}

	public function test_les_exemples_ont_les_cles_du_bloc(): void {
		$keys = array_keys( $this->probe( [], [] )->describe() );

		foreach ( [ 'journal_dans_wordpress', 'journal_hors_wordpress', 'journal_non_defini' ] as $name ) {
			self::assertSame( $keys, array_keys( self::example( $name ) ), $name );
		}
		$decoded = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( [ 'unset', 'inside_wordpress', 'outside_wordpress', 'missing', 'refused', 'network_shared' ], $decoded['values']['error_log'] );
	}

	/**
	 * @param array<string, string> $ini        Directives lues.
	 * @param array<string, mixed>  $constants  Constantes définies.
	 * @param bool                  $secondaire Site secondaire d'un réseau multisite.
	 */
	private function probe( array $ini, array $constants, bool $secondaire = false ): LoggingProbe {
		return new LoggingProbe(
			// Directive absente : false, comme ini_get() pour une directive inconnue.
			static fn ( string $option ): string|false => $ini[ $option ] ?? false,
			static fn ( string $name ): mixed => $constants[ $name ] ?? null,
			$this->root,
			static fn (): bool => $secondaire
		);
	}

	/**
	 * La sonde dans un processus PHP à part (tests/fixtures/php-errors/logging-probe-open-basedir.php),
	 * open_basedir borné à la racine du test : rien d'autre qu'une ligne JSON sur la sortie.
	 *
	 * @return array<string, mixed> Statut (describe()) et chemin lisible (readable_log_path()).
	 */
	private function under_open_basedir( string $log ): array {
		if ( ! function_exists( 'exec' ) ) {
			self::markTestSkipped( 'exec() est désactivée : impossible de lancer un processus PHP à part.' );
		}
		$zone   = $this->root . '/';
		$sortie = [];
		$code   = 0;

		exec( escapeshellarg( PHP_BINARY ) . ' -n -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg( self::OPEN_BASEDIR_SCRIPT ) . ' ' . escapeshellarg( $zone ) . ' ' . escapeshellarg( $this->root ) . ' ' . escapeshellarg( $log ) . ' 2>&1', $sortie, $code );

		$texte = implode( "\n", $sortie );
		self::assertSame( 0, $code, $texte );
		self::assertCount( 1, $sortie, 'Une seule ligne, aucun avertissement. Sortie : ' . $texte );
		$out = json_decode( $sortie[0], true );
		self::assertIsArray( $out, 'Sortie illisible : ' . $texte );
		self::assertSame( $zone, $out['open_basedir'] ?? null, 'open_basedir posé dans le processus à part.' );
		unset( $out['open_basedir'] );

		return $out;
	}

	/**
	 * Lien symbolique vers un dossier, ou jonction sous Windows sans droit de créer des liens
	 * (même précédent que SizeMeterTest et ProtectedUpdateTest).
	 */
	private function link_directory( string $target, string $link ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- refus attendu sous Windows sans droit.
		$created = @symlink( $target, $link );
		if ( ! $created && '\\' === DIRECTORY_SEPARATOR && function_exists( 'exec' ) ) {
			$to_windows = static fn ( string $path ): string => '"' . str_replace( '/', '\\', $path ) . '"';
			exec( 'cmd /c mklink /J ' . $to_windows( $link ) . ' ' . $to_windows( $target ) . ' 2>&1', $output, $code );
			$created = 0 === $code && is_dir( $link );
		}
		if ( $created ) {
			$this->links[] = $link;
		}

		return $created;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function example( string $name ): array {
		$decoded = json_decode( (string) file_get_contents( self::FIXTURE ), true, 512, JSON_THROW_ON_ERROR );

		return $decoded['examples'][ $name ];
	}

	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( ! is_string( $entry ) || '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} else {
				self::remove( $path );
			}
		}
		rmdir( $dir );
	}
}
