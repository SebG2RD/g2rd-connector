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
 * persistant (LiteSpeed Object Cache) servirait un état périmé. Une lecture que la base
 * refuse lève StoreUnavailable : elle ne rend jamais null, qui veut dire « numéro
 * inconnu ».
 *
 * Contenu : command_id, command, body_sha256, state, accepted_at, started_at, alive_at,
 * finished_at (secondes Unix), step (facultatif), outcome ({status, result, error,
 * stray_output}, forme de la réponse de `/command`), interruption (facultatif :
 * {reason, error, recovery}). Un enregistrement réduit pour tenir dans la borne porte
 * `outcome.result_truncated` ou `interruption.recovery_truncated` (encode()).
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

	/**
	 * Taille maximale d'un message d'erreur gardé (outcome.error, interruption.error), et
	 * de l'étape (step), coupée de même.
	 */
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
	 * Parties réduites, entrée par entrée, quand l'enregistrement dépasse la borne :
	 * [ partie, clé, drapeau posé dans la partie ]. Le résultat de la commande, et le
	 * résultat de reprise rattaché à une interruption (aussi consigné dans l'inventaire,
	 * PendingOutcomes : le réduire ici ne l'efface pas de l'inventaire). La plus
	 * volumineuse est réduite d'abord ; à taille égale, dans cet ordre.
	 */
	private const REDUCIBLE_PARTS = [
		[ 'outcome', 'result', 'result_truncated' ],
		[ 'interruption', 'recovery', 'recovery_truncated' ],
	];

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
	 * reçu ; absent, rien n'a été écrit ; StoreUnavailable, la base ne répond plus.
	 *
	 * « Impossible à borner » ne vient que d'un appelant fautif : encode() réduit tout ce
	 * qui peut grossir (erreurs, étape, sortie parasite, résultats) ; seuls restent des
	 * champs fixés par le connecteur (commande, empreinte, dates, état).
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
	 *
	 * @throws StoreUnavailable Si la base renvoie une erreur : la lecture n'a pas eu lieu,
	 *                          le numéro n'est pas pour autant inconnu.
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
	 * Enregistrement lu en base. Null si le numéro est inconnu ou mal formé, ou si la
	 * valeur est illisible.
	 *
	 * @return array<string, mixed>|null
	 * @throws StoreUnavailable Si la base renvoie une erreur (cf. read_raw()).
	 */
	public static function read( string $command_id ): ?array {
		return self::decode( self::read_raw( $command_id ) );
	}

	/**
	 * Décode une valeur brute (read_raw()). Null si ce n'est pas un enregistrement : pas
	 * un objet JSON (valeur abîmée, ou écrite par un autre moyen : une sérialisation PHP
	 * n'est jamais décodée), ou un objet sans numéro ni état en texte (`{}`, une liste).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode( ?string $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		$record = json_decode( $raw, true );
		if ( ! is_array( $record ) || array_is_list( $record ) ) {
			return null;
		}

		return is_string( $record['command_id'] ?? null ) && is_string( $record['state'] ?? null ) ? $record : null;
	}

	/**
	 * Remplace l'enregistrement, seulement s'il vaut encore `$expected_raw` (la valeur
	 * relue par l'appelant). Vrai si une ligne a été modifiée. Faux :
	 *   - si un autre processus a écrit entre-temps (course perdue) ;
	 *   - si la base refuse l'écriture ;
	 *   - si le numéro est mal formé, ou si l'enregistrement porte un autre numéro ;
	 *   - si l'enregistrement ne peut pas être borné (appelant fautif, cf. create()) ;
	 *   - si la valeur est identique à `$expected_raw` (aucune ligne modifiée).
	 *
	 * Seule une course perdue se règle en relisant puis en réessayant. Pour la
	 * reconnaître, l'appelant (RunLifecycle) relit : une valeur différente de
	 * `$expected_raw`, c'est une course perdue ; la même valeur, personne n'a écrit,
	 * l'écriture est refusée : réessayer à l'identique ne servirait à rien.
	 *
	 * La comparaison suit la collation de la colonne (souvent insensible à la casse).
	 * Elle reste sûre : les valeurs sont du JSON en ASCII (wp_json_encode() échappe le
	 * reste), et chaque écriture d'un enregistrement change son état, une date ou son
	 * étape, au-delà d'une simple casse.
	 *
	 * @param array<string, mixed> $record Nouvel enregistrement complet, même numéro.
	 */
	public static function swap( string $command_id, string $expected_raw, array $record ): bool {
		global $wpdb;

		if ( ! self::is_valid_id( $command_id ) ) {
			return false;
		}
		// Jamais l'enregistrement d'un numéro sous l'option d'un autre : la route de
		// lecture servirait un autre numéro que celui demandé.
		if ( ( $record['command_id'] ?? null ) !== $command_id ) {
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
	 * Les messages d'erreur et l'étape sont coupés à 2 Ko. Si l'ensemble dépasse 64 Ko, la
	 * sortie parasite part d'abord. Puis, si cela ne suffit pas, les entrées du résultat
	 * (`outcome.result`) et du résultat de reprise (`interruption.recovery`), la partie la
	 * plus volumineuse d'abord (le résultat, à taille égale). Dans chaque partie : les
	 * tableaux d'abord et le plus volumineux en premier, enfin, en dernier recours, les
	 * valeurs simples démesurées, jusqu'à repasser sous la borne. Une partie qui n'est pas
	 * un tableau (un long texte) est retirée entière (null). Chaque partie réduite est
	 * marquée : `outcome.result_truncated`, `interruption.recovery_truncated`. Le statut,
	 * l'état et les dates ne sont jamais retirés.
	 *
	 * Encodage et mesure par wp_json_encode() sans option, comme la réponse REST qui le
	 * servira : caractères non ASCII et « / » échappés, jusqu'à trois fois plus longs.
	 * Mesurée sur un encodage plus compact, la borne laisserait passer une réponse que
	 * la plateforme refuserait : le site paraîtrait muet.
	 *
	 * @param array<string, mixed> $record
	 * @return string Le JSON ; chaîne vide si l'enregistrement ne peut être ni encodé ni
	 *                borné (plus rien à retirer) : il n'est alors pas écrit.
	 */
	public static function encode( array $record ): string {
		foreach ( [ 'outcome', 'interruption' ] as $part ) {
			if ( isset( $record[ $part ]['error'] ) && is_string( $record[ $part ]['error'] ) ) {
				$record[ $part ]['error'] = self::truncate_error( $record[ $part ]['error'] );
			}
		}
		// L'étape est un libellé court ; démesurée, elle est coupée comme une erreur.
		if ( isset( $record['step'] ) && is_string( $record['step'] ) ) {
			$record['step'] = self::truncate_error( $record['step'] );
		}

		$json = self::fitting_json( $record );
		if ( null !== $json ) {
			return $json;
		}

		if ( isset( $record['outcome'] ) && is_array( $record['outcome'] ) && array_key_exists( 'stray_output', $record['outcome'] ) ) {
			unset( $record['outcome']['stray_output'] );
			$json = self::fitting_json( $record );
			if ( null !== $json ) {
				return $json;
			}
		}

		foreach ( self::reducible_parts( $record ) as [ $part, $key, $flag ] ) {
			$json = self::reduce( $record, $part, $key, $flag );
			if ( null !== $json ) {
				return $json;
			}
		}

		return '';
	}

	/**
	 * Parties réductibles présentes (non nulles), la plus volumineuse d'abord ; à taille
	 * égale, dans l'ordre de REDUCIBLE_PARTS. Une partie impossible à encoder passe en tête.
	 *
	 * @param array<string, mixed> $record
	 * @return list<array{0: string, 1: string, 2: string}>
	 */
	private static function reducible_parts( array $record ): array {
		$present = [];
		foreach ( self::REDUCIBLE_PARTS as $rank => $reducible ) {
			[ $part, $key ] = $reducible;
			if ( ! isset( $record[ $part ] ) || ! is_array( $record[ $part ] ) || ! isset( $record[ $part ][ $key ] ) ) {
				continue;
			}
			$json      = wp_json_encode( $record[ $part ][ $key ] );
			$present[] = [
				'order'     => [ is_string( $json ) ? -strlen( $json ) : PHP_INT_MIN, $rank ],
				'reducible' => $reducible,
			];
		}
		usort( $present, static fn ( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		return array_column( $present, 'reducible' );
	}

	/**
	 * Retire, une à une, les entrées de `$record[ $part ][ $key ]` (removal_order()),
	 * marque la partie avec `$flag`, et rend le JSON dès qu'il tient dans la borne. Null si
	 * la partie entière retirée ne suffit pas : `$record` garde alors la partie vidée, et
	 * la réduction passe à la suivante.
	 *
	 * Une liste (clés 0..n) reste une liste : sans réindexation, elle serait servie en
	 * objet JSON (`{"0":…,"2":…}`), sous une autre forme que la réponse de `/command`.
	 *
	 * @param array<string, mixed> $record Modifié sur place.
	 */
	private static function reduce( array &$record, string $part, string $key, string $flag ): ?string {
		$value                    = $record[ $part ][ $key ];
		$record[ $part ][ $flag ] = true;

		if ( ! is_array( $value ) ) {
			$record[ $part ][ $key ] = null;
			return self::fitting_json( $record );
		}

		$is_list = array_is_list( $value );
		foreach ( self::removal_order( $value ) as $entry ) {
			unset( $value[ $entry ] );
			$record[ $part ][ $key ] = $is_list ? array_values( $value ) : $value;
			$json                    = self::fitting_json( $record );
			if ( null !== $json ) {
				return $json;
			}
		}

		return null;
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
	 * Ordre de retrait des entrées d'une partie (résultat, résultat de reprise) : les
	 * tableaux (listes, détails) avant les valeurs simples (extension, versions, issue),
	 * et, dans chaque groupe, la plus volumineuse d'abord. Une entrée impossible à encoder
	 * passe en tête de son groupe.
	 *
	 * @param array<mixed> $result
	 * @return list<int|string> Clés de la partie.
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
	 * Coupe un message d'erreur (ou l'étape) à MAX_ERROR_BYTES octets, marque comprise,
	 * entre deux caractères UTF-8 (jamais au milieu d'un caractère accentué).
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
