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
	 * Même ajout quand l'extension n'a pas pu être essayée : le processus avait déjà
	 * inclus son fichier principal (requête où elle était active au démarrage : rollback
	 * automatique, son filet de shutdown, rollback manuel), et le bac à sable ne le
	 * rechargerait pas (cf. CommandExecutor::try_activate()). Décision du 2026-10-10 :
	 * elle reste désactivée.
	 */
	private const LEFT_INACTIVE_NOT_CHECKED = '; the plugin was left inactive: after this failed restore its files may be incomplete, and it is reactivated only if it loads without error, which could not be checked because its code was already loaded earlier in this request; check its files and the PHP error log, then roll it back again from the platform or reinstall it';

	/**
	 * Ce qu'ajoute à l'erreur d'une restauration manquée chaque issue de la réactivation
	 * par le bac à sable (cf. restore_archive()) : rien quand l'extension est active.
	 */
	private const LEFT_INACTIVE_DETAILS = [
		CommandExecutor::ACTIVATION_LOAD_ERROR     => self::LEFT_INACTIVE,
		CommandExecutor::ACTIVATION_INVALID        => self::LEFT_INACTIVE,
		CommandExecutor::ACTIVATION_ALREADY_LOADED => self::LEFT_INACTIVE_NOT_CHECKED,
	];

	/**
	 * Ajouté à l'erreur d'un rollback manuel qui a échoué une fois le dossier mis de côté,
	 * quand le restaurateur a remis en place, tel quel, le dossier qui tournait au
	 * démarrage de la commande : l'extension est réactivée comme avant (cf.
	 * restore_archive()).
	 */
	private const PUT_BACK_AS_BEFORE = '; the plugin was put back as it was before this rollback: the files it had when the rollback started were restored unchanged and it was reactivated; fix the cause of the error, then retry the rollback from the platform';

	/**
	 * Issue de la réactivation (cf. reactivation(), champ `reactivation` des résultats
	 * `recovery_failed`) quand l'extension était inactive avant : elle n'est pas essayée,
	 * elle reste inactive. Les autres issues : CommandExecutor::ACTIVATION_*.
	 */
	public const REACTIVATION_AS_BEFORE = 'left_inactive_as_before';

	/**
	 * Ajouté au détail d'une reprise dont la restauration a échoué, quand l'extension
	 * était inactive avant la mise à jour (cf. recover()).
	 */
	private const LEFT_INACTIVE_AS_BEFORE_UPDATE = '; the plugin was inactive before the update and stays inactive; check its files, then roll it back from the platform: its restore point is kept';

	/**
	 * Abandon d'une reprise (RECOVERY_GAVE_UP, `%2$s`) : ce qui est fait de l'extension,
	 * consigné AVANT l'essai de réactivation (une erreur fatale peut l'interrompre), puis
	 * remplacé par ce qui s'est réellement passé (cf. GAVE_UP_OUTCOMES).
	 */
	private const GAVE_UP_PENDING = 'is reactivated only if it loads without error (otherwise it stays inactive)';

	/** Abandon d'une reprise, extension inactive avant la mise à jour (RECOVERY_GAVE_UP, `%2$s`). */
	private const GAVE_UP_AS_BEFORE = 'is left inactive, as it was before the update';

	/**
	 * Abandon d'une reprise : ce qui s'est réellement passé à l'essai de réactivation, par
	 * issue (RECOVERY_GAVE_UP, `%2$s`).
	 */
	private const GAVE_UP_OUTCOMES = [
		CommandExecutor::ACTIVATION_ACTIVE         => 'was reactivated: it loads without error, but its files may still be those of the interrupted update',
		CommandExecutor::ACTIVATION_LOAD_ERROR     => 'was left inactive: it raised an error while loading, so its files are probably incomplete',
		CommandExecutor::ACTIVATION_ALREADY_LOADED => 'was left inactive: whether it loads without error could not be checked, because its code was already loaded earlier in this request',
		CommandExecutor::ACTIVATION_INVALID        => 'was left inactive: WordPress refused to activate it (main file missing or plugin header unreadable)',
	];

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

	/**
	 * Transactions (identité) que ce processus a fermées lui-même alors qu'elles étaient
	 * encore les siennes : aucune reprise d'un autre processus ne les avait prises. Le
	 * filet de shutdown de la mise à jour ne réactive l'extension qu'après une de ces
	 * fermetures, ou tant que la transaction est encore la sienne (cf. may_reactivate()).
	 *
	 * @var array<string, bool>
	 */
	private static array $closed_here = [];

	/**
	 * Ce que la dernière restauration manquée de cette instance a fait de l'extension
	 * (cf. reactivation()).
	 */
	private ?string $reactivation = null;

	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Après une restauration manquée (restore(), restore_archive()) : ce qui a été fait de
	 * l'extension. CommandExecutor::ACTIVATION_ACTIVE (active en sortie),
	 * CommandExecutor::ACTIVATION_LOAD_ERROR, ACTIVATION_ALREADY_LOADED, ACTIVATION_INVALID
	 * (laissée inactive, et pourquoi), ou REACTIVATION_AS_BEFORE (inactive avant, pas
	 * essayée). Null avant tout échec, ou pour un refus sans archive.
	 */
	public function reactivation(): ?string {
		return $this->reactivation;
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
		// Active pour tout le réseau (multisite) : gardé pour le rollback automatique.
		// perform_plugin_upgrade() ne le rend pas dans son résultat (forme historique).
		$network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );

		if ( ! UpdateTransaction::open(
			[
				'plugin_file'    => $plugin_file,
				'version_before' => $version,
				'was_active'     => is_plugin_active( $plugin_file ),
				'network_active' => $network_active,
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
		// la mise à jour, cf. may_reactivate()) : seulement tant que la transaction est
		// encore celle de cette requête, ou après que la requête l'a fermée elle-même.
		// Jamais après une reprise faite par un autre processus (décision du
		// 2026-10-10), en cours ou finie : elle a désactivé l'extension, extrait
		// l'archive, et décide seule de la réactiver. Si sa restauration a échoué et
		// qu'elle a laissé inactive une extension qui ne se charge pas, la forcer active
		// ici (force_reactivate()) ferait tomber toutes les pages du site en « erreur
		// critique ».
		//
		// Refusé aussi dès que ce processus a commencé le rollback automatique ou la
		// reprise de cette transaction (filet de shutdown d'une requête morte en route) :
		// restore() décide seul, alors, de l'activation.
		$guard          = new PreInstallGuard( $plugin_file, self::TAKEN_OVER_BEFORE_INSTALL );
		$may_reactivate = static fn (): bool => self::may_reactivate( $identity );
		add_filter( 'upgrader_pre_install', $guard, self::PRE_INSTALL_PRIORITY, 2 );
		try {
			$result = $perform_upgrade( $may_reactivate );
		} catch ( \Throwable $e ) {
			if ( $guard->stopped ) {
				$this->stop_taken_over( $point['id'], $hold_until, self::TAKEN_OVER_BEFORE_INSTALL );
			}
			// WordPress ≥ 6.3 remet lui-même les fichiers d'origine quand l'upgrade échoue ;
			// le point est retenu jusqu'à résolution (design §5.6), l'erreur remonte comme avant.
			$s->store->hold( $point['id'], $hold_until );
			if ( ! self::close_own( $identity ) ) {
				// Prise par une reprise entre-temps : elle consigne son propre résultat.
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
			self::close_own( $identity );
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
			self::close_own( $identity );
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
			// Dossier remis en place après un échec : la nouvelle version, mesurée cassée.
			$this->restore( $plugin_file, $point, $version, $version_after, (bool) ( $result['was_active'] ?? false ), $network_active, false );
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
	 * garde anti-réinstallation, `.maintenance`. Partagé avec le rollback manuel (cf.
	 * restore_archive(), qui sert aussi à l'archive téléchargée).
	 *
	 * @param array<string, mixed> $point
	 * @param bool                 $previous_folder_trusted Cf. restore_archive().
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	public function restore( string $plugin_file, array $point, string $expected_version, ?string $expected_current_version, bool $reactivate, bool $network, bool $previous_folder_trusted ): array {
		$path = $this->services->store->path_for( $point );
		if ( null === $path ) {
			$this->reactivation = null;
			throw RestoreException::integrity( 'restore point has no usable archive' );
		}
		return $this->restore_archive( $path, $plugin_file, (string) $point['sha256'], $expected_version, $expected_current_version, $reactivate, $network, $previous_folder_trusted );
	}

	/**
	 * Restaure un plugin depuis une archive (point local, ou archive téléchargée par le
	 * rollback manuel) : désactivation, fichiers, réactivation, garde anti-réinstallation,
	 * `.maintenance`.
	 *
	 * Désactivation une fois le dossier actuel mis de côté, pas avant : un refus d'avant
	 * (intégrité, version_drift, dossier impossible à déplacer) laisse l'extension telle
	 * qu'elle était, active et intacte.
	 *
	 * Réactivation (`$reactivate`) : garantie après une restauration réussie. Après un
	 * échec :
	 *  - `$previous_folder_trusted` (rollback manuel) et dossier d'avant remis en place
	 *    tel quel par le restaurateur : ce dossier est celui qui tournait au démarrage de
	 *    la commande. Revenir à l'état d'avant la commande n'est jamais pire que cet état :
	 *    l'extension est réactivée comme avant (force_reactivate()), et l'erreur le dit
	 *    (cf. PUT_BACK_AS_BEFORE) ;
	 *  - sinon (rollback automatique, reprise : le dossier remis en place est la nouvelle
	 *    version mesurée cassée, ou des fichiers à moitié copiés ; ou remise en place
	 *    manquée), seulement si l'extension se charge sans erreur, sinon elle reste
	 *    inactive et le message de l'exception le dit (cf. LEFT_INACTIVE). Jamais dans un
	 *    processus qui avait déjà chargé son fichier principal (requête où elle était
	 *    active au démarrage : rollback automatique, filet de shutdown de la mise à jour,
	 *    rollback manuel) : rien ne peut y vérifier qu'elle se charge, elle reste
	 *    désactivée (décision du 2026-10-10, cf. LEFT_INACTIVE_NOT_CHECKED).
	 *
	 * Ce qui a été fait de l'extension après un échec est lisible ensuite par
	 * reactivation().
	 *
	 * @param string $sha256                  Empreinte attendue de l'archive (chaîne vide = non vérifiée).
	 * @param bool   $previous_folder_trusted Le dossier en place au démarrage est sain par définition :
	 *                                        vrai pour le rollback manuel, faux pour le rollback
	 *                                        automatique et les reprises.
	 * @return array{version_before:string, version_after:string}
	 * @throws RestoreException
	 */
	public function restore_archive( string $zip_path, string $plugin_file, string $sha256, string $expected_version, ?string $expected_current_version, bool $reactivate, bool $network, bool $previous_folder_trusted ): array {
		$s                  = $this->services;
		$this->reactivation = null;

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// `$wp_filesystem` initialisé AVANT de toucher aux fichiers. Nous n'en avons
		// pas besoin — l'extraction passe par ZipArchive — mais beaucoup de code tiers
		// le suppose disponible dès qu'une extension bouge, ce qui est vrai dans
		// l'administration et FAUX dans une requête REST. Une seule de ces
		// suppositions suffit à faire tomber la restauration entière.
		self::ensure_filesystem();

		try {
			// Désactivation par le restaurateur, une fois le dossier mis de côté (et avant
			// l'extraction). Plus tôt, un refus d'avant (version_drift d'un rollback manuel,
			// par exemple) laisserait éteinte une extension intacte : dans une requête qui
			// l'a chargée au démarrage, le bac à sable ne peut plus la rallumer.
			$restored = $s->restorer->restore_from_zip(
				$zip_path,
				$plugin_file,
				$sha256,
				$expected_version,
				$expected_current_version,
				static function () use ( $plugin_file ): void {
					deactivate_plugins( $plugin_file, true );
				}
			);
		} catch ( \Throwable $e ) {
			if ( ! $reactivate ) {
				// Inactive avant : elle le reste, quel que soit l'échec.
				$this->reactivation = self::REACTIVATION_AS_BEFORE;
				throw $e;
			}
			if ( $previous_folder_trusted && $e instanceof RestoreException && $e->previous_folder_restored() && self::force_reactivate_quietly( $plugin_file, $network ) ) {
				// Rollback manuel : le dossier remis en place est celui qui tournait au
				// démarrage de la commande, intact. L'état d'avant la commande est rétabli.
				$this->reactivation = CommandExecutor::ACTIVATION_ACTIVE;
				throw $e->with_detail( self::PUT_BACK_AS_BEFORE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message déjà échappé par celui qui l'a levé, suffixe fixe.
			}
			// Restauration ÉCHOUÉE, dossier douteux : il n'est peut-être plus un tout
			// cohérent. Le restaurateur remet le dossier d'avant en place quand il le peut,
			// mais ce dossier peut lui-même être à moitié remplacé (reprise d'une mise à
			// jour tuée pendant la copie des fichiers), ou être la nouvelle version mesurée
			// cassée (rollback automatique), et la remise en place peut échouer. Forcer
			// `active_plugins` ferait alors tomber toutes les pages du site en « erreur
			// critique ». Réactivation par le bac à sable d'activate_plugin() seulement :
			// une extension qui ne se charge pas, ou qui ne peut pas être essayée ici,
			// reste inactive, et l'erreur remontée à la plateforme dit pourquoi. Refus
			// d'avant la mise de côté : l'extension n'a pas été désactivée, rien à faire.
			$this->reactivation = self::reactivate_if_it_loads( $plugin_file, $network );
			$left_inactive      = self::LEFT_INACTIVE_DETAILS[ $this->reactivation ] ?? null;
			if ( null !== $left_inactive ) {
				throw self::left_inactive( $e, $left_inactive ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message déjà échappé par celui qui l'a levé, suffixe fixe.
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
	 * reste inactive, cf. CommandExecutor::attempt_activation()), et complète le résultat
	 * consigné de ce qui s'est réellement passé (champ `reactivation`, détail).
	 *
	 * Tout `recovery_failed` dit ce qu'est devenue l'extension : champ `reactivation`
	 * (cf. reactivation()) et détail en anglais (cause exacte, action à mener).
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
		// Un point retenu (reprise abandonnée, manquée ou réussie) est supprimé par le cron
		// au-delà de ce plafond, comme après un rollback automatique manqué.
		$hold_until = $now + RestorePointPurgeJob::DEFAULT_HOLD_MAX_SECONDS;
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
					// réactivée si elle était active ET se charge sans erreur. Ce qui s'est
					// réellement passé complète le résultat après l'essai (cf. plus bas).
					$try_reactivate     = ! empty( $reserved['was_active'] );
					$outcome['outcome'] = 'recovery_failed';
					$outcome['detail']  = sprintf(
						self::RECOVERY_GAVE_UP,
						UpdateTransaction::recovery_attempts( $reserved ) - 1, // Celle-ci exclue.
						$try_reactivate ? self::GAVE_UP_PENDING : self::GAVE_UP_AS_BEFORE
					);
					if ( ! $try_reactivate ) {
						$outcome['reactivation'] = self::REACTIVATION_AS_BEFORE;
					}
					// Pas en silence : une rétention manquée complète le détail, qui dit le
					// point gardé (cf. plus bas).
					$services->store->hold( $point_id, $hold_until );
				} else {
					$updater = new self( $services );
					try {
						$updater->restore( $plugin_file, $point, (string) $point['version'], null, ! empty( $reserved['was_active'] ), ! empty( $reserved['network_active'] ), false );
					} catch ( \Throwable $e ) {
						// Restauration manquée : le point est retenu quand même, comme après un
						// rollback automatique manqué (run()). Sans cela il garderait sa date
						// d'expiration et la purge le supprimerait, alors qu'il reste le seul
						// moyen de remettre l'ancienne version à la main. En silence : l'erreur
						// de la restauration, qui dit ce qu'est devenue l'extension, doit
						// remonter, pas celle de la rétention.
						$services->store->hold_quietly( $point_id, $hold_until );
						$reactivation = $updater->reactivation();
						if ( null !== $reactivation ) {
							$outcome['reactivation'] = $reactivation;
						}
						if ( self::REACTIVATION_AS_BEFORE === $reactivation ) {
							// Rien ne le disait : inactive avant, elle n'a pas été essayée.
							throw self::left_inactive( $e, self::LEFT_INACTIVE_AS_BEFORE_UPDATE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message déjà échappé par celui qui l'a levé, suffixe fixe.
						}
						throw $e;
					}
					// En silence : l'ancienne version est en place, une rétention manquée ne
					// doit pas faire consigner `recovery_failed`.
					$services->store->hold_quietly( $point_id, $hold_until );
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
			$reactivation = self::reactivate_if_it_loads( $plugin_file, ! empty( $reserved['network_active'] ) );

			// Puis le résultat déjà consigné est complété de ce qui s'est réellement passé :
			// extension rallumée, ou laissée inactive, et pourquoi (sans quoi il ne disait
			// que « réactivée seulement si elle se charge »).
			try {
				PendingOutcomes::complete(
					$outcome,
					$now,
					[
						'reactivation' => $reactivation,
						'detail'       => str_replace( self::GAVE_UP_PENDING, self::GAVE_UP_OUTCOMES[ $reactivation ] ?? self::GAVE_UP_PENDING, (string) $outcome['detail'] ),
					]
				);
			} catch ( \Throwable $e ) {
				// Précaution : le résultat consigné reste, tel quel.
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
	 * Droit de réactiver l'extension d'une mise à jour protégée (réactivation nominale
	 * et filet de shutdown de perform_plugin_upgrade(), cf. run()) : liste blanche. Hors
	 * de ces cas, l'extension reste telle que l'a laissée celui qui a repris la main.
	 *
	 *  - jamais une fois le rollback automatique ou la reprise de la transaction commencé
	 *    par ce processus : restore() décide ;
	 *  - après une fermeture faite par la requête elle-même, la transaction encore la
	 *    sienne (aucune reprise ne l'avait prise) : oui, sauf si une reprise d'un autre
	 *    processus tient maintenant cette transaction en base. La réservation n'est pas
	 *    atomique (cf. UpdateTransaction::reserve()) : une reprise qui avait lu la
	 *    transaction encore ouverte peut écrire sa réservation APRÈS la suppression faite
	 *    par close(), et la transaction réapparaît, réservée ; c'est ce cas que couvre
	 *    recovering_elsewhere(). Limite connue, non couverte : une réservation écrite
	 *    ENTRE la relecture de close() et sa suppression est effacée par cette
	 *    suppression ; si la reprise a déjà validé sa réservation, elle restaure sans
	 *    transaction en base, et rien ici ne la voit (quelques millisecondes ; seule une
	 *    suppression conditionnelle en SQL direct la fermerait, suivie avec la
	 *    réservation atomique) ;
	 *  - sinon, seulement tant que la transaction est encore la sienne en base : une
	 *    reprise d'un autre processus qui l'a prise, en cours ou finie (transaction
	 *    fermée), décide seule (décision du 2026-10-10).
	 */
	private static function may_reactivate( string $identity ): bool {
		if ( isset( self::$rollback_started[ $identity ] ) ) {
			return false;
		}
		if ( isset( self::$closed_here[ $identity ] ) ) {
			return ! UpdateTransaction::recovering_elsewhere( $identity, time() );
		}
		return UpdateTransaction::held_identity() === $identity && null !== UpdateTransaction::held();
	}

	/**
	 * Fermeture de sa transaction par la mise à jour, sur un chemin normal (mise à jour
	 * finie, sans objet, ou échec du cœur) : retenue pour le droit de réactiver si elle
	 * était encore la sienne (cf. may_reactivate()).
	 *
	 * @return bool Vrai si elle l'était : aucune reprise ne l'avait prise.
	 */
	private static function close_own( string $identity ): bool {
		$mine = UpdateTransaction::close();
		if ( $mine ) {
			self::$closed_here[ $identity ] = true;
		}
		return $mine;
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
	 * d'activate_plugin() seulement (CommandExecutor::attempt_activation()), jamais par
	 * une écriture forcée de `active_plugins`. `$wp_filesystem` d'abord, comme à
	 * l'abandon d'une reprise : hors de l'administration (cron, REST), une extension
	 * saine qui s'en sert au chargement lèverait une erreur et resterait inactive sans
	 * raison.
	 *
	 * @return string Issue : CommandExecutor::ACTIVATION_* (ACTIVATION_ACTIVE si elle est
	 *                active en sortie, encore active compris : refus d'avant la désactivation).
	 */
	private static function reactivate_if_it_loads( string $plugin_file, bool $network ): string {
		try {
			self::ensure_filesystem();
			return CommandExecutor::attempt_activation( $plugin_file, $network );
		} catch ( \Throwable $e ) {
			// Précaution : l'erreur de la restauration doit remonter, pas celle-ci.
			unset( $e );
			return CommandExecutor::ACTIVATION_LOAD_ERROR;
		}
	}

	/**
	 * force_reactivate() sans jamais lever : l'erreur de la restauration doit remonter,
	 * pas celle-ci (cf. restore_archive()).
	 *
	 * @return bool Vrai si l'extension est active en sortie.
	 */
	private static function force_reactivate_quietly( string $plugin_file, bool $network ): bool {
		try {
			return CommandExecutor::force_reactivate( $plugin_file, $network );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * L'erreur d'une restauration qui a échoué, complétée de ce qui a été fait de
	 * l'extension (`$detail`, texte fixe). Même classe et même code pour une
	 * RestoreException (la plateforme lit `error_code`) ; toute autre erreur devient une
	 * RuntimeException qui la porte.
	 */
	private static function left_inactive( \Throwable $e, string $detail ): \Throwable {
		if ( $e instanceof RestoreException ) {
			return $e->with_detail( $detail );
		}
		// Message déjà échappé par celui qui l'a levé, suffixe fixe.
		return new \RuntimeException( $e->getMessage() . $detail, 0, $e );
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
