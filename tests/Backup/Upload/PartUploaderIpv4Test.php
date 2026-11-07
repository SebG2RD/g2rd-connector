<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Upload;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Upload\PartUploader;
use G2RD\Connector\Backup\Upload\PartUploadResult;
use G2RD\Connector\Outbound\ManagerIpv4Guard;
use G2RD\Connector\Tests\TestCase;

/**
 * IPv4 forcée vers l'hôte de stockage, pour la seule durée de l'envoi d'une partie
 * (incident IPv6 du 25/09 : le CDN de l'hébergeur renvoyait 403 à l'adresse IPv6
 * sortante du serveur ; ManagerIpv4Guard ne couvre que l'hôte du manager).
 *
 * WordPress est simulé comme il se comporte (wp-includes/plugin.php et
 * class-wp-http-requests-hooks.php) : add_action et remove_action tiennent un registre
 * des crochets, où une fermeture se retrouve par l'objet lui-même ; wp_remote_request
 * déclenche `http_api_curl` sur les crochets inscrits à ce moment-là, avec un vrai
 * handle cURL, les arguments et l'URL de la requête.
 *
 * Deux méthodes sont observées par Patchwork (chargé par Brain Monkey), sans rien
 * changer à ce qu'elles font : ManagerIpv4Guard::applies_to() (arguments et décision)
 * et PartUploader::force_ipv4_to_storage() (IPv4 posée ou non sur le handle).
 */
final class PartUploaderIpv4Test extends TestCase {

	private const SIGNATURE = '5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7';

	private const ETAG = '"9e107d9d372bb6826bd81d3542a419d6"';

	/** @var list<array{0: string, 1: list<mixed>}> Appels à add_action, wp_remote_request et remove_action, dans l'ordre. */
	private array $journal = [];

	/** @var array<int, array{0: string, 1: callable, 2: int, 3: int}> Crochets inscrits, par objet (spl_object_id). */
	private array $crochets = [];

	/** Nombre de crochets `http_api_curl` déclenchés pendant les requêtes. */
	private int $declenches_pendant_la_requete = 0;

	/** @var list<array{url: string, cible: string, rendu: bool}> Appels à ManagerIpv4Guard::applies_to(). */
	private array $decisions = [];

	/** @var list<bool> Issues de PartUploader::force_ipv4_to_storage() : IPv4 posée ou non. */
	private array $ipv4_posee = [];

	/** @var array<string, mixed>|\WP_Error|\Throwable Réponse rendue (ou exception levée) par wp_remote_request. */
	private $reponse;

	/** @var list<string> Fichiers temporaires créés par le test. */
	private array $fichiers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->journal                       = [];
		$this->crochets                      = [];
		$this->declenches_pendant_la_requete = 0;
		$this->decisions                     = [];
		$this->ipv4_posee                    = [];
		$this->fichiers                      = [];
		$this->reponse                       = self::reponse( 200, [ 'etag' => self::ETAG ] );

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $valeur ): bool => $valeur instanceof \WP_Error );
		Functions\when( 'add_action' )->alias(
			function ( string $crochet, $fonction, int $priorite = 10, int $nb_arguments = 1 ): bool {
				$this->journal[]                          = [ 'add_action', [ $crochet, $fonction, $priorite, $nb_arguments ] ];
				$this->crochets[ self::cle( $fonction ) ] = [ $crochet, $fonction, $priorite, $nb_arguments ];
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( string $crochet, $fonction, int $priorite = 10 ): bool {
				$this->journal[] = [ 'remove_action', [ $crochet, $fonction, $priorite ] ];
				$cle             = self::cle( $fonction );
				if ( ! isset( $this->crochets[ $cle ] ) || $crochet !== $this->crochets[ $cle ][0] || $priorite !== $this->crochets[ $cle ][2] ) {
					return false;
				}
				unset( $this->crochets[ $cle ] );
				return true;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->journal[] = [ 'wp_remote_request', [ $url ] ];
				// Comme WP_HTTP_Requests_Hooks avant l'envoi (curl.before_send) : un vrai handle.
				$handle = curl_init();
				foreach ( $this->crochets as [ $crochet, $fonction ] ) {
					if ( 'http_api_curl' === $crochet ) {
						++$this->declenches_pendant_la_requete;
						$fonction( $handle, $args, $url );
					}
				}
				if ( $this->reponse instanceof \Throwable ) {
					throw $this->reponse;
				}
				return $this->reponse;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $reponse ) => ( $reponse instanceof \WP_Error || ! isset( $reponse['response']['code'] ) ) ? '' : $reponse['response']['code']
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static function ( $reponse, string $nom ) {
				if ( $reponse instanceof \WP_Error || ! isset( $reponse['headers'] ) ) {
					return '';
				}
				foreach ( $reponse['headers'] as $cle => $valeur ) {
					if ( strtolower( (string) $cle ) === strtolower( $nom ) ) {
						return $valeur;
					}
				}
				return '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $reponse ) => ( $reponse instanceof \WP_Error || ! isset( $reponse['body'] ) ) ? '' : $reponse['body']
		);

		// Observées, pas remplacées : le vrai code décide et pose l'IPv4 sur le vrai handle.
		\Patchwork\redefine(
			[ ManagerIpv4Guard::class, 'applies_to' ],
			function ( string $url, string $manager_url ): bool {
				$rendu             = (bool) \Patchwork\relay();
				$this->decisions[] = [ 'url' => $url, 'cible' => $manager_url, 'rendu' => $rendu ];
				return $rendu;
			}
		);
		\Patchwork\redefine(
			[ PartUploader::class, 'force_ipv4_to_storage' ],
			function (): bool {
				$posee              = (bool) \Patchwork\relay();
				$this->ipv4_posee[] = $posee;
				return $posee;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->fichiers as $fichier ) {
			if ( is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}
		parent::tearDown();
	}

	public function test_ipv4_forcee_seulement_vers_l_hote_de_stockage(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$url     = self::url( 'http://stockage:9000' );

		$resultat = ( new PartUploader( true, true ) )->upload( $url, 'stockage', 9000, $fichier, 0, 10, 30 );

		self::assertSame( PartUploadResult::UPLOADED, $resultat->outcome );
		self::assertSame( [ 'add_action', 'wp_remote_request', 'remove_action' ], array_column( $this->journal, 0 ), 'crochet posé avant la requête, retiré après' );
		[ $crochet, $fermeture, $priorite, $nb_arguments ] = $this->journal[0][1];
		self::assertSame( 'http_api_curl', $crochet );
		self::assertInstanceOf( \Closure::class, $fermeture );
		self::assertSame( 10, $priorite );
		self::assertSame( 3, $nb_arguments, 'handle, arguments et URL de la requête' );
		self::assertSame( [ 'http_api_curl', $fermeture, 10 ], $this->journal[2][1], 'la même fermeture est retirée, à la même priorité' );
		self::assertSame( [], $this->crochets, 'aucun crochet ne survit à la requête' );

		// Pendant la requête : IPv4 posée sur le handle de la requête vers `stockage:9000`.
		self::assertSame( 1, $this->declenches_pendant_la_requete );
		self::assertSame( [ true ], $this->ipv4_posee );
		self::assertSame( [ [ 'url' => $url, 'cible' => 'https://stockage', 'rendu' => true ] ], $this->decisions, 'applies_to() appelée avec l\'hôte seul, sans le port' );

		// Toute requête lancée pendant ce temps vers un autre hôte n'est pas touchée.
		$this->decisions  = [];
		$this->ipv4_posee = [];
		$handle           = curl_init();
		self::assertInstanceOf( \CurlHandle::class, $handle );
		$attendus = [
			'http://stockage:9000/g2rd-backups/t/x?partNumber=3' => true,
			'http://STOCKAGE:9000/autre'                         => true,
			'https://stockage/sans-port'                         => true,
			'http://stockage.evil:9000/g2rd-backups/t/x'         => false,
			'http://x.stockage:9000/'                            => false,
			'http://autre:9000/'                                 => false,
			'https://api.wordpress.org/core/version-check/1.7/'  => false,
			'https://wp-manager.g2rd.fr/api/agent/heartbeat'     => false,
			'https://evil.example/?h=stockage'                   => false,
		];
		foreach ( array_keys( $attendus ) as $autre_url ) {
			$fermeture( $handle, [], $autre_url );
		}
		self::assertSame( array_keys( $attendus ), array_column( $this->decisions, 'url' ) );
		self::assertSame( array_fill( 0, count( $attendus ), 'https://stockage' ), array_column( $this->decisions, 'cible' ) );
		self::assertSame( array_values( $attendus ), array_column( $this->decisions, 'rendu' ), 'vraie pour l\'hôte annoncé, quel que soit le port ; fausse pour tout autre' );
		self::assertSame( array_values( $attendus ), $this->ipv4_posee, 'IPv4 posée exactement quand applies_to() le dit' );
	}

	public function test_ipv4_forcee_vers_l_hote_de_stockage_en_https(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$url     = self::url( 'https://s3.fr-par.scw.cloud' );

		$resultat = ( new PartUploader( true ) )->upload( $url, 's3.fr-par.scw.cloud', null, $fichier, 0, 10, 30 );

		self::assertSame( PartUploadResult::UPLOADED, $resultat->outcome );
		self::assertSame( [ true ], $this->ipv4_posee );
		self::assertSame( [ [ 'url' => $url, 'cible' => 'https://s3.fr-par.scw.cloud', 'rendu' => true ] ], $this->decisions );
		self::assertSame( [], $this->crochets );
	}

	public function test_le_crochet_est_retire_meme_en_cas_d_erreur(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader( true );

		// Erreur rendue par WordPress.
		$this->reponse = new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to stockage.example port 443' );
		self::assertSame( PartUploadResult::NETWORK, $uploader->upload( self::url(), 'stockage.example', null, $fichier, 0, 10, 30 )->outcome );
		$this->assert_crochet_pose_puis_retire( 'erreur WordPress' );

		// Exception levée pendant la requête (lecture ou envoi du corps par le transport,
		// filtre d'une autre extension) : elle remonte telle quelle, le crochet est retiré.
		$exception     = new \RuntimeException( 'lecture du corps interrompue' );
		$this->reponse = $exception;
		try {
			$uploader->upload( self::url(), 'stockage.example', null, $fichier, 0, 10, 30 );
			self::fail( 'exception attendue' );
		} catch ( \RuntimeException $levee ) {
			self::assertSame( $exception, $levee, 'l\'exception n\'est ni avalée ni remplacée' );
		}
		$this->assert_crochet_pose_puis_retire( 'exception pendant la requête' );

		// Succès.
		$this->reponse = self::reponse( 200, [ 'etag' => self::ETAG ] );
		self::assertSame( PartUploadResult::UPLOADED, $uploader->upload( self::url(), 'stockage.example', null, $fichier, 0, 10, 30 )->outcome );
		$this->assert_crochet_pose_puis_retire( 'succès' );

		// Rien n'est envoyé : aucun crochet n'est posé (la partie est lue, et l'URL
		// contrôlée, avant la pose).
		self::assertSame( PartUploadResult::READ_FAILED, $uploader->upload( self::url(), 'stockage.example', null, $fichier . '-absent', 0, 10, 30 )->outcome );
		self::assertSame( PartUploadResult::READ_FAILED, $uploader->upload( self::url(), 'stockage.example', null, $fichier, 0, 11, 30 )->outcome );
		self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( self::url(), 'autre.example', null, $fichier, 0, 10, 30 )->outcome );
		self::assertSame( [], $this->journal, 'ni crochet ni requête quand rien n\'est envoyé' );
		self::assertSame( [], $this->crochets );
	}

	public function test_sans_le_reglage_aucun_crochet(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [
			'par défaut'   => [ new PartUploader(), self::url(), 'stockage.example', null ],
			'réglage faux' => [ new PartUploader( false ), self::url(), 'stockage.example', null ],
			'banc'         => [ new PartUploader( false, true ), self::url( 'http://stockage:9000' ), 'stockage', 9000 ],
		] as $cas => [ $uploader, $url, $hote, $port ] ) {
			$this->journal = [];

			$this->reponse = self::reponse( 200, [ 'etag' => self::ETAG ] );
			self::assertSame( PartUploadResult::UPLOADED, $uploader->upload( $url, $hote, $port, $fichier, 0, 10, 30 )->outcome, $cas );
			$this->reponse = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			self::assertSame( PartUploadResult::TOO_SLOW, $uploader->upload( $url, $hote, $port, $fichier, 0, 10, 30 )->outcome, $cas );

			self::assertSame( [ 'wp_remote_request', 'wp_remote_request' ], array_column( $this->journal, 0 ), $cas . ' : ni add_action ni remove_action' );
		}
		self::assertSame( 0, $this->declenches_pendant_la_requete );
		self::assertSame( [], $this->ipv4_posee );
		self::assertSame( [], $this->decisions );
	}

	public function test_un_handle_qui_n_est_pas_curl_est_ignore(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$url     = self::url();
		( new PartUploader( true ) )->upload( $url, 'stockage.example', null, $fichier, 0, 10, 30 );
		$fermeture = $this->journal[0][1][1];
		self::assertInstanceOf( \Closure::class, $fermeture );
		$this->decisions  = [];
		$this->ipv4_posee = [];

		// WordPress en fsockopen, ou un autre code qui déclencherait le crochet à la main.
		$ressource = fopen( 'php://memory', 'rb' );
		$handles   = [
			'chaîne'    => 'pas-un-handle',
			'null'      => null,
			'objet'     => new \stdClass(),
			'ressource' => $ressource,
			'entier'    => 42,
		];
		foreach ( $handles as $handle ) {
			$fermeture( $handle, [], $url );
		}
		if ( is_resource( $ressource ) ) {
			fclose( $ressource );
		}
		// Un vrai handle, mais une URL qui n'est pas une chaîne.
		$handle       = curl_init();
		$pas_des_urls = [ null, 42, [ $url ] ];
		self::assertInstanceOf( \CurlHandle::class, $handle );
		foreach ( $pas_des_urls as $pas_une_url ) {
			$fermeture( $handle, [], $pas_une_url );
		}
		self::assertSame( array_fill( 0, count( $handles ) + count( $pas_des_urls ), false ), $this->ipv4_posee, 'aucune IPv4 posée, aucune erreur' );
		self::assertSame( [], $this->decisions, 'ignoré avant toute décision' );

		// Témoin : le même crochet, avec un vrai handle et l'URL de la requête, pose l'IPv4.
		$fermeture( $handle, [], $url );
		self::assertTrue( end( $this->ipv4_posee ) );
	}

	private function assert_crochet_pose_puis_retire( string $cas ): void {
		self::assertSame( [ 'add_action', 'wp_remote_request', 'remove_action' ], array_column( $this->journal, 0 ), $cas );
		$fermeture = $this->journal[0][1][1];
		self::assertInstanceOf( \Closure::class, $fermeture, $cas );
		self::assertSame( [ 'http_api_curl', $fermeture, 10, 3 ], $this->journal[0][1], $cas );
		self::assertSame( [ 'http_api_curl', $fermeture, 10 ], $this->journal[2][1], $cas . ' : la même fermeture' );
		self::assertSame( [], $this->crochets, $cas . ' : registre vide' );
		self::assertSame( [ true ], $this->ipv4_posee, $cas . ' : IPv4 posée pendant la requête' );
		$this->journal    = [];
		$this->ipv4_posee = [];
	}

	/** Clé d'un rappel dans le registre : l'objet lui-même, comme _wp_filter_build_unique_id() pour une fermeture. */
	private static function cle( $fonction ): int {
		self::assertIsObject( $fonction, 'le crochet est une fermeture' );
		return spl_object_id( $fonction );
	}

	/** URL présignée d'essai, de la forme que rend la plateforme (`presignPart`). */
	private static function url( string $origine = 'https://stockage.example' ): string {
		return $origine . '/g2rd-backups/t/' . str_repeat( 'a', 32 ) . '/s/' . str_repeat( 'b', 32 ) . '/' . str_repeat( 'c', 32 ) . '/0?'
			. http_build_query(
				[
					'partNumber'          => '2',
					'uploadId'            => 'VXBsb2FkSWQtZXhlbXBsZQ',
					'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
					'X-Amz-Credential'    => 'DEPOTEXEMPLE/20261024/fr-par/s3/aws4_request',
					'X-Amz-Date'          => '20261024T120000Z',
					'X-Amz-Expires'       => '900',
					'X-Amz-SignedHeaders' => 'host',
					'X-Amz-Signature'     => self::SIGNATURE,
				],
				'',
				'&',
				PHP_QUERY_RFC3986
			);
	}

	/**
	 * Réponse de wp_remote_request, comme WordPress la rend.
	 *
	 * @param array<string, string|list<string>> $entetes
	 * @return array<string, mixed>
	 */
	private static function reponse( int $statut, array $entetes = [], string $corps = '' ): array {
		return [
			'headers'  => $entetes,
			'body'     => $corps,
			'response' => [ 'code' => $statut, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private function fichier( string $contenu ): string {
		$chemin = tempnam( sys_get_temp_dir(), 'g2rd-partie-' );
		self::assertIsString( $chemin );
		file_put_contents( $chemin, $contenu );
		$this->fichiers[] = $chemin;
		return $chemin;
	}
}
