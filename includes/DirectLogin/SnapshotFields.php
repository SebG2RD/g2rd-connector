<?php
/**
 * Ce que le snapshot dit de la connexion au site (connecteur 1.13, spec connexion
 * WordPress §5.4 et §5.6), dans le bloc `site` :
 *   - `login_url` : wp_login_url(), que les extensions qui déplacent la connexion
 *     (WPS Hide Login, Solid Security…) filtrent — c'est ainsi que le manager la
 *     détecte sans saisie ;
 *   - `direct_login_enabled` : la case « Autoriser la connexion directe depuis G2RD » ;
 *   - `admins` : les administrateurs du site, du plus ancien au plus récent, 50 au
 *     plus, parmi lesquels le manager choisit le compte ouvert.
 * La capacité `direct_login` est annoncée par SnapshotController::capabilities().
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\DirectLogin;

use G2RD\Connector\Settings;

final class SnapshotFields {

	public const CAPABILITY = 'direct_login';
	public const MAX_ADMINS = 50;

	/**
	 * @return array{login_url: string, direct_login_enabled: bool, admins: list<array{id: int, login: string, email: string, registered: string}>}
	 */
	public static function site_fields(): array {
		return [
			'login_url'            => (string) wp_login_url(),
			'direct_login_enabled' => (bool) Settings::get( 'allow_direct_login' ),
			'admins'               => self::admins(),
		];
	}

	/**
	 * @return list<array{id: int, login: string, email: string, registered: string}>
	 */
	public static function admins(): array {
		$users = get_users(
			[
				'role'    => 'administrator',
				'number'  => self::MAX_ADMINS,
				'orderby' => 'registered',
				'order'   => 'ASC',
				'fields'  => [ 'ID', 'user_login', 'user_email', 'user_registered' ],
			]
		);

		$admins = [];
		foreach ( (array) $users as $user ) {
			$row = is_object( $user ) ? get_object_vars( $user ) : [];
			if ( empty( $row['ID'] ) || ! isset( $row['user_login'] ) ) {
				continue;
			}
			$admins[] = [
				'id'         => (int) $row['ID'],
				'login'      => (string) $row['user_login'],
				'email'      => (string) ( $row['user_email'] ?? '' ),
				'registered' => (string) ( $row['user_registered'] ?? '' ),
			];
		}

		return $admins;
	}
}
