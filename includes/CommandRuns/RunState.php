<?php
/**
 * États d'une exécution en arrière-plan, côté site (§ 3.5 de la spécification).
 *
 * Valeurs lues telles quelles par la plateforme dans la réponse de lecture
 * (`GET /g2rd/v1/command-runs/<numéro>`) : ne pas les renommer.
 *
 *   accepted    → running, abandoned
 *   running     → done, failed, interrupted
 *   interrupted → done, failed (résultat tardif d'une exécution en réalité vivante)
 *   done, failed, abandoned : définitifs
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class RunState {

	/** Enregistrée, pas encore démarrée. */
	public const ACCEPTED = 'accepted';

	/** En cours : son signe de vie est rafraîchi à chaque étape connue. */
	public const RUNNING = 'running';

	/** La commande a rendu un résultat (le 200 de `/command`). */
	public const DONE = 'done';

	/** La commande a rendu une erreur (le 500 de `/command`). */
	public const FAILED = 'failed';

	/** Le processus est mort en route, ou s'est tu plus de 10 minutes : le travail est peut-être partiel. */
	public const INTERRUPTED = 'interrupted';

	/** Acceptée mais jamais démarrée : rien n'a été modifié sur le site. */
	public const ABANDONED = 'abandoned';

	/**
	 * Vrai si l'exécution est conclue : elle n'est plus ni en attente de démarrage ni en
	 * cours. `interrupted` en fait partie, même s'il accepte encore un résultat tardif.
	 */
	public static function is_terminal( string $state ): bool {
		return in_array( $state, [ self::DONE, self::FAILED, self::INTERRUPTED, self::ABANDONED ], true );
	}

	/**
	 * Vrai si le résultat rendu par CommandExecutor peut encore être écrit : exécution en
	 * cours, ou déclarée interrompue alors qu'elle était vivante (une étape de plus de
	 * 10 minutes). Un résultat réel vaut mieux qu'une présomption.
	 */
	public static function accepts_late_result( string $state ): bool {
		return in_array( $state, [ self::RUNNING, self::INTERRUPTED ], true );
	}
}
