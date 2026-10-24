<?php
/**
 * Lecture commune du fichier de vecteurs du format chiffré des sauvegardes (G2BK v1).
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup;

/**
 * Le fichier tests/fixtures/backup-format-vectors.json est une copie, à l'octet, de
 * backend/tests/fixtures/backup-format-vectors.json du dépôt de la plateforme, prise au commit de
 * la tâche 5 du plan de la phase 2 (4195d67b), et non dans une copie de travail qui a pu avancer.
 * Il est produit uniquement par le générateur Python indépendant de la plateforme
 * (tools/backup-format-vectors/generer_vecteurs.py), contrôlé contre les RFC 9180, 7748, 8032 et
 * 5869 : régénéré par ce script, jamais modifié à la main.
 *
 * Aucun test du connecteur ne compare cette copie à celle de la plateforme : depuis une copie de
 * travail (.claude/worktrees/), le dépôt voisin n'est pas à côté, et un tel test serait toujours
 * sauté. Le contrôle réel des copies est le `cmp` du plan (rappel transverse n° 11), lancé à chaque
 * tâche qui crée ou lit une copie.
 *
 * Lecture stricte (JSON_THROW_ON_ERROR, tableau à la racine), faite une fois par processus.
 */
final class VectorsFixture {

	private const FILE = __DIR__ . '/../fixtures/backup-format-vectors.json';

	/**
	 * Contenu décodé, mémorisé.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Tout le fichier décodé.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$data ) {
			$json = file_get_contents( self::FILE );
			if ( false === $json ) {
				throw new \RuntimeException( 'Fichier de vecteurs illisible : ' . self::FILE . '. Cause probable : copie absente (tâche 12 du plan). Action : la recopier depuis le commit de la tâche 5 de la plateforme.' );
			}
			$data = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'Fichier de vecteurs sans objet à la racine : ' . self::FILE . '. Action : le régénérer par le script, jamais à la main.' );
			}
			/** @var array<string, mixed> $data */
			self::$data = $data;
		}
		return self::$data;
	}

	/**
	 * Une section du fichier (`self_test`, `hpke`, `index`, `index_tampering`…).
	 *
	 * @return array<mixed>
	 */
	public static function section( string $name ): array {
		$all = self::all();
		if ( ! isset( $all[ $name ] ) || ! is_array( $all[ $name ] ) ) {
			throw new \RuntimeException( 'Section « ' . $name . ' » absente du fichier de vecteurs.' );
		}
		return $all[ $name ];
	}

	/**
	 * Le cas nommé `$name` d'une section qui est une liste de cas.
	 *
	 * @return array<string, mixed>
	 */
	public static function named( string $section, string $name ): array {
		foreach ( self::section( $section ) as $case ) {
			if ( is_array( $case ) && ( $case['name'] ?? null ) === $name ) {
				/** @var array<string, mixed> $case */
				return $case;
			}
		}
		throw new \RuntimeException( 'Cas « ' . $name . ' » absent de la section « ' . $section . ' ».' );
	}

	/**
	 * Octets d'une valeur hexadécimale du fichier (minuscules, longueur paire : sinon exception).
	 */
	public static function bin( mixed $hex ): string {
		if ( ! is_string( $hex ) || 1 !== preg_match( '/\A(?:[0-9a-f]{2})*\z/', $hex ) ) {
			throw new \RuntimeException( 'Valeur hexadécimale attendue (minuscules, longueur paire) dans le fichier de vecteurs.' );
		}
		return (string) hex2bin( $hex );
	}
}
