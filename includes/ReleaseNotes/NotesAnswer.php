<?php
/**
 * Réponse de plugins_api() ou themes_api() réduite à ce que la commande `get_release_notes` rend au
 * manager (1.18.0) : la section des notes (`changelog`) en HTML brut, bornée à 256 Kio, la version
 * annoncée et la page d'accueil, en https seulement. Le manager analyse ce HTML sans réseau et n'en
 * garde que du texte (spécification « Notes de version », § 6.3) : rien n'est nettoyé ici, sauf
 * l'UTF-8, rendu valide pour que la réponse JSON en soit une.
 *
 * Mémoire bornée : le texte est coupé à NOTES_MAX_BYTES + 4 octets avant d'être rendu valide.
 *
 * Aucun effet au chargement, aucune écriture.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\ReleaseNotes;

final class NotesAnswer {

	/** Borne de `notes_html`, en octets (256 Kio, contrat release-notes-command-v1.json). */
	public const NOTES_MAX_BYTES = 262144;

	/** Version : un chiffre ou une lettre, puis chiffres, lettres, « . », « + », « _ », « - » ; 64 caractères au plus. */
	private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}$/D';

	/** Caractères admis dans une adresse (RFC 3986) : ni espace, ni guillemet, ni chevron, ni caractère non ASCII. */
	private const URL_PATTERN = '/^[A-Za-z0-9._~:\/?#\[\]@!$&\'()*+,;=%-]+$/D';

	/** Longueur maximale de la page d'accueil, en octets. */
	private const URL_MAX_BYTES = 2048;

	/**
	 * @param mixed $info Réponse de plugins_api() ou themes_api() : objet (WordPress), tableau toléré.
	 * @return array{notes_html: string, truncated: bool, version: string|null, homepage: string|null}
	 * @throws ReleaseNotesRefused SOURCE_UNAVAILABLE (réponse illisible) ou NOT_PROVIDED (pas de section de notes).
	 */
	public static function from( $info ): array {
		if ( is_object( $info ) ) {
			$info = get_object_vars( $info );
		}
		if ( ! is_array( $info ) ) {
			throw new ReleaseNotesRefused( ReleaseNotesRefused::SOURCE_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		}
		$notes = self::changelog( $info['sections'] ?? null );
		if ( null === $notes ) {
			throw new ReleaseNotesRefused( ReleaseNotesRefused::NOT_PROVIDED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message construit par ReleaseNotesRefused (phrase traduite, code constant).
		}
		// Coupé avant d'être rendu valide : jamais tout le texte d'un éditeur repris en mémoire. Quatre
		// octets de plus que la borne : bounded() retire le caractère coupé et la balise ouverte.
		[ $html, $cut ] = self::bounded( self::valid_utf8( substr( $notes, 0, self::NOTES_MAX_BYTES + 4 ) ) );

		return [
			'notes_html' => $html,
			'truncated'  => $cut || strlen( $notes ) > self::NOTES_MAX_BYTES,
			'version'    => self::version( $info['version'] ?? null ),
			'homepage'   => self::https_url( $info['homepage'] ?? null ),
		];
	}

	/**
	 * Texte en UTF-8 valide : une suite d'octets invalide devient U+FFFD, le reste ne change pas
	 * (aller-retour htmlspecialchars, sans mbstring, que l'hébergeur peut ne pas avoir).
	 */
	public static function valid_utf8( string $text ): string {
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}

		return htmlspecialchars_decode( htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
	}

	/**
	 * Texte borné à NOTES_MAX_BYTES octets, avec `true` s'il a été coupé : jamais au milieu d'un
	 * caractère, et une balise coupée (« < » sans « > » après lui) part avec ce qui la suit.
	 *
	 * @param string $html Texte en UTF-8 valide (cf. valid_utf8()).
	 * @return array{0: string, 1: bool}
	 */
	public static function bounded( string $html ): array {
		if ( strlen( $html ) <= self::NOTES_MAX_BYTES ) {
			return [ $html, false ];
		}
		$cut = substr( $html, 0, self::NOTES_MAX_BYTES );
		// Un caractère UTF-8 tient en 4 octets au plus : en retirer 3 suffit à ne garder que des
		// caractères entiers.
		$tries = 0;
		while ( $tries < 3 && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
			++$tries;
		}
		$open = strrpos( $cut, '<' );
		if ( false !== $open && false === strpos( $cut, '>', $open ) ) {
			$cut = substr( $cut, 0, $open );
		}

		return [ $cut, true ];
	}

	/**
	 * Version annoncée, si elle a la forme d'un numéro de version ; null sinon.
	 *
	 * @param mixed $version Texte, ou entier.
	 */
	public static function version( $version ): ?string {
		if ( is_int( $version ) ) {
			$version = (string) $version;
		}
		if ( ! is_string( $version ) ) {
			return null;
		}
		$version = trim( $version );

		return 1 === preg_match( self::VERSION_PATTERN, $version ) ? $version : null;
	}

	/**
	 * Adresse https absolue, sans identifiants (« https://nom@hôte » trompe le lecteur) ni caractère
	 * hors RFC 3986, rendue avec le schéma et l'hôte en minuscules et **sans requête ni fragment**
	 * (une clé de licence peut s'y trouver) ; null sinon.
	 *
	 * @param mixed $url Adresse annoncée.
	 */
	public static function https_url( $url ): ?string {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::URL_MAX_BYTES || 1 !== preg_match( self::URL_PATTERN, $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		return 'https://' . strtolower( (string) $parts['host'] )
			. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. (string) ( $parts['path'] ?? '' );
	}

	/**
	 * Section `changelog` (nom comparé sans tenir compte de la casse), si c'est un texte non vide.
	 *
	 * @param mixed $sections Sections de la réponse (tableau ; objet toléré).
	 */
	private static function changelog( $sections ): ?string {
		if ( is_object( $sections ) ) {
			$sections = get_object_vars( $sections );
		}
		if ( ! is_array( $sections ) ) {
			return null;
		}
		foreach ( $sections as $name => $content ) {
			if ( 'changelog' === strtolower( (string) $name ) && is_string( $content ) && '' !== trim( $content ) ) {
				return $content;
			}
		}

		return null;
	}
}
