<?php
/**
 * Verrou consultatif MySQL (`GET_LOCK`) réutilisable : une section critique dans un
 * seul processus, sortie de SignatureState pour servir aussi à la mesure quotidienne
 * de l'environnement et, plus tard, aux tranches des sauvegardes.
 *
 * Portée de SESSION : MySQL libère le verrou d'office à la fin de la connexion. Si le
 * processus PHP meurt (délai dépassé, mémoire épuisée), aucun verrou ne reste
 * orphelin. C'est aussi sa limite : un `GET_LOCK` n'est PAS un bail durable entre
 * requêtes (prise, signe de vie, détenteur lisible par la plateforme) ; ce besoin-là
 * relève d'un autre mécanisme (CommandRuns\ExecutionSlot, choix n° 2 du plan de la
 * phase 0 des sauvegardes).
 *
 * L'espace des noms `GET_LOCK` est commun à TOUT le serveur MySQL, donc à tous les
 * sites du serveur mutualisé. Le nom est haché à partir de la base et de la table (ou
 * du préfixe) : jamais deux sites voisins sérialisés l'un par l'autre, et aucun nom de
 * base ni de table divulgué à qui lirait la liste des verrous du serveur.
 *
 * Trois issues, distinguées exprès :
 *  - ACQUIRED    : `GET_LOCK` a rendu 1 ;
 *  - BUSY        : `GET_LOCK` a rendu 0, verrou tenu par un autre processus jusqu'à
 *                  la fin du délai ;
 *  - UNAVAILABLE : tout le reste (NULL de la base, fonction refusée par l'hébergeur
 *                  ou par un cluster Galera, exception, $wpdb absent, requête non
 *                  préparée).
 * Les confondre ferait prendre une base sans `GET_LOCK` pour un verrou toujours tenu :
 * un appelant qui « ne fait rien si occupé » ne ferait alors jamais rien. C'est donc
 * l'appelant qui choisit ses replis.
 *
 * Les requêtes de verrou sont silencieuses : une erreur SQL affichée casserait la
 * réponse JSON d'un site en WP_DEBUG_DISPLAY.
 *
 * Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Support;

final class MysqlLock {

	/** Portée d'un blog : nom calculé sur la base et la table des options du blog courant. */
	public const SCOPE_BLOG = 'blog';

	/** Portée de l'installation entière (tous les blogs d'un multisite) : base et préfixe commun. */
	public const SCOPE_NETWORK = 'network';

	/** Attente maximale acceptée pour prendre un verrou, en secondes. */
	public const MAX_TIMEOUT_SECONDS = 10;

	/** Verrou pris : l'opération s'exécute sous lui. */
	public const ACQUIRED = 'acquired';

	/** Verrou tenu par un autre processus jusqu'à la fin du délai. */
	public const BUSY = 'busy';

	/** Verrou impossible à prendre ici (GET_LOCK refusé, base ou $wpdb indisponible). */
	public const UNAVAILABLE = 'unavailable';

	/** Forme d'un but : court, pour que le nom reste sous la limite de 64 caractères de MySQL. */
	private const PURPOSE_PATTERN = '/\A[a-z0-9]{1,12}\z/';

	/**
	 * Nom du verrou : `g2rd_<but>_<24 hex>`, 42 caractères au plus.
	 *
	 * Pour le but `sig` en portée blog, c'est exactement le nom du registre des nonces
	 * depuis la 1.12 : deux versions du connecteur qui tournent ensemble pendant une
	 * mise à jour se sérialisent toujours.
	 *
	 * @param string $purpose But du verrou, `[a-z0-9]{1,12}`.
	 * @param string $scope   SCOPE_BLOG ou SCOPE_NETWORK.
	 * @return string|null Null si $wpdb n'est pas exploitable (tests, contexte précoce).
	 * @throws \InvalidArgumentException But mal formé ou portée inconnue : erreur de programmation.
	 */
	public static function name( string $purpose, string $scope = self::SCOPE_BLOG ): ?string {
		if ( 1 !== preg_match( self::PURPOSE_PATTERN, $purpose ) ) {
			throw new \InvalidArgumentException( 'invalid MySQL lock purpose: it must be 1 to 12 lowercase letters or digits (programming error: pass a short constant purpose such as "sig" or "env")' );
		}
		if ( self::SCOPE_BLOG !== $scope && self::SCOPE_NETWORK !== $scope ) {
			throw new \InvalidArgumentException( 'unknown MySQL lock scope: only MysqlLock::SCOPE_BLOG and MysqlLock::SCOPE_NETWORK are accepted (programming error: pass one of these constants)' );
		}

		$wpdb = self::wpdb();
		if ( null === $wpdb ) {
			return null;
		}

		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$property = self::SCOPE_BLOG === $scope ? 'options' : 'base_prefix';
		$table    = isset( $wpdb->{$property} ) && is_string( $wpdb->{$property} ) ? $wpdb->{$property} : '';

		return 'g2rd_' . $purpose . '_' . substr( hash( 'sha256', $database . '|' . $table ), 0, 24 );
	}

	/**
	 * Tente de prendre le verrou, en attendant au plus `$timeout_seconds` (borné à
	 * [0, MAX_TIMEOUT_SECONDS]).
	 *
	 * @param string $purpose         But du verrou, `[a-z0-9]{1,12}`.
	 * @param int    $timeout_seconds Attente maximale ; 0 = ne pas attendre.
	 * @param string $scope           SCOPE_BLOG ou SCOPE_NETWORK.
	 * @return array{state: string, name: string|null} `name` n'est renseigné que si `state` vaut ACQUIRED.
	 * @throws \InvalidArgumentException But mal formé ou portée inconnue : erreur de programmation.
	 */
	public static function acquire( string $purpose, int $timeout_seconds, string $scope = self::SCOPE_BLOG ): array {
		$name = self::name( $purpose, $scope );
		$wpdb = self::wpdb();
		if ( null === $name || null === $wpdb ) {
			return self::outcome( self::UNAVAILABLE, null );
		}

		$timeout = max( 0, min( self::MAX_TIMEOUT_SECONDS, $timeout_seconds ) );
		$result  = self::quiet_query( $wpdb, $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		if ( '1' === $result ) {
			return self::outcome( self::ACQUIRED, $name );
		}
		return self::outcome( '0' === $result ? self::BUSY : self::UNAVAILABLE, null );
	}

	/**
	 * Libère un verrou pris par acquire() dans ce processus. Sans effet si $wpdb n'est
	 * plus exploitable : MySQL le libérera à la fin de la connexion.
	 */
	public static function release( string $name ): void {
		$wpdb = self::wpdb();
		if ( null !== $wpdb ) {
			self::quiet_query( $wpdb, $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Exécute `$operation` sous verrou, libéré ensuite même si elle lève ; sinon le
	 * repli qui correspond à l'issue (aucune libération dans ces deux cas).
	 *
	 * @template T
	 * @param string        $purpose          But du verrou, `[a-z0-9]{1,12}`.
	 * @param int           $timeout_seconds  Attente maximale, bornée à [0, MAX_TIMEOUT_SECONDS].
	 * @param callable(): T $operation        Appelée verrou pris.
	 * @param callable(): T $when_busy        Appelée si le verrou est tenu ailleurs (BUSY).
	 * @param callable(): T $when_unavailable Appelée si le verrou ne peut pas être pris ici (UNAVAILABLE).
	 * @param string        $scope            SCOPE_BLOG ou SCOPE_NETWORK.
	 * @return T
	 * @throws \InvalidArgumentException But mal formé ou portée inconnue : erreur de programmation.
	 */
	public static function run( string $purpose, int $timeout_seconds, callable $operation, callable $when_busy, callable $when_unavailable, string $scope = self::SCOPE_BLOG ): mixed {
		$lock = self::acquire( $purpose, $timeout_seconds, $scope );

		if ( self::BUSY === $lock['state'] ) {
			return $when_busy();
		}
		if ( self::ACQUIRED !== $lock['state'] || null === $lock['name'] ) {
			return $when_unavailable();
		}

		try {
			return $operation();
		} finally {
			self::release( $lock['name'] );
		}
	}

	/**
	 * @return array{state: string, name: string|null}
	 */
	private static function outcome( string $state, ?string $name ): array {
		return [
			'state' => $state,
			'name'  => $name,
		];
	}

	/**
	 * Exécute une requête de verrou sans jamais afficher d'erreur SQL (une erreur
	 * affichée casserait la réponse JSON d'un site en WP_DEBUG_DISPLAY) ni lever :
	 * toute erreur, comme une requête non préparée, rend null (issue UNAVAILABLE pour
	 * GET_LOCK). L'état de masquage des erreurs du site est rétabli ensuite.
	 *
	 * @param object $wpdb  $wpdb exploitable (cf. wpdb()).
	 * @param mixed  $query Requête préparée par l'appelant ($wpdb->prepare()).
	 */
	private static function quiet_query( object $wpdb, mixed $query ): ?string {
		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$can_suppress = method_exists( $wpdb, 'suppress_errors' );
		$previous     = $can_suppress ? $wpdb->suppress_errors( true ) : false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- verrou consultatif MySQL (GET_LOCK/RELEASE_LOCK), requête préparée par l'appelant, rien à mettre en cache.
			$result = $wpdb->get_var( $query );
		} catch ( \Throwable ) {
			$result = null;
		} finally {
			if ( $can_suppress ) {
				$wpdb->suppress_errors( (bool) $previous );
			}
		}

		return null === $result ? null : (string) $result;
	}

	/**
	 * Le $wpdb global s'il est exploitable, sinon null (tests, contexte précoce).
	 */
	private static function wpdb(): ?object {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		return $wpdb;
	}
}
