<?php
/**
 * Relevé des erreurs fatales de PHP à l'arrêt du script (évolution stratégique, § 6 ; décision
 * du propriétaire : fonction d'arrêt seule en v1, ni set_error_handler ni mu-plugin).
 *
 * Armé dans g2rd-connector.php, AVANT plugins_loaded : sa fonction d'arrêt est la première du
 * connecteur. Elle passe après celles que WordPress enregistre au chargement (son gestionnaire
 * d'erreurs fatales, l'action `shutdown`) : une erreur sans gravité levée là cache l'erreur
 * fatale (limite déclarée par `coverage`, comme dans CommandRuns\AfterResponseRunner l.52-63).
 *
 * Trois passages, pour ne jamais gêner les filets existants :
 *  1. on_shutdown(), premier : lit error_get_last() et, pour une erreur fatale, programme
 *     defer(). Rien d'autre : aucune lecture de la base, aucune classe chargée, aucune erreur
 *     possible ; le relevé du dernier filet de AfterResponseRunner, qui passe juste après, voit
 *     toujours l'erreur fatale ;
 *  2. defer(), après les filets (reprise, réactivation, relevé du dernier filet) : programme
 *     flush(), que PHP ajoute APRÈS le dernier filet (mark_interrupted_on_shutdown()) ;
 *  3. flush(), tout à la fin : normalise, filtre et écrit dans le tampon. Une écriture qui
 *     échoue, même par manque de mémoire, ne peut plus empêcher un filet de passer.
 *
 * Ne fait rien tant que le relevé n'est pas activé (`php_errors_enabled`) ou que le site n'est
 * pas enrôlé. Ne lève jamais.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\PhpErrors;

use G2RD\Connector\Settings;

final class FatalCatcher {

	/** Types relevés (Normalizer::FATAL_TYPES et E_RECOVERABLE_ERROR), recopiés : le premier passage ne charge aucune classe. */
	private const CAUGHT_TYPES = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];

	/** Instance armée pour cette requête. */
	private static ?self $armed = null;

	/** @var \Closure(): mixed */
	private \Closure $last_error;

	/** @var \Closure(callable): void */
	private \Closure $register;

	/** @var \Closure(): int */
	private \Closure $clock;

	/** @var array<mixed>|null Erreur relevée au premier passage, en attente d'écriture. */
	private ?array $pending = null;

	/**
	 * @param (\Closure(): mixed)|null         $last_error error_get_last() (tests).
	 * @param (\Closure(callable): void)|null $register   register_shutdown_function() (tests).
	 * @param (\Closure(): int)|null           $clock      time() (tests).
	 */
	public function __construct( ?\Closure $last_error = null, ?\Closure $register = null, ?\Closure $clock = null ) {
		$this->last_error = $last_error ?? static fn (): mixed => error_get_last();
		$this->register   = $register ?? static function ( callable $callback ): void {
			register_shutdown_function( $callback );
		};
		$this->clock      = $clock ?? static fn (): int => time();
	}

	/** Arme le relevé pour cette requête, une seule fois, s'il est activé. */
	public static function arm(): void {
		try {
			if ( null !== self::$armed || ! self::enabled() ) {
				return;
			}
			self::$armed = new self();
			self::$armed->listen();
		} catch ( \Throwable ) {
			// Réglages illisibles : rien n'est relevé, le site n'en est pas gêné.
			self::$armed = null;
		}
	}

	/**
	 * Relevé activé ET site enrôlé, lu dans les réglages autochargés (aucune requête SQL).
	 */
	public static function enabled(): bool {
		$settings = get_option( Settings::OPTION_KEY, [] );

		return is_array( $settings )
			&& true === ( $settings['php_errors_enabled'] ?? false )
			&& ! empty( $settings['site_id'] )
			&& ! empty( $settings['site_token'] );
	}

	public function listen(): void {
		( $this->register )( [ $this, 'on_shutdown' ] );
	}

	/** Premier passage : relève, programme la suite, rien d'autre. */
	public function on_shutdown(): void {
		try {
			$last = ( $this->last_error )();
			if ( ! is_array( $last ) || ! in_array( $last['type'] ?? null, self::CAUGHT_TYPES, true ) ) {
				return;
			}
			$this->pending = $last;
			( $this->register )( [ $this, 'defer' ] );
		} catch ( \Throwable ) {
			$this->pending = null;
		}
	}

	/** Deuxième passage, après les filets : programme l'écriture après le dernier filet. */
	public function defer(): void {
		if ( null === $this->pending ) {
			return;
		}
		try {
			( $this->register )( [ $this, 'flush' ] );
		} catch ( \Throwable ) {
			$this->pending = null;
		}
	}

	/**
	 * Dernier passage : écrit l'erreur dans le tampon, une seule fois. La version du composant
	 * n'est calculée que si le groupe est à écrire (au plus une fois par minute et par empreinte,
	 * cf. Buffer::record()).
	 */
	public function flush(): void {
		$last          = $this->pending;
		$this->pending = null;
		if ( null === $last ) {
			return;
		}
		try {
			$occurrence = Normalizer::occurrence_from_error( $last );
			if ( null === $occurrence ) {
				return;
			}
			$type = $occurrence['type'];
			$slug = $occurrence['slug'];
			Buffer::record( $occurrence, ( $this->clock )(), static fn (): ?string => Normalizer::component_version( $type, $slug ) );
		} catch ( \Throwable ) {
			// Base injoignable, fonction de WordPress absente : l'erreur reste dans le journal PHP du site.
			return;
		}
	}
}
