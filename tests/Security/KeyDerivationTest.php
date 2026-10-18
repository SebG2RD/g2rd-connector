<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Security;

use G2RD\Connector\Security\DirectLoginTicket;
use G2RD\Connector\Security\KeyDerivation;
use G2RD\Connector\Security\RequestSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Classe pure : pas de Brain Monkey ici.
 *
 * Les vecteurs ont été calculés par une implémentation .NET indépendante
 * (HMACSHA256), pas par le code testé. Le risque couvert : inverser la clé et le
 * message du HMAC changerait toutes les clés dérivées, donc toutes les signatures
 * et tous les tickets de connexion, et couperait le parc entier.
 */
final class KeyDerivationTest extends TestCase {

	private const VECTORS = __DIR__ . '/../fixtures/key-derivation-vectors.json';

	/**
	 * @return list<array{name: string, site_token: string, context: string, key_hex: string}>
	 */
	private static function vectors(): array {
		$json = json_decode( (string) file_get_contents( self::VECTORS ), true, 16, JSON_THROW_ON_ERROR );
		/** @var list<array{name: string, site_token: string, context: string, key_hex: string}> $vectors */
		$vectors = $json['vectors'];
		return $vectors;
	}

	/**
	 * Jetons des vecteurs calculés pour un contexte donné.
	 *
	 * @return list<string>
	 */
	private static function tokens_for( string $context ): array {
		$tokens = [];
		foreach ( self::vectors() as $vector ) {
			if ( $context === $vector['context'] ) {
				$tokens[] = $vector['site_token'];
			}
		}
		return $tokens;
	}

	/**
	 * @return iterable<string, array{array{name: string, site_token: string, context: string, key_hex: string}}>
	 */
	public static function independent_vectors(): iterable {
		foreach ( self::vectors() as $vector ) {
			yield $vector['name'] => [ $vector ];
		}
	}

	/**
	 * @param array{name: string, site_token: string, context: string, key_hex: string} $vector
	 */
	#[DataProvider( 'independent_vectors' )]
	public function test_les_vecteurs_independants_sont_reproduits_a_l_octet( array $vector ): void {
		self::assertSame( $vector['key_hex'], bin2hex( KeyDerivation::derive( $vector['site_token'], $vector['context'] ) ) );
	}

	public function test_le_fichier_couvre_les_quatre_jetons_dans_les_trois_contextes(): void {
		self::assertCount( 12, self::vectors() );
		foreach ( KeyDerivation::CONTEXTS as $context ) {
			self::assertSame(
				[ 'test-site-token-0123456789abcdef', '', str_repeat( 'ab', 32 ), "jeton-\u{e9}-\u{2713}" ],
				self::tokens_for( $context ),
				$context
			);
		}
	}

	public function test_la_signature_derive_exactement_comme_avant(): void {
		foreach ( self::tokens_for( 'g2rd-sign-v1' ) as $token ) {
			$key = KeyDerivation::derive( $token, KeyDerivation::CONTEXT_SIGN );

			self::assertSame( $key, RequestSignature::derive_key( $token ) );
			// Formule d'avant la factorisation, écrite en clair : clé HMAC = jeton, message = contexte.
			self::assertSame( hash_hmac( 'sha256', 'g2rd-sign-v1', $token, true ), $key );
		}
		self::assertSame( 'g2rd-sign-v1', RequestSignature::KEY_CONTEXT );
	}

	public function test_le_ticket_de_connexion_derive_exactement_comme_avant(): void {
		foreach ( self::tokens_for( 'g2rd-login-v1' ) as $token ) {
			$key = KeyDerivation::derive( $token, KeyDerivation::CONTEXT_LOGIN );

			self::assertSame( $key, DirectLoginTicket::derive_key( $token ) );
			// Formule d'avant la factorisation, écrite en clair : clé HMAC = jeton, message = contexte.
			self::assertSame( hash_hmac( 'sha256', 'g2rd-login-v1', $token, true ), $key );
		}
		self::assertSame( 'g2rd-login-v1', DirectLoginTicket::KEY_CONTEXT );
	}

	public function test_les_trois_contextes_donnent_trois_cles_differentes(): void {
		self::assertSame( [ 'g2rd-sign-v1', 'g2rd-login-v1', 'g2rd-backup-v1' ], KeyDerivation::CONTEXTS );

		$keys = array_map(
			static fn ( string $context ): string => KeyDerivation::derive( 'test-site-token-0123456789abcdef', $context ),
			KeyDerivation::CONTEXTS
		);

		self::assertCount( 3, array_unique( $keys ) );
	}

	public function test_un_contexte_inconnu_est_refuse(): void {
		$this->expectException( \InvalidArgumentException::class );

		KeyDerivation::derive( 'test-site-token-0123456789abcdef', 'g2rd-autre-v1' );
	}

	public function test_la_cle_fait_32_octets(): void {
		foreach ( KeyDerivation::CONTEXTS as $context ) {
			self::assertSame( 32, strlen( KeyDerivation::derive( 'test-site-token-0123456789abcdef', $context ) ), $context );
		}
	}

	public function test_le_contexte_des_sauvegardes_n_a_aucun_appelant(): void {
		$racine    = dirname( __DIR__, 2 );
		$appelants = [];
		$iterateur = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $racine . '/includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterateur as $fichier ) {
			if ( ! $fichier instanceof \SplFileInfo || 'php' !== $fichier->getExtension() ) {
				continue;
			}
			$relatif = str_replace( '\\', '/', substr( $fichier->getPathname(), strlen( $racine ) + 1 ) );
			if ( 'includes/Security/KeyDerivation.php' === $relatif ) {
				continue;
			}
			$source = (string) file_get_contents( $fichier->getPathname() );
			if ( str_contains( $source, 'CONTEXT_BACKUP' ) || str_contains( $source, 'g2rd-backup-v1' ) ) {
				$appelants[] = $relatif;
			}
		}

		self::assertFileExists( $racine . '/includes/Security/KeyDerivation.php' );
		self::assertSame( [], $appelants, 'Le contexte g2rd-backup-v1 est réservé à la phase 2 des sauvegardes : aucun appelant avant elle.' );
	}
}
