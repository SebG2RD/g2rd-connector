<?php
/**
 * En-tête d'un segment G2BK v1 (Backup\Format\SegmentHeader) : lecture, écriture, empreinte,
 * sous-clés, engagement de clé.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Format;

use G2RD\Connector\Backup\Crypto\Hkdf;
use G2RD\Connector\Backup\Format\FormatError;
use G2RD\Connector\Backup\Format\SegmentHeader;
use G2RD\Connector\Tests\Backup\VectorsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Valeurs attendues : sections `segments` et `tampering` du fichier de vecteurs (générateur Python
 * indépendant de la plateforme) ; constantes recopiées du contrat (docs/backup-format.md § 3, § 4
 * et § 12, dépôt de la plateforme). Jamais une sortie du code testé.
 *
 * Le cas `grand_bloc` ne porte pas ses octets (seulement leurs empreintes) : il sert ici aux
 * sous-clés et à l'engagement ; son en-tête complet est reproduit par l'écrivain (tâche 18).
 */
final class SegmentHeaderTest extends TestCase {

	/** Codes que rend la lecture de l'en-tête, dans l'ordre du contrat (§ 12). */
	private const CODES_D_EN_TETE = [
		FormatError::HEADER_TRUNCATED,
		FormatError::BAD_MAGIC,
		FormatError::UNSUPPORTED_VERSION,
		FormatError::UNSUPPORTED_SUITE,
		FormatError::UNKNOWN_KIND,
		FormatError::RESERVED_FLAGS,
		FormatError::BAD_BLOCK_SIZE,
		FormatError::BAD_KEY_VERSION,
		FormatError::BAD_SEGMENT_INDEX,
	];

	/** Le plus grand entier sur 4 octets (2³² − 1). */
	private const MAX_UINT32 = 4294967295;

	public function test_constantes_du_contrat(): void {
		self::assertSame( 148, SegmentHeader::BYTES );
		self::assertSame( 36, SegmentHeader::PREFIX_BYTES );
		self::assertSame( 'G2BK', SegmentHeader::MAGIC );
		self::assertSame( 1, SegmentHeader::FORMAT_VERSION );
		self::assertSame( 1, SegmentHeader::SUITE_GCM );
		self::assertSame( 2, SegmentHeader::SUITE_SECRETSTREAM );
		self::assertSame( 1, SegmentHeader::KIND_DB );
		self::assertSame( 2, SegmentHeader::KIND_FILES );
		self::assertSame( 3, SegmentHeader::KIND_MANIFEST );
		self::assertSame( 16, SegmentHeader::MIN_BLOCK_SIZE );
		self::assertSame( 67108864, SegmentHeader::MAX_BLOCK_SIZE );
		self::assertSame( 'g2bk-v1/commit', SegmentHeader::COMMIT_LABEL );
		self::assertSame( 'g2bk-v1/blocks', SegmentHeader::BLOCK_LABEL );
		self::assertSame( 4096, SegmentHeader::MAX_SEGMENTS );
	}

	public function test_chaque_en_tete_des_vecteurs_est_lu(): void {
		$sans_octets = [];
		foreach ( VectorsFixture::section( 'segments' ) as $cas ) {
			self::assertIsArray( $cas );
			if ( ! isset( $cas['header'] ) ) {
				$sans_octets[] = $cas['name'] ?? null;
			}
		}
		self::assertSame( [ 'grand_bloc' ], $sans_octets, 'Seul grand_bloc ne porte pas ses octets.' );

		$segments = self::segments_avec_octets();
		self::assertCount( 5, $segments );

		foreach ( $segments as $nom => $cas ) {
			$octets = VectorsFixture::bin( $cas['header'] ?? null );
			$hex    = bin2hex( $octets );
			self::assertSame( SegmentHeader::BYTES, strlen( $octets ), $nom );
			$champs = self::champs( $cas );

			// L'en-tête seul, puis le segment entier : seuls les 148 premiers octets sont lus.
			foreach ( [ $octets, VectorsFixture::bin( $cas['segment'] ?? null ) ] as $entree ) {
				$en_tete = SegmentHeader::parse( $entree );

				self::assertSame( SegmentHeader::SUITE_GCM, $en_tete->suite(), $nom );
				self::assertSame( $champs['kind'], $en_tete->kind(), $nom );
				self::assertSame( bin2hex( $champs['backup_id'] ), bin2hex( $en_tete->backup_id() ), $nom );
				self::assertSame( $champs['segment_index'], $en_tete->segment_index(), $nom );
				self::assertSame( $champs['site_key_version'], $en_tete->site_key_version(), $nom );
				self::assertSame( $champs['block_size'], $en_tete->block_size(), $nom );
				self::assertSame( substr( $hex, 0, 72 ), bin2hex( $en_tete->prefix() ), $nom . ' : préfixe, octets 0 à 35.' );
				self::assertSame( substr( $hex, 72, 64 ), bin2hex( $en_tete->enc() ), $nom . ' : enc, octets 36 à 67.' );
				self::assertSame( substr( $hex, 136, 96 ), bin2hex( $en_tete->sealed_dek() ), $nom . ' : clé scellée, octets 68 à 115.' );
				self::assertSame( $cas['key_commitment'] ?? null, bin2hex( $en_tete->key_commitment() ), $nom . ' : engagement, octets 116 à 147.' );
				self::assertSame( $hex, bin2hex( $en_tete->encode() ), $nom );
			}
		}
	}

	public function test_l_empreinte_est_celle_du_vecteur(): void {
		foreach ( self::segments_avec_octets() as $nom => $cas ) {
			$empreinte = SegmentHeader::parse( VectorsFixture::bin( $cas['header'] ?? null ) )->hash();

			self::assertSame( 32, strlen( $empreinte ), $nom . ' : SHA-256 brut.' );
			self::assertSame( $cas['header_hash'] ?? null, bin2hex( $empreinte ), $nom );
		}
	}

	public function test_aller_retour_a_l_octet(): void {
		foreach ( self::segments_avec_octets() as $nom => $cas ) {
			$octets = VectorsFixture::bin( $cas['header'] ?? null );
			$champs = self::champs( $cas );

			// Lu puis réécrit.
			self::assertSame( bin2hex( $octets ), bin2hex( SegmentHeader::parse( $octets )->encode() ), $nom );

			// Écrit depuis les champs du vecteur.
			$prefixe = SegmentHeader::prefix_bytes( SegmentHeader::SUITE_GCM, $champs['kind'], $champs['backup_id'], $champs['segment_index'], $champs['site_key_version'], $champs['block_size'] );
			self::assertSame( bin2hex( substr( $octets, 0, 36 ) ), bin2hex( $prefixe ), $nom . ' : préfixe.' );

			$en_tete = new SegmentHeader( $prefixe, substr( $octets, 36, 32 ), substr( $octets, 68, 48 ), substr( $octets, 116, 32 ) );
			self::assertSame( bin2hex( $octets ), bin2hex( $en_tete->encode() ), $nom . ' : en-tête assemblé.' );
			self::assertSame( $cas['header_hash'] ?? null, bin2hex( $en_tete->hash() ), $nom . ' : empreinte de l\'en-tête assemblé.' );
			self::assertSame( $champs['segment_index'], $en_tete->segment_index(), $nom );
			self::assertSame( bin2hex( $octets ), bin2hex( SegmentHeader::parse( $en_tete->encode() )->encode() ), $nom . ' : assemblé, lu, réécrit.' );
		}
	}

	public function test_engagement_de_cle_reproduit(): void {
		foreach ( self::segments_avec_octets() as $nom => $cas ) {
			$prefixe = substr( VectorsFixture::bin( $cas['header'] ?? null ), 0, 36 );
			$dek     = VectorsFixture::bin( $cas['dek'] ?? null );

			$engagement = SegmentHeader::commitment( $dek, $prefixe );
			self::assertSame( $cas['key_commitment'] ?? null, bin2hex( $engagement ), $nom );

			// L'engagement dépend de chaque octet du préfixe et de la clé de données.
			$autre_prefixe    = $prefixe;
			$autre_prefixe[8] = chr( ord( $autre_prefixe[8] ) ^ 0x01 );
			$autre_dek        = $dek;
			$autre_dek[31]    = chr( ord( $autre_dek[31] ) ^ 0x80 );
			self::assertNotSame( bin2hex( $engagement ), bin2hex( SegmentHeader::commitment( $dek, $autre_prefixe ) ), $nom );
			self::assertNotSame( bin2hex( $engagement ), bin2hex( SegmentHeader::commitment( $autre_dek, $prefixe ) ), $nom );
		}
	}

	public function test_sous_cles_reproduites(): void {
		$vus = 0;
		foreach ( VectorsFixture::section( 'segments' ) as $cas ) {
			self::assertIsArray( $cas );
			/** @var array<string, mixed> $cas */
			$nom              = (string) ( $cas['name'] ?? '' );
			$champs           = self::champs( $cas );
			$dek              = VectorsFixture::bin( $cas['dek'] ?? null );
			$cle_d_engagement = VectorsFixture::bin( $cas['commitment_key'] ?? null );
			$cle_de_bloc      = SegmentHeader::block_key( $dek );

			// k_bloc = HKDF-Expand( clé de données, « g2bk-v1/blocks », 32 ).
			self::assertSame( $cas['block_key'] ?? null, bin2hex( $cle_de_bloc ), $nom . ' : k_bloc.' );
			// k_engagement = HKDF-Expand( clé de données, « g2bk-v1/commit », 32 ).
			self::assertSame( bin2hex( $cle_d_engagement ), bin2hex( Hkdf::expand( $dek, SegmentHeader::COMMIT_LABEL, 32 ) ), $nom . ' : k_engagement.' );

			// commitment() calcule HMAC-SHA256 sous k_engagement, jamais sous la clé de données.
			$prefixe = SegmentHeader::prefix_bytes( SegmentHeader::SUITE_GCM, $champs['kind'], $champs['backup_id'], $champs['segment_index'], $champs['site_key_version'], $champs['block_size'] );
			self::assertSame(
				bin2hex( hash_hmac( 'sha256', $prefixe, $cle_d_engagement, true ) ),
				bin2hex( SegmentHeader::commitment( $dek, $prefixe ) ),
				$nom . ' : engagement sous k_engagement.'
			);
			self::assertNotSame( bin2hex( hash_hmac( 'sha256', $prefixe, $dek, true ) ), bin2hex( SegmentHeader::commitment( $dek, $prefixe ) ), $nom );

			// Séparation des usages : trois clés différentes.
			self::assertNotSame( bin2hex( $dek ), bin2hex( $cle_de_bloc ), $nom );
			self::assertNotSame( bin2hex( $dek ), bin2hex( $cle_d_engagement ), $nom );
			self::assertNotSame( bin2hex( $cle_de_bloc ), bin2hex( $cle_d_engagement ), $nom );
			++$vus;
		}
		self::assertSame( 6, $vus, 'Les six cas de segments, grand_bloc compris.' );
	}

	/**
	 * Cas de `tampering` refusés dès la lecture de l'en-tête (leur code est un code d'en-tête).
	 *
	 * @return array<string, array{0: string, 1: string}> Nom du cas => [ octets altérés, code attendu ].
	 */
	public static function alterations_d_en_tete(): array {
		$cas = [];
		foreach ( VectorsFixture::section( 'tampering' ) as $alteration ) {
			if ( ! is_array( $alteration ) || ! in_array( $alteration['expected_error'] ?? null, self::CODES_D_EN_TETE, true ) ) {
				continue;
			}
			$source = VectorsFixture::named( 'segments', (string) ( $alteration['from'] ?? '' ) );

			$cas[ (string) ( $alteration['name'] ?? '' ) ] = [
				self::altere( VectorsFixture::bin( $source['segment'] ?? null ), $alteration['mutation'] ?? null ),
				(string) $alteration['expected_error'],
			];
		}
		return $cas;
	}

	#[DataProvider( 'alterations_d_en_tete' )]
	public function test_alterations_d_en_tete_refusees_avec_leur_code( string $octets, string $code ): void {
		self::assertSame( $code, self::refus( $octets ), 'Segment altéré.' );
		if ( strlen( $octets ) >= SegmentHeader::BYTES ) {
			self::assertSame( $code, self::refus( substr( $octets, 0, SegmentHeader::BYTES ) ), 'En-tête altéré seul.' );
		}
	}

	public function test_les_neuf_cas_d_en_tete_du_fichier_sont_essayes(): void {
		self::assertSame(
			[
				'magie_fausse',
				'version_inconnue',
				'suite_inconnue',
				'nature_inconnue',
				'drapeaux_reserves',
				'taille_de_bloc_trop_petite',
				'version_de_cle_nulle',
				'en_tete_tronque',
				'index_de_segment_hors_bornes',
			],
			array_keys( self::alterations_d_en_tete() )
		);
	}

	public function test_alterations_controlees_plus_loin_passent_la_lecture_de_l_en_tete(): void {
		// Identifiant, clé éphémère, clé scellée, engagement : refusés à l'ouverture de la clé
		// (seal_open_failed, key_commitment_mismatch, tâche 19), jamais par la lecture de l'en-tête.
		$vus = [];
		foreach ( VectorsFixture::section( 'tampering' ) as $alteration ) {
			self::assertIsArray( $alteration );
			$mutation = $alteration['mutation'] ?? null;
			self::assertIsArray( $mutation );
			if ( 'xor' !== ( $mutation['type'] ?? null ) || $mutation['offset'] >= SegmentHeader::BYTES || in_array( $alteration['expected_error'] ?? null, self::CODES_D_EN_TETE, true ) ) {
				continue;
			}
			$source  = VectorsFixture::named( 'segments', (string) ( $alteration['from'] ?? '' ) );
			$altere  = self::altere( VectorsFixture::bin( $source['segment'] ?? null ), $mutation );
			$en_tete = SegmentHeader::parse( $altere );

			self::assertSame( bin2hex( substr( $altere, 0, SegmentHeader::BYTES ) ), bin2hex( $en_tete->encode() ), (string) ( $alteration['name'] ?? '' ) );
			$vus[] = $alteration['name'] ?? null;
		}
		self::assertSame( [ 'identifiant_de_sauvegarde_modifie', 'cle_ephemere_modifiee', 'cle_scellee_modifiee', 'engagement_de_cle_modifie' ], $vus );
	}

	public function test_ordre_des_controles(): void {
		$base = self::en_tete_de_base();

		// Magie fausse et version fausse : la magie d'abord.
		self::assertSame( FormatError::BAD_MAGIC, self::refus( self::ecrit( self::ecrit( $base, 0, 'X' ), 4, "\x02" ) ) );

		// Moins de 148 octets : rien d'autre n'est lu, même à la magie fausse.
		self::assertSame( FormatError::HEADER_TRUNCATED, self::refus( substr( self::ecrit( $base, 0, 'X' ), 0, 100 ) ) );
		self::assertSame( FormatError::HEADER_TRUNCATED, self::refus( substr( $base, 0, 147 ) ) );
		self::assertSame( FormatError::HEADER_TRUNCATED, self::refus( '' ) );

		// Toutes les fautes à la fois, corrigées une à une dans l'ordre du contrat : chaque refus
		// est celui du premier contrôle encore faux. L'index de segment (décalage 24) est contrôlé
		// après la version de clé (décalage 28) : l'ordre est celui du contrat, pas celui des octets.
		$fautes = [
			FormatError::BAD_MAGIC           => [ 0, 'X' ],
			FormatError::UNSUPPORTED_VERSION => [ 4, "\x02" ],
			FormatError::UNSUPPORTED_SUITE   => [ 5, chr( SegmentHeader::SUITE_SECRETSTREAM ) ],
			FormatError::UNKNOWN_KIND        => [ 6, "\x00" ],
			FormatError::RESERVED_FLAGS      => [ 7, "\x80" ],
			FormatError::BAD_BLOCK_SIZE      => [ 32, pack( 'N', 15 ) ],
			FormatError::BAD_KEY_VERSION     => [ 28, pack( 'N', 0 ) ],
			FormatError::BAD_SEGMENT_INDEX   => [ 24, pack( 'N', 4096 ) ],
		];
		$octets = $base;
		foreach ( $fautes as [ $decalage, $valeur ] ) {
			$octets = self::ecrit( $octets, $decalage, $valeur );
		}
		foreach ( $fautes as $code => [ $decalage, $valeur ] ) {
			self::assertSame( $code, self::refus( $octets ) );
			$octets = self::ecrit( $octets, $decalage, substr( $base, $decalage, strlen( $valeur ) ) );
		}
		self::assertSame( bin2hex( $base ), bin2hex( SegmentHeader::parse( $octets )->encode() ), 'Toutes les fautes corrigées : en-tête lu.' );
	}

	public function test_prefixe_refuse_une_taille_de_bloc_hors_bornes(): void {
		foreach ( [ 15, 0, -1, -16, 67108865, 134217728, PHP_INT_MAX, PHP_INT_MIN ] as $taille ) {
			self::refus_du_prefixe( self::champs_valides( [ 'block_size' => $taille ] ), 'taille de bloc ' . $taille );
		}

		// Les deux bornes sont permises, et relues telles quelles.
		foreach ( [ 16, 67108864 ] as $taille ) {
			self::assertSame( $taille, self::relu( self::champs_valides( [ 'block_size' => $taille ] ) )->block_size() );
		}
	}

	public function test_prefixe_refuse_le_segment_4096(): void {
		foreach ( [ 4096, 4097, 65536, self::MAX_UINT32, self::MAX_UINT32 + 1, -1, PHP_INT_MAX, PHP_INT_MIN ] as $index ) {
			self::refus_du_prefixe( self::champs_valides( [ 'segment_index' => $index ] ), 'index de segment ' . $index );
		}

		foreach ( [ 0, 4095 ] as $index ) {
			self::assertSame( $index, self::relu( self::champs_valides( [ 'segment_index' => $index ] ) )->segment_index() );
		}
	}

	public function test_prefixe_refuse_chaque_champ_hors_contrat(): void {
		// Suite : seule SUITE_GCM ; la suite 2 (secretstream) est réservée, non retenue en v1.
		foreach ( [ 0, SegmentHeader::SUITE_SECRETSTREAM, 3, 255, 256, -1 ] as $suite ) {
			self::refus_du_prefixe( self::champs_valides( [ 'suite' => $suite ] ), 'suite ' . $suite );
		}
		foreach ( [ 0, 4, 255, -1 ] as $nature ) {
			self::refus_du_prefixe( self::champs_valides( [ 'kind' => $nature ] ), 'nature ' . $nature );
		}
		// Identifiant : 16 octets bruts, jamais ses 32 caractères hexadécimaux.
		$identifiant = self::champs_valides()['backup_id'];
		foreach ( [ '', substr( $identifiant, 0, 15 ), $identifiant . "\x00", bin2hex( $identifiant ) ] as $autre ) {
			self::refus_du_prefixe( self::champs_valides( [ 'backup_id' => $autre ] ), 'identifiant de ' . strlen( $autre ) . ' octets', bin2hex( $identifiant ) );
		}
		foreach ( [ 0, -1, self::MAX_UINT32 + 1, PHP_INT_MAX, PHP_INT_MIN ] as $version ) {
			self::refus_du_prefixe( self::champs_valides( [ 'site_key_version' => $version ] ), 'version de clé ' . $version );
		}

		// Bornes permises, relues telles quelles.
		foreach ( [ SegmentHeader::KIND_DB, SegmentHeader::KIND_FILES, SegmentHeader::KIND_MANIFEST ] as $nature ) {
			self::assertSame( $nature, self::relu( self::champs_valides( [ 'kind' => $nature ] ) )->kind() );
		}
		foreach ( [ 1, self::MAX_UINT32 ] as $version ) {
			self::assertSame( $version, self::relu( self::champs_valides( [ 'site_key_version' => $version ] ) )->site_key_version() );
		}
	}

	public function test_lecture_refuse_le_segment_4096_avec_son_code(): void {
		$base = self::en_tete_de_base();

		$dernier = SegmentHeader::parse( self::ecrit( $base, 24, pack( 'N', 4095 ) ) );
		self::assertSame( 4095, $dernier->segment_index() );

		self::assertSame( FormatError::BAD_SEGMENT_INDEX, self::refus( self::ecrit( $base, 24, pack( 'N', 4096 ) ) ) );
		self::assertSame( FormatError::BAD_SEGMENT_INDEX, self::refus( self::ecrit( $base, 24, pack( 'N', self::MAX_UINT32 ) ) ) );

		// La version de clé nulle est contrôlée avant l'index de segment.
		self::assertSame( FormatError::BAD_KEY_VERSION, self::refus( self::ecrit( self::ecrit( $base, 24, pack( 'N', 4096 ) ), 28, pack( 'N', 0 ) ) ) );
	}

	public function test_lecture_accepte_les_bornes_du_contrat(): void {
		$base = self::en_tete_de_base();

		self::assertSame( 16, SegmentHeader::parse( self::ecrit( $base, 32, pack( 'N', 16 ) ) )->block_size() );
		self::assertSame( 67108864, SegmentHeader::parse( self::ecrit( $base, 32, pack( 'N', 67108864 ) ) )->block_size() );
		self::assertSame( FormatError::BAD_BLOCK_SIZE, self::refus( self::ecrit( $base, 32, pack( 'N', 67108865 ) ) ) );
		self::assertSame( FormatError::BAD_BLOCK_SIZE, self::refus( self::ecrit( $base, 32, pack( 'N', self::MAX_UINT32 ) ) ) );
		self::assertSame( self::MAX_UINT32, SegmentHeader::parse( self::ecrit( $base, 28, pack( 'N', self::MAX_UINT32 ) ) )->site_key_version() );
		foreach ( [ SegmentHeader::KIND_DB, SegmentHeader::KIND_FILES, SegmentHeader::KIND_MANIFEST ] as $nature ) {
			self::assertSame( $nature, SegmentHeader::parse( self::ecrit( $base, 6, chr( $nature ) ) )->kind() );
		}
	}

	public function test_constructeur_refuse_des_longueurs_fausses(): void {
		$base    = self::en_tete_de_base();
		$prefixe = substr( $base, 0, 36 );
		$enc     = substr( $base, 36, 32 );
		$scellee = substr( $base, 68, 48 );
		$engage  = substr( $base, 116, 32 );

		$essais = [
			'préfixe de 35'     => [ substr( $prefixe, 0, 35 ), $enc, $scellee, $engage ],
			'préfixe de 37'     => [ $prefixe . "\x00", $enc, $scellee, $engage ],
			'en-tête entier'    => [ $base, $enc, $scellee, $engage ],
			'enc de 31'         => [ $prefixe, substr( $enc, 0, 31 ), $scellee, $engage ],
			'enc de 33'         => [ $prefixe, $enc . "\x00", $scellee, $engage ],
			'clé scellée de 32' => [ $prefixe, $enc, substr( $scellee, 0, 32 ), $engage ],
			'clé scellée de 49' => [ $prefixe, $enc, $scellee . "\x00", $engage ],
			'engagement de 31'  => [ $prefixe, $enc, $scellee, substr( $engage, 0, 31 ) ],
			'engagement de 64'  => [ $prefixe, $enc, $scellee, bin2hex( $engage ) ],
			// Préfixe de la bonne longueur, mais que la lecture refuserait.
			'magie fausse'      => [ self::ecrit( $prefixe, 0, 'X' ), $enc, $scellee, $engage ],
			'suite 2 réservée'  => [ self::ecrit( $prefixe, 5, "\x02" ), $enc, $scellee, $engage ],
			'segment 4096'      => [ self::ecrit( $prefixe, 24, pack( 'N', 4096 ) ), $enc, $scellee, $engage ],
		];
		foreach ( $essais as $essai => [ $p, $e, $s, $k ] ) {
			try {
				new SegmentHeader( $p, $e, $s, $k );
				self::fail( 'En-tête assemblé à tort : ' . $essai );
			} catch ( \InvalidArgumentException $erreur ) {
				self::assertNotInstanceOf( FormatError::class, $erreur, $essai );
				self::assertStringNotContainsString( bin2hex( $enc ), $erreur->getMessage(), $essai );
			}
		}
	}

	public function test_sous_cles_refusent_une_cle_de_donnees_de_mauvaise_longueur(): void {
		$cas     = VectorsFixture::named( 'segments', 'trois_blocs_dernier_partiel' );
		$dek     = VectorsFixture::bin( $cas['dek'] ?? null );
		$prefixe = substr( VectorsFixture::bin( $cas['header'] ?? null ), 0, 36 );

		foreach ( [ '', substr( $dek, 0, 31 ), $dek . "\x00", bin2hex( $dek ) ] as $autre ) {
			foreach ( [ 'block_key', 'commitment' ] as $methode ) {
				try {
					'block_key' === $methode ? SegmentHeader::block_key( $autre ) : SegmentHeader::commitment( $autre, $prefixe );
					self::fail( $methode . ' a accepté une clé de données de ' . strlen( $autre ) . ' octets.' );
				} catch ( \InvalidArgumentException $erreur ) {
					self::assertStringNotContainsString( bin2hex( $dek ), $erreur->getMessage() );
				}
			}
		}

		// L'engagement porte sur le préfixe (36 octets), jamais sur l'en-tête entier.
		foreach ( [ '', substr( $prefixe, 0, 35 ), $prefixe . "\x00", VectorsFixture::bin( $cas['header'] ?? null ) ] as $autre ) {
			try {
				SegmentHeader::commitment( $dek, $autre );
				self::fail( 'commitment a accepté un préfixe de ' . strlen( $autre ) . ' octets.' );
			} catch ( \InvalidArgumentException $erreur ) {
				self::assertStringNotContainsString( bin2hex( $dek ), $erreur->getMessage() );
			}
		}
	}

	public function test_k_engagement_effacee_sans_sodium_memzero_hors_du_natif(): void {
		// commitment() est statique et n'a pas d'instance de Sodium : il efface k_engagement par sa
		// propre méthode, avec la règle de Sodium::wipe(). Lecture du source : sodium_memzero() n'est
		// appelée que dans wipe_native(), que wipe() choisit par extension_loaded( 'sodium' ), jamais
		// par function_exists() (sans l'extension, le sodium_memzero() global de sodium_compat lève).
		// Les deux branches s'exécutent : suite lancée avec, puis sans l'extension (php -n).
		$memzero    = [];
		$extension  = [];
		$effacement = [];
		foreach ( self::identifiants_du_code() as [ $fonction, $nom ] ) {
			if ( str_contains( strtolower( $nom ), 'memzero' ) ) {
				$memzero[] = $fonction . ':' . $nom;
			}
			if ( in_array( $nom, [ 'extension_loaded', 'function_exists', 'is_callable' ], true ) ) {
				$extension[] = $fonction . ':' . $nom;
			}
			if ( 'wipe' === $nom ) {
				$effacement[] = $fonction;
			}
		}
		self::assertSame( [ 'wipe_native:sodium_memzero' ], $memzero );
		self::assertSame( [ 'wipe:extension_loaded' ], $extension );
		self::assertSame( [ 'commitment' ], $effacement, 'k_engagement effacée par commitment().' );
	}

	// ---------------------------------------------------------------------------------------------
	// Outils.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Noms appelés ou cités dans le code de SegmentHeader.php (commentaires écartés), chacun avec la
	 * méthode qui le contient (dernière fonction nommée déclarée avant).
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function identifiants_du_code(): array {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/Backup/Format/SegmentHeader.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lecture locale du source testé, hors WordPress.
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

	/**
	 * Cas de `segments` qui portent leurs octets (tous sauf `grand_bloc`), par nom.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function segments_avec_octets(): array {
		$segments = [];
		foreach ( VectorsFixture::section( 'segments' ) as $cas ) {
			if ( is_array( $cas ) && isset( $cas['header'] ) ) {
				/** @var array<string, mixed> $cas */
				$segments[ (string) ( $cas['name'] ?? '' ) ] = $cas;
			}
		}
		return $segments;
	}

	/**
	 * Champs du préfixe d'un cas de `segments`, typés.
	 *
	 * @param array<string, mixed> $cas
	 *
	 * @return array{kind: int, backup_id: string, segment_index: int, site_key_version: int, block_size: int}
	 */
	private static function champs( array $cas ): array {
		foreach ( [ 'kind', 'segment_index', 'site_key_version', 'block_size' ] as $cle ) {
			self::assertIsInt( $cas[ $cle ] ?? null, 'Champ ' . $cle . ' du vecteur.' );
		}
		return [
			'kind'             => (int) $cas['kind'],
			'backup_id'        => VectorsFixture::bin( $cas['backup_id'] ?? null ),
			'segment_index'    => (int) $cas['segment_index'],
			'site_key_version' => (int) $cas['site_key_version'],
			'block_size'       => (int) $cas['block_size'],
		];
	}

	/** En-tête du cas `trois_blocs_dernier_partiel`, point de départ des altérations. */
	private static function en_tete_de_base(): string {
		return VectorsFixture::bin( VectorsFixture::named( 'segments', 'trois_blocs_dernier_partiel' )['header'] ?? null );
	}

	/**
	 * Champs valides de prefix_bytes() (ceux de `trois_blocs_dernier_partiel`), avec des remplacements.
	 *
	 * @param array<string, int|string> $remplacements
	 *
	 * @return array{suite: int, kind: int, backup_id: string, segment_index: int, site_key_version: int, block_size: int}
	 */
	private static function champs_valides( array $remplacements = [] ): array {
		$champs = array_merge(
			[ 'suite' => SegmentHeader::SUITE_GCM ],
			self::champs( VectorsFixture::named( 'segments', 'trois_blocs_dernier_partiel' ) ),
			$remplacements
		);
		/** @var array{suite: int, kind: int, backup_id: string, segment_index: int, site_key_version: int, block_size: int} $champs */
		return $champs;
	}

	/**
	 * Préfixe des champs donnés, assemblé avec des valeurs quelconques de bonne longueur, puis lu.
	 *
	 * @param array{suite: int, kind: int, backup_id: string, segment_index: int, site_key_version: int, block_size: int} $champs
	 */
	private static function relu( array $champs ): SegmentHeader {
		$prefixe = SegmentHeader::prefix_bytes( $champs['suite'], $champs['kind'], $champs['backup_id'], $champs['segment_index'], $champs['site_key_version'], $champs['block_size'] );
		$en_tete = new SegmentHeader( $prefixe, str_repeat( "\x01", 32 ), str_repeat( "\x02", 48 ), str_repeat( "\x03", 32 ) );
		return SegmentHeader::parse( $en_tete->encode() );
	}

	/**
	 * prefix_bytes() doit refuser ces champs par une \InvalidArgumentException, sans recopier la
	 * valeur interdite dans le message.
	 *
	 * @param array{suite: int, kind: int, backup_id: string, segment_index: int, site_key_version: int, block_size: int} $champs
	 */
	private static function refus_du_prefixe( array $champs, string $essai, string $absent_du_message = '' ): void {
		try {
			SegmentHeader::prefix_bytes( $champs['suite'], $champs['kind'], $champs['backup_id'], $champs['segment_index'], $champs['site_key_version'], $champs['block_size'] );
			self::fail( 'Préfixe écrit à tort : ' . $essai );
		} catch ( \InvalidArgumentException $erreur ) {
			self::assertNotInstanceOf( FormatError::class, $erreur, $essai );
			if ( '' !== $absent_du_message ) {
				self::assertStringNotContainsString( $absent_du_message, $erreur->getMessage(), $essai );
			}
		}
	}

	/** Code stable du refus de parse() ; échec du test si l'en-tête est accepté. */
	private static function refus( string $octets ): string {
		try {
			SegmentHeader::parse( $octets );
		} catch ( FormatError $erreur ) {
			self::assertSame( $erreur->reason(), $erreur->getMessage(), 'Le message ne porte que le code.' );
			return $erreur->reason();
		}
		self::fail( 'En-tête accepté à tort.' );
	}

	/** $valeur écrite à la place des octets de $octets à partir de $decalage. */
	private static function ecrit( string $octets, int $decalage, string $valeur ): string {
		return substr_replace( $octets, $valeur, $decalage, strlen( $valeur ) );
	}

	/**
	 * Une altération `xor` ou `truncate` de la section `tampering`, appliquée aux octets.
	 *
	 * @param mixed $mutation Objet `mutation` du cas.
	 */
	private static function altere( string $octets, mixed $mutation ): string {
		if ( ! is_array( $mutation ) ) {
			throw new \RuntimeException( 'Altération illisible dans le fichier de vecteurs.' );
		}
		if ( 'xor' === ( $mutation['type'] ?? null ) && is_int( $mutation['offset'] ?? null ) ) {
			$masque = VectorsFixture::bin( $mutation['mask'] ?? null );
			for ( $i = 0, $n = strlen( $masque ); $i < $n; $i++ ) {
				$position            = $mutation['offset'] + $i;
				$octets[ $position ] = chr( ord( $octets[ $position ] ) ^ ord( $masque[ $i ] ) );
			}
			return $octets;
		}
		if ( 'truncate' === ( $mutation['type'] ?? null ) && is_int( $mutation['length'] ?? null ) ) {
			return substr( $octets, 0, $mutation['length'] );
		}
		throw new \RuntimeException( 'Altération sans objet pour la lecture d\'un en-tête (seules xor et truncate y servent).' );
	}
}
