<?php

declare(strict_types=1);

namespace G2RD\Connector\Tests\Cron;

use Brain\Monkey\Functions;
use G2RD\Connector\Commands\CommandExecutor;
use G2RD\Connector\Cron\HeartbeatJob;
use G2RD\Connector\Rollback\RestorePointStore;
use G2RD\Connector\Security\RequestSignature;
use G2RD\Connector\Security\SignatureState;
use G2RD\Connector\Settings;
use G2RD\Connector\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Error;

/**
 * K1 — Les commandes de CommandExecutor::SIGNED_ONLY tirées par le cron depuis la
 * file du manager exigent la même preuve que par la route REST : une signature v1
 * valide, quelle que soit la politique du site. Avant ce correctif, une entrée
 * `{"id":42,"kind":"set_signature_policy"}` sans signature suffisait à faire
 * retomber un site de `required` à `report`.
 *
 * Les commandes historiques de la file (que le manager publie sans signer) doivent
 * rester strictement inchangées : c'est le trafic réel des 23 sites.
 */
final class HeartbeatJobSignedQueueTest extends TestCase {

	private const TOKEN   = 'jeton-du-site-0123456789';
	private const SITE_ID = 7;
	private const NOW     = 1789000000;

	/** Corps JSON renvoyé par GET /api/agent/sites/7/commands. */
	private string $queue = '{"commands":[]}';

	/** @var list<array{url:string, body:array<string, mixed>}> */
	private array $posts = [];

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->queue = '{"commands":[]}';
		$this->posts = [];
		$this->calls = [];

		// Jeton stocké en clair (valeur legacy, acceptée telle quelle par decrypt_token).
		$this->options[ Settings::OPTION_KEY ] = array_merge(
			Settings::defaults(),
			[
				'manager_url'       => 'https://manager.invalid',
				'site_id'           => self::SITE_ID,
				'site_token'        => self::TOKEN,
				'heartbeat_enabled' => false,
			]
		);

		Functions\when( 'is_wp_error' )->alias( static fn ( $v ): bool => $v instanceof WP_Error );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $r ) => $r['body'] );
		Functions\when( 'wp_remote_get' )->alias( fn (): array => [ 'body' => $this->queue ] );
		Functions\when( 'wp_remote_post' )->alias(
			function ( string $url, array $args ): array {
				$this->posts[] = [
					'url'  => $url,
					'body' => (array) json_decode( (string) $args['body'], true ),
				];
				return [ 'body' => '{"ok":true}' ];
			}
		);

		// Commandes historiques (clear_cache, check_updates) : on trace leur exécution.
		Functions\when( 'wp_cache_flush' )->alias( function (): bool { $this->calls[] = 'wp_cache_flush'; return true; } );
		Functions\when( 'wp_version_check' )->alias( function (): void { $this->calls[] = 'wp_version_check'; } );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'wp_update_plugins' )->justReturn( null );
		Functions\when( 'wp_update_themes' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_plugins' )->justReturn( [] );
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
	}

	// ── Le défaut : SIGNED_ONLY non signée ──────────────────────────────────────

	public function test_set_signature_policy_non_signee_ne_degrade_pas_la_politique_required(): void {
		$this->set_policy( 'required' );
		$this->queue( [ [ 'id' => 42, 'kind' => 'set_signature_policy' ] ] );

		$this->run_job();

		self::assertSame( 'required', Settings::get( 'signature_policy' ) );
		$post = $this->only_post();
		self::assertSame( 'https://manager.invalid/api/agent/sites/7/commands/42/result', $post['url'] );
		self::assertSame( 'failed', $post['body']['status'] );
		self::assertSame( 'g2rd_connector_signature_missing', $post['body']['result']['code'] );
		self::assertSame( 'absent', $post['body']['result']['signature_check']['status'] );
		self::assertIsString( $post['body']['error'] );
		self::assertStringContainsString( 'set_signature_policy', $post['body']['error'] );
		self::assertStringContainsString( 'sans signature', $post['body']['error'] );
	}

	public function test_set_signature_policy_non_signee_refusee_aussi_en_report(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'      => 42,
					'kind'    => 'set_signature_policy',
					'payload' => [ 'policy' => 'required' ],
				],
			]
		);

		$this->run_job();

		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
		self::assertSame( 'failed', $this->only_post()['body']['status'] );
	}

	/**
	 * Liste écrite en dur, et non lue dans CommandExecutor::SIGNED_ONLY : un
	 * fournisseur de données s'exécute à la collecte des tests, avant que Patchwork
	 * ne réécrive les fichiers chargés. Y charger CommandExecutor rendait son
	 * register_shutdown_function non simulable dans UpdatePluginLegacyTest (erreur
	 * fatale à l'arrêt de PHPUnit). Le test suivant vérifie que la liste est complète.
	 *
	 * @return iterable<string, array{string, array<string, mixed>}>
	 */
	public static function signed_only_commands(): iterable {
		yield 'rollback_plugin' => [ 'rollback_plugin', [ 'file' => 'akismet/akismet.php' ] ];
		yield 'delete_restore_point' => [ 'delete_restore_point', [ 'all' => true ] ];
		yield 'set_signature_policy' => [ 'set_signature_policy', [ 'policy' => 'report' ] ];
	}

	public function test_le_fournisseur_couvre_toutes_les_commandes_signed_only(): void {
		$covered = array_keys( iterator_to_array( self::signed_only_commands() ) );
		sort( $covered );
		$expected = CommandExecutor::SIGNED_ONLY;
		sort( $expected );
		self::assertSame( $expected, $covered );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	#[DataProvider( 'signed_only_commands' )]
	public function test_chaque_commande_signed_only_non_signee_est_refusee( string $kind, array $payload ): void {
		$this->set_policy( 'required' );
		// Un point de restauration existant : `delete_restore_point {all:true}` le supprimerait.
		$this->options[ RestorePointStore::OPTION_KEY ] = [
			'rp_1' => [
				'id'          => 'rp_1',
				'file'        => 'rp_1.zip',
				'plugin_file' => 'akismet/akismet.php',
				'created_at'  => self::NOW - 60,
				'size'        => 10,
			],
		];
		$this->queue(
			[
				[
					'id'      => 42,
					'kind'    => $kind,
					'payload' => $payload,
				],
			]
		);

		$this->run_job();

		$post = $this->only_post();
		self::assertSame( 'failed', $post['body']['status'] );
		self::assertSame( 'g2rd_connector_signature_missing', $post['body']['result']['code'] );
		self::assertSame( 1, SignatureState::stats()['failed_count'] );
		self::assertSame( 'signature_missing', SignatureState::stats()['last_code'] );
		self::assertArrayHasKey( 'rp_1', $this->options[ RestorePointStore::OPTION_KEY ], 'aucun point supprimé' );
		self::assertSame( 'required', Settings::get( 'signature_policy' ) );
	}

	// ── Le chemin légitime futur : SIGNED_ONLY signée ───────────────────────────

	public function test_signed_only_avec_signature_valide_est_executee_avec_le_payload_signe(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'kind'   => 'set_signature_policy',
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}' ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'required', Settings::get( 'signature_policy' ) );
		$post = $this->only_post();
		self::assertSame( 'done', $post['body']['status'] );
		self::assertSame( [ 'signature_policy' => 'required' ], $post['body']['result'] );
		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	public function test_payload_de_premier_niveau_ignore_pour_signed_only(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'      => 42,
					'kind'    => 'set_signature_policy',
					'payload' => [ 'policy' => 'report' ],
					'signed'  => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}' ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'required', Settings::get( 'signature_policy' ) );
	}

	public function test_kind_de_premier_niveau_different_du_corps_signe_est_refuse(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'kind'   => 'clear_cache',
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}' ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
		self::assertSame( [], $this->calls, 'clear_cache non exécuté non plus' );
		$post = $this->only_post();
		self::assertSame( 'failed', $post['body']['status'] );
		self::assertSame( 'g2rd_connector_signature_invalid', $post['body']['result']['code'] );
	}

	/**
	 * @return iterable<string, array{int, int}>
	 */
	public static function foreign_bindings(): iterable {
		yield 'autre commande' => [ self::SITE_ID, 41 ];
		yield 'autre site' => [ 8, 42 ];
	}

	#[DataProvider( 'foreign_bindings' )]
	public function test_signature_d_une_autre_commande_ou_d_un_autre_site_est_refusee( int $site_id, int $command_id ): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'signed' => $this->envelope( $command_id, '{"command":"set_signature_policy","payload":{"policy":"required"}}', null, null, $site_id ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
		self::assertSame( 'g2rd_connector_signature_invalid', $this->only_post()['body']['result']['code'] );
	}

	public function test_rejeu_de_la_meme_enveloppe_refuse_au_second_passage(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}' ),
				],
			]
		);

		$this->run_job();
		self::assertSame( 'done', $this->posts[0]['body']['status'] );

		// Entre-temps, la politique a été remise à `report` : le rejeu ne doit rien refaire.
		$this->set_policy( 'report' );
		$this->run_job();

		self::assertCount( 2, $this->posts );
		self::assertSame( 'failed', $this->posts[1]['body']['status'] );
		self::assertSame( 'g2rd_connector_signature_replayed', $this->posts[1]['body']['result']['code'] );
		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
		self::assertStringContainsString( 'déjà', (string) $this->posts[1]['body']['error'] );
	}

	public function test_horloge_hors_fenetre_refusee_avec_heure_du_site(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}', (string) ( self::NOW - 301 ) ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'report', Settings::get( 'signature_policy' ) );
		$post = $this->only_post();
		self::assertSame( 'g2rd_connector_clock_skew', $post['body']['result']['code'] );
		self::assertSame( self::NOW, $post['body']['result']['signature_check']['server_time'] );
		self::assertStringContainsString( 'horloge', (string) $post['body']['error'] );
		self::assertSame( 'clock_skew', SignatureState::stats()['last_code'] );
	}

	/**
	 * Une série de commandes longues (update_plugin…) ne doit pas faire sortir les
	 * suivantes de la fenêtre de 300 s : toutes sont vérifiées au même instant,
	 * avant toute exécution.
	 */
	public function test_verification_faite_avant_toute_execution(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[
					'id'     => 42,
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}', null, '000102030405060708090a0b0c0d0e0f' ),
				],
				[
					'id'     => 43,
					'signed' => $this->envelope( 43, '{"command":"delete_restore_point","payload":{"ids":[]}}', null, 'ffeeddccbbaa99887766554433221100' ),
				],
			]
		);

		$now   = self::NOW;
		$clock = static function () use ( &$now ): int {
			$current = $now;
			$now    += 400;
			return $current;
		};
		( new HeartbeatJob( $clock ) )->run();

		self::assertCount( 2, $this->posts );
		self::assertSame( 'done', $this->posts[0]['body']['status'] );
		self::assertSame( 'done', $this->posts[1]['body']['status'] );
	}

	/**
	 * Format futur : le manager omet `kind` pour qu'un ANCIEN connecteur ignore
	 * l'entrée (HeartbeatJob exigeait `kind`) au lieu de l'exécuter sans preuve.
	 */
	public function test_entree_sans_kind_mais_signee_est_executee_et_sans_kind_ni_signed_est_ignoree(): void {
		$this->set_policy( 'report' );
		$this->queue(
			[
				[ 'id' => 41 ],
				[
					'id'     => 42,
					'signed' => $this->envelope( 42, '{"command":"set_signature_policy","payload":{"policy":"required"}}' ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'required', Settings::get( 'signature_policy' ) );
		$post = $this->only_post();
		self::assertStringEndsWith( '/commands/42/result', $post['url'] );
		self::assertSame( 'done', $post['body']['status'] );
	}

	// ── Non-régression : le trafic réel des 23 sites ────────────────────────────

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function historical_commands_and_policies(): iterable {
		foreach ( [ 'report', 'required' ] as $policy ) {
			yield "clear_cache en $policy" => [ 'clear_cache', $policy ];
			yield "check_updates en $policy" => [ 'check_updates', $policy ];
			yield "update_plugin en $policy" => [ 'update_plugin', $policy ];
		}
	}

	#[DataProvider( 'historical_commands_and_policies' )]
	public function test_commandes_historiques_non_signees_inchangees_en_report_et_en_required( string $kind, string $policy ): void {
		$this->set_policy( $policy );
		$entry = [
			'id'   => 42,
			'kind' => $kind,
		];
		if ( 'update_plugin' === $kind ) {
			$entry['payload'] = [ 'file' => 'akismet/akismet.php' ];
		}
		$this->queue( [ $entry ] );

		$this->run_job();

		$post = $this->only_post();
		if ( 'update_plugin' === $kind ) {
			// Le payload de premier niveau est bien transmis : l'erreur cite le fichier demandé.
			self::assertSame( 'failed', $post['body']['status'] );
			self::assertSame( 'plugin not installed: akismet/akismet.php', $post['body']['error'] );
		} else {
			self::assertSame( 'done', $post['body']['status'] );
			self::assertSame( 'clear_cache' === $kind ? [ 'wp_cache_flush' ] : [ 'wp_version_check' ], $this->calls );
		}
		self::assertSame( 0, SignatureState::stats()['failed_count'], 'le compteur « zéro échec » reste propre' );
		self::assertSame( $policy, Settings::get( 'signature_policy' ) );
	}

	/** Rapport seul (dormant) : une enveloppe ratée sur une commande historique est comptée, pas bloquante. */
	public function test_commande_historique_avec_enveloppe_invalide_executee_mais_comptee(): void {
		$this->set_policy( 'required' );
		$signed              = $this->envelope( 42, '{"command":"clear_cache"}' );
		$signed['signature'] = 'v1=' . str_repeat( '0', 64 );
		$this->queue(
			[
				[
					'id'     => 42,
					'kind'   => 'clear_cache',
					'signed' => $signed,
				],
			]
		);

		$this->run_job();

		self::assertSame( 'done', $this->only_post()['body']['status'] );
		self::assertSame( [ 'wp_cache_flush' ], $this->calls );
		self::assertSame( 1, SignatureState::stats()['failed_count'] );
		self::assertSame( 'signature_invalid', SignatureState::stats()['last_code'] );
	}

	public function test_commande_historique_signee_valide_utilise_le_corps_signe(): void {
		$this->queue(
			[
				[
					'id'     => 42,
					'kind'   => 'clear_cache',
					'signed' => $this->envelope( 42, '{"command":"clear_cache"}' ),
				],
			]
		);

		$this->run_job();

		self::assertSame( 'done', $this->only_post()['body']['status'] );
		self::assertSame( [ 'wp_cache_flush' ], $this->calls );
		self::assertSame( 0, SignatureState::stats()['failed_count'] );
	}

	// ── Outils ──────────────────────────────────────────────────────────────────

	private function run_job(): void {
		( new HeartbeatJob( static fn (): int => self::NOW ) )->run();
	}

	private function set_policy( string $policy ): void {
		$this->options[ Settings::OPTION_KEY ]['signature_policy'] = $policy;
	}

	/**
	 * @param list<array<string, mixed>> $commands
	 */
	private function queue( array $commands ): void {
		$this->queue = (string) json_encode( [ 'commands' => $commands ] );
	}

	/**
	 * @return array{url:string, body:array<string, mixed>}
	 */
	private function only_post(): array {
		self::assertCount( 1, $this->posts, 'un seul compte rendu attendu' );
		return $this->posts[0];
	}

	/**
	 * Enveloppe signée comme le manager devra la produire (méthode PULL, route de
	 * la commande dans la file).
	 *
	 * @return array{body:string, timestamp:string, nonce:string, signature:string}
	 */
	private function envelope( int $command_id, string $body, ?string $timestamp = null, ?string $nonce = null, int $site_id = self::SITE_ID ): array {
		$timestamp ??= (string) self::NOW;
		$nonce     ??= bin2hex( random_bytes( 16 ) );
		$route       = sprintf( '/api/agent/sites/%d/commands/%d', $site_id, $command_id );

		return [
			'body'      => $body,
			'timestamp' => $timestamp,
			'nonce'     => $nonce,
			'signature' => 'v1=' . RequestSignature::sign( self::TOKEN, 'PULL', $route, $timestamp, $nonce, $body ),
		];
	}
}
