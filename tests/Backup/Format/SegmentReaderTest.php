<?php
/**
 * Lecture d'un segment G2BK v1 (Backup\Format\SegmentReader).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Backup\Format\SegmentReader;
use G2RD\Connector\Backup\Format\SegmentWriter;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : sections `segments` (six cas) et `tampering` (21 cas) du fichier de
 * vecteurs, produites par le générateur Python indépendant de la plateforme. Jamais une sortie du
 * code testé. Les altérations sont appliquées ici comme les applique le générateur (alterer() de
 * generer_vecteurs.py : xor, truncate, append, swap_blocks, graft_block, wrong_recipient), sur le
 * segment source du cas. Le cas `grand_bloc` ne porte pas ses octets : il est réécrit par
 * l'écrivain avec le tirage du vecteur, et le segment obtenu est comparé à son empreinte avant
 * d'être lu.
 *
 * Flux : php://memory pour les segments lus d'un coup ; un protocole de test (classe anonyme
 * enregistrée une fois par processus) pour un flux qui ne rend que quelques octets à chaque
 * lecture. Pour un flux utilisateur, PHP fait une seule lecture du protocole par fread() (relevé du
 * 24/10/2026, PHP 8.4.3) : fread() rend alors au plus ces quelques octets, et le lecteur doit
 * reprendre lui-même.
 *
 * Moteurs : comme SegmentWriterTest. Natif si l'extension est chargée ; « compat » = la copie de
 * sodium_compat de vendor (1.24.0, celle du parc), forcée en PHP pur par
 * $disableFallbackForUnitTests. Seule l'ouverture de la clé de données dépend du moteur : les
 * blocs passent par OpenSSL dans les deux. Les cas qui jouent le moteur compat portent le groupe
 * sodium-compat.
 */
final class SegmentReaderTest extends TestCase {

	/** Les cas de la section `segments` qui portent leurs octets (tous sauf `grand_bloc`). */
	private const CAS_AVEC_OCTETS = [
		'trois_blocs_dernier_partiel',
		'multiple_exact_bloc_final_vide',
		'segment_vide',
		'manifeste',
		'cle_de_site_version_7',
	];

	/**
	 * Les 21 cas de `tampering`, dans l'ordre du fichier, avec le nombre de blocs du clair qui
	 * s'authentifient avant le refus, donc livrés à $sink (segment source : trois blocs de 16, 16
	 * et 8 octets, soit 32, 32 et 24 octets écrits). Lecture par les seules longueurs (§ 4) :
	 * - `bloc_final_retire` (212 octets) : le bloc 0 est suivi d'un octet, il est donc non final,
	 *   s'authentifie et est livré ; il reste ensuite exactement 32 octets (block_size + 16) :
	 *   truncated ;
	 * - `etiquette_modifiee`, `bloc_final_tronque_d_un_octet`, `octet_ajoute_apres_le_bloc_final` :
	 *   seul le bloc final est touché, les blocs 0 et 1 sont livrés.
	 */
	private const LIVRES_AVANT_LE_REFUS = [
		'magie_fausse'                      => 0,
		'version_inconnue'                  => 0,
		'suite_inconnue'                    => 0,
		'nature_inconnue'                   => 0,
		'drapeaux_reserves'                 => 0,
		'taille_de_bloc_trop_petite'        => 0,
		'version_de_cle_nulle'              => 0,
		'en_tete_tronque'                   => 0,
		'identifiant_de_sauvegarde_modifie' => 0,
		'cle_ephemere_modifiee'             => 0,
		'cle_scellee_modifiee'              => 0,
		'engagement_de_cle_modifie'         => 0,
		'index_de_segment_hors_bornes'      => 0,
		'octet_modifie_dans_un_bloc'        => 0,
		'etiquette_modifiee'                => 2,
		'blocs_permutes'                    => 0,
		'bloc_final_retire'                 => 1,
		'bloc_final_tronque_d_un_octet'     => 2,
		'octet_ajoute_apres_le_bloc_final'  => 2,
		'bloc_greffe_d_un_autre_segment'    => 0,
		'mauvais_destinataire'              => 0,
	];

	/** Protocole du flux de test. */
	private const PROTOCOLE = 'g2rdtestsegmentreader';

	private bool $disable_fallback = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
	}

	protected function tearDown(): void {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------------------
	// Vecteurs.
	// ---------------------------------------------------------------------------------------

	public function test_chaque_segment_des_vecteurs_se_dechiffre(): void {
		self::assert_segments_dechiffres( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_se_dechiffre_en_compat(): void {
		self::assert_segments_dechiffres( self::compat() );
	}

	#[Group( 'sodium-compat' )]
	public function test_open_key_rend_la_cle_de_donnees_du_vecteur(): void {
		foreach ( self::moteurs() as $moteur => $sodium ) {
			$lecteur = new SegmentReader( new Hpke( $sodium ) );
			foreach ( self::CAS_AVEC_OCTETS as $nom ) {
				$v   = self::segment( $nom );
				$dek = $lecteur->open_key( SegmentHeader::parse( $v['segment'] ), $v['sk_r'] );
				self::assertSame( bin2hex( $v['dek'] ), bin2hex( $dek ), $moteur . ' : ' . $nom );
			}

			// Engagement faux d'un bit, clé de données juste : key_commitment_mismatch ; mauvaise clé
			// secrète : seal_open_failed (l'engagement n'est pas atteint).
			$v             = self::segment( 'trois_blocs_dernier_partiel' );
			$altere        = $v['segment'];
			$altere[ 116 ] = chr( ord( $altere[ 116 ] ) ^ 0x80 );
			self::assert_format_error( FormatError::KEY_COMMITMENT_MISMATCH, static fn () => $lecteur->open_key( SegmentHeader::parse( $altere ), $v['sk_r'] ), $moteur . ' : engagement' );
			self::assert_format_error( FormatError::SEAL_OPEN_FAILED, static fn () => $lecteur->open_key( SegmentHeader::parse( $v['segment'] ), $v['sk_e'] ), $moteur . ' : clé éphémère de l\'écrivain' );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Altérations.
	// ---------------------------------------------------------------------------------------

	/**
	 * Les 21 cas de `tampering`, par nom.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function alterations(): array {
		$cas = [];
		foreach ( VectorsFixture::section( 'tampering' ) as $alteration ) {
			if ( is_array( $alteration ) && is_string( $alteration['name'] ?? null ) ) {
				$cas[ $alteration['name'] ] = [ $alteration['name'] ];
			}
		}
		return $cas;
	}

	#[DataProvider( 'alterations' )]
	public function test_chaque_alteration_est_refusee_avec_son_code( string $nom ): void {
		[ $octets, $sk_r, $code ] = self::alteration( $nom );
		$lecteur                  = new SegmentReader( new Hpke( self::moteur() ) );
		self::assert_format_error( $code, static fn () => self::lire( $lecteur, $octets, $sk_r ), $nom );
	}

	#[Group( 'sodium-compat' )]
	public function test_alterations_refusees_en_compat(): void {
		$lecteur = new SegmentReader( new Hpke( self::compat() ) );
		foreach ( array_keys( self::alterations() ) as $nom ) {
			[ $octets, $sk_r, $code ] = self::alteration( $nom );
			self::assert_format_error( $code, static fn () => self::lire( $lecteur, $octets, $sk_r ), 'compat : ' . $nom );
		}
	}

	public function test_les_21_cas_du_fichier_sont_essayes(): void {
		self::assertSame( array_keys( self::LIVRES_AVANT_LE_REFUS ), array_keys( self::alterations() ), 'Les 21 cas, dans l\'ordre du fichier.' );
		self::assertCount( 21, self::alterations() );
		self::assertSame( FormatError::BAD_SEGMENT_INDEX, self::alteration( 'index_de_segment_hors_bornes' )[2] );
	}

	// ---------------------------------------------------------------------------------------
	// Ce qui est livré.
	// ---------------------------------------------------------------------------------------

	public function test_rien_n_est_livre_avant_verification_du_bloc(): void {
		$source  = self::segment( 'trois_blocs_dernier_partiel' );
		$blocs   = str_split( $source['plaintext'], $source['block_size'] );
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );
		self::assertCount( 3, $blocs );

		$livres_en_tout = 0;
		foreach ( self::LIVRES_AVANT_LE_REFUS as $nom => $attendus ) {
			[ $octets, $sk_r, $code ] = self::alteration( $nom );
			$livres                   = self::livres_avant_le_refus( $lecteur, $octets, $sk_r, $code, $nom );
			// Chaque appel de $sink est un bloc entier qui s'est authentifié, dans l'ordre ; le bloc
			// refusé, et tout ce qui le suit, n'est jamais livré.
			self::assertSame( array_slice( $blocs, 0, $attendus ), $livres, $nom );
			$livres_en_tout += count( $livres );
		}
		self::assertSame( 7, $livres_en_tout, 'Contrôle du test : les blocs livrés avant un refus sont bien vus.' );

		// Un octet modifié dans le bloc du milieu (bloc 1) : le bloc 0 est livré, le bloc 1 jamais.
		$altere             = $source['segment'];
		$altere[ 148 + 40 ] = chr( ord( $altere[ 148 + 40 ] ) ^ 0x01 );
		self::assertSame( [ $blocs[0] ], self::livres_avant_le_refus( $lecteur, $altere, $source['sk_r'], FormatError::BLOCK_AUTH_FAILED, 'bloc 1 modifié' ), 'bloc 1 modifié' );

		// Étiquette du bloc 0 modifiée : rien n'est livré.
		$altere             = $source['segment'];
		$altere[ 148 + 31 ] = chr( ord( $altere[ 148 + 31 ] ) ^ 0x01 );
		self::assertSame( [], self::livres_avant_le_refus( $lecteur, $altere, $source['sk_r'], FormatError::BLOCK_AUTH_FAILED, 'étiquette du bloc 0' ), 'étiquette du bloc 0' );
	}

	/**
	 * Règle des longueurs (§ 4, « Lecture ») au-delà des cas du fichier : après le dernier bloc
	 * plein, il reste 0 octet, de 1 à 15 octets, ou exactement block_size + 16 octets → truncated,
	 * sans aucun essai d'authentification ; de 16 à block_size + 15 octets → bloc final, qui ne
	 * s'authentifie que s'il est intact.
	 */
	public function test_fin_du_segment_lue_par_les_seules_longueurs(): void {
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );

		// Segment vide : l'en-tête seul, ou l'étiquette du bloc final vide amputée.
		$vide = self::segment( 'segment_vide' );
		self::assertSame( 164, strlen( $vide['segment'] ) );
		foreach ( [ 148, 149, 155, 163 ] as $longueur ) {
			self::assertSame( [], self::livres_avant_le_refus( $lecteur, substr( $vide['segment'], 0, $longueur ), $vide['sk_r'], FormatError::TRUNCATED, 'segment vide coupé à ' . $longueur ), 'segment vide coupé à ' . $longueur );
		}

		// Multiple exact : deux blocs pleins, puis l'étiquette seule du bloc final vide.
		$multiple = self::segment( 'multiple_exact_bloc_final_vide' );
		$blocs    = str_split( $multiple['plaintext'], 16 );
		self::assertSame( 228, strlen( $multiple['segment'] ) );
		foreach ( [ 212 => 1, 213 => 2, 220 => 2, 227 => 2 ] as $longueur => $livres ) {
			self::assertSame( array_slice( $blocs, 0, $livres ), self::livres_avant_le_refus( $lecteur, substr( $multiple['segment'], 0, $longueur ), $multiple['sk_r'], FormatError::TRUNCATED, 'multiple exact coupé à ' . $longueur ), 'multiple exact coupé à ' . $longueur );
		}

		// Trois blocs, dernier partiel : bloc final de 24 octets coupé à 15 (truncated) ou à 16
		// (assez pour un bloc final, mais il ne s'authentifie pas).
		$partiel = self::segment( 'trois_blocs_dernier_partiel' );
		$blocs   = str_split( $partiel['plaintext'], 16 );
		self::assertSame( array_slice( $blocs, 0, 2 ), self::livres_avant_le_refus( $lecteur, substr( $partiel['segment'], 0, 148 + 64 + 15 ), $partiel['sk_r'], FormatError::TRUNCATED, '15 octets du bloc final' ), '15 octets du bloc final' );
		self::assertSame( array_slice( $blocs, 0, 2 ), self::livres_avant_le_refus( $lecteur, substr( $partiel['segment'], 0, 148 + 64 + 16 ), $partiel['sk_r'], FormatError::BLOCK_AUTH_FAILED, '16 octets du bloc final' ), '16 octets du bloc final' );
	}

	public function test_lecture_par_petits_morceaux_du_flux(): void {
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );
		foreach ( array_merge( self::CAS_AVEC_OCTETS, [ 'grand_bloc' ] ) as $nom ) {
			$v      = self::segment( $nom );
			$flux   = self::flux_par_morceaux( $v['segment'], 5 );
			$livres = [];
			$sink   = static function ( string $clair ) use ( &$livres ): void {
				$livres[] = $clair;
			};

			$resultat = $lecteur->decrypt( $flux, $v['sk_r'], $sink );

			self::assertSame( bin2hex( $v['plaintext'] ), bin2hex( implode( '', $livres ) ), $nom . ' : clair lu par morceaux de 5 octets' );
			self::assertSame( $v['blocks'], $resultat['blocks'], $nom );
			self::assertSame( $v['bytes'], $resultat['bytes'], $nom );
			$classe = self::classe_du_flux();
			self::assertSame( strlen( $v['segment'] ), $classe::$position, $nom . ' : tout le flux est lu' );
			self::assertSame( 5, $classe::$plus_grande_lecture, $nom . ' : jamais plus de 5 octets par lecture' );
			self::assertGreaterThanOrEqual( intdiv( strlen( $v['segment'] ), 5 ), $classe::$lectures, $nom . ' : le lecteur a repris lui-même chaque lecture partielle' );
		}

		// Les refus ne dépendent pas du découpage du flux (lecture par les seules longueurs).
		foreach ( [ 'en_tete_tronque', 'bloc_final_retire', 'bloc_final_tronque_d_un_octet', 'octet_ajoute_apres_le_bloc_final', 'blocs_permutes' ] as $nom ) {
			[ $octets, $sk_r, $code ] = self::alteration( $nom );
			foreach ( [ 1, 5, 17 ] as $taille ) {
				self::assert_format_error( $code, static fn () => $lecteur->decrypt( self::flux_par_morceaux( $octets, $taille ), $sk_r, static function (): void {} ), $nom . ', morceaux de ' . $taille );
			}
		}
	}

	public function test_aller_retour_avec_l_ecrivain_pour_des_tailles_aleatoires(): void {
		$v       = self::segment( 'trois_blocs_dernier_partiel' );
		$hpke    = new Hpke( self::moteur() );
		$lecteur = new SegmentReader( $hpke );

		// Les bornes d'abord (blocs de 16 octets), puis des tailles et des tailles de bloc tirées :
		// 50 tailles en tout, de 0 à 3 blocs + 1.
		$essais = [];
		foreach ( [ 0, 1, 15, 16, 17, 31, 32, 33, 47, 48, 49 ] as $taille ) {
			$essais[] = [ 16, $taille ];
		}
		while ( count( $essais ) < 50 ) {
			$taille_de_bloc = random_int( 16, 80 );
			$essais[]       = [ $taille_de_bloc, random_int( 0, 3 * $taille_de_bloc + 1 ) ];
		}

		foreach ( $essais as [ $taille_de_bloc, $taille ] ) {
			$libelle  = $taille . ' octets en blocs de ' . $taille_de_bloc;
			$clair    = $taille > 0 ? random_bytes( $taille ) : '';
			$ecrivain = new SegmentWriter( $hpke );
			$flux     = self::flux_memoire();
			$ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, random_bytes( 16 ), random_int( 0, 4095 ), random_int( 1, 3 ), random_int( 1, 9 ), $taille_de_bloc, $v['pk_r'] );
			$ecrivain->write( $clair );
			$ecrit = $ecrivain->finish();
			rewind( $flux );

			$livres   = [];
			$resultat = $lecteur->decrypt(
				$flux,
				$v['sk_r'],
				static function ( string $bloc ) use ( &$livres ): void {
					$livres[] = $bloc;
				}
			);

			self::assertSame( bin2hex( $clair ), bin2hex( implode( '', $livres ) ), $libelle );
			self::assertSame( $ecrit['blocks'], $resultat['blocks'], $libelle . ' : nombre de blocs de l\'écrivain' );
			self::assertSame( $ecrit['bytes'], $resultat['bytes'], $libelle . ' : taille écrite par l\'écrivain' );
			self::assertSame( intdiv( $taille, $taille_de_bloc ) + 1, $resultat['blocks'], $libelle . ' : bloc final compris' );
			self::assertCount( $resultat['blocks'], $livres, $libelle . ' : un appel de $sink par bloc' );
			self::assertLessThan( $taille_de_bloc, strlen( (string) end( $livres ) ), $libelle . ' : bloc final plus court qu\'un bloc' );
			rewind( $flux );
			$octets = (string) stream_get_contents( $flux );
			self::assertSame( bin2hex( substr( $octets, 0, 148 ) ), bin2hex( $resultat['header']->encode() ), $libelle . ' : en-tête rendu' );
			self::assertSame( bin2hex( $ecrit['sha256'] ), hash( 'sha256', $octets ), $libelle );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Clés.
	// ---------------------------------------------------------------------------------------

	/**
	 * Réflexion sur ce que garde le lecteur : k_bloc (propriété block_key) n'existe que pendant la
	 * lecture, vide ou remise à zéro dès que decrypt() rend ou lève ; la clé de données n'est dans
	 * aucune propriété, ni pendant ni après. Chaque refus est essayé : à l'ouverture de la clé
	 * (avant k_bloc), au milieu des blocs, à la troncature, et quand $sink lève lui-même.
	 * Limite : l'effacement des variables locales (clé de données dans decrypt() et open_key(),
	 * clair de chaque bloc) ne se voit pas depuis l'objet (une mutation qui le retire passe ce test) ;
	 * il est relu dans le code, et test_effacement_par_sodium_memzero_seulement_en_natif contrôle
	 * la règle d'effacement.
	 */
	public function test_cle_effacee_meme_en_cas_d_erreur(): void {
		$v       = self::segment( 'trois_blocs_dernier_partiel' );
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );
		self::assertSame( [], ( new \ReflectionClass( SegmentReader::class ) )->getStaticProperties(), 'Aucune propriété statique : tout ce que garde le lecteur est dans l\'objet.' );

		// Pendant la lecture : k_bloc est gardée (elle ouvre les blocs), la clé de données non.
		$vues = [];
		$sink = static function () use ( $lecteur, $v, &$vues ): void {
			$vues[] = self::propriete( $lecteur, 'block_key' );
			$gardees = self::chaines_gardees( $lecteur );
			self::assertFalse( self::contient( $gardees, $v['dek'] ), 'Clé de données gardée pendant la lecture.' );
			self::assertFalse( self::contient( $gardees, $v['commitment_key'] ), 'k_engagement gardée pendant la lecture.' );
		};
		$lecteur->decrypt( self::flux_de( $v['segment'] ), $v['sk_r'], $sink );
		self::assertSame( array_fill( 0, 3, bin2hex( $v['block_key'] ) ), array_map( 'bin2hex', $vues ), 'Le test doit voir k_bloc tant qu\'elle sert.' );
		self::assert_rien_de_garde( $lecteur, $v, 'après une lecture réussie' );

		// Refus au milieu des blocs, à la troncature, à l'ouverture de la clé et à l'engagement.
		foreach ( [ 'etiquette_modifiee', 'bloc_final_retire', 'blocs_permutes', 'mauvais_destinataire', 'engagement_de_cle_modifie' ] as $nom ) {
			[ $octets, $sk_r, $code ] = self::alteration( $nom );
			self::assert_format_error( $code, static fn () => self::lire( $lecteur, $octets, $sk_r ), $nom );
			self::assert_rien_de_garde( $lecteur, $v, 'après ' . $nom );
		}

		// $sink qui lève au deuxième bloc : son exception passe telle quelle, k_bloc est effacée.
		$panne  = new \RuntimeException( 'Disque plein (exception du test).' );
		$appels = 0;
		try {
			$lecteur->decrypt(
				self::flux_de( $v['segment'] ),
				$v['sk_r'],
				static function () use ( $panne, &$appels ): void {
					if ( 2 === ++$appels ) {
						throw $panne;
					}
				}
			);
			self::fail( 'L\'exception de $sink doit remonter.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( $panne, $e );
		}
		self::assertSame( 2, $appels );
		self::assert_rien_de_garde( $lecteur, $v, 'après une exception de $sink' );

		// Le lecteur sert encore après chaque échec : rien d'une lecture ne passe à la suivante.
		$livres = [];
		self::lire( $lecteur, $v['segment'], $v['sk_r'], $livres );
		self::assertSame( bin2hex( $v['plaintext'] ), bin2hex( implode( '', $livres ) ) );
	}

	public function test_effacement_par_sodium_memzero_seulement_en_natif(): void {
		// Même règle que Sodium::wipe(), SegmentHeader et SegmentWriter : sodium_memzero() n'est
		// appelée que dans wipe_native(), que wipe() choisit par extension_loaded( 'sodium' ), jamais
		// par function_exists() (sans l'extension, le sodium_memzero() global de sodium_compat lève).
		// L'engagement est comparé par hash_equals(), dans open_key().
		$memzero   = [];
		$extension = [];
		$egalites  = [];
		foreach ( self::identifiants_du_code() as [ $fonction, $nom ] ) {
			if ( str_contains( strtolower( $nom ), 'memzero' ) ) {
				$memzero[] = $fonction . ':' . $nom;
			}
			if ( in_array( $nom, [ 'extension_loaded', 'function_exists', 'is_callable' ], true ) ) {
				$extension[] = $fonction . ':' . $nom;
			}
			if ( 'hash_equals' === $nom ) {
				$egalites[] = $fonction;
			}
		}
		self::assertSame( [ 'wipe_native:sodium_memzero' ], $memzero );
		self::assertSame( [ 'wipe:extension_loaded' ], $extension );
		self::assertSame( [ 'open_key' ], $egalites );
	}

	// ---------------------------------------------------------------------------------------
	// Contrat d'appel.
	// ---------------------------------------------------------------------------------------

	public function test_entree_qui_n_est_pas_un_flux_est_une_erreur_de_programmation(): void {
		$v       = self::segment( 'trois_blocs_dernier_partiel' );
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );
		try {
			$lecteur->decrypt( 'php://memory', $v['sk_r'], static function (): void {} );
			self::fail( 'Entrée qui n\'est pas un flux acceptée.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'flux', $e->getMessage() );
			self::assertStringContainsString( 'Action :', $e->getMessage() );
			self::assertNull( $e->getPrevious() );
		}
	}

	public function test_une_lecture_imbriquee_sur_le_meme_lecteur_est_refusee(): void {
		$v       = self::segment( 'trois_blocs_dernier_partiel' );
		$lecteur = new SegmentReader( new Hpke( self::moteur() ) );
		try {
			$lecteur->decrypt(
				self::flux_de( $v['segment'] ),
				$v['sk_r'],
				static function () use ( $lecteur, $v ): void {
					$lecteur->decrypt( self::flux_de( $v['segment'] ), $v['sk_r'], static function (): void {} );
				}
			);
			self::fail( 'Lecture imbriquée acceptée.' );
		} catch ( \LogicException $e ) {
			self::assertNotInstanceOf( \InvalidArgumentException::class, $e );
			self::assertStringContainsString( 'Action :', $e->getMessage() );
			self::assertNull( $e->getPrevious() );
		}
		self::assert_rien_de_garde( $lecteur, $v, 'après la lecture imbriquée refusée' );

		// Deux lecteurs distincts lisent en même temps sans se gêner.
		$autre  = new SegmentReader( new Hpke( self::moteur() ) );
		$livres = [];
		$lecteur->decrypt(
			self::flux_de( $v['segment'] ),
			$v['sk_r'],
			static function ( string $bloc ) use ( $autre, $v, &$livres ): void {
				$livres[] = $bloc;
				$autre->decrypt( self::flux_de( $v['segment'] ), $v['sk_r'], static function (): void {} );
			}
		);
		self::assertSame( bin2hex( $v['plaintext'] ), bin2hex( implode( '', $livres ) ) );
	}

	// ---------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------

	private static function assert_segments_dechiffres( Sodium $sodium ): void {
		$lecteur = new SegmentReader( new Hpke( $sodium ) );
		foreach ( array_merge( self::CAS_AVEC_OCTETS, [ 'grand_bloc' ] ) as $nom ) {
			$v        = self::segment( $nom );
			$livres   = [];
			$resultat = self::lire( $lecteur, $v['segment'], $v['sk_r'], $livres );

			self::assertSame( [ 'header', 'blocks', 'bytes' ], array_keys( $resultat ), $nom );
			self::assertSame( bin2hex( $v['plaintext'] ), bin2hex( implode( '', $livres ) ), $nom . ' : clair' );
			self::assertSame( $v['blocks'], $resultat['blocks'], $nom . ' : blocs, bloc final compris' );
			self::assertSame( $v['bytes'], $resultat['bytes'], $nom . ' : taille de l\'objet, en-tête compris' );
			self::assertSame( bin2hex( substr( $v['segment'], 0, 148 ) ), bin2hex( $resultat['header']->encode() ), $nom . ' : en-tête' );
			self::assertCount( $v['blocks'], $livres, $nom . ' : un appel de $sink par bloc' );
			foreach ( $livres as $i => $bloc ) {
				$attendu = $i < $v['blocks'] - 1 ? $v['block_size'] : strlen( $v['plaintext'] ) % $v['block_size'];
				self::assertSame( $attendu, strlen( $bloc ), $nom . ' : taille du bloc ' . $i );
			}
		}
	}

	/**
	 * Après decrypt(), réussi ou non : k_bloc vide ou remise à zéro, et aucune clé ni aucun clair
	 * dans ce que garde le lecteur.
	 *
	 * @param array<string, mixed> $v
	 */
	private static function assert_rien_de_garde( SegmentReader $lecteur, array $v, string $libelle ): void {
		$k_bloc = self::propriete( $lecteur, 'block_key' );
		self::assertIsString( $k_bloc, $libelle );
		self::assertSame( str_repeat( "\0", strlen( $k_bloc ) ), $k_bloc, $libelle . ' : k_bloc ni vide ni remise à zéro' );
		$gardees = self::chaines_gardees( $lecteur );
		foreach ( [ 'clé de données' => $v['dek'], 'k_bloc' => $v['block_key'], 'k_engagement' => $v['commitment_key'], 'clé secrète du site' => $v['sk_r'], 'bloc final du clair' => substr( $v['plaintext'], 32 ) ] as $nom => $secret ) {
			self::assertFalse( self::contient( $gardees, $secret ), $nom . ' gardé(e) ' . $libelle . '.' );
		}
	}

	/**
	 * Lit $octets depuis un flux php://memory ; chaque bloc livré est ajouté à $livres.
	 *
	 * @param list<string> $livres
	 *
	 * @return array{header: SegmentHeader, blocks: int, bytes: int}
	 */
	private static function lire( SegmentReader $lecteur, string $octets, string $sk_r, array &$livres = [] ): array {
		return $lecteur->decrypt(
			self::flux_de( $octets ),
			$sk_r,
			static function ( string $bloc ) use ( &$livres ): void {
				$livres[] = $bloc;
			}
		);
	}

	/**
	 * Lit $octets, exige le refus $code et rend les blocs livrés à $sink avant lui.
	 *
	 * @return list<string>
	 */
	private static function livres_avant_le_refus( SegmentReader $lecteur, string $octets, string $sk_r, string $code, string $libelle ): array {
		$livres = [];
		self::assert_format_error(
			$code,
			static function () use ( $lecteur, $octets, $sk_r, &$livres ): void {
				self::lire( $lecteur, $octets, $sk_r, $livres );
			},
			$libelle
		);
		return $livres;
	}

	/**
	 * Octets altérés, clé secrète du destinataire à employer et code attendu d'un cas de
	 * `tampering`, comme alterer() du générateur.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function alteration( string $nom ): array {
		$cas = VectorsFixture::named( 'tampering', $nom );
		self::assertIsString( $cas['from'] ?? null, $nom );
		self::assertIsString( $cas['expected_error'] ?? null, $nom );
		$mutation = $cas['mutation'] ?? null;
		self::assertIsArray( $mutation, $nom );

		$source = self::segment( $cas['from'] );
		$octets = $source['segment'];
		$sk_r   = $source['sk_r'];
		switch ( $mutation['type'] ?? null ) {
			case 'xor':
				self::assertIsInt( $mutation['offset'] ?? null );
				$masque = VectorsFixture::bin( $mutation['mask'] ?? null );
				for ( $k = 0, $n = strlen( $masque ); $k < $n; $k++ ) {
					$position            = $mutation['offset'] + $k;
					$octets[ $position ] = chr( ord( $octets[ $position ] ) ^ ord( $masque[ $k ] ) );
				}
				break;
			case 'truncate':
				self::assertIsInt( $mutation['length'] ?? null );
				$octets = substr( $octets, 0, $mutation['length'] );
				break;
			case 'append':
				$octets .= VectorsFixture::bin( $mutation['hex'] ?? null );
				break;
			case 'swap_blocks':
				self::assertIsInt( $mutation['a'] ?? null );
				self::assertIsInt( $mutation['b'] ?? null );
				[ $en_tete, $blocs ]   = self::decouper_en_blocs( $octets );
				$a                     = $mutation['a'];
				$b                     = $mutation['b'];
				[ $blocs[ $a ], $blocs[ $b ] ] = [ $blocs[ $b ], $blocs[ $a ] ];
				$octets                = $en_tete . implode( '', $blocs );
				break;
			case 'graft_block':
				self::assertIsInt( $mutation['index'] ?? null );
				self::assertIsString( $mutation['from'] ?? null );
				[ $en_tete, $blocs ] = self::decouper_en_blocs( $octets );
				[ , $donneur ]       = self::decouper_en_blocs( self::segment( $mutation['from'] )['segment'] );
				$i                   = $mutation['index'];
				self::assertSame( strlen( $blocs[ $i ] ), strlen( $donneur[ $i ] ), 'greffe : blocs de même taille' );
				$blocs[ $i ] = $donneur[ $i ];
				$octets      = $en_tete . implode( '', $blocs );
				break;
			case 'wrong_recipient':
				$sk_r = VectorsFixture::bin( $mutation['sk_r'] ?? null );
				break;
			default:
				self::fail( 'Mutation inconnue dans le fichier de vecteurs : ' . $nom );
		}
		return [ $octets, $sk_r, $cas['expected_error'] ];
	}

	/**
	 * En-tête et blocs (chiffré ‖ étiquette) d'un segment bien formé, par les seules longueurs, comme
	 * _decouper_en_blocs() du générateur.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private static function decouper_en_blocs( string $segment ): array {
		$pas      = SegmentHeader::parse( $segment )->block_size() + 16;
		$blocs    = [];
		$position = 148;
		while ( strlen( $segment ) - $position > $pas ) {
			$blocs[]   = substr( $segment, $position, $pas );
			$position += $pas;
		}
		$blocs[] = substr( $segment, $position );
		return [ substr( $segment, 0, 148 ), $blocs ];
	}

	/**
	 * Un cas de la section `segments`, binaires en octets. `grand_bloc` : clair recalculé par det(),
	 * segment réécrit par l'écrivain avec le tirage du vecteur et contrôlé par son empreinte.
	 *
	 * @return array<string, mixed>
	 */
	private static function segment( string $nom ): array {
		$cas = VectorsFixture::named( 'segments', $nom );
		$v   = [];
		foreach ( [ 'kind', 'segment_index', 'site_key_version', 'block_size', 'blocks', 'bytes' ] as $champ ) {
			self::assertIsInt( $cas[ $champ ] ?? null, $nom . ' : ' . $champ );
			$v[ $champ ] = $cas[ $champ ];
		}
		foreach ( [ 'backup_id', 'sk_r', 'pk_r', 'dek', 'sk_e', 'block_key', 'commitment_key' ] as $champ ) {
			$v[ $champ ] = VectorsFixture::bin( $cas[ $champ ] ?? null );
		}
		self::assertIsString( $cas['sha256'] ?? null );
		if ( isset( $cas['plaintext_det'] ) ) {
			self::assertIsArray( $cas['plaintext_det'] );
			self::assertIsString( $cas['plaintext_det']['label'] ?? null );
			self::assertIsInt( $cas['plaintext_det']['length'] ?? null );
			$v['plaintext'] = self::det( $cas['plaintext_det']['label'], $cas['plaintext_det']['length'] );
			self::assertSame( $cas['plaintext_sha256'] ?? null, hash( 'sha256', $v['plaintext'] ), $nom . ' : clair recalculé par det() différent de celui du générateur.' );
			$v['segment'] = self::reecrire( $v );
		} else {
			foreach ( [ 'plaintext', 'segment' ] as $champ ) {
				$v[ $champ ] = VectorsFixture::bin( $cas[ $champ ] ?? null );
			}
		}
		self::assertSame( $cas['sha256'], hash( 'sha256', $v['segment'] ), $nom . ' : empreinte du segment' );
		return $v;
	}

	/**
	 * Segment d'un vecteur sans octets, écrit avec son tirage (clé de données, puis clé éphémère).
	 *
	 * @param array<string, mixed> $v
	 */
	private static function reecrire( array $v ): string {
		$tirages  = [ $v['dek'], $v['sk_e'] ];
		$tirage   = static function () use ( &$tirages ): string {
			return (string) array_shift( $tirages );
		};
		$ecrivain = new SegmentWriter( new Hpke( self::moteur(), $tirage ), $tirage );
		$flux     = self::flux_memoire();
		$ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, $v['backup_id'], $v['segment_index'], $v['kind'], $v['site_key_version'], $v['block_size'], $v['pk_r'] );
		$ecrivain->write( $v['plaintext'] );
		$ecrivain->finish();
		rewind( $flux );
		return (string) stream_get_contents( $flux );
	}

	/**
	 * Entrée déterministe du fichier de vecteurs : det( label, n ) = n premiers octets de
	 * SHA-256( « g2bk-vecteurs/ » ‖ label ‖ 00 00 00 00 ) ‖ SHA-256( … ‖ 00 00 00 01 ) ‖ …
	 */
	private static function det( string $label, int $longueur ): string {
		$entrees = VectorsFixture::section( 'deterministic_inputs' );
		self::assertSame( 'g2bk-vecteurs/', $entrees['prefix'] ?? null );
		$octets = '';
		for ( $compteur = 0; strlen( $octets ) < $longueur; ++$compteur ) {
			$octets .= hash( 'sha256', 'g2bk-vecteurs/' . $label . pack( 'N', $compteur ), true );
		}
		return substr( $octets, 0, $longueur );
	}

	/**
	 * Toutes les chaînes que garde l'objet : ses propriétés, celles des objets qu'il tient (Hpke,
	 * Sodium…), les éléments et clés des tableaux, les variables des fermetures (use et static) et
	 * l'objet auquel elles sont liées. Recopié de SegmentWriterTest.
	 *
	 * @return list<string>
	 */
	private static function chaines_gardees( object $racine ): array {
		$chaines = [];
		$vus     = new \SplObjectStorage();
		$visiter = static function ( mixed $valeur ) use ( &$visiter, &$chaines, $vus ): void {
			if ( is_string( $valeur ) ) {
				$chaines[] = $valeur;
				return;
			}
			if ( is_array( $valeur ) ) {
				foreach ( $valeur as $cle => $element ) {
					if ( is_string( $cle ) ) {
						$chaines[] = $cle;
					}
					$visiter( $element );
				}
				return;
			}
			if ( ! is_object( $valeur ) || $vus->contains( $valeur ) ) {
				return;
			}
			$vus->attach( $valeur );
			if ( $valeur instanceof \Closure ) {
				$fonction = new \ReflectionFunction( $valeur );
				$visiter( $fonction->getStaticVariables() );
				$liee = $fonction->getClosureThis();
				if ( null !== $liee ) {
					$visiter( $liee );
				}
				return;
			}
			for ( $classe = new \ReflectionObject( $valeur ); false !== $classe; $classe = $classe->getParentClass() ) {
				foreach ( $classe->getProperties() as $propriete ) {
					if ( ! $propriete->isStatic() && $propriete->isInitialized( $valeur ) ) {
						$visiter( $propriete->getValue( $valeur ) );
					}
				}
			}
		};
		$visiter( $racine );
		return $chaines;
	}

	/**
	 * @param list<string> $chaines
	 */
	private static function contient( array $chaines, string $cherche ): bool {
		foreach ( $chaines as $chaine ) {
			if ( str_contains( $chaine, $cherche ) ) {
				return true;
			}
		}
		return false;
	}

	private static function propriete( SegmentReader $lecteur, string $nom ): mixed {
		return ( new \ReflectionProperty( SegmentReader::class, $nom ) )->getValue( $lecteur );
	}

	/**
	 * Noms appelés ou cités dans le code de SegmentReader.php (commentaires écartés), chacun avec la
	 * méthode qui le contient (dernière fonction nommée déclarée avant). Recopié de
	 * SegmentHeaderTest.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function identifiants_du_code(): array {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Backup/Format/SegmentReader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lecture locale du source testé, hors WordPress.
		self::assertIsString( $source );

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
			if ( $attend_nom && T_STRING === $type ) {
				$attend_nom = false;
				$fonction   = $texte;
				continue;
			}
			$attend_nom = false;
			if ( in_array( $type, [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true ) ) {
				$parties    = explode( '\\', $texte );
				$resultat[] = [ $fonction, (string) end( $parties ) ];
			}
		}
		return $resultat;
	}

	private static function assert_format_error( string $code, \Closure $operation, string $message = '' ): void {
		try {
			$operation();
		} catch ( FormatError $e ) {
			self::assertSame( $code, $e->reason(), $message );
			self::assertSame( $code, $e->getMessage(), $message );
			self::assertNull( $e->getPrevious(), $message );
			return;
		}
		self::fail( 'FormatError « ' . $code . ' » attendue. ' . $message );
	}

	/**
	 * @return resource
	 */
	private static function flux_memoire() {
		$flux = fopen( 'php://memory', 'w+b' );
		self::assertIsResource( $flux );
		return $flux;
	}

	/**
	 * Flux php://memory qui contient $octets, relu depuis le début.
	 *
	 * @return resource
	 */
	private static function flux_de( string $octets ) {
		$flux = self::flux_memoire();
		self::assertSame( strlen( $octets ), fwrite( $flux, $octets ) );
		rewind( $flux );
		return $flux;
	}

	/**
	 * Flux de test qui rend $octets au plus $par_lecture octets à la fois.
	 *
	 * @return resource
	 */
	private static function flux_par_morceaux( string $octets, int $par_lecture ) {
		$classe                       = self::classe_du_flux();
		$classe::$octets              = $octets;
		$classe::$par_lecture         = $par_lecture;
		$classe::$position            = 0;
		$classe::$lectures            = 0;
		$classe::$plus_grande_lecture = 0;
		$flux                         = fopen( self::PROTOCOLE . '://segment', 'rb' );
		self::assertIsResource( $flux );
		return $flux;
	}

	/**
	 * Classe du flux de test, enregistrée une fois par processus sous self::PROTOCOLE.
	 */
	private static function classe_du_flux(): string {
		$modele = new class() {

			/** @var resource|null Contexte, posé par PHP. */
			public $context;

			public static string $octets = '';

			public static int $par_lecture = 5;

			public static int $position = 0;

			/** Appels à stream_read. */
			public static int $lectures = 0;

			/** Plus grand nombre d'octets rendu par un appel. */
			public static int $plus_grande_lecture = 0;

			public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
				return true;
			}

			public function stream_read( int $count ): string {
				++self::$lectures;
				$morceau         = substr( self::$octets, self::$position, min( $count, self::$par_lecture ) );
				self::$position += strlen( $morceau );

				self::$plus_grande_lecture = max( self::$plus_grande_lecture, strlen( $morceau ) );
				return $morceau;
			}

			public function stream_eof(): bool {
				return self::$position >= strlen( self::$octets );
			}
		};
		$classe = get_class( $modele );
		if ( ! in_array( self::PROTOCOLE, stream_get_wrappers(), true ) ) {
			self::assertTrue( stream_wrapper_register( self::PROTOCOLE, $classe ) );
		}
		return $classe;
	}

	/**
	 * Moteur natif ; test sauté sans l'extension.
	 */
	private static function natif(): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, static fn (): bool => true );
	}

	/**
	 * Moteur disponible : natif si l'extension est chargée, sinon compat. Pour les tests qui ne
	 * dépendent pas du moteur : sans l'extension, ils tournent quand même, et l'effacement y passe par
	 * la branche d'écrasement du lecteur (la seule employée alors).
	 */
	private static function moteur(): Sodium {
		return extension_loaded( 'sodium' ) ? self::natif() : self::compat();
	}

	private static function compat(): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true );
	}

	/**
	 * Les moteurs joués : natif si l'extension est chargée, compat toujours.
	 *
	 * @return array<string, Sodium>
	 */
	private static function moteurs(): array {
		$moteurs = [];
		if ( extension_loaded( 'sodium' ) ) {
			$moteurs['natif'] = self::natif();
		}
		$moteurs['compat'] = self::compat();
		return $moteurs;
	}
}
