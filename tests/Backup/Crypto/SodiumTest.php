<?php
/**
 * X25519 et Ed25519 du format G2BK v1 : sodium natif ou sodium_compat de WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Crypto;

use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Les valeurs attendues viennent du fichier de vecteurs (générateur Python indépendant, contrôlé
 * contre les RFC 7748 et 8032), jamais du code testé.
 *
 * Moteur « compat » : la copie de sodium_compat de vendor, épinglée sur celle que WordPress livre
 * au parc (1.24.0, contrôlée par SodiumCompatCopieTest), forcée en PHP pur par
 * $disableFallbackForUnitTests (sans quoi elle déléguerait à l'extension chargée sur le poste).
 * Les cas « compat » portent le groupe sodium-compat : ils se rejouent contre une autre copie
 * (WordPress 6.4, 1.24.2) par la commande du docblock de SodiumCompatCopieTest.
 *
 * Moteur « native » : depuis la relecture de la tâche 13, son contrôle n° 5 (sous-groupe
 * principal) est le même calcul qu'en compat, par les classes Core de sodium_compat (celles de
 * vendor, ou celles de la copie rejouée) ; les cas natifs qui l'exercent portent donc aussi le
 * groupe.
 */
final class SodiumTest extends TestCase {

	/** Domaine de la signature d'un index (docs/backup-format.md § 7.5). */
	private const INDEX_DOMAIN = "g2bk-v1/index\x00";

	/** Une clé qui ne se décode en aucun point de la courbe (y = 2). */
	private const HORS_COURBE = '0200000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Clé d'ordre 2·L : clé du TEST 1 de la RFC 8032 plus le point d'ordre 2 (0, −1), soit (−x, −y).
	 * L·A y vaut (0, −1) : X nul mais Y = −Z, ce que seul le test de Y − Z distingue du neutre.
	 * Calculée hors bibliothèque le 24/10/2026 (arithmétique de la RFC 8032 § 5.1, Python).
	 */
	private const ORDRE_2L = '16a567fe7d4ef5482ab4012c369bf8c5f11e8d0c2559dcda50fde59708f8aee5';

	/**
	 * Signature de MESSAGE_2L pour la clé ORDRE_2L, fabriquée par le détenteur de la clé du TEST 1
	 * (nonce choisi pour que h réduit modulo L soit pair, 4 essais) : la vérification sans cofacteur
	 * l'accepte dès que la clé passe le contrôle n° 5. Recopiée de
	 * docs/superpowers/mesures/2026-10-24-sauvegardes/ed25519-limites/forge_2l.json (dépôt de la
	 * plateforme ; forger_2l.py, contre-vérifiée par contre_2l.py).
	 */
	private const SIGNATURE_2L = 'cfb2016c60ecf9e53bbbe1d085ffb585581771a1b46df37a5cf205d5a33e95244161d6eec424ed363c959951784474b5003fca5e36bab9853baad016bde4dc00';

	/** « g2bk-v1/index » ‖ 00 ‖ {"format":"g2bk-index"} (même fichier). */
	private const MESSAGE_2L = '6732626b2d76312f696e646578007b22666f726d6174223a226732626b2d696e646578227d';

	/** L = 2²⁵² + 27742317777372353535851937790883648493, petit-boutiste. */
	private const L = 'edd3f55c1a631258d69cf7a2def9de1400000000000000000000000000000010';

	/**
	 * Coordonnées u d'ordre faible de X25519 (et leurs variantes p − 1, p, p + 1) : avec un
	 * scalaire réduit (multiple de 8), l'échange rend 32 zéros.
	 */
	private const X25519_ORDRE_FAIBLE = [
		'0000000000000000000000000000000000000000000000000000000000000000',
		'0100000000000000000000000000000000000000000000000000000000000000',
		'e0eb7a7c3b41b8ae1656e3faf19fc46ada098deb9c32b1fd866205165f49b800',
		'5f9c95bca3508c24b1d0b1559c83ef5b04445cc4581c8e86d8224eddd09f1157',
		'ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
		'edffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
		'eeffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
	];

	private bool $disable_fallback = false;

	private bool $fast_mult = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
		$this->fast_mult        = \ParagonIE_Sodium_Compat::$fastMult;
	}

	protected function tearDown(): void {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		\ParagonIE_Sodium_Compat::$fastMult                    = $this->fast_mult;
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------------------
	// Choix du moteur.
	// ---------------------------------------------------------------------------------------

	public function test_le_natif_est_choisi_quand_l_extension_est_chargee(): void {
		$demandes = [];
		$charges  = 0;
		$sodium   = new Sodium(
			static function ( string $nom ) use ( &$demandes ): bool {
				$demandes[] = $nom;
				return true;
			},
			static function () use ( &$charges ): bool {
				++$charges;
				return true;
			}
		);

		// Rien au chargement ni à la construction.
		self::assertSame( [], $demandes );

		self::assertSame( Sodium::BACKEND_NATIVE, $sodium->backend() );
		self::assertSame( Sodium::BACKEND_NATIVE, $sodium->backend() );
		self::assertSame( [ 'sodium' ], $demandes, 'Une seule question, mémorisée, et sur l\'extension « sodium ».' );
		self::assertSame( 0, $charges, 'sodium_compat n\'est pas chargé quand l\'extension est là.' );
	}

	public function test_compat_est_choisi_sans_l_extension_si_wordpress_la_livre(): void {
		$charges = 0;
		$sodium  = new Sodium(
			static fn ( string $nom ): bool => false,
			static function () use ( &$charges ): bool {
				++$charges;
				return true;
			}
		);

		self::assertSame( Sodium::BACKEND_COMPAT, $sodium->backend() );
		self::assertSame( Sodium::BACKEND_COMPAT, $sodium->backend() );
		self::assertSame( 1, $charges, 'Chargeur appelé une fois, résultat mémorisé.' );
	}

	public function test_aucun_moteur_sans_extension_ni_compat(): void {
		$sodium = new Sodium( static fn (): bool => false, static fn (): bool => false );

		self::assertSame( Sodium::BACKEND_NONE, $sodium->backend() );

		$cle = str_repeat( "\x01", 32 );
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $sodium->x25519_base( $cle ) );
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $sodium->x25519( $cle, $cle ) );
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $sodium->ed25519_keypair_from_seed( $cle ) );
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $sodium->ed25519_sign( 'message', $cle . $cle ) );
		// La vérification aussi : rendre faux ferait prendre une panne pour une signature invalide.
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => $sodium->ed25519_verify( str_repeat( "\x01", 64 ), 'message', $cle ) );
	}

	public function test_wipe_sans_moteur_ecrase_sans_lever(): void {
		// Choix d'écriture (compte rendu de la tâche 13) : l'effacement n'est pas une opération
		// cryptographique. Il sert dans les `finally` : il ne lève jamais et écrase au mieux,
		// même sans moteur, plutôt que de laisser la clé en place.
		$sodium = new Sodium( static fn (): bool => false, static fn (): bool => false );
		$secret = str_repeat( "\xa5", 32 );

		$sodium->wipe( $secret );

		self::assertSame( str_repeat( "\0", 32 ), $secret );
	}

	public function test_le_choix_ne_regarde_jamais_function_exists(): void {
		// Sur le poste, les fonctions sodium_* existent (extension, ou sodium_compat de vendor) :
		// un choix par function_exists rendrait « native » ici.
		self::assertTrue( function_exists( 'sodium_crypto_sign_verify_detached' ) );
		self::assertTrue( function_exists( 'sodium_crypto_scalarmult' ) );

		$sans_rien = new Sodium( static fn (): bool => false, static fn (): bool => false );
		self::assertSame( Sodium::BACKEND_NONE, $sans_rien->backend() );

		$avec_compat = new Sodium( static fn (): bool => false, static fn (): bool => true );
		self::assertSame( Sodium::BACKEND_COMPAT, $avec_compat->backend() );
	}

	public function test_le_chargeur_par_defaut_ne_charge_rien_hors_wordpress(): void {
		self::assertFalse( defined( 'WPINC' ), 'Les tests tournent sans WordPress : WPINC n\'est pas définie.' );
		// sodium_compat est déjà là, chargeable par vendor/autoload.php (le chargeur par défaut
		// demande aussi la classe Core du contrôle n° 5 : elle est chargée ici, avant la photo).
		self::assertTrue( class_exists( \ParagonIE_Sodium_Compat::class ) );
		self::assertTrue( class_exists( \ParagonIE_Sodium_Core_Ed25519::class ) );

		// Extension réputée absente, chargeur par défaut : il ne doit inclure aucun fichier.
		// Seconde photo prise avant toute assertion : PHPUnit charge ses propres classes en
		// comparant.
		$sodium = new Sodium( static fn (): bool => false );
		$avant  = get_included_files();
		$moteur = $sodium->backend();
		$apres  = get_included_files();

		self::assertSame( Sodium::BACKEND_COMPAT, $moteur );
		self::assertSame( $avant, $apres, 'Le chargeur par défaut a inclus un fichier hors de WordPress.' );

		// Extension chargée, chargeur par défaut : appelé à la première vérification (contrôle
		// n° 5), il n'inclut rien non plus et trouve les classes Core déjà chargeables (vendor).
		if ( extension_loaded( 'sodium' ) ) {
			$ed        = VectorsFixture::section( 'self_test' )['ed25519'][0];
			$signature = VectorsFixture::bin( $ed['signature'] );
			$message   = VectorsFixture::bin( $ed['message'] );
			$cle       = VectorsFixture::bin( $ed['public_key'] );
			// Échauffement : les classes du calcul sont chargées par vendor/autoload.php.
			self::assertTrue( self::natif()->ed25519_verify( $signature, $message, $cle ) );

			$natif  = new Sodium( static fn (): bool => true );
			$avant  = get_included_files();
			$valide = $natif->ed25519_verify( $signature, $message, $cle );
			$apres  = get_included_files();

			self::assertTrue( $valide, 'Natif, chargeur par défaut : TEST 1 refusé hors de WordPress.' );
			self::assertSame( $avant, $apres, 'Le chargeur par défaut a inclus un fichier hors de WordPress (natif).' );
		}
	}

	// ---------------------------------------------------------------------------------------
	// RFC 7748 et 8032, deux moteurs.
	// ---------------------------------------------------------------------------------------

	public function test_x25519_reproduit_la_rfc_7748_en_natif(): void {
		self::assert_rfc_7748( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_x25519_reproduit_la_rfc_7748_en_compat(): void {
		self::assert_rfc_7748( self::compat() );
	}

	public function test_ed25519_reproduit_la_rfc_8032_en_natif(): void {
		self::assert_rfc_8032( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_ed25519_reproduit_la_rfc_8032_en_compat(): void {
		self::assert_rfc_8032( self::compat() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_deux_moteurs_donnent_les_memes_octets(): void {
		$natif  = self::natif();
		$compat = self::compat();

		foreach ( VectorsFixture::section( 'hpke' ) as $cas ) {
			$sk_r = VectorsFixture::bin( $cas['sk_r'] );
			$sk_e = VectorsFixture::bin( $cas['sk_e'] );
			$pk_r = VectorsFixture::bin( $cas['pk_r'] );
			$enc  = VectorsFixture::bin( $cas['enc'] );

			foreach ( [ 'natif' => $natif, 'compat' => $compat ] as $nom => $sodium ) {
				self::assertSame( $cas['pk_r'], bin2hex( $sodium->x25519_base( $sk_r ) ), $cas['name'] . ' pk_r, ' . $nom );
				self::assertSame( $cas['enc'], bin2hex( $sodium->x25519_base( $sk_e ) ), $cas['name'] . ' enc, ' . $nom );
			}
			$dh = $natif->x25519( $sk_e, $pk_r );
			self::assertSame( bin2hex( $dh ), bin2hex( $compat->x25519( $sk_e, $pk_r ) ), $cas['name'] . ' dh côté émetteur' );
			self::assertSame( bin2hex( $dh ), bin2hex( $natif->x25519( $sk_r, $enc ) ), $cas['name'] . ' dh côté destinataire, natif' );
			self::assertSame( bin2hex( $dh ), bin2hex( $compat->x25519( $sk_r, $enc ) ), $cas['name'] . ' dh côté destinataire, compat' );
		}

		foreach ( VectorsFixture::section( 'index' ) as $cas ) {
			$paires = [];
			foreach ( [ 'natif' => $natif, 'compat' => $compat ] as $nom => $sodium ) {
				$paire           = $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( $cas['seed'] ) );
				$paires[ $nom ] = $paire;
				self::assertSame( $cas['public_key'], bin2hex( $paire['public'] ), $cas['name'] . ' clé publique, ' . $nom );
				$signature = $sodium->ed25519_sign( self::INDEX_DOMAIN . $cas['index'], $paire['secret'] );
				self::assertSame( $cas['signature'], bin2hex( $signature ), $cas['name'] . ' signature, ' . $nom );
			}
			self::assertSame( bin2hex( $paires['natif']['secret'] ), bin2hex( $paires['compat']['secret'] ), $cas['name'] . ' clé secrète' );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Clés refusées.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_cle_publique_de_mauvaise_longueur_refusee(): void {
		$x       = VectorsFixture::section( 'self_test' )['x25519'];
		$secret  = VectorsFixture::bin( $x['alice_private'] );
		$public  = VectorsFixture::bin( $x['bob_public'] );
		$ed      = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$cle_ed  = VectorsFixture::bin( $ed['public_key'] );
		$sig_ed  = VectorsFixture::bin( $ed['signature'] );
		$message = VectorsFixture::bin( $ed['message'] );

		foreach ( self::moteurs() as $nom => $sodium ) {
			foreach ( [ '', substr( $public, 0, 31 ), $public . "\0" ] as $mauvaise ) {
				self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $sodium->x25519( $secret, $mauvaise ) );
			}
			self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $sodium->x25519( substr( $secret, 0, 31 ), $public ) );
			self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $sodium->x25519_base( substr( $secret, 0, 31 ) ) );
			self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $sodium->x25519_base( $secret . "\0" ) );

			self::assertTrue( $sodium->ed25519_verify( $sig_ed, $message, $cle_ed ), 'témoin, ' . $nom );
			self::assertFalse( $sodium->ed25519_verify( $sig_ed, $message, substr( $cle_ed, 0, 31 ) ), $nom );
			self::assertFalse( $sodium->ed25519_verify( $sig_ed, $message, $cle_ed . "\0" ), $nom );
			self::assertFalse( $sodium->ed25519_verify( substr( $sig_ed, 0, 63 ), $message, $cle_ed ), $nom );
			self::assertFalse( $sodium->ed25519_verify( $sig_ed . "\0", $message, $cle_ed ), $nom );
			self::assertFalse( $sodium->ed25519_verify( '', $message, '' ), $nom );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_point_d_ordre_faible_refuse(): void {
		$secret = VectorsFixture::bin( VectorsFixture::section( 'self_test' )['x25519']['alice_private'] );
		$ed     = VectorsFixture::section( 'self_test' )['ed25519'][0];

		foreach ( self::moteurs() as $nom => $sodium ) {
			foreach ( self::X25519_ORDRE_FAIBLE as $u ) {
				self::assert_format_error( FormatError::BAD_PUBLIC_KEY, static fn () => $sodium->x25519( $secret, (string) hex2bin( $u ) ) );
			}
			// La clé publique Ed25519 de 32 zéros, elle aussi.
			self::assertFalse( $sodium->ed25519_verify( VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), str_repeat( "\0", 32 ) ), $nom );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_verification_ne_leve_jamais_sur_des_octets_quelconques(): void {
		$cas = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$alea      = hash( 'sha512', 'g2rd-sodium-test/a/' . $i, true ) . hash( 'sha512', 'g2rd-sodium-test/b/' . $i, true );
			$signature = substr( $alea, 0, 64 );
			if ( 0 === $i % 2 ) {
				// S < 2²⁵² < L : la moitié des cas passe le contrôle n° 1 et va plus loin.
				$signature[63] = chr( ord( $signature[63] ) & 0x0f );
			}
			$cas[] = [ $signature, substr( $alea, 64, $i * 3 ), substr( $alea, 96, 32 ) ];
		}
		$ed        = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$signature = VectorsFixture::bin( $ed['signature'] );
		$message   = VectorsFixture::bin( $ed['message'] );
		foreach ( [ self::HORS_COURBE, str_repeat( 'ff', 32 ), str_repeat( 'ee', 32 ), '0000000000000000000000000000000000000000000000000000000000000080', '26e8958fc2b227b045c3f489f2ef98f0d5dfac05d3c63339b13802886d53fc85' ] as $cle ) {
			$cas[] = [ $signature, $message, (string) hex2bin( $cle ) ];
		}
		$cas[] = [ str_repeat( "\xff", 64 ), $message, str_repeat( "\xff", 32 ) ];
		$cas[] = [ str_repeat( "\0", 64 ), '', str_repeat( "\0", 32 ) ];
		$cas[] = [ '', '', '' ];
		$cas[] = [ str_repeat( "\0", 63 ), $message, str_repeat( "\0", 31 ) ];

		foreach ( self::moteurs() as $nom => $sodium ) {
			foreach ( $cas as $n => [ $s, $m, $k ] ) {
				self::assertFalse( $sodium->ed25519_verify( $s, $m, $k ), 'cas ' . $n . ', ' . $nom );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_signatures_degenerees_refusees_par_les_deux_moteurs(): void {
		foreach ( [ 'signature_s_non_canonique', 'cle_publique_d_ordre_faible' ] as $nom_cas ) {
			$cas       = VectorsFixture::named( 'index_tampering', $nom_cas );
			$signature = VectorsFixture::bin( $cas['signature'] );
			$message   = self::INDEX_DOMAIN . $cas['index'];
			$cle       = VectorsFixture::bin( $cas['public_key'] );

			foreach ( self::moteurs() as $nom => $sodium ) {
				self::assertFalse( $sodium->ed25519_verify( $signature, $message, $cle ), $nom_cas . ', ' . $nom );
			}

			// Ce que la classe ne laisse jamais sortir : appelée seule, la copie de sodium_compat
			// lève une SodiumException (« S >= L - Invalid signature », « Signature is on too small
			// of an order » en 1.24.0 ; « S < L - Invalid signature » dans la copie de 6.4).
			\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
			try {
				\ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $cle );
				self::fail( 'sodium_compat seul a accepté ' . $nom_cas . ' sans lever.' );
			} catch ( \SodiumException $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_les_controles_stricts_passent_avant_le_moteur(): void {
		$valide = VectorsFixture::named( 'index', 'index_complet' );
		$sig    = VectorsFixture::bin( $valide['signature'] );
		$cle    = VectorsFixture::bin( $valide['public_key'] );
		$msg    = self::INDEX_DOMAIN . $valide['index'];
		$s      = substr( $sig, 32 );

		$cas = [];
		foreach ( [ 'signature_s_non_canonique', 'cle_publique_d_ordre_faible', 'cle_publique_non_canonique', 'cle_publique_d_ordre_mixte', 'cle_publique_d_ordre_2l' ] as $nom_cas ) {
			$vecteur         = VectorsFixture::named( 'index_tampering', $nom_cas );
			$cas[ $nom_cas ] = [ VectorsFixture::bin( $vecteur['signature'] ), self::INDEX_DOMAIN . $vecteur['index'], VectorsFixture::bin( $vecteur['public_key'] ) ];
		}
		// Cas construits ici, pour R et les bornes (mêmes règles, docs/backup-format.md § 8) :
		// S = L exactement (n° 1) ;
		$cas['s_egal_a_l'] = [ substr( $sig, 0, 32 ) . self::bin( self::L ), $msg, $cle ];
		// R d'ordre 8 avec le bit de signe mis (n° 2, comparaison masquée par 0x7f) ;
		$cas['r_d_ordre_faible_bit_de_signe'] = [ self::bin( 'c7176a703d4dd84fba3c0b760d10670f2a2053fa2c39ccc64ec7fd7792ac03fa' ) . $s, $msg, $cle ];
		// clé nulle avec le bit de signe mis (n° 2 et 3) ;
		$cas['cle_nulle_bit_de_signe'] = [ $sig, $msg, self::bin( '0000000000000000000000000000000000000000000000000000000000000080' ) ];
		// R non canonique, y = p + 3, absent de la liste d'ordre faible (n° 4). Pour la clé, le n° 4
		// ne s'isole pas : calculé le 24/10 (arithmétique de la RFC 8032), toute clé non canonique
		// est soit d'ordre faible (eeff…7f, le neutre), soit hors de la courbe, soit hors du
		// sous-groupe principal ;
		// cle_publique_non_canonique est donc refusée aussi par le n° 5, et seul R prouve le n° 4 ;
		$cas['r_non_canonique'] = [ self::bin( 'f0ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f' ) . $s, $msg, $cle ];
		// clé hors de la courbe (n° 5) ;
		$cas['cle_hors_courbe'] = [ $sig, $msg, self::bin( self::HORS_COURBE ) ];
		// clé d'ordre 2·L (n° 5 : L·A = (0, −1), X nul mais pas le neutre), en plus du vecteur
		// cle_publique_d_ordre_2l lu plus haut.
		$cas['cle_d_ordre_2l'] = [ $sig, $msg, self::bin( self::ORDRE_2L ) ];

		$appels   = 0;
		$tout_oui = static function ( string $signature, string $message, string $public ) use ( &$appels ): bool {
			++$appels;
			return true;
		};

		foreach ( self::moteurs( $tout_oui ) as $nom => $sodium ) {
			foreach ( $cas as $nom_cas => [ $signature, $message, $public ] ) {
				$appels = 0;
				self::assertFalse( $sodium->ed25519_verify( $signature, $message, $public ), $nom_cas . ', ' . $nom );
				self::assertSame( 0, $appels, 'Vérificateur appelé malgré le refus des contrôles stricts : ' . $nom_cas . ', ' . $nom );
			}

			// Témoin : une signature valide passe les contrôles et arrive au vérificateur doublé.
			$appels = 0;
			self::assertTrue( $sodium->ed25519_verify( $sig, $msg, $cle ), 'témoin, ' . $nom );
			self::assertSame( 1, $appels, 'témoin, ' . $nom );
			// Le vérificateur doublé remplace bien celui du moteur : il accepte un message faux.
			self::assertTrue( $sodium->ed25519_verify( $sig, $msg . 'x', $cle ), 'doublure, ' . $nom );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_cle_d_ordre_mixte_refusee_par_les_deux_moteurs(): void {
		$mixte     = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_mixte' );
		$signature = VectorsFixture::bin( $mixte['signature'] );
		$message   = self::INDEX_DOMAIN . $mixte['index'];
		$cle       = VectorsFixture::bin( $mixte['public_key'] );

		// Le danger : la signature fabriquée par le détenteur de la clé passe la vérification
		// seule de sodium natif, et celle de la copie du parc (1.24.0, avis PKSA-32g2-byr9-drtw).
		if ( extension_loaded( 'sodium' ) ) {
			self::assertTrue( sodium_crypto_sign_verify_detached( $signature, $message, $cle ), 'sodium natif seul accepte la clé d\'ordre mixte.' );
		}
		$rejeu = getenv( 'G2RD_SODIUM_COMPAT_COPIE' );
		if ( false === $rejeu || '' === $rejeu ) {
			\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
			self::assertTrue( \ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $cle ), 'sodium_compat 1.24.0 seul accepte la clé d\'ordre mixte.' );
		}

		foreach ( self::moteurs() as $nom => $sodium ) {
			self::assertFalse( $sodium->ed25519_verify( $signature, $message, $cle ), 'clé d\'ordre mixte, ' . $nom );

			foreach ( self::signatures_valides() as $nom_cas => [ $s, $m, $k ] ) {
				self::assertTrue( $sodium->ed25519_verify( $s, $m, $k ), $nom_cas . ', ' . $nom );
			}

			$ed = VectorsFixture::section( 'self_test' )['ed25519'][0];
			self::assertFalse( $sodium->ed25519_verify( VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), self::bin( self::HORS_COURBE ) ), 'clé hors de la courbe, ' . $nom );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_cle_d_ordre_2l_refusee_par_les_deux_moteurs(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : ce cas montre ce que sodium natif accepte seul, il ne se joue qu\'avec elle.' );
		}
		// Deux clés d'ordre 2·L : celle construite ici (TEST 1 + (0, −1)) et le vecteur partagé
		// cle_publique_d_ordre_2l ([a]B + (0, −1), h pair), que les quatre implémentations refusent.
		$vecteur = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_2l' );
		$cas     = [
			'cle_d_ordre_2l'          => [ self::bin( self::SIGNATURE_2L ), self::bin( self::MESSAGE_2L ), self::bin( self::ORDRE_2L ) ],
			'cle_publique_d_ordre_2l' => [ VectorsFixture::bin( $vecteur['signature'] ), self::INDEX_DOMAIN . $vecteur['index'], VectorsFixture::bin( $vecteur['public_key'] ) ],
		];

		$rejeu = getenv( 'G2RD_SODIUM_COMPAT_COPIE' );
		foreach ( $cas as $nom_cas => [ $signature, $message, $cle ] ) {
			// Le cas est réel : libsodium ne teste que X après la multiplication par L
			// (ge25519_is_on_main_subgroup, ed25519_ref10.c), et L·A vaut ici (0, −1). La conversion
			// accepte donc la clé, et la vérification la signature fabriquée.
			try {
				$conversion = 32 === strlen( sodium_crypto_sign_ed25519_pk_to_curve25519( $cle ) );
			} catch ( \SodiumException ) {
				$conversion = false;
			}
			if ( ! $conversion || ! sodium_crypto_sign_verify_detached( $signature, $message, $cle ) ) {
				self::markTestSkipped( 'Le sodium natif de ce PHP (libsodium ' . SODIUM_LIBRARY_VERSION . ') refuse déjà seul la clé d\'ordre 2·L (' . $nom_cas . ') : le cas ne montre plus l\'écart. Action : relever ce que fait cette version et le reporter au § 8.1 de docs/backup-format.md.' );
			}
			// La copie du parc (vendor, 1.24.0) seule l'accepte aussi (avis PKSA-32g2-byr9-drtw) ;
			// une copie rejouée peut la refuser (1.24.2) : rien n'est attendu d'elle seule.
			if ( false === $rejeu || '' === $rejeu ) {
				\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
				self::assertTrue( \ParagonIE_Sodium_Compat::crypto_sign_verify_detached( $signature, $message, $cle ), 'sodium_compat 1.24.0 seul accepte la clé d\'ordre 2·L (' . $nom_cas . ').' );
			}
		}

		$ed = VectorsFixture::section( 'self_test' )['ed25519'][0];
		foreach ( self::moteurs() as $nom => $sodium ) {
			foreach ( $cas as $nom_cas => [ $signature, $message, $cle ] ) {
				self::assertFalse( $sodium->ed25519_verify( $signature, $message, $cle ), $nom_cas . ', ' . $nom );
			}
			// Témoin : la clé du TEST 1, dont la clé construite dérive, reste acceptée.
			self::assertTrue( $sodium->ed25519_verify( VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), VectorsFixture::bin( $ed['public_key'] ) ), 'TEST 1, ' . $nom );
		}
	}

	public function test_le_natif_refuse_sans_les_classes_de_sodium_compat(): void {
		$ed        = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$signature = VectorsFixture::bin( $ed['signature'] );
		$message   = VectorsFixture::bin( $ed['message'] );
		$cle       = VectorsFixture::bin( $ed['public_key'] );

		$appels   = 0;
		$tout_oui = static function () use ( &$appels ): bool {
			++$appels;
			return true;
		};
		$charges  = 0;
		$absentes = static function () use ( &$charges ): bool {
			++$charges;
			return false;
		};

		// Classes Core absentes (WordPress sans wp-includes/sodium_compat lisible) : refus sûr,
		// le vérificateur n'est jamais appelé, et l'autotest le signale (TEST 1 refusé).
		$sans_classes = self::natif( $tout_oui, $absentes );
		self::assertSame( Sodium::BACKEND_NATIVE, $sans_classes->backend(), 'Le moteur reste natif : les classes ne servent qu\'au contrôle n° 5.' );
		self::assertFalse( $sans_classes->ed25519_verify( $signature, $message, $cle ), 'TEST 1 accepté sans les classes du contrôle n° 5.' );
		self::assertFalse( $sans_classes->ed25519_verify( $signature, $message, $cle ) );
		self::assertSame( 0, $appels, 'Vérificateur appelé sans contrôle n° 5.' );
		self::assertSame( 1, $charges, 'Résultat du chargeur mémorisé, même faux.' );
		self::assertFalse( self::natif( null, static fn (): bool => false )->ed25519_verify( $signature, $message, $cle ), 'Vrai vérificateur : TEST 1 accepté sans les classes.' );

		// Un chargeur qui lève vaut des classes absentes : refus sûr, sans exception.
		$en_panne = static function (): bool {
			throw new \RuntimeException( 'chargeur en panne' );
		};
		self::assertFalse( self::natif( $tout_oui, $en_panne )->ed25519_verify( $signature, $message, $cle ) );
		self::assertSame( 0, $appels );
		// Sans l'extension, le même chargeur en panne ne donne aucun moteur.
		self::assertSame( Sodium::BACKEND_NONE, ( new Sodium( static fn (): bool => false, $en_panne ) )->backend() );

		// Les autres opérations du natif n'en dépendent pas.
		$paire = $sans_classes->ed25519_keypair_from_seed( VectorsFixture::bin( $ed['seed'] ) );
		self::assertSame( $ed['signature'], bin2hex( $sans_classes->ed25519_sign( $message, $paire['secret'] ) ) );
		self::assertSame( 1, $charges );
	}

	public function test_le_chargeur_n_est_appele_qu_a_la_premiere_verification_native(): void {
		$charges = 0;
		$sodium  = self::natif(
			null,
			static function () use ( &$charges ): bool {
				++$charges;
				return true;
			}
		);
		$tests   = VectorsFixture::section( 'self_test' )['ed25519'];
		$x       = VectorsFixture::section( 'self_test' )['x25519'];

		self::assertSame( Sodium::BACKEND_NATIVE, $sodium->backend() );
		self::assertSame( 0, $charges, 'backend() natif a appelé le chargeur.' );

		// Les opérations sans contrôle n° 5 ne l'appellent pas.
		$sodium->x25519( VectorsFixture::bin( $x['alice_private'] ), VectorsFixture::bin( $x['bob_public'] ) );
		$paire = $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( $tests[0]['seed'] ) );
		$sodium->ed25519_sign( 'message', $paire['secret'] );
		$sodium->wipe( $paire['secret'] );
		// Ni une signature refusée par les contrôles d'octets (S ≥ L) : le n° 5 n'est pas atteint.
		self::assertFalse( $sodium->ed25519_verify( str_repeat( "\xff", 64 ), 'message', VectorsFixture::bin( $tests[0]['public_key'] ) ) );
		self::assertSame( 0, $charges );

		foreach ( $tests as $test ) {
			self::assertTrue( $sodium->ed25519_verify( VectorsFixture::bin( $test['signature'] ), VectorsFixture::bin( $test['message'] ), VectorsFixture::bin( $test['public_key'] ) ), $test['name'] );
			self::assertSame( 1, $charges, 'Chargeur appelé une seule fois, à la première vérification : ' . $test['name'] );
		}
		self::assertFalse( $sodium->ed25519_verify( self::bin( self::SIGNATURE_2L ), self::bin( self::MESSAGE_2L ), self::bin( self::ORDRE_2L ) ) );
		self::assertSame( 1, $charges );
	}

	#[Group( 'sodium-compat' )]
	public function test_fastmult_est_retabli_aussi_en_natif(): void {
		$mixte = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_mixte' );
		$ed    = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$cas   = [];
		foreach ( self::signatures_valides() as $nom_cas => $valeurs ) {
			$cas[ $nom_cas ] = [ $valeurs, true ];
		}
		$cas['cle_publique_d_ordre_mixte'] = [ [ VectorsFixture::bin( $mixte['signature'] ), self::INDEX_DOMAIN . $mixte['index'], VectorsFixture::bin( $mixte['public_key'] ) ], false ];
		$cas['cle_hors_courbe']            = [ [ VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), self::bin( self::HORS_COURBE ) ], false ];
		$cas['cle_d_ordre_2l']             = [ [ self::bin( self::SIGNATURE_2L ), self::bin( self::MESSAGE_2L ), self::bin( self::ORDRE_2L ) ], false ];

		$sodium  = self::natif();
		$pendant = [];
		$espion  = self::natif(
			static function () use ( &$pendant ): bool {
				$pendant[] = \ParagonIE_Sodium_Compat::$fastMult;
				return true;
			}
		);
		foreach ( [ false, true ] as $depart ) {
			foreach ( $cas as $nom_cas => [ [ $s, $m, $k ], $attendu ] ) {
				\ParagonIE_Sodium_Compat::$fastMult = $depart;
				self::assertSame( $attendu, $sodium->ed25519_verify( $s, $m, $k ), $nom_cas );
				self::assertSame( $depart, \ParagonIE_Sodium_Compat::$fastMult, '$fastMult non rétabli en natif après ' . $nom_cas . ' (départ ' . var_export( $depart, true ) . ').' );
			}
			// Le vérificateur natif vient après le calcul du n° 5 : $fastMult y est déjà rétabli.
			$pendant                            = [];
			\ParagonIE_Sodium_Compat::$fastMult = $depart;
			self::assertTrue( $espion->ed25519_verify( VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), VectorsFixture::bin( $ed['public_key'] ) ) );
			self::assertSame( [ $depart ], $pendant );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_le_sous_groupe_est_controle_en_compat_par_double_multiplication(): void {
		$sodium = self::compat();

		$mixte = VectorsFixture::named( 'index_tampering', 'cle_publique_d_ordre_mixte' );
		$ed    = VectorsFixture::section( 'self_test' )['ed25519'][0];
		$cas   = [];
		foreach ( self::signatures_valides() as $nom_cas => $valeurs ) {
			$cas[ $nom_cas ] = [ $valeurs, true ];
		}
		$cas['cle_publique_d_ordre_mixte'] = [ [ VectorsFixture::bin( $mixte['signature'] ), self::INDEX_DOMAIN . $mixte['index'], VectorsFixture::bin( $mixte['public_key'] ) ], false ];
		$cas['cle_hors_courbe']            = [ [ VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), self::bin( self::HORS_COURBE ) ], false ];
		$cas['cle_d_ordre_2l']             = [ [ VectorsFixture::bin( $ed['signature'] ), VectorsFixture::bin( $ed['message'] ), self::bin( self::ORDRE_2L ) ], false ];

		// La clé hors de la courbe passe bien par une exception de sodium_compat (RangeException
		// « Internal check failed. » en 1.24.0 et dans la copie de 6.4), que la classe convertit.
		try {
			\ParagonIE_Sodium_Core_Ed25519::ge_frombytes_negate_vartime( self::bin( self::HORS_COURBE ) );
			self::fail( 'La clé hors de la courbe se décode : le cas ne prouve plus rien.' );
		} catch ( \Throwable $e ) {
			self::assertInstanceOf( \RangeException::class, $e );
		}

		foreach ( [ false, true ] as $depart ) {
			foreach ( $cas as $nom_cas => [ [ $s, $m, $k ], $attendu ] ) {
				\ParagonIE_Sodium_Compat::$fastMult = $depart;
				self::assertSame( $attendu, $sodium->ed25519_verify( $s, $m, $k ), $nom_cas );
				self::assertSame( $depart, \ParagonIE_Sodium_Compat::$fastMult, '$fastMult non rétabli après ' . $nom_cas . ' (départ ' . var_export( $depart, true ) . ').' );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_fastmult_vrai_pendant_le_calcul_et_retabli_meme_si_le_verificateur_leve(): void {
		// La vérification de sodium_compat met $fastMult à vrai et ne le rétablit pas quand elle
		// lève ensuite. Avec de vraies données, elle ne lève plus une fois le contrôle n° 5 passé :
		// le vérificateur doublé reproduit ce comportement pour éprouver le rétablissement.
		$imite  = static function (): bool {
			\ParagonIE_Sodium_Compat::$fastMult = true;
			throw new \SodiumException( 'imitation' );
		};
		$sodium = self::compat( $imite );
		$valide = VectorsFixture::named( 'index', 'index_complet' );

		\ParagonIE_Sodium_Compat::$fastMult = false;
		self::assertFalse( $sodium->ed25519_verify( VectorsFixture::bin( $valide['signature'] ), self::INDEX_DOMAIN . $valide['index'], VectorsFixture::bin( $valide['public_key'] ) ) );
		self::assertFalse( \ParagonIE_Sodium_Compat::$fastMult );

		// Pendant le calcul, $fastMult est vrai (multiplications rapides : 17 à 52 ms au lieu de
		// 180 à 230 pour le contrôle n° 5, mesuré le 24/10).
		$pendant = null;
		$espion  = static function () use ( &$pendant ): bool {
			$pendant = \ParagonIE_Sodium_Compat::$fastMult;
			return true;
		};
		\ParagonIE_Sodium_Compat::$fastMult = false;
		self::assertTrue( self::compat( $espion )->ed25519_verify( VectorsFixture::bin( $valide['signature'] ), self::INDEX_DOMAIN . $valide['index'], VectorsFixture::bin( $valide['public_key'] ) ) );
		self::assertTrue( $pendant );
		self::assertFalse( \ParagonIE_Sodium_Compat::$fastMult );
	}

	public function test_le_controle_compat_n_emploie_ni_pk_to_curve25519_ni_ge_mul_l(): void {
		// Révisé à la relecture de la tâche 13 : le contrôle n° 5 est un seul calcul, le même pour
		// les deux moteurs. sodium_crypto_sign_ed25519_pk_to_curve25519 accepte la clé d'ordre 2·L
		// (libsodium ne teste que X) ; les conversions de sodium_compat et ge_mul_l acceptent la clé
		// d'ordre mixte dans les copies de WordPress. Aucune n'apparaît donc, sous aucune forme.
		$calcul         = [];
		$appels_du_n5   = [];
		$avec_fast_mult = [];
		foreach ( self::identifiants_du_code() as [ $fonction, $nom ] ) {
			self::assertStringNotContainsString( 'ge_mul_l', $nom, 'ge_mul_l est faux dans les copies de WordPress (dans ' . $fonction . ').' );
			self::assertStringNotContainsString( 'pk_to_curve25519', $nom, 'Conversion vers Curve25519 employée dans ' . $fonction . ' : elle accepte une clé hors du sous-groupe principal (2·L en natif, ordre mixte dans les copies de WordPress).' );
			if ( 'ge_double_scalarmult_vartime' === $nom ) {
				$calcul[] = $fonction;
			}
			if ( 'main_subgroup' === $nom ) {
				$appels_du_n5[] = $fonction;
			}
			if ( 'with_fast_mult' === $nom ) {
				$avec_fast_mult[] = $fonction;
			}
		}
		self::assertSame( [ 'main_subgroup' ], $calcul, 'Contrôle n° 5 attendu par un seul ge_double_scalarmult_vartime, dans la méthode commune aux deux moteurs.' );
		self::assertSame( [ 'ed25519_verify' ], $appels_du_n5, 'La méthode commune est appelée une seule fois, par ed25519_verify, pour les deux moteurs.' );
		self::assertContains( 'main_subgroup', $avec_fast_mult, 'Le calcul du n° 5 doit passer par with_fast_mult ($fastMult mis à vrai, puis rétabli).' );
	}

	public function test_chaque_moteur_n_est_appele_que_dans_ses_methodes(): void {
		// En compat, jamais les fonctions globales sodium_* : sur le poste, elles sont natives, et
		// un cas « compat » qui les appellerait éprouverait le natif sans rien dire. Aucun test
		// d'exécution ne le verrait (mêmes octets) : la règle est contrôlée à la lecture du code.
		// Seule exception côté sodium_compat (relecture de la tâche 13) : le calcul du contrôle
		// n° 5, commun aux deux moteurs, qui n'emploie que les classes Core et $fastMult.
		$communes = [
			'main_subgroup'  => 'ParagonIE_Sodium_Core_Ed25519',
			'with_fast_mult' => 'ParagonIE_Sodium_Compat',
		];
		$natif    = 0;
		$compat   = 0;
		foreach ( self::identifiants_du_code() as [ $fonction, $nom ] ) {
			if ( str_starts_with( strtolower( $nom ), 'sodium_' ) ) {
				self::assertStringEndsWith( '_native', $fonction, 'Fonction globale ' . $nom . ' appelée dans ' . $fonction . '.' );
				++$natif;
			}
			if ( str_starts_with( $nom, 'ParagonIE_' ) ) {
				if ( isset( $communes[ $fonction ] ) ) {
					self::assertSame( $communes[ $fonction ], $nom, 'Classe ' . $nom . ' employée dans la méthode commune ' . $fonction . '.' );
				} else {
					self::assertStringEndsWith( '_compat', $fonction, 'Classe ' . $nom . ' employée dans ' . $fonction . '.' );
				}
				++$compat;
			}
		}
		self::assertGreaterThanOrEqual( 8, $natif );
		self::assertGreaterThanOrEqual( 8, $compat );
	}

	// ---------------------------------------------------------------------------------------
	// Exceptions et effacement.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_une_sodium_exception_devient_un_code_stable(): void {
		$secret = VectorsFixture::bin( VectorsFixture::section( 'self_test' )['x25519']['alice_private'] );
		$nulle  = str_repeat( "\0", 32 );

		// Appelés seuls, les deux moteurs lèvent une SodiumException pour ce point.
		if ( extension_loaded( 'sodium' ) ) {
			try {
				sodium_crypto_scalarmult( $secret, $nulle );
				self::fail( 'sodium natif seul n\'a pas levé.' );
			} catch ( \SodiumException $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		$origine = '';
		try {
			\ParagonIE_Sodium_Compat::crypto_scalarmult( $secret, $nulle );
			self::fail( 'sodium_compat seul n\'a pas levé.' );
		} catch ( \SodiumException $e ) {
			$origine = $e->getMessage();
		}
		self::assertNotSame( '', $origine );

		foreach ( self::moteurs() as $nom => $sodium ) {
			try {
				$sodium->x25519( $secret, $nulle );
				self::fail( 'Point d\'ordre faible accepté, ' . $nom );
			} catch ( FormatError $e ) {
				self::assertSame( FormatError::BAD_PUBLIC_KEY, $e->reason(), $nom );
				self::assertSame( FormatError::BAD_PUBLIC_KEY, $e->getMessage(), $nom );
				self::assertStringNotContainsString( $origine, $e->getMessage(), $nom );
				self::assertNull( $e->getPrevious(), 'Message d\'origine propagé par l\'exception chaînée, ' . $nom );
			}
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_wipe_en_compat_ecrase_sans_appeler_sodium_memzero(): void {
		$sodium = self::compat();
		$secret = random_bytes( 32 );
		$copie  = $secret;

		$sodium->wipe( $secret );

		self::assertSame( str_repeat( "\0", 32 ), $secret );
		self::assertNotSame( $copie, $secret );

		// Lecture du source : sans sodium natif, sodium_memzero() comme
		// ParagonIE_Sodium_Compat::memzero() lèvent une SodiumException.
		$vus = 0;
		foreach ( self::identifiants_du_code() as [ $fonction, $nom ] ) {
			if ( str_contains( strtolower( $nom ), 'memzero' ) ) {
				self::assertSame( 'sodium_memzero', $nom, 'Effacement de sodium_compat employé dans ' . $fonction . '.' );
				self::assertSame( 'wipe_native', $fonction, 'sodium_memzero hors de la branche native.' );
				++$vus;
			}
		}
		self::assertSame( 1, $vus );
	}

	public function test_wipe_en_natif_passe_par_sodium_memzero(): void {
		$sodium = self::natif();
		$secret = random_bytes( 32 );

		$sodium->wipe( $secret );

		self::assertSame( '', $secret, 'sodium_memzero laisse la variable à null : la classe la remet à une chaîne vide.' );
	}

	// ---------------------------------------------------------------------------------------
	// Aides.
	// ---------------------------------------------------------------------------------------

	/**
	 * Moteur natif. Le chargeur rend vrai par défaut : le contrôle n° 5 emploie, en natif aussi, les
	 * classes Core de sodium_compat déjà chargeables (celles de vendor, ou celles de la copie
	 * rejouée, chargée avant).
	 */
	private static function natif( ?\Closure $verifier = null, ?\Closure $load_compat = null ): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, $load_compat ?? static fn (): bool => true, $verifier );
	}

	private static function compat( ?\Closure $verifier = null ): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true, $verifier );
	}

	/**
	 * Les moteurs joués : natif si l'extension est chargée, compat toujours.
	 *
	 * @return array<string, Sodium>
	 */
	private static function moteurs( ?\Closure $verifier = null ): array {
		$moteurs = [];
		if ( extension_loaded( 'sodium' ) ) {
			$moteurs['natif'] = self::natif( $verifier );
		}
		$moteurs['compat'] = self::compat( $verifier );
		return $moteurs;
	}

	/**
	 * Signatures valides : TEST 1 à 3 de la RFC 8032 et index des vecteurs.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private static function signatures_valides(): array {
		$cas = [];
		foreach ( VectorsFixture::section( 'self_test' )['ed25519'] as $test ) {
			$cas[ $test['name'] ] = [ VectorsFixture::bin( $test['signature'] ), VectorsFixture::bin( $test['message'] ), VectorsFixture::bin( $test['public_key'] ) ];
		}
		foreach ( VectorsFixture::section( 'index' ) as $index ) {
			$cas[ $index['name'] ] = [ VectorsFixture::bin( $index['signature'] ), self::INDEX_DOMAIN . $index['index'], VectorsFixture::bin( $index['public_key'] ) ];
		}
		return $cas;
	}

	private static function assert_rfc_7748( Sodium $sodium ): void {
		$x = VectorsFixture::section( 'self_test' )['x25519'];

		self::assertSame( $x['alice_public'], bin2hex( $sodium->x25519_base( VectorsFixture::bin( $x['alice_private'] ) ) ) );
		self::assertSame( $x['shared_secret'], bin2hex( $sodium->x25519( VectorsFixture::bin( $x['alice_private'] ), VectorsFixture::bin( $x['bob_public'] ) ) ) );
	}

	private static function assert_rfc_8032( Sodium $sodium ): void {
		$tests = VectorsFixture::section( 'self_test' )['ed25519'];
		self::assertCount( 3, $tests );

		foreach ( $tests as $test ) {
			$paire = $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( $test['seed'] ) );
			self::assertSame( [ 'public', 'secret' ], array_keys( $paire ), $test['name'] );
			self::assertSame( $test['public_key'], bin2hex( $paire['public'] ), $test['name'] );
			self::assertSame( $test['seed'] . $test['public_key'], bin2hex( $paire['secret'] ), $test['name'] );

			$message   = VectorsFixture::bin( $test['message'] );
			$signature = $sodium->ed25519_sign( $message, $paire['secret'] );
			self::assertSame( $test['signature'], bin2hex( $signature ), $test['name'] );
			self::assertTrue( $sodium->ed25519_verify( $signature, $message, $paire['public'] ), $test['name'] );

			$alteree     = $signature;
			$alteree[0]  = chr( ord( $alteree[0] ) ^ 0x01 );
			self::assertFalse( $sodium->ed25519_verify( $alteree, $message, $paire['public'] ), $test['name'] . ' altérée' );
			self::assertFalse( $sodium->ed25519_verify( $signature, $message . 'x', $paire['public'] ), $test['name'] . ' autre message' );
		}
	}

	private static function assert_format_error( string $code, \Closure $operation ): void {
		try {
			$operation();
		} catch ( FormatError $e ) {
			self::assertSame( $code, $e->reason() );
			self::assertSame( $code, $e->getMessage() );
			self::assertNull( $e->getPrevious() );
			return;
		}
		self::fail( 'FormatError « ' . $code . ' » attendue.' );
	}

	private static function bin( string $hex ): string {
		return (string) hex2bin( $hex );
	}

	/**
	 * Identifiants (noms de fonctions, de classes, de méthodes) et chaînes littérales du code de
	 * Sodium.php, commentaires écartés (le docblock explique pourquoi certains appels sont
	 * exclus), chacun avec la méthode qui le contient (dernière fonction nommée déclarée avant).
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function identifiants_du_code(): array {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Backup/Crypto/Sodium.php' );
		self::assertIsString( $source );

		$noms       = [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ];
		$fonction   = '';
		$attend_nom = false;
		$resultat   = [];
		foreach ( token_get_all( $source ) as $jeton ) {
			if ( ! is_array( $jeton ) ) {
				$attend_nom = false;
				continue;
			}
			[ $type, $texte ] = $jeton;
			if ( in_array( $type, [ T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ], true ) ) {
				continue;
			}
			if ( T_FUNCTION === $type ) {
				$attend_nom = true;
				continue;
			}
			if ( $attend_nom ) {
				$attend_nom = false;
				if ( T_STRING === $type ) {
					$fonction = $texte;
					continue;
				}
			}
			if ( in_array( $type, $noms, true ) ) {
				$parties    = explode( '\\', $texte );
				$resultat[] = [ $fonction, (string) end( $parties ) ];
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $type ) {
				$resultat[] = [ $fonction, substr( $texte, 1, -1 ) ];
			}
		}
		return $resultat;
	}
}
