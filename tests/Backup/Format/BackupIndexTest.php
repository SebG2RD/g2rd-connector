<?php
/**
 * Index signé d'une sauvegarde G2BK v1 (Backup\Format\BackupIndex).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use Brain\Monkey;
use G2RD\Connector\Backup\Crypto\Sodium;
use G2RD\Connector\Backup\Format\BackupIndex;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : Brain Monkey ne sert qu'à un test, pour sa doublure de Sodium (classe finale) par
 * Patchwork, dans un processus à part (précédents : SelfTestTest, HpkeTest).
 *
 * Valeurs attendues : sections `index` (trois cas), `index_tampering` (huit cas) et
 * `index_malformed` (vingt-trois cas) du fichier de vecteurs, produites par le générateur Python
 * indépendant de la plateforme, jamais par le code testé. Les index construits ici (bornes,
 * 4 096 segments) sont encodés par json_encode() avec les options du contrat, et signés avec la
 * graine du vecteur `index_complet`.
 *
 * Moteurs : comme SodiumTest. Natif si l'extension est chargée ; « compat » = la copie de
 * sodium_compat de vendor (1.24.0, celle du parc), forcée en PHP pur par
 * $disableFallbackForUnitTests. Depuis la relecture de la tâche 13, le contrôle n° 5 de la
 * vérification (sous-groupe principal) passe, dans les deux moteurs, par les classes Core de
 * sodium_compat : tout cas qui vérifie une signature porte donc le groupe sodium-compat (rejeu
 * contre une autre copie : commande du docblock de SodiumCompatCopieTest), sauf
 * test_une_signature_fausse_ne_se_verifie_pas_avec_la_cle_du_site. Ce test tourne dans un
 * processus à part, que PHPUnit 10.5 lance sans le `-d auto_prepend_file` du rejeu
 * (AbstractPhpProcess::getCommand() ne transmet que les réglages de Xdebug et de PCOV ; sonde du
 * 25/10 : chargement anticipé vide dans l'enfant, variable G2RD_SODIUM_COMPAT_COPIE présente).
 * L'enfant charge donc toujours la copie de vendor : dans le groupe, il passerait au rejeu sans
 * avoir essayé la copie rejouée. Son objet (la bibliothèque n'enchaîne pas sign() et verify())
 * ne dépend pas de la copie ; le refus d'une signature altérée par chaque copie est rejoué par
 * test_les_index_alteres_ne_se_verifient_pas_en_natif et …_en_compat.
 */
final class BackupIndexTest extends TestCase {

	/** Domaine de la signature d'un index (docs/backup-format.md § 7.5). */
	private const INDEX_DOMAIN = "g2bk-v1/index\x00";

	/** Options d'encodage du contrat (§ 7.1). */
	private const JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

	/** Les huit cas d'`index_tampering`, dans l'ordre du fichier (relecture de la tâche 13). */
	private const ALTERATIONS = [
		'octet_modifie',
		'signature_d_un_autre_index',
		'autre_cle_publique',
		'signature_s_non_canonique',
		'cle_publique_d_ordre_faible',
		'cle_publique_non_canonique',
		'cle_publique_d_ordre_mixte',
		'cle_publique_d_ordre_2l',
	];

	/**
	 * Les vingt-trois cas d'`index_malformed`, dans l'ordre du fichier : `cle_en_double`, les deux
	 * cas du périmètre (relecture du lead dev, tour 6) et les onze cas de bornes (relecture de la
	 * tâche 3, d'`entier_negatif` à `parent_egal_a_soi`) compris.
	 */
	private const MAL_FORMES = [
		'cle_manquante',
		'cle_en_trop',
		'segment_non_contigu',
		'manifeste_hors_bornes',
		'deux_manifestes',
		'incremental_sans_parent',
		'complet_avec_parent',
		'hex_majuscule',
		'date_sans_z',
		'cle_en_double',
		'ni_base_ni_fichiers',
		'incremental_sans_fichiers',
		'entier_negatif',
		'entier_negatif_hors_precision_js',
		'entier_hors_precision_js',
		'booleen_au_lieu_d_un_entier',
		'blocs_nuls',
		'octets_sous_le_minimum',
		'version_de_cle_hors_bornes',
		'date_impossible',
		'seconde_intercalaire',
		'annee_avant_1970',
		'parent_egal_a_soi',
	];

	private bool $disable_fallback = false;

	private bool $fast_mult = false;

	/** Brain Monkey (et donc Patchwork) chargé par ce test : à refermer dans tearDown(). */
	private bool $monkey = false;

	protected function setUp(): void {
		parent::setUp();
		$this->disable_fallback = \ParagonIE_Sodium_Compat::$disableFallbackForUnitTests;
		$this->fast_mult        = \ParagonIE_Sodium_Compat::$fastMult;
	}

	protected function tearDown(): void {
		if ( $this->monkey ) {
			Monkey\tearDown();
		}
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = $this->disable_fallback;
		\ParagonIE_Sodium_Compat::$fastMult                    = $this->fast_mult;
		parent::tearDown();
	}

	public function test_constantes_du_contrat(): void {
		self::assertSame( self::INDEX_DOMAIN, BackupIndex::SIGNATURE_DOMAIN );
		self::assertSame( 1048576, BackupIndex::MAX_BYTES );
		self::assertSame( 4096, BackupIndex::MAX_SEGMENTS );
		self::assertSame( 1073741824, BackupIndex::MAX_SEGMENT_BYTES );
	}

	public function test_le_fichier_porte_les_cas_attendus(): void {
		self::assertSame( [ 'index_complet', 'index_incremental', 'index_base_seule' ], array_column( VectorsFixture::section( 'index' ), 'name' ) );
		self::assertSame( self::ALTERATIONS, array_column( VectorsFixture::section( 'index_tampering' ), 'name' ) );
		self::assertSame( self::MAL_FORMES, array_column( VectorsFixture::section( 'index_malformed' ), 'name' ) );
	}

	// ---------------------------------------------------------------------------------------
	// Écriture et lecture des vecteurs.
	// ---------------------------------------------------------------------------------------

	public function test_les_index_des_vecteurs_sont_reproduits_a_l_octet(): void {
		foreach ( VectorsFixture::section( 'index' ) as $vecteur ) {
			$nom    = (string) $vecteur['name'];
			$octets = self::octets( $vecteur );
			$champs = self::decode( $octets );

			// Encodage depuis les champs, puis lecture : mêmes octets, mêmes champs.
			self::assertSame( $octets, BackupIndex::encode( $champs ), $nom );
			self::assertSame( $champs, BackupIndex::parse( $octets ), $nom );
			self::assertSame( $octets, BackupIndex::encode( BackupIndex::parse( $octets ) ), $nom );

			// L'encodeur écrit dans l'ordre du contrat, quel que soit l'ordre des champs reçus.
			$desordre             = array_reverse( $champs, true );
			$desordre['segments'] = array_map(
				static fn ( array $segment ): array => array_reverse( $segment, true ),
				$champs['segments']
			);
			self::assertNotSame( array_keys( $champs ), array_keys( $desordre ) );
			self::assertSame( $octets, BackupIndex::encode( $desordre ), $nom . ' (champs dans le désordre)' );
		}
	}

	#[Group( 'sodium-compat' )]
	public function test_les_signatures_des_vecteurs_sont_reproduites_en_natif(): void {
		self::assert_signatures_reproduites( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_signatures_des_vecteurs_sont_reproduites_en_compat(): void {
		self::assert_signatures_reproduites( self::compat() );
	}

	// ---------------------------------------------------------------------------------------
	// Ce qui ne se vérifie pas.
	// ---------------------------------------------------------------------------------------

	#[Group( 'sodium-compat' )]
	public function test_les_index_alteres_ne_se_verifient_pas_en_natif(): void {
		self::assert_alterations_refusees( self::natif() );
	}

	#[Group( 'sodium-compat' )]
	public function test_les_index_alteres_ne_se_verifient_pas_en_compat(): void {
		self::assert_alterations_refusees( self::compat() );
	}

	#[Group( 'sodium-compat' )]
	public function test_verify_ne_leve_jamais_sur_des_octets_quelconques(): void {
		$vecteur   = VectorsFixture::named( 'index', 'index_complet' );
		$octets    = self::octets( $vecteur );
		$signature = VectorsFixture::bin( $vecteur['signature'] );
		$cle       = VectorsFixture::bin( $vecteur['public_key'] );

		foreach ( self::moteurs() as $moteur => $sodium ) {
			$essais = [
				'signature vide'          => [ $octets, '', $cle ],
				'signature de 63 octets'  => [ $octets, substr( $signature, 0, 63 ), $cle ],
				'signature de 65 octets'  => [ $octets, $signature . "\0", $cle ],
				'signature en hexadécimal' => [ $octets, bin2hex( $signature ), $cle ],
				'clé vide'                => [ $octets, $signature, '' ],
				'clé de 31 octets'        => [ $octets, $signature, substr( $cle, 0, 31 ) ],
				'clé en hexadécimal'      => [ $octets, $signature, bin2hex( $cle ) ],
				'clé hors de la courbe'   => [ $octets, $signature, (string) hex2bin( '0200000000000000000000000000000000000000000000000000000000000000' ) ],
				'index vide'              => [ '', $signature, $cle ],
				'octets quelconques'      => [ random_bytes( 300 ), random_bytes( 64 ), random_bytes( 32 ) ],
			];
			foreach ( $essais as $libelle => [ $index, $sig, $public ] ) {
				self::assertFalse( BackupIndex::verify( $index, $sig, $public, $sodium ), $moteur . ' : ' . $libelle );
			}
			self::assertTrue( BackupIndex::verify( $octets, $signature, $cle, $sodium ), $moteur . ' : témoin' );
		}
	}

	public function test_verify_sans_moteur_leve_crypto_unavailable(): void {
		// Une panne n'est pas une signature invalide (même règle que Sodium::ed25519_verify()).
		$vecteur = VectorsFixture::named( 'index', 'index_complet' );
		$sans    = new Sodium( static fn (): bool => false, static fn (): bool => false );

		self::assert_format_error(
			FormatError::CRYPTO_UNAVAILABLE,
			static fn () => BackupIndex::verify( self::octets( $vecteur ), VectorsFixture::bin( $vecteur['signature'] ), VectorsFixture::bin( $vecteur['public_key'] ), $sans )
		);
	}

	/**
	 * La taille n'est pas contrôlée par verify() : c'est l'appelant qui refuse d'abord un index de
	 * plus de MAX_BYTES octets (§ 7.5), puis parse(), qui refait ce contrôle en premier.
	 */
	#[Group( 'sodium-compat' )]
	public function test_verify_ne_controle_pas_la_taille_l_appelant_le_fait(): void {
		$sodium = self::moteur();
		$paire  = self::paire_du_site( $sodium );
		$grand  = self::octets( VectorsFixture::named( 'index', 'index_complet' ) );
		$grand .= str_repeat( ' ', BackupIndex::MAX_BYTES + 1 - strlen( $grand ) );

		$signature = BackupIndex::sign( $grand, $paire['secret'], $sodium );

		self::assertTrue( BackupIndex::verify( $grand, $signature, $paire['public'], $sodium ) );
		self::assert_format_error( FormatError::INDEX_TOO_LARGE, static fn () => BackupIndex::parse( $grand ) );
	}

	// ---------------------------------------------------------------------------------------
	// Lecture stricte.
	// ---------------------------------------------------------------------------------------

	/**
	 * Vingt-trois index mal formés, signés : la signature est valide, la lecture refuse.
	 *
	 * @param array<string, mixed> $cas Cas d'`index_malformed`.
	 */
	#[DataProvider( 'index_mal_formes' )]
	#[Group( 'sodium-compat' )]
	public function test_les_index_mal_formes_sont_refuses( array $cas ): void {
		self::assertSame( 'index_malformed', $cas['expected_error'] );
		$octets = self::octets( $cas );

		self::assertTrue( BackupIndex::verify( $octets, VectorsFixture::bin( $cas['signature'] ), VectorsFixture::bin( $cas['public_key'] ), self::moteur() ), 'La signature du cas doit être valide : seule la lecture refuse.' );
		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $octets ) );
	}

	/**
	 * Index valide réécrit autrement : chaque variante décode en champs valides (ou en une valeur
	 * proche) et doit être refusée, le réencodage exact ne redonnant pas les octets reçus.
	 */
	#[DataProvider( 'variantes_de_reencodage' )]
	public function test_le_reencodage_doit_redonner_les_octets_lus( string $cherche, string $remplace ): void {
		$valide = self::octets( VectorsFixture::named( 'index', 'index_complet' ) );
		BackupIndex::parse( $valide );

		$pos = strpos( $valide, $cherche );
		self::assertNotFalse( $pos, 'Motif absent de l\'index : ' . $cherche );
		$variante = substr_replace( $valide, $remplace, $pos, strlen( $cherche ) );
		self::assertNotSame( $valide, $variante, 'Variante identique à l\'index valide : elle ne prouverait rien.' );
		self::assertIsArray( json_decode( $variante, true ), 'La variante doit rester du JSON décodable.' );

		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $variante ) );
	}

	/**
	 * Liste des segments écrite en objet (`{"0":{…},"1":{…}}`) : json_decode() en fait un tableau
	 * PHP aux clés 0, 1, 2… (une liste), les champs sont valides, et seul le réencodage refuse.
	 */
	public function test_segments_ecrits_en_objet_refuses(): void {
		$champs             = self::decode( self::octets( VectorsFixture::named( 'index', 'index_complet' ) ) );
		$champs['segments'] = (object) $champs['segments'];
		$octets             = json_encode( $champs, self::JSON_OPTIONS );

		self::assertStringContainsString( '"segments":{"0":{"index":0,', $octets );
		self::assertSame( self::decode( self::octets( VectorsFixture::named( 'index', 'index_complet' ) ) ), self::decode( $octets ) );
		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $octets ) );

		// À l'écriture, un objet n'est pas une liste de segments.
		$this->expectException( \InvalidArgumentException::class );
		BackupIndex::encode( $champs );
	}

	public function test_encode_refuse_ce_que_parse_refuserait(): void {
		$refuses = 0;
		foreach ( VectorsFixture::section( 'index_malformed' ) as $cas ) {
			$nom    = (string) $cas['name'];
			$octets = self::octets( $cas );
			$champs = self::decode( $octets );

			if ( 'cle_en_double' === $nom ) {
				// Le décodage garde la dernière des deux valeurs : les champs sont valides, c'est
				// le réencodage qui ne redonne pas les octets lus (une seule clé « version »).
				$encode = BackupIndex::encode( $champs );
				self::assertNotSame( $octets, $encode, $nom );
				self::assertSame( 1, substr_count( $encode, '"version":' ), $nom );
				continue;
			}

			try {
				BackupIndex::encode( $champs );
				self::fail( 'encode() a accepté les champs de ' . $nom . ', que parse() refuse.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assert_message_d_ecriture( $e, $nom );
				++$refuses;
			}
		}
		self::assertSame( 22, $refuses );
	}

	/**
	 * Bornes de chaque champ, en plus des vecteurs : mutation des champs d'`index_complet`,
	 * accepté ou non, à l'écriture comme à la lecture. Les bornes sont inclusives (§ 7.2).
	 *
	 * @param \Closure( array<string, mixed> ): array<string, mixed> $mutation Mutation des champs.
	 */
	#[DataProvider( 'bornes_des_champs' )]
	public function test_bornes_des_champs( \Closure $mutation, bool $accepte ): void {
		$champs = $mutation( self::decode( self::octets( VectorsFixture::named( 'index', 'index_complet' ) ) ) );
		// JSON_PRESERVE_ZERO_FRACTION : un flottant reste écrit en flottant (3.0, et non 3), comme le
		// lirait un lecteur. Sans flottant, les octets sont ceux des seules options du contrat.
		$octets = json_encode( $champs, self::JSON_OPTIONS | JSON_PRESERVE_ZERO_FRACTION );

		if ( $accepte ) {
			self::assertSame( $octets, BackupIndex::encode( $champs ) );
			self::assertSame( $champs, BackupIndex::parse( $octets ) );
			return;
		}

		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $octets ) );
		try {
			BackupIndex::encode( $champs );
			self::fail( 'encode() a accepté des champs hors bornes.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assert_message_d_ecriture( $e, 'borne' );
		}
	}

	/**
	 * La taille est contrôlée avant tout décodage (§ 7.5) : 1 048 577 octets qui ne sont pas du
	 * JSON rendent `index_too_large`, pas `index_malformed` ; 1 048 576 octets se décodent (et sont
	 * refusés pour ce qu'ils sont).
	 */
	public function test_index_trop_grand_refuse_avant_decodage(): void {
		self::assert_format_error( FormatError::INDEX_TOO_LARGE, static fn () => BackupIndex::parse( str_repeat( '{', BackupIndex::MAX_BYTES + 1 ) ) );
		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( str_repeat( '{', BackupIndex::MAX_BYTES ) ) );

		$valide = self::octets( VectorsFixture::named( 'index', 'index_complet' ) );
		self::assert_format_error( FormatError::INDEX_TOO_LARGE, static fn () => BackupIndex::parse( $valide . str_repeat( ' ', BackupIndex::MAX_BYTES + 1 - strlen( $valide ) ) ) );
		// À la borne, l'espace final est du JSON valide : c'est le réencodage exact qui refuse.
		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $valide . str_repeat( ' ', BackupIndex::MAX_BYTES - strlen( $valide ) ) ) );
	}

	/**
	 * Index au maximum (docs/backup-format.md § 11) : 4 096 segments aux valeurs maximales du
	 * calcul des bornes, `site_key_version` 4 294 967 295 et `created_at` 9999-12-31T23:59:59Z
	 * compris. Moins de 600 000 octets, signé, vérifié puis lu.
	 */
	#[Group( 'sodium-compat' )]
	public function test_index_de_4096_segments_aux_valeurs_maximales_lu(): void {
		$champs = self::champs_maximaux( BackupIndex::MAX_SEGMENTS );
		$octets = BackupIndex::encode( $champs );

		self::assertSame( json_encode( $champs, self::JSON_OPTIONS ), $octets );
		self::assertLessThan( 600000, strlen( $octets ) );

		$sodium    = self::moteur();
		$paire     = self::paire_du_site( $sodium );
		$signature = BackupIndex::sign( $octets, $paire['secret'], $sodium );
		self::assertTrue( BackupIndex::verify( $octets, $signature, $paire['public'], $sodium ) );

		$lu = BackupIndex::parse( $octets );
		self::assertSame( $champs, $lu );
		self::assertCount( 4096, $lu['segments'] );
		self::assertSame( 4294967295, $lu['site_key_version'] );
		self::assertSame( '9999-12-31T23:59:59Z', $lu['created_at'] );
		self::assertSame( 4095, $lu['manifest_segment'] );
	}

	public function test_index_de_4097_segments_refuse(): void {
		$champs = self::champs_maximaux( BackupIndex::MAX_SEGMENTS + 1 );
		$octets = json_encode( $champs, self::JSON_OPTIONS );

		// Sous la borne de taille : c'est le nombre de segments qui est refusé.
		self::assertLessThan( BackupIndex::MAX_BYTES, strlen( $octets ) );
		self::assert_format_error( FormatError::INDEX_MALFORMED, static fn () => BackupIndex::parse( $octets ) );
		try {
			BackupIndex::encode( $champs );
			self::fail( 'encode() a accepté 4 097 segments.' );
		} catch ( \InvalidArgumentException $e ) {
			self::assert_message_d_ecriture( $e, '4 097 segments' );
		}
	}

	// ---------------------------------------------------------------------------------------
	// Identifiant de la clé, signature.
	// ---------------------------------------------------------------------------------------

	public function test_identifiant_de_cle_de_signature(): void {
		foreach ( VectorsFixture::section( 'index' ) as $vecteur ) {
			$cle = VectorsFixture::bin( $vecteur['public_key'] );
			self::assertSame( $vecteur['signing_key_id'], BackupIndex::signing_key_id( $cle ), (string) $vecteur['name'] );
			self::assertSame( $vecteur['signing_key_id'], BackupIndex::parse( self::octets( $vecteur ) )['signing_key_id'], (string) $vecteur['name'] );
		}

		// 8 premiers octets de SHA-256 : début de l'empreinte de comparaison (16 octets) de la clé.
		$empreinte = VectorsFixture::named( 'empreintes_de_comparaison', 'cle_de_signature_d_index_complet' );
		self::assertSame( substr( (string) $empreinte['fingerprint'], 0, 16 ), BackupIndex::signing_key_id( VectorsFixture::bin( $empreinte['public_key'] ) ) );

		$cle = VectorsFixture::bin( VectorsFixture::named( 'index', 'index_complet' )['public_key'] );
		foreach ( [ '', substr( $cle, 0, 31 ), $cle . "\0", bin2hex( $cle ) ] as $fausse ) {
			try {
				BackupIndex::signing_key_id( $fausse );
				self::fail( 'Clé de ' . strlen( $fausse ) . ' octets acceptée.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( '32 octets', $e->getMessage() );
			}
		}
	}

	/**
	 * Docblock de sign() : la phase 4 vérifie la signature produite avant tout envoi. La
	 * bibliothèque n'enchaîne pas elle-même ces appels ; ce test montre qu'une signature fausse
	 * (moteur doublé : signature altérée d'un bit) ne passe pas verify() par le vrai moteur.
	 * Doublure : vrai objet Sodium dont ed25519_sign() est redéfinie par Patchwork, chargé avant
	 * Sodium.php dans ce processus à part. Fermeture non statique : Patchwork la lie à l'objet.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_une_signature_fausse_ne_se_verifie_pas_avec_la_cle_du_site(): void {
		self::assertFalse( class_exists( Sodium::class, false ), 'Sodium déjà chargée avant Patchwork : sa doublure serait impossible (test hors de son processus à part ?).' );
		Monkey\setUp();
		$this->monkey = true;

		$vecteur = VectorsFixture::named( 'index', 'index_complet' );
		$octets  = self::octets( $vecteur );
		$vrai    = self::moteur();
		$paire   = self::paire_du_site( $vrai );

		foreach ( [ 0, 32 ] as $octet ) {
			$appels       = 0;
			$redefinition = \Patchwork\redefine(
				[ Sodium::class, 'ed25519_sign' ],
				function () use ( &$appels, $octet ): string {
					$juste            = (string) \Patchwork\relay();
					$juste[ $octet ] = chr( ord( $juste[ $octet ] ) ^ 0x01 );
					++$appels;
					return $juste;
				}
			);
			$fausse = BackupIndex::sign( $octets, $paire['secret'], self::moteur() );
			\Patchwork\restore( $redefinition );

			self::assertSame( 1, $appels, 'La doublure n\'a pas signé : l\'échec viendrait d\'ailleurs.' );
			$ecart = VectorsFixture::bin( $vecteur['signature'] ) ^ $fausse;
			self::assertSame( 1, substr_count( decbin( ord( $ecart[ $octet ] ) ), '1' ), 'Un seul bit d\'écart.' );
			self::assertSame( str_repeat( "\0", 63 ), substr( $ecart, 0, $octet ) . substr( $ecart, $octet + 1 ) );
			self::assertFalse( BackupIndex::verify( $octets, $fausse, $paire['public'], $vrai ), 'octet ' . $octet );
		}

		// Doublure retirée : la vraie signature est celle du vecteur, et elle se vérifie.
		$juste = BackupIndex::sign( $octets, $paire['secret'], $vrai );
		self::assertSame( $vecteur['signature'], bin2hex( $juste ) );
		self::assertTrue( BackupIndex::verify( $octets, $juste, $paire['public'], $vrai ) );
	}

	public function test_la_classe_ne_passe_que_par_sodium(): void {
		$noms = self::identifiants( dirname( __DIR__, 3 ) . '/includes/Backup/Format/BackupIndex.php' );

		foreach ( $noms as $nom ) {
			self::assertStringStartsNotWith( 'sodium_', strtolower( $nom ), 'Appel direct à l\'extension sodium : ' . $nom );
			self::assertStringNotContainsString( 'paragonie', strtolower( $nom ), 'Appel direct à sodium_compat : ' . $nom );
			self::assertNotContains( $nom, [ 'pk_to_curve25519', 'crypto_sign_ed25519_pk_to_curve25519', 'ge_mul_l' ] );
		}
		// La lecture des jetons marche : les deux appels attendus y sont.
		self::assertContains( 'ed25519_sign', $noms );
		self::assertContains( 'ed25519_verify', $noms );
	}

	// ---------------------------------------------------------------------------------------
	// Fournisseurs.
	// ---------------------------------------------------------------------------------------

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function index_mal_formes(): iterable {
		foreach ( VectorsFixture::section( 'index_malformed' ) as $cas ) {
			/** @var array<string, mixed> $cas */
			yield (string) $cas['name'] => [ $cas ];
		}
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function variantes_de_reencodage(): iterable {
		yield 'espace après une virgule' => [ '"version":1,', '"version":1, ' ];
		yield 'espace après l\'accolade ouvrante' => [ '{"format"', '{ "format"' ];
		yield 'fin de ligne après l\'objet' => [ '"manifest_segment":3}', "\"manifest_segment\":3}\n" ];
		yield 'deux clés permutées' => [ '"version":1,"suite":1', '"suite":1,"version":1' ];
		yield 'deux clés d\'un segment permutées' => [ '"blocks":3,"bytes":236', '"bytes":236,"blocks":3' ];
		yield '1.0 au lieu de 1' => [ '"version":1,', '"version":1.0,' ];
		yield '1e0 au lieu de 1' => [ '"suite":1,', '"suite":1e0,' ];
		yield '-0 au lieu de 0' => [ '"index":0,', '"index":-0,' ];
		// « g2bk », barre oblique inverse, « u002dindex » : l'échappement JSON du trait d'union, que
		// json_decode() lit « - ». chr( 92 ) écrit la barre sans séquence d'échappement dans ce fichier.
		yield 'échappement Unicode d\'un caractère ASCII' => [ '"g2bk-index"', '"g2bk' . chr( 92 ) . 'u002dindex"' ];
		// Aucune valeur d'un index valide ne porte de « / » : son échappement n'apparaît qu'avec une
		// valeur fausse, refusée elle aussi.
		yield 'échappement \/' => [ '"g2bk-index"', '"g2bk\/index"' ];
	}

	/**
	 * @return iterable<string, array{\Closure( array<string, mixed> ): array<string, mixed>, bool}>
	 */
	public static function bornes_des_champs(): iterable {
		$racine  = static fn ( string $cle, mixed $valeur ): \Closure => static fn ( array $champs ): array => array_replace( $champs, [ $cle => $valeur ] );
		$segment = static fn ( string $cle, mixed $valeur ): \Closure => static function ( array $champs ) use ( $cle, $valeur ): array {
			$champs['segments'][0][ $cle ] = $valeur;
			return $champs;
		};

		yield 'blocks à 1' => [ $segment( 'blocks', 1 ), true ];
		yield 'blocks au plus grand entier JSON exact (2⁵³ − 1)' => [ $segment( 'blocks', 9007199254740991 ), true ];
		yield 'blocks à 2⁵³' => [ $segment( 'blocks', 9007199254740992 ), false ];
		yield 'blocks en flottant (3.0)' => [ $segment( 'blocks', 3.0 ), false ];
		yield 'bytes à 164 (segment vide)' => [ $segment( 'bytes', 164 ), true ];
		yield 'bytes à 163' => [ $segment( 'bytes', 163 ), false ];
		yield 'bytes à 1 Gio' => [ $segment( 'bytes', 1073741824 ), true ];
		yield 'bytes à 1 Gio + 1' => [ $segment( 'bytes', 1073741825 ), false ];
		yield 'index de segment négatif' => [ $segment( 'index', -1 ), false ];
		yield 'index de segment en chaîne' => [ $segment( 'index', '0' ), false ];
		yield 'nature inconnue' => [ $segment( 'kind', 'base' ), false ];
		yield 'empreinte trop courte' => [ $segment( 'sha256', str_repeat( 'a', 63 ) ), false ];
		yield 'empreinte en majuscules' => [ $segment( 'sha256', str_repeat( 'A', 64 ) ), false ];
		yield 'site_key_version à 1' => [ $racine( 'site_key_version', 1 ), true ];
		yield 'site_key_version à 2³² − 1' => [ $racine( 'site_key_version', 4294967295 ), true ];
		yield 'site_key_version à 2³²' => [ $racine( 'site_key_version', 4294967296 ), false ];
		yield 'site_key_version à 0' => [ $racine( 'site_key_version', 0 ), false ];
		yield 'site_key_version à vrai' => [ $racine( 'site_key_version', true ), false ];
		yield 'manifest_segment négatif' => [ $racine( 'manifest_segment', -1 ), false ];
		yield 'manifest_segment hors de la liste' => [ $racine( 'manifest_segment', 4 ), false ];
		yield 'manifest_segment sur un segment de fichiers' => [ $racine( 'manifest_segment', 1 ), false ];
		yield 'version 2' => [ $racine( 'version', 2 ), false ];
		yield 'suite 2' => [ $racine( 'suite', 2 ), false ];
		yield 'payload_format 2' => [ $racine( 'payload_format', 2 ), false ];
		yield 'format inconnu' => [ $racine( 'format', 'g2bk-manifest' ), false ];
		yield 'type inconnu' => [ $racine( 'type', 'differential' ), false ];
		yield 'signing_key_id trop long' => [ $racine( 'signing_key_id', str_repeat( 'a', 17 ) ), false ];
		yield 'backup_id trop court' => [ $racine( 'backup_id', str_repeat( 'a', 31 ) ), false ];
		yield 'site_ref en entier' => [ $racine( 'site_ref', 1 ), false ];
		yield 'parent_backup_id en chaîne vide pour une complète' => [ $racine( 'parent_backup_id', '' ), false ];
		yield 'segments vide' => [ $racine( 'segments', [] ), false ];
		yield 'segment qui n\'est pas un objet' => [ $racine( 'segments', [ 1 ] ), false ];
		yield 'date au 1er janvier 1970' => [ $racine( 'created_at', '1970-01-01T00:00:00Z' ), true ];
		yield 'date au 31 décembre 1969' => [ $racine( 'created_at', '1969-12-31T23:59:59Z' ), false ];
		yield 'date au 31 décembre 9999' => [ $racine( 'created_at', '9999-12-31T23:59:59Z' ), true ];
		yield 'date au 29 février d\'une année bissextile' => [ $racine( 'created_at', '2028-02-29T00:00:00Z' ), true ];
		yield 'date au 29 février 2100 (non bissextile)' => [ $racine( 'created_at', '2100-02-29T00:00:00Z' ), false ];
		yield 'date au 31 avril' => [ $racine( 'created_at', '2026-04-31T00:00:00Z' ), false ];
		yield 'mois 13' => [ $racine( 'created_at', '2026-13-01T00:00:00Z' ), false ];
		yield 'jour 00' => [ $racine( 'created_at', '2026-10-00T00:00:00Z' ), false ];
		yield 'heure 24' => [ $racine( 'created_at', '2026-10-24T24:00:00Z' ), false ];
		yield 'minute 60' => [ $racine( 'created_at', '2026-10-24T01:60:00Z' ), false ];
		yield 'date avec un chiffre arabe-indien' => [ $racine( 'created_at', '٢026-10-24T01:00:00Z' ), false ];
		yield 'date suivie d\'une fin de ligne' => [ $racine( 'created_at', "2026-10-24T01:00:00Z\n" ), false ];
		yield 'date à chiffres seuls' => [ $racine( 'created_at', '2026-1-5T1:0:0Z' ), false ];
		yield 'date avec fuseau' => [ $racine( 'created_at', '2026-10-24T01:00:00+00:00' ), false ];
	}

	// ---------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------

	private static function assert_signatures_reproduites( Sodium $sodium ): void {
		foreach ( VectorsFixture::section( 'index' ) as $vecteur ) {
			$nom    = (string) $vecteur['name'];
			$octets = self::octets( $vecteur );
			$paire  = $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( $vecteur['seed'] ) );
			self::assertSame( $vecteur['public_key'], bin2hex( $paire['public'] ), $nom );

			// Ed25519 est déterministe : la signature est celle du vecteur, à l'octet.
			$signature = BackupIndex::sign( $octets, $paire['secret'], $sodium );
			self::assertSame( $vecteur['signature'], bin2hex( $signature ), $nom );
			self::assertSame( $sodium->ed25519_sign( self::INDEX_DOMAIN . $octets, $paire['secret'] ), $signature, $nom . ' : domaine' );

			self::assertTrue( BackupIndex::verify( $octets, $signature, $paire['public'], $sodium ), $nom );
			self::assertFalse( BackupIndex::verify( $octets . ' ', $signature, $paire['public'], $sodium ), $nom . ' : autre index' );
			// Sans le domaine, la même signature ne vaut rien.
			self::assertFalse( $sodium->ed25519_verify( $signature, $octets, $paire['public'] ), $nom . ' : sans domaine' );
		}
	}

	private static function assert_alterations_refusees( Sodium $sodium ): void {
		$cas_vus = [];
		foreach ( VectorsFixture::section( 'index_tampering' ) as $cas ) {
			$nom = (string) $cas['name'];
			self::assertSame( 'index_signature_invalid', $cas['expected_error'], $nom );
			self::assertFalse( BackupIndex::verify( self::octets( $cas ), VectorsFixture::bin( $cas['signature'] ), VectorsFixture::bin( $cas['public_key'] ), $sodium ), $nom );
			$cas_vus[] = $nom;
		}
		self::assertSame( self::ALTERATIONS, $cas_vus );
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

	/**
	 * Refus d'écriture : erreur de programmation au message explicite (quoi, cause probable,
	 * action), sans exception chaînée.
	 */
	private static function assert_message_d_ecriture( \InvalidArgumentException $e, string $cas ): void {
		$message = $e->getMessage();
		self::assertStringStartsWith( 'Index de sauvegarde impossible à écrire : ', $message, $cas );
		self::assertStringContainsString( 'Cause probable :', $message, $cas );
		self::assertStringContainsString( 'Action :', $message, $cas );
		self::assertNull( $e->getPrevious(), $cas );
	}

	/**
	 * Octets d'un index du fichier de vecteurs (le JSON, tel quel).
	 *
	 * @param array<mixed> $cas Cas d'une section `index…`.
	 */
	private static function octets( array $cas ): string {
		self::assertIsString( $cas['index'] );
		return $cas['index'];
	}

	/**
	 * Champs d'un index, par json_decode() (la dernière valeur d'une clé en double l'emporte).
	 *
	 * @return array<string, mixed>
	 */
	private static function decode( string $octets ): array {
		$champs = json_decode( $octets, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $champs );
		/** @var array<string, mixed> $champs */
		return $champs;
	}

	/**
	 * Index de `$nombre` segments aux valeurs maximales du calcul des bornes (§ 11) :
	 * incrémentale (parent de 32 hex), `site_key_version` 2³² − 1, date maximale, segments de
	 * fichiers puis un manifeste en dernier, `blocks` 67 108 864 et `bytes` 1 073 741 824.
	 *
	 * @return array<string, mixed>
	 */
	private static function champs_maximaux( int $nombre ): array {
		$segments = [];
		for ( $i = 0; $i < $nombre; ++$i ) {
			$segments[] = [
				'index'  => $i,
				'kind'   => $nombre - 1 === $i ? 'manifest' : 'files',
				'blocks' => 67108864,
				'bytes'  => 1073741824,
				'sha256' => str_repeat( 'f', 64 ),
			];
		}

		return array_replace(
			self::decode( self::octets( VectorsFixture::named( 'index', 'index_complet' ) ) ),
			[
				'site_key_version' => 4294967295,
				'created_at'       => '9999-12-31T23:59:59Z',
				'type'             => 'incremental',
				'parent_backup_id' => str_repeat( 'f', 32 ),
				'segments'         => $segments,
				'manifest_segment' => $nombre - 1,
			]
		);
	}

	/**
	 * Paire Ed25519 de la graine des vecteurs `index`.
	 *
	 * @return array{public: string, secret: string}
	 */
	private static function paire_du_site( Sodium $sodium ): array {
		return $sodium->ed25519_keypair_from_seed( VectorsFixture::bin( VectorsFixture::named( 'index', 'index_complet' )['seed'] ) );
	}

	private static function natif(): Sodium {
		if ( ! extension_loaded( 'sodium' ) ) {
			self::markTestSkipped( 'Extension sodium absente : cas natifs non joués sur ce PHP.' );
		}
		return new Sodium( static fn (): bool => true, static fn (): bool => true );
	}

	private static function compat(): Sodium {
		\ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true;
		return new Sodium( static fn (): bool => false, static fn (): bool => true );
	}

	/** Moteur des cas qui ne portent pas sur le moteur : natif si l'extension est là. */
	private static function moteur(): Sodium {
		return extension_loaded( 'sodium' ) ? self::natif() : self::compat();
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

	/**
	 * Noms (fonctions, classes, méthodes) employés par le code d'un fichier, commentaires écartés.
	 *
	 * @return list<string>
	 */
	private static function identifiants( string $fichier ): array {
		$source = file_get_contents( $fichier );
		self::assertIsString( $source );

		$noms = [];
		foreach ( token_get_all( $source ) as $jeton ) {
			if ( is_array( $jeton ) && in_array( $jeton[0], [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ], true ) ) {
				$parties = explode( '\\', $jeton[1] );
				$noms[]  = (string) end( $parties );
				$noms[]  = $jeton[1];
			}
		}
		return $noms;
	}
}
