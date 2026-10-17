<?php
/**
 * Dérivation des clés tirées du SiteToken : une seule formule pour tout le connecteur.
 *
 *   clé = HMAC-SHA256(clé HMAC = SiteToken, message = contexte), 32 octets bruts
 *
 * L'ordre compte : le jeton est la CLÉ du HMAC, le contexte en est le MESSAGE.
 * Les inverser changerait toutes les clés dérivées, donc toutes les signatures du
 * manager et tous les tickets de connexion, et couperait le parc entier. La formule
 * est celle que RequestSignature et DirectLoginTicket appliquaient chacun de leur
 * côté jusqu'ici : sortie identique à l'octet (vecteurs calculés par une
 * implémentation .NET indépendante, tests/fixtures/key-derivation-vectors.json).
 *
 * Un contexte par usage, pour qu'une preuve faite pour l'un ne vaille jamais pour
 * l'autre :
 *   - `g2rd-sign-v1`   : signature des requêtes du manager (RequestSignature). La
 *                        file des commandes (QueueSignature) n'a PAS de dérivation
 *                        propre : elle passe par RequestSignature::verify(), donc
 *                        par cette même clé ;
 *   - `g2rd-login-v1`  : tickets de connexion directe (DirectLoginTicket) ;
 *   - `g2rd-backup-v1` : réservé à la phase 2 des sauvegardes chiffrées (jeton de
 *                        job) ; aucun appelant pour l'instant.
 *
 * Un jeton vide est dérivé comme avant, sans refus nouveau : la clé obtenue serait
 * publique, et les appelants refusent déjà ce cas avant de s'en servir
 * (RequestSignature : `token_unavailable` ; DirectLoginTicket : refus avant tout
 * calcul de signature).
 *
 * Hors périmètre : Settings::enc_key() / v2_key(), qui dérivent des sels de
 * wp-config.php et non du SiteToken.
 *
 * Classe PURE (aucun appel WordPress), sans effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

final class KeyDerivation {

	/** Signature des requêtes du manager (RequestSignature, et par elle QueueSignature). */
	public const CONTEXT_SIGN = 'g2rd-sign-v1';

	/** Tickets de connexion directe (DirectLoginTicket). */
	public const CONTEXT_LOGIN = 'g2rd-login-v1';

	/** Réservé à la phase 2 des sauvegardes, jeton de job ; aucun appelant. */
	public const CONTEXT_BACKUP = 'g2rd-backup-v1';

	/** Seuls contextes acceptés par derive(). */
	public const CONTEXTS = [ self::CONTEXT_SIGN, self::CONTEXT_LOGIN, self::CONTEXT_BACKUP ];

	/**
	 * Clé (32 octets bruts) dérivée d'un secret pour un contexte déclaré.
	 *
	 * @param string $secret  SiteToken en clair ('' est dérivé comme avant, cf. en-tête).
	 * @param string $context Une des constantes CONTEXT_*.
	 * @throws \InvalidArgumentException Contexte absent de CONTEXTS : erreur de programmation.
	 */
	public static function derive( string $secret, string $context ): string {
		if ( ! in_array( $context, self::CONTEXTS, true ) ) {
			throw new \InvalidArgumentException( 'unknown key derivation context: only the contexts listed in KeyDerivation::CONTEXTS are accepted (programming error: pass one of the KeyDerivation::CONTEXT_* constants, or declare the new context in KeyDerivation first)' );
		}

		return hash_hmac( 'sha256', $context, $secret, true );
	}
}
