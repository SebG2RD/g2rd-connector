<?php
/**
 * Envoi d'une partie de sauvegarde vers une URL présignée (S3 `UploadPart`).
 *
 * La plateforme ouvre l'envoi en plusieurs parties et signe une URL par partie
 * (`PresignedPart` : URL, hôte et port que le site doit exiger) ; le site lit la
 * partie dans son fichier et la dépose par un `PUT` vers cette URL. Le stockage
 * vérifie l'intégrité de chaque partie grâce à l'en-tête `Content-MD5`, toujours
 * envoyé (le verrou d'objets l'exige chez certains fournisseurs).
 *
 * Garde-fous :
 *  - hôte ET port de l'URL imposés par l'appelant, vérifiés avant tout envoi : une
 *    URL qui viserait un autre hôte, ou le même hôte sur un autre port, n'est jamais
 *    appelée ;
 *  - `https` seulement (sauf `allow_insecure_http`, réservé au banc local), aucune
 *    redirection suivie, `reject_unsafe_urls` hors banc, réponse lue sur 64 Kio au plus ;
 *  - lecture bornée à la partie demandée (64 Mio au plus) ;
 *  - l'URL signée n'apparaît dans aucun résultat ni aucune exception.
 *
 * Une URL échue se reconnaît à l'horloge du STOCKAGE (en-tête `Date` d'une réponse
 * 403 comparé à `X-Amz-Date` + `X-Amz-Expires`), jamais à celle du site.
 *
 * Non branché avant la phase 4 : aucun appelant, aucun réglage, aucune commande.
 * Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Upload;

final class PartUploader {

	/** Taille maximale d'une partie : 64 Mio (borne, pas taille conseillée). */
	public const MAX_PART_BYTES = 67108864;

	public const MIN_TIMEOUT_SECONDS = 5;

	public const MAX_TIMEOUT_SECONDS = 120;

	/** Corps de réponse lu au plus (une erreur S3 tient en quelques centaines d'octets). */
	public const MAX_RESPONSE_BYTES = 65536;

	/** Port par défaut de chaque schéma accepté : un port explicite égal vaut « aucun port ». */
	private const DEFAULT_PORTS = [
		'https' => 443,
		'http'  => 80,
	];

	/** ETag d'une partie : ASCII imprimable sans espace, guillemets compris (même borne que la plateforme). */
	private const ETAG_PATTERN = '/^[\x21-\x7E]{1,130}$/D';

	/** Code d'erreur S3 retenu : lettres seulement, 64 au plus. */
	private const STORAGE_CODE_PATTERN = '/^[A-Za-z]{1,64}$/D';

	/**
	 * Forme exacte d'une date HTTP (RFC 7231, IMF-fixdate), toujours en GMT. Écrite en
	 * clair : DATE_RFC7231, qui vaut la même chose, est obsolète depuis PHP 8.5
	 * (E_DEPRECATED sur les sites passés en 8.5).
	 */
	private const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

	/**
	 * @param bool $force_ipv4          IPv4 forcée vers l'hôte de stockage : signature du contrat,
	 *                                  sans effet tant que la tâche 19 du plan de la phase 3 ne l'a
	 *                                  pas branchée (aucun appelant d'ici là).
	 * @param bool $allow_insecure_http Accepte `http` et désactive `reject_unsafe_urls` : banc local seulement.
	 */
	public function __construct(
		bool $force_ipv4 = false,
		private readonly bool $allow_insecure_http = false,
	) {
		unset( $force_ipv4 );
	}

	/**
	 * Lit `$length` octets de `$file` à partir de `$offset` et les dépose par un `PUT`
	 * vers l'URL présignée.
	 *
	 * @param string   $url             URL présignée (`PresignedPart::$url`).
	 * @param string   $expected_host   Hôte exigé, sans port (`PresignedPart::$host`).
	 * @param int|null $expected_port   Port exigé, null pour le port par défaut du schéma (`PresignedPart::$port`).
	 * @param string   $file            Fichier local qui contient la partie.
	 * @param int      $offset          Position du premier octet de la partie, ≥ 0.
	 * @param int      $length          Taille de la partie, de 1 à MAX_PART_BYTES.
	 * @param int      $timeout_seconds Délai de la requête, de 5 à 120 s.
	 *
	 * @throws \InvalidArgumentException Borne dépassée (erreur de programmation) : aucune lecture, aucune requête.
	 */
	public function upload( string $url, string $expected_host, ?int $expected_port, string $file, int $offset, int $length, int $timeout_seconds ): PartUploadResult {
		self::check_bounds( $expected_port, $offset, $length, $timeout_seconds );

		$expires_at = $this->accepted_url_expiry( $url, $expected_host, $expected_port );
		if ( null === $expires_at ) {
			return new PartUploadResult( PartUploadResult::BAD_URL, null, 0, null, '', 0 );
		}

		$bytes = self::read_part( $file, $offset, $length );
		if ( null === $bytes ) {
			return new PartUploadResult( PartUploadResult::READ_FAILED, null, 0, null, '', 0 );
		}
		$content_md5 = base64_encode( md5( $bytes, true ) );

		$started  = microtime( true );
		$response = wp_remote_request(
			$url,
			[
				'method'              => 'PUT',
				'body'                => $bytes,
				'headers'             => [
					'Content-MD5'  => $content_md5,
					'Content-Type' => 'application/octet-stream',
				],
				'timeout'             => $timeout_seconds,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => ! $this->allow_insecure_http,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
			]
		);
		$elapsed_ms = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
		// La partie (jusqu'à 64 Mio) n'a plus d'usage : libérée avant de lire la réponse.
		unset( $bytes );

		return self::interpret( $response, $expires_at, $content_md5, $elapsed_ms );
	}

	/**
	 * @throws \InvalidArgumentException Une borne est dépassée.
	 */
	private static function check_bounds( ?int $expected_port, int $offset, int $length, int $timeout_seconds ): void {
		if ( $length < 1 || $length > self::MAX_PART_BYTES ) {
			throw new \InvalidArgumentException( 'Taille de partie hors bornes : de 1 à 67108864 octets.' );
		}
		if ( $timeout_seconds < self::MIN_TIMEOUT_SECONDS || $timeout_seconds > self::MAX_TIMEOUT_SECONDS ) {
			throw new \InvalidArgumentException( 'Délai d\'envoi de partie hors bornes : de 5 à 120 secondes.' );
		}
		if ( $offset < 0 ) {
			throw new \InvalidArgumentException( 'Décalage de partie négatif.' );
		}
		if ( null !== $expected_port && ( $expected_port < 1 || $expected_port > 65535 ) ) {
			throw new \InvalidArgumentException( 'Port attendu hors bornes : de 1 à 65535, ou null.' );
		}
	}

	/**
	 * Échéance de l'URL (horodatage Unix : `X-Amz-Date` + `X-Amz-Expires`) si l'URL est
	 * acceptable, null sinon (`bad_url`, rien n'est envoyé).
	 *
	 * Acceptable : ASCII imprimable, schéma `https` (ou `http` au banc), ni identifiants
	 * ni fragment, hôte égal à l'hôte annoncé (sans tenir compte de la casse), port égal
	 * au port annoncé (un port explicite égal au port par défaut du schéma vaut null, des
	 * deux côtés), paramètres `X-Amz-Signature`, `X-Amz-Date` et `X-Amz-Expires` présents
	 * et bien formés.
	 */
	private function accepted_url_expiry( string $url, string $expected_host, ?int $expected_port ): ?int {
		// Une URL présignée est encodée en pourcentage : tout autre caractère (espace,
		// contrôle, non-ASCII) peut être lu autrement par cURL que par wp_parse_url().
		if ( 1 !== preg_match( '/^[\x21-\x7E]+$/D', $url ) || str_contains( $url, '#' ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $this->allow_insecure_http ) ) {
			return null;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || strtolower( $expected_host ) !== $host ) {
			return null;
		}

		$url_port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( self::normalized_port( $url_port, $scheme ) !== self::normalized_port( $expected_port, $scheme ) ) {
			return null;
		}

		$query = [];
		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		$signature = $query['X-Amz-Signature'] ?? null;
		$signed_at = $query['X-Amz-Date'] ?? null;
		$validity  = $query['X-Amz-Expires'] ?? null;
		if ( ! is_string( $signature ) || '' === $signature || ! is_string( $signed_at ) || ! is_string( $validity ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,6}$/D', $validity ) ) {
			return null;
		}
		$signed_timestamp = self::parse_utc( '!Ymd\THis\Z', 'Ymd\THis\Z', $signed_at );
		if ( null === $signed_timestamp ) {
			return null;
		}

		return $signed_timestamp + (int) $validity;
	}

	private static function normalized_port( ?int $port, string $scheme ): ?int {
		return ( null === $port || self::DEFAULT_PORTS[ $scheme ] === $port ) ? null : $port;
	}

	/**
	 * Les `$length` octets de `$file` à partir de `$offset`, exactement, ou null si le
	 * fichier est absent, illisible ou trop court.
	 */
	private static function read_part( string $file, int $offset, int $length ): ?string {
		if ( '' === $file || str_contains( $file, "\0" ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$size = filesize( $file );
		if ( false === $size || $offset > $size - $length ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lecture bornée d'une partie ; WP_Filesystem n'a pas d'API de lecture par décalage.
		$handle = fopen( $file, 'rb' );
		if ( false === $handle ) {
			return null;
		}

		$bytes = '';
		try {
			// fseek n'a pas de code d'erreur propre dans ce sniff : l'ignore vise le sniff
			// entier, sur cette seule ligne, sans masquer les autres sniffs.
			// phpcs:ignore WordPress.WP.AlternativeFunctions -- lecture bornée d'une partie ; WP_Filesystem n'a pas d'API de lecture par décalage.
			if ( 0 !== fseek( $handle, $offset ) ) {
				return null;
			}
			$remaining = $length;
			while ( $remaining > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- lecture bornée d'une partie ; WP_Filesystem n'a pas d'API de lecture par décalage.
				$chunk = fread( $handle, $remaining );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$bytes     .= $chunk;
				$remaining -= strlen( $chunk );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- lecture bornée d'une partie ; WP_Filesystem n'a pas d'API de lecture par décalage.
			fclose( $handle );
		}

		return strlen( $bytes ) === $length ? $bytes : null;
	}

	/**
	 * Issue d'une réponse de wp_remote_request().
	 *
	 * @param array<string, mixed>|\WP_Error $response   Réponse ou erreur de WordPress.
	 * @param int                            $expires_at Échéance de l'URL (horodatage Unix).
	 */
	private static function interpret( $response, int $expires_at, string $content_md5, int $elapsed_ms ): PartUploadResult {
		if ( is_wp_error( $response ) ) {
			if ( 'http_request_not_executed' === $response->get_error_code() ) {
				$outcome = PartUploadResult::BAD_URL;
			} elseif ( 1 === preg_match( '/\bcURL error 28\b/', $response->get_error_message() ) ) {
				$outcome = PartUploadResult::TOO_SLOW;
			} else {
				$outcome = PartUploadResult::NETWORK;
			}
			// Le message de l'erreur recopie souvent l'URL : il n'est jamais gardé.
			return new PartUploadResult( $outcome, null, 0, null, $content_md5, $elapsed_ms );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			$etag = wp_remote_retrieve_header( $response, 'etag' );
			if ( is_string( $etag ) && 1 === preg_match( self::ETAG_PATTERN, $etag ) ) {
				return new PartUploadResult( PartUploadResult::UPLOADED, $etag, $status, null, $content_md5, $elapsed_ms );
			}
			$outcome = PartUploadResult::REFUSED;
		} elseif ( 403 === $status ) {
			$storage_now = self::storage_date( $response );
			$outcome     = ( null !== $storage_now && $storage_now >= $expires_at ) ? PartUploadResult::EXPIRED_URL : PartUploadResult::REFUSED;
		} elseif ( $status >= 300 && $status < 500 ) {
			$outcome = PartUploadResult::REFUSED;
		} else {
			// 5xx, ou réponse sans statut exploitable (absent, 1xx final, au-delà de 599) :
			// réessayer est sans danger, un nouvel envoi de la même partie la remplace.
			$outcome = PartUploadResult::NETWORK;
		}

		return new PartUploadResult( $outcome, null, $status, self::storage_code( (string) wp_remote_retrieve_body( $response ) ), $content_md5, $elapsed_ms );
	}

	/**
	 * Date de la réponse selon l'horloge du stockage (en-tête `Date`, forme RFC 7231
	 * exacte), null si elle manque ou est illisible.
	 *
	 * @param array<string, mixed> $response Réponse de WordPress.
	 */
	private static function storage_date( array $response ): ?int {
		$date = wp_remote_retrieve_header( $response, 'date' );
		return is_string( $date ) ? self::parse_utc( '!' . self::HTTP_DATE_FORMAT, self::HTTP_DATE_FORMAT, $date ) : null;
	}

	/**
	 * Horodatage d'une date écrite exactement au format donné, en UTC ; null sinon (la
	 * relecture refuse aussi les dates « débordantes », comme un 32 octobre).
	 */
	private static function parse_utc( string $parse_format, string $print_format, string $value ): ?int {
		$date = \DateTimeImmutable::createFromFormat( $parse_format, $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( $print_format ) !== $value ) {
			return null;
		}
		return $date->getTimestamp();
	}

	/** Élément `<Code>` d'une erreur S3, s'il est fait de 1 à 64 lettres ; null sinon. */
	private static function storage_code( string $body ): ?string {
		if ( 1 !== preg_match( '#<Code>([^<]*)</Code>#', substr( $body, 0, self::MAX_RESPONSE_BYTES ), $matches ) ) {
			return null;
		}
		return 1 === preg_match( self::STORAGE_CODE_PATTERN, $matches[1] ) ? $matches[1] : null;
	}
}
