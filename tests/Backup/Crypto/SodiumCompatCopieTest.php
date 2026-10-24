<?php
/**
 * Copie de sodium_compat chargée par les tests : celle que WordPress livre au parc.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Crypto;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Sans sodium natif, le connecteur calcule X25519 et Ed25519 avec la copie de sodium_compat que
 * livre WordPress (wp-includes/sodium_compat). Les cas « compat » des tests (groupe
 * sodium-compat) ne valent donc que s'ils tournent contre cette copie-là, défauts compris.
 *
 * Relevé du 24/10/2026 (tâche 12 du plan de la phase 2) : les 24 sites du parc sont en
 * WordPress 7.1.3, dont la copie est identique, fichier pour fichier, à sodium_compat 1.24.0
 * (comme celles de 6.9.10 et 7.0.7) ; WordPress 6.4, le minimum du connecteur, livre une copie
 * proche de 1.20.1. Dans ces deux copies, le contrôle du sous-groupe principal de
 * pk_to_curve25519 et de la vérification des signatures ne refuse rien (avis
 * PKSA-32g2-byr9-drtw, corrigé en 1.24.1) : les tests doivent le voir, pas une version corrigée.
 *
 * D'où la dépendance de développement épinglée sur 1.24.0 (composer.json) et ce test, qui échoue
 * si la copie chargée n'est pas celle attendue :
 * - suite normale : la copie de vendor, d'empreinte EMPREINTE_PARC. Une montée de version le fait
 *   échouer : elle suit la copie de WordPress du parc, pas Packagist ;
 * - rejeu des cas « compat » contre une autre copie (celle de WordPress 6.4, ou la dernière de la
 *   ligne) : la copie est chargée avant celle de vendor, et son dossier est donné dans
 *   G2RD_SODIUM_COMPAT_COPIE :
 *
 *       G2RD_SODIUM_COMPAT_COPIE=<dossier> php -d auto_prepend_file=<dossier>/autoload.php vendor/bin/phpunit --group sodium-compat
 *
 *   (PowerShell : $env:G2RD_SODIUM_COMPAT_COPIE = '<dossier>', puis la commande sans le préfixe.)
 *   <dossier> est le wp-includes/sodium_compat d'un paquet officiel de WordPress, ou une
 *   étiquette de paragonie/sodium_compat, extrait dans un dossier temporaire. Le chargement
 *   anticipé est nécessaire : PHPUnit charge vendor/autoload.php, donc la copie de vendor, avant
 *   tests/bootstrap.php. Sans la variable, aucun rejeu : la suite ne connaît que vendor. En rejeu,
 *   le test écrit sur la sortie d'erreur la copie rejouée (nom et empreinte), pour que la trace
 *   le dise même quand tout passe.
 *
 * Empreinte d'une copie : SHA-256 de la liste triée « chemin \0 SHA-256 du contenu \n » des
 * fichiers de src/, lib/, namespaced/, autoload.php et autoload-php7.php, fins de ligne en LF.
 *
 * Relevé à refaire (Dependabot ignore ce paquet, personne d'autre ne préviendra) : à chaque
 * nouvelle version majeure ou mineure de WordPress sur le parc, recalculer l'empreinte du
 * wp-includes/sodium_compat de son paquet officiel. Si elle change, mettre EMPREINTE_PARC,
 * EMPREINTES_CONNUES et l'épinglage de composer.json à jour, puis rejouer le groupe
 * sodium-compat contre l'ancienne copie (commande ci-dessus).
 */
#[Group( 'sodium-compat' )]
final class SodiumCompatCopieTest extends TestCase {

	/** Copie de WordPress 6.9.10, 7.0.7 et 7.1.3, identique à sodium_compat 1.24.0 (106 fichiers). */
	private const EMPREINTE_PARC = 'abc1c2afd0f23ff09bc209bf15971fa22222bfeba5bf4b7c91e4de0ea11b0c7f';

	/** Copies relevées le 24/10/2026, pour un message d'échec qui dise laquelle est chargée. */
	private const EMPREINTES_CONNUES = [
		self::EMPREINTE_PARC                                               => 'sodium_compat 1.24.0, copie de WordPress 6.9.10 à 7.1.3 (le parc)',
		'95b4940f131f274447da994f9bd9067ea96094ec9b8f08863b0f7b2b84025f5f' => 'copie de WordPress 6.4 à 6.4.13 (proche de sodium_compat 1.20.1)',
		'3eff45ed26c1311f09f7552100e27ef2358f89037aa091936cfd864c80190aec' => 'sodium_compat 1.20.1',
		'f35b98614d8e2acfaaa40083dbec159edc716391b71e46fb08107b637af2f94d' => 'sodium_compat 1.24.2',
	];

	/**
	 * Classes dont le code compte pour X25519 et Ed25519 : toutes doivent venir de la même copie.
	 * Les classes Core32 n'y sont pas : sodium_compat ne les emploie qu'en PHP 32 bits.
	 */
	private const CLASSES = [
		'ParagonIE_Sodium_Compat',
		'ParagonIE_Sodium_Core_Ed25519',
		'ParagonIE_Sodium_Core_Curve25519',
		'ParagonIE_Sodium_Core_X25519',
		'ParagonIE_Sodium_Core_Util',
	];

	public function test_la_copie_chargee_est_celle_attendue(): void {
		$dossier   = self::dossier_charge();
		$empreinte = self::empreinte( $dossier );
		$nom       = ( self::EMPREINTES_CONNUES[ $empreinte ] ?? 'copie inconnue' ) . ', empreinte ' . $empreinte;
		$demandee  = getenv( 'G2RD_SODIUM_COMPAT_COPIE' );

		if ( false === $demandee || '' === $demandee ) {
			self::assertSame(
				self::chemin( dirname( __DIR__, 3 ) . '/vendor/paragonie/sodium_compat' ),
				$dossier,
				'sodium_compat chargé depuis ' . $dossier . ' (' . $nom . ') au lieu de vendor. Cause probable : '
				. 'un chargement anticipé (auto_prepend_file) sans G2RD_SODIUM_COMPAT_COPIE. Action : définir '
				. 'G2RD_SODIUM_COMPAT_COPIE pour un rejeu, ou relancer sans auto_prepend_file.'
			);
			self::assertSame(
				self::EMPREINTE_PARC,
				$empreinte,
				'La copie de développement de sodium_compat (' . $nom . ') n\'est plus celle que WordPress livre au '
				. 'parc (1.24.0 dans WordPress 6.9.10 à 7.1.3). Cause probable : une montée de version de '
				. 'paragonie/sodium_compat dans composer.json. Action : la garder épinglée sur la copie du parc ; '
				. 'ne la changer qu\'avec un nouveau relevé des versions de WordPress des sites.'
			);
			return;
		}

		self::assertSame(
			self::chemin( $demandee ),
			$dossier,
			'Rejeu demandé contre ' . $demandee . ', mais sodium_compat est chargé depuis ' . $dossier . ' (' . $nom
			. '). Cause probable : la copie n\'est pas chargée avant vendor. Action : lancer '
			. 'php -d auto_prepend_file=<dossier>/autoload.php vendor/bin/phpunit --group sodium-compat.'
		);

		// Trace du rejeu, même réussi : la sortie d'erreur n'est pas comptée comme une sortie du
		// test (beStrictAboutOutputDuringTests ne regarde que la sortie standard).
		if ( defined( 'STDERR' ) ) {
			fwrite( STDERR, PHP_EOL . 'Rejeu sodium-compat : copie ' . $dossier . ' (' . $nom . ')' . PHP_EOL );
		}
	}

	/**
	 * Dossier de la copie qui a fourni les classes de CLASSES (une seule copie admise).
	 */
	private static function dossier_charge(): string {
		$racine = null;
		foreach ( self::CLASSES as $classe ) {
			self::assertTrue( class_exists( $classe ), 'Classe ' . $classe . ' introuvable : sodium_compat n\'est pas installé (composer install).' );
			$fichier = ( new \ReflectionClass( $classe ) )->getFileName();
			self::assertIsString( $fichier, 'Classe ' . $classe . ' sans fichier source.' );
			$fichier = self::chemin( $fichier );
			$position = strrpos( $fichier, '/src/' );
			self::assertNotFalse( $position, 'Classe ' . $classe . ' hors d\'un dossier src/ de sodium_compat : ' . $fichier );
			$dossier = substr( $fichier, 0, $position );
			if ( null === $racine ) {
				$racine = $dossier;
			}
			self::assertSame( $racine, $dossier, 'Deux copies de sodium_compat mêlées : ' . $classe . ' vient de ' . $dossier . ', les autres de ' . $racine . '.' );
		}
		self::assertIsString( $racine );
		return $racine;
	}

	/**
	 * Empreinte d'une copie (voir le docblock de la classe).
	 */
	private static function empreinte( string $dossier ): string {
		$fichiers = [];
		foreach ( [ 'autoload.php', 'autoload-php7.php' ] as $fichier ) {
			if ( is_file( $dossier . '/' . $fichier ) ) {
				$fichiers[] = $fichier;
			}
		}
		foreach ( [ 'src', 'lib', 'namespaced' ] as $sous_dossier ) {
			if ( ! is_dir( $dossier . '/' . $sous_dossier ) ) {
				continue;
			}
			$parcours = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dossier . '/' . $sous_dossier, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $parcours as $fichier ) {
				if ( $fichier instanceof \SplFileInfo && $fichier->isFile() ) {
					$fichiers[] = substr( str_replace( '\\', '/', $fichier->getPathname() ), strlen( $dossier ) + 1 );
				}
			}
		}
		sort( $fichiers, SORT_STRING );
		$liste = '';
		foreach ( $fichiers as $relatif ) {
			$contenu = file_get_contents( $dossier . '/' . $relatif );
			self::assertIsString( $contenu, 'Fichier illisible : ' . $dossier . '/' . $relatif );
			$liste .= $relatif . "\0" . hash( 'sha256', str_replace( "\r\n", "\n", $contenu ) ) . "\n";
		}
		return hash( 'sha256', $liste );
	}

	/**
	 * Chemin absolu réel, séparateurs « / » (Windows compris).
	 */
	private static function chemin( string $chemin ): string {
		$reel = realpath( $chemin );
		self::assertIsString( $reel, 'Chemin introuvable : ' . $chemin );
		return rtrim( str_replace( '\\', '/', $reel ), '/' );
	}
}
