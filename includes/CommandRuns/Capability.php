<?php
/**
 * Exécution des commandes en arrière-plan sur ce site : capacité `async_commands` annoncée
 * dans l'inventaire (§ 3.6 de la spécification) et constante d'arrêt (§ 8.5).
 *
 * La capacité dit à la plateforme qu'elle peut confier à ce site ses commandes longues par
 * `POST /g2rd/v1/command-runs`. Elle est annoncée quand trois conditions sont réunies :
 *   - les routes sont enregistrées : commandes à distance permises
 *     (`remote_commands_enabled`, comme `/command`) ;
 *   - le serveur sait terminer une réponse avant la fin du script
 *     (AfterResponseRunner::finisher_available() : litespeed_finish_request() ou
 *     fastcgi_finish_request()). Sans elle (Apache + mod_php), la réponse ne partirait qu'à
 *     la fin de la commande : le site reste en mode actuel (décision n° 6 du propriétaire,
 *     pas de repli par WP-Cron) ;
 *   - la constante d'arrêt n'est pas posée.
 *
 * `define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );` dans wp-config.php coupe
 * l'exécution en arrière-plan sans désinstaller le connecteur : la capacité disparaît à la
 * synchronisation suivante, la route d'acceptation refuse tout numéro neuf (503
 * `async_disabled`, rien n'est exécuté), et la plateforme repasse par l'exécution habituelle
 * (`/command`). La lecture des exécutions passées reste possible.
 *
 * Rien n'est lu au chargement : tout est évalué à l'appel, une constante posée ou un réglage
 * changé valent dès la requête suivante.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

use G2RD\Connector\Settings;

final class Capability {

	/** Nom de la capacité dans l'inventaire (`capabilities` de `/snapshot`). */
	public const NAME = 'async_commands';

	/** Constante de wp-config.php qui coupe l'exécution en arrière-plan sur ce site. */
	public const DISABLE_CONSTANT = 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS';

	/**
	 * Vrai si la constante d'arrêt est posée, et vraie, dans wp-config.php. Lue à chaque
	 * appel : rien n'est mis en cache, une constante posée vaut dès la requête suivante.
	 */
	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && (bool) constant( self::DISABLE_CONSTANT );
	}

	/**
	 * Vrai si la capacité `async_commands` doit être annoncée (cf. l'en-tête de la classe).
	 *
	 * @param (\Closure(string): bool)|null $has_finisher Détection d'une fonction de fin de
	 *        réponse par son nom ; function_exists() par défaut.
	 */
	public static function available( ?\Closure $has_finisher = null ): bool {
		return (bool) Settings::get( 'remote_commands_enabled' )
			&& AfterResponseRunner::finisher_available( $has_finisher )
			&& ! self::disabled_by_constant();
	}
}
