<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\PhpErrors;

use G2RD\Connector\PhpErrors\Buffer;
use G2RD\Connector\PhpErrors\LoggingProbe;
use G2RD\Connector\PhpErrors\LogTail;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\Support\FakeLockWpdb;
use G2RD\Connector\Tests\TestCase;

/**
 * Lecture de la fin du journal PHP : seulement relevé ET lecture activés, journal rangé dans
 * WordPress ; 256 Ko et 2 s au plus ; décalage mémorisé ; journal remplacé ou vidé sur place
 * reconnu à son début et relu ; ligne en cours d'écriture relue ; lignes regroupées par
 * empreinte ; aucun chemin du serveur ; verrou tenu ailleurs.
 */
final class LogTailTest extends TestCase {

	private int $now = 0;

	private string $log = '';

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wpdb'] );
		$this->now = time();
		$this->log = str_replace( '\\', '/', (string) realpath( ABSPATH ) ) . '/g2rd-logtail-' . bin2hex( random_bytes( 4 ) ) . '.log';
		file_put_contents( $this->log, '' );
		$this->options[ Settings::OPTION_KEY ] = [
			'php_errors_enabled'  => true,
			'php_errors_log_tail' => true,
		];
	}

	protected function tearDown(): void {
		if ( is_file( $this->log ) ) {
			unlink( $this->log );
		}
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_coupee_sans_les_deux_reglages(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'php_errors_enabled' => true ];

		self::assertSame( LogTail::OUTCOME_OFF, LogTail::run( $this->now, $this->probe() ) );
		self::assertArrayNotHasKey( LogTail::CURSOR_OPTION, $this->options );
	}

	public function test_sans_journal_lisible(): void {
		self::assertSame( LogTail::OUTCOME_NO_LOG, LogTail::run( $this->now, new LoggingProbe( static fn (): string => '', static fn (): mixed => null, ABSPATH ) ) );
	}

	public function test_premiere_lecture_lignes_regroupees(): void {
		$this->write( $this->sample() );

		self::assertSame( LogTail::OUTCOME_READ, LogTail::run( $this->now, $this->probe() ) );

		$groups = array_values( Buffer::read( $this->now )['groups'] );
		self::assertCount( 3, $groups );
		$by_level = array_column( $groups, null, 'level' );
		self::assertSame( 2, $by_level['warning']['count'], 'Deux avertissements, même empreinte.' );
		self::assertSame( 'log_tail', $by_level['warning']['source'] );
		self::assertSame( 'wp-content/plugins/exemple-cache/exemple-cache.php', $by_level['warning']['file'] );
		self::assertSame( 'theme', $by_level['fatal']['type'] );
		self::assertSame( 214, $by_level['fatal']['line'] );
		self::assertSame( 'core', $by_level['deprecated']['type'] );
		self::assertSame( filesize( $this->log ), $this->options[ LogTail::CURSOR_OPTION ]['offset'] );
	}

	public function test_lecture_suivante_depuis_le_decalage(): void {
		$this->write( $this->sample() );
		LogTail::run( $this->now, $this->probe() );

		$this->write( $this->line( 'Warning', 'Undefined array key "troisieme"', 'wp-content/plugins/exemple-cache/exemple-cache.php on line 50' ) );
		LogTail::run( $this->now, $this->probe() );

		$by_level = array_column( array_values( Buffer::read( $this->now )['groups'] ), null, 'level' );
		self::assertSame( 3, $by_level['warning']['count'] );
		self::assertSame( 1, $by_level['fatal']['count'], 'Rien relu deux fois.' );
	}

	public function test_une_ligne_en_cours_d_ecriture_est_relue_ensuite(): void {
		$this->write( rtrim( $this->line( 'Notice', 'Only variables should be assigned by reference', 'wp-content/mu-plugins/outil.php on line 19' ), "\n" ) );

		LogTail::run( $this->now, $this->probe() );
		self::assertSame( [], Buffer::read( $this->now )['groups'] );

		$this->write( "\n" );
		LogTail::run( $this->now, $this->probe() );
		self::assertCount( 1, Buffer::read( $this->now )['groups'] );
	}

	public function test_au_plus_256_ko_lus_et_tronque_signale(): void {
		$line = $this->line( 'Warning', 'Undefined variable $x', 'wp-content/plugins/exemple/x.php on line 3' );
		$this->write( str_repeat( $line, (int) ceil( 600000 / strlen( $line ) ) ) );

		LogTail::run( $this->now, $this->probe() );

		$read  = Buffer::read( $this->now );
		$count = array_values( $read['groups'] )[0]['count'];
		self::assertLessThanOrEqual( (int) ceil( LogTail::MAX_BYTES / strlen( $line ) ), $count );
		self::assertGreaterThan( 0, $count );
		self::assertTrue( $read['truncated'] );
	}

	public function test_journal_renouvele_relu_depuis_le_debut(): void {
		$this->write( $this->sample() );
		$this->options[ LogTail::CURSOR_OPTION ] = [
			'file'   => hash( 'sha256', $this->log ),
			'offset' => 1000000000,
			'at'     => $this->now - 3600,
		];

		LogTail::run( $this->now, $this->probe() );

		self::assertCount( 3, Buffer::read( $this->now )['groups'] );
		self::assertFalse( Buffer::read( $this->now )['truncated'] );
	}

	public function test_journal_remplace_plus_long_que_l_ancien_decalage_est_relu(): void {
		$old_offset = $this->first_pass_on_one_line();

		unlink( $this->log );
		file_put_contents( $this->log, $this->renewed() );
		clearstatcache();
		self::assertGreaterThan( $old_offset, (int) filesize( $this->log ), 'Le nouveau journal dépasse l\'ancien décalage.' );

		LogTail::run( $this->now, $this->probe() );

		$this->assert_renewed_log_read_whole();
	}

	public function test_journal_vide_sur_place_puis_reecrit_est_relu(): void {
		$old_offset = $this->first_pass_on_one_line();

		$handle = fopen( $this->log, 'r+b' );
		self::assertNotFalse( $handle );
		ftruncate( $handle, 0 );
		fclose( $handle );
		$this->write( $this->renewed() );
		clearstatcache();
		self::assertGreaterThan( $old_offset, (int) filesize( $this->log ), 'Le journal réécrit dépasse l\'ancien décalage.' );

		LogTail::run( $this->now, $this->probe() );

		$this->assert_renewed_log_read_whole();
	}

	public function test_curseur_sans_empreinte_du_debut_relu_depuis_le_debut(): void {
		$this->write( $this->sample() );
		$this->options[ LogTail::CURSOR_OPTION ] = [
			'file'   => hash( 'sha256', $this->log ),
			'offset' => 100,
			'at'     => $this->now - 3600,
		];

		LogTail::run( $this->now, $this->probe() );

		$read     = Buffer::read( $this->now );
		$by_level = array_column( array_values( $read['groups'] ), null, 'level' );
		self::assertSame( 2, $by_level['warning']['count'], 'Sans empreinte, le décalage n\'est pas cru : le premier avertissement est lu lui aussi.' );
		self::assertCount( 3, $read['groups'] );
		self::assertFalse( $read['truncated'] );
	}

	public function test_deux_secondes_au_plus(): void {
		$this->write( $this->sample() );
		$ticks = [ 0.0, 0.1, 3.0 ];
		$clock = static function () use ( &$ticks ): float {
			return array_shift( $ticks ) ?? 99.0;
		};

		self::assertSame( LogTail::OUTCOME_READ, LogTail::run( $this->now, $this->probe(), $clock ) );

		self::assertCount( 1, Buffer::read( $this->now )['groups'], 'Une seule ligne lue avant la borne.' );
		self::assertTrue( Buffer::read( $this->now )['truncated'] );
	}

	public function test_aucun_chemin_du_serveur_dans_les_groupes(): void {
		$this->write( $this->line( 'Warning', 'include(/home/u123456789/.env): Failed to open stream', '/home/u123456789/domains/exemple.fr/public_html/wp-content/plugins/x/x.php on line 4', false ) );

		LogTail::run( $this->now, $this->probe() );

		$group = array_values( Buffer::read( $this->now )['groups'] )[0];
		self::assertSame( 'wp-content/plugins/x/x.php', $group['file'] );
		self::assertStringNotContainsString( '/home/', $group['message'] );
		self::assertStringNotContainsString( 'u123456789', $group['message'] );
	}

	public function test_verrou_tenu_ailleurs(): void {
		$this->write( $this->sample() );
		$GLOBALS['wpdb'] = new FakeLockWpdb( '0' );

		self::assertSame( LogTail::OUTCOME_BUSY, LogTail::run( $this->now, $this->probe() ) );
		self::assertArrayNotHasKey( LogTail::CURSOR_OPTION, $this->options );
	}

	public function test_une_ligne_trop_longue_est_sautee(): void {
		$this->write( '[' . gmdate( 'd-M-Y H:i:s', $this->now - 60 ) . ' UTC] PHP Warning:  ' . str_repeat( 'a', 10000 ) . " in /x/y.php on line 1\n" );
		$this->write( $this->line( 'Notice', 'Courte', 'wp-content/plugins/z/z.php on line 2' ) );

		LogTail::run( $this->now, $this->probe() );

		$groups = array_values( Buffer::read( $this->now )['groups'] );
		self::assertCount( 1, $groups );
		self::assertSame( 'notice', $groups[0]['level'] );
	}

	public function test_le_rappel_ne_prend_aucun_argument(): void {
		self::assertSame( 0, ( new \ReflectionMethod( LogTail::class, 'run_now' ) )->getNumberOfParameters() );
	}

	private function probe(): LoggingProbe {
		$log = $this->log;

		return new LoggingProbe( static fn ( string $option ): string => 'error_log' === $option ? $log : '', static fn (): mixed => null, ABSPATH );
	}

	private function write( string $text ): void {
		file_put_contents( $this->log, $text, FILE_APPEND );
	}

	private function line( string $kind, string $message, string $where, bool $relative_to_root = true ): string {
		$root = $relative_to_root ? str_replace( '\\', '/', (string) realpath( ABSPATH ) ) . '/' : '';

		return '[' . gmdate( 'd-M-Y H:i:s', $this->now - 60 ) . ' UTC] PHP ' . $kind . ':  ' . $message . ' in ' . $root . $where . "\n";
	}

	private function sample(): string {
		$root = str_replace( '\\', '/', (string) realpath( ABSPATH ) ) . '/';
		$date = '[' . gmdate( 'd-M-Y H:i:s', $this->now - 60 ) . ' UTC]';

		return $this->line( 'Warning', 'Undefined array key "reglages"', 'wp-content/plugins/exemple-cache/exemple-cache.php on line 42' )
			. $this->line( 'Warning', 'Undefined array key "autre"', 'wp-content/plugins/exemple-cache/exemple-cache.php on line 44' )
			. $date . ' PHP Fatal error:  Uncaught Error: Call to undefined function exemple() in ' . $root . "wp-content/themes/exemple-theme/functions.php:214\n"
			. "Stack trace:\n#0 {main}\n  thrown in " . $root . "wp-content/themes/exemple-theme/functions.php on line 214\n"
			. $this->line( 'Deprecated', 'Function get_page_by_title is deprecated since version 6.2.0! Use WP_Query instead.', 'wp-includes/functions.php on line 6114' )
			. "Une ligne sans rapport\n";
	}

	/** Premier passage sur un journal d'une ligne (un avertissement) ; rend le décalage mémorisé. */
	private function first_pass_on_one_line(): int {
		$this->write( $this->line( 'Warning', 'Undefined array key "ancien"', 'wp-content/plugins/exemple-cache/exemple-cache.php on line 7' ) );
		LogTail::run( $this->now, $this->probe() );
		$offset = $this->options[ LogTail::CURSOR_OPTION ]['offset'];
		self::assertSame( (int) filesize( $this->log ), $offset );

		return $offset;
	}

	/** Journal renouvelé : il commence par une erreur fatale, puis une notice et un avertissement. */
	private function renewed(): string {
		$root = str_replace( '\\', '/', (string) realpath( ABSPATH ) ) . '/';
		$date = '[' . gmdate( 'd-M-Y H:i:s', $this->now - 30 ) . ' UTC]';

		return $date . ' PHP Fatal error:  Uncaught Error: Call to undefined function exemple() in ' . $root . "wp-content/themes/exemple-theme/functions.php:214\n"
			. $this->line( 'Notice', 'Only variables should be assigned by reference', 'wp-content/mu-plugins/outil.php on line 19' )
			. $this->line( 'Deprecated', 'Function get_page_by_title is deprecated since version 6.2.0! Use WP_Query instead.', 'wp-includes/functions.php on line 6114' );
	}

	private function assert_renewed_log_read_whole(): void {
		$read     = Buffer::read( $this->now );
		$by_level = array_column( array_values( $read['groups'] ), null, 'level' );
		self::assertArrayHasKey( 'fatal', $by_level, 'L\'erreur fatale du début du nouveau journal est lue.' );
		self::assertSame( 1, $by_level['fatal']['count'] );
		self::assertArrayHasKey( 'notice', $by_level, 'La notice du début du nouveau journal est lue.' );
		self::assertSame( 1, $by_level['deprecated']['count'] );
		self::assertSame( 1, $by_level['warning']['count'], 'L\'avertissement de l\'ancien journal n\'est pas recompté.' );
		self::assertFalse( $read['truncated'], 'Le nouveau journal a été lu en entier.' );
		self::assertSame( (int) filesize( $this->log ), $this->options[ LogTail::CURSOR_OPTION ]['offset'] );
	}
}
