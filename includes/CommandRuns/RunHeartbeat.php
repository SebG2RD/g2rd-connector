<?php
/**
 * Signe de vie d'une exécution en arrière-plan, pendant qu'elle tourne (§ 3.5 de la
 * spécification).
 *
 * Le ramassage (RunReaper) déclare interrompue une exécution en cours restée 600 s sans
 * signe de vie, et le créneau (ExecutionSlot) est repris au même silence. Une longue
 * opération rafraîchit donc `alive_at` de son enregistrement (RunLifecycle::alive()) et du
 * créneau (ExecutionSlot::touch()) à chaque étape connue :
 *   - les crochets du programme de mise à jour de WordPress : filtres
 *     `upgrader_pre_download`, `upgrader_pre_install`, `upgrader_post_install`, action
 *     `upgrader_process_complete` ;
 *   - chaque table optimisée : action `g2rd_connector_command_progress`, déclenchée par
 *     CommandExecutor::optimize_database() ;
 *   - chaque étape de la transaction d'une mise à jour protégée : action
 *     `g2rd_connector_update_txn_progress`, déclenchée par UpdateTransaction::step() et
 *     touch() avec l'étape courante, enregistrée dans `step`.
 * Même hypothèse que la reprise d'une mise à jour protégée : aucune étape vivante ne dure
 * plus de 10 minutes sans l'un de ces crochets.
 *
 * Au plus une écriture toutes les 5 s : les crochets d'une mise à jour s'enchaînent en
 * quelques millisecondes, et chaque écriture coûte deux lectures et deux écritures
 * conditionnelles. Une étape nouvelle de la transaction est écrite sans attendre : le
 * contrôle de santé suit de près la fin du remplacement des fichiers, et ne serait sinon
 * jamais affiché ; il y a quatre ou cinq étapes par mise à jour protégée.
 *
 * Les écouteurs sont appelés au milieu d'une mise à jour, y compris depuis
 * UpdateTransaction::step() : ils rattrapent toute erreur (\Throwable) et n'en laissent
 * jamais remonter. Un signe de vie raté ne fait pas échouer la mise à jour : le suivant le
 * remplace, et la mort n'est déclarée qu'après 600 s de silence. Les trois filtres rendent
 * leur première valeur telle quelle, quelle qu'elle soit (faux, vrai, chemin du paquet,
 * WP_Error) : un filtre qui rendrait autre chose casserait toute mise à jour du site.
 *
 * Une exécution à la fois par processus : attach() la nomme et branche les écouteurs,
 * detach() les retire. Sans exécution attachée, les écouteurs ne lisent ni n'écrivent
 * rien. Rien n'est branché au chargement de l'extension.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class RunHeartbeat {

	/** Au plus une écriture du signe de vie toutes les 5 s (sauf étape nouvelle). */
	public const THROTTLE_SECONDS = 5;

	/** Filtres du programme de mise à jour : la valeur reçue est rendue telle quelle. */
	private const FILTERS = [ 'upgrader_pre_download', 'upgrader_pre_install', 'upgrader_post_install' ];

	/** Actions sans étape : fin d'une mise à jour, pas d'une commande longue (table optimisée). */
	private const ACTIONS = [ 'upgrader_process_complete', 'g2rd_connector_command_progress' ];

	/** Action d'une étape de la transaction d'une mise à jour protégée (UpdateTransaction). */
	private const TRANSACTION_STEP_ACTION = 'g2rd_connector_update_txn_progress';

	/** Numéro de l'exécution attachée ; null : aucune, les écouteurs ne font rien. */
	private static ?string $command_id = null;

	/**
	 * Horloge de l'exécution attachée (secondes Unix).
	 *
	 * @var (\Closure(): int)|null
	 */
	private static ?\Closure $clock = null;

	/** Heure de la dernière écriture tentée ; null : aucune depuis attach(). */
	private static ?int $written_at = null;

	/** Dernière étape écrite ; null : aucune depuis attach(). */
	private static ?string $written_step = null;

	/**
	 * Attache l'exécution `$command_id` à ce processus et branche les écouteurs. Rien n'est
	 * lu ni écrit ici : le démarrage (RunLifecycle::start()) vient d'écrire le premier
	 * signe de vie.
	 *
	 * @param string          $command_id Exécution démarrée par ce processus.
	 * @param \Closure(): int $clock      Heure courante (secondes Unix).
	 */
	public static function attach( string $command_id, \Closure $clock ): void {
		self::$command_id   = $command_id;
		self::$clock        = $clock;
		self::$written_at   = null;
		self::$written_step = null;

		foreach ( self::FILTERS as $hook ) {
			add_filter( $hook, [ self::class, 'on_filter' ] );
		}
		foreach ( self::ACTIONS as $hook ) {
			add_action( $hook, [ self::class, 'on_progress' ], 10, 0 );
		}
		add_action( self::TRANSACTION_STEP_ACTION, [ self::class, 'on_transaction_step' ] );
	}

	/**
	 * Retire les écouteurs et oublie l'exécution : plus rien n'est écrit, même par un
	 * écouteur encore appelé.
	 */
	public static function detach(): void {
		foreach ( self::FILTERS as $hook ) {
			remove_filter( $hook, [ self::class, 'on_filter' ] );
		}
		foreach ( self::ACTIONS as $hook ) {
			remove_action( $hook, [ self::class, 'on_progress' ] );
		}
		remove_action( self::TRANSACTION_STEP_ACTION, [ self::class, 'on_transaction_step' ] );

		self::$command_id   = null;
		self::$clock        = null;
		self::$written_at   = null;
		self::$written_step = null;
	}

	/**
	 * Écouteur des trois filtres du programme de mise à jour : signe de vie, puis la
	 * valeur reçue, rendue telle quelle.
	 *
	 * @param mixed $value Valeur des filtres précédents (faux, vrai, chemin, WP_Error…).
	 * @return mixed La même valeur.
	 */
	public static function on_filter( mixed $value ): mixed {
		self::beat( null );

		return $value;
	}

	/** Écouteur des actions sans étape (fin d'une mise à jour, table optimisée). */
	public static function on_progress(): void {
		self::beat( null );
	}

	/**
	 * Écouteur des étapes de la transaction d'une mise à jour protégée.
	 *
	 * @param mixed $step Étape courante (UpdateTransaction::STEP_*) ; autre chose qu'un
	 *                    texte : signe de vie seul, l'étape écrite reste.
	 */
	public static function on_transaction_step( mixed $step ): void {
		self::beat( is_string( $step ) ? $step : null );
	}

	/**
	 * Rafraîchit le signe de vie de l'exécution attachée et du créneau, si c'est le
	 * moment (should_write()). Ne lève jamais.
	 *
	 * @param string|null $step Étape à enregistrer ; null garde la précédente.
	 */
	private static function beat( ?string $step ): void {
		$command_id = self::$command_id;
		$clock      = self::$clock;
		if ( null === $command_id || null === $clock ) {
			return;
		}

		try {
			$now = $clock();
			if ( ! self::should_write( $now, $step ) ) {
				return;
			}
			// Notées avant l'écriture : une écriture refusée par la base n'est retentée
			// qu'au prochain crochet, 5 s plus tard au plus tôt.
			self::$written_at = $now;
			if ( null !== $step ) {
				self::$written_step = $step;
			}

			RunLifecycle::alive( $command_id, $now, $step );
			ExecutionSlot::touch( $command_id, $now );
		} catch ( \Throwable $e ) {
			// Base qui lève (mysqli en mode exception), horloge en panne… : ce signe de vie
			// est perdu, le suivant le remplace. Rien ne doit remonter dans la mise à jour.
			return;
		}
	}

	/**
	 * Faut-il écrire maintenant ? Oui pour une étape nouvelle, pour le premier signe de
	 * vie depuis attach(), et 5 s après la dernière écriture.
	 *
	 * Une horloge qui recule (resynchronisation) fait écrire aussitôt : sans cela, le
	 * signe de vie se tairait jusqu'à ce qu'elle rattrape la dernière écriture, et
	 * l'exécution pourrait être déclarée morte en pleine activité.
	 */
	private static function should_write( int $now, ?string $step ): bool {
		if ( null !== $step && $step !== self::$written_step ) {
			return true;
		}
		if ( null === self::$written_at ) {
			return true;
		}
		$elapsed = $now - self::$written_at;

		return $elapsed < 0 || $elapsed >= self::THROTTLE_SECONDS;
	}
}
