<?php
/**
 * AES-256-GCM du format chiffré des sauvegardes (G2BK v1), par l'extension OpenSSL de PHP.
 *
 * Sert aux blocs d'un segment (sous la sous-clé k_bloc, nonce = 00 00 00 00 ‖ numéro du bloc sur
 * 8 octets) et à l'AEAD de HPKE (identifiant 0x0002) : docs/backup-format.md, dépôt de la
 * plateforme. Débit mesuré le 24/10/2026 : 2 à 4,6 Gio/s (OpenSSL natif) ; mémoire : deux copies
 * d'un bloc (le chiffré découpé, puis le résultat).
 *
 * Étiquette de 16 octets imposée, dans les deux sens :
 * - seal() demande 16 octets à openssl_encrypt() et refuse toute autre longueur rendue ;
 * - open() découpe lui-même les 16 derniers octets et refuse un objet plus court. Piège mesuré le
 *   24/10/2026 (PHP 8.4.3, OpenSSL 3.0.15) : openssl_decrypt() accepte une étiquette de 15, 4 ou
 *   1 octet(s) quand ce sont les premiers octets de la vraie. Un bloc final vide tronqué, passé
 *   tel quel, s'ouvrirait donc en '' : bloc final accepté sans son étiquette complète.
 *
 * Longueurs de clé (32 octets) et de nonce (12 octets) contrôlées avant tout appel :
 * openssl_encrypt() complète en silence une clé plus courte par des zéros et accepte un nonce
 * d'une autre longueur (mesuré le même jour). Une longueur fausse est une erreur de programmation
 * (\InvalidArgumentException) : clé et nonce viennent du code (sous-clé dérivée, compteur de
 * bloc, sortie de HPKE), jamais d'une donnée reçue.
 *
 * open() rend null pour tout échec d'authentification, et le clair sinon, '' compris (bloc final
 * vide) : le retour d'openssl_decrypt() est comparé strictement à false, jamais par ! $clair,
 * empty() ni == false, qui confondraient les deux.
 *
 * Extension OpenSSL absente, ou échec d'openssl_encrypt() : FormatError::CRYPTO_UNAVAILABLE, à
 * l'ouverture comme au chiffrement (une ouverture impossible n'est pas un bloc altéré). L'appelant
 * contrôle available() avant de commencer.
 *
 * Aucune clé ni aucun texte clair dans un message, aucune exception chaînée. La classe ne garde
 * rien : l'effacement des clés revient à qui les détient (écrivain et lecteur d'un segment, HPKE).
 *
 * Classe pure, sans effet au chargement, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Crypto;

use G2RD\Connector\Backup\Format\FormatError;

final class Aead {

	public const KEY_BYTES   = 32;
	public const NONCE_BYTES = 12;
	public const TAG_BYTES   = 16;

	/** Nom du chiffrement pour OpenSSL, tel que le rend openssl_get_cipher_methods(). */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * AES-256-GCM est-il utilisable ? Fonctions openssl_encrypt() et openssl_decrypt() présentes,
	 * et « aes-256-gcm » dans la liste des chiffrements d'OpenSSL. Rien n'est mémorisé : la liste
	 * est relue à chaque appel (l'autotest, tâche 15, recalcule tout à chaque passage).
	 *
	 * @param ( \Closure(): array<mixed> )|null $cipher_methods Liste des chiffrements, pour les tests ;
	 *                                                          par défaut openssl_get_cipher_methods().
	 */
	public static function available( ?\Closure $cipher_methods = null ): bool {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		if ( null === $cipher_methods ) {
			if ( ! function_exists( 'openssl_get_cipher_methods' ) ) {
				return false;
			}
			$cipher_methods = static fn (): array => openssl_get_cipher_methods();
		}
		return in_array( self::CIPHER, $cipher_methods(), true );
	}

	/**
	 * Chiffre et authentifie.
	 *
	 * @param string $key       Clé, 32 octets.
	 * @param string $nonce     Nonce, 12 octets ; jamais deux fois le même sous une même clé.
	 * @param string $aad       Données associées (authentifiées, non chiffrées).
	 * @param string $plaintext Clair, éventuellement vide.
	 *
	 * @return string Chiffré ‖ étiquette (16 octets) : strlen( $plaintext ) + 16 octets.
	 *
	 * @throws \InvalidArgumentException Clé ou nonce de longueur fausse : erreur de programmation.
	 * @throws FormatError               CRYPTO_UNAVAILABLE : OpenSSL absent, en échec, ou étiquette
	 *                                   rendue qui n'a pas 16 octets.
	 */
	public static function seal( string $key, string $nonce, string $aad, string $plaintext ): string {
		self::check_lengths( $key, $nonce );
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES );
		// Comparaison stricte : un clair vide donne un chiffré vide (''), qui n'est pas un échec.
		if ( false === $ciphertext || ! is_string( $tag ) || self::TAG_BYTES !== strlen( $tag ) ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'OpenSSL.
		}
		return $ciphertext . $tag;
	}

	/**
	 * Vérifie et déchiffre.
	 *
	 * @param string $key    Clé, 32 octets.
	 * @param string $nonce  Nonce, 12 octets.
	 * @param string $aad    Données associées attendues.
	 * @param string $sealed Chiffré ‖ étiquette (16 octets), tel que le rend seal().
	 *
	 * @return string|null Le clair ('' pour un clair vide, bloc final vide du format), ou null si
	 *                     l'objet a moins de 16 octets ou ne s'authentifie pas (clé, nonce, données
	 *                     associées, chiffré ou étiquette différents).
	 *
	 * @throws \InvalidArgumentException Clé ou nonce de longueur fausse : erreur de programmation.
	 * @throws FormatError               CRYPTO_UNAVAILABLE : OpenSSL absent.
	 */
	public static function open( string $key, string $nonce, string $aad, string $sealed ): ?string {
		self::check_lengths( $key, $nonce );
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}

		$length = strlen( $sealed );
		if ( $length < self::TAG_BYTES ) {
			return null;
		}

		// Étiquette découpée ici, toujours 16 octets : jamais la longueur laissée à OpenSSL.
		$plaintext = openssl_decrypt(
			substr( $sealed, 0, $length - self::TAG_BYTES ),
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$nonce,
			substr( $sealed, $length - self::TAG_BYTES ),
			$aad
		);
		if ( false === $plaintext ) {
			return null;
		}
		return $plaintext;
	}

	/**
	 * @throws \InvalidArgumentException Clé ou nonce de longueur fausse.
	 */
	private static function check_lengths( string $key, string $nonce ): void {
		if ( self::KEY_BYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'AES-256-GCM impossible : la clé doit faire 32 octets (OpenSSL compléterait en silence une clé plus courte par des zéros). Cause probable : erreur de programmation (sous-clé mal dérivée). Action : passer une clé de 32 octets tirée de Hkdf::expand() ou de HPKE.' );
		}
		if ( self::NONCE_BYTES !== strlen( $nonce ) ) {
			throw new \InvalidArgumentException( 'AES-256-GCM impossible : le nonce doit faire 12 octets. Cause probable : erreur de programmation (nonce mal construit). Action : passer le nonce du format (00 00 00 00 suivi du numéro du bloc sur 8 octets) ou celui de HPKE.' );
		}
	}
}
