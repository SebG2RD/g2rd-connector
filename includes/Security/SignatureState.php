<?php
/**
 * État persistant de la vérification de signature : nonces déjà vus (protection
 * contre le rejeu) et compteur d'échecs (diagnostic remonté au manager).
 *
 * Stocké dans une OPTION dédiée, sans autoload — pas un transient : sur un site à
 * cache objet persistant, un transient peut être évincé avant son terme, ce qui
 * rouvrirait la fenêtre de rejeu (même piège que `g2rd_updates_snapshot`).
 *
 * Le registre est borné par le TEMPS, jamais par le volume (K2) : un nonce reste
 * enregistré tant qu'il est rejouable. Avant, au-delà de 500 entrées, les plus
 * anciennes étaient évincées même encore valides — 500 requêtes signées
 * suffisaient à rendre un nonce rejouable. Au plafond mémoire (MAX_LIVE_NONCES),
 * on REFUSE d'enregistrer plutôt que d'évincer une entrée vivante.
 *
 * Concurrence : la lecture-modification-écriture de l'option se fait sous un
 * verrou consultatif MySQL (GET_LOCK), pour qu'une requête simultanée n'efface pas
 * le nonce qu'une autre vient d'écrire (il redeviendrait rejouable). Si le verrou
 * est indisponible (fonction désactivée par l'hébergeur, cluster Galera, délai
 * dépassé), on retombe sur le comportement d'avant, sans verrou : jamais de refus
 * ni d'erreur à cause du verrou.
 *
 * Format de l'option inchangé depuis la 1.12.0-rc.1 : {nonces: {nonce: vu_à}, stats}.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Security;

final class SignatureState {

	public const OPTION_KEY = 'g2rd_connector_signature_state';

	/**
	 * Durée de rétention d'un nonce, comptée depuis son ARRIVÉE.
	 *
	 * Un nonce horodaté T est accepté de T-300 à T+300 : arrivé au plus tôt à
	 * T-300, il reste rejouable 600 s. On garde deux fenêtres, plus une minute de
	 * marge.
	 */
	public const NONCE_TTL_SECONDS = 2 * RequestSignature::WINDOW_SECONDS + 60;

	/**
	 * Ancien plafond (rc.4 et avant), qui évinçait des nonces encore rejouables.
	 *
	 * @deprecated Conservée pour d'éventuels appelants ; plus utilisée. Voir MAX_LIVE_NONCES.
	 */
	public const MAX_NONCES = 500;

	/**
	 * Garde mémoire : nombre maximal de nonces VIVANTS. Environ 7,5 requêtes signées
	 * par seconde soutenues pendant 11 min — très au-delà du trafic du manager, qui
	 * envoie une commande à la fois par site. Atteint, le registre refuse le nouveau
	 * nonce (NONCE_STORE_FULL) au lieu d'évincer une entrée encore rejouable.
	 */
	public const MAX_LIVE_NONCES = 5000;

	/** Résultat de register_nonce() : premier passage, nonce enregistré. */
	public const NONCE_ACCEPTED = 'accepted';

	/** Résultat de register_nonce() : nonce déjà vu, encore dans sa fenêtre. */
	public const NONCE_REPLAYED = 'replayed';

	/** Résultat de register_nonce() : registre au plafond, rien ajouté ni évincé. */
	public const NONCE_STORE_FULL = 'store_full';

	/** Attente maximale du verrou, en secondes, avant de continuer sans lui. */
	private const LOCK_TIMEOUT_SECONDS = 3;

	/**
	 * Enregistre un nonce et dit ce qu'il en est : NONCE_ACCEPTED, NONCE_REPLAYED
	 * ou NONCE_STORE_FULL.
	 */
	public static function register_nonce( string $nonce, int $now ): string {
		return self::with_lock(
			static function () use ( $nonce, $now ): string {
				$state  = self::read();
				$nonces = self::prune( $state['nonces'], $now );

				if ( isset( $nonces[ $nonce ] ) ) {
					return self::NONCE_REPLAYED;
				}

				if ( count( $nonces ) >= self::MAX_LIVE_NONCES ) {
					// On n'évince JAMAIS une entrée vivante : on purge seulement les
					// expirées (s'il y en a) et on refuse le nouveau nonce.
					if ( count( $nonces ) !== count( $state['nonces'] ) ) {
						$state['nonces'] = $nonces;
						self::write( $state );
					}
					return self::NONCE_STORE_FULL;
				}

				$nonces[ $nonce ] = $now;
				$state['nonces']  = $nonces;
				self::write( $state );
				return self::NONCE_ACCEPTED;
			}
		);
	}

	/**
	 * Mémorise un nonce. Retourne false s'il a déjà été vu (rejeu) ou si le
	 * registre est plein — register_nonce() distingue les deux cas.
	 */
	public static function remember_nonce( string $nonce, int $now ): bool {
		return self::NONCE_ACCEPTED === self::register_nonce( $nonce, $now );
	}

	/**
	 * Compte un échec de vérification (mode rapport : la requête est acceptée,
	 * mais l'anomalie ne doit pas rester invisible).
	 */
	public static function record_failure( string $code, int $now ): void {
		self::with_lock(
			static function () use ( $code, $now ): void {
				$state                            = self::read();
				$state['nonces']                  = self::prune( $state['nonces'], $now );
				$state['stats']['failed_count']   = (int) $state['stats']['failed_count'] + 1;
				$state['stats']['last_failed_at'] = $now;
				$state['stats']['last_code']      = $code;
				self::write( $state );
			}
		);
	}

	/**
	 * @return array{failed_count:int,last_failed_at:int|null,last_code:string|null}
	 */
	public static function stats(): array {
		return self::read()['stats'];
	}

	/**
	 * @param array<string, int> $nonces
	 * @return array<string, int>
	 */
	private static function prune( array $nonces, int $now ): array {
		$threshold = $now - self::NONCE_TTL_SECONDS;
		return array_filter(
			$nonces,
			static fn ( int $seen_at ): bool => $seen_at >= $threshold
		);
	}

	/**
	 * @return array{nonces:array<string,int>,stats:array{failed_count:int,last_failed_at:int|null,last_code:string|null}}
	 */
	private static function read(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$nonces = [];
		if ( isset( $stored['nonces'] ) && is_array( $stored['nonces'] ) ) {
			foreach ( $stored['nonces'] as $nonce => $seen_at ) {
				$nonces[ (string) $nonce ] = (int) $seen_at;
			}
		}

		$stats = isset( $stored['stats'] ) && is_array( $stored['stats'] ) ? $stored['stats'] : [];

		return [
			'nonces' => $nonces,
			'stats'  => [
				'failed_count'   => isset( $stats['failed_count'] ) ? (int) $stats['failed_count'] : 0,
				'last_failed_at' => isset( $stats['last_failed_at'] ) ? (int) $stats['last_failed_at'] : null,
				'last_code'      => isset( $stats['last_code'] ) ? (string) $stats['last_code'] : null,
			],
		];
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function write( array $state ): void {
		update_option( self::OPTION_KEY, $state, false );
	}

	/**
	 * Exécute `$operation` (lecture-modification-écriture de l'option) sous verrou
	 * MySQL, ou sans verrou s'il ne peut pas être pris — le comportement d'avant.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	private static function with_lock( callable $operation ): mixed {
		$lock = self::acquire_lock();
		try {
			if ( null !== $lock && function_exists( 'wp_cache_delete' ) ) {
				// Sous verrou, on relit la base : un cache objet persistant (Redis,
				// LiteSpeed…) pourrait sinon servir une version antérieure à
				// l'écriture d'une requête concurrente.
				wp_cache_delete( self::OPTION_KEY, 'options' );
			}
			return $operation();
		} finally {
			if ( null !== $lock ) {
				self::release_lock( $lock );
			}
		}
	}

	/**
	 * Prend le verrou consultatif. Retourne son nom, ou null s'il n'a pas pu être
	 * pris (pas de $wpdb, GET_LOCK indisponible, délai dépassé) : l'appelant
	 * continue alors sans verrou.
	 */
	private static function acquire_lock(): ?string {
		$wpdb = self::wpdb();
		if ( null === $wpdb ) {
			return null;
		}

		$name     = self::lock_name( $wpdb );
		$acquired = self::quiet_query( $wpdb, $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) );

		return '1' === $acquired ? $name : null;
	}

	private static function release_lock( string $name ): void {
		$wpdb = self::wpdb();
		if ( null !== $wpdb ) {
			self::quiet_query( $wpdb, $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Nom du verrou. L'espace des noms GET_LOCK est commun à TOUT le serveur MySQL
	 * (mutualisé) : il inclut la base et la table des options, pour ne jamais
	 * sérialiser des sites voisins (ni deux blogs d'un multisite). 33 caractères,
	 * sous la limite de 64 de MySQL.
	 */
	private static function lock_name( object $wpdb ): string {
		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$table    = isset( $wpdb->options ) && is_string( $wpdb->options ) ? $wpdb->options : '';

		return 'g2rd_sig_' . substr( hash( 'sha256', $database . '|' . $table ), 0, 24 );
	}

	/**
	 * Exécute une requête de verrou sans jamais afficher d'erreur SQL (une erreur
	 * affichée casserait la réponse JSON d'un site en WP_DEBUG_DISPLAY) ni lever.
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
