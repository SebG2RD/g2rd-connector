<?php
/**
 * Bloc `environment` du snapshot (GET /g2rd/v1/snapshot), version 1 de la forme : ce
 * que le serveur du site permet (RuntimeProbe : extensions natives, limites PHP,
 * disque) et les volumes de la dernière mesure quotidienne (SizeReport::read(), une
 * lecture d'option).
 *
 * Rien n'est mesuré ici : aucun parcours de fichiers, aucune taille de base. Les
 * volumes sont calculés en tâche de fond au plus une fois par jour, et le snapshot
 * ne lit que leur cache daté.
 *
 * La forme est partagée avec la plateforme par tests/fixtures/snapshot-environment-v1.json,
 * identique à l'octet à backend/tests/fixtures/snapshot-environment-v1.json du
 * manager : les clés et leur ordre ne changent pas sans les deux dépôts. Une forme
 * nouvelle prendra une autre VERSION.
 *
 * Le snapshot ne doit jamais échouer à cause de ce bloc : toute erreur rend le bloc
 * minimal `{version}`, sans aucune valeur (jamais une valeur inventée). Aucun effet
 * au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Environment;

final class SnapshotBlock {

	/** Version de la forme du bloc. */
	public const VERSION = 1;

	/**
	 * Bloc `environment`, dans l'ordre des clés du contrat.
	 *
	 * @param int               $now   Horodatage Unix de la requête du snapshot.
	 * @param RuntimeProbe|null $probe Sonde des mesures légères ; par défaut, les vraies fonctions de PHP.
	 * @return array<string, mixed>
	 */
	public static function build( int $now, ?RuntimeProbe $probe = null ): array {
		try {
			return [ 'version' => self::VERSION ]
				+ ( $probe ?? new RuntimeProbe() )->describe()
				+ [ 'sizes' => SizeReport::read( $now ) ];
		} catch ( \Throwable ) {
			// Option illisible (base injoignable), fonction de WordPress absente : le reste du snapshot tient.
			return [ 'version' => self::VERSION ];
		}
	}
}
