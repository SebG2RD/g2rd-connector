<?php
/**
 * HKDF-SHA256 (RFC 5869) du format chiffré des sauvegardes (G2BK v1), extraction et expansion
 * séparées.
 *
 * Pourquoi pas hash_hkdf() : elle enchaîne toujours l'extraction et l'expansion, alors que le
 * format a besoin de chacune seule. HPKE (RFC 9180) les sépare (LabeledExtract, LabeledExpand), et
 * les sous-clés d'un segment (k_bloc, k_engagement) sont des expansions de la clé de données, qui
 * sert directement de PRK, sans extraction (RFC 5869 § 2.3 ; docs/backup-format.md, dépôt de la
 * plateforme).
 *
 * Le PRK n'est pas contrôlé : la RFC le veut d'au moins 32 octets, et tous les appelants du format
 * passent 32 octets (clé de données, sortie d'extract()). Aucun résultat mémorisé. La classe ne
 * garde rien : l'effacement des sorties revient à qui les détient.
 *
 * Classe pure, sans effet au chargement, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Crypto;

final class Hkdf {

	/** Longueur d'une sortie de HMAC-SHA256 (HashLen). */
	private const HASH_BYTES = 32;

	/** Longueur maximale d'une expansion : 255 × HashLen (RFC 5869 § 2.3). */
	private const MAX_LENGTH = 255 * self::HASH_BYTES;

	/**
	 * HKDF-Extract (RFC 5869 § 2.2) : PRK = HMAC-SHA256( clé = sel, message = IKM ).
	 *
	 * Un sel vide équivaut à 32 zéros (la clé du HMAC est complétée par des zéros), comme le veut
	 * la RFC pour un sel absent.
	 *
	 * @param string $salt Sel, éventuellement vide.
	 * @param string $ikm  Matière de clé d'entrée.
	 *
	 * @return string PRK, 32 octets.
	 */
	public static function extract( string $salt, string $ikm ): string {
		return hash_hmac( 'sha256', $ikm, $salt, true );
	}

	/**
	 * HKDF-Expand (RFC 5869 § 2.3) : T(0) = '' ; T(i) = HMAC-SHA256( PRK, T(i − 1) ‖ info ‖ i ),
	 * i de 1 à N = ⌈L / 32⌉ sur un octet ; sortie = les L premiers octets de T(1) ‖ … ‖ T(N).
	 *
	 * @param string $prk    Clé pseudo-aléatoire, 32 octets.
	 * @param string $info   Contexte, éventuellement vide.
	 * @param int    $length Longueur de la sortie, de 1 à 8 160 octets.
	 *
	 * @throws \InvalidArgumentException Longueur hors de 1 à 8 160 : erreur de programmation.
	 */
	public static function expand( string $prk, string $info, int $length ): string {
		if ( $length < 1 || $length > self::MAX_LENGTH ) {
			throw new \InvalidArgumentException( 'HKDF-Expand impossible : la longueur demandée doit être de 1 à 8 160 octets (255 blocs de SHA-256, RFC 5869). Cause probable : erreur de programmation. Action : demander la longueur du contrat (32 pour une clé, 12 pour un nonce).' );
		}

		$okm   = '';
		$block = '';
		for ( $counter = 1; strlen( $okm ) < $length; $counter++ ) {
			$block = hash_hmac( 'sha256', $block . $info . chr( $counter ), $prk, true );
			$okm  .= $block;
		}
		return substr( $okm, 0, $length );
	}
}
