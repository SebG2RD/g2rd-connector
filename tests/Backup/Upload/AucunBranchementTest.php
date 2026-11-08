<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Upload;

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;

/**
 * Garde-fous de la phase 3 des sauvegardes : l'envoi d'une partie (PartUploader) existe,
 * mais rien ne s'en sert avant la phase 4. Aucun appelant, aucun réglage, aucune
 * commande : une version publiée par erreur avec cette branche ne change rien au site.
 *
 * Les listes de référence sont celles d'origin/main (993d729), recopiées en dur : lues
 * dans le code, elles suivraient ce qu'elles doivent surveiller. Elles sont recalées sur
 * la 1.16.0-rc.1 (origin/main 666f084 : réglages `php_errors_enabled` et
 * `php_errors_log_tail`, commande signée `set_php_errors_capture`) dans le commit qui
 * fusionne main dans le module (plan de la phase 4, « Piste concurrente C1 »). La phase 4, qui
 * branchera l'envoi (commande, réglage `force_ipv4_to_storage`), met ces listes à jour
 * dans le même commit que le branchement, sciemment.
 */
final class AucunBranchementTest extends TestCase {

	/** Dossier où PartUploader et PartUploadResult ont le droit d'être cités. */
	private const DOSSIER_UPLOAD = 'includes/Backup/Upload/';

	/** Phase 4 : seul appelant permis de l'envoi par parties hors de son dossier (plan de la phase 4, tâche 28). */
	private const APPELANTS_PERMIS = [ 'includes/Backup/Run/BackupJob.php' ];

	/** Réglages par défaut d'origin/main : ni clé ajoutée, ni valeur changée. */
	private const REGLAGES_D_ORIGINE = [
		'manager_url'             => 'https://wp-manager.g2rd.fr',
		'site_id'                 => null,
		'site_token'              => '',
		'enrolled_at'             => null,
		'last_heartbeat_at'       => null,
		'heartbeat_enabled'       => true,
		'events_enabled'          => true,
		'remote_commands_enabled' => true,
		'force_ipv4_to_manager'   => false,
		'allow_direct_login'      => true,
		'signature_policy'        => 'report',
		'php_errors_enabled'      => false,
		'php_errors_log_tail'     => false,
	];

	/** CommandExecutor::ALLOWED d'origin/main, dans son ordre. */
	private const COMMANDES_D_ORIGINE = [
		'clear_cache',
		'check_updates',
		'update_core',
		'delete_spam_comments',
		'delete_post_revisions',
		'empty_trashed_posts',
		'delete_expired_transients',
		'optimize_database',
		'update_plugin',
		'update_theme',
		'update_translations',
		'rollback_plugin',
		'delete_restore_point',
		'set_signature_policy',
		'set_php_errors_capture',
	];

	/** CommandExecutor::SIGNED_ONLY d'origin/main, dans son ordre. */
	private const COMMANDES_SIGNEES_D_ORIGINE = [
		'rollback_plugin',
		'delete_restore_point',
		'set_signature_policy',
		'set_php_errors_capture',
	];

	/**
	 * Parcours de `includes/` (et des deux fichiers PHP de la racine, chargés par
	 * WordPress) : PartUploader et PartUploadResult, ou leur espace de noms, ne sont
	 * cités que dans `includes/Backup/Upload/`. Une mention ailleurs, même dans un
	 * commentaire, est un branchement à justifier (phase 4).
	 */
	public function test_aucun_appelant_hors_du_dossier_upload(): void {
		$hors_upload = [];
		$dans_upload = [];
		$parcourus   = 0;
		foreach ( self::fichiers_php_du_plugin() as $relatif => $chemin ) {
			++$parcourus;
			if ( ! self::cite_l_envoi_par_parties( (string) file_get_contents( $chemin ) ) ) {
				continue;
			}
			if ( str_starts_with( $relatif, self::DOSSIER_UPLOAD ) ) {
				$dans_upload[] = $relatif;
			} elseif ( ! in_array( $relatif, self::APPELANTS_PERMIS, true ) ) {
				$hors_upload[] = $relatif;
			}
		}

		self::assertSame( [], $hors_upload, 'PartUploader est cité hors de ' . self::DOSSIER_UPLOAD . ' : aucun branchement avant la phase 4.' );
		// Le parcours n'est pas vide et la recherche reconnaît une citation : sans ces
		// deux contrôles, un chemin faux ou un motif cassé ferait passer le test à vide.
		self::assertGreaterThan( 50, $parcourus );
		self::assertContains( self::DOSSIER_UPLOAD . 'PartUploader.php', $dans_upload );
		self::assertContains( self::DOSSIER_UPLOAD . 'PartUploadResult.php', $dans_upload );
	}

	/**
	 * Settings::defaults() et les deux listes de commandes sont exactement celles
	 * d'origin/main : ni réglage, ni option, ni commande ajoutés par l'envoi par parties.
	 */
	public function test_aucun_reglage_ni_commande_ajoutes(): void {
		self::assertSame( self::REGLAGES_D_ORIGINE, Settings::defaults(), 'Settings::defaults() a changé : aucun réglage avant la phase 4.' );
		self::assertSame( self::COMMANDES_D_ORIGINE, CommandExecutor::ALLOWED, 'CommandExecutor::ALLOWED a changé : aucune commande avant la phase 4.' );
		self::assertSame( self::COMMANDES_SIGNEES_D_ORIGINE, CommandExecutor::SIGNED_ONLY, 'CommandExecutor::SIGNED_ONLY a changé : aucune commande avant la phase 4.' );
	}

	/** Le code cite-t-il une classe de l'envoi par parties, ou son espace de noms ? */
	private static function cite_l_envoi_par_parties( string $code ): bool {
		return str_contains( $code, 'PartUpload' ) || str_contains( $code, 'Backup\\Upload' );
	}

	/**
	 * Fichiers PHP chargés par WordPress : `includes/` en entier, plus le fichier
	 * principal et `uninstall.php`. Clés : chemin relatif à la racine, en `/`.
	 *
	 * @return array<string, string>
	 */
	private static function fichiers_php_du_plugin(): array {
		$racine   = str_replace( '\\', '/', dirname( __DIR__, 3 ) );
		$fichiers = [];
		foreach ( [ 'g2rd-connector.php', 'uninstall.php' ] as $fichier_racine ) {
			$fichiers[ $fichier_racine ] = $racine . '/' . $fichier_racine;
		}

		$iterateur = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $racine . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterateur as $fichier ) {
			if ( $fichier instanceof \SplFileInfo && 'php' === $fichier->getExtension() ) {
				$chemin = str_replace( '\\', '/', $fichier->getPathname() );

				$fichiers[ substr( $chemin, strlen( $racine ) + 1 ) ] = $chemin;
			}
		}

		ksort( $fichiers );
		return $fichiers;
	}
}
