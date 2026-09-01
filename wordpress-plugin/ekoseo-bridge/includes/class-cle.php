<?php
/**
 * Génération et révocation de la clé d'accès de JUPITER.
 *
 * WordPress sait créer un mot de passe d'application par API depuis la 5.6.
 * Le plugin s'en sert pour éviter le parcours manuel Utilisateurs → fiche →
 * section « Mots de passe d'application ».
 *
 * ⚠️ Le mot de passe en clair n'existe qu'à l'instant de sa création : WordPress
 * n'en garde qu'un hachage. Il est donc affiché une seule fois, et n'est jamais
 * écrit en base par ce plugin — ni en option, ni en transient, ni dans le journal.
 * Le journal d'audit note qu'une clé a été créée, pas sa valeur.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Cle {

	const NOM = 'JUPITER';

	public static function disponible() {
		return class_exists( 'WP_Application_Passwords' )
			&& function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();
	}

	public static function raison_indisponible() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return 'WordPress est trop ancien : les mots de passe d\'application demandent la version 5.6.';
		}
		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return 'Les mots de passe d\'application sont désactivés sur ce site '
				. '(constante WP_ENVIRONMENT_TYPE, filtre wp_is_application_passwords_available, '
				. 'ou site servi en HTTP). Ils exigent HTTPS.';
		}
		return '';
	}

	public static function bot_id() {
		$u = get_user_by( 'login', EKOSEO_BRIDGE_BOT );
		return $u ? (int) $u->ID : 0;
	}

	/** Les clés existantes, sans leur valeur — WordPress ne la conserve pas. */
	public static function lister() {
		$id = self::bot_id();
		if ( ! $id || ! self::disponible() ) {
			return [];
		}
		$out = [];
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $id ) as $p ) {
			$out[] = [
				'uuid'      => isset( $p['uuid'] ) ? $p['uuid'] : '',
				'nom'       => isset( $p['name'] ) ? $p['name'] : '',
				'cree'      => isset( $p['created'] ) ? gmdate( 'Y-m-d H:i', (int) $p['created'] ) : '',
				'dernier'   => ! empty( $p['last_used'] ) ? gmdate( 'Y-m-d H:i', (int) $p['last_used'] ) : null,
				'ip'        => isset( $p['last_ip'] ) ? $p['last_ip'] : null,
			];
		}
		return $out;
	}

	/**
	 * Crée une clé et renvoie [succes, clair_ou_message].
	 * La valeur retournée ne doit être affichée qu'une fois, puis oubliée.
	 */
	public static function creer( $nom = self::NOM ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ false, "Cette opération demande les droits d'administration." ];
		}
		if ( ! self::disponible() ) {
			return [ false, self::raison_indisponible() ];
		}
		$id = self::bot_id();
		if ( ! $id ) {
			return [ false, 'Le compte ' . EKOSEO_BRIDGE_BOT . " n'existe pas. Désactiver puis réactiver le plugin." ];
		}

		$nom = sanitize_text_field( $nom );
		if ( '' === $nom ) {
			$nom = self::NOM;
		}
		// une clé du même nom est révoquée d'abord : deux clés « JUPITER » prêtent à confusion
		foreach ( self::lister() as $c ) {
			if ( $c['nom'] === $nom && $c['uuid'] ) {
				WP_Application_Passwords::delete_application_password( $id, $c['uuid'] );
			}
		}

		$r = WP_Application_Passwords::create_new_application_password( $id, [ 'name' => $nom ] );
		if ( is_wp_error( $r ) ) {
			return [ false, $r->get_error_message() ];
		}
		$clair = isset( $r[0] ) ? $r[0] : '';
		if ( '' === $clair ) {
			return [ false, "WordPress n'a pas renvoyé de clé." ];
		}

		EkoSEO_Audit::journaliser( [
			'post_id' => null,
			'action'  => 'cle:creation',
			'result'  => 'ok',
			'message' => sprintf( 'Clé « %s » créée pour %s.', $nom, EKOSEO_BRIDGE_BOT ),
		] );

		return [ true, $clair ];
	}

	public static function revoquer( $uuid ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ false, "Cette opération demande les droits d'administration." ];
		}
		$id = self::bot_id();
		if ( ! $id ) {
			return [ false, 'Compte introuvable.' ];
		}
		$ok = WP_Application_Passwords::delete_application_password( $id, sanitize_text_field( $uuid ) );
		EkoSEO_Audit::journaliser( [
			'post_id' => null,
			'action'  => 'cle:revocation',
			'result'  => $ok ? 'ok' : 'error',
			'message' => 'Révocation ' . $uuid,
		] );
		return [ (bool) $ok, $ok ? 'Clé révoquée. Effet immédiat.' : 'Révocation impossible.' ];
	}
}
