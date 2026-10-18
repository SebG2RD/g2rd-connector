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
 * Les requêtes elles-mêmes sont celles d'OptionRow, partagées avec le créneau
 * (ExecutionSlot) : une correction du verrou vaut pour les deux.
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
 * Durée de vie (décision n° 7) : une exécution terminée est gardée 7 jours, 200
 * enregistrements au plus ; une exécution non terminée n'est jamais purgée (purge(),
 * branchée sur la tâche biquotidienne) : seul le ramassage (RunReaper) la clôt, après
 * l'avoir cherchée par unfinished(). Tout part à la désinstallation, créneau compris
 * (delete_all()).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\CommandRuns;

use G2RD\Connector\Support\ArrayShape;

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

	/**
	 * Conservation d'une exécution terminée : 7 jours après sa fin (décision n° 7), comme
	 * les résultats de reprise (PendingOutcomes::TTL). Le temps de relire un site resté
	 * injoignable plusieurs jours.
	 */
	public const RETENTION_SECONDS = 7 * 86400;

	/**
	 * Enregistrements gardés au plus. Au-delà, les plus anciennes exécutions terminées
	 * partent d'abord ; une exécution non terminée compte, mais ne part jamais.
	 */
	public const MAX_RECORDS = 200;

	/** Suppressions au plus par passage de la tâche biquotidienne (purge_now()). */
	private const CRON_MAX_DELETIONS = 500;

	/**
	 * Options lues au plus par recherche (une purge, un lot de delete_all()). D'ordinaire :
	 * 200 enregistrements et une ou deux exécutions en cours. La marge couvre une purge
	 * longtemps empêchée ; au-delà, le passage suivant traite la suite. Borne aussi la
	 * mémoire : un enregistrement fait 64 Ko au plus.
	 */
	private const SCAN_LIMIT = 500;

	/** Garde-fou de delete_all() : 100 lots de 500, très au-delà de ce qu'un site peut accumuler. */
	private const DELETE_ALL_MAX_BATCHES = 100;

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
		$command_id = $record['command_id'] ?? null;
		if ( ! is_string( $command_id ) || ! self::is_valid_id( $command_id ) ) {
			return false;
		}
		$value = self::encode( $record );
		if ( '' === $value ) {
			return false;
		}

		return 1 === OptionRow::insert_ignore( self::option_name( $command_id ), $value );
	}

	/**
	 * Valeur brute de l'enregistrement, lue en base : c'est elle que swap() attend.
	 * Null si le numéro est inconnu ou mal formé.
	 *
	 * @throws StoreUnavailable Si la base renvoie une erreur : la lecture n'a pas eu lieu,
	 *                          le numéro n'est pas pour autant inconnu.
	 */
	public static function read_raw( string $command_id ): ?string {
		if ( ! self::is_valid_id( $command_id ) ) {
			return null;
		}

		return OptionRow::read_raw( self::option_name( $command_id ) );
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
		if ( ! is_array( $record ) || ArrayShape::is_list( $record ) ) {
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

		return 1 === OptionRow::replace( self::option_name( $command_id ), $expected_raw, $value );
	}

	/**
	 * Supprime les exécutions terminées depuis plus de 7 jours ; puis, s'il reste plus de
	 * 200 enregistrements, les plus anciennes terminées jusqu'à revenir à 200. Au plus
	 * `$max_deletions` suppressions, les plus anciennes d'abord.
	 *
	 * Âge d'une exécution terminée : depuis sa fin (`finished_at`) ; à défaut, depuis la
	 * plus récente de ses dates connues (signe de vie, démarrage, acceptation).
	 *
	 * Jamais supprimés :
	 *   - une exécution non terminée (acceptée, en cours, ou d'un état inconnu), quel que
	 *     soit son âge : seul le ramassage la clôt ;
	 *   - le créneau (`g2rd_cmdrun_slot`), et toute option du préfixe dont le nom n'est pas
	 *     un numéro d'exécution ;
	 *   - une valeur illisible : rien ne dit qu'elle est terminée, ni depuis quand (elle
	 *     part à la désinstallation) ;
	 *   - un enregistrement réécrit entre la lecture et la suppression (résultat tardif
	 *     d'une exécution interrompue) : la suppression est conditionnelle, sur la valeur lue.
	 *
	 * Ménage seulement : si la base refuse la lecture ou une suppression, le passage
	 * s'arrête sans erreur, et le suivant reprend.
	 *
	 * @param int $now           Heure courante (secondes Unix).
	 * @param int $max_deletions Suppressions au plus : 500 pour la tâche biquotidienne ;
	 *                           20 prévues à chaque acceptation (§ 3.2 de la spécification).
	 * @return int Nombre d'enregistrements supprimés.
	 */
	public static function purge( int $now, int $max_deletions ): int {
		if ( $max_deletions <= 0 ) {
			return 0;
		}

		$records  = 0;
		$finished = [];
		foreach ( self::scan() as [ $name, $raw ] ) {
			if ( ! self::is_valid_id( substr( $name, strlen( self::OPTION_PREFIX ) ) ) ) {
				continue;
			}
			$record = self::decode( $raw );
			if ( null === $record ) {
				continue;
			}
			++$records;
			if ( RunState::is_terminal( $record['state'] ) ) {
				$finished[] = [ self::finished_at( $record ), $name, $raw ];
			}
		}
		// Les plus anciennes d'abord ; à date égale, par nom (ordre stable).
		usort( $finished, static fn ( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

		$deleted = 0;
		foreach ( $finished as [ $finished_at, $name, $raw ] ) {
			$expired = $now - $finished_at > self::RETENTION_SECONDS;
			// Triées par âge : si celle-ci n'a ni 7 jours ni de place à libérer, les
			// suivantes, plus récentes, non plus.
			if ( $deleted >= $max_deletions || ( ! $expired && $records <= self::MAX_RECORDS ) ) {
				break;
			}
			$result = OptionRow::delete_if_unchanged( $name, $raw );
			if ( false === $result ) {
				break;
			}
			$deleted += $result;
			// Supprimée ici, ou réécrite ou supprimée entre-temps par un autre processus
			// (purge simultanée) : elle ne compte plus pour la place à libérer. Une purge
			// simultanée ne fait ainsi pas partir plus que l'excédent.
			--$records;
		}

		return $deleted;
	}

	/**
	 * Cible de la tâche biquotidienne (RestorePointPurgeJob::HOOK), branchée comme la purge
	 * des tickets de connexion directe : 500 suppressions au plus par passage.
	 */
	public static function purge_now(): void {
		self::purge( time(), self::CRON_MAX_DELETIONS );
	}

	/**
	 * Supprime toutes les options du préfixe du site courant, lot après lot
	 * (désinstallation) : les enregistrements, terminés ou non, lisibles ou non, et le
	 * créneau. S'arrête quand une recherche ne laisse plus rien à retirer, ou dès que la
	 * base refuse une suppression (pas de boucle sans fin).
	 *
	 * @return int Nombre d'options supprimées.
	 */
	public static function delete_all(): int {
		$total = 0;
		for ( $batch = 0; $batch < self::DELETE_ALL_MAX_BATCHES; $batch++ ) {
			$deleted = 0;
			foreach ( self::scan() as [ $name, $raw ] ) {
				$result = OptionRow::delete_if_unchanged( $name, $raw );
				if ( false === $result ) {
					return $total + $deleted;
				}
				$deleted += $result;
			}
			$total += $deleted;
			// Recherche vide, ou options toutes réécrites entre-temps : rien n'a bougé,
			// une recherche de plus ne ferait pas mieux.
			if ( 0 === $deleted ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * Exécutions acceptées ou en cours, `$limit` au plus, avec leur valeur brute : ce que
	 * le ramassage (RunReaper::sweep()) examine. Vide si la base refuse la lecture.
	 *
	 * Le filtre sur la valeur se fait en base : les enregistrements conclus (200 gardés,
	 * jusqu'à 64 Ko chacun) ne sont ni transférés ni comptés dans la borne, et le créneau
	 * (sans état) non plus. Il ne fait qu'écarter : la valeur est écrite par
	 * wp_json_encode() sans espace, donc l'état d'une exécution ouverte y figure toujours
	 * sous la forme `"state":"accepted"` ou `"state":"running"`. Le même texte dans un
	 * résultat, ou une collation insensible à la casse, peut ramener une exécution de
	 * plus : l'appelant décode et relit l'état.
	 *
	 * @return list<array{0: string, 1: string}> [ numéro, valeur brute ].
	 */
	public static function unfinished( int $limit ): array {
		global $wpdb;

		if ( $limit <= 0 ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recherche par préfixe d'options non autochargées, jamais lues par get_option() : aucun cache pertinent.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND ( option_value LIKE %s OR option_value LIKE %s ) LIMIT %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				'%' . $wpdb->esc_like( '"state":"' . RunState::ACCEPTED . '"' ) . '%',
				'%' . $wpdb->esc_like( '"state":"' . RunState::RUNNING . '"' ) . '%',
				$limit
			),
			ARRAY_A
		);

		$found = [];
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['option_name'] ?? null ) || ! is_string( $row['option_value'] ?? null ) ) {
				continue;
			}
			$command_id = substr( $row['option_name'], strlen( self::OPTION_PREFIX ) );
			if ( self::is_valid_id( $command_id ) ) {
				$found[] = [ $command_id, $row['option_value'] ];
			}
		}

		return $found;
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

		$is_list = ArrayShape::is_list( $value );
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

	/**
	 * Options dont le nom commence par le préfixe (enregistrements, créneau…), SCAN_LIMIT
	 * au plus, avec leur valeur brute. Vide si la base refuse la lecture.
	 *
	 * Le « _ » du préfixe est échappé : non échappé, il vaudrait n'importe quel caractère
	 * dans LIKE, et la recherche attraperait les options d'une autre extension.
	 *
	 * @return list<array{0: string, 1: string}> [ nom, valeur brute ].
	 */
	private static function scan(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recherche par préfixe d'options non autochargées, jamais lues par get_option() : aucun cache pertinent.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				self::SCAN_LIMIT
			),
			ARRAY_A
		);

		$found = [];
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) && is_string( $row['option_name'] ?? null ) && is_string( $row['option_value'] ?? null ) ) {
				$found[] = [ $row['option_name'], $row['option_value'] ];
			}
		}

		return $found;
	}

	/**
	 * Date à partir de laquelle compte l'âge d'une exécution terminée : sa fin ; à défaut,
	 * la plus récente de ses dates connues (le dernier signe de vie suit le démarrage, qui
	 * suit l'acceptation). Sans aucune date, 0 : la plus ancienne possible.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function finished_at( array $record ): int {
		foreach ( [ 'finished_at', 'alive_at', 'started_at', 'accepted_at' ] as $key ) {
			if ( is_int( $record[ $key ] ?? null ) ) {
				return $record[ $key ];
			}
		}

		return 0;
	}

	private static function is_valid_id( string $command_id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $command_id );
	}
}
