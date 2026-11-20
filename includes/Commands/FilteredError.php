<?php
/**
 * Échec d'une commande dont le message est prêt à quitter le site : phrases fixes du connecteur
 * et texte tiers déjà filtré par PhpErrors\Redactor (500 caractères au plus). Le filet de
 * CommandExecutor::run() le rend tel quel : filtré une seconde fois, son explication serait
 * perdue (tout ce qui suit un masque de requête est pris pour la suite de la requête) ou coupée
 * par la borne de longueur.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Commands;

use G2RD\Connector\PhpErrors\Redactor;

final class FilteredError extends \RuntimeException {

	/**
	 * Message de `$e` prêt à quitter le site : tel quel s'il est déjà filtré, sinon filtré par
	 * Redactor (chemins du serveur, e-mails, IP, jetons, requêtes SQL retirés ; 500 caractères au plus).
	 */
	public static function message_of( \Throwable $e ): string {
		return $e instanceof self ? $e->getMessage() : Redactor::redact( $e->getMessage() );
	}
}
