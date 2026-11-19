<?php
/**
 * Refus de la commande `get_release_notes` (1.18.0), avec un code stable que la plateforme lit pour
 * choisir sa phrase FR/EN (contrat release-notes-command-v1.json, partagé à l'octet avec le
 * manager). Le message suit le format du connecteur : quoi, cause probable, action ; il sert au
 * journal et à qui lit la réponse, sans aucun détail interne (ni chemin, ni texte renvoyé par un
 * éditeur).
 *
 * Aucun refus n'est réessayable par lui-même : pas de `retry_after`, la plateforme décide.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\ReleaseNotes;

final class ReleaseNotesRefused extends \RuntimeException {

	/** Charge sans `kind` valable (`plugin` ou `theme`), ou sans `file` / `stylesheet` en texte. */
	public const INVALID_REQUEST = 'release_notes_invalid_request';

	/** Extension ou thème absent de ce site (get_plugins(), wp_get_themes()). */
	public const NOT_INSTALLED = 'release_notes_not_installed';

	/**
	 * Réponse sans section de notes, ou aucun éditeur n'a répondu pour un composant qui ne vient pas
	 * de wordpress.org (wordpress.org n'est alors pas interrogé, cf. ReleaseNotesCommand).
	 */
	public const NOT_PROVIDED = 'release_notes_not_provided';

	/** Appel en échec (erreur, délai dépassé, exception) ou réponse illisible. */
	public const SOURCE_UNAVAILABLE = 'release_notes_source_unavailable';

	/** Commande tirée de la file du battement de cœur : jamais exécutée par ce chemin. */
	public const QUEUE_UNSUPPORTED = 'release_notes_queue_unsupported';

	/** Codes de la commande par `/command`, dans l'ordre du contrat (« codes »). */
	public const CODES = [ self::INVALID_REQUEST, self::NOT_INSTALLED, self::NOT_PROVIDED, self::SOURCE_UNAVAILABLE ];

	private string $reason_code;

	/**
	 * @param string $reason_code Un des codes ci-dessus.
	 * @throws \InvalidArgumentException Code inconnu : erreur de programmation.
	 */
	public function __construct( string $reason_code ) {
		if ( ! in_array( $reason_code, self::CODES, true ) && self::QUEUE_UNSUPPORTED !== $reason_code ) {
			throw new \InvalidArgumentException( 'Code de refus des notes de version inconnu : ' . esc_html( $reason_code ) );
		}
		$this->reason_code = $reason_code;
		parent::__construct( self::message_for( $reason_code ) );
	}

	public function reason_code(): string {
		return $this->reason_code;
	}

	/**
	 * Issue d'une commande refusée, de la forme de CommandExecutor::run().
	 *
	 * @return array{status: string, error: string, error_code: string}
	 */
	public function outcome(): array {
		return [
			'status'     => 'failed',
			'error'      => $this->getMessage(),
			'error_code' => $this->reason_code,
		];
	}

	/**
	 * @throws \InvalidArgumentException Code sans phrase : erreur de programmation.
	 */
	private static function message_for( string $code ): string {
		return match ( $code ) {
			self::INVALID_REQUEST    => __( 'Les notes de version n’ont pas été lues : la demande ne dit pas quoi lire (type « plugin » avec le fichier de l’extension, ou « theme » avec le dossier du thème). Rien n’a été modifié. Cause probable : plateforme incompatible avec cette version du connecteur. Action : mettre à jour le connecteur G2RD sur ce site ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			self::NOT_INSTALLED      => __( 'Les notes de version n’ont pas été lues : cette extension ou ce thème n’est pas installé sur ce site. Rien n’a été modifié. Cause probable : supprimé ou renommé depuis la dernière synchronisation. Action : synchroniser le site depuis la plateforme, puis réessayer.', 'g2rd-connector' ),
			self::NOT_PROVIDED       => __( 'Les notes de version n’ont pas été lues : l’éditeur ne les fournit pas à WordPress pour cette extension ou ce thème. Rien n’a été modifié. Cause probable : l’éditeur ne publie pas ses notes par WordPress, ou son module de mise à jour ne se charge que dans l’administration du site. Action : consulter les notes de version sur le site de l’éditeur.', 'g2rd-connector' ),
			self::SOURCE_UNAVAILABLE => __( 'Les notes de version n’ont pas été lues : la source des mises à jour (serveur de l’éditeur ou wordpress.org) n’a pas répondu dans les 15 secondes, ou sa réponse est illisible. Rien n’a été modifié. Cause probable : serveur injoignable depuis ce site, pare-feu sortant, ou licence de l’extension non reconnue. Action : réessayer plus tard ; si cela se répète, vérifier la licence de l’extension dans l’administration du site.', 'g2rd-connector' ),
			self::QUEUE_UNSUPPORTED  => __( 'Les notes de version n’ont pas été lues : cette lecture passe seulement par une demande directe de la plateforme, jamais par la file des commandes. Rien n’a été modifié. Cause probable : plateforme incompatible avec cette version du connecteur. Action : réessayer depuis la plateforme ; si le refus se répète, contacter le support G2RD.', 'g2rd-connector' ),
			default                  => throw new \InvalidArgumentException( 'Code de refus des notes de version sans phrase : ' . esc_html( $code ) ),
		};
	}
}
