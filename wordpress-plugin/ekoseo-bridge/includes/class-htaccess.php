<?php
/**
 * Le correctif qui laisse passer l'en-tête Authorization.
 *
 * Le problème : sur beaucoup d'hébergements en PHP-CGI/FastCGI, Apache ne
 * transmet pas l'en-tête `Authorization` à PHP. WordPress ne voit alors aucune
 * authentification et répond `rest_not_logged_in` — même avec un mot de passe
 * d'application parfaitement valide.
 *
 * ⚠️ Œuf et poule : ce correctif ne peut pas s'appliquer par l'API REST, puisque
 * c'est l'API REST qu'il débloque. Il passe donc par wp-admin, où l'utilisateur
 * est authentifié par cookie.
 *
 * Écrire dans `.htaccess` peut mettre un site entier hors ligne : une directive
 * refusée par Apache produit un 500 sur toutes les pages. La séquence est donc
 * sauvegarde → écriture → vérification par requête réelle → restauration
 * immédiate si le site ne répond plus.
 *
 * Cette opération exige `manage_options`. Le compte `ekoseo_bot` ne l'a pas et
 * ne doit jamais l'avoir : un pont compromis ne réécrit pas la configuration
 * du serveur.
 */

defined( 'ABSPATH' ) || exit;

class EkoSEO_Htaccess {

	const MARQUEUR = 'EkoSEO Bridge — Authorization';
	const OPTION_SAUVEGARDE = 'ekoseo_htaccess_backup';

	public static function lignes() {
		return [
			'<IfModule mod_setenvif.c>',
			'  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine On',
			'  RewriteCond %{HTTP:Authorization} ^(.+)$',
			'  RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]',
			'</IfModule>',
		];
	}

	public static function chemin() {
		return get_home_path() . '.htaccess';
	}

	/** Apache/LiteSpeed lisent .htaccess ; Nginx non. */
	public static function serveur() {
		$s = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
		if ( false !== strpos( $s, 'litespeed' ) ) { return 'litespeed'; }
		if ( false !== strpos( $s, 'apache' ) )    { return 'apache'; }
		if ( false !== strpos( $s, 'nginx' ) )     { return 'nginx'; }
		return $s ? $s : 'inconnu';
	}

	public static function htaccess_utilisable() {
		return in_array( self::serveur(), [ 'apache', 'litespeed', 'inconnu' ], true );
	}

	/** L'en-tête arrive-t-il jusqu'à PHP ? Vrai diagnostic, pas une supposition. */
	public static function entete_recu() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return true;
		}
		if ( function_exists( 'apache_request_headers' ) ) {
			$h = apache_request_headers();
			foreach ( (array) $h as $k => $v ) {
				if ( 'authorization' === strtolower( $k ) && '' !== $v ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function present() {
		$f = self::chemin();
		if ( ! file_exists( $f ) ) {
			return false;
		}
		$c = (string) @file_get_contents( $f );
		if ( false !== strpos( $c, '# BEGIN ' . self::MARQUEUR ) ) {
			return true;
		}
		// posé à la main, hors de nos marqueurs : on le reconnaît quand même
		return (bool) preg_match( '/SetEnvIf\s+Authorization/i', $c );
	}

	public static function etat() {
		$f = self::chemin();
		return [
			'serveur'          => self::serveur(),
			'htaccess_utile'   => self::htaccess_utilisable(),
			'fichier'          => $f,
			'existe'           => file_exists( $f ),
			'inscriptible'     => file_exists( $f ) ? is_writable( $f ) : is_writable( dirname( $f ) ),
			'correctif_present'=> self::present(),
			'entete_recu'      => self::entete_recu(),
			'sauvegarde'       => (bool) get_option( self::OPTION_SAUVEGARDE ),
		];
	}

	/**
	 * Pose le correctif. Retourne [succes, message].
	 * Toute écriture est précédée d'une sauvegarde et suivie d'une vérification.
	 */
	public static function appliquer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ false, "Cette opération demande les droits d'administration." ];
		}
		if ( ! self::htaccess_utilisable() ) {
			return [ false, sprintf(
				"Ce serveur est un %s : il ne lit pas .htaccess. Le correctif doit être posé dans sa configuration :\n"
				. "    fastcgi_param HTTP_AUTHORIZATION \$http_authorization;",
				self::serveur()
			) ];
		}
		if ( self::present() ) {
			return [ true, 'Le correctif est déjà en place.' ];
		}

		$f = self::chemin();
		if ( file_exists( $f ) && ! is_writable( $f ) ) {
			return [ false, sprintf( "Le fichier %s n'est pas inscriptible. Le corriger à la main, ou ajuster ses droits.", $f ) ];
		}
		if ( ! file_exists( $f ) && ! is_writable( dirname( $f ) ) ) {
			return [ false, sprintf( "Le dossier %s n'est pas inscriptible.", dirname( $f ) ) ];
		}

		// --- sauvegarde AVANT toute écriture
		$avant = file_exists( $f ) ? (string) file_get_contents( $f ) : '';
		update_option( self::OPTION_SAUVEGARDE, [
			'contenu' => $avant,
			'date'    => current_time( 'mysql', true ),
		] );

		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$ok = insert_with_markers( $f, self::MARQUEUR, self::lignes() );
		if ( ! $ok ) {
			return [ false, "L'écriture a échoué. Le fichier est inchangé." ];
		}

		// --- vérification : le site répond-il encore ?
		$verdict = self::verifier_site();
		if ( ! $verdict['ok'] ) {
			self::restaurer();
			return [ false, sprintf(
				"Le correctif a été écrit puis RETIRÉ : le site a répondu %s juste après. "
				. "La configuration d'Apache refuse ces directives. Le fichier a été remis dans son état d'origine.",
				$verdict['detail']
			) ];
		}

		return [ true, sprintf(
			"Correctif posé dans %s, et le site répond toujours (HTTP %s). "
			. "Vérifier maintenant qu'un appel authentifié fonctionne.",
			$f, $verdict['detail']
		) ];
	}

	public static function retirer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ false, "Cette opération demande les droits d'administration." ];
		}
		$f = self::chemin();
		if ( ! file_exists( $f ) || ! is_writable( $f ) ) {
			return [ false, 'Fichier absent ou non inscriptible.' ];
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		insert_with_markers( $f, self::MARQUEUR, [] );
		return [ true, 'Correctif retiré.' ];
	}

	public static function restaurer() {
		$s = get_option( self::OPTION_SAUVEGARDE );
		if ( ! $s || ! isset( $s['contenu'] ) ) {
			return [ false, 'Aucune sauvegarde disponible.' ];
		}
		$f = self::chemin();
		if ( '' === $s['contenu'] && file_exists( $f ) ) {
			@unlink( $f );          // il n'y avait pas de fichier avant nous
			return [ true, 'Fichier supprimé : il n\'existait pas avant l\'intervention.' ];
		}
		$ok = false !== @file_put_contents( $f, $s['contenu'] );
		return [ $ok, $ok ? sprintf( 'Fichier restauré dans son état du %s.', $s['date'] ) : 'Restauration impossible.' ];
	}

	/**
	 * Requête réelle sur l'accueil : c'est la seule façon de savoir si Apache
	 * accepte les directives qu'on vient d'écrire.
	 */
	private static function verifier_site() {
		$r = wp_remote_get( home_url( '/?ekoseo_check=' . time() ), [
			'timeout'   => 12,
			'sslverify' => false,
			'headers'   => [ 'Cache-Control' => 'no-cache' ],
		] );
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => false, 'detail' => $r->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		return [ 'ok' => $code > 0 && $code < 500, 'detail' => (string) $code ];
	}
}
