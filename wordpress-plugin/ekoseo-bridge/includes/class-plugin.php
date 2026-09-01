<?php
/**
 * Amorçage : enregistre les routes et vérifie que le schéma est à jour.
 */

defined( 'ABSPATH' ) || exit;

final class EkoSEO_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		// Le hook qui SERT les redirections. Sans lui, `POST /redirect` enregistrait
		// bien l'entrée et aucune 301 n'était jamais rendue : un registre en écriture
		// seule, qui laisse croire que la redirection est posée.
		EkoSEO_Redirections::init();
		if ( is_admin() ) {
			EkoSEO_Admin::init();
		}
		add_action( 'plugins_loaded', [ EkoSEO_Installer::class, 'maybe_upgrade' ] );
		// Traçabilité des appels : table (idempotent), déclencheur sur chaque
		// page publique, purge quotidienne (90 jours — l'IP est personnelle).
		add_action( 'plugins_loaded', [ EkoSEO_ClicsTel_Controller::class, 'maybe_installer' ] );
		add_action( 'wp_footer', [ EkoSEO_ClicsTel_Controller::class, 'script' ] );
		add_action( 'ekoseo_clics_tel_purge', [ EkoSEO_ClicsTel_Controller::class, 'purger' ] );
	}

	public function routes() {
		( new EkoSEO_Site_Controller() )->register_routes();
		( new EkoSEO_Tree_Controller() )->register_routes();
		( new EkoSEO_Page_Controller() )->register_routes();
		( new EkoSEO_Audit_Controller() )->register_routes();
		( new EkoSEO_Scan_Controller() )->register_routes();
		( new EkoSEO_Import_Controller() )->register_routes();
		( new EkoSEO_Wxr_Controller() )->register_routes();
		( new EkoSEO_Delete_Controller() )->register_routes();
		( new EkoSEO_Redirect_Controller() )->register_routes();
		( new EkoSEO_Menu_Controller() )->register_routes();
		( new EkoSEO_ClicsTel_Controller() )->register_routes();
		( new EkoSEO_Media_Controller() )->register_routes();
	}
}
