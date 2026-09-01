<?php
/**
 * Tables, rôle, compte robot, migrations.
 *
 * La désactivation ne supprime rien : couper le pont ne doit pas détruire
 * l'historique des snapshots, seul filet de sécurité en cas de mauvaise écriture.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Installer {

	const OPTION_VERSION = 'ekoseo_bridge_version';
	const OPTION_PINS    = 'ekoseo_bridge_pins';

	public static function activate() {
		EkoSEO_Roles::create();
		EkoSEO_Roles::ensure_bot();
		self::tables();
		self::pin_versions();
		update_option( self::OPTION_VERSION, EKOSEO_BRIDGE_VERSION );

		/* Le correctif Authorization est tenté dès l'activation : sans lui le pont
		   est inutilisable, et l'utilisateur ne comprendrait pas pourquoi. La pose
		   est sauvegardée puis vérifiée par une requête réelle ; si le site ne
		   répond plus, le fichier est immédiatement remis en état. */
		if ( class_exists( 'EkoSEO_Htaccess' ) && ! EkoSEO_Htaccess::present() ) {
			list( $ok, $msg ) = EkoSEO_Htaccess::appliquer();
			update_option( 'ekoseo_htaccess_activation', [ 'ok' => $ok, 'message' => $msg ] );
		}
	}

	public static function deactivate() {
		// Rien. Voir uninstall.php.
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION ) === EKOSEO_BRIDGE_VERSION ) {
			return;
		}
		EkoSEO_Roles::create();
		self::tables();
		update_option( self::OPTION_VERSION, EKOSEO_BRIDGE_VERSION );
	}

	public static function tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$snap    = $wpdb->prefix . 'ekoseo_snapshots';
		$audit   = $wpdb->prefix . 'ekoseo_audit';

		dbDelta(
			"CREATE TABLE {$snap} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				operation_id VARCHAR(64) NOT NULL,
				payload LONGTEXT NOT NULL,
				hash_before VARCHAR(80) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY operation_id (operation_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NULL,
				operation_id VARCHAR(64) NULL,
				action VARCHAR(40) NOT NULL,
				reason TEXT NULL,
				recommendation_ids TEXT NULL,
				changed_fields TEXT NULL,
				result VARCHAR(20) NOT NULL,
				message TEXT NULL,
				user_login VARCHAR(60) NULL,
				snapshot_id BIGINT UNSIGNED NULL,
				response LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY operation_id (operation_id)
			) {$charset};"
		);
	}

	/**
	 * Épingle la majeure d'Elementor au moment de l'installation. Le PUT refuse
	 * d'écrire si elle change : la structure de `_elementor_data` n'est pas
	 * garantie entre deux majeures.
	 */
	public static function pin_versions() {
		$pins = [ 'elementor_major' => self::elementor_major() ];
		update_option( self::OPTION_PINS, $pins );
	}

	public static function elementor_major() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return null;
		}
		$parts = explode( '.', ELEMENTOR_VERSION );
		return isset( $parts[0] ) ? (int) $parts[0] : null;
	}

	public static function pinned_elementor_major() {
		$pins = get_option( self::OPTION_PINS, [] );
		return isset( $pins['elementor_major'] ) ? $pins['elementor_major'] : null;
	}

	public static function snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'ekoseo_snapshots';
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'ekoseo_audit';
	}
}
