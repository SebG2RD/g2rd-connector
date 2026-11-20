<?php
/**
 * Échec d'une commande dont le message est prêt à quitter le site : phrases fixes du connecteur
 * et texte tiers déjà filtré par PhpErrors\Redactor (500 caractères au plus). Le filet de
 * CommandExecutor::run() le rend tel quel : filtré une seconde fois, son explication serait
 * perdue (tout ce qui suit un masque de requête est pris pour la suite de la requête) ou coupée
 * par la borne de longueur.
 *
 * Principe : tout texte d'origine tierce (exception, WP_Error, message d'un upgrader, de PclZip…)
 * qui part au manager passe une fois, et une seule, par text() ; l'explication fixe du connecteur
 * qui le suit n'est jamais refiltrée ni coupée.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Commands;

use G2RD\Connector\PhpErrors\Redactor;

final class FilteredError extends \RuntimeException {

	/**
	 * Message de `$e` prêt à quitter le site : tel quel s'il est déjà filtré, sinon filtré par text().
	 */
	public static function message_of( \Throwable $e ): string {
		return $e instanceof self ? $e->getMessage() : self::text( $e->getMessage(), get_class( $e ) );
	}

	/**
	 * Texte d'une WP_Error prêt à quitter le site (cf. text()) ; sans message, son code.
	 */
	public static function of_wp_error( \WP_Error $error ): string {
		return self::text( $error->get_error_message(), (string) $error->get_error_code() );
	}

	/**
	 * Texte tiers filtré par Redactor : chemins du serveur, e-mails, IP, jetons, requêtes SQL
	 * retirés ; 500 caractères au plus. Vide, il n'y a rien à filtrer : `$if_empty` (nom de la
	 * classe d'une exception, code d'une WP_Error), filtré lui aussi, sinon une chaîne vide ; jamais
	 * Redactor::UNREADABLE, qui dit l'échec du filtre.
	 */
	public static function text( string $text, string $if_empty = '' ): string {
		if ( '' === trim( $text ) ) {
			$text = $if_empty;
		}
		return '' === trim( $text ) ? '' : Redactor::redact( $text );
	}
}
