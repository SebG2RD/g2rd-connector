<?php
/**
 * Santé de la base pour l'inventaire (bloc `db_health` du snapshot) : compteurs façon
 * ManageWP, calculés au plus une fois par jour.
 *
 * Le calcul coûte cinq requêtes, dont un SHOW TABLE STATUS sur toutes les tables du
 * site ; il était refait à chaque synchronisation, sur un serveur partagé avec tout le
 * parc. Ces compteurs bougent lentement (sauf après un nettoyage, cf. forget()) : le
 * dernier calcul est gardé 24 h dans une option non autochargée. Les champs restent
 * identiques ; `collected_at` est la date du calcul, ce qui dit à la plateforme de
 * quand datent les valeurs.
 *
 * Lecture seule et bornée : aucun OPTIMIZE ni suppression ici. Le nettoyage passe par
 * les commandes delete_spam_comments / delete_post_revisions / empty_trashed_posts /
 * delete_expired_transients / optimize_database. `autoloaded_options_mb` est purement
 * informatif (pas d'action associée).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rest;

final class DbHealth {

	/** Dernier calcul : option NON autochargée (seul l'inventaire la lit). */
	public const OPTION = 'g2rd_connector_db_health';

	/** Durée de validité d'un calcul : 24 h. */
	public const MAX_AGE_SECONDS = 24 * 3600;

	/**
	 * Commandes qui changent ces compteurs : le calcul gardé est oublié après chacune
	 * (cf. CommandExecutor::run()). La plateforme remet le compteur à 0 et attend la
	 * valeur réelle de la synchronisation suivante.
	 */
	public const CLEANUP_COMMANDS = [
		'delete_spam_comments',
		'delete_post_revisions',
		'empty_trashed_posts',
		'delete_expired_transients',
		'optimize_database',
	];

	/** Champs du bloc, dans l'ordre de l'inventaire (forme inchangée). */
	private const KEYS = [
		'spam_comments',
		'post_revisions',
		'trashed_posts',
		'expired_transients',
		'autoloaded_options_mb',
		'db_overhead_mb',
		'collected_at',
	];

	/**
	 * Bloc `db_health` : le calcul gardé s'il a moins de 24 h, sinon un nouveau calcul,
	 * gardé à son tour s'il est complet (aucune requête en échec : un compteur faux ne
	 * reste pas figé 24 h).
	 *
	 * @return array<string, mixed>
	 */
	public static function get( int $now ): array {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && isset( $stored['at'], $stored['health'] ) && is_array( $stored['health'] )
			&& array_keys( $stored['health'] ) === self::KEYS ) {
			$age = $now - (int) $stored['at'];
			if ( $age >= 0 && $age < self::MAX_AGE_SECONDS ) {
				return $stored['health'];
			}
		}

		[ $health, $complete ] = self::compute( $now );
		if ( $complete ) {
			update_option(
				self::OPTION,
				[
					'at'     => $now,
					'health' => $health,
				],
				false
			);
		}

		return $health;
	}

	/** Oublie le calcul gardé : le prochain inventaire recalcule. */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @return array{0: array<string, mixed>, 1: bool} Le bloc, et s'il est complet.
	 */
	private static function compute( int $now ): array {
		global $wpdb;

		// wp_count_comments() renvoie un objet ; cast en tableau pour un accès sûr.
		$counts        = (array) wp_count_comments();
		$spam_comments = isset( $counts['spam'] ) ? (int) $counts['spam'] : 0;

		$post_revisions     = 0;
		$trashed_posts      = 0;
		$expired_transients = 0;
		$autoload_bytes     = 0;
		$overhead_bytes     = 0;
		$complete           = false;

		if ( $wpdb ) {
			// wpdb remet `last_error` à zéro à chaque requête : on la relève après chacune.
			$errors = [];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- comptage de maintenance (lecture seule), sans cache pertinent.
			$post_revisions = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
			);
			$errors[] = (string) $wpdb->last_error;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- comptage de maintenance (lecture seule), sans cache pertinent.
			$trashed_posts = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
			);
			$errors[] = (string) $wpdb->last_error;

			// Transients expirés : mêmes critères que la purge (cf CommandExecutor::purge_expired_transients).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- comptage transients expires (requete preparee), sans cache pertinent.
			$expired_transients = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options}
					 WHERE option_name LIKE %s AND option_value < %d",
					'_transient_timeout_%',
					$now
				)
			);
			$errors[] = (string) $wpdb->last_error;

			// Taille des options autoloadées (indicateur de perf, informatif). WP 6.6+
			// a introduit de nouvelles valeurs d'autoload ('on'/'auto'/'auto-on') en
			// plus de 'yes' — on couvre l'ensemble des valeurs réellement autoloadées.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- somme des tailles autoload (lecture seule), sans cache pertinent.
			$autoload_bytes = (int) $wpdb->get_var(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
				 WHERE autoload IN ( 'yes', 'on', 'auto', 'auto-on' )"
			);
			$errors[] = (string) $wpdb->last_error;

			// Overhead = espace fragmenté récupérable (SUM Data_free) sur les tables
			// du préfixe courant. ARRAY_A pour un accès par clé (évite l'accès dynamique
			// à une propriété d'objet côté PHPStan).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- SHOW TABLE STATUS de maintenance (lecture seule), prefixe sur.
			$status = (array) $wpdb->get_results( "SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'", ARRAY_A );
			$errors[] = (string) $wpdb->last_error;
			foreach ( $status as $row ) {
				if ( is_array( $row ) && isset( $row['Data_free'] ) ) {
					$overhead_bytes += (int) $row['Data_free'];
				}
			}

			$complete = [] === array_filter( $errors );
		}

		return [
			[
				'spam_comments'         => $spam_comments,
				'post_revisions'        => $post_revisions,
				'trashed_posts'         => $trashed_posts,
				'expired_transients'    => $expired_transients,
				'autoloaded_options_mb' => round( $autoload_bytes / 1048576, 2 ),
				'db_overhead_mb'        => round( $overhead_bytes / 1048576, 2 ),
				'collected_at'          => gmdate( 'c', $now ),
			],
			$complete,
		];
	}
}
