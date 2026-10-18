<?php
/**
 * Forme d'un tableau PHP : liste (clés 0, 1, 2… dans l'ordre) ou tableau à clés.
 *
 * Même définition que la fonction native array_is_list de PHP 8.1. Pourquoi ne pas
 * l'appeler : Plugin Check la prend pour la fonction de compatibilité que WordPress
 * n'apporte qu'à partir de la 6.5, et la refuse avec « Requires at least: 6.4 », alors
 * qu'elle est native dans PHP 8.1, le minimum du connecteur. Ce double évite la fausse
 * alerte sans relever le minimum de WordPress ni assouplir le contrôle.
 *
 * Aucun effet au chargement.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Support;

final class ArrayShape {

	/**
	 * Vrai si les clés sont exactement 0, 1, 2… dans cet ordre (un tableau vide en est
	 * une). S'arrête à la première clé qui ne suit pas.
	 *
	 * @param array<mixed> $value Tableau à examiner.
	 */
	public static function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}

		return true;
	}
}
