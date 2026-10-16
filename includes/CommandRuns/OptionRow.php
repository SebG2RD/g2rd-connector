<?php
/**
 * Ligne de la table des options lue et écrite en SQL direct, sans cache : les quatre
 * primitives des exécutions en arrière-plan, partagées par RunStore (un enregistrement
 * par exécution) et ExecutionSlot (le créneau du site).
 *
 * Pourquoi pas get_option(), add_option(), update_option() ni delete_option() :
 *   - ils lisent puis écrivent : deux processus peuvent écrire tous les deux. Ici, chaque
 *     écriture est atomique : « INSERT IGNORE » (la clé unique sur option_name ne laisse
 *     créer la ligne qu'à un seul processus), « UPDATE … AND option_value = %s » et
 *     « DELETE … AND option_value = %s » sur la valeur relue (aucune ligne touchée : un
 *     autre processus a écrit entre la lecture et l'écriture) ;
 *   - un cache d'objets persistant (LiteSpeed Object Cache) servirait une valeur périmée :
 *     les lectures passent par SQL, et chaque écriture vide l'entrée du cache.
 *
 * Une lecture que la base refuse lève StoreUnavailable : elle ne rend jamais null, qui
 * veut dire « option absente ». Une écriture refusée rend false, distinct de 0 (aucune
 * ligne touchée).
 *
 * Comparaison des valeurs (UPDATE et DELETE conditionnels) : elle suit la collation de
 * la colonne, souvent insensible à la casse. Elle reste sûre pour les appelants : leurs
 * valeurs sont du JSON en ASCII (wp_json_encode() échappe le reste), et chacune de leurs
 * écritures change une date, un état ou un numéro, au-delà d'une simple casse.
 *
 * Le nom de l'option n'est pas contrôlé ici : chaque appelant le compose et le contrôle.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class OptionRow {

	/** Valeur « non autochargée » comprise par toutes les versions de WordPress prises en charge. */
	private const AUTOLOAD_OFF = 'no';

	/**
	 * Valeur brute de l'option `$name`, lue en base. Null si l'option est absente.
	 *
	 * @throws StoreUnavailable Si la base renvoie une erreur : la lecture n'a pas eu lieu,
	 *                          l'option n'est pas pour autant absente.
	 */
	public static function read_raw( string $name ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture sans cache voulue : un cache d'objets persistant servirait un état périmé (enregistrement, verrou).
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		// get_var() rend null aussi quand la requête échoue (« MySQL server has gone
		// away », trop de connexions) : seule `last_error`, remise à vide par wpdb avant
		// chaque requête, distingue l'échec de l'absence.
		$db_error = (string) $wpdb->last_error;
		if ( '' !== $db_error ) {
			throw new StoreUnavailable( $db_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- l'argument n'est pas le message (texte fixe, StoreUnavailable::MESSAGE) : l'erreur de la base, gardée pour le journal, jamais affichée.
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Crée l'option `$name`, non autochargée, si elle n'existe pas.
	 *
	 * « INSERT IGNORE » peut aussi ne toucher aucune ligne sans rien écrire (erreur
	 * changée en avertissement par IGNORE) : 0 ne prouve pas que l'option existe,
	 * l'appelant relit.
	 *
	 * @return int|false 1 si elle est créée ; 0 si elle existe déjà (ou rien n'a été
	 *                   écrit) ; false si la base refuse.
	 */
	public static function insert_ignore( string $name, string $value ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- création atomique voulue (add_option lit puis écrit) ; option jamais lue par get_option(), cache vidé juste après.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				$value,
				self::AUTOLOAD_OFF
			)
		);
		wp_cache_delete( $name, 'options' );

		return false === $inserted ? false : (int) $inserted;
	}

	/**
	 * Remplace la valeur de l'option `$name`, seulement si elle vaut encore `$expected`
	 * (la valeur relue par l'appelant).
	 *
	 * Comme MySQL compte les lignes changées et non les lignes trouvées, une valeur
	 * identique à `$expected` ne touche aucune ligne.
	 *
	 * @return int|false 1 si elle est remplacée ; 0 si elle a changé ou disparu entre-temps
	 *                   (ou si la valeur est identique) ; false si la base refuse.
	 */
	public static function replace( string $name, string $expected, string $value ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- écriture conditionnelle atomique voulue (update_option lit puis écrit) ; cache vidé juste après.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$expected
			)
		);
		wp_cache_delete( $name, 'options' );

		return false === $updated ? false : (int) $updated;
	}

	/**
	 * Supprime l'option `$name`, seulement si elle vaut encore `$expected` (la valeur lue
	 * par l'appelant) : une option réécrite entre la lecture et la suppression est gardée.
	 *
	 * @return int|false 1 si elle est supprimée ; 0 si elle a été réécrite ou supprimée
	 *                   entre-temps ; false si la base refuse.
	 */
	public static function delete_if_unchanged( string $name, string $expected ): int|false {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- suppression conditionnelle voulue (delete_option ne compare pas la valeur) : une option réécrite entre la lecture et la suppression est gardée ; cache vidé juste après.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$expected
			)
		);
		wp_cache_delete( $name, 'options' );

		return false === $deleted ? false : (int) $deleted;
	}
}
