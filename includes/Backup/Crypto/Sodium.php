<?php
/**
 * X25519 et Ed25519 du format chiffré des sauvegardes (G2BK v1), par sodium natif ou par la copie
 * de sodium_compat que livre WordPress.
 *
 * Pourquoi sodium : l'extension OpenSSL de PHP n'offre X25519 et Ed25519 qu'à partir de PHP 8.4,
 * et le plancher du connecteur est 8.1. Un moteur « openssl » pour PHP ≥ 8.4 est envisagé, tranché
 * au plan de la phase 4 (choix d'interprétation n° 13 du plan de la phase 2) : backend() rend une
 * constante, jamais un booléen, pour pouvoir en ajouter un sans toucher au format. Aucun BLAKE2b
 * n'est employé (défaut connu de sodium_compat sous JIT).
 *
 * Moteurs :
 * - « native » : l'extension sodium est chargée (extension_loaded) ; fonctions globales sodium_*,
 *   sauf le contrôle n° 5 de la vérification (ci-dessous).
 * - « compat » : pas d'extension, mais les classes ParagonIE_Sodium_Compat et
 *   ParagonIE_Sodium_Core_Ed25519 sont disponibles (WordPress les charge lui-même quand l'extension
 *   manque, depuis wp-includes/sodium_compat) ; appels
 *   explicites aux méthodes statiques de ParagonIE_Sodium_Compat, jamais aux fonctions globales,
 *   qui peuvent être natives ou non. PHP pur : 70 à 160 ms par opération, mesuré le 24/10/2026.
 * - « none » : ni l'un ni l'autre ; chaque opération lève FormatError::CRYPTO_UNAVAILABLE.
 * Chaque appel à un moteur est écrit dans une méthode privée dont le nom finit par _native ou par
 * _compat, et nulle part ailleurs : les tests le contrôlent à la lecture du code. Seule exception :
 * le calcul du contrôle n° 5 ci-dessous, commun aux deux moteurs (main_subgroup, with_fast_mult),
 * qui n'emploie que les classes Core de sodium_compat et sa propriété $fastMult.
 *
 * Le choix se fait par extension_loaded( 'sodium' ), **jamais** par function_exists() : sans
 * l'extension, sodium_compat définit lui-même des fonctions globales sodium_* (lib/php72compat.php),
 * qui passeraient pour natives.
 *
 * Vérification Ed25519 stricte (docs/backup-format.md § 8, dépôt de la plateforme), faite par la
 * classe elle-même, avant le vérificateur et avec le même code pour les deux moteurs :
 *  1. S < L (signature canonique) ;
 *  2. clé et R hors de la liste des points d'ordre faible de libsodium (ge25519_has_small_order(),
 *     octets 0 à 30 tels quels, octet 31 masqué par 0x7f) ;
 *  3. clé non nulle ;
 *  4. encodages canoniques de la clé et de R (y < p = 2²⁵⁵ − 19), que sodium_compat ne contrôle
 *     pas ;
 *  5. clé décodée en un point du sous-groupe principal (L·A = neutre), par un seul calcul, le même
 *     pour les deux moteurs (méthode main_subgroup) : ParagonIE_Sodium_Core_Ed25519::ge_frombytes_negate_vartime()
 *     décode la clé (en −A), puis ge_double_scalarmult_vartime( L, −A, 0 ) calcule L·(−A), neutre
 *     si et seulement si L·A l'est ; la clé n'est acceptée que si X est nul et Y − Z nul.
 *     Aucune conversion vers Curve25519 n'y sert :
 *     - sodium_crypto_sign_ed25519_pk_to_curve25519() (natif) accepte une clé d'ordre 2·L :
 *       libsodium (1.0.18 et 1.0.20, ge25519_is_on_main_subgroup()) ne teste que X nul après la
 *       multiplication par L, et pour A + (0, −1), L·A vaut (0, −1) ; sodium natif accepte alors
 *       la signature que le détenteur de la clé fabrique (relecture de la tâche 13, 24/10/2026) ;
 *     - pk_to_curve25519 et ge_mul_l de sodium_compat : les copies que livre WordPress (1.24.0,
 *       tout le parc en WordPress 6.9.10 à 7.1.3, et celle de 6.4, proche de 1.20.1) ne contrôlent
 *       pas le sous-groupe (avis PKSA-32g2-byr9-drtw, corrigé en 1.24.1) et acceptent avec elles
 *       la clé d'ordre mixte, comme leur vérification des signatures.
 *     Les classes Core de sodium_compat viennent, en compat, de la copie de WordPress déjà chargée ;
 *     en natif, du chargeur (load_compat), appelé au plus une fois, à la première vérification qui
 *     atteint ce contrôle (jamais par backend(), ni au chargement), résultat mémorisé. Le chargeur
 *     par défaut, en natif, ne charge que le chargeur de classes de WordPress
 *     (wp-includes/sodium_compat/autoload-php7.php, qui ne définit aucune fonction globale, mesuré
 *     le 24/10/2026 dans les copies de 6.4 et de 1.24.0). Classes absentes, ou chargeur qui lève :
 *     la vérification rend faux, refus sûr que l'autotest signale (SelfTest, TEST 1 refusé).
 *     Ces méthodes sont marquées @internal par sodium_compat ; présentes et justes de la copie
 *     de 6.4 à 1.24.2 (mesure du 24/10/2026). Une copie future qui les changerait (ou celle d'une
 *     extension chargée avant le connecteur) serait vue par l'autotest (TEST 1 refusé, clé d'ordre
 *     2·L acceptée) et par le rejeu du groupe sodium-compat des tests.
 *     ParagonIE_Sodium_Compat::$fastMult est mis à vrai le temps du calcul, puis rétabli
 *     (la clé est publique : aucun temps constant requis) ; coût mesuré le 24/10/2026 : en compat,
 *     180 à 230 ms sans $fastMult, 17 à 52 ms avec ; en natif, environ 32 ms avec (moins de 0,1 ms
 *     avant, mais faux pour l'ordre 2·L), et la vérification entière, chargeur par défaut, 23 à
 *     53 ms avec la copie de WordPress 7.1.3, 50 à 90 ms avec celle de 6.4 (deux séries de 15,
 *     poste chargé par d'autres travaux) : quelques vérifications par sauvegarde, jamais une liste.
 *     En PHP 32 bits, sodium_compat passe par ses classes Core32, pour lesquelles ce calcul n'est
 *     pas écrit : la vérification rend alors faux dans les deux moteurs, refus sûr que l'autotest
 *     signale (TEST 1 refusé, self_test_failed).
 *     Toute exception de ce contrôle est un refus (\SodiumException ; \RangeException, que
 *     sodium_compat lève pour une clé hors de la courbe, « Internal check failed. » ; \Error d'une
 *     méthode interne absente), et le vérificateur n'est alors jamais appelé.
 *
 * Exceptions de sodium : chaque appel est entouré d'un try/catch ; une \SodiumException devient un
 * code stable, sans le message d'origine (x25519, x25519_base : FormatError::BAD_PUBLIC_KEY ;
 * ed25519_sign, ed25519_keypair_from_seed : FormatError::CRYPTO_UNAVAILABLE ; ed25519_verify :
 * faux). Aucune clé, aucun texte clair dans un message.
 *
 * Classe pure : rien au chargement ni à la construction (le moteur est choisi au premier appel ;
 * en natif, les classes du contrôle n° 5 sont chargées à la première vérification), aucune
 * fonction WordPress (seules les constantes ABSPATH et WPINC, pour charger sodium_compat).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Crypto;

use G2RD\Connector\Backup\Format\FormatError;

final class Sodium {

	public const BACKEND_NATIVE = 'native';
	public const BACKEND_COMPAT = 'compat';
	public const BACKEND_NONE   = 'none';

	/** Clé X25519 (secrète ou publique), clé publique et graine Ed25519. */
	private const KEY_BYTES = 32;

	/** Clé secrète Ed25519 au format de sodium : graine ‖ clé publique. */
	private const ED25519_SECRET_BYTES = 64;

	private const SIGNATURE_BYTES = 64;

	/** L = 2²⁵² + 27742317777372353535851937790883648493, ordre du sous-groupe principal, petit-boutiste. */
	private const GROUP_ORDER = 'edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010';

	/** P = 2²⁵⁵ − 19, petit-boutiste. */
	private const FIELD_PRIME = 'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f';

	/**
	 * Liste des points d'ordre faible de libsodium (ge25519_has_small_order(), ed25519_ref10.c,
	 * sources 1.0.18 et 1.0.20), recopiée de docs/backup-format.md § 8.2 (dépôt de la plateforme).
	 * La liste de ParagonIE_Sodium_Core_Ed25519::small_order() n'est pas équivalente (même §).
	 */
	private const SMALL_ORDER_POINTS = [
		'0000000000000000000000000000000000000000000000000000000000000000', // 0 (ordre 4).
		'0100000000000000000000000000000000000000000000000000000000000000', // 1 (ordre 1).
		'26e8958fc2b227b045c3f489f2ef98f0d5dfac05d3c63339b13802886d53fc05', // Ordre 8.
		'c7176a703d4dd84fba3c0b760d10670f2a2053fa2c39ccc64ec7fd7792ac037a', // Ordre 8.
		'ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f', // P − 1 (ordre 2).
		'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f', // P (= 0, ordre 4).
		'eeffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f', // P + 1 (= 1, ordre 1).
	];

	/**
	 * Dit si une extension PHP est chargée (par défaut extension_loaded()).
	 *
	 * @var \Closure( string ): bool
	 */
	private \Closure $extension_loaded;

	/**
	 * Charge sodium_compat si besoin et dit si ses classes (ParagonIE_Sodium_Compat et
	 * ParagonIE_Sodium_Core_Ed25519) sont disponibles. Sert aux deux moteurs : sans l'extension,
	 * pour choisir compat ; avec, pour le calcul du contrôle n° 5.
	 *
	 * @var \Closure(): bool
	 */
	private \Closure $load_compat;

	/**
	 * Vérificateur du moteur remplacé (tests seulement) : signature, message, clé publique.
	 *
	 * @var ( \Closure( string, string, string ): bool )|null
	 */
	private ?\Closure $verifier;

	/** Moteur choisi, mémorisé au premier appel. */
	private ?string $backend = null;

	/** Réponse du chargeur de sodium_compat, mémorisée à son premier appel. */
	private ?bool $compat_loaded = null;

	/**
	 * @param ( \Closure( string ): bool )|null                 $extension_loaded Par défaut extension_loaded() ; appelée avec « sodium ».
	 * @param ( \Closure(): bool )|null                         $load_compat      Par défaut load_wordpress_compat() : charge le sodium_compat
	 *                                                                            de WordPress (le chargeur complet sans l'extension, le seul
	 *                                                                            chargeur de classes avec), puis dit si ses classes existent.
	 *                                                                            Appelé au plus une fois : par backend() sans l'extension, à
	 *                                                                            la première vérification (contrôle n° 5) avec.
	 * @param ( \Closure( string, string, string ): bool )|null $verifier         Vérificateur du moteur remplacé, pour les tests : appelé
	 *                                                                            après les cinq contrôles stricts, à la place de celui du moteur.
	 */
	public function __construct( ?\Closure $extension_loaded = null, ?\Closure $load_compat = null, ?\Closure $verifier = null ) {
		$is_loaded              = $extension_loaded ?? static fn ( string $name ): bool => extension_loaded( $name );
		$this->extension_loaded = $is_loaded;
		$this->load_compat      = $load_compat ?? static fn (): bool => self::load_wordpress_compat( $is_loaded( 'sodium' ) );
		$this->verifier         = $verifier;
	}

	/**
	 * Moteur employé : BACKEND_NATIVE si l'extension sodium est chargée, sinon BACKEND_COMPAT si
	 * sodium_compat est disponible, sinon BACKEND_NONE. Choisi au premier appel, puis mémorisé.
	 * Avec l'extension, le chargeur de sodium_compat n'est pas appelé ici.
	 */
	public function backend(): string {
		if ( null === $this->backend ) {
			if ( ( $this->extension_loaded )( 'sodium' ) ) {
				$this->backend = self::BACKEND_NATIVE;
			} elseif ( $this->compat_loaded() ) {
				$this->backend = self::BACKEND_COMPAT;
			} else {
				$this->backend = self::BACKEND_NONE;
			}
		}
		return $this->backend;
	}

	/**
	 * Clé publique X25519 d'une clé secrète.
	 *
	 * @param string $secret Clé secrète X25519, 32 octets.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur ; BAD_PUBLIC_KEY pour une longueur fausse
	 *                     ou un résultat de 32 zéros.
	 */
	public function x25519_base( string $secret ): string {
		$backend = $this->engine();
		if ( self::KEY_BYTES !== strlen( $secret ) ) {
			throw new FormatError( FormatError::BAD_PUBLIC_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		try {
			$public_key = self::BACKEND_NATIVE === $backend ? self::scalarmult_native( $secret, null ) : self::scalarmult_compat( $secret, null );
		} catch ( \SodiumException ) {
			throw new FormatError( FormatError::BAD_PUBLIC_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
		return self::non_zero( $public_key );
	}

	/**
	 * Échange X25519 (RFC 7748).
	 *
	 * @param string $secret     Clé secrète X25519, 32 octets.
	 * @param string $public_key Clé publique X25519 de l'autre partie, 32 octets.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur ; BAD_PUBLIC_KEY pour une longueur fausse
	 *                     ou un résultat de 32 zéros (point d'ordre faible).
	 */
	public function x25519( string $secret, string $public_key ): string {
		$backend = $this->engine();
		if ( self::KEY_BYTES !== strlen( $secret ) || self::KEY_BYTES !== strlen( $public_key ) ) {
			throw new FormatError( FormatError::BAD_PUBLIC_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		try {
			$shared = self::BACKEND_NATIVE === $backend ? self::scalarmult_native( $secret, $public_key ) : self::scalarmult_compat( $secret, $public_key );
		} catch ( \SodiumException ) {
			// Le natif lève sur un résultat nul, sodium_compat sur une clé publique nulle.
			throw new FormatError( FormatError::BAD_PUBLIC_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
		return self::non_zero( $shared );
	}

	/**
	 * Paire Ed25519 déterministe d'une graine (RFC 8032 § 5.1.5).
	 *
	 * @param string $seed Graine, 32 octets.
	 *
	 * @return array{public: string, secret: string} Clé publique (32 octets) et clé secrète au
	 *                                               format de sodium (graine ‖ clé publique, 64 octets).
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur ou si le moteur échoue.
	 * @throws \InvalidArgumentException Graine qui n'a pas 32 octets : erreur de programmation.
	 */
	public function ed25519_keypair_from_seed( string $seed ): array {
		$backend = $this->engine();
		if ( self::KEY_BYTES !== strlen( $seed ) ) {
			throw new \InvalidArgumentException( 'Paire Ed25519 impossible : la graine doit faire 32 octets. Cause probable : erreur de programmation. Action : passer une graine de 32 octets tirée par random_bytes().' );
		}
		try {
			return self::BACKEND_NATIVE === $backend ? self::keypair_native( $seed ) : self::keypair_compat( $seed );
		} catch ( \SodiumException ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
	}

	/**
	 * Signature Ed25519 détachée (64 octets).
	 *
	 * @param string $message Octets signés.
	 * @param string $secret  Clé secrète au format de sodium (graine ‖ clé publique), 64 octets.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur ou si le moteur échoue.
	 * @throws \InvalidArgumentException Clé secrète qui n'a pas 64 octets : erreur de programmation.
	 */
	public function ed25519_sign( string $message, string $secret ): string {
		$backend = $this->engine();
		if ( self::ED25519_SECRET_BYTES !== strlen( $secret ) ) {
			throw new \InvalidArgumentException( 'Signature Ed25519 impossible : la clé secrète doit faire 64 octets (graine et clé publique). Cause probable : erreur de programmation. Action : passer la clé « secret » rendue par ed25519_keypair_from_seed().' );
		}
		try {
			return self::BACKEND_NATIVE === $backend ? self::sign_native( $message, $secret ) : self::sign_compat( $message, $secret );
		} catch ( \SodiumException ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
	}

	/**
	 * Vérification Ed25519 stricte d'une signature détachée (voir le docblock de la classe). Ne
	 * lève jamais pour des octets reçus : longueurs fausses, contrôle strict refusé ou exception
	 * du moteur rendent faux.
	 *
	 * @param string $signature  Signature détachée, 64 octets.
	 * @param string $message    Octets signés.
	 * @param string $public_key Clé publique Ed25519, 32 octets.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur : une panne n'est pas une signature
	 *                     invalide.
	 */
	public function ed25519_verify( string $signature, string $message, string $public_key ): bool {
		$backend = $this->engine();
		if ( self::SIGNATURE_BYTES !== strlen( $signature ) || self::KEY_BYTES !== strlen( $public_key ) ) {
			return false;
		}
		if ( ! self::passes_byte_checks( $signature, $public_key ) ) {
			return false;
		}
		// Contrôle n° 5, le même calcul pour les deux moteurs ; en natif, ses classes sont chargées
		// ici, à la première vérification (en compat, backend() les a déjà trouvées).
		if ( ! $this->compat_loaded() || ! self::main_subgroup( $public_key ) ) {
			return false;
		}
		if ( self::BACKEND_NATIVE === $backend ) {
			return $this->verify_native( $signature, $message, $public_key );
		}
		return $this->verify_compat( $signature, $message, $public_key );
	}

	/**
	 * Efface des octets secrets (clé, sous-clé, clair) après usage. Ne lève jamais.
	 *
	 * En natif, sodium_memzero() écrase la mémoire de la chaîne (si elle n'est pas partagée) et
	 * laisse la variable à null : elle est remise à une chaîne vide, pour garder le type.
	 *
	 * Sinon (compat, et aussi sans moteur : l'effacement sert dans les `finally` et ne doit ni lever
	 * ni laisser la clé en place), écrasement par une chaîne de zéros de même longueur. PHP ne
	 * garantit pas l'effacement : l'ancienne chaîne peut rester en mémoire jusqu'à sa réutilisation.
	 * Jamais sodium_memzero() ni ParagonIE_Sodium_Compat::memzero() sans sodium natif : tous deux
	 * lèvent alors une SodiumException (« not implemented in sodium_compat », relevé dans 1.24.0,
	 * la copie du parc, et dans 2.5.2 ; le sodium_memzero() global que WordPress définit sans
	 * l'extension appelle la même méthode).
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	public function wipe( string &$bytes ): void {
		if ( self::BACKEND_NATIVE === $this->backend() ) {
			self::wipe_native( $bytes );
			return;
		}
		self::overwrite( $bytes );
	}

	/**
	 * Moteur disponible, ou FormatError::CRYPTO_UNAVAILABLE.
	 *
	 * @throws FormatError CRYPTO_UNAVAILABLE sans moteur.
	 */
	private function engine(): string {
		$backend = $this->backend();
		if ( self::BACKEND_NONE === $backend ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		return $backend;
	}

	/**
	 * Réponse du chargeur de sodium_compat, appelé au plus une fois puis mémorisée. Un chargeur qui
	 * lève (fichier de WordPress illisible ou cassé) vaut des classes absentes : aucun moteur sans
	 * l'extension, refus sûr de chaque vérification avec.
	 */
	private function compat_loaded(): bool {
		if ( null === $this->compat_loaded ) {
			try {
				$this->compat_loaded = true === ( $this->load_compat )();
			} catch ( \Throwable ) {
				$this->compat_loaded = false;
			}
		}
		return $this->compat_loaded;
	}

	/**
	 * Refuse un résultat X25519 de 32 zéros (point d'ordre faible).
	 *
	 * @throws FormatError BAD_PUBLIC_KEY.
	 */
	private static function non_zero( string $output ): string {
		if ( self::KEY_BYTES !== strlen( $output ) || hash_equals( str_repeat( "\0", self::KEY_BYTES ), $output ) ) {
			throw new FormatError( FormatError::BAD_PUBLIC_KEY ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		return $output;
	}

	/**
	 * Contrôles stricts n° 1 à 4 (docblock de la classe), par comparaisons d'octets, les mêmes pour
	 * les deux moteurs. Sans temps constant : seules des valeurs publiques y passent.
	 */
	private static function passes_byte_checks( string $signature, string $public_key ): bool {
		$r = substr( $signature, 0, 32 );

		// N° 1 : S < L.
		if ( ! self::less_than( substr( $signature, 32 ), self::bin( self::GROUP_ORDER ) ) ) {
			return false;
		}
		// N° 2 : clé et R hors de la liste d'ordre faible de libsodium.
		if ( self::has_small_order( $public_key ) || self::has_small_order( $r ) ) {
			return false;
		}
		// N° 3 : clé non nulle (déjà dans la liste ; écrit pour que le refus n'en dépende pas).
		if ( str_repeat( "\0", self::KEY_BYTES ) === $public_key ) {
			return false;
		}
		// N° 4 : encodages canoniques (y < P) de la clé et de R.
		return self::is_canonical( $public_key ) && self::is_canonical( $r );
	}

	/**
	 * Contrôle n° 2 : 255 bits bas égaux à ceux d'une entrée de la liste, quel que soit le bit de
	 * signe (règle de ge25519_has_small_order()).
	 */
	private static function has_small_order( string $encoding ): bool {
		$masked = substr( $encoding, 0, 31 ) . chr( ord( $encoding[31] ) & 0x7f );
		foreach ( self::SMALL_ORDER_POINTS as $hex ) {
			$entry = self::bin( $hex );
			if ( substr( $entry, 0, 31 ) . chr( ord( $entry[31] ) & 0x7f ) === $masked ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Contrôle n° 4 : y lu (bit de signe 255 mis à zéro) < P, comme ge25519_is_canonical().
	 */
	private static function is_canonical( string $encoding ): bool {
		$y = substr( $encoding, 0, 31 ) . chr( ord( $encoding[31] ) & 0x7f );
		return self::less_than( $y, self::bin( self::FIELD_PRIME ) );
	}

	/**
	 * Vrai si $a < $b, deux entiers de 32 octets petit-boutistes.
	 */
	private static function less_than( string $a, string $b ): bool {
		for ( $i = 31; $i >= 0; --$i ) {
			$x = ord( $a[ $i ] );
			$y = ord( $b[ $i ] );
			if ( $x !== $y ) {
				return $x < $y;
			}
		}
		return false;
	}

	/**
	 * Octets d'une constante hexadécimale de la classe.
	 */
	private static function bin( string $hex ): string {
		return (string) hex2bin( $hex );
	}

	/**
	 * Écrasement par des zéros de même longueur (voir wipe()).
	 *
	 * @param string $bytes Octets à écraser, remplacés en place.
	 */
	private static function overwrite( string &$bytes ): void {
		$bytes = str_repeat( "\0", strlen( $bytes ) );
	}

	// -----------------------------------------------------------------------------------------
	// Appels au moteur natif : fonctions globales de l'extension sodium, ici seulement.
	// -----------------------------------------------------------------------------------------

	/**
	 * X25519 natif ; avec la clé publique de base si $public_key est null.
	 *
	 * @throws \SodiumException Échec de l'extension (résultat nul compris).
	 */
	private static function scalarmult_native( string $secret, ?string $public_key ): string {
		return null === $public_key ? sodium_crypto_scalarmult_base( $secret ) : sodium_crypto_scalarmult( $secret, $public_key );
	}

	/**
	 * Paire Ed25519 native ; la paire concaténée de sodium est effacée.
	 *
	 * @param non-empty-string $seed Graine de 32 octets, longueur contrôlée par l'appelant.
	 *
	 * @return array{public: string, secret: string}
	 *
	 * @throws \SodiumException Échec de l'extension.
	 */
	private static function keypair_native( string $seed ): array {
		$pair = sodium_crypto_sign_seed_keypair( $seed );
		try {
			return [
				'public' => sodium_crypto_sign_publickey( $pair ),
				'secret' => sodium_crypto_sign_secretkey( $pair ),
			];
		} finally {
			self::wipe_native( $pair );
		}
	}

	/**
	 * Signature Ed25519 native.
	 *
	 * @param string           $message Octets signés.
	 * @param non-empty-string $secret  Clé secrète de 64 octets, longueur contrôlée par l'appelant.
	 *
	 * @throws \SodiumException Échec de l'extension.
	 */
	private static function sign_native( string $message, string $secret ): string {
		return sodium_crypto_sign_detached( $message, $secret );
	}

	/**
	 * Vérificateur natif (ou celui des tests), appelé après le contrôle n° 5. Toute exception rend
	 * faux.
	 *
	 * @param non-empty-string $signature  Signature de 64 octets, longueur contrôlée par l'appelant.
	 * @param string           $message    Octets signés.
	 * @param non-empty-string $public_key Clé publique de 32 octets, longueur contrôlée par l'appelant.
	 */
	private function verify_native( string $signature, string $message, string $public_key ): bool {
		try {
			if ( null !== $this->verifier ) {
				return true === ( $this->verifier )( $signature, $message, $public_key );
			}
			return true === sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Effacement natif, seule méthode qui appelle sodium_memzero().
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	private static function wipe_native( string &$bytes ): void {
		sodium_memzero( $bytes );
		$bytes = '';
	}

	// -----------------------------------------------------------------------------------------
	// Appels à sodium_compat : méthodes statiques de ses classes, ici seulement.
	// -----------------------------------------------------------------------------------------

	/**
	 * Chargeur par défaut (modèle : Settings::sodium_available()). Sans WordPress (ABSPATH ou
	 * WPINC non définie), ou si les classes Core sont déjà chargeables (copie chargée avant le
	 * connecteur, par WordPress ou une extension), ne charge rien. Sinon :
	 * - sans l'extension : autoload.php, le chargeur complet. WordPress le charge déjà lui-même
	 *   dans ce cas (wp-includes/compat.php) : require_once ne le recharge pas ;
	 * - avec l'extension (classes demandées pour le contrôle n° 5 seulement) : autoload-php7.php
	 *   seul, chargeur de classes qui ne définit aucune fonction globale (mesuré le 24/10/2026 dans
	 *   les copies de 6.4 et de 1.24.0) ; autoload.php, lui, définit même avec l'extension 55
	 *   fonctions globales de l'espace de noms Sodium\ (mêmes copies, mesuré le 24/10/2026),
	 *   inutiles ici.
	 *
	 * @param bool $extension_loaded Vrai si l'extension sodium est chargée.
	 */
	private static function load_wordpress_compat( bool $extension_loaded ): bool {
		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && ! class_exists( 'ParagonIE_Sodium_Core_Ed25519' ) ) {
			$compat = ABSPATH . WPINC . '/sodium_compat/' . ( $extension_loaded ? 'autoload-php7.php' : 'autoload.php' );
			if ( is_readable( $compat ) ) {
				require_once $compat;
			}
		}
		return class_exists( 'ParagonIE_Sodium_Compat' ) && class_exists( 'ParagonIE_Sodium_Core_Ed25519' );
	}

	/**
	 * X25519 de sodium_compat ; avec la clé publique de base si $public_key est null.
	 *
	 * @throws \SodiumException Échec de sodium_compat (clé publique nulle, résultat nul).
	 */
	private static function scalarmult_compat( string $secret, ?string $public_key ): string {
		return null === $public_key
			? \ParagonIE_Sodium_Compat::crypto_scalarmult_base( $secret )
			: \ParagonIE_Sodium_Compat::crypto_scalarmult( $secret, $public_key );
	}

	/**
	 * Paire Ed25519 de sodium_compat ; la paire concaténée est écrasée.
	 *
	 * @return array{public: string, secret: string}
	 *
	 * @throws \SodiumException Échec de sodium_compat.
	 */
	private static function keypair_compat( string $seed ): array {
		$pair = \ParagonIE_Sodium_Compat::crypto_sign_seed_keypair( $seed );
		try {
			return [
				'public' => \ParagonIE_Sodium_Compat::crypto_sign_publickey( $pair ),
				'secret' => \ParagonIE_Sodium_Compat::crypto_sign_secretkey( $pair ),
			];
		} finally {
			self::overwrite( $pair );
		}
	}

	/**
	 * Signature Ed25519 de sodium_compat.
	 *
	 * @throws \SodiumException Échec de sodium_compat.
	 */
	private static function sign_compat( string $message, string $secret ): string {
		return \ParagonIE_Sodium_Compat::crypto_sign_detached( $message, $secret );
	}

	/**
	 * Vérificateur de sodium_compat (ou celui des tests), appelé après le contrôle n° 5. Toute
	 * exception rend faux (\SodiumException « S >= L - Invalid signature », « Signature is on too
	 * small of an order » ; \RangeException pour une clé hors de la courbe).
	 */
	private function verify_compat( string $signature, string $message, string $public_key ): bool {
		$verifier = $this->verifier;
		return self::with_fast_mult(
			static function () use ( $verifier, $signature, $message, $public_key ): bool {
				if ( null !== $verifier ) {
					return true === $verifier( $signature, $message, $public_key );
				}
				return true === \ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $public_key );
			}
		);
	}

	// -----------------------------------------------------------------------------------------
	// Contrôle n° 5, commun aux deux moteurs : classes Core de sodium_compat et $fastMult, ici
	// seulement.
	// -----------------------------------------------------------------------------------------

	/**
	 * Contrôle n° 5 : L·(−A) calculé par les méthodes de ParagonIE_Sodium_Core_Ed25519 que la
	 * vérification des signatures exerce déjà, puis neutre exigé (docblock de la classe). Le même
	 * code pour les deux moteurs ; toute exception est un refus ; faux en PHP 32 bits.
	 */
	private static function main_subgroup( string $public_key ): bool {
		if ( 8 !== PHP_INT_SIZE ) {
			return false;
		}
		return self::with_fast_mult(
			static function () use ( $public_key ): bool {
				$minus_a = \ParagonIE_Sodium_Core_Ed25519::ge_frombytes_negate_vartime( $public_key );
				$product = \ParagonIE_Sodium_Core_Ed25519::ge_double_scalarmult_vartime( self::bin( self::GROUP_ORDER ), $minus_a, str_repeat( "\0", 32 ) );

				// Neutre en coordonnées projectives : X = 0 et Y = Z. Tester X seul accepterait
				// (0, −1), le point d'ordre 2 : c'est le défaut de libsodium pour l'ordre 2·L.
				return ! \ParagonIE_Sodium_Core_Ed25519::fe_isnonzero( $product->X ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés de sodium_compat.
					&& ! \ParagonIE_Sodium_Core_Ed25519::fe_isnonzero( \ParagonIE_Sodium_Core_Ed25519::fe_sub( $product->Y, $product->Z ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés de sodium_compat.
			}
		);
	}

	/**
	 * Exécute $operation avec ParagonIE_Sodium_Compat::$fastMult mis à vrai (multiplications plus
	 * rapides, sans temps constant : seules des valeurs publiques passent ici), puis rétablit sa
	 * valeur dans tous les cas : la vérification de sodium_compat, elle, le met à vrai et ne le
	 * rétablit pas quand elle lève après coup. Toute exception rend faux, y compris l'absence de la
	 * classe ou de la propriété. Propriété présente dans toutes les copies relevées (de celle de
	 * WordPress 6.4 à 1.24.2).
	 *
	 * @param \Closure(): bool $operation Calcul à faire.
	 */
	private static function with_fast_mult( \Closure $operation ): bool {
		$fast_mult = null;
		try {
			$fast_mult                          = (bool) \ParagonIE_Sodium_Compat::$fastMult; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété de sodium_compat.
			\ParagonIE_Sodium_Compat::$fastMult = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété de sodium_compat.
			return $operation();
		} catch ( \Throwable ) {
			return false;
		} finally {
			// Rétablie seulement si elle a pu être lue (classe ou propriété absente : rien à rétablir).
			if ( null !== $fast_mult ) {
				\ParagonIE_Sodium_Compat::$fastMult = $fast_mult; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété de sodium_compat.
			}
		}
	}
}
