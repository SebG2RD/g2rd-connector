<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Gate\UpdateWitness;
use G2RD\Connector\Backup\Keys\SigningKey;
use G2RD\Connector\Backup\Keys\SiteKeyStore;
use G2RD\Connector\Backup\Run\WorkDir;
use G2RD\Connector\Backup\State\BackupState;
use G2RD\Connector\Tests\DirectLogin\FakeWpdb;
use G2RD\Connector\Tests\TestCase;

final class UninstallBackupTest extends TestCase {

	private string $travail;

	protected function setUp(): void {
		parent::setUp();
		$this->travail   = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-desinstall-' . bin2hex( random_bytes( 4 ) );
		$GLOBALS['wpdb'] = new FakeWpdb( fn (): array => array_map( 'strval', array_keys( $this->options ) ), static function (): void {} );
		$travail         = $this->travail;
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'delete_site_option' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->alias( static fn ( string $file ): string => dirname( $file ) . '/' );
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => match ( $hook ) {
				'g2rd_connector_backup_dir'    => $travail,
				'g2rd_connector_snapshots_dir' => sys_get_temp_dir() . '/g2rd-uninstall-absent',
				default                        => $value,
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $d ): bool => is_dir( $d ) || mkdir( $d, 0777, true ) );
		Functions\when( 'wp_salt' )->justReturn( 'sel-de-test' );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $f ): void {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_unschedule_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
	}

	public function test_options_et_fichiers_du_module_retires_un_fichier_etranger_garde(): void {
		foreach ( [ BackupState::OPTION, UpdateWitness::OPTION, SiteKeyStore::OPTION, SigningKey::OPTION ] as $option ) {
			$this->options[ $option ] = 'x';
		}
		$job = (string) WorkDir::job( str_repeat( 'a', 32 ) );
		file_put_contents( $job . '/seg-0.seg', 'x' );
		file_put_contents( WorkDir::baseline_dir() . '/' . str_repeat( 'b', 32 ) . '.jsonl', 'x' );
		file_put_contents( WorkDir::baseline_dir() . '/current.json', '{}' );
		file_put_contents( $this->travail . '/etranger.txt', 'x' );

		$this->uninstall();

		foreach ( [ BackupState::OPTION, UpdateWitness::OPTION, SiteKeyStore::OPTION, SigningKey::OPTION ] as $option ) {
			self::assertArrayNotHasKey( $option, $this->options );
		}
		self::assertDirectoryDoesNotExist( $job );
		self::assertDirectoryDoesNotExist( WorkDir::baseline_dir() );
		$restants = [ '.htaccess', 'etranger.txt', 'index.php', 'web.config' ];
		self::assertSame( $restants, array_values( array_diff( (array) scandir( $this->travail ), [ '.', '..' ] ) ), 'un fichier étranger n\'est jamais touché, et le dossier qui le garde reste protégé' );
		foreach ( $restants as $nom ) {
			unlink( $this->travail . '/' . $nom );
		}
		rmdir( $this->travail );
	}

	public function test_sans_fichier_etranger_le_dossier_disparait(): void {
		$job = (string) WorkDir::job( str_repeat( 'c', 32 ) );
		file_put_contents( $job . '/index.bin', 'x' );

		$this->uninstall();

		self::assertDirectoryDoesNotExist( $this->travail );
	}

	public function test_sans_dossier_de_travail_rien_ne_casse(): void {
		$this->uninstall();

		self::assertDirectoryDoesNotExist( $this->travail );
	}

	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'g2rd-connector/g2rd-connector.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
