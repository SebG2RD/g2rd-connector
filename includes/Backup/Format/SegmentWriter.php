<?php
/**
 * Écriture en flux d'un segment du format chiffré des sauvegardes (G2BK v1) : en-tête de
 * 148 octets, puis blocs AES-256-GCM (docs/backup-format.md § 3 et § 4, dépôt de la plateforme).
 *
 * Usage, dans cet ordre et une seule fois : begin() (en-tête), write() autant de fois qu'il le faut
 * (clair, découpé comme on veut), finish() (bloc final, empreinte). Un autre ordre lève une
 * \LogicException. Après finish(), ou après toute exception de begin(), write() ou finish(),
 * l'écrivain est inutilisable : un segment interrompu est jeté et refait avec un nouvel écrivain,
 * donc une clé de données neuve (§ 4).
 *
 * begin() :
 * - tire la clé de données (32 octets) par $random( 32 ), puis Hpke::seal() tire la clé éphémère :
 *   ordre des tirages du contrat (§ 4), qui permet de reproduire les vecteurs avec une seule
 *   fermeture partagée par l'écrivain et Hpke ;
 * - scelle la clé de données vers la clé publique X25519 du site (HPKE, info « g2bk-v1/dek »,
 *   aad = préfixe de l'en-tête), calcule l'engagement (SegmentHeader::commitment()) et dérive k_bloc
 *   (SegmentHeader::block_key()) ;
 * - efface la clé de données aussitôt : elle ne sert qu'à ces trois calculs, n'est jamais écrite ni
 *   gardée dans une propriété (elle est donc effacée bien avant finish()) ;
 * - écrit l'en-tête.
 * L'écrivain ne reçoit que la clé publique du site : rien de ce qu'il garde ne rouvre la clé de
 * données (§ 9 de la spécification : le site ne détient jamais une clé capable de relire une
 * sauvegarde passée).
 *
 * Blocs (§ 4) : bloc n° i chiffré sous k_bloc, jamais sous la clé de données ; nonce = 00 00 00 00 ‖
 * i sur 8 octets ; données associées = empreinte de l'en-tête ‖ i sur 8 octets ‖ 01 pour le dernier
 * bloc, sinon 00. Le dernier bloc a strictement moins de block_size octets (0 permis) : un bloc
 * plein n'est donc jamais le dernier, et write() l'émet dès qu'il est complet. Le tampon garde
 * toujours moins d'un bloc ; un morceau reçu est découpé par décalages (substr), sans recopier le
 * reste du tampon à chaque bloc. Mémoire : quelques blocs (tampon, bloc chiffré par OpenSSL, sa
 * copie avec l'étiquette), plus le morceau reçu, quelle que soit la taille du segment ; mesuré le
 * 24/10/2026 (PHP 8.4.3, sodium natif, 64 Mio écrits en blocs de 8 Mio) : pic en hausse de 30 Mio,
 * débit de 390 à 490 Mio/s, que les morceaux reçus fassent 8 Kio ou 1 Mio. Numéro de bloc sur
 * 8 octets par pack( 'J' ) : PHP 64 bits (en 32 bits, l'autotest échoue déjà, et aucune sauvegarde
 * n'y tourne).
 *
 * finish() écrit le bloc final (de 0 à block_size − 1 octets), efface k_bloc et le tampon, et rend
 * les champs de l'entrée du segment dans l'index (§ 7.1) : bytes (taille de l'objet écrit, en-tête
 * compris), blocks (bloc final compris) et sha256, empreinte SHA-256 incrémentale (hash_init()) de
 * tous les octets écrits, en 32 octets bruts (64 caractères hexadécimaux dans l'index).
 *
 * Écriture : chaque fwrite() est vérifié et repris jusqu'au dernier octet ; une écriture de 0 octet,
 * un échec (false) ou un flux fermé lèvent FormatError::WRITE_FAILED. Le flux appartient à
 * l'appelant : l'écrivain ne le ferme pas, et ce qui a déjà été écrit d'un segment en échec est à
 * jeter.
 *
 * Erreurs :
 * - suite autre que SegmentHeader::SUITE_GCM → FormatError::UNSUPPORTED_SUITE (la suite 2,
 *   secretstream, n'est pas retenue en v1) ;
 * - clé publique du site refusée (longueur, échange de 32 zéros) → FormatError::BAD_PUBLIC_KEY ;
 * - source d'aléa en panne, OpenSSL ou moteur sodium absents → FormatError::CRYPTO_UNAVAILABLE ;
 * - champ hors du contrat (nature, identifiant, index de segment au-delà du 4 096e, version de clé,
 *   taille de bloc) ou sortie qui n'est pas un flux → \InvalidArgumentException ; tirage injecté qui
 *   n'a pas 32 octets → \UnexpectedValueException (erreurs de programmation) ;
 * - mauvais ordre d'appel → \LogicException (un appel refusé avant begin() ne change rien ; après
 *   finish() ou après un échec, tout appel est refusé).
 * Ordre de begin() : flux, suite et champs de l'en-tête sont contrôlés avant tout tirage ; la clé
 * publique du site l'est au scellement, après les deux tirages ; rien n'est écrit avant que
 * l'en-tête soit complet. Aucune clé ni aucun texte clair dans un message ; aucune exception
 * chaînée.
 *
 * Effacement (rappel transverse n° 3 du plan de la phase 2) : clé de données, k_bloc et tampon, par
 * sodium_memzero() si l'extension sodium est chargée, sinon par écrasement au mieux (PHP ne garantit
 * pas l'effacement, et les copies internes de hash_hmac() et d'OpenSSL ne sont pas atteignables).
 * Le clair reçu par write() appartient à l'appelant, qui l'efface.
 *
 * Classe pure, sans effet au chargement ni à la construction, sans fonction WordPress.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Format;

use G2RD\Connector\Backup\Crypto\Aead;

final class SegmentWriter {

	/** Info HPKE du scellement de la clé de données d'un segment (§ 6). */
	public const DATA_KEY_INFO = 'g2bk-v1/dek';

	/** Clé de données, 32 octets aléatoires par segment. */
	private const DATA_KEY_BYTES = 32;

	/** Quatre octets nuls en tête du nonce d'un bloc (suivis du numéro du bloc sur 8 octets). */
	private const NONCE_PREFIX = "\0\0\0\0";

	private const LAST_BLOCK = "\x01";

	private const NOT_LAST_BLOCK = "\x00";

	/** Avant begin(). */
	private const STATE_NEW = 0;

	/** Entre begin() et finish(). */
	private const STATE_WRITING = 1;

	/** Après finish() ou après une exception : inutilisable. */
	private const STATE_CLOSED = 2;

	private Hpke $hpke;

	/**
	 * Source d'aléa : reçoit un nombre d'octets, rend autant d'octets aléatoires.
	 *
	 * @var \Closure( int ): string
	 */
	private \Closure $random;

	private int $state = self::STATE_NEW;

	/**
	 * Flux de sortie, à l'appelant.
	 *
	 * @var resource|null
	 */
	private $out = null;

	private int $block_size = 0;

	/** Empreinte de l'en-tête (32 octets) : données associées de chaque bloc. */
	private string $header_hash = '';

	/** k_bloc, effacée par finish() ou à la première exception. */
	private string $block_key = '';

	/** Clair en attente, toujours moins d'un bloc. */
	private string $buffer = '';

	/** SHA-256 incrémental de tout ce qui est écrit. */
	private ?\HashContext $hash = null;

	/** Blocs écrits : c'est aussi le numéro du prochain bloc. */
	private int $blocks = 0;

	/** Octets écrits, en-tête compris. */
	private int $written = 0;

	/**
	 * @param Hpke                            $hpke   Scellement de la clé de données ; il tire lui-même la clé éphémère.
	 * @param ( \Closure( int ): string )|null $random Source d'aléa de la clé de données, pour les tests (vecteurs : la
	 *                                                même fermeture que Hpke) ; par défaut random_bytes().
	 */
	public function __construct( Hpke $hpke, ?\Closure $random = null ) {
		$this->hpke   = $hpke;
		$this->random = $random ?? static function ( int $length ): string {
			/** @var int<1, max> $length Seul appelant : la clé de données, DATA_KEY_BYTES (32). */
			return random_bytes( $length );
		};
	}

	/**
	 * Commence le segment : tire et scelle la clé de données, dérive k_bloc, écrit l'en-tête.
	 *
	 * @param resource $out              Flux de sortie, ouvert en écriture, à l'appelant.
	 * @param int      $suite            SegmentHeader::SUITE_GCM, seule suite de la v1.
	 * @param string   $backup_id        Identifiant de la sauvegarde, 16 octets bruts.
	 * @param int      $segment_index    Index du segment, de 0 à SegmentHeader::MAX_SEGMENTS − 1.
	 * @param int      $kind             SegmentHeader::KIND_DB, KIND_FILES ou KIND_MANIFEST.
	 * @param int      $site_key_version Version de la clé X25519 du site, de 1 à 2³² − 1.
	 * @param int      $block_size       Taille en clair d'un bloc, de 16 à 67 108 864 octets.
	 * @param string   $site_public_key  Clé publique X25519 du site, 32 octets : la seule clé reçue.
	 *
	 * @throws \LogicException            begin() déjà appelé, ou écrivain terminé ou en échec.
	 * @throws \InvalidArgumentException  Sortie qui n'est pas un flux, ou champ hors du contrat.
	 * @throws FormatError                UNSUPPORTED_SUITE ; BAD_PUBLIC_KEY ; CRYPTO_UNAVAILABLE ;
	 *                                    WRITE_FAILED (en-tête non écrit en entier).
	 * @throws \UnexpectedValueException Tirage injecté qui n'a pas 32 octets.
	 */
	public function begin( $out, int $suite, string $backup_id, int $segment_index, int $kind, int $site_key_version, int $block_size, string $site_public_key ): void {
		if ( self::STATE_NEW !== $this->state ) {
			throw new \LogicException( 'Segment G2BK : begin() ne s\'appelle qu\'une fois, sur un écrivain neuf. Cause probable : écrivain réutilisé pour un second segment, ou après un échec. Action : créer un nouvel écrivain pour chaque segment ; un segment interrompu est jeté et refait.' );
		}
		// Toute exception d'ici à la fin laisse l'écrivain inutilisable.
		$this->state = self::STATE_CLOSED;
		try {
			if ( ! is_resource( $out ) ) {
				throw new \InvalidArgumentException( 'Segment G2BK impossible à commencer : la sortie doit être un flux ouvert en écriture. Cause probable : erreur de programmation (chemin passé à la place du flux). Action : passer le flux rendu par fopen().' );
			}
			if ( SegmentHeader::SUITE_GCM !== $suite ) {
				throw new FormatError( FormatError::UNSUPPORTED_SUITE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
			}
			$prefix = SegmentHeader::prefix_bytes( $suite, $kind, $backup_id, $segment_index, $site_key_version, $block_size );
			$header = $this->seal_data_key( $prefix, $site_public_key );

			$this->out         = $out;
			$this->block_size  = $block_size;
			$this->header_hash = $header->hash();
			$this->hash        = hash_init( 'sha256' );
			$this->write_all( $header->encode() );
		} catch ( \Throwable $e ) {
			$this->close();
			throw $e;
		}
		$this->state = self::STATE_WRITING;
	}

	/**
	 * Ajoute du clair au segment : chaque bloc plein est chiffré et écrit dès qu'il est complet, le
	 * reste attend le prochain appel ou finish().
	 *
	 * @param string $data Clair, de toute longueur ('' permis : rien ne change).
	 *
	 * @throws \LogicException Avant begin(), après finish() ou après un échec.
	 * @throws FormatError     WRITE_FAILED ; CRYPTO_UNAVAILABLE (OpenSSL en échec).
	 */
	public function write( string $data ): void {
		$this->assert_writing( 'write()' );
		$length = strlen( $data );
		try {
			$offset  = 0;
			$pending = strlen( $this->buffer );
			if ( $pending > 0 ) {
				$missing = $this->block_size - $pending;
				if ( $length < $missing ) {
					$this->buffer .= $data;
					return;
				}
				$this->buffer .= substr( $data, 0, $missing );
				$this->emit( $this->buffer, false );
				$this->buffer = '';
				$offset       = $missing;
			}
			while ( $length - $offset >= $this->block_size ) {
				$this->emit( substr( $data, $offset, $this->block_size ), false );
				$offset += $this->block_size;
			}
			if ( $offset < $length ) {
				$this->buffer = substr( $data, $offset );
			}
		} catch ( \Throwable $e ) {
			$this->close();
			throw $e;
		}
	}

	/**
	 * Termine le segment : écrit le bloc final, efface k_bloc et le tampon. L'écrivain est ensuite
	 * inutilisable.
	 *
	 * @return array{bytes: int, blocks: int, sha256: string} Champs de l'entrée du segment dans
	 *                                                        l'index : taille écrite (en-tête
	 *                                                        compris), nombre de blocs (bloc final
	 *                                                        compris), SHA-256 brut (32 octets) de
	 *                                                        tout ce qui est écrit.
	 *
	 * @throws \LogicException Avant begin(), après finish() ou après un échec.
	 * @throws FormatError     WRITE_FAILED ; CRYPTO_UNAVAILABLE (OpenSSL en échec).
	 */
	public function finish(): array {
		$this->assert_writing( 'finish()' );
		try {
			$this->emit( $this->buffer, true );
			$hash = $this->hash;
			if ( null === $hash ) {
				throw new \LogicException( 'Segment G2BK : empreinte absente pendant l\'écriture. Cause probable : erreur de programmation dans l\'écrivain. Action : signaler l\'anomalie ; jeter le segment.' );
			}
			$this->hash = null;
			return [
				'bytes'  => $this->written,
				'blocks' => $this->blocks,
				'sha256' => hash_final( $hash, true ),
			];
		} finally {
			$this->close();
		}
	}

	/**
	 * Tire la clé de données, la scelle, calcule l'engagement et dérive k_bloc (gardée dans
	 * $this->block_key), puis efface la clé de données, en cas d'échec comme de succès.
	 *
	 * @param string $prefix          Préfixe de l'en-tête, contrôlé par SegmentHeader::prefix_bytes().
	 * @param string $site_public_key Clé publique X25519 du site.
	 *
	 * @throws FormatError               BAD_PUBLIC_KEY ; CRYPTO_UNAVAILABLE.
	 * @throws \UnexpectedValueException Tirage injecté qui n'a pas 32 octets.
	 */
	private function seal_data_key( string $prefix, string $site_public_key ): SegmentHeader {
		$data_key = $this->draw_data_key();
		try {
			$sealed          = $this->hpke->seal( $site_public_key, self::DATA_KEY_INFO, $prefix, $data_key );
			$header          = new SegmentHeader( $prefix, $sealed['enc'], $sealed['ciphertext'], SegmentHeader::commitment( $data_key, $prefix ) );
			$this->block_key = SegmentHeader::block_key( $data_key );
			return $header;
		} finally {
			self::wipe( $data_key );
		}
	}

	/**
	 * Clé de données, tirée par la source d'aléa.
	 *
	 * @throws FormatError               CRYPTO_UNAVAILABLE : la source d'aléa lève (random_bytes()
	 *                                   sans source d'aléa sûre).
	 * @throws \UnexpectedValueException Tirage qui n'a pas 32 octets.
	 */
	private function draw_data_key(): string {
		try {
			$data_key = ( $this->random )( self::DATA_KEY_BYTES );
		} catch ( \Exception ) {
			throw new FormatError( FormatError::CRYPTO_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), sans le message d'origine.
		}
		if ( self::DATA_KEY_BYTES !== strlen( $data_key ) ) {
			self::wipe( $data_key );
			throw new \UnexpectedValueException( 'Segment G2BK impossible à commencer : la source d\'aléa doit rendre 32 octets pour la clé de données. Cause probable : erreur de programmation (fermeture d\'aléa injectée fausse). Action : passer une fermeture qui rend random_bytes( $longueur ), ou aucune.' );
		}
		return $data_key;
	}

	/**
	 * Chiffre un bloc sous k_bloc et l'écrit.
	 *
	 * @param string $plaintext Clair du bloc : block_size octets, ou moins pour le dernier.
	 * @param bool   $last      Dernier bloc du segment.
	 *
	 * @throws FormatError WRITE_FAILED ; CRYPTO_UNAVAILABLE.
	 */
	private function emit( string $plaintext, bool $last ): void {
		$number = pack( 'J', $this->blocks );
		$this->write_all(
			Aead::seal(
				$this->block_key,
				self::NONCE_PREFIX . $number,
				$this->header_hash . $number . ( $last ? self::LAST_BLOCK : self::NOT_LAST_BLOCK ),
				$plaintext
			)
		);
		++$this->blocks;
	}

	/**
	 * Écrit tous les octets dans le flux, en reprenant une écriture partielle, puis les ajoute à
	 * l'empreinte.
	 *
	 * @throws FormatError WRITE_FAILED : écriture de 0 octet, échec, ou flux fermé.
	 */
	private function write_all( string $bytes ): void {
		$length = strlen( $bytes );
		$done   = 0;
		while ( $done < $length ) {
			if ( ! is_resource( $this->out ) ) {
				throw new FormatError( FormatError::WRITE_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
			}
			$count = fwrite( $this->out, 0 === $done ? $bytes : substr( $bytes, $done ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- flux du segment ; WP_Filesystem n'a pas d'API de flux.
			if ( false === $count || 0 === $count ) {
				throw new FormatError( FormatError::WRITE_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- code stable du format (constante), aucune donnée reçue.
			}
			$done += $count;
		}
		if ( null !== $this->hash ) {
			hash_update( $this->hash, $bytes );
		}
		$this->written += $length;
	}

	/**
	 * @throws \LogicException Hors de l'écriture (avant begin(), après finish() ou après un échec).
	 */
	private function assert_writing( string $method ): void {
		if ( self::STATE_NEW === $this->state ) {
			throw new \LogicException( 'Segment G2BK : ' . $method . ' avant begin(). Cause probable : erreur de programmation (ordre des appels). Action : appeler begin(), puis write(), puis finish().' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- nom de méthode écrit dans le code, aucune donnée reçue.
		}
		if ( self::STATE_WRITING !== $this->state ) {
			throw new \LogicException( 'Segment G2BK : ' . $method . ' sur un écrivain terminé ou en échec. Cause probable : finish() déjà appelé, ou exception précédente. Action : créer un nouvel écrivain pour chaque segment ; un segment interrompu est jeté et refait.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- nom de méthode écrit dans le code, aucune donnée reçue.
		}
	}

	/**
	 * Rend l'écrivain inutilisable : efface k_bloc et le tampon, lâche le flux et l'empreinte.
	 * Ne lève jamais.
	 */
	private function close(): void {
		$this->state = self::STATE_CLOSED;
		self::wipe( $this->block_key );
		self::wipe( $this->buffer );
		$this->out  = null;
		$this->hash = null;
	}

	/**
	 * Efface des octets secrets ; ne lève jamais.
	 *
	 * Même règle que Sodium::wipe() et que SegmentHeader : l'écrivain ne reçoit pas d'instance de
	 * Sodium (API du plan). sodium_memzero() si l'extension sodium est chargée, sinon écrasement par
	 * des zéros de même longueur (PHP ne garantit pas l'effacement : l'ancienne chaîne peut rester
	 * en mémoire jusqu'à sa réutilisation). Choix par extension_loaded( 'sodium' ), jamais par
	 * function_exists() : sans l'extension, le sodium_memzero() global que définit sodium_compat
	 * lève une SodiumException. sodium_memzero() n'écrase en place qu'une chaîne non partagée : une
	 * chaîne encore tenue ailleurs (clair de l'appelant gardé tel quel dans le tampon) n'est pas
	 * touchée, seule la propriété est vidée.
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
