<?php
/**
 * Exécution des commandes en arrière-plan sur ce site : constante d'arrêt (§ 8.5 de la
 * spécification).
 *
 * `define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );` dans wp-config.php coupe
 * l'exécution en arrière-plan sans désinstaller le connecteur : la route d'acceptation
 * refuse alors tout numéro neuf (503 `async_disabled`, rien n'est exécuté), et la
 * plateforme repasse par l'exécution habituelle (`/command`).
 *
 * Version minimale, posée avec la route d'acceptation : la capacité `async_commands`
 * annoncée dans l'inventaire (fonction de fin de réponse présente, commandes à distance
 * permises, pas de constante d'arrêt) la complète.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class Capability {

	/** Constante de wp-config.php qui coupe l'exécution en arrière-plan sur ce site. */
	public const DISABLE_CONSTANT = 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS';

	/**
	 * Vrai si la constante d'arrêt est posée, et vraie, dans wp-config.php. Lue à chaque
	 * appel : rien n'est mis en cache, une constante posée vaut dès la requête suivante.
	 */
	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && (bool) constant( self::DISABLE_CONSTANT );
	}
}
