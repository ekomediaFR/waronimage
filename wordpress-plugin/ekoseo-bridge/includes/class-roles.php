<?php
/**
 * Le rôle du robot : une seule capacité, et `read` pour que WordPress
 * l'accepte comme utilisateur valide.
 *
 * Volontairement dépourvu de `manage_options`, `install_plugins`,
 * `edit_theme_options` : un pont compromis ne doit pas pouvoir prendre le site.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Roles {

	public static function create() {
		remove_role( EKOSEO_BRIDGE_ROLE );
		add_role(
			EKOSEO_BRIDGE_ROLE,
			'EkoSEO Agent',
			[
				'read'              => true,
				EKOSEO_BRIDGE_CAP   => true,
			]
		);
	}

	public static function remove() {
		remove_role( EKOSEO_BRIDGE_ROLE );
	}

	/**
	 * Crée le compte du robot s'il n'existe pas. Sans mot de passe utilisable :
	 * l'accès passe exclusivement par un mot de passe d'application, révocable
	 * d'un clic depuis la fiche utilisateur.
	 */
	public static function ensure_bot() {
		$user = get_user_by( 'login', EKOSEO_BRIDGE_BOT );
		if ( $user ) {
			if ( ! in_array( EKOSEO_BRIDGE_ROLE, (array) $user->roles, true ) ) {
				$user->add_role( EKOSEO_BRIDGE_ROLE );
			}
			return $user->ID;
		}
		$id = wp_insert_user(
			[
				'user_login'   => EKOSEO_BRIDGE_BOT,
				'user_pass'    => wp_generate_password( 48, true, true ),
				'user_email'   => 'ekoseo-bot@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'display_name' => 'EkoSEO Agent',
				'role'         => EKOSEO_BRIDGE_ROLE,
			]
		);
		return is_wp_error( $id ) ? 0 : $id;
	}
}
