<?php
/**
 * Ce que le PHP du site permet, lu à chaque snapshot pour quelques appels de
 * fonction : SAPI, extensions NATIVES (sodium, openssl, zip, zlib, mysqli), version
 * de libsodium, disponibilité d'`exec`, limites de mémoire et de durée, espace disque
 * du volume de `WP_CONTENT_DIR`.
 *
 * Lu dans la requête web servie à la plateforme, pas en tâche de fond : c'est le PHP
 * web qui servira les sauvegardes, et il peut différer du PHP en ligne de commande
 * (chez Hostinger, `sodium` manque en ligne de commande ; relevé du 05/10). Aucun
 * parcours de fichiers ni requête SQL ici : les volumes (wp-content, médias, base)
 * relèvent de la mesure quotidienne en tâche de fond.
 *
 * Règles à ne pas changer :
 *  - `sodium` natif se lit par extension_loaded(), JAMAIS par function_exists() :
 *    WordPress embarque sodium_compat, qui définit les fonctions `sodium_*` ET la
 *    constante SODIUM_LIBRARY_VERSION quand l'extension manque. Sans sodium natif, la
 *    sauvegarde chiffrée sera refusée (D4 : sodium_compat est trop lent) ;
 *  - `exec` n'est JAMAIS appelée : sa disponibilité se lit (en PHP 8, une fonction de
 *    `disable_functions` n'est plus définie ; la directive est relue par prudence),
 *    et aucune fermeture d'exécution n'est injectable ;
 *  - aucun chemin dans la sortie : des drapeaux, des nombres et deux textes réduits à
 *    `[a-z0-9._-]` ;
 *  - ce qui n'a pas pu être lu est rendu inconnu (null, `unavailable`, ou faux pour un
 *    drapeau : jamais une capacité affirmée sans avoir été constatée), et describe()
 *    ne lève jamais : une fonction désactivée par l'hébergeur ne doit pas faire
 *    échouer le snapshot.
 *
 * Les fonctions internes de PHP passent par des fermetures injectables (les vraies
 * par défaut) : le banc de test ne peut pas les redéfinir. Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Environment;

final class RuntimeProbe {

	/** Espace disque lu. */
	public const DISK_OK = 'ok';

	/** Espace disque illisible ici (fonction désactivée, accès refusé, valeur absurde). */
	public const DISK_UNAVAILABLE = 'unavailable';

	/** Longueur maximale des deux textes rendus (SAPI, version de libsodium). */
	private const TEXT_MAX_LENGTH = 32;

	/** @var \Closure(string): bool */
	private \Closure $extension_loaded;

	/** @var \Closure(string): (string|false) */
	private \Closure $ini_get;

	/** @var \Closure(string): (float|false) */
	private \Closure $disk_free_space;

	/** @var \Closure(string): (float|false) */
	private \Closure $disk_total_space;

	/** @var \Closure(string): bool */
	private \Closure $function_exists;

	/** @var \Closure(string): bool */
	private \Closure $class_exists;

	/** @var \Closure(string): mixed */
	private \Closure $constant;

	/**
	 * Chaque fermeture remplace la fonction de PHP du même nom (tests) ; par défaut, la
	 * vraie. Les fermetures par défaut appellent la fonction au moment de la mesure,
	 * jamais à la construction : une fonction désactivée par l'hébergeur (souvent
	 * disk_free_space) n'est alors pas définie, et seule sa mesure devient inconnue.
	 *
	 * @param (\Closure(string): bool)|null           $extension_loaded extension_loaded().
	 * @param (\Closure(string): (string|false))|null $ini_get          ini_get().
	 * @param (\Closure(string): (float|false))|null  $disk_free_space  disk_free_space().
	 * @param (\Closure(string): (float|false))|null  $disk_total_space disk_total_space().
	 * @param (\Closure(string): bool)|null           $function_exists  function_exists().
	 * @param (\Closure(string): bool)|null           $class_exists     class_exists(), sans chargement automatique.
	 * @param (\Closure(string): mixed)|null          $constant         Valeur d'une constante, null si elle n'est pas définie.
	 */
	public function __construct(
		?\Closure $extension_loaded = null,
		?\Closure $ini_get = null,
		?\Closure $disk_free_space = null,
		?\Closure $disk_total_space = null,
		?\Closure $function_exists = null,
		?\Closure $class_exists = null,
		?\Closure $constant = null
	) {
		$this->extension_loaded = $extension_loaded ?? static fn ( string $name ): bool => extension_loaded( $name );
		$this->ini_get          = $ini_get ?? static fn ( string $option ): string|false => ini_get( $option );
		$this->disk_free_space  = $disk_free_space ?? static fn ( string $directory ): float|false => disk_free_space( $directory );
		$this->disk_total_space = $disk_total_space ?? static fn ( string $directory ): float|false => disk_total_space( $directory );
		$this->function_exists  = $function_exists ?? static fn ( string $name ): bool => function_exists( $name );
		// ZipArchive est une classe interne de PHP : inutile de réveiller les chargeurs automatiques des autres plugins.
		$this->class_exists = $class_exists ?? static fn ( string $class_name ): bool => class_exists( $class_name, false );
		$this->constant     = $constant ?? static fn ( string $name ): mixed => defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * Mesures légères, dans l'ordre des clés du contrat du bloc `environment`.
	 *
	 * @return array{php_sapi: string, extensions: array{sodium: bool, openssl: bool, zip: bool, zlib: bool, mysqli: bool}, sodium_library_version: string|null, exec_available: bool, memory_limit_bytes: int|null, max_execution_seconds: int|null, disk: array{status: string, free_bytes: int|null, total_bytes: int|null}}
	 */
	public function describe(): array {
		$sodium = $this->has_extension( 'sodium' );

		return [
			'php_sapi'               => $this->constant_text( 'PHP_SAPI' ) ?? '',
			'extensions'             => [
				'sodium'  => $sodium,
				'openssl' => $this->has_extension( 'openssl' ),
				'zip'     => $this->has_extension( 'zip' ) && $this->has_class( \ZipArchive::class ),
				'zlib'    => $this->has_extension( 'zlib' ),
				'mysqli'  => $this->has_extension( 'mysqli' ),
			],
			// sodium_compat définit aussi cette constante : lue seulement si l'extension est native.
			'sodium_library_version' => $sodium ? $this->constant_text( 'SODIUM_LIBRARY_VERSION' ) : null,
			'exec_available'         => $this->exec_available(),
			'memory_limit_bytes'     => $this->memory_limit_bytes(),
			'max_execution_seconds'  => $this->max_execution_seconds(),
			'disk'                   => $this->disk(),
		];
	}

	/**
	 * `exec` utilisable : définie et absente de `disable_functions` (liste découpée sur
	 * les virgules, espaces retirés, casse ignorée). Lu sans rien exécuter.
	 */
	private function exec_available(): bool {
		if ( ! $this->has_function( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', strtolower( (string) $this->ini( 'disable_functions' ) ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	/**
	 * `memory_limit` en octets ; -1 = illimitée ; null si vide ou illisible (jamais un
	 * 0 inventé à partir d'une valeur qui ne se lit pas).
	 */
	private function memory_limit_bytes(): ?int {
		$raw = $this->ini( 'memory_limit' );
		if ( '-1' === $raw ) {
			return -1;
		}
		if ( null === $raw || 1 !== preg_match( '/\A\d+[kmg]?\z/i', $raw ) ) {
			return null;
		}
		$bytes = self::attempt( static fn (): mixed => wp_convert_hr_to_bytes( $raw ) );
		return is_int( $bytes ) && $bytes >= 0 ? $bytes : null;
	}

	/**
	 * `max_execution_time` en secondes, jamais négatif (0 = illimitée) ; null si illisible.
	 */
	private function max_execution_seconds(): ?int {
		$raw = $this->ini( 'max_execution_time' );
		if ( null === $raw || 1 !== preg_match( '/\A-?\d+\z/', $raw ) ) {
			return null;
		}
		return max( 0, (int) $raw );
	}

	/**
	 * Espace libre et total du volume de WP_CONTENT_DIR, tel que PHP le voit (sur un
	 * hébergement mutualisé : le disque du serveur, pas le quota du compte). `ok`
	 * seulement si les deux mesures ont réussi : une seule ne dit rien d'utile.
	 *
	 * @return array{status: string, free_bytes: int|null, total_bytes: int|null}
	 */
	private function disk(): array {
		$free  = $this->disk_bytes( 'disk_free_space', $this->disk_free_space );
		$total = $this->disk_bytes( 'disk_total_space', $this->disk_total_space );

		if ( null === $free || null === $total ) {
			return [
				'status'      => self::DISK_UNAVAILABLE,
				'free_bytes'  => null,
				'total_bytes' => null,
			];
		}
		return [
			'status'      => self::DISK_OK,
			'free_bytes'  => $free,
			'total_bytes' => $total,
		];
	}

	/**
	 * Une mesure de disque arrondie à l'octet ; null si la fonction est désactivée, si
	 * elle échoue ou si sa valeur est absurde (négative, non finie, pas un nombre).
	 *
	 * @param string   $function_name Nom de la fonction de PHP, pour savoir si elle est définie.
	 * @param \Closure $measure       La fermeture qui la remplace.
	 */
	private function disk_bytes( string $function_name, \Closure $measure ): ?int {
		if ( ! $this->has_function( $function_name ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- désactivée ou bridée chez certains hébergeurs (open_basedir) : « indisponible », jamais une erreur affichée dans la réponse JSON du snapshot.
		$bytes = @self::attempt( $measure, WP_CONTENT_DIR );
		if ( ( ! is_int( $bytes ) && ! is_float( $bytes ) ) || ! is_finite( (float) $bytes ) || $bytes < 0 ) {
			return null;
		}
		return (int) round( $bytes );
	}

	private function has_extension( string $name ): bool {
		return true === self::attempt( $this->extension_loaded, $name );
	}

	private function has_function( string $name ): bool {
		return true === self::attempt( $this->function_exists, $name );
	}

	private function has_class( string $class_name ): bool {
		return true === self::attempt( $this->class_exists, $class_name );
	}

	/**
	 * Valeur brute d'une directive, sans les espaces autour ; null si illisible.
	 */
	private function ini( string $option ): ?string {
		$value = self::attempt( $this->ini_get, $option );
		return is_string( $value ) ? trim( $value ) : null;
	}

	/**
	 * Constante texte réduite à `[a-z0-9._-]` et à TEXT_MAX_LENGTH caractères : rien
	 * d'autre qu'un identifiant court ne peut sortir (ni chemin ni balise). Null si la
	 * constante est absente, n'est pas un texte ou ne garde aucun caractère.
	 */
	private function constant_text( string $name ): ?string {
		$value = self::attempt( $this->constant, $name );
		if ( ! is_string( $value ) ) {
			return null;
		}
		$reduced = substr( (string) preg_replace( '/[^a-z0-9._-]/', '', strtolower( $value ) ), 0, self::TEXT_MAX_LENGTH );
		return '' === $reduced ? null : $reduced;
	}

	/**
	 * Appelle une sonde ; toute erreur (fonction désactivée par l'hébergeur, donc non
	 * définie en PHP 8 ; fonction de WordPress absente) rend null : inconnu, jamais une
	 * exception qui ferait échouer le snapshot.
	 *
	 * @param \Closure $probe     Sonde à appeler.
	 * @param mixed    ...$arguments Arguments transmis tels quels.
	 */
	private static function attempt( \Closure $probe, mixed ...$arguments ): mixed {
		try {
			return $probe( ...$arguments );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
