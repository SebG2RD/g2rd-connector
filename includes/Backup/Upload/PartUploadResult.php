<?php
/**
 * Issue de l'envoi d'une partie de sauvegarde vers une URL présignée (PartUploader).
 *
 * Valeur immuable, sans effet au chargement. Elle ne porte que des codes stables et
 * des valeurs bornées : jamais l'URL signée, ni le corps de la réponse du stockage,
 * ni un message d'erreur du transport (qui recopie souvent l'URL). Les phrases
 * destinées aux personnes viennent avec l'appelant (phase 4).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\Upload;

final class PartUploadResult {

	/** Partie reçue par le stockage (2xx portant un ETag valide). */
	public const UPLOADED = 'uploaded';

	/** URL échue selon l'horloge du stockage (403 et en-tête `Date` ≥ échéance de l'URL). */
	public const EXPIRED_URL = 'expired_url';

	/** Refus du stockage : 3xx (jamais suivie), 4xx hors URL échue, 2xx sans ETag valide. */
	public const REFUSED = 'refused';

	/** 5xx, erreur de transport autre qu'un délai, réponse sans statut exploitable. */
	public const NETWORK = 'network';

	/** Délai dépassé (erreur cURL 28). */
	public const TOO_SLOW = 'too_slow';

	/** URL refusée avant tout envoi (ou par WordPress, qui ne l'a pas exécutée). */
	public const BAD_URL = 'bad_url';

	/** Partie illisible : fichier absent, illisible ou plus court que la partie demandée. */
	public const READ_FAILED = 'read_failed';

	private const OUTCOMES = [
		self::UPLOADED,
		self::EXPIRED_URL,
		self::REFUSED,
		self::NETWORK,
		self::TOO_SLOW,
		self::BAD_URL,
		self::READ_FAILED,
	];

	/** Issues qu'un nouvel essai peut changer (`expired_url` : avec une URL neuve). */
	private const RETRYABLE = [ self::NETWORK, self::TOO_SLOW, self::EXPIRED_URL ];

	/**
	 * @param string      $outcome      Une des constantes de la classe.
	 * @param string|null $etag         ETag rendu par le stockage, seulement pour `uploaded`.
	 * @param int         $http_status  Statut HTTP de la réponse, 0 sans réponse.
	 * @param string|null $storage_code Élément `<Code>` de l'erreur S3 (`^[A-Za-z]{1,64}$`), sinon null.
	 * @param string      $content_md5  `Content-MD5` des octets envoyés (base64), vide si rien n'a été lu.
	 * @param int         $elapsed_ms   Durée de la requête HTTP en millisecondes, 0 sans requête.
	 *
	 * @throws \InvalidArgumentException Issue inconnue (erreur de programmation).
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly ?string $etag,
		public readonly int $http_status,
		public readonly ?string $storage_code,
		public readonly string $content_md5,
		public readonly int $elapsed_ms,
	) {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			throw new \InvalidArgumentException( 'Issue d\'envoi de partie inconnue.' );
		}
	}

	/** Un nouvel essai peut-il réussir (`network`, `too_slow`, `expired_url` avec une URL neuve) ? */
	public function is_retryable(): bool {
		return in_array( $this->outcome, self::RETRYABLE, true );
	}
}
