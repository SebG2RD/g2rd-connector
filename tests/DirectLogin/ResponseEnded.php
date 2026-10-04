<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\DirectLogin;

/**
 * Levée par les doublures de wp_die() (`die`) et wp_safe_redirect() (`redirect`) :
 * en production, ces deux issues terminent la requête.
 */
final class ResponseEnded extends \RuntimeException {

	/**
	 * @param string               $kind   `die` ou `redirect`.
	 * @param string               $target Message de wp_die() ou adresse de redirection.
	 * @param string               $title  Titre de la page wp_die().
	 * @param array<string, mixed> $args   Arguments de wp_die().
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $target,
		public readonly string $title = '',
		public readonly array $args = []
	) {
		parent::__construct( $kind . ' : ' . $target );
	}
}
