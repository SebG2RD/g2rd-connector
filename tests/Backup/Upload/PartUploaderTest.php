<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Backup\Upload;

use Brain\Monkey\Functions;
use G2RD\Connector\Backup\Upload\PartUploader;
use G2RD\Connector\Backup\Upload\PartUploadResult;
use G2RD\Connector\Tests\TestCase;

/**
 * Envoi d'une partie de sauvegarde vers une URL présignée (contrat « Envoi d'une partie »
 * du plan de la phase 3) : `PUT` des seuls octets demandés, `Content-MD5`, aucune
 * redirection, hôte ET port imposés par l'appelant, issues stables, URL signée jamais
 * recopiée dans un résultat.
 *
 * Les fonctions HTTP de WordPress sont simulées comme WordPress les définit
 * (wp-includes/http.php) : en-têtes lus sans tenir compte de la casse, code de statut
 * vide quand la réponse n'en porte pas.
 */
final class PartUploaderTest extends TestCase {

	private const HOTE = 'stockage.example';

	private const SIGNATURE = '5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7';

	/** `X-Amz-Date` des URL d'essai : samedi 24 octobre 2026, 12:00:00 UTC (horodatage 1792843200). */
	private const DATE_SIGNEE = '20261024T120000Z';

	private const VALIDITE = 900;

	private const ETAG = '"9e107d9d372bb6826bd81d3542a419d6"';

	/** @var list<array{url: string, args: array<string, mixed>}> Appels à wp_remote_request, dans l'ordre. */
	private array $appels = [];

	/** @var array<string, mixed>|\WP_Error Réponse rendue par wp_remote_request. */
	private $reponse;

	/** @var list<string> Fichiers temporaires créés par le test. */
	private array $fichiers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appels   = [];
		$this->fichiers = [];
		$this->reponse  = self::reponse( 200, [ 'etag' => self::ETAG ] );

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $valeur ): bool => $valeur instanceof \WP_Error );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->appels[] = [ 'url' => $url, 'args' => $args ];
				return $this->reponse;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $reponse ) {
				if ( $reponse instanceof \WP_Error || ! isset( $reponse['response']['code'] ) ) {
					return '';
				}
				return $reponse['response']['code'];
			}
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
	}

	protected function tearDown(): void {
		foreach ( $this->fichiers as $fichier ) {
			if ( is_file( $fichier ) ) {
				unlink( $fichier );
			}
		}
		parent::tearDown();
	}

	public function test_une_partie_envoyee_rend_son_etag(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

		self::assertSame( PartUploadResult::UPLOADED, $resultat->outcome );
		self::assertSame( 'uploaded', $resultat->outcome, 'chaîne stable' );
		self::assertSame( self::ETAG, $resultat->etag );
		self::assertSame( 200, $resultat->http_status );
		self::assertNull( $resultat->storage_code );
		self::assertSame( base64_encode( md5( 'abcdefghij', true ) ), $resultat->content_md5 );
		self::assertGreaterThanOrEqual( 0, $resultat->elapsed_ms );
		self::assertFalse( $resultat->is_retryable() );
		self::assertCount( 1, $this->appels );
		self::assertSame( self::url(), $this->appels[0]['url'], 'l\'URL signée est envoyée telle quelle' );
	}

	public function test_l_en_tete_content_md5_est_celui_des_octets_envoyes(): void {
		$octets  = random_bytes( 4096 );
		$fichier = $this->fichier( $octets );

		$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 4096, 30 );

		$args = $this->appels[0]['args'];
		self::assertSame( $octets, $args['body'] );
		self::assertSame(
			[
				'Content-MD5'  => base64_encode( md5( $octets, true ) ),
				'Content-Type' => 'application/octet-stream',
			],
			$args['headers']
		);
		self::assertSame( $args['headers']['Content-MD5'], $resultat->content_md5 );
	}

	public function test_la_requete_est_un_put_sans_redirection(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 37 );

		$args = $this->appels[0]['args'];
		self::assertSame( 'PUT', $args['method'] );
		self::assertSame( 0, $args['redirection'] );
		self::assertTrue( $args['sslverify'] );
		self::assertTrue( $args['reject_unsafe_urls'] );
		self::assertSame( 65536, $args['limit_response_size'] );
		self::assertSame( 37, $args['timeout'] );
		$cles = array_keys( $args );
		sort( $cles );
		self::assertSame(
			[ 'body', 'headers', 'limit_response_size', 'method', 'redirection', 'reject_unsafe_urls', 'sslverify', 'timeout' ],
			$cles,
			'aucun autre argument (ni flux vers un fichier, ni envoi sans attente)'
		);
	}

	public function test_un_hote_autre_que_celui_annonce_est_refuse_sans_envoi(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();

		foreach ( [
			'suffixe ajouté'   => [ self::url( 'https://stockage.example.evil' ), self::HOTE ],
			'autre hôte'       => [ self::url( 'https://evil.example' ), self::HOTE ],
			'sous-domaine'     => [ self::url( 'https://x.stockage.example' ), self::HOTE ],
			'hôte attendu vide' => [ self::url(), '' ],
		] as $cas => [ $url, $hote ] ) {
			$resultat = $uploader->upload( $url, $hote, null, $fichier, 0, 10, 30 );
			self::assertSame( PartUploadResult::BAD_URL, $resultat->outcome, $cas );
			self::assertSame( 0, $resultat->http_status, $cas );
			self::assertFalse( $resultat->is_retryable(), $cas );
		}
		self::assertSame( [], $this->appels, 'rien n\'est envoyé vers un hôte non annoncé' );

		$resultat = $uploader->upload( self::url( 'https://STOCKAGE.Example' ), self::HOTE, null, $fichier, 0, 10, 30 );
		self::assertSame( PartUploadResult::UPLOADED, $resultat->outcome, 'comparaison insensible à la casse' );
	}

	public function test_l_hote_et_le_port_annonces_sont_exiges(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$banc    = new PartUploader( false, true );

		self::assertSame( PartUploadResult::UPLOADED, $banc->upload( self::url( 'http://stockage:9000' ), 'stockage', 9000, $fichier, 0, 10, 30 )->outcome );
		self::assertCount( 1, $this->appels );

		foreach ( [
			'même hôte, autre port'       => [ self::url( 'http://stockage:9000' ), 9001 ],
			'port de l\'URL non annoncé'  => [ self::url( 'http://stockage:9000' ), null ],
			'URL sans le port attendu'    => [ self::url( 'http://stockage' ), 9000 ],
		] as $cas => [ $url, $port ] ) {
			self::assertSame( PartUploadResult::BAD_URL, $banc->upload( $url, 'stockage', $port, $fichier, 0, 10, 30 )->outcome, $cas );
		}
		self::assertCount( 1, $this->appels, 'aucun envoi vers un port non annoncé' );

		// Un port explicite égal au port par défaut du schéma vaut « aucun port ».
		self::assertSame( PartUploadResult::UPLOADED, ( new PartUploader() )->upload( self::url( 'https://hote:443' ), 'hote', null, $fichier, 0, 10, 30 )->outcome );
		self::assertSame( PartUploadResult::UPLOADED, ( new PartUploader() )->upload( self::url( 'https://hote' ), 'hote', 443, $fichier, 0, 10, 30 )->outcome );
		self::assertSame( PartUploadResult::UPLOADED, $banc->upload( self::url( 'http://stockage:80' ), 'stockage', null, $fichier, 0, 10, 30 )->outcome );
		self::assertSame( PartUploadResult::BAD_URL, ( new PartUploader() )->upload( self::url( 'https://hote:80' ), 'hote', null, $fichier, 0, 10, 30 )->outcome, '80 n\'est pas le port par défaut de https' );
		self::assertCount( 4, $this->appels );
	}

	public function test_http_est_refuse_sauf_banc(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		self::assertSame( PartUploadResult::BAD_URL, ( new PartUploader() )->upload( self::url( 'http://' . self::HOTE ), self::HOTE, null, $fichier, 0, 10, 30 )->outcome );
		self::assertSame( [], $this->appels );

		$banc = new PartUploader( false, true );
		self::assertSame( PartUploadResult::UPLOADED, $banc->upload( self::url( 'http://' . self::HOTE ), self::HOTE, null, $fichier, 0, 10, 30 )->outcome );
		self::assertFalse( $this->appels[0]['args']['reject_unsafe_urls'], 'le banc vise un conteneur du réseau local' );

		foreach ( [ 'ftp://' . self::HOTE, 'file://' . self::HOTE, '//' . self::HOTE ] as $origine ) {
			self::assertSame( PartUploadResult::BAD_URL, $banc->upload( self::url( $origine ), self::HOTE, null, $fichier, 0, 10, 30 )->outcome, $origine );
		}
		self::assertCount( 1, $this->appels );
	}

	public function test_une_url_sans_signature_est_refusee(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();

		foreach ( [
			'sans X-Amz-Signature'  => [ 'X-Amz-Signature' => null ],
			'sans X-Amz-Date'       => [ 'X-Amz-Date' => null ],
			'sans X-Amz-Expires'    => [ 'X-Amz-Expires' => null ],
			'signature vide'        => [ 'X-Amz-Signature' => '' ],
			'date illisible'        => [ 'X-Amz-Date' => 'hier' ],
			'date impossible'       => [ 'X-Amz-Date' => '20261332T250000Z' ],
			'validité négative'     => [ 'X-Amz-Expires' => '-1' ],
			'validité non numérique' => [ 'X-Amz-Expires' => '15min' ],
		] as $cas => $parametres ) {
			self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( self::url( 'https://' . self::HOTE, $parametres ), self::HOTE, null, $fichier, 0, 10, 30 )->outcome, $cas );
		}
		self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( 'https://' . self::HOTE . '/g2rd-backups/t/x', self::HOTE, null, $fichier, 0, 10, 30 )->outcome, 'aucun paramètre' );
		self::assertSame( [], $this->appels );
	}

	public function test_identifiants_ou_fragment_dans_l_url_refuses(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();
		$chemin   = substr( self::url(), strlen( 'https://' . self::HOTE ) );

		foreach ( [
			'utilisateur et mot de passe' => 'https://depot:secret@' . self::HOTE . $chemin,
			'utilisateur seul'            => 'https://depot@' . self::HOTE . $chemin,
			'identifiants vides'          => 'https://@' . self::HOTE . $chemin,
			'fragment'                    => self::url() . '#partie',
			'fragment vide'               => self::url() . '#',
		] as $cas => $url ) {
			self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( $url, self::HOTE, null, $fichier, 0, 10, 30 )->outcome, $cas );
		}
		self::assertSame( [], $this->appels );
	}

	/** Une URL présignée est en ASCII imprimable (encodage en pourcentage) : tout le reste est refusé. */
	public function test_url_aux_caracteres_non_imprimables_refusee(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();

		foreach ( [ ' ', "\t", "\r\n", "\0", "\x7F", 'é' ] as $caractere ) {
			$url = str_replace( '/g2rd-backups/', '/g2rd' . $caractere . 'backups/', self::url() );
			self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( $url, self::HOTE, null, $fichier, 0, 10, 30 )->outcome, bin2hex( $caractere ) );
		}
		self::assertSame( [], $this->appels );
	}

	public function test_lecture_bornee_a_la_partie_demandee(): void {
		$fichier  = $this->fichier( str_repeat( 'A', 7 ) . str_repeat( 'B', 7 ) . str_repeat( 'C', 5 ) );
		$uploader = new PartUploader();

		$resultat = $uploader->upload( self::url(), self::HOTE, null, $fichier, 7, 7, 30 );
		self::assertSame( 'BBBBBBB', $this->appels[0]['args']['body'], 'la deuxième partie seule' );
		self::assertSame( base64_encode( md5( 'BBBBBBB', true ) ), $resultat->content_md5 );

		$uploader->upload( self::url(), self::HOTE, null, $fichier, 14, 5, 30 );
		self::assertSame( 'CCCCC', $this->appels[1]['args']['body'], 'la dernière partie, plus courte, jusqu\'à la fin du fichier' );
	}

	public function test_fichier_illisible_ou_trop_court_rend_read_failed(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();

		foreach ( [
			'fichier absent'           => [ $fichier . '-absent', 0, 10 ],
			'dossier'                  => [ sys_get_temp_dir(), 0, 10 ],
			'chemin à octet nul'       => [ $fichier . "\0.txt", 0, 10 ],
			'un octet de trop'         => [ $fichier, 0, 11 ],
			'décalage à la fin'        => [ $fichier, 10, 1 ],
			'partie à cheval'          => [ $fichier, 8, 3 ],
			'décalage très au-delà'    => [ $fichier, PHP_INT_MAX, 1 ],
		] as $cas => [ $chemin, $decalage, $longueur ] ) {
			$resultat = $uploader->upload( self::url(), self::HOTE, null, $chemin, $decalage, $longueur, 30 );
			self::assertSame( PartUploadResult::READ_FAILED, $resultat->outcome, $cas );
			self::assertSame( 0, $resultat->http_status, $cas );
			self::assertSame( '', $resultat->content_md5, $cas );
			self::assertFalse( $resultat->is_retryable(), $cas );
		}
		self::assertSame( [], $this->appels );
	}

	public function test_taille_et_delai_hors_bornes_sont_des_erreurs_de_programmation(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$uploader = new PartUploader();

		foreach ( [
			'partie vide'          => [ 0, 0, 30, null ],
			'partie de 64 Mio + 1' => [ 0, PartUploader::MAX_PART_BYTES + 1, 30, null ],
			'longueur négative'    => [ 0, -1, 30, null ],
			'délai de 4 s'         => [ 0, 10, 4, null ],
			'délai de 121 s'       => [ 0, 10, 121, null ],
			'décalage négatif'     => [ -1, 10, 30, null ],
			'port 0'               => [ 0, 10, 30, 0 ],
			'port 65 536'          => [ 0, 10, 30, 65536 ],
		] as $cas => [ $decalage, $longueur, $delai, $port ] ) {
			try {
				$uploader->upload( self::url(), self::HOTE, $port, $fichier, $decalage, $longueur, $delai );
				self::fail( $cas . ' : exception attendue' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringNotContainsString( self::SIGNATURE, $e->getMessage(), $cas );
			}
		}
		self::assertSame( [], $this->appels );

		self::assertSame( 67108864, PartUploader::MAX_PART_BYTES );
		// Bornes atteintes, mais pas dépassées : aucune exception.
		self::assertSame( PartUploadResult::READ_FAILED, $uploader->upload( self::url(), self::HOTE, null, $fichier, 0, PartUploader::MAX_PART_BYTES, 30 )->outcome );
		self::assertSame( PartUploadResult::UPLOADED, $uploader->upload( self::url(), self::HOTE, null, $fichier, 0, 1, 5 )->outcome );
		self::assertSame( PartUploadResult::UPLOADED, $uploader->upload( self::url(), self::HOTE, null, $fichier, 0, 1, 120 )->outcome );
		self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( self::url(), self::HOTE, 1, $fichier, 0, 1, 30 )->outcome );
		self::assertSame( PartUploadResult::BAD_URL, $uploader->upload( self::url(), self::HOTE, 65535, $fichier, 0, 1, 30 )->outcome );
	}

	public function test_url_echue_selon_la_date_du_stockage(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$echeance = self::horodatage_signe() + self::VALIDITE;

		foreach ( [ 'à l\'échéance' => $echeance, 'une heure après' => $echeance + 3600 ] as $cas => $date_stockage ) {
			$this->reponse = self::reponse( 403, [ 'date' => gmdate( DATE_RFC7231, $date_stockage ) ], self::erreur_s3( 'AccessDenied' ) );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::EXPIRED_URL, $resultat->outcome, $cas );
			self::assertSame( 'expired_url', $resultat->outcome, $cas );
			self::assertTrue( $resultat->is_retryable(), $cas . ' : réessayable avec une URL neuve' );
			self::assertSame( 403, $resultat->http_status, $cas );
			self::assertSame( 'AccessDenied', $resultat->storage_code, $cas );
			self::assertNull( $resultat->etag, $cas );
		}
	}

	public function test_refus_403_avant_echeance_reste_refused(): void {
		$fichier  = $this->fichier( 'abcdefghij' );
		$echeance = self::horodatage_signe() + self::VALIDITE;

		foreach ( [
			'une seconde avant'   => [ 'date' => gmdate( DATE_RFC7231, $echeance - 1 ) ],
			'sans date'           => [],
			'date illisible'      => [ 'date' => 'demain' ],
			'jour de semaine faux' => [ 'date' => 'Mon, 24 Oct 2026 13:00:00 GMT' ],
			'autre fuseau'        => [ 'date' => gmdate( 'D, d M Y H:i:s', $echeance + 3600 ) . ' +0000' ],
			'plusieurs dates'     => [ 'date' => [ gmdate( DATE_RFC7231, $echeance + 3600 ), gmdate( DATE_RFC7231, $echeance + 7200 ) ] ],
		] as $cas => $entetes ) {
			$this->reponse = self::reponse( 403, $entetes, self::erreur_s3( 'AccessDenied' ) );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::REFUSED, $resultat->outcome, $cas );
			self::assertFalse( $resultat->is_retryable(), $cas );
			self::assertSame( 403, $resultat->http_status, $cas );
		}
	}

	public function test_5xx_rend_network_reessayable(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [ 500 => 'InternalError', 503 => 'SlowDown', 599 => null ] as $statut => $code ) {
			$this->reponse = self::reponse( $statut, [], null === $code ? '' : self::erreur_s3( $code ) );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::NETWORK, $resultat->outcome, (string) $statut );
			self::assertTrue( $resultat->is_retryable(), (string) $statut );
			self::assertSame( $statut, $resultat->http_status );
			self::assertSame( $code, $resultat->storage_code );
		}
	}

	/** Statut absent ou hors des classes 2xx à 5xx : réponse inexploitable, traitée comme un incident de transport. */
	public function test_statut_hors_des_classes_connues_rend_network(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [ 'sans statut' => null, '1xx final' => 101, 'au-delà de 5xx' => 600 ] as $cas => $statut ) {
			$this->reponse = self::reponse( $statut, [ 'etag' => self::ETAG ] );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::NETWORK, $resultat->outcome, $cas );
			self::assertNull( $resultat->etag, $cas );
		}
	}

	public function test_delai_curl_28_rend_too_slow(): void {
		$fichier       = $this->fichier( 'abcdefghij' );
		$this->reponse = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' );

		$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

		self::assertSame( PartUploadResult::TOO_SLOW, $resultat->outcome );
		self::assertSame( 'too_slow', $resultat->outcome );
		self::assertTrue( $resultat->is_retryable() );
		self::assertSame( 0, $resultat->http_status );
		self::assertNull( $resultat->etag );
		self::assertNull( $resultat->storage_code );
		self::assertSame( base64_encode( md5( 'abcdefghij', true ) ), $resultat->content_md5, 'les octets ont été lus et envoyés' );
	}

	public function test_autre_erreur_reseau_rend_network(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [
			'cURL error 7: Failed to connect to stockage.example port 443 after 3 ms: Connection refused',
			'cURL error 6: Could not resolve host: stockage.example',
			'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL',
			'cURL error 280: inconnu',
			'',
		] as $message ) {
			$this->reponse = new \WP_Error( 'http_request_failed', $message );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::NETWORK, $resultat->outcome, $message );
			self::assertTrue( $resultat->is_retryable(), $message );
			self::assertSame( 0, $resultat->http_status, $message );
		}
	}

	public function test_requete_non_executee_rend_bad_url(): void {
		$fichier       = $this->fichier( 'abcdefghij' );
		$this->reponse = new \WP_Error( 'http_request_not_executed', 'A valid URL was not provided.' );

		$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

		self::assertSame( PartUploadResult::BAD_URL, $resultat->outcome );
		self::assertFalse( $resultat->is_retryable() );
		self::assertSame( 0, $resultat->http_status );
	}

	public function test_200_sans_etag_rend_refused(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [
			'sans ETag'               => [],
			'ETag vide'               => [ 'etag' => '' ],
			'ETag de 131 caractères'  => [ 'etag' => '"' . str_repeat( 'a', 129 ) . '"' ],
			'ETag avec une espace'    => [ 'etag' => '"abc def"' ],
			'ETag avec un retour'     => [ 'etag' => "\"abc\"\n" ],
			'plusieurs ETag'          => [ 'etag' => [ self::ETAG, self::ETAG ] ],
		] as $cas => $entetes ) {
			$this->reponse = self::reponse( 200, $entetes );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::REFUSED, $resultat->outcome, $cas );
			self::assertNull( $resultat->etag, $cas );
			self::assertFalse( $resultat->is_retryable(), $cas );
		}

		$this->reponse = self::reponse( 200, [ 'etag' => '"' . str_repeat( 'a', 128 ) . '"' ] );
		self::assertSame( PartUploadResult::UPLOADED, ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 )->outcome, 'ETag de 130 caractères' );
		$this->reponse = self::reponse( 204, [ 'etag' => self::ETAG ] );
		self::assertSame( PartUploadResult::UPLOADED, ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 )->outcome, 'tout 2xx avec un ETag' );
	}

	public function test_redirection_ou_autre_4xx_rend_refused(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [
			301 => [ 'location' => 'https://evil.example/' ],
			307 => [ 'location' => 'https://' . self::HOTE . '/ailleurs' ],
			400 => [],
			404 => [],
			409 => [],
			499 => [],
		] as $statut => $entetes ) {
			$this->reponse = self::reponse( $statut, $entetes + [ 'etag' => self::ETAG ] );

			$resultat = ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 );

			self::assertSame( PartUploadResult::REFUSED, $resultat->outcome, (string) $statut );
			self::assertSame( $statut, $resultat->http_status );
			self::assertNull( $resultat->etag, (string) $statut );
		}
		self::assertCount( 6, $this->appels, 'une requête par envoi, aucune redirection suivie' );
	}

	public function test_code_d_erreur_s3_lu_et_borne(): void {
		$fichier = $this->fichier( 'abcdefghij' );

		foreach ( [
			'code ordinaire'      => [ self::erreur_s3( 'SignatureDoesNotMatch' ), 'SignatureDoesNotMatch' ],
			'64 lettres'          => [ self::erreur_s3( str_repeat( 'A', 64 ) ), str_repeat( 'A', 64 ) ],
			'65 lettres'          => [ self::erreur_s3( str_repeat( 'A', 65 ) ), null ],
			'chevrons'            => [ '<Error><Code>Access<b>Denied</b></Code></Error>', null ],
			'chevrons échappés'   => [ self::erreur_s3( '&lt;script&gt;' ), null ],
			'chiffres'            => [ self::erreur_s3( 'Error500' ), null ],
			'code vide'           => [ self::erreur_s3( '' ), null ],
			'sans code'           => [ '<Error><Message>Access Denied</Message></Error>', null ],
			'corps vide'          => [ '', null ],
			'corps qui n\'est pas du XML' => [ 'Service indisponible', null ],
		] as $cas => [ $corps, $attendu ] ) {
			$this->reponse = self::reponse( 400, [], $corps );

			self::assertSame( $attendu, ( new PartUploader() )->upload( self::url(), self::HOTE, null, $fichier, 0, 10, 30 )->storage_code, $cas );
		}
	}

	public function test_l_url_signee_n_apparait_dans_aucun_resultat(): void {
		$fichier = $this->fichier( 'abcdefghij' );
		$url     = self::url();
		// Erreur S3 « SignatureDoesNotMatch » : elle recopie la requête canonique et la signature reçue.
		$echo_s3 = '<Error><Code>SignatureDoesNotMatch</Code><SignatureProvided>' . self::SIGNATURE . '</SignatureProvided>'
			. '<CanonicalRequest>PUT ' . htmlspecialchars( $url ) . '</CanonicalRequest></Error>';

		$scenarios = [
			'envoyée'         => self::reponse( 200, [ 'etag' => self::ETAG ] ),
			'refusée'         => self::reponse( 403, [ 'date' => 'Sat, 24 Oct 2026 12:01:00 GMT' ], $echo_s3 ),
			'échue'           => self::reponse( 403, [ 'date' => 'Sat, 24 Oct 2026 13:00:00 GMT' ], $echo_s3 ),
			'erreur réseau'   => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to ' . $url ),
			'non exécutée'    => new \WP_Error( 'http_request_not_executed', 'A valid URL was not provided: ' . $url ),
		];
		$resultats = [];
		foreach ( $scenarios as $cas => $reponse ) {
			$this->reponse      = $reponse;
			$resultats[ $cas ] = ( new PartUploader() )->upload( $url, self::HOTE, null, $fichier, 0, 10, 30 );
		}
		$resultats['URL refusée']    = ( new PartUploader() )->upload( $url . '#x', self::HOTE, null, $fichier, 0, 10, 30 );
		$resultats['lecture échouée'] = ( new PartUploader() )->upload( $url, self::HOTE, null, $fichier . '-absent', 0, 10, 30 );

		foreach ( $resultats as $cas => $resultat ) {
			foreach ( [
				'var_export' => var_export( $resultat, true ),
				'print_r'    => print_r( $resultat, true ),
				'serialize'  => serialize( $resultat ),
				'json'       => (string) json_encode( $resultat ),
			] as $forme => $texte ) {
				self::assertStringNotContainsString( self::SIGNATURE, $texte, $cas . ' / ' . $forme );
				self::assertStringNotContainsString( 'X-Amz-', $texte, $cas . ' / ' . $forme );
				self::assertStringNotContainsString( self::HOTE, $texte, $cas . ' / ' . $forme );
			}
		}
	}

	public function test_le_resultat_refuse_une_issue_inconnue(): void {
		$this->expectException( \InvalidArgumentException::class );

		new PartUploadResult( 'stored', null, 0, null, '', 0 );
	}

	/**
	 * URL présignée d'essai, de la forme que rend la plateforme (`presignPart`).
	 *
	 * @param array<string, string|null> $parametres Paramètres remplacés (null : retiré).
	 */
	private static function url( string $origine = 'https://' . self::HOTE, array $parametres = [] ): string {
		$tous = array_merge(
			[
				'partNumber'          => '2',
				'uploadId'            => 'VXBsb2FkSWQtZXhlbXBsZQ',
				'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
				'X-Amz-Credential'    => 'DEPOTEXEMPLE/20261024/fr-par/s3/aws4_request',
				'X-Amz-Date'          => self::DATE_SIGNEE,
				'X-Amz-Expires'       => (string) self::VALIDITE,
				'X-Amz-SignedHeaders' => 'host',
				'X-Amz-Signature'     => self::SIGNATURE,
			],
			$parametres
		);

		return $origine . '/g2rd-backups/t/' . str_repeat( 'a', 32 ) . '/s/' . str_repeat( 'b', 32 ) . '/' . str_repeat( 'c', 32 ) . '/0?'
			. http_build_query( array_filter( $tous, static fn ( ?string $valeur ): bool => null !== $valeur ), '', '&', PHP_QUERY_RFC3986 );
	}

	private static function horodatage_signe(): int {
		$date = \DateTimeImmutable::createFromFormat( '!Ymd\THis\Z', self::DATE_SIGNEE, new \DateTimeZone( 'UTC' ) );
		self::assertInstanceOf( \DateTimeImmutable::class, $date );
		return $date->getTimestamp();
	}

	/**
	 * Réponse de wp_remote_request, comme WordPress la rend.
	 *
	 * @param array<string, string|list<string>> $entetes
	 * @return array<string, mixed>
	 */
	private static function reponse( ?int $statut, array $entetes = [], string $corps = '' ): array {
		return [
			'headers'  => $entetes,
			'body'     => $corps,
			'response' => null === $statut ? [] : [ 'code' => $statut, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private static function erreur_s3( string $code ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<Error><Code>' . $code . '</Code><Message>Message du stockage</Message><RequestId>4442587FB7D0A2F9</RequestId></Error>';
	}

	private function fichier( string $contenu ): string {
		$chemin = tempnam( sys_get_temp_dir(), 'g2rd-partie-' );
		self::assertIsString( $chemin );
		file_put_contents( $chemin, $contenu );
		$this->fichiers[] = $chemin;
		return $chemin;
	}
}
