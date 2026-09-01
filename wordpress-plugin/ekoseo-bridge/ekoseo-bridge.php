<?php
/**
 * Plugin Name: EkoSEO Bridge
 * Plugin URI:  https://github.com/Call-Connect-Benin/jupiter
 * Description: Pont API entre JUPITER, War on Image Manager et ce site WordPress. Lecture complète (arborescence, contenu, maillage interne, SEO, médiathèque), écriture chirurgicale des champs SEO et des métadonnées d'images, snapshot et rollback.
 * Version:     1.5.0
 * Author:      EkoMedia
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'EKOSEO_BRIDGE_VERSION', '1.5.0' );
define( 'EKOSEO_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'EKOSEO_BRIDGE_FILE', __FILE__ );
define( 'EKOSEO_BRIDGE_NS', 'ekoseo/v1' );
define( 'EKOSEO_BRIDGE_CAP', 'ekoseo_manage' );
define( 'EKOSEO_BRIDGE_ROLE', 'ekoseo_agent' );
define( 'EKOSEO_BRIDGE_BOT', 'ekoseo_bot' );

require_once EKOSEO_BRIDGE_PATH . 'includes/class-roles.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-installer.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-hash.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-cache.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-audit.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-htaccess.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-cle.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-redirections.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-admin.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/content/class-html-parser.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/content/class-yoast-io.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/content/class-elementor-io.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/content/class-geo-guard.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/content/class-elementor-build.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-controller-base.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-site-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-tree-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-page-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-audit-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-scan-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-import-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-wxr-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-delete-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-redirect-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-menu-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-clics-tel-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/rest/class-media-controller.php';
require_once EKOSEO_BRIDGE_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, [ 'EkoSEO_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'EkoSEO_Installer', 'deactivate' ] );

EkoSEO_Plugin::instance();
