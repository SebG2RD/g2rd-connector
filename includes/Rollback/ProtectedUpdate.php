<?php
/**
 * Mise à jour d'un plugin PROTÉGÉE par un point de restauration :
 *
 *   référence de santé → point de restauration → mise à jour → contrôle de santé
 *   → rollback automatique si le site a régressé.
 *
 * Tout tient dans la même requête : le processus PHP qui l'exécute a chargé
 * l'ancien code avant le remplacement des fichiers, il survit donc à un plugin
 * cassé — seul le loopback, qui est un autre processus, plante. C'est ce qui rend
 * le rollback automatique fiable sans dépendre de la plateforme, dont l'API REST
 * du site serait de toute façon morte.
 *
 * Un journal de transaction (UpdateTransaction) permet de rejouer une requête
 * morte en route : par le filet de shutdown, puis par le cron (recover()), ou au
 * plus tard par la mise à jour protégée suivante, qui la reprend avant d'ouvrir la
 * sienne.
 *
 * Résultat : la forme historique d'update_plugin, plus `outcome`, `health`,
 * `restore_point` (et `error_code` / `error` quand pertinent). Les cas prévus
 * (espace disque, rollback automatique…) sont des RÉSULTATS, pas des exceptions :
 * la plateforme les lit dans `outcome`.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\RestorePointPurgeJob;

final class ProtectedUpdate {

	public const OUTCOME_UPDATED              = 'updated';
	public const OUTCOME_NOT_UPDATED          = 'not_updated';
	public const OUTCOME_AUTO_ROLLED_BACK     = 'auto_rolled_back';
	public const OUTCOME_AUTO_ROLLBACK_FAILED = 'auto_rollback_failed';
	public const OUTCOME_UPDATE_FAILED        = 'update_failed';

	/**
	 * La transaction a été prise par une reprise pendant la mise à jour (étape de plus
	 * de 10 minutes, fichiers peut-être à moitié remplacés) : la reprise restaure
	 * l'ancienne version et consigne son propre résultat. La requête n'y touche plus.
	 */
	private const TAKEN_OVER_WHILE_UPDATING = 'protected update was taken over by a recovery while it was updating the plugin (a step took more than 10 minutes); the recovery puts the previous version back from the restore point and reports its own result';

	/**
	 * La transaction a été prise par une reprise pendant le contrôle de santé : la
	 * reprise ne touche pas aux fichiers (mise à jour terminée), et la requête ne
	 * restaure pas sans sa transaction. Le point est gardé pour un rollback manuel.
	 */
	private const TAKEN_OVER_BEFORE_ROLLBACK = 'protected update found the site broken after updating the plugin, but a recovery took over its transaction (a step took more than 10 minutes); the plugin was not rolled back automatically: its restore point is kept, roll it back from the platform';

	/**
	 * La transaction a été prise par une reprise AVANT le remplacement des fichiers
	 * (téléchargement, ou rafraîchissement des transients, de plus de 10 minutes) :
	 * Plugin_Upgrader s'est arrêté sans toucher à l'extension (cf. run()).
	 */
	private const TAKEN_OVER_BEFORE_INSTALL = 'protected update stopped before replacing the plugin files: its transaction was taken over by a recovery (a step took more than 10 minutes); the plugin was not updated, retry in a few minutes';

	/**
	 * Ajouté à l'erreur de la mise à jour elle-même quand une reprise a pris la
	 * transaction entre-temps : la plateforme reçoit aussi le résultat de la reprise,
	 * et doit pouvoir relier les deux.
	 */
	private const TAKEN_OVER_SUFFIX = ' (its transaction was taken over by a recovery, which reports its own result)';

	/**
	 * Détail du résultat `recovery_failed` quand les reprises précédentes sont toutes
	 * mortes en route (cf. recover()). `%1$d` : nombre de reprises, `%2$s` : ce qui est
	 * fait de l'extension.
	 */
	private const RECOVERY_GAVE_UP = 'recovery gave up after %1$d recovery attempts that did not finish (each one stopped while restoring the plugin from its restore point, probably on a fatal error or a time limit); the plugin was not restored and %2$s; check its files and the PHP error log, then roll it back from the platform: its restore point is kept';

	/**
	 * Ajouté à l'erreur d'une restauration qui a échoué quand l'extension, active avant,
	 * n'a pas été réactivée : elle ne s'est pas chargée sans erreur (cf. restore()).
	 */
	private const LEFT_INACTIVE = '; the plugin was left inactive: after this failed restore its files may be incomplete, and it is reactivated only if it loads without error, which it did not; check its files and the PHP error log, then roll it back again from the platform or reinstall it';

	/**
	 * Priorité du contrôle d'avant remplacement des fichiers sur `upgrader_pre_install` :
	 * avant Plugin_Upgrader::deactivate_plugin_before_upgrade() (10), qui laisse passer
	 * une WP_Error sans désactiver l'extension.
	 */
	private const PRE_INSTALL_PRIORITY = 9;

	/** Filet de shutdown déjà armé dans ce processus (cf. arm_shutdown_net()). */
	private static bool $shutdown_net_armed = false;

	/**
	 * Transactions (identité, cf. UpdateTransaction::identity()) dont ce processus a
	 * commencé le rollback automatique ou la reprise. L'activation de leur extension
	 * revient dès lors à restore() et à la reprise, plus à la mise à jour (cf. run(),
	 * droit de réactiver).
	 *
	 * @var array<string, bool>
	 */
	private static array $rollback_started = [];

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * @param string               $plugin_file Plugin déjà validé par CommandExecutor (installé).
	 * @param array<string, mixed> $payload     Options envoyées par la plateforme (kind, grace_seconds, keep_on_success, hold_max_seconds, max_total_bytes, disk_margin_bytes).
	 * @param callable(callable(): bool): array<string, mixed> $perform_upgrade La mise à jour historique (Plugin_Upgrader + réactivation), à qui run() passe le droit de réactiver l'extension (cf. plus bas).
	 * @return array<string, mixed>
	 * @throws \RuntimeException Échec de la mise à jour elle-même (comportement historique conservé).
	 */
	public function run( string $plugin_file, array $payload, callable $perform_upgrade ): array {
		// Filet de shutdown armé d'abord : il couvre aussi la reprise ci-dessous. Il
		// n'agit que sur la transaction que tient ce processus (cf. recover_on_shutdown()).
		self::arm_shutdown_net();

		// ── Reprise d'une mise à jour morte, AVANT d'ouvrir ──────────────────────
		// Une transaction morte que ni le filet de shutdown ni le contrôle du cron n'ont
		// encore reprise (requête tuée pendant la mise à jour : extension désactivée,
		// fichiers à moitié remplacés) serait sinon écrasée par open() : l'extension
		// resterait cassée, sans résultat pour la plateforme (incident du 2026-09-23).
		// Elle est reprise une fois, quelle que soit son extension, puis la nouvelle
		// mise à jour s'ouvre. Une reprise déjà en cours dans un autre processus fait
		// refuser l'ouverture ci-dessous.
		if ( self::recover( time() ) && function_exists( 'wp_clean_plugins_cache' ) ) {
			// Fichiers peut-être restaurés : la liste des extensions en cache (lue par
			// la commande) ne reflète plus le disque. `false` : le transient des mises
			// à jour reste (entrées des updaters tiers, cf. CommandExecutor).
			wp_clean_plugins_cache( false );
		}

		$now     = time();
		$s       = $this->services;
		$version = $this->installed_version( $plugin_file );

		if ( ! UpdateTransaction::open(
			[
				'plugin_file'    => $plugin_file,
				'version_before' => $version,
				'was_active'     => is_plugin_active( $plugin_file ),
				'network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
			],
			$now
		) ) {
			// Refus avant tout changement, déjà connu de la plateforme (échec de
			// l'élément, message affiché tel quel) : même début de phrase qu'avant.
			throw new \RuntimeException( UpdateTransaction::BUSY_MESSAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constante, texte fixe.
		}
		// Gardée après close() : le filet de shutdown de la mise à jour la consulte en fin
		// de requête (cf. plus bas, droit de réactiver).
		$identity = (string) UpdateTransaction::held_identity();
		// Filet du filet : un processus tué par le serveur ne passe pas par le
		// shutdown. La purge locale (qui reprend aussi les transactions mortes) ne
		// passant que deux fois par jour, un contrôle de reprise ponctuel (reprise
		// seule, sans purge) est programmé juste après le délai au-delà duquel cette
		// transaction pourra être déclarée morte.
		RestorePointPurgeJob::schedule_recovery_check( $now );

		// Chaque étape qui peut durer est suivie d'un rafraîchissement de la
		// transaction (step() ou touch()) : un contrôle de reprise qui tombe pendant
		// l'étape suivante (autre processus) ne prend pas cette requête vivante pour
		// morte, tant qu'aucune étape ne dépasse la durée supposée (cf.
		// RestorePointPurgeJob::RECOVERY_CHECK_DELAY). Au-delà, si un contrôle a fermé
		// ou réservé la transaction entre-temps, step() et touch() ne la recréent pas,
		// et la mise à jour s'arrête : avant de toucher à l'extension (avant la mise à
		// jour, ou juste avant le remplacement des fichiers, cf. PreInstallGuard), ou,
		// après la mise à jour, sans plus rien mesurer ni restaurer — la reprise s'en
		// charge (cf. plus bas et stop_taken_over()).

		// ── Référence de santé, AVANT tout changement ────────────────────────────
		$baseline = $s->health->measure();
		UpdateTransaction::touch();

		// ── Point de restauration ────────────────────────────────────────────────
		$keep    = ! empty( $payload['keep_on_success'] );
		$grace   = max( 0, (int) ( $payload['grace_seconds'] ?? 0 ) );
		// Un point retenu (échec, rollback) est supprimé par le cron au-delà de ce plafond.
		$hold_until = $now + (int) ( $payload['hold_max_seconds'] ?? RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
		$meta    = [
			'version'           => $version,
			'kind'              => (string) ( $payload['kind'] ?? RestorePointStore::KIND_CUSTOM ),
			'expires_at'        => $keep ? $now + $grace : $now,
			'hold'              => false,
			'max_total_bytes'   => (int) ( $payload['max_total_bytes'] ?? Snapshotter::DEFAULT_MAX_TOTAL_BYTES ),
			'disk_margin_bytes' => (int) ( $payload['disk_margin_bytes'] ?? Snapshotter::DEFAULT_DISK_MARGIN_BYTES ),
		];
		try {
			$point = $s->snapshotter->create( $plugin_file, $meta, $now );
		} catch ( RestorePointException $e ) {
			UpdateTransaction::close();
			// Refus AVANT toute mise à jour : rien n'a changé sur le site.
			return $this->legacy_shape( $plugin_file, $version ) + [
				'outcome'       => $e->error_code(),
				'error_code'    => $e->error_code(),
				'error'         => $e->getMessage(),
				'health'        => [ 'baseline' => $baseline ],
				'restore_point' => null,
			];
		}
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING, [ 'restore_point_id' => $point['id'] ] ) ) {
			// La transaction a été fermée, remplacée ou réservée par un autre processus
			// pendant la création du point (étape de plus de 10 minutes, prise pour
			// morte par un contrôle de reprise). Pas de mise à jour sans transaction :
			// si cette requête mourait en route, personne ne remettrait l'extension en
			// état. Rien n'a changé sur l'extension. Le point reste : son délai le fait
			// purger, et le retirer ici pourrait réécrire l'index des points en même
			// temps qu'une autre mise à jour.
			UpdateTransaction::close();
			throw new \RuntimeException( 'protected update stopped before updating the plugin: its transaction was closed by another process (a step took more than 10 minutes); the plugin was not updated, retry in a few minutes' );
		}
		// Les fichiers vont changer : le contrôle de reprise programmé à l'ouverture doit
		// être en attente. S'il a été perdu (liste des tâches réécrite en même temps par
		// un autre processus), une requête tuée maintenant ne serait reprise qu'à la purge
		// biquotidienne, jusqu'à 12 h plus tard. Lecture seule s'il est là.
		RestorePointPurgeJob::ensure_recovery_check( time() );

		// ── Mise à jour (chemin historique, inchangé) ────────────────────────────
		// Le rafraîchissement des transients, le téléchargement et la décompression
		// peuvent dépasser 10 minutes à eux seuls : une reprise prend alors la
		// transaction AVANT que les fichiers changent, et restaure l'ancienne version,
		// encore en place. Copier la nouvelle ensuite la laisserait installée sans
		// contrôle de santé (la reprise annonçant l'ancienne), ou déplacerait le même
		// dossier que la reprise en même temps. D'où un dernier contrôle juste avant le
		// remplacement des fichiers (cf. PreInstallGuard) : il rafraîchit la
		// transaction, ou arrête Plugin_Upgrader sans rien toucher si elle n'est plus
		// celle de cette requête.
		//
		// Droit de réactiver l'extension (réactivation nominale et filet de shutdown de
		// la mise à jour) : refusé tant qu'une reprise d'un autre processus tient cette
		// transaction. Elle a désactivé l'extension et extrait l'archive ; activate_plugin()
		// inclurait des fichiers à moitié extraits, en même temps qu'elle écrit
		// `active_plugins`. Elle réactive elle-même, à la fin de restore().
		//
		// Refusé aussi dès que ce processus a commencé le rollback automatique ou la
		// reprise de cette transaction (filet de shutdown d'une requête morte en route) :
		// restore() décide seul, alors, de l'activation. Après une restauration qui a
		// échoué, il laisse inactive une extension qui ne se charge pas ; le filet de la
		// mise à jour (force_reactivate()) la forcerait active en fin de requête.
		$guard          = new PreInstallGuard( $plugin_file, self::TAKEN_OVER_BEFORE_INSTALL );
		$may_reactivate = static fn (): bool => ! isset( self::$rollback_started[ $identity ] )
			&& ! UpdateTransaction::recovering_elsewhere( $identity, time() );
		add_filter( 'upgrader_pre_install', $guard, self::PRE_INSTALL_PRIORITY, 2 );
		try {
			$result = $perform_upgrade( $may_reactivate );
		} catch ( \Throwable $e ) {
			if ( $guard->stopped ) {
				$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_BEFORE_INSTALL );
			}
			// Lue avant close(), qui oublie la transaction tenue.
			$taken_over = null === UpdateTransaction::held();
			// WordPress ≥ 6.3 remet lui-même les fichiers d'origine quand l'upgrade échoue ;
			// le point est retenu jusqu'à résolution (design §5.6), l'erreur remonte comme avant.
			$s->store->hold( $point['id'], $hold_until );
			UpdateTransaction::close();
			if ( $taken_over ) {
				// Message déjà échappé par celui qui l'a levé (CommandExecutor), suffixe fixe.
				throw new \RuntimeException( $e->getMessage() . self::TAKEN_OVER_SUFFIX, 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- cf. ci-dessus.
			}
			throw $e;
		} finally {
			remove_filter( 'upgrader_pre_install', $guard, self::PRE_INSTALL_PRIORITY );
		}
		if ( $guard->stopped ) {
			// Plugin_Upgrader ne rend pas toujours l'erreur du contrôle (WordPress ne garde
			// le résultat d'install_package() qu'après une installation réussie) : même
			// arrêt que ci-dessus, rien n'a changé sur l'extension.
			$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_BEFORE_INSTALL );
		}

		if ( true !== ( $result['updated'] ?? false ) ) {
			if ( null === UpdateTransaction::held() ) {
				// Prise par une reprise pendant le remplacement des fichiers (après le
				// contrôle d'avant copie) : la version relue est peut-être déjà celle que
				// la reprise a restaurée. Son point lui sert : ni suppression, ni résultat
				// « not_updated ».
				$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_WHILE_UPDATING );
			}
			// Rien n'a changé (version inchangée, licence premium…) : le point n'a pas de raison d'être.
			$s->store->remove( $point['id'] );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_NOT_UPDATED,
				'health'        => [ 'baseline' => $baseline ],
				'restore_point' => null,
			];
		}

		// ── Contrôle de santé, sur le NOUVEAU code ───────────────────────────────
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_HEALTH ) ) {
			// Prise par une reprise pendant le remplacement des fichiers (après le
			// contrôle d'avant copie, étape d'origine `upgrading`) : elle restaure
			// l'ancienne version. Mesurer, rendre « updated » ou restaurer à notre tour
			// contredirait son résultat, ou déplacerait le même dossier qu'elle en même
			// temps.
			$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_WHILE_UPDATING );
		}
		$this->invalidate_opcache( $plugin_file );
		$after   = $s->health->measure();
		$verdict = HealthChecker::verdict( $baseline, $after );
		$health  = [
			'baseline' => $baseline,
			'after'    => $after,
			'verdict'  => $verdict,
		];

		if ( HealthChecker::BROKEN !== $verdict ) {
			if ( HealthChecker::HEALTHY === $verdict && ! $keep ) {
				// Plan sans délai de grâce : le site est sain, le point n'a plus d'utilité.
				$s->store->remove( $point['id'] );
				$point = null;
			} elseif ( HealthChecker::UNVERIFIABLE === $verdict && ! $keep ) {
				// Personne n'a pu vérifier : on garde le point le temps de la grâce, même sans plan (design D3).
				$s->store->update( $point['id'], [ 'expires_at' => $now + max( $grace, 72 * 3600 ) ] );
				$point = $s->store->get( $point['id'] );
			}
			// Un point plus ancien du même plugin n'a plus de raison d'être : un seul par plugin.
			$this->drop_older_points( $plugin_file, $point['id'] ?? null );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_UPDATED,
				'health'        => $health,
				'restore_point' => $this->public_point( $point ),
			];
		}

		// ── Régression prouvée : rollback automatique ────────────────────────────
		if ( ! UpdateTransaction::step( UpdateTransaction::STEP_ROLLING_BACK ) ) {
			// Prise par une reprise pendant la mesure (étape d'origine `health`) : elle
			// ne touche pas aux fichiers. Pas de restauration sans transaction pour
			// autant : si cette requête mourait en route, personne ne remettrait
			// l'extension en état, et la plateforme a pu passer à la commande suivante.
			//
			// Limite connue et acceptée (relecture du 2026-10-09) : une version mesurée
			// comme cassée reste alors en place, la reprise ne consigne
			// qu'`interrupted_unverified` et la plateforme reçoit l'erreur ci-dessous,
			// qui demande un rollback manuel. Il faut que la mesure d'après mise à jour
			// (deux sondes de 15 s au plus) dépasse 10 minutes. Attendre la fin de la
			// reprise puis rouvrir une transaction ferait rendre deux résultats
			// contradictoires (`interrupted_unverified` et `auto_rolled_back`) pour la
			// même mise à jour.
			$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_BEFORE_ROLLBACK );
		}
		$version_after = (string) ( $result['version_after'] ?? '' );
		// L'activation de l'extension revient désormais à restore() (cf. droit de réactiver).
		self::$rollback_started[ $identity ] = true;
		try {
			$this->restore( $plugin_file, $point, $version, $version_after, (bool) ( $result['was_active'] ?? false ), (bool) ( $result['network_active'] ?? false ) );
			$s->store->hold( $point['id'], $hold_until );
			UpdateTransaction::touch();
			$health['after_rollback'] = $s->health->measure();
			UpdateTransaction::close();
			// array_replace (pas `+`) : `updated` et `version_after` doivent refléter l'état
			// APRÈS rollback, pas celui de la mise à jour annulée.
			return array_replace(
				$result,
				[
					'updated'       => false,
					'version_after' => $version,
					'outcome'       => self::OUTCOME_AUTO_ROLLED_BACK,
					'reason'        => 'health_check_failed',
					'health'        => $health,
					'restore_point' => $this->public_point( $s->store->get( $point['id'] ) ),
					'rolled_back'   => [
						'from' => $version_after,
						'to'   => $version,
					],
				]
			);
		} catch ( \Throwable $e ) {
			$s->store->hold( $point['id'], $hold_until );
			UpdateTransaction::close();
			return $result + [
				'outcome'       => self::OUTCOME_AUTO_ROLLBACK_FAILED,
				'error_code'    => $e instanceof RestoreException ? $e->error_code() : RestoreException::FAILED,
				'error'         => $e->getMessage(),
				'health'        => $health,
				'restore_point' => $this->public_point( $s->store->get( $point['id'] ) ),
			];
		}
	}

	/**
	 * Restaure un plugin depuis un point : désactivation, fichiers, réactivation,
	 * garde anti-réinstallation, `.maintenance`. Partagé avec le rollback manuel.
	 *
	 * Réactivation (`$reactivate`) : garantie après une restauration réussie ; après un
	 * échec, seulement si l'extension se charge sans erreur, sinon elle reste inactive
	 * et le message de l'exception le dit (cf. LEFT_INACTIVE).
	 *
	 * @param array<string, mixed> $point
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	public function restore( string $plugin_file, array $point, string $expected_version, ?string $expected_current_version, bool $reactivate, bool $network = false ): array {
		$s    = $this->services;
		$path = $s->store->path_for( $point );
		if ( null === $path ) {
			throw RestoreException::integrity( 'restore point has no usable archive' );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// `$wp_filesystem` initialisé AVANT de toucher aux fichiers. Nous n'en avons
		// pas besoin — l'extraction passe par ZipArchive — mais beaucoup de code tiers
		// le suppose disponible dès qu'une extension bouge, ce qui est vrai dans
		// l'administration et FAUX dans une requête REST. Une seule de ces
		// suppositions suffit à faire tomber la restauration entière.
		self::ensure_filesystem();

		deactivate_plugins( $plugin_file, true );

		try {
			$restored = $s->restorer->restore_from_zip( $path, $plugin_file, (string) $point['sha256'], $expected_version, $expected_current_version );
		} catch ( \Throwable $e ) {
			// Restauration ÉCHOUÉE : le dossier n'est peut-être plus un tout cohérent. Le
			// restaurateur remet le dossier d'avant en place quand il le peut, mais ce
			// dossier peut lui-même être à moitié remplacé (reprise d'une mise à jour tuée
			// pendant la copie des fichiers), et la remise en place peut échouer. Forcer
			// `active_plugins` ferait alors tomber toutes les pages du site en « erreur
			// critique ». Réactivation par le bac à sable d'activate_plugin() seulement :
			// une extension qui ne se charge pas reste inactive, et l'erreur remontée à la
			// plateforme le dit.
			if ( $reactivate && ! self::reactivate_if_it_loads( $plugin_file, $network ) ) {
				throw self::left_inactive( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message déjà échappé par celui qui l'a levé, suffixe fixe.
			}
			throw $e;
		}

		// Restauration RÉUSSIE : l'ancienne version, complète et vérifiée, est en place.
		// Réactivation garantie, comme avant : laisser éteinte une extension saine sur un
		// site client est le pire résultat (incident du 2026-09-23). force_reactivate(),
		// pas try_activate() : hors du processus qui l'avait chargée (reprise par le cron
		// ou par la mise à jour suivante), le bac à sable peut refuser une extension saine
		// (liste des extensions en cache, lue pendant que la mise à jour morte avait
		// retiré ses fichiers ; code qui suppose l'administration), alors que la voie
		// forcée la rallume telle qu'elle tournait avant la mise à jour.
		if ( $reactivate ) {
			CommandExecutor::force_reactivate( $plugin_file, $network );
		}

		if ( null !== $expected_current_version && '' !== $expected_current_version ) {
			AutoUpdateGuard::block( $plugin_file, $expected_current_version );
		}
		$this->clear_maintenance_flag();
		$this->invalidate_opcache( $plugin_file );

		return $restored;
	}

	/**
	 * Reprise d'une transaction interrompue (shutdown, cron, ou mise à jour suivante) :
	 * si les fichiers peuvent être dans un état intermédiaire, on restaure le point ;
	 * sinon on consigne seulement ce qui s'est passé. Toujours silencieuse.
	 *
	 * La transaction est d'abord réservée (UpdateTransaction::reserve()) : une seule
	 * reprise à la fois, et aucune mise à jour ne s'ouvre par-dessus pendant la
	 * restauration. Une transaction déjà réservée par un autre processus, ou changée
	 * depuis sa lecture, n'est pas reprise ici.
	 *
	 * Une reprise par transaction : si close() n'a pas pu la retirer (delete_option()
	 * en échec), la reprise est tracée (UpdateTransaction::RECOVERY_TRACE_KEY) et les
	 * passages suivants ne font que retenter la fermeture — sans quoi chacun
	 * restaurerait l'extension à nouveau et consignerait un nouveau résultat.
	 *
	 * Au-delà de UpdateTransaction::MAX_RECOVERY_ATTEMPTS reprises commencées sur la
	 * même transaction (toutes mortes en route, sans résultat), la reprise ne restaure
	 * plus : elle consigne `recovery_failed`, ferme la transaction, puis, si l'extension
	 * était active, la réactive seulement si elle se charge sans erreur (sinon elle
	 * reste inactive, cf. CommandExecutor::try_activate()).
	 *
	 * @param bool $force Filet de shutdown : reprend la transaction que tient ce
	 *                    processus, même fraîche — et aucune autre.
	 * @return bool Vrai si une transaction a été reprise (fichiers peut-être restaurés).
	 */
	public static function recover( int $now, bool $force = false ): bool {
		$txn = UpdateTransaction::current();
		if ( null === $txn ) {
			return false;
		}

		// Avant le contrôle de fraîcheur : une transaction réservée puis tracée (close()
		// en échec) est fraîche, mais sa reprise est faite.
		if ( UpdateTransaction::recovery_already_attempted( $txn ) ) {
			UpdateTransaction::discard( $txn );
			return false;
		}

		if ( ! $force && ! UpdateTransaction::is_stale( $txn, $now ) ) {
			return false;
		}

		$reserved = UpdateTransaction::reserve( $txn, $now, $force );
		if ( null === $reserved ) {
			return false;
		}
		// Filet de shutdown d'une mise à jour morte en route : son propre filet (celui de
		// perform_plugin_upgrade(), qui passe après celui-ci) ne réactive plus l'extension,
		// la reprise en décide seule (cf. run(), droit de réactiver).
		self::$rollback_started[ UpdateTransaction::identity( $reserved ) ] = true;

		// La reprise elle-même peut mourir en route (erreur fatale, délai dépassé
		// pendant l'extraction, processus tué) : extension désactivée, dossier peut-être
		// mis de côté, transaction à l'étape `recovering`. Menée par le cron, elle n'a ni
		// le filet de run() ni de contrôle en attente (wp-cron.php retire l'événement
		// avant de l'exécuter) : rien ne la reprendrait avant la purge biquotidienne.
		// D'où, AVANT toute restauration, le filet de shutdown (il reprend la
		// réservation que tient ce processus) et un contrôle : la réservation date de
		// `$now`, il la verra morte et la reprendra (cf. `recovering_from`). Une fois la
		// reprise faite, ce contrôle ne trouve plus rien à faire.
		if ( ! $force ) {
			self::arm_shutdown_net();
		}
		try {
			RestorePointPurgeJob::ensure_recovery_check( $now );
		} catch ( \Throwable $e ) {
			// Précaution (code tiers branché sur la planification) : elle ne doit
			// jamais empêcher la reprise elle-même.
			unset( $e );
		}

		$plugin_file = (string) $reserved['plugin_file'];
		$point_id    = (string) ( $reserved['restore_point_id'] ?? '' );
		$outcome     = [
			'plugin_file'      => $plugin_file,
			'restore_point_id' => '' !== $point_id ? $point_id : null,
			// Ce que faisait la mise à jour quand elle est morte (pas l'étape de reprise).
			'step'             => $reserved['recovering_from'] ?? $reserved['step'],
		];

		// Réactivation sans restauration, une fois le résultat consigné (cf. plus bas).
		$try_reactivate = false;
		try {
			if ( UpdateTransaction::files_may_be_dirty( $reserved ) && '' !== $point_id ) {
				$services = Services::make();
				$point    = $services->store->get( $point_id );
				if ( null === $point ) {
					$outcome['outcome'] = 'recovered_no_restore_point';
				} elseif ( UpdateTransaction::recovery_attempts( $reserved ) > UpdateTransaction::MAX_RECOVERY_ATTEMPTS ) {
					// Les reprises précédentes sont toutes mortes en route, sans doute sur la
					// même erreur fatale (code tiers lancé par la désactivation, archive qui
					// fait planter l'extraction) : leur `finally` ne s'est pas exécuté, rien
					// n'a été consigné, et l'extension est restée désactivée. Restaurer encore
					// rejouerait la même fin, toutes les 11 minutes environ. On s'arrête :
					// résultat explicite, point gardé pour un rollback manuel, extension
					// réactivée si elle était active ET se charge sans erreur.
					$try_reactivate     = ! empty( $reserved['was_active'] );
					$outcome['outcome'] = 'recovery_failed';
					$outcome['detail']  = sprintf(
						self::RECOVERY_GAVE_UP,
						UpdateTransaction::recovery_attempts( $reserved ) - 1, // Celle-ci exclue.
						$try_reactivate ? 'is reactivated only if it loads without error (otherwise it stays inactive)' : 'is left inactive, as it was before the update'
					);
					$services->store->hold( $point_id, $now + RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
				} else {
					( new self( $services ) )->restore( $plugin_file, $point, (string) $point['version'], null, ! empty( $reserved['was_active'] ), ! empty( $reserved['network_active'] ) );
					$services->store->hold( $point_id, $now + RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS );
					$outcome['outcome'] = 'recovered_rolled_back';
				}
			} else {
				// Rien à restaurer : la mise à jour a été interrompue avant de toucher à
				// l'extension (étape `snapshot`), ou après la mise à jour, santé non
				// contrôlée (`health`) — ou, transaction d'une version d'avant, sans point.
				// `step` dit laquelle. La plateforme ne lit aujourd'hui que `outcome` et
				// l'affiche comme « mise à jour appliquée, non vérifiée », `snapshot`
				// compris : la distinction attend qu'elle lise `step` (un nouveau résultat
				// lui serait inconnu).
				$outcome['outcome'] = 'interrupted_unverified';
			}
		} catch ( \Throwable $e ) {
			$outcome['outcome'] = 'recovery_failed';
			// Abandon (ci-dessus) : son explication reste, l'erreur du point la complète.
			$outcome['detail'] = isset( $outcome['detail'] ) ? $outcome['detail'] . ' (' . $e->getMessage() . ')' : $e->getMessage();
		}

		PendingOutcomes::add( $outcome, $now );
		UpdateTransaction::close();

		// Trace écrite seulement si la transaction est toujours là : le cas courant
		// (fermeture réussie) ne coûte aucune écriture de plus. Et seulement si c'est
		// encore NOTRE réservation (même identité, même jeton) : une restauration de
		// plus de 10 minutes a pu être reprise par une autre reprise, encore en cours.
		// La tracer la ferait passer pour faite : une mise à jour pourrait s'ouvrir
		// par-dessus, ou un autre passage la retirer pendant sa restauration.
		$left = UpdateTransaction::current();
		if ( null !== $left && UpdateTransaction::same_reservation( $left, $reserved ) ) {
			UpdateTransaction::record_recovery_attempt( $reserved, (string) $outcome['outcome'], $now );
		}

		if ( $try_reactivate ) {
			// APRÈS le résultat, la fermeture et la trace : si la réactivation mourait à
			// son tour (erreur fatale non rattrapable au chargement de l'extension), la
			// plateforme est déjà prévenue, et aucune reprise ne rejouera cette fin.
			//
			// Par le bac à sable d'activate_plugin() seulement, jamais par une écriture
			// forcée de `active_plugins` (force_reactivate()) : les fichiers sont ici les
			// plus douteux (reprises mortes pendant la restauration, dossier peut-être à
			// moitié extrait). Une extension qui ne se charge pas reste inactive : le site
			// reste debout, au lieu de tomber en « erreur critique » sur toutes ses pages.
			//
			// `$wp_filesystem` d'abord, comme restore() : hors de l'administration (cron,
			// REST), une extension saine dont le fichier principal s'en sert lèverait une
			// erreur au chargement et resterait inactive sans raison (cf. 2026-09-23).
			try {
				self::ensure_filesystem();
				CommandExecutor::try_activate( $plugin_file, ! empty( $reserved['network_active'] ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return true;
	}

	/**
	 * Filet de shutdown : si la transaction que tient ce processus est encore ouverte
	 * à la fin du cycle PHP, la requête est morte en route (fatale, délai dépassé). On
	 * rejoue tout de suite. Jamais la transaction d'une autre mise à jour, ni celle
	 * qu'une reprise a réservée entre-temps : le cron et la mise à jour suivante s'en
	 * chargent.
	 */
	public static function recover_on_shutdown(): void {
		if ( null === UpdateTransaction::held() ) {
			return;
		}
		self::recover( time(), true );
	}

	/**
	 * Arme le filet de shutdown, une fois par processus : il reprend la transaction
	 * que tient le processus à la fin du cycle PHP, qu'elle ait été ouverte (run())
	 * ou réservée (recover()). Sans transaction tenue, il ne fait rien (aucune requête).
	 */
	private static function arm_shutdown_net(): void {
		if ( self::$shutdown_net_armed ) {
			return;
		}
		register_shutdown_function( [ self::class, 'recover_on_shutdown' ] );
		self::$shutdown_net_armed = true;
	}

	/**
	 * Arrêt d'une mise à jour dont une reprise a pris la transaction, juste avant le
	 * remplacement des fichiers ou après la mise à jour : plus rien n'est copié,
	 * mesuré ni restauré ici, la reprise s'en charge et consigne son résultat. Le
	 * point est retenu (la reprise peut en avoir besoin, ou un rollback manuel), la
	 * transaction n'est pas retirée (close() ne retire que la sienne).
	 *
	 * @throws \RuntimeException Toujours : la plateforme reçoit un échec explicite.
	 */
	private function stop_taken_over( string $point_id, int $hold_until, string $message ): never {
		$this->services->store->hold( $point_id, $hold_until );
		UpdateTransaction::close();
		throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- constantes, texte fixe.
	}

	private function installed_version( string $plugin_file ): string {
		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function legacy_shape( string $plugin_file, string $version ): array {
		return [
			'updated'        => false,
			'file'           => $plugin_file,
			'version_before' => $version,
			'version_after'  => $version,
			'was_active'     => is_plugin_active( $plugin_file ),
			'reactivated'    => is_plugin_active( $plugin_file ),
			'reason'         => 'snapshot_refused',
		];
	}

	private function drop_older_points( string $plugin_file, ?string $keep_id ): void {
		foreach ( $this->services->store->for_plugin( $plugin_file ) as $id => $record ) {
			if ( $id !== $keep_id && empty( $record['hold'] ) ) {
				$this->services->store->remove( $id );
			}
		}
	}

	/**
	 * @param array<string, mixed>|null $point
	 * @return array<string, mixed>|null Ce que la plateforme a besoin de connaître.
	 */
	private function public_point( ?array $point ): ?array {
		if ( null === $point ) {
			return null;
		}
		return [
			'id'         => $point['id'],
			'version'    => $point['version'],
			'sha256'     => $point['sha256'],
			'size'       => $point['size'],
			'created_at' => $point['created_at'],
			'expires_at' => $point['expires_at'],
			'hold'       => (bool) $point['hold'],
		];
	}

	private function invalidate_opcache( string $plugin_file ): void {
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			$relative = '.' !== dirname( $plugin_file ) ? dirname( $plugin_file ) : '';
			wp_opcache_invalidate_directory( rtrim( $this->services->plugins_root . '/' . $relative, '/' ) );
		}
	}

	private function clear_maintenance_flag(): void {
		$flag = rtrim( (string) ABSPATH, '/\\' ) . '/.maintenance';
		if ( file_exists( $flag ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fichier posé par WP_Upgrader, retiré comme il le ferait lui-même.
			unlink( $flag );
		}
	}

	/**
	 * Réactivation après une restauration qui a échoué : par le bac à sable
	 * d'activate_plugin() seulement (CommandExecutor::try_activate()), jamais par une
	 * écriture forcée de `active_plugins`. `$wp_filesystem` d'abord, comme à l'abandon
	 * d'une reprise : hors de l'administration (cron, REST), une extension saine qui
	 * s'en sert au chargement lèverait une erreur et resterait inactive sans raison.
	 *
	 * @return bool Vrai si l'extension est active en sortie.
	 */
	private static function reactivate_if_it_loads( string $plugin_file, bool $network ): bool {
		try {
			self::ensure_filesystem();
			return CommandExecutor::try_activate( $plugin_file, $network );
		} catch ( \Throwable $e ) {
			// Précaution : l'erreur de la restauration doit remonter, pas celle-ci.
			unset( $e );
			return false;
		}
	}

	/**
	 * L'erreur d'une restauration qui a échoué, complétée : l'extension est restée
	 * inactive. Même classe et même code pour une RestoreException (la plateforme lit
	 * `error_code`) ; toute autre erreur devient une RuntimeException qui la porte.
	 */
	private static function left_inactive( \Throwable $e ): \Throwable {
		if ( $e instanceof RestoreException ) {
			return $e->with_detail( self::LEFT_INACTIVE );
		}
		// Message déjà échappé par celui qui l'a levé, suffixe fixe.
		return new \RuntimeException( $e->getMessage() . self::LEFT_INACTIVE, 0, $e );
	}

	/**
	 * Renseigne `$wp_filesystem`, que WordPress n'initialise pas de lui-même hors de
	 * l'administration.
	 *
	 * Le connecteur n'en a pas l'usage : l'extraction passe par ZipArchive. Mais dès
	 * qu'une extension bouge, du code tiers se réveille — vérificateurs de mise à
	 * jour, caches, sauvegardes de réglages — et beaucoup écrit `$wp_filesystem->…`
	 * sans vérifier, parce que la supposition tient toujours dans `wp-admin`. Dans
	 * une requête REST elle ne tient pas, et une seule de ces lignes fait tomber la
	 * restauration entière.
	 *
	 * Volontairement silencieuse : c'est une précaution, pas une dépendance. Si
	 * l'initialisation échoue, la restauration doit continuer — elle n'en a pas
	 * besoin pour son propre travail.
	 */
	private static function ensure_filesystem(): void {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			if ( ! defined( 'ABSPATH' ) ) {
				return;
			}
			// `is_readable` avant `require_once` : l'échec d'un require est une erreur
			// fatale que try/catch ne rattrape pas. Une précaution ne doit jamais
			// pouvoir casser ce qu'elle protège.
			$file = rtrim( (string) ABSPATH, '/\\' ) . '/wp-admin/includes/file.php';
			if ( ! is_readable( $file ) ) {
				return;
			}
			require_once $file;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			return;
		}

		try {
			WP_Filesystem();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
