<?php
/**
 * Lecture d'une exécution impossible : la base a renvoyé une erreur (connexion perdue,
 * trop de connexions, table verrouillée…). Levée par RunStore::read_raw() et read().
 *
 * Ce n'est PAS un numéro inconnu. Le confondre fausserait le statut : la plateforme lit
 * la 404 `run_unknown` comme une exécution perdue (état définitif « non vérifiée ») et
 * arrête d'interroger, alors que la commande tourne peut-être encore sur le site. Une
 * erreur passagère doit rester une erreur passagère, que la plateforme réessaie.
 *
 * Ce que les appelants en font :
 *   - routes d'acceptation et de lecture (tâches 19 et 20) : réponse 500 avec un message
 *     explicite (quoi, cause probable, action), jamais la 404 `run_unknown`, et jamais
 *     « numéro neuf » à l'acceptation ;
 *   - cycle de vie et ramassage (tâche 15) : ne jamais conclure qu'une exécution est
 *     absente, abandonnée ou interrompue sur cette seule lecture ; laisser remonter ou
 *     réessayer plus tard ;
 *   - signe de vie et dernier filet (tâches 16 et 22) : rattraper, comme toute erreur.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class StoreUnavailable extends \RuntimeException {

	/** Message fixe, sans détail interne : il peut être relayé tel quel. */
	public const MESSAGE = 'L\'état de l\'opération n\'a pas pu être lu sur le site : la base de données a refusé la lecture. Cause probable : base momentanément indisponible ou surchargée (connexion perdue, trop de connexions). Action : aucune, la lecture est réessayée ; si l\'erreur se répète, vérifier l\'état de la base de données auprès de l\'hébergeur.';

	/** Erreur rendue par la base (`$wpdb->last_error`), pour le journal seulement. */
	private string $db_error;

	public function __construct( string $db_error ) {
		parent::__construct( self::MESSAGE );
		$this->db_error = $db_error;
	}

	/**
	 * Erreur technique de la base, pour le journal du site. Jamais servie à la plateforme
	 * ni affichée : elle peut nommer des tables ou le serveur.
	 */
	public function db_error(): string {
		return $this->db_error;
	}
}
