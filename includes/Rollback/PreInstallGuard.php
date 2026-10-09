<?php
/**
 * Dernier contrôle d'une mise à jour protégée, juste avant que WordPress remplace
 * les fichiers de l'extension (filtre `upgrader_pre_install`, cf. ProtectedUpdate::run()).
 *
 * WordPress applique ce filtre dans WP_Upgrader::install_package() : après le
 * téléchargement et la décompression, avant la mise de côté de l'ancienne version
 * (WordPress ≥ 6.3), la désactivation de l'extension et la copie. Une WP_Error rendue
 * ici arrête la mise à jour à cet endroit : Plugin_Upgrader::deactivate_plugin_before_upgrade()
 * et active_before() la laissent passer sans rien faire.
 *
 * Le contrôle rafraîchit la transaction de la requête (step(), qui recale aussi
 * `updated_at` après un téléchargement long). Si elle n'est plus la sienne (prise par
 * une reprise pendant une étape de plus de 10 minutes), il rend une WP_Error : la
 * reprise a restauré, ou restaure encore, l'ancienne version ; copier la nouvelle la
 * laisserait installée sans contrôle de santé, ou déplacerait le même dossier en même
 * temps qu'elle.
 *
 * @package G2RD\Connector
 */

declare(strict_types=1);

namespace G2RD\Connector\Rollback;

final class PreInstallGuard {

	/** Vrai si le contrôle a arrêté la mise à jour (transaction prise par une reprise). */
	public bool $stopped = false;

	/** Contrôle déjà fait : il ne vaut qu'une fois, pour l'extension mise à jour. */
	private bool $checked = false;

	/**
	 * @param string $plugin_file Extension mise à jour : les installations d'autres
	 *                            paquets pendant la même requête (traductions lancées
	 *                            après la mise à jour…) ne sont pas concernées.
	 * @param string $message     Message de la WP_Error rendue à WordPress (anglais).
	 */
	public function __construct( private readonly string $plugin_file, private readonly string $message ) {
	}

	/**
	 * @param bool|\WP_Error $response   Réponse des filtres précédents.
	 * @param mixed          $hook_extra Paquet installé (`plugin` pour une mise à jour d'extension).
	 * @return bool|\WP_Error
	 */
	public function __invoke( $response, $hook_extra = [] ) {
		if ( $this->checked || is_wp_error( $response ) || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? null ) !== $this->plugin_file ) {
			return $response;
		}
		$this->checked = true;
		if ( UpdateTransaction::step( UpdateTransaction::STEP_UPGRADING ) ) {
			return $response;
		}
		$this->stopped = true;
		return new \WP_Error( 'g2rd_protected_update_taken_over', $this->message );
	}
}
