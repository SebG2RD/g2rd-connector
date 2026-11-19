<?php
/**
 * Provenance des mises à jour d'une extension ou d'un thème (1.18.0), lue dans la dernière
 * vérification que WordPress a rangée dans le transient `update_plugins` ou `update_themes`, sans
 * aucun appel réseau (spécification « Notes de version » du manager, § 5.2) :
 *
 *  - `wporg` : l'entrée vient de wordpress.org (cf. is_wporg_entry()) ;
 *  - `vendor` : une entrée existe et ne vient pas de wordpress.org (updater de l'éditeur, en-tête
 *    `Update URI`, rejeu de la capture des mises à jour tierces, cf. PremiumUpdatesBridge) ;
 *  - null : aucune entrée, WordPress ne sait rien de sa provenance.
 *
 * L'entrée de `response` (la mise à jour que WordPress installerait) passe avant celle de
 * `no_update`.
 *
 * Aucun effet au chargement, aucune écriture (tests/ReleaseNotes/ReadOnlyTest.php).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Updates;

final class UpdateSource {

	public const WPORG = 'wporg';

	public const VENDOR = 'vendor';

	/**
	 * Provenance des mises à jour d'une extension ou d'un thème.
	 *
	 * @param mixed  $transient Transient `update_plugins` ou `update_themes` (objet ; autre chose :
	 *                          aucune entrée).
	 * @param string $key       Fichier de l'extension (« dossier/fichier.php ») ou feuille de style
	 *                          du thème.
	 * @return string|null self::WPORG, self::VENDOR, ou null sans entrée.
	 */
	public static function of( $transient, string $key ): ?string {
		$entry = self::entry( $transient, $key );
		if ( null === $entry ) {
			return null;
		}

		return self::is_wporg_entry( $entry ) ? self::WPORG : self::VENDOR;
	}

	/**
	 * Entrée de mise à jour d'une extension ou d'un thème, en tableau : celle de `response`, sinon
	 * celle de `no_update` ; null s'il n'y en a pas. WordPress y range des objets pour les
	 * extensions, des tableaux pour les thèmes ; une entrée d'une autre forme est ignorée.
	 *
	 * @param mixed  $transient Transient `update_plugins` ou `update_themes`.
	 * @param string $key       Fichier de l'extension ou feuille de style du thème.
	 * @return array<mixed>|null
	 */
	public static function entry( $transient, string $key ): ?array {
		if ( ! is_object( $transient ) ) {
			return null;
		}
		$fields = get_object_vars( $transient );
		foreach ( [ 'response', 'no_update' ] as $list ) {
			$entries = $fields[ $list ] ?? null;
			if ( ! is_array( $entries ) || ! array_key_exists( $key, $entries ) ) {
				continue;
			}
			$entry = $entries[ $key ];
			if ( is_object( $entry ) ) {
				return get_object_vars( $entry );
			}
			if ( is_array( $entry ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Entrée venue de wordpress.org. Preuve : un `id` qui commence par `w.org/` (extensions), ou une
	 * fiche (`url`) ou un paquet (`package`) sur wordpress.org ou l'un de ses sous-domaines
	 * (downloads.wordpress.org). Refus, quel que soit l'`id` : une fiche ou un paquet renseigné
	 * ailleurs ; un updater tiers qui se dit wordpress.org sans y prendre son paquet ne passe pas
	 * pour lui (le manager irait lire les notes d'un homonyme). Règle du champ `wporg` des thèmes
	 * (1.14), étendue à l'`id` des extensions.
	 *
	 * @param mixed $entry Entrée de `response` ou `no_update` (tableau ; objet toléré).
	 */
	public static function is_wporg_entry( $entry ): bool {
		if ( is_object( $entry ) ) {
			$entry = get_object_vars( $entry );
		}
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$found = isset( $entry['id'] ) && is_string( $entry['id'] ) && str_starts_with( $entry['id'], 'w.org/' );
		foreach ( [ 'url', 'package' ] as $key ) {
			$address = isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ? $entry[ $key ] : '';
			if ( '' === $address ) {
				continue;
			}
			if ( ! self::on_wordpress_org( $address ) ) {
				return false;
			}
			$found = true;
		}

		return $found;
	}

	/** Adresse dont l'hôte est wordpress.org ou l'un de ses sous-domaines, pas un domaine qui leur ressemble. */
	private static function on_wordpress_org( string $address ): bool {
		$host = strtolower( (string) wp_parse_url( $address, PHP_URL_HOST ) );

		return 'wordpress.org' === $host || str_ends_with( $host, '.wordpress.org' );
	}
}
