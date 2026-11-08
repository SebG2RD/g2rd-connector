<?php
/**
 * Autotest à valeurs connues des primitives du format chiffré des sauvegardes (G2BK v1).
 *
 * Chaque primitive est comparée à une valeur publiée, calculée ailleurs que par le code éprouvé :
 * - sha256 : SHA-256 de « abc » (FIPS 180-2, annexe B.1) ;
 * - hkdf_sha256 : RFC 5869, cas A.1, extraction puis expansion (Hkdf) ;
 * - aes_256_gcm : un cas calculé par le générateur indépendant des vecteurs (Aead), chiffré et
 *   étiquette à l'octet, ouverture, et la même étiquette altérée d'un bit refusée ;
 * - x25519 : RFC 7748 § 6.1, clé publique d'Alice et secret partagé (Sodium) ;
 * - ed25519 : RFC 8032 § 7.1, TEST 1 à 3, clé publique et signature à l'octet (Sodium), puis trois
 *   vérifications par Sodium::ed25519_verify() : la signature du TEST 1 acceptée, la même altérée
 *   d'un bit (bit de poids faible de son premier octet, dans R) refusée, et la signature du vecteur
 *   cle_publique_d_ordre_2l (index_tampering) refusée.
 *
 * Pourquoi des valeurs publiées et pas un aller-retour chiffrer puis déchiffrer (choix
 * d'interprétation n° 5 du plan de la phase 2) : un aller-retour passe deux fois par le même code ;
 * s'il calcule faux, il se trompe dans les deux sens et ne voit rien. Cas vérifié : BLAKE2b de
 * sodium_compat sous JIT (aucun BLAKE2b n'est employé par le format).
 *
 * Quand (phase 4) : au départ de chaque sauvegarde **et après chaque segment**, car sous JIT une
 * primitive peut ne calculer faux qu'une fois le code « chaud » ; et juste avant de signer un
 * index. Un échec arrête la sauvegarde (self_test_failed) et jette le segment en cours.
 *
 * Le refus de la clé d'ordre 2·L (relecture de la tâche 13) : sodium natif et la copie 1.24.0 de
 * sodium_compat (celle du parc) acceptent d'eux-mêmes sa signature ; seul le contrôle n° 5 de la
 * vérification stricte (Sodium::ed25519_verify(), sous-groupe principal par un calcul commun aux
 * deux moteurs) la refuse. Ce refus est donc la seule preuve que ce calcul n'accepte pas tout, en
 * natif comme en compat. Classes de ce calcul absentes, ou PHP 32 bits : le TEST 1 est refusé, et
 * l'autotest échoue aussi (refus sûr).
 *
 * Valeurs recopiées du fichier de vecteurs partagé (section self_test, et le cas
 * cle_publique_d_ordre_2l d'index_tampering) ; un test les y compare
 * (test_les_constantes_sont_celles_du_fichier_de_vecteurs). Ce sont des valeurs publiques : il
 * n'y a ni clé ni clair à effacer.
 *
 * Rien n'est mémorisé : chaque appel recalcule tout. Aucune exception ne sort de run() : un
 * contrôle qui lève (\Throwable) est en échec. Comparaisons par hash_equals().
 *
 * Coût mesuré le 24/10/2026 (poste de développement, PHP 8.4.3 sans JIT, libsodium 1.0.20,
 * sodium_compat 1.24.0 de vendor, 12 appels de suite) :
 * - en natif, 66 à 130 ms par appel (médiane 93 ms), surtout les trois vérifications Ed25519
 *   (calcul du contrôle n° 5, environ 32 ms chacune) ;
 * - en compat, 1,08 à 1,29 s (médiane 1,16 s) : l'échange X25519 environ 320 ms, sa clé publique
 *   environ 110 ms, chaque paire et chaque signature Ed25519 environ 110 ms, chaque vérification
 *   environ 70 ms.
 * Relancé après chaque segment de 64 Mio, cela ajoute environ 0,1 s par segment en natif et
 * environ 1 s en compat : la fréquence en compat est à peser au plan de la phase 4.
 *
 * Classe pure, sans effet au chargement, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Crypto;

final class SelfTest {

	/** Les contrôles, dans l'ordre où ils sont faits et rendus. */
	public const CHECKS = [ 'sha256', 'hkdf_sha256', 'aes_256_gcm', 'x25519', 'ed25519' ];

	/** FIPS 180-2, annexe B.1 : message en clair, empreinte en hexadécimal. */
	private const SHA256 = [
		'message' => 'abc',
		'digest'  => 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
	];

	/** RFC 5869, cas A.1 (self_test.hkdf_sha256 du fichier de vecteurs). */
	private const HKDF_SHA256 = [
		'ikm'    => '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b',
		'salt'   => '000102030405060708090a0b0c',
		'info'   => 'f0f1f2f3f4f5f6f7f8f9',
		'length' => 42,
		'prk'    => '077709362c2e32df0ddc3f0dc47bba6390b6c73bb50f9c3122ec844ad7c2b3e5',
		'okm'    => '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
	];

	/** Cas calculé par le générateur indépendant (self_test.aes_256_gcm du fichier de vecteurs). */
	private const AES_256_GCM = [
		'key'   => '62bb71bab472ea09ad7acb17ee81a9d7ad0d4e06df8426c40a67dc980f53f5ff',
		'nonce' => '9907d62923990edc96f70499',
		'aad'   => '68b8612f3affd7292acddeae9b880caaad90c8da',
		'pt'    => '52bd7f5c98a7c9a4be3a2f490e4c329ff9b06f054840e726b42ffe36a46337954edc36940125c73a',
		'ct'    => '2a2835ee5dfdca055b9c596417e96d362f4b70b00cb12cdef875f339e1b041d1c43cfe521485a7e6',
		'tag'   => 'c525ab3c5ca09f4b0c38001089f35bde',
	];

	/** RFC 7748 § 6.1 (self_test.x25519 du fichier de vecteurs). */
	private const X25519 = [
		'alice_private' => '77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a',
		'alice_public'  => '8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a',
		'bob_public'    => 'de9edb7d7b7dc1b4d35b61c2ece435373f8343c85b78674dadfc7e146f882b4f',
		'shared_secret' => '4a5d9d5ba4ce2de1728e3bf480350f25e07e21c947d19e3376f09b3c1e161742',
	];

	/** RFC 8032 § 7.1, TEST 1 à 3 (self_test.ed25519 du fichier de vecteurs). */
	private const ED25519 = [
		[
			'name'       => 'TEST 1',
			'seed'       => '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60',
			'public_key' => 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a',
			'message'    => '',
			'signature'  => 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b',
		],
		[
			'name'       => 'TEST 2',
			'seed'       => '4ccd089b28ff96da9db6c346ec114e0f5b8a319f35aba624da8cf6ed4fb8a6fb',
			'public_key' => '3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c',
			'message'    => '72',
			'signature'  => '92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00',
		],
		[
			'name'       => 'TEST 3',
			'seed'       => 'c5aa8df43f9f837bedb7442f31dcb7b166d38535076f094b85ce3a2e0b4458f7',
			'public_key' => 'fc51cd8e6218a1a38da47ed00230f0580816ed13ba3303ac5deb911548908025',
			'message'    => 'af82',
			'signature'  => '6291d657deec24024827e69c3abe01a30ce548a284743a445e3680d7db5ac3ac18ff9b538d16f290ae67f760984dc6594a7c15e9716ed28dc027beceea1ec40a',
		],
	];

	/**
	 * Vecteur cle_publique_d_ordre_2l d'index_tampering : index signé (texte, sans le domaine), clé
	 * publique d'ordre 2·L ([a]B + (0, −1)) et signature fabriquée par son détenteur (h pair), en
	 * hexadécimal. Refusée par les quatre implémentations du format (index_signature_invalid).
	 */
	private const ED25519_ORDER_2L = [
		'index'      => '{"format":"g2bk-index","version":1,"suite":1,"backup_id":"8985695e536befd8b249c869f62ee42b","site_ref":"a5249f70814246f98358a6f092664dcb","site_key_version":1,"signing_key_id":"2ceee2170bd536c2","created_at":"2026-10-24T01:00:00Z","type":"full","parent_backup_id":null,"payload_format":1,"segments":['
			. '{"index":0,"kind":"db","blocks":3,"bytes":236,"sha256":"bcf0ce9cf2b14893482c853886ff740e34589e3b42021d5bc708ddd315ebbd3a"},'
			. '{"index":1,"kind":"files","blocks":3,"bytes":228,"sha256":"48928f3b7b90f9f4b4847b75e703ce714a8fa49d70c91b111f6db73d170f933c"},'
			. '{"index":2,"kind":"files","blocks":1,"bytes":164,"sha256":"cb09d38d3e98b08992d0c9afe7a009148cfe2f3e8e217560dade438eafd6f76f"},'
			. '{"index":3,"kind":"manifest","blocks":3,"bytes":234,"sha256":"93f85eb42a601157dd46ff30751cf591f3d5e6d5ead26c7de3110cfe8f09eaf0"}'
			. '],"manifest_segment":3}',
		'public_key' => 'afb1ce62206b999b17d1724af49defda9b336983e71290efa79338ff241a0959',
		'signature'  => '0007afbc6ca8227efcc791197a5ac6fc76043979de6b599b1cbda78c03ccc39516c335e42c7d26c8bfcc413e545d6ac827ffce920630e4a24fbb9531a9e2dc0b',
	];

	/** Domaine de la signature d'un index (docs/backup-format.md § 7.5, dépôt de la plateforme). */
	private const INDEX_SIGNATURE_DOMAIN = "g2bk-v1/index\x00";

	/**
	 * Lance tous les contrôles, sans rien mémoriser d'un appel à l'autre. Ne lève jamais.
	 *
	 * @param Sodium $sodium Moteur X25519 et Ed25519 employé par la sauvegarde.
	 *
	 * @return array{ok: bool, failed: list<string>, backend: string} ok : tous les contrôles
	 *         passent, AES-256-GCM est disponible et un moteur sodium existe ; failed : les
	 *         contrôles en échec, dans l'ordre de CHECKS ; backend : le moteur de $sodium
	 *         (Sodium::BACKEND_*, BACKEND_NONE s'il n'a pas pu être lu).
	 */
	public static function run( Sodium $sodium ): array {
		try {
			$backend = $sodium->backend();
		} catch ( \Throwable ) {
			$backend = Sodium::BACKEND_NONE;
		}
		try {
			$gcm = Aead::available();
		} catch ( \Throwable ) {
			$gcm = false;
		}

		$failed = [];
		foreach ( self::CHECKS as $check ) {
			try {
				$passed = match ( $check ) {
					'sha256'      => self::sha256(),
					'hkdf_sha256' => self::hkdf_sha256(),
					'aes_256_gcm' => $gcm && self::aes_256_gcm(),
					'x25519'      => self::x25519( $sodium ),
					'ed25519'     => self::ed25519( $sodium ),
				};
			} catch ( \Throwable ) {
				$passed = false;
			}
			if ( true !== $passed ) {
				$failed[] = $check;
			}
		}

		return [
			'ok'      => [] === $failed && $gcm && Sodium::BACKEND_NONE !== $backend,
			'failed'  => $failed,
			'backend' => $backend,
		];
	}

	private static function sha256(): bool {
		return hash_equals( self::bin( self::SHA256['digest'] ), hash( 'sha256', self::SHA256['message'], true ) );
	}

	private static function hkdf_sha256(): bool {
		$prk = Hkdf::extract( self::bin( self::HKDF_SHA256['salt'] ), self::bin( self::HKDF_SHA256['ikm'] ) );
		$okm = Hkdf::expand( $prk, self::bin( self::HKDF_SHA256['info'] ), self::HKDF_SHA256['length'] );
		return hash_equals( self::bin( self::HKDF_SHA256['prk'] ), $prk )
			&& hash_equals( self::bin( self::HKDF_SHA256['okm'] ), $okm );
	}

	/**
	 * Appelé seulement si Aead::available() : sans aes-256-gcm, OpenSSL lèverait un avertissement
	 * PHP pour un chiffrement inconnu.
	 */
	private static function aes_256_gcm(): bool {
		$key    = self::bin( self::AES_256_GCM['key'] );
		$nonce  = self::bin( self::AES_256_GCM['nonce'] );
		$aad    = self::bin( self::AES_256_GCM['aad'] );
		$sealed = self::bin( self::AES_256_GCM['ct'] . self::AES_256_GCM['tag'] );

		if ( ! hash_equals( $sealed, Aead::seal( $key, $nonce, $aad, self::bin( self::AES_256_GCM['pt'] ) ) ) ) {
			return false;
		}
		$opened = Aead::open( $key, $nonce, $aad, $sealed );
		if ( null === $opened || ! hash_equals( self::bin( self::AES_256_GCM['pt'] ), $opened ) ) {
			return false;
		}
		// Une ouverture qui accepterait tout passerait les deux contrôles ci-dessus.
		return null === Aead::open( $key, $nonce, $aad, self::flip_low_bit( $sealed, strlen( $sealed ) - 1 ) );
	}

	private static function x25519( Sodium $sodium ): bool {
		$secret = self::bin( self::X25519['alice_private'] );
		return hash_equals( self::bin( self::X25519['alice_public'] ), $sodium->x25519_base( $secret ) )
			&& hash_equals( self::bin( self::X25519['shared_secret'] ), $sodium->x25519( $secret, self::bin( self::X25519['bob_public'] ) ) );
	}

	private static function ed25519( Sodium $sodium ): bool {
		foreach ( self::ED25519 as $test ) {
			$pair = $sodium->ed25519_keypair_from_seed( self::bin( $test['seed'] ) );
			if ( ! hash_equals( self::bin( $test['public_key'] ), $pair['public'] ) ) {
				return false;
			}
			if ( ! hash_equals( self::bin( $test['signature'] ), $sodium->ed25519_sign( self::bin( $test['message'] ), $pair['secret'] ) ) ) {
				return false;
			}
		}

		$test_1    = self::ED25519[0];
		$signature = self::bin( $test_1['signature'] );
		$message   = self::bin( $test_1['message'] );
		$public    = self::bin( $test_1['public_key'] );
		if ( true !== $sodium->ed25519_verify( $signature, $message, $public ) ) {
			return false;
		}
		// Un vérificateur qui accepte tout passerait le contrôle ci-dessus.
		if ( false !== $sodium->ed25519_verify( self::flip_low_bit( $signature, 0 ), $message, $public ) ) {
			return false;
		}
		// Un contrôle n° 5 qui accepte tout passerait les deux contrôles ci-dessus (docblock).
		return false === $sodium->ed25519_verify(
			self::bin( self::ED25519_ORDER_2L['signature'] ),
			self::INDEX_SIGNATURE_DOMAIN . self::ED25519_ORDER_2L['index'],
			self::bin( self::ED25519_ORDER_2L['public_key'] )
		);
	}

	/**
	 * Les mêmes octets, bit de poids faible de l'octet n° $offset inversé.
	 */
	private static function flip_low_bit( string $bytes, int $offset ): string {
		$bytes[ $offset ] = chr( ord( $bytes[ $offset ] ) ^ 0x01 );
		return $bytes;
	}

	/**
	 * Octets d'une constante hexadécimale de la classe.
	 */
	private static function bin( string $hex ): string {
		return (string) hex2bin( $hex );
	}
}
