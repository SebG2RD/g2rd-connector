<?php
/**
 * Sonde de diagnostic `async_probe` (décision n° 4 du propriétaire), dormante.
 *
 * Elle prouve, sur l'hébergement réel, que le processus PHP continue de vivre après la
 * réponse (au-delà des 60 s du CDN, jusqu'à 10 minutes) : elle ne fait qu'attendre, en
 * donnant signe de vie, sans rien toucher sur le site. Admise par la route d'acceptation
 * seulement si `define( 'G2RD_CONNECTOR_ASYNC_PROBE', true );` est posé dans
 * wp-config.php (le site pilote) ; sinon, c'est une commande inconnue (400). Ni `/command`
 * ni la file tirée par le battement de cœur ne la connaissent.
 *
 * Version minimale, posée avec la route d'acceptation (interrupteur et bornes de la
 * durée) ; l'attente elle-même la complète.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class AsyncProbe {

	/** Constante de wp-config.php qui allume la sonde sur ce site. */
	public const CONSTANT = 'G2RD_CONNECTOR_ASYNC_PROBE';

	/** Durée d'attente maximale demandée à la sonde (secondes) : 10 minutes. */
	public const MAX_SECONDS = 600;

	/** Pas de la durée, et d'un signe de vie à l'autre (secondes). */
	public const STEP_SECONDS = 10;

	/** Vrai si la constante est posée, et vraie, dans wp-config.php. */
	public static function enabled(): bool {
		return defined( self::CONSTANT ) && (bool) constant( self::CONSTANT );
	}
}
