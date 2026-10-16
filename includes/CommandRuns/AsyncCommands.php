<?php
/**
 * Commandes que le site accepte d'exécuter en arrière-plan (route
 * `POST /g2rd/v1/command-runs`), pour qu'un renvoi de la requête par le CDN de
 * l'hébergeur ne les exécute jamais deux fois.
 *
 * Toutes les commandes du site y passent, sauf les quatre instantanées, dont les
 * appelants attendent une réponse immédiate : elles restent sur `/command`
 * (décision n° 5 du propriétaire). Chaque commande de CommandExecutor::ALLOWED est
 * rangée dans l'une des deux listes, jamais dans les deux (cf. AsyncCommandsTest).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class AsyncCommands {

	/**
	 * Commandes longues, admises en arrière-plan. Leur charge est celle de `/command`.
	 *
	 * @var list<string>
	 */
	public const ADMITTED = [
		'update_plugin',
		'update_theme',
		'update_core',
		'update_translations',
		'rollback_plugin',
		'optimize_database',
		'delete_spam_comments',
		'delete_post_revisions',
		'empty_trashed_posts',
		'delete_expired_transients',
	];

	/**
	 * Commandes instantanées, refusées en arrière-plan : elles restent sur `/command`.
	 *
	 * @var list<string>
	 */
	public const EXCLUDED = [
		'clear_cache',
		'check_updates',
		'delete_restore_point',
		'set_signature_policy',
	];

	/**
	 * Sonde de diagnostic (décision n° 4) : elle ne fait qu'attendre, en donnant signe de
	 * vie. Hors de CommandExecutor::ALLOWED : ni `/command` ni la file tirée par le
	 * battement de cœur ne la connaissent. Admise seulement si la constante
	 * `G2RD_CONNECTOR_ASYNC_PROBE` est vraie dans `wp-config.php` ; sinon, inconnue.
	 */
	public const PROBE = 'async_probe';

	/**
	 * Commandes qui remplacent les fichiers d'une extension : refusées tant qu'une mise à
	 * jour protégée est en cours sur le site (§ 3.4 de la spécification).
	 *
	 * @var list<string>
	 */
	public const TOUCHES_PLUGIN_FILES = [
		'update_plugin',
		'rollback_plugin',
	];

	/**
	 * Vrai si la commande peut être confiée à la route asynchrone. Comparaison stricte :
	 * ni casse différente ni espace toléré.
	 *
	 * @param string $command       Nom de la commande reçue.
	 * @param bool   $probe_enabled Vrai si la sonde est allumée sur ce site.
	 */
	public static function is_admitted( string $command, bool $probe_enabled ): bool {
		if ( self::PROBE === $command ) {
			return $probe_enabled;
		}

		return in_array( $command, self::ADMITTED, true );
	}
}
