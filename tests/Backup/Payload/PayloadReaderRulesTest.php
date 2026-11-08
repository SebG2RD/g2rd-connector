<?php
/**
 * Règles du lecteur de la charge utile (docs/backup-payload.md § 2, dépôt de la plateforme) que les
 * flux altérés des vecteurs ne montrent pas : date et taille à partir de 2^63, chemins finissant par
 * « / » ou à composant « . », chemin manquant sur un dossier, un lien ou un « supprimé », chemin sur le
 * manifeste ou la fin, DEFLATE de longueur nulle, flux sans fin. Chaque cas est accompagné de son flux
 * valide le plus proche, accepté : le refus vient de la règle visée et d'elle seule.
 *
 * Classe pure : pas de Brain Monkey ici.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Payload;

use G2RD\Connector\Backup\Payload\PayloadError;
use G2RD\Connector\Backup\Payload\PayloadFormat;
use G2RD\Connector\Backup\Payload\PayloadReader;
use PHPUnit\Framework\TestCase;

final class PayloadReaderRulesTest extends TestCase {

	private static function fin(): string {
		return PayloadFormat::header( PayloadFormat::T_END, 0, 0, 0, 0, 0, 0, 0, 0 );
	}

	/** Enregistrement avec les huit champs à la main : 'J' écrit un entier négatif en 2^64 + valeur (≥ 2^63). */
	private static function brut( int $type, int $comp, string $chemin, string $stocke, int $brut, int $decalage, int $total, int $mtime, int $mode ): string {
		return pack( 'CCnNNJJJN', $type, $comp, strlen( $chemin ), strlen( $stocke ), $brut, $decalage, $total, $mtime, $mode ) . $chemin . $stocke;
	}

	private static function fichier( string $chemin = 'a.txt', int $total = 1, int $mtime = 1 ): string {
		return self::brut( PayloadFormat::T_FILE, PayloadFormat::C_STORED, $chemin, 'x', 1, 0, $total, $mtime, 0644 );
	}

	/** @return array<string, array{0: string}> */
	public static function flux_refuses(): array {
		return [
			'taille déclarée à 2^63'                 => [ PayloadFormat::MAGIC . self::fichier( 'a.txt', -1 ) . self::fin() ],
			'taille déclarée à PHP_INT_MIN'          => [ PayloadFormat::MAGIC . self::fichier( 'a.txt', PHP_INT_MIN ) . self::fin() ],
			'date à 2^63'                            => [ PayloadFormat::MAGIC . self::fichier( 'a.txt', 1, PHP_INT_MIN ) . self::fin() ],
			'date à 2^64 - 1'                        => [ PayloadFormat::MAGIC . self::fichier( 'a.txt', 1, -1 ) . self::fin() ],
			'chemin qui finit par /'                 => [ PayloadFormat::MAGIC . self::fichier( 'd/' ) . self::fin() ],
			'chemin à composant « . »'               => [ PayloadFormat::MAGIC . self::fichier( 'd/./a' ) . self::fin() ],
			'chemin à composant « .. » au milieu'    => [ PayloadFormat::MAGIC . self::fichier( 'd/../a' ) . self::fin() ],
			'chemin à composant vide au milieu'      => [ PayloadFormat::MAGIC . self::fichier( 'd//a' ) . self::fin() ],
			'dossier sans chemin'                    => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_DIR, 0, '', '', 0, 0, 0, 1, 0755 ) . self::fin() ],
			'lien sans chemin'                       => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_LINK, 0, '', 'cible', 5, 0, 0, 1, 0777 ) . self::fin() ],
			'supprimé sans chemin'                   => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_DELETED, 0, '', '', 0, 0, 0, 0, 0 ) . self::fin() ],
			'chemin sur le manifeste'                => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_MANIFEST, 0, 'm', 'x', 1, 0, 0, 0, 0 ) . self::fin() ],
			'chemin sur la fin'                      => [ PayloadFormat::MAGIC . pack( 'CCnNNJJJN', PayloadFormat::T_END, 0, 1, 0, 0, 0, 0, 0, 0 ) . 'a' ],
			'fin avec une longueur stockée'          => [ PayloadFormat::MAGIC . pack( 'CCnNNJJJN', PayloadFormat::T_END, 0, 0, 1, 0, 0, 0, 0, 0 ) . 'a' ],
			'fin avec un mode'                       => [ PayloadFormat::MAGIC . pack( 'CCnNNJJJN', PayloadFormat::T_END, 0, 0, 0, 0, 0, 0, 0, 0644 ) ],
			'DEFLATE de longueur brute nulle'        => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_SQL, PayloadFormat::C_DEFLATE, '', "\x03\x00", 0, 0, 0, 0, 0 ) . self::fin() ],
			'magie seule, sans fin'                  => [ PayloadFormat::MAGIC ],
			'flux vide'                              => [ '' ],
			'fichier dont le morceau dépasse la taille' => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_FILE, 0, 'a', 'xy', 2, 0, 1, 1, 0644 ) . self::fin() ],
			'décalage et morceau au-delà de la taille' => [ PayloadFormat::MAGIC . self::brut( PayloadFormat::T_FILE, 0, 'a', 'x', 1, 5, 5, 1, 0644 ) . self::fin() ],
		];
	}

	/** @dataProvider flux_refuses */
	public function test_flux_refuse( string $flux ): void {
		$this->expectException( PayloadError::class );
		PayloadReader::parse( $flux );
	}

	public function test_les_cas_voisins_valides_sont_acceptes(): void {
		$valides = [
			PayloadFormat::MAGIC . self::fin(),
			PayloadFormat::MAGIC . self::fichier( 'd/a.txt', 1, 1 ) . self::fin(),
			PayloadFormat::MAGIC . self::fichier( 'a.txt', PHP_INT_MAX, PHP_INT_MAX ) . self::fin(),
			PayloadFormat::MAGIC . self::brut( PayloadFormat::T_DIR, 0, 'd', '', 0, 0, 0, 1, 0755 ) . self::fin(),
			PayloadFormat::MAGIC . self::brut( PayloadFormat::T_DELETED, 0, 'd/e', '', 0, 0, 0, 0, 0 ) . self::fin(),
			PayloadFormat::MAGIC . self::brut( PayloadFormat::T_MANIFEST, 0, '', 'x', 1, 0, 0, 0, 0 ) . self::fin(),
		];
		foreach ( $valides as $i => $flux ) {
			self::assertIsArray( PayloadReader::parse( $flux ), 'flux valide n° ' . $i );
		}
		self::assertCount( 0, PayloadReader::parse( $valides[0] ) );
	}
}
