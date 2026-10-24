<?php
/**
 * Écriture en flux d'un segment G2BK v1 (Backup\Format\SegmentWriter).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use G2RD\Connector\Backup\Crypto\Aead;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\Hpke;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Backup\Format\SegmentWriter;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : section `segments` du fichier de vecteurs (six cas), produite par le
 * générateur Python indépendant de la plateforme. Jamais une sortie du code testé. Le cas
 * `grand_bloc` ne porte pas ses octets : son clair est recalculé par det() (définition de la
 * section `deterministic_inputs`), et le segment écrit est comparé à son empreinte. Les sous-clés
 * du fichier (`block_key`, `commitment_key`) servent à ouvrir les blocs écrits et à chercher les
 * clés dans ce que l'écrivain garde.
 *
 * Tirage injecté (vecteurs) : une seule fermeture, partagée par l'écrivain et Hpke, rend la clé de
 * données, puis la clé éphémère (ordre des tirages du contrat, § 4 de docs/backup-format.md). Ses
 * valeurs sont dans une propriété statique du test, et non capturées par la fermeture : l'écrivain
 * garde la fermeture, et les tests qui parcourent tout ce qu'il garde n'y trouvent ainsi que ce
 * qu'il y a mis lui-même.
 *
 * Flux : php://memory pour les octets écrits ; un protocole de test (classe anonyme enregistrée une
 * fois par processus) pour un flux qui refuse d'écrire, qui accepte peu à chaque appel, ou qui jette
 * tout (test de mémoire). PHP découpe lui-même une écriture vers un flux utilisateur en appels de
 * 8 192 octets et reprend une écriture partielle (relevé du 24/10/2026, PHP 8.4.3) : seule une
 * écriture de 0 octet remonte à l'écrivain.
 *
 * Moteurs : comme HpkeTest. Natif si l'extension est chargée ; « compat » = la copie de
 * sodium_compat de vendor (1.24.0, celle du parc), forcée en PHP pur par
 * $disableFallbackForUnitTests. Seul le scellement de la clé de données dépend du moteur : les
 * blocs passent par OpenSSL dans les deux.
 */
final class SegmentWriterTest extends TestCase {

	/** Les cas de la section `segments` qui portent leurs octets (tous sauf `grand_bloc`). */
	private const CAS_AVEC_OCTETS = [
		'trois_blocs_dernier_partiel',
		'multiple_exact_bloc_final_vide',
		'segment_vide',
		'manifeste',
		'cle_de_site_version_7',
	];

	/** Protocole du flux de test. */
	private const PROTOCOLE = 'g2rdtestsegmentwriter';

	private const MIO = 1048576;

	/**
	 * Valeurs que rendra le tirage injecté, dans l'ordre.
	 *
	 * @var list<string>
	 */
	private static array $tirages = [];

	/**
	 * Longueurs demandées au tirage injecté.
	 *
	 * @var list<int>
	 */
	private static array $demandes = [];

	private bool $disable_fallback = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
		self::$tirages          = [];
		self::$demandes         = [];
	}

	protected function tearDown(): void {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		self::$tirages  = [];
		self::$demandes = [];
		parent::tearDown();
	}

	public function test_info_du_scellement_de_la_cle_de_donnees(): void {
		self::assertSame( 'g2bk-v1/dek', SegmentWriter::DATA_KEY_INFO );
	}

	// ---------------------------------------------------------------------------------------
	// Vecteurs.
	// ---------------------------------------------------------------------------------------

	public function test_chaque_segment_des_vecteurs_est_reproduit_a_l_octet(): void {
		self::assert_segments_reproduits( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_reproduit_en_compat(): void {
		self::assert_segments_reproduits( self::compat() );
		self::assert_grand_bloc_reproduit( self::compat() );
	}

	public function test_grand_bloc_reproduit_par_son_empreinte(): void {
		$v = self::segment( 'grand_bloc' );
		self::assertSame( 200000, strlen( $v['plaintext'] ) );
		self::assertSame( $v['plaintext_sha256'], hash( 'sha256', $v['plaintext'] ), 'Clair recalculé par det() différent de celui du générateur.' );
		self::assert_grand_bloc_reproduit( self::natif() );
	}

	public function test_le_decoupage_des_ecritures_ne_change_rien(): void {
		$sodium = self::natif();
		foreach ( self::CAS_AVEC_OCTETS as $nom ) {
			$v          = self::segment( $nom );
			$decoupages = [
				'd\'un coup'         => [ $v['plaintext'] ],
				'octet par octet'    => self::morceaux( $v['plaintext'], 1 ),
				'par 7'              => self::morceaux( $v['plaintext'], 7 ),
				'par 15'             => self::morceaux( $v['plaintext'], 15 ),
				'par 16 (un bloc)'   => self::morceaux( $v['plaintext'], 16 ),
				'par 17'             => self::morceaux( $v['plaintext'], 17 ),
				'avec des morceaux vides' => array_merge( [ '' ], self::morceaux( $v['plaintext'], 9 ), [ '', '' ] ),
			];
			foreach ( $decoupages as $decoupage => $morceaux ) {
				[ $octets, $resultat ] = self::ecrire_vecteur( $sodium, $v, $morceaux );
				self::assertSame( bin2hex( $v['segment'] ), bin2hex( $octets ), $nom . ', écrit ' . $decoupage );
				self::assertSame( self::resultat_attendu( $v ), self::resultat_lisible( $resultat ), $nom . ', écrit ' . $decoupage );
			}
		}

		// Grand bloc : morceaux qui chevauchent les blocs de 65 536 octets de toutes les façons.
		$v = self::segment( 'grand_bloc' );
		foreach ( [ 7, 65535, 65536, 65537, 150000 ] as $taille ) {
			[ $octets, $resultat ] = self::ecrire_vecteur( $sodium, $v, self::morceaux( $v['plaintext'], $taille ) );
			self::assertSame( $v['sha256'], hash( 'sha256', $octets ), 'grand_bloc, morceaux de ' . $taille );
			self::assertSame( self::resultat_attendu( $v ), self::resultat_lisible( $resultat ), 'grand_bloc, morceaux de ' . $taille );
		}
	}

	public function test_chaque_bloc_plein_est_emis_des_qu_il_est_complet(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$ecrivain = self::ecrivain_du_vecteur( self::moteur(), $v );
		$flux     = self::flux_memoire();
		self::commencer( $ecrivain, $flux, $v );
		self::assertSame( 148, self::taille( $flux ), 'En-tête écrit par begin().' );

		$ecrivain->write( substr( $v['plaintext'], 0, 15 ) );
		self::assertSame( 148, self::taille( $flux ), 'Quinze octets : aucun bloc plein, rien d\'émis.' );
		$ecrivain->write( substr( $v['plaintext'], 15, 1 ) );
		self::assertSame( 148 + 32, self::taille( $flux ), 'Seizième octet : le bloc 0 est émis aussitôt.' );
		$ecrivain->write( substr( $v['plaintext'], 16, 24 ) );
		self::assertSame( 148 + 64, self::taille( $flux ), 'Bloc 1 émis ; les 8 octets restants attendent.' );

		$ecrivain->finish();
		self::assertSame( bin2hex( $v['segment'] ), bin2hex( self::contenu( $flux ) ) );
	}

	public function test_multiple_exact_termine_par_un_bloc_final_vide(): void {
		$v = self::segment( 'multiple_exact_bloc_final_vide' );
		self::assertSame( 32, strlen( $v['plaintext'] ) );
		self::assertSame( 16, $v['block_size'] );

		[ $octets, $resultat ] = self::ecrire_vecteur( self::moteur(), $v, [ $v['plaintext'] ] );
		self::assertSame( 3, $resultat['blocks'], 'Deux blocs pleins, puis le bloc final vide.' );
		self::assertSame( 148 + 32 + 3 * 16, $resultat['bytes'] );
		self::assertSame( $resultat['bytes'], strlen( $octets ) );

		$empreinte = substr( $octets, 0, 148 );
		$empreinte = hash( 'sha256', $empreinte, true );
		$bloc_1    = substr( $octets, 148 + 32, 32 );
		$final     = substr( $octets, 148 + 64 );
		self::assertSame( 16, strlen( $final ), 'Bloc final vide : son étiquette seule.' );
		// Ouverture sous k_bloc du vecteur : le bloc final vide s'ouvre en '' (pas en null) avec le
		// drapeau de fin, et le bloc 1 n'est pas marqué final.
		self::assertSame( '', Aead::open( $v['block_key'], self::nonce( 2 ), self::aad( $empreinte, 2, true ), $final ) );
		self::assertNull( Aead::open( $v['block_key'], self::nonce( 2 ), self::aad( $empreinte, 2, false ), $final ) );
		self::assertSame( substr( $v['plaintext'], 16 ), Aead::open( $v['block_key'], self::nonce( 1 ), self::aad( $empreinte, 1, false ), $bloc_1 ) );
		self::assertNull( Aead::open( $v['block_key'], self::nonce( 1 ), self::aad( $empreinte, 1, true ), $bloc_1 ) );

		// Un seul bloc plein, écrit d'un coup ou octet par octet : bloc 0, puis le bloc final vide.
		foreach ( [ [ substr( $v['plaintext'], 0, 16 ) ], self::morceaux( substr( $v['plaintext'], 0, 16 ), 1 ) ] as $morceaux ) {
			$ecrivain = new SegmentWriter( new Hpke( self::moteur() ) );
			$flux     = self::flux_memoire();
			self::commencer( $ecrivain, $flux, $v );
			foreach ( $morceaux as $morceau ) {
				$ecrivain->write( $morceau );
			}
			$resultat = $ecrivain->finish();
			self::assertSame( 2, $resultat['blocks'] );
			self::assertSame( 148 + 16 + 32, $resultat['bytes'] );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Clés.
	// ---------------------------------------------------------------------------------------

	/**
	 * Réflexion sur ce que garde l'écrivain : k_bloc (propriété block_key) et le tampon, vides ou
	 * remis à zéro après finish() ; la clé de données n'est dans aucune propriété, ni pendant
	 * l'écriture ni après (elle ne vit que dans begin()). Limite : l'effacement de la variable locale
	 * de la clé de données, dans le finally de begin(), ne se voit pas depuis l'objet (une mutation
	 * qui le retire passe ce test) ; il est relu dans le code.
	 */
	public function test_la_cle_de_donnees_est_effacee_apres_finish(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$ecrivain = self::ecrivain_du_vecteur( self::moteur(), $v );
		$flux     = self::flux_memoire();
		self::assertSame( [], ( new \ReflectionClass( SegmentWriter::class ) )->getStaticProperties(), 'Aucune propriété statique : tout ce que garde l\'écrivain est dans l\'objet.' );

		self::commencer( $ecrivain, $flux, $v );
		$ecrivain->write( $v['plaintext'] );
		// Pendant l'écriture, k_bloc est gardée (elle chiffre les blocs) ; la clé de données, elle,
		// n'est plus nulle part : elle ne servait qu'au scellement, à l'engagement et à k_bloc.
		self::assertSame( bin2hex( $v['block_key'] ), bin2hex( self::propriete( $ecrivain, 'block_key' ) ), 'Le test doit voir k_bloc tant qu\'elle sert.' );
		$gardees = self::chaines_gardees( $ecrivain );
		self::assertTrue( self::contient( $gardees, $v['block_key'] ), 'Le parcours doit trouver k_bloc pendant l\'écriture.' );
		self::assertFalse( self::contient( $gardees, $v['dek'] ), 'Clé de données gardée après begin().' );
		self::assertFalse( self::contient( $gardees, $v['commitment_key'] ), 'k_engagement gardée après begin().' );

		$ecrivain->finish();
		foreach ( [ 'block_key', 'buffer' ] as $nom ) {
			self::assert_vide_ou_zeros( self::propriete( $ecrivain, $nom ), $nom );
		}
		$gardees = self::chaines_gardees( $ecrivain );
		foreach ( [ 'clé de données' => $v['dek'], 'k_bloc' => $v['block_key'], 'k_engagement' => $v['commitment_key'], 'clé éphémère' => $v['sk_e'] ] as $libelle => $cle ) {
			self::assertFalse( self::contient( $gardees, $cle ), $libelle . ' gardée après finish().' );
		}
		self::assertFalse( self::contient( $gardees, substr( $v['plaintext'], 32 ) ), 'Reste du clair gardé après finish().' );
	}

	/**
	 * § 9 de la spécification : le site ne détient jamais une clé capable de relire une sauvegarde
	 * passée. L'écrivain ne reçoit que la clé publique du site ; après finish(), rien de ce qu'il
	 * garde n'ouvre la clé de données scellée. Tirage réel : la clé de données n'est connue du test
	 * que par l'ouverture du scellement avec la clé secrète du site, qu'il détient seul.
	 */
	public function test_apres_ecriture_le_connecteur_ne_garde_aucune_cle_de_lecture(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$hpke     = new Hpke( self::moteur() );
		$ecrivain = new SegmentWriter( $hpke );
		$flux     = self::flux_memoire();
		self::commencer( $ecrivain, $flux, $v );
		$ecrivain->write( $v['plaintext'] );

		$en_tete = SegmentHeader::parse( self::contenu( $flux ) );
		fseek( $flux, 0, SEEK_END );
		$dek = $hpke->open( $v['sk_r'], $en_tete->enc(), SegmentWriter::DATA_KEY_INFO, $en_tete->prefix(), $en_tete->sealed_dek() );
		self::assertIsString( $dek, 'La clé secrète du site doit ouvrir la clé de données (contrôle du test).' );
		self::assertSame( 32, strlen( $dek ) );
		$k_bloc = SegmentHeader::block_key( $dek );
		self::assertTrue( self::contient( self::chaines_gardees( $ecrivain ), $k_bloc ), 'Le parcours doit trouver k_bloc pendant l\'écriture.' );

		$ecrivain->finish();
		$gardees = self::chaines_gardees( $ecrivain );
		self::assertFalse( self::contient( $gardees, $dek ), 'Clé de données gardée.' );
		self::assertFalse( self::contient( $gardees, $k_bloc ), 'k_bloc gardée.' );
		self::assertFalse( self::contient( $gardees, $v['sk_r'] ), 'Clé secrète du site gardée.' );

		// Une lecture avec tout ce que l'objet garde : chaque fenêtre de 32 octets de chaque chaîne,
		// essayée comme clé secrète X25519, échoue seal_open_failed. Exception : une fenêtre de
		// 32 zéros (k_bloc effacée par écrasement, sans l'extension sodium) ; sodium_compat refuse
		// lui-même une clé secrète nulle (« Zero secret key is not allowed »), d'où bad_public_key.
		$essais = 0;
		foreach ( $gardees as $chaine ) {
			for ( $debut = 0; $debut + 32 <= strlen( $chaine ); ++$debut ) {
				++$essais;
				$fenetre = substr( $chaine, $debut, 32 );
				$code    = self::ouverture( $hpke, $fenetre, $en_tete );
				if ( str_repeat( "\0", 32 ) === $fenetre ) {
					self::assertContains( $code, [ FormatError::SEAL_OPEN_FAILED, FormatError::BAD_PUBLIC_KEY ], 'fenêtre de zéros' );
				} else {
					self::assertSame( FormatError::SEAL_OPEN_FAILED, $code );
				}
			}
		}
		self::assertGreaterThan( 0, $essais, 'L\'écrivain garde au moins l\'empreinte de l\'en-tête : le test doit l\'essayer.' );
		self::assertSame( 'ok', self::ouverture( $hpke, $v['sk_r'], $en_tete ), 'Contrôle : la clé secrète du site ouvre.' );
	}

	public function test_deux_segments_ont_deux_cles_de_donnees(): void {
		$v       = self::segment( 'trois_blocs_dernier_partiel' );
		$hpke    = new Hpke( self::moteur() );
		$ecrits  = [];
		$en_tete = [];
		$dek     = [];
		for ( $i = 0; $i < 2; ++$i ) {
			$ecrivain = new SegmentWriter( $hpke );
			$flux     = self::flux_memoire();
			self::commencer( $ecrivain, $flux, $v );
			$ecrivain->write( $v['plaintext'] );
			$ecrivain->finish();
			$ecrits[ $i ]  = self::contenu( $flux );
			$en_tete[ $i ] = SegmentHeader::parse( $ecrits[ $i ] );
			$dek[ $i ]     = $hpke->open( $v['sk_r'], $en_tete[ $i ]->enc(), SegmentWriter::DATA_KEY_INFO, $en_tete[ $i ]->prefix(), $en_tete[ $i ]->sealed_dek() );
			self::assertIsString( $dek[ $i ] );
		}

		self::assertNotSame( $dek[0], $dek[1], 'Une clé de données neuve par segment.' );
		self::assertNotSame( $v['dek'], $dek[0] );
		self::assertNotSame( $en_tete[0]->enc(), $en_tete[1]->enc(), 'Une clé éphémère neuve par scellement.' );
		self::assertNotSame( $en_tete[0]->key_commitment(), $en_tete[1]->key_commitment() );
		self::assertSame( $en_tete[0]->prefix(), $en_tete[1]->prefix(), 'Mêmes champs : seul le tirage change.' );
		self::assertNotSame( substr( $ecrits[0], 148 ), substr( $ecrits[1], 148 ), 'Même clair, blocs différents.' );
		self::assertSame( strlen( $ecrits[0] ), strlen( $ecrits[1] ) );
	}

	// ---------------------------------------------------------------------------------------
	// Flux.
	// ---------------------------------------------------------------------------------------

	public function test_ecriture_partielle_du_flux_signalee(): void {
		$v      = self::segment( 'trois_blocs_dernier_partiel' );
		$sodium = self::moteur();

		// Flux qui n'accepte rien : l'en-tête ne s'écrit pas.
		$ecrivain = new SegmentWriter( new Hpke( $sodium ) );
		$flux     = self::flux_controle( 0, null, true );
		self::assert_format_error( FormatError::WRITE_FAILED, static fn () => self::commencer( $ecrivain, $flux, $v ), 'flux qui n\'accepte rien' );
		self::assert_inutilisable( $ecrivain, 'après l\'échec de l\'en-tête' );
		self::assert_vide_ou_zeros( self::propriete( $ecrivain, 'block_key' ), 'k_bloc après l\'échec de l\'en-tête' );

		// Flux qui accepte l'en-tête, puis plus rien : le premier bloc plein échoue.
		$ecrivain = new SegmentWriter( new Hpke( $sodium ) );
		$flux     = self::flux_controle( 148, null, true );
		self::commencer( $ecrivain, $flux, $v );
		$ecrivain->write( substr( $v['plaintext'], 0, 15 ) );
		self::assert_format_error( FormatError::WRITE_FAILED, static fn () => $ecrivain->write( substr( $v['plaintext'], 15 ) ), 'bloc 0 refusé' );
		self::assert_inutilisable( $ecrivain, 'après l\'échec d\'un bloc' );
		self::assert_vide_ou_zeros( self::propriete( $ecrivain, 'block_key' ), 'k_bloc après l\'échec d\'un bloc' );
		self::assert_vide_ou_zeros( self::propriete( $ecrivain, 'buffer' ), 'tampon après l\'échec d\'un bloc' );

		// Flux qui accepte l'en-tête et les deux blocs pleins, puis refuse le bloc final.
		$ecrivain = new SegmentWriter( new Hpke( $sodium ) );
		$flux     = self::flux_controle( 148 + 64, null, true );
		self::commencer( $ecrivain, $flux, $v );
		$ecrivain->write( $v['plaintext'] );
		self::assert_format_error( FormatError::WRITE_FAILED, static fn () => $ecrivain->finish(), 'bloc final refusé' );
		self::assert_inutilisable( $ecrivain, 'après l\'échec du bloc final' );
		self::assert_vide_ou_zeros( self::propriete( $ecrivain, 'block_key' ), 'k_bloc après l\'échec du bloc final' );

		// Écriture tronquée au milieu d'un bloc : refusée elle aussi.
		$ecrivain = new SegmentWriter( new Hpke( $sodium ) );
		$flux     = self::flux_controle( 148 + 10, null, true );
		self::commencer( $ecrivain, $flux, $v );
		self::assert_format_error( FormatError::WRITE_FAILED, static fn () => $ecrivain->write( $v['plaintext'] ), 'bloc 0 tronqué à 10 octets' );
		self::assertSame( 148 + 10, self::classe_du_flux()::$recus );

		// Flux fermé par l'appelant pendant l'écriture.
		$ecrivain = new SegmentWriter( new Hpke( $sodium ) );
		$flux     = self::flux_memoire();
		self::commencer( $ecrivain, $flux, $v );
		fclose( $flux );
		self::assert_format_error( FormatError::WRITE_FAILED, static fn () => $ecrivain->write( $v['plaintext'] ), 'flux fermé' );
		self::assert_inutilisable( $ecrivain, 'après un flux fermé' );

		// Flux qui accepte au plus 5 octets par appel (PHP reprend lui-même l'écriture), puis le même
		// avec un refus un appel sur deux : chaque fwrite() rend 5 octets écrits, et l'écrivain
		// reprend jusqu'au dernier. Rien n'est perdu ni écrit deux fois.
		foreach ( [ 'par 5 octets' => false, 'par 5 octets, avec hoquet' => true ] as $libelle => $hoquet ) {
			self::$tirages = [ $v['dek'], $v['sk_e'] ];
			$tirage        = self::tirage();
			$ecrivain      = new SegmentWriter( new Hpke( $sodium, $tirage ), $tirage );
			$flux          = self::flux_controle( null, 5, true, $hoquet );
			self::commencer( $ecrivain, $flux, $v );
			$ecrivain->write( $v['plaintext'] );
			self::assertSame( self::resultat_attendu( $v ), self::resultat_lisible( $ecrivain->finish() ), $libelle );
			self::assertSame( bin2hex( $v['segment'] ), bin2hex( self::classe_du_flux()::$octets ), $libelle );
			if ( $hoquet ) {
				self::assertGreaterThan( 10, self::classe_du_flux()::$hoquets, 'Le hoquet doit avoir rendu des écritures partielles.' );
			}
		}
	}

	public function test_mauvais_ordre_d_appel_refuse(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$ecrivain = self::ecrivain_du_vecteur( self::moteur(), $v );
		$flux     = self::flux_memoire();

		// Avant begin() : ni write() ni finish() ; l'écrivain reste utilisable.
		self::assert_logic_exception( static fn () => $ecrivain->write( 'abc' ), 'write() avant begin()', 'write() avant begin()' );
		self::assert_logic_exception( static fn () => $ecrivain->finish(), 'finish() avant begin()', 'finish() avant begin()' );
		self::assertSame( 0, self::taille( $flux ) );
		self::assertSame( [], self::$demandes, 'Rien de tiré avant begin().' );

		self::commencer( $ecrivain, $flux, $v );
		self::assert_logic_exception( static fn () => self::commencer( $ecrivain, $flux, $v ), 'begin() deux fois', 'ne s\'appelle qu\'une fois' );
		self::assertSame( 148, self::taille( $flux ), 'Un seul en-tête écrit.' );
		self::assertSame( [ 32, 32 ], self::$demandes, 'Un seul tirage de clé de données et de clé éphémère.' );

		$ecrivain->write( $v['plaintext'] );
		$ecrivain->finish();
		self::assertSame( bin2hex( $v['segment'] ), bin2hex( self::contenu( $flux ) ), 'Les appels refusés n\'ont rien changé.' );

		// Après finish() : plus rien.
		self::assert_inutilisable( $ecrivain, 'après finish()' );
		self::assertSame( strlen( $v['segment'] ), self::taille( $flux ) );
	}

	// ---------------------------------------------------------------------------------------
	// Refus de begin().
	// ---------------------------------------------------------------------------------------

	public function test_une_suite_autre_que_gcm_est_refusee(): void {
		$v = self::segment( 'trois_blocs_dernier_partiel' );
		foreach ( [ SegmentHeader::SUITE_SECRETSTREAM, 0, 3, 255 ] as $suite ) {
			self::$demandes = [];
			$tirage         = self::tirage();
			$ecrivain       = new SegmentWriter( new Hpke( self::moteur(), $tirage ), $tirage );
			$flux           = self::flux_memoire();
			self::assert_format_error(
				FormatError::UNSUPPORTED_SUITE,
				static fn () => $ecrivain->begin( $flux, $suite, $v['backup_id'], $v['segment_index'], $v['kind'], $v['site_key_version'], $v['block_size'], $v['pk_r'] ),
				'suite ' . $suite
			);
			self::assertSame( 0, self::taille( $flux ), 'suite ' . $suite . ' : rien d\'écrit' );
			self::assertSame( [], self::$demandes, 'suite ' . $suite . ' : rien de tiré' );
			self::assert_inutilisable( $ecrivain, 'après la suite ' . $suite );
		}
	}

	public function test_des_champs_hors_contrat_sont_une_erreur_de_programmation(): void {
		$v   = self::segment( 'trois_blocs_dernier_partiel' );
		$cas = [
			'nature 4'                 => [ 'kind', 4 ],
			'nature 0'                 => [ 'kind', 0 ],
			'identifiant de 15 octets' => [ 'backup_id', substr( $v['backup_id'], 0, 15 ) ],
			'identifiant en hex'       => [ 'backup_id', bin2hex( $v['backup_id'] ) ],
			'segment 4 096'            => [ 'segment_index', 4096 ],
			'segment −1'               => [ 'segment_index', -1 ],
			'version de clé 0'         => [ 'site_key_version', 0 ],
			'version de clé 2³²'       => [ 'site_key_version', 4294967296 ],
			'bloc de 15 octets'        => [ 'block_size', 15 ],
			'bloc de 64 Mio + 1'       => [ 'block_size', 67108865 ],
		];
		foreach ( $cas as $libelle => [ $champ, $valeur ] ) {
			self::$demandes = [];
			$tirage         = self::tirage();
			$ecrivain       = new SegmentWriter( new Hpke( self::moteur(), $tirage ), $tirage );
			$flux           = self::flux_memoire();
			$champs         = array_merge( $v, [ $champ => $valeur ] );
			try {
				$ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, $champs['backup_id'], $champs['segment_index'], $champs['kind'], $champs['site_key_version'], $champs['block_size'], $champs['pk_r'] );
				self::fail( $libelle . ' accepté.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertNull( $e->getPrevious(), $libelle );
			}
			self::assertSame( 0, self::taille( $flux ), $libelle . ' : rien d\'écrit' );
			self::assertSame( [], self::$demandes, $libelle . ' : rien de tiré' );
			self::assert_inutilisable( $ecrivain, 'après ' . $libelle );
		}

		// Sortie qui n'est pas un flux.
		$ecrivain = new SegmentWriter( new Hpke( self::moteur() ) );
		try {
			$ecrivain->begin( 'php://memory', SegmentHeader::SUITE_GCM, $v['backup_id'], $v['segment_index'], $v['kind'], $v['site_key_version'], $v['block_size'], $v['pk_r'] );
			self::fail( 'Sortie qui n\'est pas un flux acceptée.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assertStringContainsString( 'flux', $e->getMessage() );
		}
	}

	public function test_une_cle_publique_du_site_refusee_n_ecrit_rien(): void {
		$v = self::segment( 'trois_blocs_dernier_partiel' );
		foreach ( [ str_repeat( "\0", 32 ), substr( $v['pk_r'], 0, 31 ), $v['pk_r'] . "\0", '' ] as $cle ) {
			$ecrivain = new SegmentWriter( new Hpke( self::moteur() ) );
			$flux     = self::flux_memoire();
			self::assert_format_error(
				FormatError::BAD_PUBLIC_KEY,
				static fn () => $ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, $v['backup_id'], $v['segment_index'], $v['kind'], $v['site_key_version'], $v['block_size'], $cle ),
				'clé de ' . strlen( $cle ) . ' octets'
			);
			self::assertSame( 0, self::taille( $flux ) );
			self::assert_inutilisable( $ecrivain, 'après une clé refusée' );
			self::assert_vide_ou_zeros( self::propriete( $ecrivain, 'block_key' ), 'k_bloc après une clé refusée' );
		}
	}

	public function test_une_source_d_alea_en_panne_rend_crypto_unavailable(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$panne    = static function (): string {
			// Ce que random_bytes() lève sans source d'aléa (\Random\RandomException en PHP 8.2+).
			throw new \Exception( 'Could not gather sufficient random data' );
		};
		$ecrivain = new SegmentWriter( new Hpke( self::moteur() ), $panne );
		$flux     = self::flux_memoire();
		self::assert_format_error( FormatError::CRYPTO_UNAVAILABLE, static fn () => self::commencer( $ecrivain, $flux, $v ) );
		self::assertSame( 0, self::taille( $flux ) );
		self::assert_inutilisable( $ecrivain, 'après une panne de l\'aléa' );
	}

	public function test_un_tirage_de_mauvaise_longueur_est_une_erreur_de_programmation(): void {
		$v = self::segment( 'trois_blocs_dernier_partiel' );
		foreach ( [ 0, 31, 33 ] as $longueur ) {
			$ecrivain = new SegmentWriter( new Hpke( self::moteur() ), static fn ( int $n ): string => str_repeat( "\x01", $longueur ) );
			$flux     = self::flux_memoire();
			try {
				self::commencer( $ecrivain, $flux, $v );
				self::fail( 'Tirage de ' . $longueur . ' octets accepté.' );
			} catch ( \UnexpectedValueException $e ) {
				self::assertStringContainsString( '32 octets', $e->getMessage() );
				self::assertNull( $e->getPrevious() );
			}
			self::assertSame( 0, self::taille( $flux ) );
			self::assert_inutilisable( $ecrivain, 'après un tirage de ' . $longueur . ' octets' );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Mémoire.
	// ---------------------------------------------------------------------------------------

	/**
	 * 64 Mio de clair écrits par morceaux de 1 Mio, blocs de 8 Mio, vers un flux qui jette tout : la
	 * mémoire ne dépend que de la taille d'un bloc (tampon, bloc chiffré, morceau reçu), jamais de
	 * celle du segment. Processus à part : le pic de mémoire y part de zéro, sans ceux des tests
	 * précédents (memory_reset_peak_usage() n'existe qu'à partir de PHP 8.2). Le débit mesuré est
	 * dans le message de l'assertion de mémoire.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_memoire_bornee(): void {
		$v        = self::segment( 'trois_blocs_dernier_partiel' );
		$morceau  = random_bytes( self::MIO );
		$flux     = self::flux_controle( null, null, false );
		$ecrivain = new SegmentWriter( new Hpke( self::moteur() ) );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$depart = memory_get_usage();
		$debut  = hrtime( true );

		$ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, random_bytes( 16 ), 0, SegmentHeader::KIND_FILES, 1, 8 * self::MIO, $v['pk_r'] );
		for ( $i = 0; $i < 64; ++$i ) {
			$ecrivain->write( $morceau );
		}
		$resultat = $ecrivain->finish();

		$secondes = ( hrtime( true ) - $debut ) / 1e9;
		$hausse   = memory_get_peak_usage() - $depart;
		self::assertSame( 9, $resultat['blocks'], 'Huit blocs pleins, puis le bloc final vide.' );
		self::assertSame( 148 + 64 * self::MIO + 9 * 16, $resultat['bytes'] );
		self::assertSame( $resultat['bytes'], self::classe_du_flux()::$recus );
		self::assertLessThan(
			40 * self::MIO,
			$hausse,
			sprintf( 'Mémoire : hausse de %.1f Mio pour 64 Mio écrits (débit %.0f Mio/s).', $hausse / self::MIO, 64 / max( $secondes, 1e-9 ) )
		);
	}

	// ---------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------

	private static function assert_segments_reproduits( Sodium $sodium ): void {
		foreach ( self::CAS_AVEC_OCTETS as $nom ) {
			$v                     = self::segment( $nom );
			[ $octets, $resultat ] = self::ecrire_vecteur( $sodium, $v, [ $v['plaintext'] ] );
			self::assertSame( bin2hex( $v['header'] ), bin2hex( substr( $octets, 0, 148 ) ), $nom . ' : en-tête (comparer enc, sealed_dek, puis key_commitment)' );
			self::assertSame( bin2hex( $v['segment'] ), bin2hex( $octets ), $nom . ' : segment (en-tête juste : comparer block_key et header_hash)' );
			self::assertSame( self::resultat_attendu( $v ), self::resultat_lisible( $resultat ), $nom . ' : résultat de finish()' );
			self::assertSame( [ 'bytes', 'blocks', 'sha256' ], array_keys( $resultat ), $nom );
			self::assertSame( 32, strlen( $resultat['sha256'] ), $nom . ' : empreinte brute de 32 octets' );
		}
	}

	private static function assert_grand_bloc_reproduit( Sodium $sodium ): void {
		$v                     = self::segment( 'grand_bloc' );
		[ $octets, $resultat ] = self::ecrire_vecteur( $sodium, $v, [ $v['plaintext'] ] );
		self::assertSame( $v['bytes'], strlen( $octets ) );
		self::assertSame( $v['sha256'], hash( 'sha256', $octets ), 'grand_bloc : empreinte du segment écrit' );
		self::assertSame( self::resultat_attendu( $v ), self::resultat_lisible( $resultat ), 'grand_bloc : résultat de finish()' );
		// Les sous-clés du fichier ouvrent le bloc final, de 200 000 − 3 × 65 536 = 3 392 octets.
		$empreinte = hash( 'sha256', substr( $octets, 0, 148 ), true );
		$final     = substr( $octets, 148 + 3 * ( 65536 + 16 ) );
		self::assertSame( substr( $v['plaintext'], 3 * 65536 ), Aead::open( $v['block_key'], self::nonce( 3 ), self::aad( $empreinte, 3, true ), $final ) );
	}

	/**
	 * Écrit le segment du vecteur $v, clair découpé en $morceaux, avec son tirage (clé de données,
	 * puis clé éphémère), et contrôle les tirages demandés.
	 *
	 * @param array<string, mixed> $v
	 * @param list<string>         $morceaux
	 *
	 * @return array{0: string, 1: array{bytes: int, blocks: int, sha256: string}}
	 */
	private static function ecrire_vecteur( Sodium $sodium, array $v, array $morceaux ): array {
		$ecrivain = self::ecrivain_du_vecteur( $sodium, $v );
		$flux     = self::flux_memoire();
		self::commencer( $ecrivain, $flux, $v );
		foreach ( $morceaux as $morceau ) {
			$ecrivain->write( $morceau );
		}
		$resultat = $ecrivain->finish();
		self::assertSame( [ 32, 32 ], self::$demandes, 'Deux tirages de 32 octets : la clé de données, puis la clé éphémère.' );
		self::assertSame( [], self::$tirages, 'Les deux valeurs du vecteur ont servi.' );
		return [ self::contenu( $flux ), $resultat ];
	}

	/**
	 * Écrivain dont le tirage (partagé avec Hpke) rend la clé de données, puis la clé éphémère du
	 * vecteur.
	 *
	 * @param array<string, mixed> $v
	 */
	private static function ecrivain_du_vecteur( Sodium $sodium, array $v ): SegmentWriter {
		self::$tirages  = [ $v['dek'], $v['sk_e'] ];
		self::$demandes = [];
		$tirage         = self::tirage();
		return new SegmentWriter( new Hpke( $sodium, $tirage ), $tirage );
	}

	/**
	 * @param resource             $flux
	 * @param array<string, mixed> $v
	 */
	private static function commencer( SegmentWriter $ecrivain, $flux, array $v ): void {
		$ecrivain->begin( $flux, SegmentHeader::SUITE_GCM, $v['backup_id'], $v['segment_index'], $v['kind'], $v['site_key_version'], $v['block_size'], $v['pk_r'] );
	}

	/**
	 * Tirage injecté : rend les valeurs de self::$tirages dans l'ordre et note chaque longueur
	 * demandée. Ne capture rien (voir le docblock de la classe). Épuisé, il rend '' : la longueur
	 * fausse est alors refusée par l'écrivain ou par Hpke, et l'assertion sur les demandes le dit.
	 */
	private static function tirage(): \Closure {
		return static function ( int $longueur ): string {
			self::$demandes[] = $longueur;
			return (string) array_shift( self::$tirages );
		};
	}

	/**
	 * Un cas de la section `segments`, binaires en octets ; clair de `grand_bloc` recalculé par det().
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
		$v['sha256'] = $cas['sha256'];
		if ( isset( $cas['plaintext_det'] ) ) {
			self::assertIsArray( $cas['plaintext_det'] );
			self::assertIsString( $cas['plaintext_det']['label'] ?? null );
			self::assertIsInt( $cas['plaintext_det']['length'] ?? null );
			self::assertIsString( $cas['plaintext_sha256'] ?? null );
			$v['plaintext']        = self::det( $cas['plaintext_det']['label'], $cas['plaintext_det']['length'] );
			$v['plaintext_sha256'] = $cas['plaintext_sha256'];
		} else {
			foreach ( [ 'plaintext', 'header', 'segment' ] as $champ ) {
				$v[ $champ ] = VectorsFixture::bin( $cas[ $champ ] ?? null );
			}
		}
		return $v;
	}

	/**
	 * Entrée déterministe du fichier de vecteurs : det( label, n ) = n premiers octets de
	 * SHA-256( « g2bk-vecteurs/ » ‖ label ‖ 00 00 00 00 ) ‖ SHA-256( … ‖ 00 00 00 01 ) ‖ …
	 * (compteur sur 4 octets gros-boutistes).
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
	 * @param array<string, mixed> $v
	 *
	 * @return array{bytes: mixed, blocks: mixed, sha256: mixed}
	 */
	private static function resultat_attendu( array $v ): array {
		return [ 'bytes' => $v['bytes'], 'blocks' => $v['blocks'], 'sha256' => $v['sha256'] ];
	}

	/**
	 * @param array{bytes: int, blocks: int, sha256: string} $resultat
	 *
	 * @return array{bytes: int, blocks: int, sha256: string}
	 */
	private static function resultat_lisible( array $resultat ): array {
		$resultat['sha256'] = bin2hex( $resultat['sha256'] );
		return $resultat;
	}

	/**
	 * @return list<string>
	 */
	private static function morceaux( string $octets, int $taille ): array {
		$morceaux = [];
		for ( $debut = 0; $debut < strlen( $octets ); $debut += $taille ) {
			$morceaux[] = substr( $octets, $debut, $taille );
		}
		return $morceaux;
	}

	private static function nonce( int $bloc ): string {
		return "\0\0\0\0" . pack( 'J', $bloc );
	}

	private static function aad( string $empreinte, int $bloc, bool $final ): string {
		return $empreinte . pack( 'J', $bloc ) . ( $final ? "\x01" : "\x00" );
	}

	/**
	 * Ce que rendrait le lecteur d'un segment à l'ouverture de la clé de données (tâche 19) :
	 * seal_open_failed si le scellement ne s'ouvre pas ; le code d'un refus de la clé secrète par le
	 * moteur (bad_public_key) ; 'ok' si la clé de données sort.
	 */
	private static function ouverture( Hpke $hpke, string $cle_secrete, SegmentHeader $en_tete ): string {
		try {
			$dek = $hpke->open( $cle_secrete, $en_tete->enc(), SegmentWriter::DATA_KEY_INFO, $en_tete->prefix(), $en_tete->sealed_dek() );
		} catch ( FormatError $e ) {
			self::assertSame( FormatError::BAD_PUBLIC_KEY, $e->reason(), 'Seul refus attendu du moteur : la clé secrète.' );
			return $e->reason();
		}
		return null === $dek ? FormatError::SEAL_OPEN_FAILED : 'ok';
	}

	/**
	 * Toutes les chaînes que garde l'objet : ses propriétés, celles des objets qu'il tient (Hpke,
	 * Sodium…), les éléments et clés des tableaux, les variables des fermetures (use et static) et
	 * l'objet auquel elles sont liées.
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

	private static function propriete( SegmentWriter $ecrivain, string $nom ): mixed {
		return ( new \ReflectionProperty( SegmentWriter::class, $nom ) )->getValue( $ecrivain );
	}

	private static function assert_vide_ou_zeros( mixed $valeur, string $libelle ): void {
		self::assertIsString( $valeur, $libelle );
		self::assertSame( str_repeat( "\0", strlen( $valeur ) ), $valeur, $libelle . ' : ni vide ni remise à zéro' );
	}

	/**
	 * Après finish() ou après un échec : begin(), write() et finish() refusés.
	 */
	private static function assert_inutilisable( SegmentWriter $ecrivain, string $libelle ): void {
		$v    = self::segment( 'trois_blocs_dernier_partiel' );
		$flux = self::flux_memoire();
		self::assert_logic_exception( static fn () => $ecrivain->write( 'abc' ), 'write() ' . $libelle, 'terminé ou en échec' );
		self::assert_logic_exception( static fn () => $ecrivain->write( '' ), 'write( \'\' ) ' . $libelle, 'terminé ou en échec' );
		self::assert_logic_exception( static fn () => $ecrivain->finish(), 'finish() ' . $libelle, 'terminé ou en échec' );
		self::assert_logic_exception( static fn () => self::commencer( $ecrivain, $flux, $v ), 'begin() ' . $libelle, 'ne s\'appelle qu\'une fois' );
		self::assertSame( 0, self::taille( $flux ), 'begin() refusé ' . $libelle . ' : rien d\'écrit' );
	}

	private static function assert_logic_exception( \Closure $operation, string $libelle, string $fragment ): void {
		try {
			$operation();
		} catch ( \LogicException $e ) {
			self::assertNotInstanceOf( \InvalidArgumentException::class, $e, $libelle );
			self::assertStringContainsString( $fragment, $e->getMessage(), $libelle );
			self::assertStringContainsString( 'Action :', $e->getMessage(), $libelle );
			self::assertNull( $e->getPrevious(), $libelle );
			return;
		}
		self::fail( 'LogicException attendue : ' . $libelle );
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
	 * @param resource $flux
	 */
	private static function contenu( $flux ): string {
		rewind( $flux );
		$octets = stream_get_contents( $flux );
		self::assertIsString( $octets );
		return $octets;
	}

	/**
	 * @param resource $flux
	 */
	private static function taille( $flux ): int {
		$etat = fstat( $flux );
		self::assertIsArray( $etat );
		return $etat['size'];
	}

	/**
	 * Flux de test : accepte au plus $budget octets au total (puis rend 0 à chaque appel, jusqu'au
	 * garde-fou de 1 000 refus) et au plus $par_appel octets par appel ; garde ce qu'il accepte, ou le
	 * compte seulement.
	 *
	 * @param int|null $budget    Octets acceptés au total ; null : sans limite.
	 * @param int|null $par_appel Octets acceptés par appel à stream_write ; null : tout ce qui vient.
	 * @param bool     $garde     Garder les octets acceptés (sinon seulement les compter).
	 * @param bool     $hoquet    Refuser le rappel qui suit une acceptation partielle (fwrite() rend alors
	 *                            des écritures partielles).
	 *
	 * @return resource
	 */
	private static function flux_controle( ?int $budget, ?int $par_appel, bool $garde, bool $hoquet = false ) {
		$classe             = self::classe_du_flux();
		$classe::$budget    = $budget;
		$classe::$par_appel = $par_appel;
		$classe::$garde     = $garde;
		$classe::$hoquet    = $hoquet;
		$classe::$octets    = '';
		$classe::$recus     = 0;
		$classe::$hoquets   = 0;
		$classe::$refus     = 0;

		$classe::$refuser_le_suivant = false;
		$flux               = fopen( self::PROTOCOLE . '://segment', 'wb' );
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

			public static ?int $budget = null;

			public static ?int $par_appel = null;

			public static bool $garde = true;

			public static string $octets = '';

			public static int $recus = 0;

			public static bool $hoquet = false;

			/** Appels refusés par le hoquet. */
			public static int $hoquets = 0;

			public static bool $refuser_le_suivant = false;

			/** Refus de suite. */
			public static int $refus = 0;

			public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
				return true;
			}

			public function stream_write( string $data ): int {
				$acceptes = strlen( $data );
				if ( null !== self::$par_appel ) {
					$acceptes = min( $acceptes, self::$par_appel );
				}
				if ( null !== self::$budget ) {
					$acceptes = max( 0, min( $acceptes, self::$budget - self::$recus ) );
				}
				// Hoquet : après une acceptation partielle, PHP rappelle aussitôt pour le reste, dans le
				// même fwrite() ; ce rappel est refusé. PHP arrête alors sa propre reprise, et fwrite()
				// rend une écriture partielle, que l'écrivain doit reprendre lui-même. Le premier appel
				// d'un fwrite() n'est jamais refusé ainsi (ce serait un vrai refus : 0 octet écrit).
				if ( self::$hoquet ) {
					if ( self::$refuser_le_suivant ) {
						self::$refuser_le_suivant = false;
						++self::$hoquets;
						return 0;
					}
					self::$refuser_le_suivant = $acceptes > 0 && $acceptes < strlen( $data );
				}
				// Garde-fou du test : après 1 000 refus de suite, le flux accepte tout. Un écrivain qui ne
				// verrait pas l'écriture de 0 octet finit alors son segment et le test échoue, au lieu de
				// boucler sans fin.
				self::$refus = 0 === $acceptes ? self::$refus + 1 : 0;
				if ( self::$refus > 1000 ) {
					$acceptes = strlen( $data );
				}
				if ( self::$garde ) {
					self::$octets .= substr( $data, 0, $acceptes );
				}
				self::$recus += $acceptes;
				return $acceptes;
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
	 * la branche d'écrasement de l'écrivain (la seule employée alors).
	 */
	private static function moteur(): Sodium {
		return extension_loaded( 'sodium' ) ? self::natif() : self::compat();
	}

	private static function compat(): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true );
	}
}
