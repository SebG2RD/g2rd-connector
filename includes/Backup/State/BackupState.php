<?php
/**
 * État de la sauvegarde du site (contrat « État » du plan de la phase 4) : une option JSON de la
 * table des options du site principal, `g2rd_backup_state`, lue et écrite par CommandRuns\OptionRow
 * (sans cache, écritures conditionnelles sur la valeur relue). Chaque écriture augmente `rev` : une
 * valeur identique ne passe jamais pour « rien écrit ».
 *
 * Phases : idle → db → files → upload → finalize → idle ; abort. Seules `db` et `files` (la copie)
 * sont interrompues par une mise à jour (choix n° 3 du plan).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Backup\State;

use G2RD\Connector\CommandRuns\OptionRow;
use G2RD\Connector\CommandRuns\StoreUnavailable;

final class BackupState {

	public const OPTION = 'g2rd_backup_state';

	public const IDLE = 'idle';

	public const DB = 'db';

	public const FILES = 'files';

	public const UPLOAD = 'upload';

	public const FINALIZE = 'finalize';

	public const ABORT = 'abort';

	public const PHASES = [ self::IDLE, self::DB, self::FILES, self::UPLOAD, self::FINALIZE, self::ABORT ];

	private const MAX_ATTEMPTS = 3;

	/**
	 * État lu en base, avec sa valeur brute pour l'écriture conditionnelle.
	 *
	 * @return array{raw: string|null, state: array<string, mixed>}
	 * @throws StoreUnavailable La base a refusé la lecture.
	 */
	public static function load(): array {
		$raw = MainSite::run( static fn (): ?string => OptionRow::read_raw( self::OPTION ) );

		return [
			'raw'   => $raw,
			'state' => self::decode( $raw ),
		];
	}

	/**
	 * État lu sans jamais lever : null si la lecture est impossible (base refusée, $wpdb absent).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function peek(): ?array {
		global $wpdb;
		// $wpdb absent ou incomplet (chargement partiel, faux $wpdb d'un test existant) : lecture
		// impossible, sans exception ni avertissement (« Undefined property » ferait échouer un test
		// existant de mise à jour sous failOnWarning). La porte reste alors transparente (choix n° 5).
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' )
			|| ! property_exists( $wpdb, 'last_error' ) || ! property_exists( $wpdb, 'options' ) ) {
			return null;
		}
		try {
			return self::load()['state'];
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Écrit l'état si l'option vaut encore $raw (null : si elle est absente).
	 *
	 * @param array<string, mixed> $state
	 * @return string|null Nouvelle valeur brute ; null si un autre processus a écrit entre-temps ou
	 *                     si la base refuse.
	 */
	public static function save( ?string $raw, array $state ): ?string {
		$state['v']   = 1;
		$state['rev'] = ( is_int( $state['rev'] ?? null ) ? $state['rev'] : 0 ) + 1;
		$json         = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return null;
		}

		$written = MainSite::run(
			static fn () => null === $raw
				? OptionRow::insert_ignore( self::OPTION, $json )
				: OptionRow::replace( self::OPTION, $raw, $json )
		);

		return 1 === $written ? $json : null;
	}

	/**
	 * État de repos.
	 *
	 * @param array<string, mixed>|null $last Issue de la dernière sauvegarde.
	 * @return array<string, mixed>
	 */
	public static function idle( ?array $last = null ): array {
		return [
			'v'         => 1,
			'rev'       => 0,
			'phase'     => self::IDLE,
			'backup_id' => null,
			'last'      => $last,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function decode( ?string $raw ): array {
		if ( null === $raw ) {
			return self::idle();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! in_array( $data['phase'] ?? null, self::PHASES, true ) ) {
			return self::idle();
		}

		/** @var array<string, mixed> $data */
		return $data;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function is_active( array $state ): bool {
		return self::IDLE !== ( $state['phase'] ?? self::IDLE );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function is_capturing( array $state ): bool {
		return in_array( $state['phase'] ?? null, [ self::DB, self::FILES ], true );
	}

	/**
	 * Passe une sauvegarde en cours de copie à `abort`, par l'écriture conditionnelle sur la valeur
	 * relue (trois essais). Rend vrai si cet appel l'a interrompue.
	 */
	public static function abort_if_capturing( string $reason, int $now ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$loaded = self::load();
			} catch ( \Throwable ) {
				return false;
			}
			if ( ! self::is_capturing( $loaded['state'] ) ) {
				return false;
			}
			$state               = $loaded['state'];
			$state['phase']      = self::ABORT;
			$state['reason']     = $reason;
			$state['aborted_at'] = $now;
			if ( null !== self::save( $loaded['raw'], $state ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Passe à `abort` une sauvegarde active (copie, envoi ou fin) commencée à $since ou après : elle
	 * a démarré pendant une mise à jour passée sans verrou (porte transparente, mise à jour faite hors
	 * de la plateforme) et mêlerait un état d'avant et un état d'après (décision n° 16 ; second
	 * filet, tâche 13). Écriture conditionnelle sur la valeur relue, trois essais. Rend vrai si cet
	 * appel l'a interrompue.
	 */
	public static function abort_if_started_since( int $since, string $reason, int $now ): bool {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$loaded = self::load();
			} catch ( \Throwable ) {
				return false;
			}
			$state = $loaded['state'];
			if ( ! in_array( $state['phase'] ?? null, [ self::DB, self::FILES, self::UPLOAD, self::FINALIZE ], true )
				|| ! is_int( $state['created_at'] ?? null ) || $state['created_at'] < $since ) {
				return false;
			}
			$state['phase']      = self::ABORT;
			$state['reason']     = $reason;
			$state['aborted_at'] = $now;
			if ( null !== self::save( $loaded['raw'], $state ) ) {
				return true;
			}
		}

		return false;
	}
}
