<?php
/**
 * Lecture en flux d'un segment du format chiffré des sauvegardes (G2BK v1) : en-tête de
 * 148 octets, puis blocs AES-256-GCM (docs/backup-format.md § 3, § 4 et § 12, dépôt de la
 * plateforme). Sert aux tests, à la restauration (phase 8) et à prouver le format côté connecteur.
 *
 * open_key() ouvre la clé de données scellée d'un en-tête déjà lu (HPKE, info « g2bk-v1/dek »,
 * aad = préfixe) avec la clé secrète X25519 du destinataire, puis recalcule l'engagement
 * (SegmentHeader::commitment()) et le compare à celui de l'en-tête par hash_equals(). La clé rendue
 * est à effacer par l'appelant dès qu'elle a servi.
 *
 * decrypt() lit un segment entier depuis un flux et passe son clair à $sink, bloc par bloc :
 * - contrôles dans l'ordre du contrat (§ 12) : en-tête (header_truncated en premier, puis
 *   SegmentHeader::parse()), ouverture de la clé (seal_open_failed), engagement
 *   (key_commitment_mismatch), puis chaque bloc (block_auth_failed, truncated) ;
 * - blocs ouverts sous k_bloc = SegmentHeader::block_key( clé de données ), jamais sous la clé de
 *   données, effacée dès que k_bloc est dérivée ; nonce = 00 00 00 00 ‖ i sur 8 octets ; données
 *   associées = empreinte de l'en-tête ‖ i sur 8 octets ‖ 01 pour le dernier bloc, sinon 00 ;
 * - fin du segment lue par les seules longueurs (§ 4, « Lecture »), avec un octet d'avance : un
 *   morceau de block_size + 16 octets suivi d'au moins un octet est un bloc non final ; un morceau
 *   de 16 à block_size + 15 octets, après lequel le flux s'arrête, est le bloc final ; 0 octet,
 *   exactement block_size + 16 octets puis la fin, ou moins de 16 : truncated. Le drapeau de fin
 *   faisant partie des données associées, un bloc final retiré, un bloc non final présenté comme
 *   final ou un octet ajouté après la fin ne s'authentifient pas ;
 * - chaque bloc est authentifié avant d'être passé à $sink : un bloc refusé, et tout ce qui le
 *   suit, n'est jamais livré ; ce qui a été livré avant le refus est à jeter par l'appelant ;
 * - rend l'en-tête lu, le nombre de blocs (bloc final compris) et la taille de l'objet lu (en-tête
 *   compris) : les champs blocks et bytes de l'entrée du segment dans l'index (§ 7.1), comme
 *   SegmentWriter::finish().
 *
 * Auteur d'un segment (§ 4 et § 16) : un segment qui s'ouvre, dont l'engagement est juste et dont
 * tous les blocs s'authentifient n'est pas pour autant celui du site ; qui connaît la clé publique
 * X25519 du site peut en écrire un. Seul son sha256 dans l'index vérifié l'authentifie. La règle
 * « Empreinte avant le clair » (§ 7.5) revient donc à l'appelant qui restaure : il compare d'abord
 * le SHA-256 de l'objet de stockage entier à l'entrée attendue, ou bien $sink écrit dans un tampon
 * qui n'est livré qu'après l'égalité et jeté en cas d'écart.
 *
 * Flux : chaque fread() est repris jusqu'à la longueur voulue (un flux peut rendre moins à chaque
 * lecture). Un fread() qui ne rend rien (fin du flux, erreur de lecture, flux fermé) arrête la
 * lecture : ce qui manque se lit comme une troncature (header_truncated, truncated), refus sûr. Le
 * flux appartient à l'appelant : le lecteur ne le ferme pas et ne le rembobine pas. Mémoire :
 * quelques blocs (le bloc lu, son chiffré découpé par Aead::open(), son clair), quelle que soit la
 * taille du segment.
 *
 * Erreurs :
 * - refus du format → FormatError avec son code stable (ci-dessus) ; OpenSSL ou moteur sodium
 *   absents → FormatError::CRYPTO_UNAVAILABLE ; clé secrète de 32 zéros, que sodium_compat
 *   refuse → FormatError::BAD_PUBLIC_KEY (code de Sodium pour une clé X25519 refusée) ;
 * - entrée qui n'est pas un flux, clé secrète qui n'a pas 32 octets → \InvalidArgumentException ;
 *   decrypt() appelé depuis $sink sur le même lecteur → \LogicException (erreurs de programmation) ;
 * - une exception de $sink remonte telle quelle.
 * Aucune clé ni aucun texte clair dans un message ; aucune exception chaînée.
 *
 * Effacement (rappel transverse n° 3 du plan de la phase 2) : clé de données, k_bloc et clair de
 * chaque bloc après son passage à $sink, en cas d'échec comme de succès, par sodium_memzero() si
 * l'extension sodium est chargée, sinon par écrasement au mieux (PHP ne garantit pas l'effacement,
 * et les copies internes de hash_hmac() et d'OpenSSL ne sont pas atteignables). k_bloc n'est gardée
 * dans l'objet que pendant decrypt(). La clé secrète du destinataire et le clair que $sink garde
 * appartiennent à l'appelant, qui les efface.
 *
 * Classe pure, sans effet au chargement ni à la construction, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Aead;

final class SegmentReader {

	/** Clé de données, 32 octets. */
	private const DATA_KEY_BYTES = 32;

	/** Quatre octets nuls en tête du nonce d'un bloc (suivis du numéro du bloc sur 8 octets). */
	private const NONCE_PREFIX = "\0\0\0\0";

	private const LAST_BLOCK = "\x01";

	private const NOT_LAST_BLOCK = "\x00";

	private Hpke $hpke;

	/** k_bloc, gardée pendant decrypt() seulement, effacée quand decrypt() rend ou lève. */
	private string $block_key = '';

	/** Vrai pendant decrypt() : une lecture imbriquée sur le même lecteur est refusée. */
	private bool $reading = false;

	/**
	 * @param Hpke $hpke Ouverture du scellement de la clé de données.
	 */
	public function __construct( Hpke $hpke ) {
		$this->hpke = $hpke;
	}

	/**
	 * Ouvre la clé de données d'un en-tête et contrôle l'engagement de clé.
	 *
	 * @param SegmentHeader $header           En-tête lu par SegmentHeader::parse().
	 * @param string        $recipient_secret Clé secrète X25519 du destinataire, 32 octets.
	 *
	 * @return string Clé de données, 32 octets, à effacer par l'appelant.
	 *
	 * @throws FormatError               SEAL_OPEN_FAILED : le scellement ne s'ouvre pas ;
	 *                                   KEY_COMMITMENT_MISMATCH : l'engagement recalculé diffère ;
	 *                                   CRYPTO_UNAVAILABLE : OpenSSL ou moteur sodium absents ;
	 *                                   BAD_PUBLIC_KEY : clé secrète refusée par le moteur
	 *                                   (32 zéros, que sodium_compat refuse).
	 * @throws \InvalidArgumentException Clé secrète qui n'a pas 32 octets : erreur de programmation.
	 */
	public function open_key( SegmentHeader $header, string $recipient_secret ): string {
		$data_key = $this->hpke->open( $recipient_secret, $header->enc(), SegmentWriter::DATA_KEY_INFO, $header->prefix(), $header->sealed_dek() );
		if ( null === $data_key ) {
			throw new FormatError( FormatError::SEAL_OPEN_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		if ( self::DATA_KEY_BYTES !== strlen( $data_key ) ) {
			// Impossible avec une clé scellée de 48 octets (32 et l'étiquette), contrôlée par
			// SegmentHeader : refus sûr quand même.
			self::wipe( $data_key );
			throw new FormatError( FormatError::SEAL_OPEN_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		if ( ! hash_equals( SegmentHeader::commitment( $data_key, $header->prefix() ), $header->key_commitment() ) ) {
			self::wipe( $data_key );
			throw new FormatError( FormatError::KEY_COMMITMENT_MISMATCH ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
		}
		return $data_key;
	}

	/**
	 * Lit un segment entier et passe son clair à $sink, un appel par bloc authentifié (le bloc final
	 * compris, éventuellement vide).
	 *
	 * @param resource                $in               Flux d'entrée, ouvert en lecture, au début du segment.
	 * @param string                  $recipient_secret Clé secrète X25519 du destinataire, 32 octets.
	 * @param \Closure( string ): void $sink             Reçoit le clair de chaque bloc, dans l'ordre.
	 *
	 * @return array{header: SegmentHeader, blocks: int, bytes: int} En-tête lu, nombre de blocs (bloc
	 *                                                                final compris) et taille de
	 *                                                                l'objet lu (en-tête compris).
	 *
	 * @throws \InvalidArgumentException Entrée qui n'est pas un flux ; clé secrète de longueur fausse.
	 * @throws \LogicException            Lecture imbriquée sur le même lecteur (appel depuis $sink).
	 * @throws FormatError                Premier refus, dans l'ordre du contrat ; CRYPTO_UNAVAILABLE.
	 */
	public function decrypt( $in, string $recipient_secret, \Closure $sink ): array {
		if ( ! is_resource( $in ) ) {
			throw new \InvalidArgumentException( 'Segment G2BK impossible à lire : l\'entrée doit être un flux ouvert en lecture. Cause probable : erreur de programmation (chemin passé à la place du flux). Action : passer le flux rendu par fopen().' );
		}
		if ( $this->reading ) {
			throw new \LogicException( 'Segment G2BK : decrypt() appelé pendant une lecture du même lecteur. Cause probable : erreur de programmation (lecture lancée depuis la fermeture qui reçoit le clair). Action : terminer la lecture en cours, ou employer un autre lecteur.' );
		}
		$this->reading = true;
		try {
			$bytes = '';
			self::fill( $in, $bytes, SegmentHeader::BYTES );
			$header   = SegmentHeader::parse( $bytes );
			$data_key = $this->open_key( $header, $recipient_secret );
			try {
				$this->block_key = SegmentHeader::block_key( $data_key );
			} finally {
				self::wipe( $data_key );
			}
			[ $blocks, $read ] = $this->read_blocks( $in, $header, $sink );
			return [
				'header' => $header,
				'blocks' => $blocks,
				'bytes'  => SegmentHeader::BYTES + $read,
			];
		} finally {
			self::wipe( $this->block_key );
			$this->reading = false;
		}
	}

	/**
	 * Lit, authentifie et livre les blocs, jusqu'au bloc final.
	 *
	 * @param resource                $in     Flux d'entrée, placé après l'en-tête.
	 * @param SegmentHeader           $header En-tête du segment.
	 * @param \Closure( string ): void $sink   Reçoit le clair de chaque bloc.
	 *
	 * @return array{0: int, 1: int} Nombre de blocs, octets lus après l'en-tête.
	 *
	 * @throws FormatError TRUNCATED ; BLOCK_AUTH_FAILED ; CRYPTO_UNAVAILABLE.
	 */
	private function read_blocks( $in, SegmentHeader $header, \Closure $sink ): array {
		$step        = $header->block_size() + Aead::TAG_BYTES;
		$header_hash = $header->hash();
		$ahead       = '';
		$read        = 0;
		for ( $index = 0; ; ++$index ) {
			// Le bloc commence par l'octet d'avance lu au tour précédent.
			$block = $ahead;
			$ahead = '';
			self::fill( $in, $block, $step );
			$length = strlen( $block );
			if ( $step === $length ) {
				self::fill( $in, $ahead, 1 );
				if ( '' === $ahead ) {
					// Exactement block_size + 16 octets, puis la fin : le bloc final manque.
					throw new FormatError( FormatError::TRUNCATED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
				}
				$last = false;
			} elseif ( $length >= Aead::TAG_BYTES ) {
				$last = true;
			} else {
				// Moins de 16 octets (0 compris) avant la fin : ni bloc final ni étiquette entière.
				throw new FormatError( FormatError::TRUNCATED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
			}

			$number    = pack( 'J', $index );
			$plaintext = Aead::open(
				$this->block_key,
				self::NONCE_PREFIX . $number,
				$header_hash . $number . ( $last ? self::LAST_BLOCK : self::NOT_LAST_BLOCK ),
				$block
			);
			if ( null === $plaintext ) {
				throw new FormatError( FormatError::BLOCK_AUTH_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
			}
			try {
				$sink( $plaintext );
			} finally {
				self::wipe( $plaintext );
			}
			$read += $length;
			if ( $last ) {
				return [ $index + 1, $read ];
			}
		}
	}

	/**
	 * Ajoute à $buffer les octets lus dans le flux, jusqu'à ce qu'il en ait $length, ou jusqu'à un
	 * fread() qui ne rend rien (fin du flux, erreur de lecture) ou un flux fermé.
	 *
	 * @param resource $in     Flux d'entrée.
	 * @param string   $buffer Octets déjà lus, complétés en place.
	 * @param int      $length Taille voulue de $buffer, au moins 1.
	 */
	private static function fill( $in, string &$buffer, int $length ): void {
		$missing = $length - strlen( $buffer );
		while ( $missing > 0 && is_resource( $in ) ) {
			$part = fread( $in, $missing ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- flux du segment ; WP_Filesystem n'a pas d'API de flux.
			if ( false === $part || '' === $part ) {
				return;
			}
			$buffer  .= $part;
			$missing -= strlen( $part );
		}
	}

	/**
	 * Efface des octets secrets ; ne lève jamais.
	 *
	 * Même règle que Sodium::wipe(), SegmentHeader et SegmentWriter : le lecteur ne reçoit pas
	 * d'instance de Sodium (API du plan). sodium_memzero() si l'extension sodium est chargée, sinon
	 * écrasement par des zéros de même longueur (PHP ne garantit pas l'effacement : l'ancienne chaîne
	 * peut rester en mémoire jusqu'à sa réutilisation). Choix par extension_loaded( 'sodium' ), jamais
	 * par function_exists() : sans l'extension, le sodium_memzero() global que définit sodium_compat
	 * lève une SodiumException. sodium_memzero() n'écrase en place qu'une chaîne non partagée : le
	 * clair d'un bloc que $sink garde tel quel n'est pas touché, seule la variable du lecteur est
	 * vidée.
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	private static function wipe( string &$bytes ): void {
		if ( extension_loaded( 'sodium' ) ) {
			self::wipe_native( $bytes );
			return;
		}
		$bytes = str_repeat( "\0", strlen( $bytes ) );
	}

	/**
	 * Effacement natif, seule méthode de la classe qui appelle sodium_memzero(). sodium_memzero()
	 * laisse la variable à null : elle est remise à une chaîne vide, pour garder le type.
	 *
	 * @param string $bytes Octets à effacer, remplacés en place.
	 */
	private static function wipe_native( string &$bytes ): void {
		sodium_memzero( $bytes );
		$bytes = '';
	}
}
