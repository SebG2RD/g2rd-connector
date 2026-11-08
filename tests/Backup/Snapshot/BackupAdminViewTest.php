<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Snapshot;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Snapshot\BackupAdminView;
use G2RD\Connector\Tests\Backup\BackupTestCase;

final class BackupAdminViewTest extends BackupTestCase {

	public function test_eteint_aucune_empreinte(): void {
		self::assertSame(
			[
				'enabled'            => false,
				'disabledByConstant' => false,
				'fingerprint'        => null,
				'siteKeyVersion'     => null,
				'lastOutcome'        => null,
				'lastAt'             => null,
			],
			BackupAdminView::data( 1800000000 )
		);
	}

	public function test_allume_empreinte_en_huit_groupes_de_quatre(): void {
		Functions\when( 'add_option' )->alias(
			function ( string $nom, $valeur ): bool {
				$this->options[ $nom ] = $valeur;
				return true;
			}
		);
		$this->settings( [ 'backup_enabled' => true ] );

		self::assertMatchesRegularExpression( '/\A([0-9a-f]{4} ){7}[0-9a-f]{4}\z/', (string) BackupAdminView::data( 1800000000 )['fingerprint'] );
	}

	public function test_le_panneau_et_la_page_portent_la_case_et_l_empreinte(): void {
		$racine  = dirname( __DIR__, 3 );
		$panneau = (string) file_get_contents( $racine . '/assets/admin/src/ConnectorPanel.tsx' );
		$page    = (string) file_get_contents( $racine . '/includes/Admin/Page.php' );

		self::assertStringContainsString( 'title="Sauvegardes chiffrées"', $panneau );
		self::assertStringContainsString( 'backup_enabled: d.backupEnabled', $panneau );
		self::assertStringContainsString( 'force_ipv4_to_storage: d.forceIpv4ToStorage', $panneau );
		self::assertStringContainsString( 'name="g2rd_backup_enabled"', $page );
		self::assertStringContainsString( 'name="g2rd_force_ipv4_to_storage"', $page );
		self::assertStringContainsString( "'backup_enabled'          => BackupSwitch::disabled_by_constant() ? (bool) Settings::get( 'backup_enabled' ) : ! empty( \$_POST['g2rd_backup_enabled'] )", $page, 'constante posée : un autre réglage enregistré ne décoche jamais la case en silence' );
	}
}
