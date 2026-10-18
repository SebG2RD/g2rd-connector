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
 * Exécutée après la réponse par AfterResponseRunner, comme les autres commandes : seul
 * l'enregistrement de son exécution (et le créneau) est écrit, par le coureur et par le
 * signe de vie. La sonde elle-même ne lit ni n'écrit aucune option, aucun fichier.
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

	/**
	 * Attend la durée demandée par pas de 10 s, avec un signe de vie après chaque pas, puis
	 * rend ce qui s'est passé.
	 *
	 * `slept` compte les secondes réellement attendues : sans interruption, la durée
	 * demandée. Un pas interrompu (signal : sleep() rend alors ce qui restait) compte pour
	 * ce qu'il a tenu, sans rattrapage par les pas suivants : la plateforme voit `slept`
	 * inférieur à `requested`. `started_at` et `finished_at` (ISO 8601 UTC, comme les autres
	 * dates des routes) disent la durée écoulée, temps de travail compris.
	 *
	 * Jamais plus de 10 minutes, même appelée hors de la route (qui n'admet que 10 à 600 s
	 * par pas de 10) ; rien n'est attendu sans durée entière positive.
	 *
	 * @param array<mixed>       $payload Charge acceptée : `seconds`, durée demandée.
	 * @param \Closure(int): int $sleep   Attend ; rend les secondes non attendues, 0 si
	 *                                    l'attente est tenue (comme sleep()).
	 * @param \Closure(): void   $alive   Signe de vie de l'exécution.
	 * @param \Closure(): int    $clock   Heure courante (secondes Unix).
	 * @return array{requested: int, slept: int, started_at: string, finished_at: string}
	 */
	public static function run( array $payload, \Closure $sleep, \Closure $alive, \Closure $clock ): array {
		$seconds    = $payload['seconds'] ?? null;
		$requested  = is_int( $seconds ) ? $seconds : 0;
		$remaining  = max( 0, min( $requested, self::MAX_SECONDS ) );
		$slept      = 0;
		$started_at = $clock();

		while ( $remaining > 0 ) {
			$step = min( self::STEP_SECONDS, $remaining );
			// Sous Windows, une attente interrompue rend 192, pas ce qui restait : le pas ne
			// compte alors pour rien.
			$slept     += $step - max( 0, min( $step, $sleep( $step ) ) );
			$remaining -= $step;
			$alive();
		}

		return [
			'requested'   => $requested,
			'slept'       => $slept,
			'started_at'  => gmdate( 'c', $started_at ),
			'finished_at' => gmdate( 'c', $clock() ),
		];
	}
}
