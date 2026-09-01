<?php
/**
 * Suppression complète : tables, rôle, options.
 * Le compte `ekoseo_bot` est conservé — il peut porter des révisions de contenu,
 * et supprimer un auteur réattribue ou détruit ses révisions.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( [ 'ekoseo_snapshots', 'ekoseo_audit' ] as $t ) {
	$table = $wpdb->prefix . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
}

remove_role( 'ekoseo_agent' );
delete_option( 'ekoseo_bridge_version' );
delete_option( 'ekoseo_bridge_pins' );
delete_option( 'ekoseo_htaccess_backup' );
delete_option( 'ekoseo_htaccess_activation' );

/* Le correctif .htaccess n'est PAS retiré : d'autres outils peuvent en dépendre,
   et le laisser en place ne casse rien. Le retirer se fait depuis l'écran du
   plugin, avant désinstallation. */
