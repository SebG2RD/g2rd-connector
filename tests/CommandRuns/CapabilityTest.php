<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\CommandRuns;

use G2RD\Connector\CommandRuns\Capability;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Capacité `async_commands` (§ 3.6 de la spécification) : annoncée dans l'inventaire
 * seulement si les routes sont enregistrées (commandes à distance permises), si le serveur
 * sait terminer une réponse avant la fin du script (`litespeed_finish_request` ou
 * `fastcgi_finish_request`) et si la constante d'arrêt n'est pas posée. Sinon, la
 * plateforme reste en mode actuel (`/command`) pour ce site (décision n° 6).
 *
 * La détection des fonctions de fin est injectée : un test ne déclare jamais ces deux
 * fonctions (Brain Monkey les définirait pour tout le reste du processus PHPUnit).
 */
final class CapabilityTest extends TestCase {

	public function test_annoncee_avec_litespeed(): void {
		self::assertSame( 'async_commands', Capability::NAME );

		self::assertTrue( Capability::available( self::seule( 'litespeed_finish_request' ) ) );
	}

	public function test_annoncee_avec_fastcgi(): void {
		self::assertTrue( Capability::available( self::seule( 'fastcgi_finish_request' ) ) );
	}

	/**
	 * Apache + mod_php (et la ligne de commande) : la réponse ne partirait qu'à la fin de la
	 * commande, le renvoi du CDN à 60 s resterait possible. Rien n'est annoncé.
	 */
	public function test_absente_sans_fonction_de_fin(): void {
		self::assertFalse( Capability::available( static fn ( string $name ): bool => false ) );
		// Détection réelle (function_exists()) : PHPUnit tourne en ligne de commande, où
		// aucune des deux fonctions n'existe.
		self::assertFalse( Capability::available() );
	}

	/**
	 * `define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );` retire la capacité : la
	 * plateforme repasse le site en mode actuel à la synchronisation suivante. Processus à
	 * part : une constante définie le reste jusqu'à la fin du processus PHP.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_absente_avec_la_constante_d_arret(): void {
		define( 'G2RD_CONNECTOR_DISABLE_ASYNC_COMMANDS', true );

		self::assertFalse( Capability::available( self::seule( 'litespeed_finish_request' ) ) );
	}

	/** Commandes à distance coupées dans les réglages : les routes ne sont pas enregistrées. */
	public function test_absente_si_les_commandes_distantes_sont_coupees(): void {
		$this->options[ Settings::OPTION_KEY ] = [ 'remote_commands_enabled' => false ];

		self::assertFalse( Capability::available( self::seule( 'litespeed_finish_request' ) ) );
	}

	/**
	 * Détection où seule la fonction nommée existe.
	 *
	 * @return \Closure(string): bool
	 */
	private static function seule( string $fonction ): \Closure {
		return static fn ( string $name ): bool => $fonction === $name;
	}
}
