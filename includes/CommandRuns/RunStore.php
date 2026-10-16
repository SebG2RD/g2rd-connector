<?php
/**
 * Enregistrement d'une exécution en arrière-plan (§ 3.2 de la spécification).
 *
 * Une option par exécution, `g2rd_cmdrun_<numéro>`, jamais autochargée, dont la valeur
 * est du JSON : pas de sérialisation PHP, donc aucune désérialisation d'objet, et une
 * valeur que l'écriture conditionnelle peut comparer telle quelle.
 *
 * Les deux écritures qui décident d'une exécution sont atomiques, en SQL direct sur la
 * table des options :
 *   - création : « INSERT IGNORE ». La clé unique sur option_name ne laisse qu'un seul
 *     processus créer l'enregistrement d'un numéro (précédent : DirectLogin\UsedTickets) ;
 *   - changement d'état : « UPDATE … WHERE option_name = %s AND option_value = %s », sur
 *     la valeur relue. Une ligne modifiée : on a gagné ; aucune : un autre processus a
 *     écrit entre-temps (c'est la voie que UpdateTransaction::reserve() désigne).
 * add_option() et update_option() ne le garantissent pas : ils lisent, puis écrivent.
 *
 * Les lectures passent aussi par SQL, jamais par get_option() : un cache d'objets
 * persistant (LiteSpeed Object Cache) servirait un état périmé.
 *
 * Contenu : command_id, command, body_sha256, state, accepted_at, started_at, alive_at,
 * finished_at (secondes Unix), step (facultatif), outcome ({status, result, error,
 * stray_output}, forme de la réponse de `/command`), interruption (facultatif :
 * {reason, error, recovery}).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

final class RunStore {

	public const OPTION_PREFIX = 'g2rd_cmdrun_';

	/**
	 * Taille maximale d'un enregistrement, mesurée sur l'encodage que la route de lecture
	 * sert. La plateforme refuse une réponse de lecture plus grosse que cette borne et
	 * son enveloppe.
	 */
	public const MAX_RECORD_BYTES = 65536;

	/** Taille maximale d'un message d'erreur gardé (outcome.error, interruption.error). */
	public const MAX_ERROR_BYTES = 2048;

	/**
	 * Numéro d'une exécution : 128 bits tirés par la plateforme, en hexadécimal minuscule.
	 * « D » : le « $ » final n'accepte pas de saut de ligne après le numéro.
	 */
	public const ID_PATTERN = '/^[a-f0-9]{32}$/D';

	/** Valeur « non autochargée » comprise par toutes les versions de WordPress prises en charge. */
	private const AUTOLOAD_OFF = 'no';

	/** Marque d'un message d'erreur coupé. */
	private const ELLIPSIS = '…';

	/**
	 * Nom de l'option d'une exécution. Le numéro n'est pas contrôlé ici : create(),
	 * read_raw() et swap() le contrôlent avant toute requête.
	 */
	public static function option_name( string $command_id ): string {
		return self::OPTION_PREFIX . $command_id;
	}

	/**
	 * Crée l'enregistrement d'une exécution. Vrai seulement pour le processus qui a créé
	 * la ligne.
	 *
	 * Faux si le numéro est déjà enregistré, si la base refuse l'écriture, si le numéro
	 * est mal formé ou si l'enregistrement ne peut pas être borné. Pour distinguer les
	 * deux premiers cas, l'appelant relit (read_raw()) : présent, le numéro a déjà été
	 * reçu ; absent, rien n'a été écrit.
	 *
	 * @param array<string, mixed> $record Enregistrement complet, numéro compris.
	 */
	public static function create( array $record ): bool {
		global $wpdb;

		$command_id = $record['command_id'] ?? null;
		if ( ! is_string( $command_id ) || ! self::is_valid_id( $command_id ) ) {
			return false;
		}
		$value = self::encode( $record );
		if ( '' === $value ) {
			return false;
		}

		$name = self::option_name( $command_id );
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

		return 1 === $inserted;
	}

	/**
	 * Valeur brute de l'enregistrement, lue en base : c'est elle que swap() attend.
	 * Null si le numéro est inconnu ou mal formé.
	 */
	public static function read_raw( string $command_id ): ?string {
		global $wpdb;

		if ( ! self::is_valid_id( $command_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture sans cache voulue : un cache d'objets persistant servirait un état périmé.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::option_name( $command_id )
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Enregistrement lu en base. Null si le numéro est inconnu ou mal formé, ou si la
	 * valeur est illisible.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read( string $command_id ): ?array {
		return self::decode( self::read_raw( $command_id ) );
	}

	/**
	 * Décode une valeur brute (read_raw()). Null si ce n'est pas un objet JSON : valeur
	 * abîmée, ou écrite par un autre moyen (une sérialisation PHP n'est jamais décodée).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode( ?string $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$record = json_decode( $raw, true );

		return is_array( $record ) ? $record : null;
	}

	/**
	 * Remplace l'enregistrement, seulement s'il vaut encore `$expected_raw` (la valeur
	 * relue par l'appelant). Vrai si une ligne a été modifiée ; faux si un autre
	 * processus a écrit entre-temps, si la base refuse l'écriture, si le numéro est mal
	 * formé ou si l'enregistrement ne peut pas être borné. Une valeur identique à
	 * `$expected_raw` ne modifie aucune ligne : faux aussi.
	 *
	 * La comparaison suit la collation de la colonne (souvent insensible à la casse).
	 * Elle reste sûre : les valeurs sont du JSON en ASCII (wp_json_encode() échappe le
	 * reste), et chaque écriture d'un enregistrement change son état, une date ou son
	 * étape, au-delà d'une simple casse.
	 *
	 * @param array<string, mixed> $record Nouvel enregistrement complet.
	 */
	public static function swap( string $command_id, string $expected_raw, array $record ): bool {
		global $wpdb;

		if ( ! self::is_valid_id( $command_id ) ) {
			return false;
		}
		$value = self::encode( $record );
		if ( '' === $value ) {
			return false;
		}

		$name = self::option_name( $command_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- écriture conditionnelle atomique voulue (update_option lit puis écrit) ; cache vidé juste après.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$name,
				$expected_raw
			)
		);
		wp_cache_delete( $name, 'options' );

		return 1 === $updated;
	}

	/**
	 * Encode un enregistrement en JSON, borné.
	 *
	 * Les messages d'erreur sont coupés à 2 Ko. Si l'ensemble dépasse 64 Ko, la sortie
	 * parasite part d'abord ; puis, si cela ne suffit pas, les entrées du résultat, les
	 * tableaux d'abord et le plus volumineux en premier, enfin, en dernier recours, les
	 * valeurs simples démesurées, jusqu'à repasser sous la borne. Le résultat est alors
	 * marqué `result_truncated`.
	 *
	 * Encodage et mesure par wp_json_encode() sans option, comme la réponse REST qui le
	 * servira : caractères non ASCII et « / » échappés, jusqu'à trois fois plus longs.
	 * Mesurée sur un encodage plus compact, la borne laisserait passer une réponse que
	 * la plateforme refuserait : le site paraîtrait muet.
	 *
	 * @param array<string, mixed> $record
	 * @return string Le JSON ; chaîne vide si l'enregistrement ne peut être ni encodé ni
	 *                borné (rien à retirer du résultat) : il n'est alors pas écrit.
	 */
	public static function encode( array $record ): string {
		foreach ( [ 'outcome', 'interruption' ] as $part ) {
			if ( isset( $record[ $part ]['error'] ) && is_string( $record[ $part ]['error'] ) ) {
				$record[ $part ]['error'] = self::truncate_error( $record[ $part ]['error'] );
			}
		}

		$json = self::fitting_json( $record );
		if ( null !== $json || ! isset( $record['outcome'] ) || ! is_array( $record['outcome'] ) ) {
			return (string) $json;
		}

		unset( $record['outcome']['stray_output'] );
		$json = self::fitting_json( $record );
		if ( null !== $json ) {
			return $json;
		}

		$result = $record['outcome']['result'] ?? null;
		if ( ! is_array( $result ) ) {
			return '';
		}
		$record['outcome']['result_truncated'] = true;
		foreach ( self::removal_order( $result ) as $key ) {
			unset( $record['outcome']['result'][ $key ] );
			$json = self::fitting_json( $record );
			if ( null !== $json ) {
				return $json;
			}
		}

		return '';
	}

	/**
	 * L'encodage de l'enregistrement s'il tient dans la borne, null sinon (ou s'il ne
	 * peut pas être encodé).
	 *
	 * @param array<string, mixed> $record
	 */
	private static function fitting_json( array $record ): ?string {
		$json = wp_json_encode( $record );

		return is_string( $json ) && strlen( $json ) <= self::MAX_RECORD_BYTES ? $json : null;
	}

	/**
	 * Ordre de retrait des entrées du résultat : les tableaux (listes, détails) avant les
	 * valeurs simples (extension, versions, issue), et, dans chaque groupe, la plus
	 * volumineuse d'abord. Une entrée impossible à encoder passe en tête de son groupe.
	 *
	 * @param array<mixed> $result
	 * @return list<int|string> Clés du résultat.
	 */
	private static function removal_order( array $result ): array {
		$weights = [];
		foreach ( $result as $key => $value ) {
			$json            = wp_json_encode( $value );
			$weights[ $key ] = [
				is_array( $value ) || is_object( $value ) ? 0 : 1,
				is_string( $json ) ? -strlen( $json ) : PHP_INT_MIN,
			];
		}
		uasort( $weights, static fn ( array $a, array $b ): int => $a <=> $b );

		return array_keys( $weights );
	}

	/**
	 * Coupe un message d'erreur à MAX_ERROR_BYTES octets, marque comprise, entre deux
	 * caractères UTF-8 (jamais au milieu d'un caractère accentué).
	 */
	private static function truncate_error( string $error ): string {
		if ( strlen( $error ) <= self::MAX_ERROR_BYTES ) {
			return $error;
		}

		$cut = self::MAX_ERROR_BYTES - strlen( self::ELLIPSIS );
		// Un octet de continuation (10xxxxxx) n'ouvre jamais un caractère : on recule
		// jusqu'au début du caractère coupé.
		while ( $cut > 0 && 0x80 === ( ord( $error[ $cut ] ) & 0xC0 ) ) {
			--$cut;
		}

		return substr( $error, 0, $cut ) . self::ELLIPSIS;
	}

	private static function is_valid_id( string $command_id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $command_id );
	}
}
