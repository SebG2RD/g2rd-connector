<?php
/**
 * Certificat de clé de site (décision n° 15 ; docs/backup-format.md § 9, dépôt de la plateforme) :
 * signature Ed25519, par la clé de signature du service de clés, de la clé publique X25519 d'un
 * site et de sa version.
 *
 * Certificat = signature Ed25519 détachée (64 octets) sur `"g2rd-keys/site-key-certificate-v1"` ‖
 * `00` ‖ `tenant_ref` (16) ‖ `site_ref` (16) ‖ `version` (4, gros-boutiste) ‖ clé publique X25519
 * du site (32). Le service de clés le rend avec chaque clé de site (`PUT`, `GET`, `rotations`).
 * `service_key_id` = 8 premiers octets de SHA-256 de la clé publique Ed25519 du service, en
 * 16 caractères hexadécimaux minuscules.
 *
 * Vérification par le connecteur (phase 4, `backup_set_key`) : `service_key_id` présent dans la
 * **liste épinglée** de son code, jamais reçue de la plateforme, puis signature ; sinon
 * `site_key_certificate_invalid`, aucune clé enregistrée. La liste elle-même (constante du code)
 * arrive en phase 4 avec `backup_set_key`, vide jusqu'au lot final ; la manière d'épingler la clé
 * du service **local** pour les essais du banc WordPress (lot 4-D) est tranchée au plan de la
 * phase 4. Ce que le certificat ne couvre pas : sur le compte d'hébergement partagé, qui contrôle
 * la plateforme peut aussi écrire dans les fichiers des sites ; la protection vaut pour les sites
 * hébergés ailleurs.
 *
 * Vérification Ed25519 stricte (§ 8) : verify() passe par Sodium::ed25519_verify() et ne refait
 * aucun des cinq contrôles ; aucun appel à pk_to_curve25519() ni à ge_mul_l() ici. Comme pour
 * l'index (BackupIndex), une clé épinglée d'ordre mixte ou d'ordre 2·L est refusée dans les deux
 * moteurs par le contrôle n° 5 de Sodium, et les exceptions de sodium_compat 1.24.0 (« S >= L »,
 * « small order », \RangeException d'une clé hors de la courbe) n'en sortent jamais.
 *
 * Classe pure, sans état : rien au chargement, aucune fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Sodium;

final class SiteKeyCertificate {

	/** Préfixe du message signé : `"g2rd-keys/site-key-certificate-v1"` ‖ `00` (§ 9 et § 10). */
	public const DOMAIN = "g2rd-keys/site-key-certificate-v1\x00";

	/** `tenant_ref` et `site_ref` : 16 octets (32 caractères hexadécimaux dans l'API). */
	private const REF_BYTES = 16;

	/** Clé publique X25519 du site, ou Ed25519 du service : 32 octets. */
	private const PUBLIC_KEY_BYTES = 32;

	/** Version de la clé du site, au plus : ce que portent ses 4 octets (2³² − 1). */
	private const MAX_VERSION = 4294967295;

	/**
	 * Message signé par le service de clés.
	 *
	 * @param string $tenant_ref Référence de l'agence, 16 octets.
	 * @param string $site_ref   Référence du site, 16 octets.
	 * @param int    $version    Version de la clé du site, de 1 à 4 294 967 295.
	 * @param string $public_key Clé publique X25519 du site, 32 octets.
	 *
	 * @throws \InvalidArgumentException Argument hors du contrat : erreur de programmation.
	 */
	public static function message( string $tenant_ref, string $site_ref, int $version, string $public_key ): string {
		if ( self::REF_BYTES !== strlen( $tenant_ref ) || self::REF_BYTES !== strlen( $site_ref ) ) {
			throw new \InvalidArgumentException( 'Certificat de clé de site impossible à vérifier : les références de l\'agence et du site doivent faire 16 octets bruts chacune. Cause probable : références passées en hexadécimal (32 caractères), erreur de programmation. Action : passer hex2bin() des références tenant_ref et site_ref de l\'API.' );
		}
		if ( self::PUBLIC_KEY_BYTES !== strlen( $public_key ) ) {
			throw new \InvalidArgumentException( 'Certificat de clé de site impossible à vérifier : la clé publique X25519 du site doit faire 32 octets bruts. Cause probable : clé passée en hexadécimal (64 caractères) ou tronquée, erreur de programmation. Action : passer hex2bin() de la clé public_key rendue par le service de clés.' );
		}
		// 4 octets : pack( 'N' ) ne garderait que les 32 bits bas d'une version plus grande, et 2³²
		// donnerait le message de la version 0.
		if ( $version < 1 || $version > self::MAX_VERSION ) {
			throw new \InvalidArgumentException( 'Certificat de clé de site impossible à vérifier : la version de la clé doit être de 1 à 4 294 967 295. Cause probable : version absente (0) ou mal lue, erreur de programmation. Action : passer la version rendue avec la clé publique par le service de clés.' );
		}
		return self::DOMAIN . $tenant_ref . $site_ref . pack( 'N', $version ) . $public_key;
	}

	/**
	 * Vérifie le certificat d'une clé de site : `$service_key_id` doit être l'identifiant d'une clé
	 * de `$pinned`, puis le certificat une signature de cette clé (vérification stricte du § 8) sur
	 * message(). Une rotation de la clé du service laisse l'ancienne listée : chaque clé épinglée
	 * dont l'identifiant correspond est essayée.
	 *
	 * Ne lève jamais pour des valeurs reçues : identifiant absent de la liste, liste vide, entrée
	 * de la liste qui n'est pas une chaîne de 32 octets (ignorée), arguments hors du contrat de
	 * message(), certificat de longueur fausse ou signature fausse rendent faux. Seule une panne
	 * lève : sans moteur, dès qu'une clé épinglée est à essayer, Sodium lève
	 * FormatError::CRYPTO_UNAVAILABLE, comme pour l'index. Rendre faux ferait conclure, en phase 4,
	 * à un certificat faux (`site_key_certificate_invalid`, donc à une plateforme ou à un service
	 * suspect) pour une simple absence de cryptographie.
	 *
	 * @param string       $tenant_ref     Référence de l'agence, 16 octets.
	 * @param string       $site_ref       Référence du site, 16 octets.
	 * @param int          $version        Version de la clé du site.
	 * @param string       $public_key     Clé publique X25519 du site, 32 octets.
	 * @param string       $certificate    Certificat reçu, 64 octets.
	 * @param string       $service_key_id Identifiant de la clé du service qui l'a signé, 16 caractères
	 *                                     hexadécimaux minuscules.
	 * @param array<mixed> $pinned         Liste épinglée : clés publiques Ed25519 du service, 32 octets
	 *                                     chacune (constante du code, jamais reçue de la plateforme).
	 * @param Sodium       $sodium         Moteur Ed25519.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur, une clé épinglée étant à essayer.
	 */
	public static function verify( string $tenant_ref, string $site_ref, int $version, string $public_key, string $certificate, string $service_key_id, array $pinned, Sodium $sodium ): bool {
		try {
			$message = self::message( $tenant_ref, $site_ref, $version, $public_key );
		} catch ( \InvalidArgumentException ) {
			return false;
		}

		foreach ( $pinned as $candidate ) {
			if ( ! is_string( $candidate ) || self::PUBLIC_KEY_BYTES !== strlen( $candidate ) ) {
				continue;
			}
			if ( ! hash_equals( substr( hash( 'sha256', $candidate ), 0, 16 ), $service_key_id ) ) {
				continue;
			}
			if ( $sodium->ed25519_verify( $certificate, $message, $candidate ) ) {
				return true;
			}
		}
		return false;
	}
}
