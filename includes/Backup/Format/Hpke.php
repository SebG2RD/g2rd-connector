<?php
/**
 * Scellement HPKE du format chiffré des sauvegardes (G2BK v1) : RFC 9180, mode base (0x00),
 * KEM DHKEM(X25519, HKDF-SHA256) (0x0020), KDF HKDF-SHA256 (0x0001), AEAD AES-256-GCM (0x0002),
 * envoi en une fois, numéro de séquence 0 (docs/backup-format.md § 5 et § 6, dépôt de la
 * plateforme).
 *
 * Usages, tous avec cette même suite (§ 6) :
 * - clé de données d'un segment : info « g2bk-v1/dek », aad = préfixe de l'en-tête (36 octets),
 *   clair = clé de données (32) ;
 * - rescellement pour une restauration : info « g2bk-v1/reseal », aad = backup_id (16) ‖
 *   segment_index (4) ‖ site_key_version (4) ;
 * - enveloppe de secours (D6) : info « g2rd-keys/recovery-v1 », aad = UTF-8 de
 *   « <tenant_ref>|<site_ref>|<version> ».
 * Le connecteur s'en sert pour sceller la clé de données (écrivain d'un segment) et pour ouvrir
 * (lecteur d'un segment ; rescellement reçu pour une restauration, phase 8) ; l'enveloppe de
 * secours est l'affaire du service de clés et de l'outil hors ligne.
 *
 * Formules, telles quelles (§ 5) :
 * - LabeledExtract( sel, label, ikm ) = HKDF-Extract( sel, « HPKE-v1 » ‖ suite_id ‖ label ‖ ikm ) ;
 *   LabeledExpand( prk, label, info, L ) = HKDF-Expand( prk, I2OSP( L, 2 ) ‖ « HPKE-v1 » ‖
 *   suite_id ‖ label ‖ info, L ) ; sel vide = clé HMAC vide (identique à 32 zéros) ;
 * - KEM (suite_id = « KEM » ‖ 00 20) : enc = X25519( skE, 9 ) ; dh = X25519( skE, pkR ), refusé
 *   s'il vaut 32 zéros ; kem_context = enc ‖ pkR ; eae_prk = LabeledExtract( "", « eae_prk », dh ) ;
 *   shared_secret = LabeledExpand( eae_prk, « shared_secret », kem_context, 32 ) ;
 * - programme de clés (suite_id = « HPKE » ‖ 00 20 ‖ 00 01 ‖ 00 02) : psk_id_hash =
 *   LabeledExtract( "", « psk_id_hash », "" ) ; info_hash = LabeledExtract( "", « info_hash »,
 *   info ) ; contexte = 00 ‖ psk_id_hash ‖ info_hash ; secret = LabeledExtract( shared_secret,
 *   « secret », "" ) ; key = LabeledExpand( secret, « key », contexte, 32 ) ; base_nonce =
 *   LabeledExpand( secret, « base_nonce », contexte, 12 ) ;
 * - chiffré = AES-256-GCM( key, base_nonce, aad, clair ), étiquette de 16 octets comprise (numéro
 *   de séquence 0 : le nonce est base_nonce tel quel).
 * À l'ouverture, pkR est recalculée depuis la clé secrète du destinataire (forme canonique), et
 * kem_context porte enc tel que reçu : un enc altéré, même en un bit que X25519 ignore (bit 255),
 * donne un autre secret partagé et ne s'ouvre pas.
 *
 * Sans BLAKE2b, au contraire de crypto_box_seal de sodium (décision n° 2) : sodium_compat calcule
 * BLAKE2b faux sous JIT « tracing » (§ 15). Les seules primitives sont X25519 (Sodium, natif ou
 * compat), HMAC-SHA256 (Hkdf) et AES-256-GCM (Aead, OpenSSL).
 *
 * Tirage : la clé secrète éphémère (32 octets) est tirée par $random( 32 ) à chaque scellement,
 * jamais réutilisée. Un écrivain de segment partage sa fermeture avec cette classe : la clé de
 * données, puis la clé éphémère (ordre des tirages des vecteurs, § 4).
 *
 * Effacement (rappel transverse n° 3 du plan de la phase 2) : clé éphémère, échange X25519,
 * eae_prk, secret partagé, secret, key et base_nonce sont effacés après usage par Sodium::wipe()
 * (sodium_memzero() en natif, écrasement au mieux sinon : PHP ne garantit pas l'effacement, et les
 * copies internes de hash_hmac() et d'OpenSSL ne sont pas atteignables). Le clair et la clé secrète
 * du destinataire appartiennent à l'appelant, qui les efface.
 *
 * Erreurs :
 * - seal() : clé publique du destinataire refusée (longueur, échange de 32 zéros) →
 *   FormatError::BAD_PUBLIC_KEY ; source d'aléa en panne, OpenSSL ou moteur sodium absents →
 *   FormatError::CRYPTO_UNAVAILABLE ; tirage injecté qui n'a pas 32 octets →
 *   \UnexpectedValueException (erreur de programmation) ;
 * - open() : rend null pour tout échec dû aux octets reçus (enc de mauvaise longueur, point
 *   d'ordre faible, authentification refusée, chiffré trop court) ; l'appelant le traduit en
 *   FormatError::SEAL_OPEN_FAILED. Une panne n'est pas un échec d'ouverture : moteur sodium ou
 *   OpenSSL absents → FormatError::CRYPTO_UNAVAILABLE. Clé secrète qui n'a pas 32 octets →
 *   \InvalidArgumentException (erreur de programmation : elle vient du code, jamais d'ailleurs).
 * Aucune clé ni aucun texte clair dans un message ; aucune exception chaînée.
 *
 * Classe pure, sans effet au chargement ni à la construction, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Crypto\Hkdf;
use G2RD\Connector\Backup\Crypto\Sodium;

final class Hpke {

	/** Mode base (RFC 9180 § 5.1.1) : ni PSK ni authentification de l'envoyeur. */
	public const MODE_BASE = 0;

	/** DHKEM(X25519, HKDF-SHA256) (RFC 9180 § 7.1). */
	public const KEM_ID = 0x0020;

	/** HKDF-SHA256 (RFC 9180 § 7.2). */
	public const KDF_ID = 0x0001;

	/** AES-256-GCM (RFC 9180 § 7.3). */
	public const AEAD_ID = 0x0002;

	/** Taille de enc : clé publique éphémère X25519 (Nenc). */
	public const ENC_BYTES = 32;

	/** Clé secrète X25519, éphémère ou du destinataire (Nsk). */
	private const SECRET_BYTES = 32;

	/** Secret partagé du KEM (Nsecret). */
	private const SHARED_SECRET_BYTES = 32;

	/** Étiquette de version de RFC 9180 (§ 4). */
	private const VERSION_LABEL = 'HPKE-v1';

	/** suite_id du KEM : « KEM » ‖ I2OSP( KEM_ID, 2 ). */
	private const KEM_SUITE_ID = "KEM\x00\x20";

	/** suite_id du programme de clés : « HPKE » ‖ I2OSP( KEM_ID, 2 ) ‖ I2OSP( KDF_ID, 2 ) ‖ I2OSP( AEAD_ID, 2 ). */
	private const HPKE_SUITE_ID = "HPKE\x00\x20\x00\x01\x00\x02";

	private Sodium $sodium;

	/**
	 * Source d'aléa : reçoit un nombre d'octets, rend autant d'octets aléatoires.
	 *
	 * @var \Closure( int ): string
	 */
	private \Closure $random;

	/**
	 * @param Sodium                          $sodium X25519 et effacement, par sodium natif ou par sodium_compat.
	 * @param ( \Closure( int ): string )|null $random Source d'aléa, pour les tests (vecteurs) et pour
	 *                                                l'écrivain d'un segment ; par défaut random_bytes().
	 */
	public function __construct( Sodium $sodium, ?\Closure $random = null ) {
		$this->sodium = $sodium;
		$this->random = $random ?? static fn ( int $length ): string => random_bytes( $length );
	}

	/**
	 * Scelle $plaintext vers la clé publique X25519 $recipient_public.
	 *
	 * @param string $recipient_public Clé publique X25519 du destinataire, 32 octets.
	 * @param string $info             Usage (« g2bk-v1/dek », « g2bk-v1/reseal »…).
	 * @param string $aad              Données associées de l'usage.
	 * @param string $plaintext        Clair, éventuellement vide.
	 *
	 * @return array{enc: string, ciphertext: string} enc (32 octets) et chiffré ‖ étiquette
	 *                                                (strlen( $plaintext ) + 16 octets).
	 *
	 * @throws FormatError               BAD_PUBLIC_KEY : clé du destinataire refusée ;
	 *                                   CRYPTO_UNAVAILABLE : aléa, OpenSSL ou moteur sodium en panne.
	 * @throws \UnexpectedValueException Tirage injecté qui n'a pas 32 octets : erreur de programmation.
	 */
	public function seal( string $recipient_public, string $info, string $aad, string $plaintext ): array {
		$ephemeral = $this->draw_ephemeral_secret();
		$dh        = '';
		$shared    = '';
		try {
			$enc    = $this->sodium->x25519_base( $ephemeral );
			$dh     = $this->sodium->x25519( $ephemeral, $recipient_public );
			$shared = $this->kem_shared_secret( $dh, $enc, $recipient_public );
			return [
				'enc'        => $enc,
				'ciphertext' => $this->aead_seal( $shared, $info, $aad, $plaintext ),
			];
		} finally {
			$this->sodium->wipe( $ephemeral );
			$this->sodium->wipe( $dh );
			$this->sodium->wipe( $shared );
		}
	}

	/**
	 * Ouvre un scellement destiné à la clé secrète X25519 $recipient_secret.
	 *
	 * @param string $recipient_secret Clé secrète X25519 du destinataire, 32 octets.
	 * @param string $enc              Clé publique éphémère reçue, 32 octets attendus.
	 * @param string $info             Usage attendu.
	 * @param string $aad              Données associées attendues.
	 * @param string $ciphertext       Chiffré ‖ étiquette reçu.
	 *
	 * @return string|null Le clair ('' pour un clair vide), ou null si l'ouverture échoue (enc de
	 *                     mauvaise longueur, point d'ordre faible, mauvais destinataire, info, aad,
	 *                     enc ou chiffré altérés).
	 *
	 * @throws FormatError               CRYPTO_UNAVAILABLE : moteur sodium ou OpenSSL absents.
	 * @throws \InvalidArgumentException Clé secrète qui n'a pas 32 octets : erreur de programmation.
	 */
	public function open( string $recipient_secret, string $enc, string $info, string $aad, string $ciphertext ): ?string {
		if ( self::SECRET_BYTES !== strlen( $recipient_secret ) ) {
			throw new \InvalidArgumentException( 'Ouverture HPKE impossible : la clé secrète X25519 du destinataire doit faire 32 octets. Cause probable : erreur de programmation (clé tronquée, ou passée en hexadécimal). Action : passer les 32 octets bruts de la clé secrète.' );
		}
		// Clé publique recalculée depuis la clé secrète (forme canonique), comme le générateur et
		// le service de clés ; lève CRYPTO_UNAVAILABLE sans moteur.
		$recipient_public = $this->sodium->x25519_base( $recipient_secret );
		if ( self::ENC_BYTES !== strlen( $enc ) ) {
			return null;
		}

		$dh = $this->exchange_or_null( $recipient_secret, $enc );
		if ( null === $dh ) {
			return null;
		}
		$shared = '';
		try {
			$shared = $this->kem_shared_secret( $dh, $enc, $recipient_public );
			[ $key, $nonce ] = $this->key_schedule( $shared, $info );
			try {
				return Aead::open( $key, $nonce, $aad, $ciphertext );
			} finally {
				$this->sodium->wipe( $key );
				$this->sodium->wipe( $nonce );
			}
		} finally {
			$this->sodium->wipe( $dh );
			$this->sodium->wipe( $shared );
		}
	}

	/**
	 * Clé secrète éphémère, tirée par la source d'aléa.
	 *
	 * @throws FormatError               CRYPTO_UNAVAILABLE : la source d'aléa lève (random_bytes()
	 *                                   sans source d'aléa sûre).
	 * @throws \UnexpectedValueException Tirage qui n'a pas 32 octets.
	 */
	private function draw_ephemeral_secret(): string {
		try {
			$secret = ( $this->random )( self::SECRET_BYTES );
		} catch ( \Exception ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
		if ( self::SECRET_BYTES !== strlen( $secret ) ) {
			$this->sodium->wipe( $secret );
			throw new \UnexpectedValueException( 'Scellement HPKE impossible : la source d\'aléa doit rendre 32 octets pour la clé éphémère. Cause probable : erreur de programmation (fermeture d\'aléa injectée fausse). Action : passer une fermeture qui rend random_bytes( $longueur ), ou aucune.' );
		}
		return $secret;
	}

	/**
	 * Échange X25519 de l'ouverture, ou null si enc est refusé (échange de 32 zéros : point d'ordre
	 * faible). Une panne (CRYPTO_UNAVAILABLE) n'est pas un refus : elle remonte.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur.
	 */
	private function exchange_or_null( string $recipient_secret, string $enc ): ?string {
		try {
			return $this->sodium->x25519( $recipient_secret, $enc );
		} catch ( FormatError $e ) {
			if ( FormatError::BAD_PUBLIC_KEY !== $e->reason() ) {
				throw $e;
			}
			return null;
		}
	}

	/**
	 * Secret partagé du KEM (RFC 9180 § 4.1, ExtractAndExpand) : eae_prk, puis shared_secret sur
	 * kem_context = enc ‖ pkR. L'appelant efface le résultat.
	 */
	private function kem_shared_secret( string $dh, string $enc, string $recipient_public ): string {
		$eae_prk = $this->labeled_extract( '', 'eae_prk', $dh, self::KEM_SUITE_ID );
		try {
			return self::labeled_expand( $eae_prk, 'shared_secret', $enc . $recipient_public, self::SHARED_SECRET_BYTES, self::KEM_SUITE_ID );
		} finally {
			$this->sodium->wipe( $eae_prk );
		}
	}

	/**
	 * Chiffré ‖ étiquette sous la clé et le nonce du programme de clés, effacés avant de rendre.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE : OpenSSL absent ou en échec.
	 */
	private function aead_seal( string $shared_secret, string $info, string $aad, string $plaintext ): string {
		[ $key, $nonce ] = $this->key_schedule( $shared_secret, $info );
		try {
			return Aead::seal( $key, $nonce, $aad, $plaintext );
		} finally {
			$this->sodium->wipe( $key );
			$this->sodium->wipe( $nonce );
		}
	}

	/**
	 * Programme de clés du mode base (RFC 9180 § 5.1) : clé AES-256-GCM (32 octets) et base_nonce
	 * (12 octets), que l'appelant efface ; secret, intermédiaire, effacé ici.
	 *
	 * @return array{0: string, 1: string} key, base_nonce.
	 */
	private function key_schedule( string $shared_secret, string $info ): array {
		$psk_id_hash = $this->labeled_extract( '', 'psk_id_hash', '', self::HPKE_SUITE_ID );
		$info_hash   = $this->labeled_extract( '', 'info_hash', $info, self::HPKE_SUITE_ID );
		$context     = chr( self::MODE_BASE ) . $psk_id_hash . $info_hash;
		$secret      = $this->labeled_extract( $shared_secret, 'secret', '', self::HPKE_SUITE_ID );
		try {
			return [
				self::labeled_expand( $secret, 'key', $context, Aead::KEY_BYTES, self::HPKE_SUITE_ID ),
				self::labeled_expand( $secret, 'base_nonce', $context, Aead::NONCE_BYTES, self::HPKE_SUITE_ID ),
			];
		} finally {
			$this->sodium->wipe( $secret );
		}
	}

	/**
	 * LabeledExtract (RFC 9180 § 4) ; l'entrée étiquetée, qui contient l'IKM (échange X25519),
	 * est effacée après usage.
	 */
	private function labeled_extract( string $salt, string $label, string $ikm, string $suite_id ): string {
		$labeled_ikm = self::VERSION_LABEL . $suite_id . $label . $ikm;
		try {
			return Hkdf::extract( $salt, $labeled_ikm );
		} finally {
			$this->sodium->wipe( $labeled_ikm );
		}
	}

	/**
	 * LabeledExpand (RFC 9180 § 4) : I2OSP( L, 2 ) en tête de l'info étiquetée.
	 */
	private static function labeled_expand( string $prk, string $label, string $info, int $length, string $suite_id ): string {
		return Hkdf::expand( $prk, pack( 'n', $length ) . self::VERSION_LABEL . $suite_id . $label . $info, $length );
	}
}
