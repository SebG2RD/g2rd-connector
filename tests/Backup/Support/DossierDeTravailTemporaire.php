<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Support;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Run\WorkDir;

/**
 * Dossier de travail des sauvegardes dans un dossier temporaire, pour les tests des classes qui
 * n'écrivent et ne lisent que dans le dossier privé (WorkDir::open_private()) : journal du
 * manifeste, producteur de segments, état local. À appeler depuis setUp() d'un test Brain Monkey ;
 * retirer_dossier_de_travail() depuis tearDown().
 */
trait DossierDeTravailTemporaire {

	private string $racine_de_travail = '';

	/** Prépare le dossier de travail et rend le dossier privé, créé avec ses protections. */
	private function preparer_dossier_de_travail(): string {
		$racine                  = str_replace( '\\', '/', sys_get_temp_dir() ) . '/g2rd-travail-' . bin2hex( random_bytes( 4 ) );
		$this->racine_de_travail = $racine;
		Functions\when( 'apply_filters' )->alias( static fn ( string $nom, $valeur ) => 'g2rd_connector_backup_dir' === $nom ? $racine : $valeur );
		Functions\when( 'wp_salt' )->justReturn( 'sel-de-test' );
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( string $d ): bool => is_dir( $d ) || mkdir( $d, 0777, true ) );
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $f ): void {
				if ( is_file( $f ) || is_link( $f ) ) {
					unlink( $f );
				}
			}
		);
		self::assertTrue( WorkDir::ensure() );

		return WorkDir::private_dir();
	}

	/** Retire le dossier de travail du test (les liens éventuels ont déjà été retirés par le test). */
	private function retirer_dossier_de_travail(): void {
		if ( '' === $this->racine_de_travail || ! is_dir( $this->racine_de_travail ) ) {
			return;
		}
		$iterateur = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->racine_de_travail, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterateur as $entree ) {
			$entree->isDir() && ! $entree->isLink() ? rmdir( $entree->getPathname() ) : unlink( $entree->getPathname() );
		}
		rmdir( $this->racine_de_travail );
	}
}
